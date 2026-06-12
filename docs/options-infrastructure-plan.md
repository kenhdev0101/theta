# Agentic Options Infrastructure Readiness Plan

Status: ACTIVE — build-now options lane for live harness integration
Scope: Robinhood options candidates, watchlist preparation, deterministic math, and audit fields

## Current Boundary

Build the options lane now as deterministic math, journal state, resolver logic,
review handling, and order-path integration points for the live harness. The
existing options playbook is the ingestion source: `tools/options-math/` emits
JSON and trade files under `docs/playbook/` hold thesis/math/kill triggers.

Broker writes stay gated by actual broker/account capability. `add_option_to_watchlist`
requires contract UUIDs from `get_option_instruments`; before that resolver is
available, the system produces complete normalized contract rows with a named
`broker_capability_pending` state rather than pretending an order can be routed.

## Candidate Schema Usage

Use `robinhood_trade_journal` as the durable candidate ledger for options rows
without changing its schema:

| Field group | Required option usage |
|---|---|
| Identity | `asset_class=option`, `symbol` as display contract symbol when known, `underlying_symbol`, `option_id` nullable until broker instrument lookup exists |
| Contract | `option_type`, `expiration_date`, `strike_price`, `intent=long_call|long_put|watch_only`, `structure_class=single_leg_long` initially |
| Lifecycle | `status=candidate -> broker_capability_pending -> watchlisted -> reviewed -> submitted/open -> closed/rejected/expired` |
| Sizing | `quantity`, `limit_price`, `review_estimated_cost`, `average_fill_price`, `filled_quantity` after broker review/fill is available |
| Thesis | `strategy_label=options_portfolio_manager`, `relative_strength_state`, `gap_classification`, `trend_geometry_state`, `liquidity_tier`, earnings timing fields |
| Artifacts | `scanner_payload`, `option_math_payload`, `review_payload`, `broker_payload`, `notes` as immutable-ish JSON snapshots |
| Outcome | `watchlisted_at`, `reviewed_at`, `submitted_at`, `filled_at`, `closed_at`, `realized_pnl`, `unrealized_pnl` |

The row should exist before any future broker review. That makes every action
traceable from scanner signal to math to review to final outcome.

## Option-ID Ingestion and Watchlist Workflow

1. Candidate generator emits an option candidate without `option_id` using
   normalized contract fields: underlying, call/put, expiry, strike, target limit,
   thesis, invalidation, and math payload hash.
2. When `get_option_instruments` is available, resolve the exact Robinhood contract
   UUID from those normalized fields. Reject ambiguous matches and stale expiries.
3. Stamp `option_id`, broker contract metadata, bid/ask/mark, open interest if
   returned, and resolver timestamp into `broker_payload.option_instrument`.
4. In active live mode, call `add_option_to_watchlist` with the resolved
   `option_id`; then set `status=watchlisted` and `watchlisted_at`.
5. If the contract cannot be resolved or the market differs from the candidate
   by more than the allowed drift, keep `status=broker_capability_pending` and
   record the exact broker/data blocker in `notes`.

Until instrument lookup exists, generate a `broker_capability_pending` row plus
a human-readable contract string. Do not synthesize option IDs.

## Deterministic Math Artifacts

Every option candidate must carry a deterministic `option_math_payload` generated
from `tools/options-math/` and the exact CLI inputs used:

| Script | Required use |
|---|---|
| `single_leg_ev.py` | Required for current Robinhood Tier 2 single-leg long calls/puts. Gate on both risk-neutral and drift-adjusted outputs. |
| `ev_defined_risk.py` | Required once spreads are allowed or represented as candidates. `NEGATIVE_EV` rejects the candidate. |
| `kelly_fraction.py` | Required sizing anchor. Quarter-Kelly remains the policy upper reference. |
| `iv_rank.py` | Required IV/HV context, with the documented HV-rank caveat. |
| `expected_move.py` | Required for event/earnings/FOMC candidates and any expiry selection. |
| `regime_classify.py` | Required directional regime and structure hint. |

Artifact payload should include script name, git SHA if available, CLI args,
stdout JSON, computed timestamp, source freshness, and any yfinance/data errors.
A candidate with missing math can be saved as `candidate`, but cannot advance to
broker-capability resolution, watchlist, review, or submission states.

## Performance Tracking Fields

Track enough outcome data to compare candidate quality before order routing:

| Metric | Storage target |
|---|---|
| Entry plan vs actual entry | `limit_price`, `review_estimated_cost`, `average_fill_price`, `notes.entry_drift` |
| Contract mark history checkpoints | `notes.marks[]` until a proper price history table exists |
| Max favorable/adverse excursion | `notes.mfe`, `notes.mae` |
| Exit trigger hit | `notes.exit_trigger=price_stop|time_stop|thesis_stop|profit_take|manual` |
| EV calibration | `notes.ev_at_entry`, `realized_pnl`, `unrealized_pnl`, `closed_at` |
| Rejected candidate outcome | `status=rejected`, `notes.reject_reason`, optional later mark/outcome snapshot |
| Watchlist conversion | `watchlisted_at`, `notes.watchlist_result`, `option_id` |

Do not rely only on closed trades. Rejected and watchlisted-but-not-entered
candidates are required to measure whether the gates are too strict or too loose.

## Validation Gates

Promotion gates should be mechanical:

1. Contract completeness: underlying, option type, expiry, strike, side, target
   limit, DTE, and thesis are present.
2. Playbook discipline: price stop, time stop, and thesis invalidation are all
   present before watchlist promotion.
3. Math completeness: required script outputs are attached and parse as JSON.
4. EV gate: reject if single-leg risk-neutral and drift-adjusted EV are both
   `NEGATIVE_EV`; reject defined-risk structures on `NEGATIVE_EV`.
5. Sizing gate: quantity and max debit fit bankroll policy; quarter-Kelly is an
   upper anchor, not a mandate.
6. Liquidity gate: when broker/instrument data exists, require non-stale bid/ask,
   tolerable spread width, and sufficient OI/volume or explicit low-liquidity
   override.
7. Staleness gate: yfinance-derived math and broker quotes must carry timestamps;
   stale inputs block review.
8. Live-write gate: option watchlist/order writes require the broker capability,
   account permission, deterministic rail approval, and the active operating mode.

## Broker-Gated Activation Checklist

As soon as `get_option_instruments` is available:

- Add a resolver command/service that maps journal candidate fields to one exact
  `option_id`.
- Add fixture tests for exact match, no match, duplicate/ambiguous match, expired
  contract, and strike precision.
- Backfill option IDs for open `broker_capability_pending` candidates only after a fresh
  broker lookup.
- Enable confirmed `add_option_to_watchlist` from journal rows.

As soon as `review_option_order` is available:

- Add review workflow from `status=watchlisted`.
- Persist full review response in `review_payload` and estimated cost in
  `review_estimated_cost`.
- Block review if math payload is stale or broker quote violates the candidate
  limit/slippage gates.
- Add tests proving review cannot mutate order-submission fields.

As soon as `place_option_order` is available:

- Require prior successful review, deterministic rail approval, active live mode,
  and a stable `client_ref_id` for idempotency.
- Submit only the reviewed contract, side, quantity, order type, limit, and TIF.
- Persist `broker_order_id`, `submitted_at`, and full response in `broker_payload`.
- Add cancellation/reconciliation follow-up before retries.
- Route through the same live rail/reconciliation spine used by equities.

## Build-Now Work Queue

1. Journal writer for option candidates from playbook/scanner output.
2. Math payload normalizer that wraps `tools/options-math/` JSON outputs with CLI
   args, timestamps, and errors.
3. Markdown-to-journal extractor for current playbook fields: thesis, structure,
   kill triggers, sizing, references, and rejected reason.
4. Candidate validation command that prints pass/block reasons without broker
   writes.
5. Broker-capability-pending report grouped by underlying, expiry, EV verdict, and missing
   broker instrument ID.
6. Outcome updater for manual fills/closes so performance fields are populated
   before order tools exist.

## Non-Goals

- Do not route option orders until broker review/place tools exist and account permission is verified.
- Do not invent Robinhood option IDs or infer them from OCC-style symbols.
- Do not replace the current playbook; ingest from it and preserve its audit
  trail.
- Do not loosen the existing EV and kill-trigger rules to increase volume.
