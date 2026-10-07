# Green convexity engine — live data-plane ingestion readback

**Owner:** infra-lane / claude &nbsp;|&nbsp; **Agent ID:** claude/theta-fringe &nbsp;|&nbsp;
**Scope:** read-only live option-data ingestion wiring for the near-0.20Δ convex lane — broker feed → sealed feature extractor → validated 41-col matrix. Math/thresholds untouched (sealed library). &nbsp;|&nbsp;
**Status:** built, unit-tested, and live-verified end-to-end against the pinned agentic account (read-only). Scheduler registered default-off. &nbsp;|&nbsp; **Date:** 2026-10-07

## What was built

Deterministic PHP wiring around the sealed primitives (no model, no math change):

- `app/Robinhood/Convex/ConvexIngestService.php` — one sweep: resolve spot → chain → in-band expirations → paginate+window call/put instruments → chunked `get_option_quotes` → assemble the extractor's row contract → `POST /run/convex_feature_row` → `POST /run/convex_feature_schema` (clean-row gate) → append → telemetry.
- `app/Robinhood/Convex/GreeksRunClient.php` — typed client over the greeks `/run/{script}` whitelist.
- `app/Robinhood/Convex/FeatureMatrixWriter.php` — locked append (header-once, `flock`), reserved outcome columns emitted as empty (schema-null / pandas NaN).
- `app/Console/Commands/ConvexIngestCommand.php` — `theta:convex-ingest {--once|--loop|--dry-run|--ticker=*}`. Default-off gates the **scheduler** only; a manual `--once` always sweeps.
- `config/harness.php` → `harness.convex` block (tickers, 45–90 DTE band, moneyness windows, strike cap, pagination cap, matrix path, cadence).
- `routes/console.php` — `Schedule::command('theta:convex-ingest')` weekdays, RTH, every 5 min, `withoutOverlapping`.
- `tests/Feature/ConvexIngestTest.php` — 4 tests / 46 assertions: in-band-tenor selection + cap, null outcome placeholders, instrument cursor pagination to the above-spot call wing, schema-drift not appended, dry-run writes nothing, default-off makes no broker calls.

Matrix: `storage/harness/convex_feature_matrix.csv`. The directive's "index feature matrix" — created by the pipe; it did not previously exist on disk (the architecture doc's "9-row matrix" was ephemeral).

## Three mechanical defects found and fixed before the pipe produced a row

1. **Stale greeks whitelist.** The running `theta-greeks-1` (37h old) pre-dated the convex scripts being added to `SCRIPTS` in `services/greeks/main.py` (bind-mounted, edited after start). `/run/convex_feature_row` 404'd ("unknown script") while direct `python` worked. Fix: `docker compose -p theta restart greeks` → whitelist 7 → 15. **Operational rule: a main.py whitelist edit requires a greeks restart; the process loads SCRIPTS at import.**
2. **Instrument pagination cursor.** `get_option_instruments` is cursor-paginated ~100/page, ascending strike; page 1 for SPY (spot 779) was strikes 475–658 — **entirely below spot**, so the OTM call wing (the product) was on later pages. The `next` token is a **raw opaque cursor** (e.g. base64 `p=658.0000`), not a URL, so the producer-style `cursorFromNext()` URL-parse returned null and dead-ended on page 1. Fix: pass `next` straight through as the cursor (URL-param extraction kept as a fallback). Call side now spans 475→920 across 3 pages.
3. **`--format csv-header` argparse.** `convex_feature_row.py` marks the data flags `required=True`, so a header-only call errored → empty stdout. Fix: derive the canonical column order from the emitted row's own keys (the schema `--row` check already guarantees `keys == FEATURE_COLUMNS` before any append), dropping a round-trip.

## Live proof (off-hours 2026-10-07, read-only, pinned account 872449178)

Full sweep SPY/QQQ/IWM × 2 tenors → **6/6 rows appended**, all ADMIT (stale off-hours quotes; the structural gate still passed):

| ticker | dte | spot | verdict | far Δ | ach_bps | fill_p |
|---|---|---|---|---|---|---|
| SPY | 54 / 72 | 779.14 | ADMIT | 0.189 / 0.191 | 12.3 / 6.9 | 0.61 / 0.58 |
| QQQ | 54 / 72 | 759.61 | ADMIT | 0.204 / 0.214 | 27.1 / 14.8 | 0.69 / 0.91 |
| IWM | 72 / 85 | 281.30 | ADMIT | 0.184 / 0.154 | 22.8 / 33.7 | 0.90 / 0.90 |

The 0.20Δ wing is captured cleanly across all three. Rows validate against the sealed schema; outcome columns empty.

## Rate budget (napkin; see also the shared broker rail)

- **No public rate limit** for the agentic MCP (`agent.robinhood.com/mcp/trading`). Closest documented reference: Robinhood **Crypto API = 100 req/min/account, burst 300**, token bucket, per-endpoint, HTTP 429 on exceed. Community guidance for unofficial endpoints: poll ≥5 min, back off ≥60s on 429, honor `Retry-After`.
- **theta's own rail** `harness.broker_credits.max_per_minute = 45` (mode `wait`, `max_wait_seconds` 20) — conservative vs the crypto reference, and **shared** with the every-minute trading jobs (option-intent-producer, autopilot, reconcile, cockpit-snapshot, thesis-execute).
- **Measured convex cost:** cold full sweep ≈ 44 calls at 24 strikes/side (hit the 45 rail → dropped the tail tenor, which self-heals next sweep). After reducing to **16 strikes/side**: warm full sweep = **22 calls**, cold ≈ 33 — both under 45 with headroom. Instrument metadata is cached 900s (`options.metadata_cache_seconds`), so steady-state sweeps are quotes-only.
- **Knobs** (all config, no code): `max_strikes_per_side`, `tenors_per_ticker`, `call/put_moneyness_*`, `max_instrument_pages`, `loop_interval_seconds`, scheduler cadence. To harden isolation from the trading jobs further: widen cadence to 10 min, or run convex one `--ticker` at a time (~8–15 calls each). Do **not** raise `max_per_minute` without a measured broker-429 threshold.

## First live readback criteria (tomorrow's open)

1. During RTH, `theta:convex-ingest --once` (or the armed scheduler) appends rows with `stale=0` / small `age_s` and non-null `far_delta ≈ 0.20` — confirms fresh-feed ingestion and the wing mapping under live quotes.
2. No `broker credits exhausted` blocks in `storage/harness/convex_ingest-*.jsonl` when running alongside the per-minute jobs — confirms the shared-rail budget holds.
3. Matrix accumulates clean rows across tenors; `convex_feature_schema --csv storage/harness/convex_feature_matrix.csv` validates the whole file.

## Rollback

- Disarm: `HARNESS_CONVEX_INGEST_ENABLED=false` (default) — scheduler no-ops; manual runs are explicit.
- Full removal: drop the `Schedule::command('theta:convex-ingest')` line and the `harness.convex` block; the `app/Robinhood/Convex/*` classes and command are additive and read-only.

## Boundary

Read-only. No order path touched; no math/threshold changed (sealed library). Any live placement remains operator-authority under the capital firewall. Broker/account truth outranks logs; the market is the judge.
