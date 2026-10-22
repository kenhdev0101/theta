# Convex evidence: storage budget and retention

Added for the October 22, 2026 POC readback: a qualifying matrix row previously
retained relative spread measures but not its absolute bid/ask. Re-querying a
contract later could not answer what was executable at the original timestamp.

## What is retained

Daily UTC JSONL files live in `storage/harness/convex-evidence/` by default.
Every record carries `schema_version`, an observation timestamp, and an event.

| Observation | Evidence |
| --- | --- |
| `signal_observed` | Exact far-call option ID, strike, expiry, bid/ask, delta and broker quote timestamp from the existing ingest response; source verdict and snapshot timestamp; SHA-256 matching the canonical CSV readback. All schema-valid appended signals are covered, including rejects. |
| `selection_observed` | Up to 32 sampled contracts from the existing selection reads, their quotes, calculated limits where reached, rejection codes, selected contract or blocked/interrupted outcome, observed cash, selection ceiling and configured caps. |
| `execution_quote_observed`, `placement_*`, `execution_blocked` | Quote before review and refreshed quote after review when applicable; actual intended limit; attempted/deferred/unknown/acknowledged placement and reference IDs. An attempt or acknowledgement does not assert a fill. |
| `probe_decision`, resting/exit quotes, `broker_order_observed`, terminal events | Changed status/blockers; already-fetched stewardship quotes; matched broker order state, quantities and fill averages; cancellation or confirmed closure. |

Signal → probe joins use `matrix_row_hash`; execution joins use thesis/leg and
broker order/reference IDs. Hashing uses the CSV's string/empty/boolean
representation, not the raw Python JSON types. The sealed matrix schema and
math are unchanged. If a source quote cannot be matched, it is explicitly null;
we never fetch a substitute and label it historical. Dry-run ingest writes no
new evidence. No historical backfill is fabricated.

Prices are per option share; standard contract premium is price × 100 × quantity.
A selector's calculated limit can have rejection codes and is not an approved
order. Confirmed closures retain gross cash P&L and premium-return BPS. Fees and
net P&L/BPS remain null with `fee_status=unverified`; missing fees are not zero.
These observations do not produce hypothetical fills or shadow P&L for blocked
contracts. Durable broker journals remain the outcome source of record.

## Napkin math: production box baseline

Read-only measurement on October 22, 2026 around 18:09 UTC:

| Resource | Measured |
| --- | ---: |
| Filesystem capacity | 149.9 GiB |
| Used | 130.1 GiB |
| Available to the service | **13.63 GiB** |
| RAM total / available | 30.6 / **20.3 GiB** |
| Existing MCP telemetry | 197.4 MiB across 99 files |
| Existing cockpit snapshots | 28.6 MiB across 63 files |
| Existing convex ingest / matrix | 1.77 / 0.59 MiB |

Available disk excludes filesystem reserves. This change does not clean up or
bound those older streams. On October 21 the existing ingest stream recorded
561 feature rows in 0.31 MiB; MCP telemetry recorded 5,662 events in 1.25 MiB.
October 22 was a partial session when sampled, so its counts are not treated as
a daily average.

Serialized representative records measured with the actual writer:

| Record | Bytes |
| --- | ---: |
| Signal with source quote and matrix hash | 700 |
| Selection with 16 samples and cash/cap context | 6,071 |
| Selection with 32 samples | 11,511 |

Sizing scenarios, not forecasts:

- Signals: 4 symbols × 2 tenors × 79 five-minute sweeps × 700 bytes ≈ **0.42 MiB/session**.
- Busy selection scenario: 8 symbol/tenor pairs × 375 entry minutes × one
  attempt/minute × 6,071 bytes ≈ **17.4 MiB/session**. Allow another ~2 MiB for
  decision and stewardship evidence: roughly **20 MiB/session** total.
- The same scenario with 32 samples is ~33 MiB of selections alone. It would
  hit the daily cap. In production the exact-DTE probes normally cover one
  expiry; available credit, daily-entry guards and nonqualifying signals also
  reduce attempts. New row boundaries can change attempt cadence, so the
  scenario is not a proof of a maximum event count.

**Additional broker requests: zero. Additional database writes: zero.** The
writer adds one file append per event, with a nonblocking file lock. A local PHP
8.4 benchmark of 1,000 representative 32-sample appends averaged **0.187 ms**, p95
0.283 ms, p99 0.355 ms, and ~75 KiB incremental peak memory for serialization and
writing. At 3,000 attempts that is ~0.56 seconds of aggregate writer time per
session on the test machine. This excludes application bootstrap and sample
assembly and is not a production disk-latency guarantee. No new daemon or
in-memory quote history is introduced.

## Bounded retention

- Keep **14 UTC calendar dates**, including today; weekends count.
- **32 MiB hard ceiling per UTC day file**, checked under the append lock;
  **32 KiB ceiling per record**, up to 32 samples. Excess observations are
  dropped; the current file is never overwritten to fit more records.
- At most **448 MiB after successful daily pruning**. There may briefly be 15
  day files before the 00:15 UTC cleanup, up to **480 MiB** (~3.4% of the measured
  available disk). This bound assumes the scheduled prune succeeds; missed
  cleanup is visible as expired files in the health command.
- Below **256 MiB filesystem free**, stop optional evidence writes.
- Lock contention, disk errors and encoding failures skip evidence without
  changing selection, order state, risk rails or the API-credit retry policy.
- A single `last-drop.json` marker records a safe failure code, at most once
  per minute. It is a last-observed drop, not a cumulative drop counter; it
  cannot itself be guaranteed writable during disk/permission failure.
- Pruning scans file metadata only, takes nonblocking locks, and deletes only
  valid expired `convex-evidence-YYYY-MM-DD.jsonl` files. Symlinks, malformed
  names, trading journals, the matrix and other telemetry streams are excluded.

Configuration: `HARNESS_CONVEX_EVIDENCE_ENABLED` (true),
`HARNESS_CONVEX_EVIDENCE_RETENTION_DAYS` (14, clamped 1–90),
`HARNESS_CONVEX_EVIDENCE_MAX_DAILY_BYTES` (33,554,432, cannot exceed 32 MiB), and
optional `HARNESS_CONVEX_EVIDENCE_DIR`. Retention changes scale disk usage; the
448/480 MiB calculations assume the initial 14-day setting.

## Readback and maintenance

From the deployed checkout, inspect metadata only:

```sh
docker compose -p theta exec -T --user www-data app php artisan theta:convex-evidence
```

The result includes daily bytes, total bytes, expired bytes and the last drop.
`theta:convex-evidence --prune` applies the TTL; scheduled daily at 00:15 UTC.
A nonzero prune exit means some expired bytes remain. No per-record SQL deletes
or broker operations are used. The cockpit does not reparse this evidence on
each UI poll.

Use completed-session file sizes to revisit the estimate. Investigate any
fresh drop marker, any expired file after scheduled cleanup, or sustained days
above 24 MiB. If necessary, reduce redundant selection/unchanged-order stamps
or shorten TTL first. An export for longer research retention should be an
explicit next step. Current position sizing, caps and thesis rules stay intact.

## Validation

Tests cover canonical row/hash joins and source bid/ask stamps with no extra
quote calls; partial selection evidence on a broker exception; secrets excluded
from new records; cap/lock/TTL boundaries and cleanup scope; gross versus unknown
net outcomes; and unchanged credit retry/order-reference behavior when the
evidence directory is unwritable. Existing convex, option-execution and rail
tests are also run for the observational integration.
