# Convex thesis-spec broker probe

Owner: theta engineering / desk
Agent ID: codex/convex-broker-probe-20261007
Scope: one-contract, long-call mechanical lifecycle using the JSON thesis-spec primitive and the pinned Robinhood agentic account
Status: machine loop built for autonomous admission and stewardship; original and first thesis-expiry live probes broker-confirmed closed and journal-reconciled; second distinct expiry probe pending eligibility
Date: 2026-10-07

## What the probe exercises

`specs/convex-mechanical-probe-20261007.json` is one `role=probe` leg, near 0.20 absolute delta, 7–21 DTE, with a ten-minute maximum hold, profit target, and hard stop. `theta:convex-probe stage` validates and persists its immutable spec. `enter` resolves a real Robinhood option ID from Friday expirations and fresh two-sided quotes, checks that one contract fits available settled cash and buying power, then uses the existing option rails, broker review, persisted `ref_id`, and limit-order executor. Entry is explicit; the scheduled `tick` never opens a staged spec.

After entry, each tick reads agentic option orders and nonzero positions. It checks broker order ID, option leg ID, side, position effect, processed quantity, and matching position before claiming ownership. A resting entry is cancelled after three minutes; the cancellation and zero position must be confirmed from broker truth before a no-fill close. A held contract is exited when its precommitted time, profit, or stop trigger fires. Exit uses a fresh bid, the frozen owned option ID, existing sell-to-close rails, a separate durable logical order key, and a broker-confirmed terminal readback. A resolver result with a different option ID stays `ambiguous` before any sell attempt. The executor rechecks quote age after broker review, immediately before placement. An unfilled exit is cancelled after two minutes, with reprice on a later tick only after the cancellation is confirmed. An exit intent with no verified broker order ID stays `ambiguous` across restart, preventing a second sell. Missing order identity or conflicting position quantity also stays `ambiguous` for manual reconciliation. The terminal readback reports gross P&L before fees from broker executions; broker/account truth governs any net outcome claim.

The first local live probes used an attended theta-only tick loop because the scheduler had been exited. The first production integration installed commands and schema without an automatic probe. This follow-up adds a dedicated convex scheduler loop while keeping the unrelated option, residual, and cockpit lanes disabled. The existing kill switch and broker rails remain unchanged.

## Latest bounded evidence

- 2026-10-07 18:06 UTC pinned account: $164.07 cash, settled cash, and buying power; no open equity positions. At 18:06:45 UTC, no nonzero option positions; 111 historical agentic option orders (110 filled, one cancelled).
- 18:00 UTC convex matrix added two fresh SPY rows. 54-DTE rejected on `executable_wing`; 72-DTE admitted at 0.1859 delta. This proves read-only ingestion, not broker lifecycle.
- 18:24 UTC read-only XLF Friday-series selection found a 2026-10-23 55.5 call near 0.2006 delta, with bid $0.19, ask $0.22, about 13.5-second quote age. One contract would have a $22 limit debit at that snapshot. The 18:25 UTC broker-context preflight found $164.07 available and **ALLOW** from all pre-review option rails. No review or place call occurred in that preflight.
- The existing option rail's 10-second quote age was too tight for the observed XLF near-delta quote. This probe reuses the convex data plane's 60-second freshness bound without changing the global option setting. Selection and the option rails use a fresh quote at each entry or exit attempt; a stale quote blocks.
- Where the broker does not expose explicit settled cash, the shared cash estimator subtracts pending deposits as well as unsettled sale proceeds. A mocked probe entry with buying power entirely from a pending deposit creates no contract-selection or order traffic.

## Live lifecycle readback

- Independent theta lead review: GO on the execution bytes after six isolated regression reproductions passed. Reviewed code hashes are recorded in the review task. Full local Docker suite after the journal follow-up: 378 tests, 3996 assertions; PHPStan and Pint pass.
- 18:47 UTC preflight: pinned account at option level 2 with agentic permission; $164.07 cash, estimated settled cash, and buying power; pending deposits zero; no open option orders or positions. XLF 2026-10-23 55.5 call, broker option ID `712f74a7-c0ae-47af-a012-9e87a2c3d631`, near 0.20 delta, bid $0.19/ask $0.22, fresh quote.
- Entry order `6ac693e3-fe22-4ae4-a7e4-f8fb5fe59678` bought one contract at $0.22. Broker order and matching option position established ownership. A brief overlapping tick hit the lifecycle lock; later ticks reconciled normally. Some interim exit quotes aged out and were retried on later ticks without placing an order.
- The ten-minute time exit fired at 18:58:03 UTC. Exit order `6ac6963f-59aa-4951-b0f7-a512b3106734` sold one contract at $0.20. By 18:58:40 UTC, the supervisor had broker-confirmed both whole-contract fills, terminal exit, and zero remaining position; status `closed`.
- `theta:option-reconcile` found zero open broker option positions and both strategy-labelled journal rows filled. Gross realized P&L was **−$2.00 before fees**. The first reconciliation left the close P&L blank because the close row was processed before its entry; a second pass booked −$2.00. `OptionReconcileCommand` now applies opening fills before closes, and a close-first broker-order regression test passes in one pass.
- Post-run portfolio readback: cash $161.98, estimated settled cash $141.98, buying power $142.03, pending deposits zero, and no open option positions. The estimator correctly holds the $20.00 option sale proceeds as unsettled until 2026-10-08. These account balances are broker readback, not a fee-adjusted strategy return calculation.

## Commands and acceptance

```bash
docker compose -p theta exec -T app php artisan theta:convex-probe status --thesis=convex-mechanical-probe-20261007
docker compose -p theta exec -T app php artisan theta:convex-probe enter --thesis=convex-mechanical-probe-20261007
docker compose -p theta exec -T app php artisan theta:convex-probe tick --thesis=convex-mechanical-probe-20261007
docker compose -p theta exec -T app php artisan theta:convex-probe exit --thesis=convex-mechanical-probe-20261007
docker compose -p theta exec -T app php artisan theta:option-reconcile
```

## Explicit expiry and multiple-contract follow-up (October 7)

An external `thesis_expired` event is persisted per `thesis_id` with an immutable event ID, source, reason, and timestamp. The frozen broker `option_id` must be supplied when signaling an active probe. `theta:convex-probe expire --thesis=<id> --option-id=<broker-id> --event-id=<id> --source=<producer> --reason=<evidence>` persists the event first, then immediately ticks its broker lifecycle. It takes priority over `max_hold_minutes` and `time_stop`; it cancels a resting entry or exit without waiting for their three- or two-minute timers. It never skips order identity, position ownership, fresh quote, review, or sell-to-close rails. A cancelled exit that the broker confirms can reprice within that same tick. If a quote or broker confirmation is missing, the event stays durable and the supervisor retries; the command reports the blocker.

Matrix `ADMIT` and `REJECT` remain **entry** labels. Probe status uses `THESIS_ACTIVE`, `THESIS_EXPIRED`, `ATTENTION_REQUIRED`, `CLOSED`, or `STAGED`. Matrix-backed entry requires a hash-matched row from the append-only feature CSV, a valid absolute timestamp no older than 15 minutes, `ADMIT`, no failed signals, a matching call symbol and tenor, and a near matching far-call delta and strike. The selected live contract must match that row's exact tenor and bounded call wing. The row hash is SHA-256 over the parsed CSV header/value object encoded as compact JSON with unescaped slashes. Each run remains a separate database row; `tick` handles every nonterminal run, catches a per-run failure, and continues to later runs. The unit fixture shuffles two broker orders, positions, and quotes across two distinct IDs; an independent review also checked a locked first run does not prevent later runs from reconciling.

Independent review on the expiry code returned GO after checks for cancellation fall-through, matrix binding, strict timestamp parsing, and per-run error isolation. Local Docker: 386 tests / 4,042 assertions, scoped PHPStan clean, Pint clean. Repository-wide PHPStan still has 27 pre-existing errors in unrelated cockpit and residual files. The `2026_10_07_190000` expiry migration ran against local Theta MySQL and then production Theta MySQL. The original live test used local bind-mounted app code; the broker plumbing was subsequently deployed to production.

Live first expiry token: a fresh XLF 72-DTE matrix row at 19:29:11 UTC admitted the 2026-12-18 58 call, broker option ID `eb75f058-07f2-4e05-90f9-bd5229fb2442`. One contract bought at $0.38 in order `6ac69e01-f4f4-4c3b-8003-99430dd02894`. The supervisor established a matching broker position and a fallback hold deadline of 19:36:13 UTC. A controlled **test** event from `operator_mechanical_probe` expired the thesis at 19:31:55 UTC; sell-to-close order `6ac69e2f-1ba8-495b-90ff-6ed0f6bbd157` was submitted immediately. By 19:32:15 UTC, broker truth confirmed one exit fill at $0.33, terminal closure, and zero option position. Gross result was −$5.00 before fees. `theta:option-reconcile` marked both rows filled and the close journal `realized_pnl=-5.00`. The first reconciliation exposed a timestamp pairing bug when entry and close journals were created minutes apart; the reconciler now pairs a close with its earlier opening order intent, and a spaced-time regression passes.

After that exit, the pinned account showed $103.89 estimated settled cash, $103.99 buying power, and zero nonzero option positions. The adjacent distinct XLF 57 call remained above the existing $50 per-underlying debit rail; other read-only ADMIT candidates lacked a fresh fundable contract. No second order was placed. A later live second token must have its own fresh ADMIT row, new immutable spec ID, distinct broker option ID, current settled-cash/rail preflight, machine-emitted matrix expiry event, terminal broker confirmation, and journal readback. The engine has no two-position cap; this is the two-token verification sample.

The first command is local-state inspection. `enter` is a consequential live order. `tick` and `exit` may cancel or place a sell-to-close for an already entered probe. The no-fill path is accepted only when the entry order is broker-confirmed cancelled and the position is zero. The filled path is accepted only when entry and exit broker IDs, whole-contract fills, terminal states, zero remaining broker position, and journal reconciliation agree. Any mismatch remains open as an execution defect.

## Remaining runtime step

Both additive migrations ran locally and in production. Production `main` has the thesis-spec value objects and convex probe dependencies; the manually operated first expiry test ran locally. The production kill-switch configuration defaults on, but its effective value read back false from an existing environment override on October 7. Verify that value and broker/account truth at activation.

## Machine-owned loop

The scheduled `theta:convex-auto` loop reads the append-only matrix every ten seconds. A fresh ADMIT row for a configured ticker and tenor becomes a deterministic immutable thesis spec; the loop stages and checks a one-contract entry without a human or agent command. `HARNESS_CONVEX_AUTO_ENTRY_ENABLED` controls **new** entries and defaults off until activated. Disabling new entries never disables supervision of existing positions. Entry occurs only during 09:30–15:45 New York time, and only once per symbol and tenor per session after an order submission. All existing settled-cash, premium, spread, position-count, broker review, and pinned-account rails still apply. The loop is generic over the run table and has no two-position special case.

For an owned run, the latest fresh matrix row for its symbol and current tenor must be **newer than its entry** before it can alter stewardship. A REJECT with no pending signals and a failed `conventional_smirk`, `fringe_not_rich`, or `quiet_surface_proxy` emits a deterministic, durable `thesis_expired` event. The event is persisted before an immediate broker lifecycle tick; that tick prioritizes liquidation ahead of the hold and order timers. An `executable_wing` failure alone is an entry or liquidity issue, not structural expiry. A later ADMIT supersedes an older REJECT, so the loop does not liquidate from stale failure evidence. ADMIT/REJECT remain matrix entry labels; active runs report stewardship labels such as `THESIS_ACTIVE` and `THESIS_EXPIRED`.

The scheduler runs the loop every ten seconds and the read-only matrix ingest every five minutes. Thus reaction after a newly appended matrix row is bounded by the loop cadence, while **market observation latency is still bounded by the five-minute ingest cadence**; this deployment does not turn the broker feed into a streaming firehose. The loop uses a MySQL connection-owned advisory lock so competing processes cannot act concurrently and a crashed worker releases the global lock on disconnect. The per-run lifecycle lock, wallet lock, order refs, and broker-confirmed ownership checks remain in place. The manual `theta:convex-probe` command remains available for diagnostics and recovery, not normal operation.

The second live expiry token remains outstanding until a distinct fresh fundable ADMIT contract appears and the autonomous path confirms its entry, expiry, zero position, and journal rows. Do not infer success from the synthetic multi-run tests alone.

Rollback: do not enter while staged; a staged row creates no broker exposure. Once filled, use the normal supervised sell-to-close path and broker reconciliation before considering code or scheduler rollback. Never drop the ownership table while a probe is nonterminal.
