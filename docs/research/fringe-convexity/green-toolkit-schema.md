# Green toolkit — convex-wrapper napkin schema

**Owner:** model-lane / claude &nbsp;|&nbsp; **Agent ID:** claude/theta-fringe (model-lane) &nbsp;|&nbsp;
**Scope:** standalone schema for the fringe-convexity napkin toolkit — six stdlib primitives under `tools/options-math/`, each callable over HTTP via the greeks service `POST /run/{key}` &nbsp;|&nbsp;
**Status:** napkin / NOT authoritative — a coherent baseline posture tested live with small clips; the market is the judge &nbsp;|&nbsp; **Date:** 2026-10-06

Thesis (see memory `convex-wrapper-thesis`): we WRAP the venue and EXPRESS convexity — we do not predict. Greeks are supplementary instrumentation. The edge is expression latency vs retail, not forecasting. Hard gates test **expressibility** (shape / execution / catalyst); cheapness is a reported diagnostic, not a veto.

## Pipeline

```
convex_terrain_filter   ──(ADMIT?)──▶  anchor_select  ──▶  convex_precompute  ──▶  fire_row
   │  (fail-closed gate)                  (pick legs)        (convexity + trigger)
   ├─ uses convex_fill_sim  (executable_wing gate: achievable cost, not quoted spread)
   └─ over fringe_response / fringe_surface  (reprice-at-destination math underneath)
```

## Primitives

| key (`/run/{key}`) | file | role |
|---|---|---|
| `fringe_response` | fringe_response.py | reprice-at-destination + 3-regime fan |
| `fringe_surface` | fringe_surface.py | sweep fringe_response across strikes×tenors×moves |
| `anchor_select` | anchor_select.py | thesis-free anchor+fringe legs from the vol cone |
| `convex_precompute` | convex_precompute.py | fire-ready convex row (lookup at fire time) |
| `convex_terrain_filter` | convex_terrain_filter.py | fail-closed admissibility gate |
| `convex_fill_sim` | convex_fill_sim.py | mid-anchored re-peg execution simulator (liquidity-aware) |

### fringe_response
Reprices a contract at a destination (spot', iv') under three skew regimes rather than trusting a greek-Taylor sum. **In:** side, spot, strike, iv, dte, premium, `--moves`, `--skew-slope`, `--beta-t`, `--hold-days`. **Out:** base greeks (delta-accel, dollar-gamma [labelled exposure, not P&L], vanna/volga per vol-pt), per-move × per-regime {d_sigma, iv_dest, price_dest, payoff/contract, return_multiple, greek attribution + taylor_residual}.

### fringe_surface
Loops fringe_response over a grid. **In:** side, spot, `--atm-iv`, `--skew-k`, `--strikes`, `--dtes`, `--moves`, `--delta-floor`. **Out:** per-cell standardized moneyness x=ln(K/F)/(σ√T), basis_gap flag (labelled crude), regime flip grids (ASCII), non-destructive gate ledger (keeps rejected cells).

### anchor_select
Computes the anchor (~1σ, delta-accelerating) and fringe (~2.5σ) strikes from {spot, atm_iv, dte} via standardized moneyness — no human thesis. **In:** side, spot, dte, `--atm-iv` or `--rows` (broker mode), `--thesis-json` (shim override), `--x-anchor`/`--x-fringe`. **Out:** anchor/fringe legs with greeks, `delta_accel_per_1pct`, skew_residual_vs_atm.

### convex_precompute
Composes anchor_select + fringe_response into one fire-ready row so execution is a lookup, not a computation. **Out:** fire_row {anchor trigger, fringe, local_skew_slope (finite-diffed from broker rows), per-regime convexity (asymmetry_ratio, convex_accel, flip_move)}. Convexity is read off the realized reprice curve, not a greek.

### convex_terrain_filter  (fail-closed admissibility gate)
Quote-quality pre-filter FIRST (strip null/zero-IV, zero-bid, crossed, toxic >15000 bps). Then six signals:

| signal | kind | passes when |
|---|---|---|
| `conventional_smirk` | HARD | 25Δ RR ≤ −0.01 (calls cheaper than puts) |
| `fringe_not_rich` | HARD | deep-OTM call IV − ATM IV ≤ +0.02 (no lottery wing) |
| `iv_rv_ratio` | **DIAGNOSTIC** | reported only — never gates (cheapness ≠ expressibility) |
| `quiet_surface_proxy` | HARD | clean-wing IV dispersion ≤ 0.06 (vol-of-vol proxy) |
| `executable_wing` | HARD | achievable cost ≤ 1500 bps AND fill_prob ≥ 0.50 (via convex_fill_sim) |
| `no_catalyst` | HARD | no earnings/binary event on/before expiry |

`pending` (insufficient clean data) ≠ `fail`, but both block ADMIT (fail-closed). `diagnostics` never gate. **Verdict:** ADMIT iff no hard gate fails and none pending. **In:** ticker, spot, dte, call_rows, put_rows (each {strike, iv, delta, bid, ask, volume?, open_interest?}), `--realized-vol`, `--catalyst-days`.

### convex_fill_sim  (liquidity-aware)
Models a programmatic price-taker posting at mid and re-pegging toward the ask (capped halfway), so a wide quote is costed, not rejected. **In:** bid, ask, `--volume`, `--open-interest`, re-peg params. **Out:** achievable_cost_bps (conditional on fill), fill_probability, spread_factor, liquidity_factor, availability, executable flag. Fill curve scales by `availability = spread_factor · liquidity_factor` so thin/wide wings get a low fill prob — no free pass.

## Feature matrix (`convex_feature_row` → `/run/convex_feature_row`)

Flattens the whole pipeline into ONE standard row per `(ticker, tenor, snapshot)` — the modular feature extractor that makes the primitives feed a later lightweight **outcome-feedback / calibration** loop (NOT a predictor; wrapper posture, no forecasting). Emits `json`, `csv`, or `csv-header`. Column groups (ordered in `FEATURE_COLUMNS`):
- **provenance:** ticker, bucket, snapshot_ts, agent_id, policy_version, source, spot, dte
- **surface:** atm_iv, realized_vol, iv_rv_ratio, rr25, fringe_richness, quiet_dispersion
- **geography:** max_quotable_sigma, n_quotable_past_2sigma, wing_slope, vega_class
- **execution:** far_strike, far_delta, quoted_spread_bps, achievable_cost_bps, fill_probability, availability
- **convexity:** anchor_strike, anchor_delta_accel, fringe_strike, convex_asymmetry, convex_accel, flip_move
- **gate/label:** verdict, failed_signals, pending_signals, diagnostics
- **RESERVED outcome (null at emit; joined later):** o_filled, o_entry_fill_px, o_exit_px, o_pnl_pct, o_underlying_move_pct, o_exit_reason, o_closed_ts

A sweep appends rows → a feature matrix; the outcome columns are filled when a realized fill/exit is joined, closing the loop for calibrating the napkin constants against the market's verdict.

**Locked + validated by `convex_feature_schema`** (`/run/convex_feature_schema`): column lock (validated columns must equal `convex_feature_row.FEATURE_COLUMNS`, asserted at import) + per-cell type/range/enum/nullability contract. Validates a CSV or a single JSON row; `--emit-schema` prints the canonical contract. Verified: the live 9-row index matrix passes clean; bad enums / out-of-range / dropped columns fail loudly. Full engine architecture: `green-engine-architecture.md`.

## Napkin calibration backlog (none are law — grounded v1 in perplexity_calibration.md)
- `convex_fill_sim`: current linear `w(f)` + `passive_fill_prob` 0.15 / `SPREAD_PENALTY_BPS` 12000 / `LIQ_REF` 1500 are an uncalibrated placeholder SHAPE. **Grounded v1:** sigmoidal hazard/survival `P=1−e^{−λT}`, `λ(d)=A·e^{−k·d}`, sequential accrual with priority-reset on re-peg, depth/contra-flow features, spread NOT double-counted — calibrated to our own Robinhood fill logs (which we don't have yet; blocks true calibration).
- `fringe_response`/`convex_precompute`: `beta_t`/`skew_slope` placeholders. **Grounded v1:** local quadratic in total variance `w(k)=Tσ²(k)` with an interval (bid–ask) loss, quote-quality weights, fringe slope + shape-change carried as RANGES; `β_T` from synchronized history (`Δσ_ATM=β_T·Δln F`), NOT a snapshot. Fringe slope from thin quotes is the key instability.
- `quiet_surface_proxy`: cross-sectional dispersion is a PROXY; true vol-of-vol needs an IV time series.
- Thresholds (`RR_MAX` −0.01, `rich_max` +0.02, `DISPERSION_MAX` 0.06, `ACH_COST_BPS_MAX` 1500, fill-prob floor 0.50, realized-vol 20d window) are all napkin starting points to retune against live readbacks.
- Time convention: fringe_response/anchor_select use calendar DTE/365; single_leg_ev.py uses /252 — reconcile.
