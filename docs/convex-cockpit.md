# Convex stakeholder cockpit

`GET /cockpit/convex` is the read-only stakeholder page for the deployed Convex family. It polls `GET /cockpit/convex/data` every 15 seconds while visible. Both routes inherit the existing private, host-loopback deployment boundary. There are no order, cancellation, configuration, or worker controls.

The existing account cockpit links to this page. To view production from an authorized laptop, forward an unused local port:

```sh
ssh -N -L 8791:127.0.0.1:8790 edge-bot
```

Then open `http://127.0.0.1:8791/cockpit/convex`. The tunnel does not publish the dashboard publicly. The legacy account cockpit can refresh broker snapshots on demand; the Convex page never does.

## Evidence contract

- Signals: the latest configured tenors per underlying from the saved feature matrix. Observations older than 900 seconds remain visible as stale. Future or malformed timestamps are excluded. A signal that passes the matrix is not an executable trade.
- Runs: all active records, submissions from the last 24 hours, and the latest 40, scoped to the configured account. Contract selection, submitted limits, fills, cancellation intent, expiry, closure, and last broker readback are presented separately.
- Exposure: remaining recorded filled contracts and their entry premium at cost. Working or ambiguous orders can carry additional risk; zero recorded inventory is not a fresh account-wide broker-flat assertion.
- P&L: gross realized premium change for fully closed, matched fills, using a 100-share multiplier for this one-contract option family. The session boundary is New York midnight. Missing prices or unmatched fills make the session result unknown. Fees and unrealized P&L are not inferred.
- Health: grant presence/expiry, the existing request-credit ledger, retained broker-call outcomes, last ingest evidence, and last stewardship readback. A recent event is not a worker heartbeat; no heartbeat is fabricated when a flat loop is quiet.
- Timeline: allowlisted lifecycle events for account-scoped visible runs. Raw errors, OAuth credentials, account numbers, order payloads, and unrecognized free text are withheld.

The web path does not instantiate `RobinhoodMcpClient`, call `CockpitSnapshot::cached`, invoke commands, update run records, or acquire trading locks. It reads the existing database, Redis ledger, encrypted grant metadata, and saved files. The matrix read uses a nonblocking shared lock and is capped at its last 1 MiB. JSONL reads cover the last two UTC date files, at most 512 KiB each per stream; counts describe retained observations and truncation is disclosed.

Missing database evidence renders exposure/P&L unknown. Missing, malformed, or temporarily locked file evidence is disclosed. A failed browser refresh preserves the last successful display with an explicit stale-view notice. Filters and expanded lifecycle details survive ordinary refreshes.

## Validation and deployment

`ConvexCockpitTest` checks HTTP read-only behavior, no broker HTTP calls, no trading-record writes, credential redaction, account isolation, signal freshness, New York session accounting, incomplete fills, bounded telemetry, and old active records.

Deploy only the dashboard reader/controller/view, its routes/navigation link, tests, and this document. No migration, worker restart, scheduler change, limit change, or broker operation is needed. Regenerate the route cache after the files are present. Check both page and data endpoints, confirm the configured caps, and verify PHP-FPM reports no new page errors.

Rollback restores the prior route and account navigation files and regenerates the route cache; the new unused reader/view/controller may remain until cleanup. Preserve every runtime artifact and ownership record.
