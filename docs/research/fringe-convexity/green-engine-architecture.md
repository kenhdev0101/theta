# Green convexity engine — standalone inference-wrapper architecture

**Owner:** model-lane / claude &nbsp;|&nbsp; **Agent ID:** claude/theta-fringe (model-lane) &nbsp;|&nbsp;
**Scope:** architecture spec for the convex-wrapper inference engine — data plane, the six primitives, the gate logic, the liquidity/convex-fill model, the near-0.20Δ gamma-expression lane, and the feature-matrix + outcome-feedback loop &nbsp;|&nbsp;
**Status:** foundation locked (napkin constants uncalibrated — see perplexity_calibration.md); read-only; any live step is operator-authority &nbsp;|&nbsp; **Date:** 2026-10-06

## 1. Thesis & posture (why this is a wrapper, not a model)
We WRAP the venue and EXPRESS structure; we do not predict. The venue (Robinhood/OPRA) has already computed the option surface — an efficient machine. Our job is a clean inference layer that reads the book's computed boundaries, fires inside the retail-latency gap when the structure says "express," and leaves when the regime turns. No model on the money path; Greeks are supplementary instrumentation, never the authority (architecture law: deterministic PHP owns execution; Python is stateless math behind HTTP).

## 2. The structural law this engine is built on (proven 2026-10-06)
Across ~24 live observations (15 single-names/sectors + SPY/QQQ/IWM × 45/73/101 DTE): **pure long-call convexity — a quotable ≥2σ tail AND a down-sloping deep call wing (rally vega tailwind) — does not exist in the liquid US surface.** Tail depth and a vega tailwind are universally anti-correlated: the deep 1–5Δ call wing turns UP (U-shaped smile) everywhere, so a rally crushes a fixed strike's IV (vega headwind). Consequence: **the realizable product is a near-0.20Δ directional-GAMMA expression on a liquid, cheap-to-fill wing — not a deep-OTM vega harvest.** The engine is designed around that reality.

## 3. Layers

### 3a. Data plane (broker truth, read-only)
`get_option_quotes` returns full per-contract greeks (δ,γ,θ,vega,ρ,IV) + bid/ask + volume + open_interest + `updated_at`. The hot path reads broker truth; the greeks service (`tools/options-math/*` behind `/run/{script}`) is stateless math only — no orders, no state, no credentials. Every feature carries provenance (snapshot_ts, source, agent_id, policy_version).

### 3b. Inference primitives (six, all `/run/*`, stdlib)
| primitive | role |
|---|---|
| `fringe_response` | reprice-at-destination + 3-regime (sticky-strike / sticky-moneyness / leverage) fan; greek attribution as audit |
| `fringe_surface` | sweep strikes×tenors×moves; standardized-moneyness axis; flip grids |
| `anchor_select` | thesis-free anchor (~1σ) + fringe (~2.5σ) legs from the vol cone; JSON shim to probe |
| `convex_precompute` | fire-ready row: convexity (asymmetry/accel off the realized reprice curve), flip, anchor delta-accel trigger |
| `convex_terrain_filter` | fail-closed admissibility gate (below) |
| `convex_fill_sim` | mid-anchored re-peg execution simulator, liquidity-aware (below) |
| `convex_feature_row` | flattens the pipeline into one standard 41-col feature row (§3e) |
| `convex_feature_schema` | locks + validates the feature-matrix contract (§3f) |

### 3c. Gate logic (expressibility, not cheapness)
Quote-quality pre-filter FIRST (strip null-IV / zero-bid / crossed / toxic strikes). Then HARD gates — all purely computed surface signals:
- `conventional_smirk` (25Δ RR ≤ −0.01), `fringe_not_rich` (deep-call IV − ATM ≤ +0.02, lottery-wing guard), `quiet_surface_proxy` (IV dispersion), `executable_wing` (achievable cost + fill prob, §3d).
DIAGNOSTICS (reported, never gate): `iv_rv_ratio` (cheapness ≠ expressibility), `no_catalyst` (the surface already prices earnings; no hand-fed calendar, no universe pre-filter). `pending` (insufficient clean data) ≠ `fail` but both block ADMIT (fail-closed).

### 3d. Liquidity / convex-fill model — where convex fills actually live
`convex_fill_sim`: a programmatic price-taker posts at MID and re-pegs toward the ask on a bounded schedule (capped halfway). The quoted spread is NOT the cost — we estimate the ACHIEVABLE cost (conditional on fill) + a fill PROBABILITY that scales with `availability = spread_factor × liquidity_factor` (spread width × volume/OI). A thin/wide wing earns a low fill prob (no free pass); a deep-but-liquid wing (seen at 73–101 DTE on index names) can clear. Empirically the **near-0.20Δ wing** is where fills are both cheap (achievable ~5–35 bps vs raw half-spread, fill ~0.90) and liquid — that is the executable surface. The deep 1–5Δ tail is often a quote desert and, where quotable, a vega headwind — so it is NOT where we express.

### 3e. The near-0.20Δ gamma-expression lane (the realizable product)
Given the §2 law, the engine's standalone product is: when a name passes the hard gates, express a **~0.20Δ call** (the anchor leg — liquid, cheap to fill, delta+gamma-dominated convexity, minimal vega dependence) — fired in the retail-latency gap via the mid-anchored re-peg, held for a fast gamma move, exited on the move or when the regime turns. No deep-vega bet (the surface won't pay it). `convex_precompute` precomputes the fire row so the decision at the instant is a lookup, and `anchor_select`'s `delta_accel_per_1pct` is the fast local trigger. Exit discipline (from the grounding): time stop, extrinsic abort, fast take-profit — leave on the spike, don't ride.

### 3f. Feature matrix + outcome-feedback loop (the modular inference output)
Every observation → one `convex_feature_row` (41 cols: provenance / surface / geography / execution / convexity / gate-verdict / reserved outcome). A sweep appends rows → a feature matrix. `convex_feature_schema` LOCKS the contract: the validated columns must equal `convex_feature_row.FEATURE_COLUMNS` (drift guard) and every cell must satisfy its type/range/enum/nullability rule — a drifting primitive fails loudly before it corrupts the matrix. The reserved outcome columns (`o_filled`, `o_pnl_pct`, `o_underlying_move_pct`, …) are joined with the realized fill/exit LATER, closing a lightweight calibration loop (not a predictor) that grades the napkin constants against the market's verdict.

## 4. Single-decision control flow
`read broker quotes → convex_feature_row (extract + gate) → convex_feature_schema (validate, reject on drift) → if ADMIT: express the ~0.20Δ wing via convex_fill_sim's re-peg in the latency gap → log the feature row → later join the realized outcome for calibration.` No step predicts; each reads the venue's computed state.

## 5. Calibration-gated (napkin → live)
Uncalibrated placeholders, all flagged: `convex_fill_sim` fill curve/constants (grounded v1 = sigmoidal hazard/survival fit to our own RH fills), `beta_t`/`skew_slope` (grounded v1 = local quad-in-total-variance fit + β_T from synchronized history), dispersion-as-vol-of-vol proxy, all thresholds. None can be truly calibrated until the outcome-feedback loop has logged our own fills/IV history. See perplexity_calibration.md.

## 6. Boundaries
Read-only today. Any live placement is operator-authority under the capital firewall (pinned agentic cash account, settled-cash rails, no margin/short/naked). Broker/account truth outranks logs. The market is the judge.
