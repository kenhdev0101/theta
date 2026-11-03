# Tacit equity basket v1 runtime

Owner: desk. Implementation: Theta engineering / codex/root.

This is the long-only fractional equity wrapper for the human-scored weights in `docs/weighting-basket.csv`, described in §§10–11 of `docs/ai-friction-convergence-thesis.md`. It is separate from the options thesis lane and Convex. No model selects names or weights. V1 does not sell, impose stop losses, take profits, or expire held positions on a timer.

## Activation and invocation

1. Run `php artisan theta:basket-rebalance --dry-run` against live broker truth. This creates a durable `rebalance_events` parent and one `rebalance_legs` row per name, including named holds/drops. No orders or order references are created.
2. Review the saved plan and select its ID in `harness.basket.active_event_id`. Set `basket.enabled` and `basket.armed` only under desk authorization. The existing kill switch must permit trading. A fixture preview or changed weight hash cannot arm.
3. The scheduler invokes `theta:basket-rebalance --arm --trigger=scheduled` each minute during 09:30–16:10 New York weekdays. Each invocation resumes that same event, with up to five new orders. Manual invocation follows exactly the same path and uses the same process-owned account lock as Convex.
4. New orders require the regular session and the event's original New York admission date. Readback may continue after close or on subsequent days. This is an admission deadline, not a holding-period exit.

The desk authorized up to 39 positions **for the basket only** on November 3, 2026, retaining $50 per order, $50 per symbol, and $200 daily debit caps. Global and Convex configuration remain unchanged. Orders are bounded dollar-notional market buys because that is the broker's fractional-notional wire format. Current settled cash, account order state, fresh tradability, programmatic review, and all existing equity rails govern every placement. Buying power is never treated as settled cash.

## Retry and reconciliation

A selected event fixes both symbols and dollar amounts. Each leg has an event-scoped logical order ID and durable broker reference. An API credit rejection before dispatch leaves the same reference retryable. An unknown placement outcome freezes the batch for reconciliation rather than generating a replacement order. Working account orders block additional admissions until readback resolves them. A completed event never creates another batch.

`cash_deployed` counts broker-confirmed executed quantity × fill price plus fees. A submitted order is not a fill. The existing trade journal stores fill quantities and prices under strategy `theta_basket_etf`; legs link by `client_ref_id` and broker order ID. Event notes record tick time, trigger, submissions in that tick, and concrete blockers.

## Deliberate v1 boundary

This launch executes one reviewed initial basket plan. Deposits, new weights, or new admission dates require a fresh live preview and explicit selection of its event; they do not silently spend through the old event. Unsupported fractional names remain named drops rather than substitutes. Broker review/placement is the holiday and exceptional-session backstop; the local clock models regular weekday hours. No liquidation policy is introduced here.

Operational evidence lives in the rebalance ledger, linked trade journal, scheduler output, and broker readback. The existing Convex cockpit remains unchanged.
