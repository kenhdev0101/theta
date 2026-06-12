# theta — Intraday Structure-Momentum Engine (Anatomy)

Owner: operator
Agent ID: lead-codex
Scope: The trading brain — universe scan → structure classification → relative-strength ranking → execution policy → risk. Instrument-agnostic spine; equities now, options at Robinhood GA.
Status: ACTIVE — build anatomy
Date: 2026-06-11
Last updated: 2026-06-12

## Relationship to the other docs

- **Architecture** (`docs/architecture.md`) — how the machine is built and isolated: inference/determinism law, rails, capital firewall, compose topology. *That* doc owns the execution-path discipline; *this* doc owns the trading logic that rides on it.
- **Bootstrap** (`docs/bootstrap.md`) — how to stand up the `theta` repo.
- **Options infra** (`docs/options-infrastructure-plan.md`) — the candidate/journal/Greeks-math layer the options phase plugs into.

## 0. Frame — what this is (and isn't)

**Not a stock picker. A breadth × speed intraday structure-momentum engine.** The infra earns its leverage only by doing what a human cannot: classify *hundreds* of symbols' structural state every minute, rank them, act in milliseconds — on a zero-fee venue where short holds cost nothing. A watchlist for long-term holds has zero leverage over doing it by hand; that is explicitly out of scope.

Edge = **breadth × speed × consistency**, on Robinhood's zero-fee equity venue that makes short intraday holds free. The unit of work is a continuous RTH funnel: every bar, over the universe, classify each symbol's current structural state, rank the tradeable ones, run entry/lifecycle/risk on the top cohort, exit on structural break. We harvest intraday momentum; we do not hold a thesis all day.

**Instrument-agnostic spine (the reason building on equities now is leverage, not throwaway):** scan → classify → rank → risk is one brain. Only the *execution/sizing organ* swaps when options GA lands (equity share → option contract via the Greeks layer), and the short half of the cross-section unlocks via puts. The taxonomy, RS, and risk layers are unchanged across instruments.

**Much of the brain is already prototyped.** `tools/equity-structure/` (built 2026-06-09) implements the classifier thresholds, the intraday state-map, RS, universe, and the normalization helpers on equities — see **§13** for the full reuse inventory and what's net-new (the transition layer + missing coordinates + re-homing onto the §2 feed).

## 1. The funnel — organs, cadence, layer

| Organ | Cadence | Function | Layer |
|---|---|---|---|
| **Universe** | daily / pre-market | liquidity floor (ADV, price, spread), optionable (forward-looking for options phase), exclude halt-prone / hard-to-borrow | deterministic screen |
| **Feature substrate** (§4) | every bar (1m) | per-symbol feature vector (returns, RVOL, ATR/realized-vol, VWAP+dist, compression, swing structure, gap, level position, vs-index) | deterministic math |
| **Structure classifier** (§3) ⭐ | every bar | feature vector → structural state + confidence + direction | **quant/ML model** (not LLM) |
| **RS ranking** (§5) | every bar | cross-sectional relative strength among tradeable-state names | deterministic |
| **Selection** | every bar | tradeable-state ∩ top-RS ∩ confidence-floor → top-N cohort | deterministic |
| **Execution policy** (§6) | on trigger | marketable-limit default, resting pullback entries, synthetic brackets, vol-scaled size | deterministic rails |
| **Lifecycle / risk** (§8) | every bar | re-classify held names; exit on structural break / stop / RS decay / time; portfolio + settled-cash + kill-switch | deterministic |

**Two planes (do not conflate):**
- **Data plane** — a separate market-data feed (§2). The scan substrate.
- **Execution/account plane** — the Robinhood MCP. Order placement + account/broker truth only.

## 2. Data plane — separate feed, owned like the Coinbase/Kalshi ticks

Robinhood's MCP is a control plane: `get_equity_quotes` / `get_index_quotes` exist but will **not** sustain hundreds-of-symbols-per-minute as a firehose (rate limits, latency). So the scan substrate runs on a **separate market-data feed** — the equity analog of the Coinbase/Kalshi tick stream, and as critical to this system as those are to edge-bot.

- **Shape:** ingest → normalize → serve the scanner. We own and operate it.
- **Reliability bar:** the edge-bot tick discipline ports directly — staleness gates (a frozen quote is not a fresh one), gap handling, WS-health checks, freshness stamps on every feature.
- **Selected v0 vendor:** Alpaca Algo Trader Plus / SIP (`ALPACA_DATA_FEED=sip`) for real-time consolidated U.S. equities data, unlimited-symbol WebSocket, historical bars, snapshots, and options-data readiness. Polygon/Massive remains the first fallback; Databento remains the later pro-feed/depth/replay path.
- **Status:** first build dependency now resolved at the vendor layer; the next gate is the feed-quality probe: staleness, missing bars, reconnect recovery, quote/bar latency, and target-universe throughput.

## 3. Structure classifier — the heart

The taxonomy is not a label set; it is a **hidden-state machine over price structure** — the direct equity analog of the Kalshi geometry primitives (normalized coordinates, states as points on a manifold, **transitions as the signal**). It is a *quant/ML model*, deterministic and auditable — **not** an LLM (§9).

Each symbol carries a `(state, confidence, direction, expected-transition)` tuple each bar. The edge is the *transition*, not the static state. **Entry = a specific set of transitions; exit = another set.**

### State → policy (long-only equities phase)

| State | Tradeable now? | Action |
|---|---|---|
| **compression (likely)** | not yet — direction unknown | **arm** (load the spring) |
| **coil expansion** | yes, if expanding up + RVOL | **PRIME ENTRY** (trigger transition) |
| **continuation** | yes — orderly trend, pullback-and-go | **CORE ENTRY** on reclaim; add |
| **acceptance** | constructive | hold/add; entry on break from acceptance |
| **shelf** | pre-breakout | arm; enter on shelf-break w/ volume |
| **parabolic (possible)** | momentum but late | **no new entry** (chasing); trail tight if held |
| **exhaustion** | no (→ put entry later) | **EXIT** longs |
| **liquidity vacuum** | no | **AVOID** (slippage trap) |
| **danger zone** | no | **HARD AVOID / FLATTEN** |

- **Entry transitions:** {compression → coil-expansion-up}, {continuation pullback → reclaim}, {shelf → break w/ volume}, {acceptance → break}.
- **Exit transitions:** {→ exhaustion}, {→ danger}, {→ liquidity-vacuum}, RS decay, stop, time.
- **Breadth lets the classifier be picky.** With hundreds of candidates, ambiguous/low-confidence names are *skipped*, not reasoned through. The confidence floor is a deliberate filter, and it is the reason the hot path needs no LLM tiebreaker.
- **Bearish states are exit/avoid now, entry later.** When puts land, exhaustion/breakdown/danger flip from exit signals to single-name entries (§7).

### 3.1 Primitive coordinate basis (deterministic, normalized)

The classifier's input is a small set of **normalized geometric primitives** computed from the §4 feature substrate. **Normalization invariant:** every coordinate is scaled to the symbol's own context (ATR units, self-percentile, or RVOL) and/or cross-sectional z-score, so a $5 name, a $500 name, and SPY occupy the *same* coordinate space. A state is a region in this space.

| Coord | Sym | Measures | Definition | Scale |
|---|---|---|---|---|
| Compression | `C` | range/vol contraction | BB/Keltner width (or ATR) as percentile of its own trailing window | [0,1], low = compressed |
| Expansion velocity | `Ċ` | range breaking out | Δwidth over k bars | ATR-norm, signed; + = expanding |
| Drive efficiency | `E` | trend vs chop | Kaufman ER = \|net move\| / Σ\|bar moves\|, signed by direction | [−1,1] |
| Trend integrity | `T` | orderly structure | HH/HL sequence fraction × slope sign | [−1,1] |
| Participation | `P` | volume confirm | RVOL = volume / time-of-day-expected | ratio, ~1 = normal |
| Extension | `X` | stretch from value | signed distance from anchored VWAP/MA in ATR units | ATR z, signed |
| Acceleration | `Ẍ` | curvature | change in slope / 2nd derivative | ATR-norm, signed |
| Acceptance | `A` | value build vs rejection | time-at-price / two-sided rotation at level vs wick-rejection ratio | [0,1] |
| Liquidity quality | `L` | tradeability gate | rel-spread + gappiness composite; + hard danger flag (halt/news) | [0,1] + bool |

`L` is a **gate axis**, not a structural one — it overrides structure (you never trade a great shape in an untradeable book).

### 3.2 States as regions (coordinate signatures)

| State | Signature |
|---|---|
| **compression** | `C` low, \|`E`\| low, `P` normal/low, `Ẍ`≈0 — coiled, no drive |
| **shelf** | compression signature **+ on a defined level** (`A` high at the ledge), flat `T` |
| **coil expansion** | prior ∈ {compression, shelf} **+** `Ċ` high **+** `E` rising & signed **+** `P` > 1 — the trigger |
| **continuation** | `T` high & signed, `E` moderate-high aligned, `X` moderate (not stretched), shallow pullback reclaiming, `P` confirms impulses |
| **acceptance** | `A` high at a freshly-migrated level, `X` low (near value), `E` low-moderate — holds gains |
| **parabolic** | `X` high, `Ẍ` > 0 (accelerating), `P` elevated, `E` high — vertical, late |
| **exhaustion** | `X` high **+** `P` climax spike **+** `Ẍ` < 0 (decel/reversal) **+** `A` breaking (wick rejection) — the top |
| **liquidity vacuum** | `L` poor (wide/thin/gappy) — gate, overrides structure |
| **danger zone** | `L` danger flag (halt/news) **or** extreme parabolic ∩ exhaustion — gate, hard avoid |

### 3.3 The state-map (sequential, rule-based, gated)

Per bar, after computing the coordinate vector:

1. **Gate axes first.** `L` danger → **danger zone**; else `L` poor → **liquidity vacuum**. Quality gates beat structure, always.
2. **Extension branch next.** High `X`: split parabolic (`Ẍ`>0) vs exhaustion (`Ẍ`<0 + `P` climax + `A` breaking).
3. **Constructive states** by signature (§3.2): compression / shelf / coil-expansion / continuation / acceptance.
4. **Transition prior + hysteresis.** Weight the assignment by the *prior* state — coil-expansion is reachable only from compression/shelf; exhaustion follows extended/parabolic. Require a confirmed transition (persistence ≥ k bars or a clean threshold-cross) to flip — this kills per-bar flicker and is what makes "the transition is the signal" operational.
5. **Outputs per bar:**
   - `state`
   - `direction` = sign(`E` / slope / `X`)
   - `confidence` = **margin**: how cleanly the vector sits inside the region (distance to nearest boundary / fraction of axes unambiguously in-region), 0–1
   - `expected_transition` = top successor states + probabilities from the transition matrix given current state
6. **Confidence floor** in Selection (§1) drops low-margin names. Breadth makes that free — we trade the clean ones and skip the rest.

### 3.4 Forward-return phenotype validation (position 2, operational)

States are validated by what they *predict*, not by how they look. This sidesteps the circular trap of training a model to reproduce subjective structure labels.

- **Measure:** for each state, the **forward-N-minute return distribution** of names in it, conditioned on (`confidence`, RS, `direction`). N matched to the intended intraday hold; use **executable** returns (spread/slippage-adjusted), not mid-to-mid.
- **Acceptance bar:** the state must *separate* forward returns from the universe baseline in the policy's assumed direction — coil-expansion-up → positive mean/skew; continuation → positive with low drawdown; exhaustion → reversal/negative; vacuum/danger → worse fill-adjusted outcomes. A state that fails to beat a **shuffled-label baseline** is dead.
- **Threshold tuning:** the cutoffs in §3.1–3.2 are set to *maximize forward-return separation*, not chart aesthetics. This is the calibration loop, and the honest success bar.
- **Dead-state pruning:** a non-separating state is merged, redefined, or dropped no matter how clean it looks.
- **Rules → learned-map graduation:** once enough labeled live bars exist (state + realized executable forward return), fit a calibrated model on the **same primitive coordinates** and A/B it against the rules by phenotype separation. The primitive layer (§3.1) never changes underneath — only the coordinate→state map graduates.

### 3.5 Transition graph

Two layers: a **structural lifecycle graph** (the 7 structural states + a `neutral` default) and a **gate overlay** (`liquidity-vacuum`, `danger`) that can interrupt *any* structural state and, on clearing, hands control back to fresh structural classification (edge-bot's conditional-skip-gate pattern — gates beat structure, always; §13).

**Hysteresis rule:** a state flip fires only when (a) it is a *legal edge* below, **and** (b) the destination signature persists ≥ k bars (or a decisive threshold-cross). Illegal "teleports" (e.g. compression → exhaustion) are suppressed — which forces the path through the lifecycle, stabilizes labels, and is what makes "the transition is the signal" operational. `expected_transition` = the current state's top legal successors, each with the trigger to watch, so the policy can **pre-arm** (arm the coil-expansion entry *while still in compression*).

**Legal structural transitions** (**E** = entry edge, **X** = exit edge, long-only):

| From | Legal successors (trigger) |
|---|---|
| **neutral** | → compression (vol contracts) · → shelf (ledge forms) · → continuation (trend resumes, **E**) · → acceptance (value builds) |
| **compression** | → **coil-expansion** (Ċ↑ + P>1, **E**) · → shelf (holds a level) · → neutral (decays) |
| **shelf** | → **coil-expansion** (break + volume, **E**) · → compression (tightens) · → continuation (**E**) · → neutral (loses level) |
| **coil-expansion** | → continuation (expansion matures, **E**/hold) · → parabolic (accelerates) · → exhaustion (fails/climax, **X**) · → compression (failed breakout, **X**) |
| **continuation** | → continuation (persists, hold) · → acceptance (pauses, hold) · → parabolic (accelerates) · → exhaustion (climax, **X**) · → compression (momentum bleed, **X** soft) |
| **acceptance** | → coil-expansion (re-ignites, **E**) · → continuation (resumes, **E**) · → compression (tightens) · → exhaustion (fails to hold, **X**) |
| **parabolic** | → **exhaustion** (dominant successor, **X**) · → continuation (orderly cool-down, rare) · → danger (blowoff) |
| **exhaustion** | → neutral / compression (cool-down reset) · → liquidity-vacuum (post-climax thinning) · → danger (cascade) · → downtrend (= long exit / future put entry) |

**Gate overlay** (orthogonal; interrupts any structural state):
- `liquidity-vacuum` ← any, when `L` poor → returns to structural classification when liquidity restores.
- `danger` ← any, when `L` danger flag (halt/news) **or** extreme parabolic ∩ exhaustion → returns when the flag clears.

**Edges as policy actions** (ties to §3.2):
- **Entry edges (E):** {compression/shelf → coil-expansion}, {coil-expansion → continuation}, {acceptance → coil-expansion/continuation}, {shelf/neutral → continuation}.
- **Exit edges (X):** {* → exhaustion}, {coil-expansion → compression} (failed breakout), {continuation → compression} (momentum bleed, soft), {* → danger / liquidity-vacuum}.
- **No-new-entry (manage-only):** {* → parabolic} (chase guard — trail, don't add).

The transition graph is **net-new to theta** (§13: edge-bot has no explicit transition matrix). We adopt its *gate-overlay + hysteresis-via-persistence* pattern and add the explicit structural lifecycle edges, deliberately stopping short of a full HMM — first-order lifecycle + legal-edge constraint is enough, and auditable.

## 4. Feature substrate

Deterministic per-symbol features, recomputed every bar, vectorized across the universe (ms budget, §10). Working set — refine in §12:

- Multi-window returns (e.g. 1/5/15/30m) and slope.
- RVOL (volume vs time-of-day expected), volume trend.
- ATR / realized vol; range compression (NR-bar, Bollinger/Keltner squeeze).
- VWAP and signed distance to it; anchored-VWAP from key levels.
- Swing structure (higher-highs/higher-lows count, pivot sequence).
- Gap state (overnight gap %, fill state).
- Position vs intraday + prior-day levels (HOD/LOD, PDH/PDL, opening range).
- Return vs index/sector (the RS input, §5).
- **Freshness stamp** on every feature (staleness gates from §2).

## 5. Relative-strength ranking

Momentum is cross-sectional and relative — trade the strongest names in the strongest structures.

- **RS inputs:** return vs index/sector over lookback(s) + **intraday RS** (how it moves vs SPY/sector *right now*).
- **Composite score:** structure-quality × RS × direction-alignment. A `coil-expansion-up` name in top-decile RS = prime long; the same structure in bottom-decile RS = prime *short* (deferred to puts / expressible coarsely via inverse ETF, §7).
- **Output:** top-N cohort to hand the execution policy.
- **Long-only now:** RS ranks the *strength* half for single-name longs; the *weakness* half is monetized coarsely via inverse ETFs now, precisely via puts later.

## 6. Execution policy

Built on **limit orders as the primitive** (they control price/timing, not direction — see §7):

1. **Marketable-limit as default** (limit at/through the touch) — caps slippage on momentum entries; the explicit defense against the *liquidity-vacuum* and *danger-zone* fill traps.
2. **Resting pullback entries** — buy-limit parked at support expresses the *continuation* state (dip-and-reclaim) without watching the tape.
3. **Synthetic brackets** — RH has no native OCO, so target = resting sell-limit, stop = separate order; **the harness owns cancel-the-other-on-fill** and orphan-leg reconciliation (arch §6).
4. **Breadth-of-intent at low cash commitment** — post conditional resting limits across many names; cash is drawn only on fills; cancel the rest. Helps cope with the settled-cash throttle (§8). **Open empirical check:** does RH *reserve* buying power per open buy-limit? If so, fan-out is bounded by reservation, not just fills (§12).
5. **Sizing** — vol-scaled (ATR-normalized), quarter-Kelly upper anchor, breadth-diversified across the cohort, capped by settled cash.
6. **Zero-fee mechanics** — enables scale-in/out and re-entry without fee drag; holds are intraday, exits are structural.

## 7. Directional expression & instrument tiers

The cash account is **long-only** — no borrow, no margin, "sell to open" rejected. Limit orders do **not** change that; direction comes from the instrument:

| Phase | Strength (bullish structure) | Weakness (bearish structure) |
|---|---|---|
| **1 — equities, cash, now** | single-name longs | **inverse ETFs** (SH/PSQ/SQQQ-class): a *long* position that rises when the index/sector falls — legal in cash, no margin/options. **Coarse: index/sector-level, not single-name.** Leveraged-inverse decay/tracking error (intraday blunts decay; slippage real). Run the structure machine on the ETF or invert the index read. |
| **2 — options GA** | (longs or long calls) | **long puts** = precise single-name short engine. Classifier's bearish states (exhaustion/breakdown/danger) flip exit→entry. |

So the cross-section is not half-dead in Phase 1: **single-name longs for strength, inverse-ETF longs for weakness (coarse), single-name put precision at GA.**

## 8. Risk controls

- **Per-trade:** ATR-based stop distance × size → fixed risk per position; hard stop always live.
- **Portfolio:** max concurrent positions; gross exposure ≤ cash (no leverage); per-symbol cap; sector/correlation cap (no stacking 10 correlated semis); daily-loss limit → flatten + halt (kill-switch).
- **Settled-cash throttle (the real ceiling):** a cash account's good-faith/T+1 mechanics bound recycling velocity — burning settled cash on entries and recycling unsettled proceeds before settlement trips good-faith violations. "Watch hundreds, trade many simultaneously" is ceilinged by *settled cash*, not the scanner. The risk layer tracks settled vs unsettled cash as a first-class input and bounds simultaneous deployment + recycle rate. **Upside:** cash accounts are PDT-exempt, so intraday round-trips don't trip pattern-day-trader.

## 9. Inference / determinism boundary

**Hot path = zero LLM.** Two reasons: (a) breadth lets the system skip ambiguity rather than reason through it (§3); (b) a per-symbol-per-minute LLM call blows the latency + cost budget by orders of magnitude. Scan/classify/rank/execute/risk are fully deterministic.

- **Model ≠ LLM.** The structure classifier is a quant/ML model (edge-bot geometry lineage) — fast, deterministic, auditable.
- **LLM lives only at two off-hot-path altitudes**, both narration-over-deterministic-tools:
  1. **Daily pre-market context / posture** (replaces the manual Sunday grind): synthesize macro calendar + earnings + sector rotation + news regime (all fed by deterministic tools/APIs) into "today's risk posture + which slice of the universe is in-play." Conditions the day; runs once; never touches an order.
  2. **Per-trade rationale narration** for the decision log/dashboard — the model *describes* what deterministic primitives decided. Explains, never decides.

## 10. Cadence / loop budget

- Hot loop on the **1-minute bar** (sub-minute possible later; 1m is plenty for structure-momentum and respects feed/rate limits).
- Vectorized deterministic feature + classify + rank across hundreds of symbols = **milliseconds**; the loop completes well inside the bar with room for execution. This budget is *why* the hot path stays deterministic.
- Daily context layer (§9.1) runs pre-market, off the hot loop.

## 11. Options-phase extension (at RH GA)

The spine is instrument-agnostic. At options GA, only the execution/sizing organ swaps:
- Equity share → option contract selection via `tools/options-math/` + the Greeks layer (strike/expiry/EV/POP gates from the options-infra plan).
- The short side gains single-name precision via long puts (§7).
- Universe, feature substrate, structure classifier, RS ranking, and risk controls are **unchanged**. Account-permission prerequisite: the agentic account must be options-enabled (arch §5.3).

## 12. Build-Time Calibration Items

1. **Classifier implementation** — *resolved in shape* (§3.1–3.5: primitive basis → gated rule-map → transition graph → phenotype validation → learned-map graduation), and ~half-prototyped already (§13). Build-time calibration owns the **edge priors/weights** on the §3.5 transitions, the **persistence k** for hysteresis, the **confidence-margin formula**, and the **graduation trigger** (labeled-bar count before A/B-ing a learned map).
2. **Coordinate calibration** — §3.1 defines the 9 primitives; implementation owns the exact **window lengths / raw-feature recipe** for each coordinate, and the **numeric region cutoffs** in §3.2 — both tuned by forward-return separation (§3.4), not by hand.
3. **RS formula** — lookbacks, index vs sector benchmark, intraday-RS weighting, composite with structure-quality.
4. **Data feed quality** — Alpaca SIP is selected for v0; still measure cost-realized throughput, latency, missing bars, reconnect recovery, and breadth under the target universe.
5. **RH buying-power reservation** — empirical: does an open buy-limit hold cash? Bounds the fan-out (§6.4). Same probe bucket as OAuth lifecycle.
6. **Inverse-ETF handling** — universe + whether to classify the ETF directly or invert the index read; decay/tracking tolerance for intraday.
7. **Settled-cash accounting model** — exact tracking, simultaneous-deployment cap, recycle-rate limiter to avoid good-faith violations.
8. **Per-state entry/exit trigger definitions** — the §3.2 signatures + §3.3 transitions give the *shape*; *still open:* the precise numeric trigger conditions per transition (tuned via §3.4 phenotype).
9. **Sizing model** — ATR-normalized + quarter-Kelly + breadth diversification + settled-cash cap, reconciled into one function.

## 13. Reuse from edge-bot (already-built substrate)

A prior pass — `tools/equity-structure/` (built 2026-06-09) — already prototypes a large share of this anatomy on equities. These are **yfinance-fed batch CLI prototypes**: the *logic and thresholds* port into theta's hot engine; the *data sourcing* is replaced by the §2 real-time feed, and the transition/hysteresis layer is added on top. Verified by direct read.

| theta organ | Already built | Reuse verdict |
|---|---|---|
| Classifier — **swing/HTF bias** | `tools/equity-structure/trend_geometry.py` `geometry()` → acceptance_shell / compression{atr_pctile, bb_width_pctile} / coil{inside_days} / parabolic{dist_ma20_atr} / slope + posture | **Port thresholds as-is** — they *are* the §3.1/§3.2 cutoffs. Use as the higher-timeframe bias/context that tilts the universe. |
| Classifier — **intraday state-map** | `tools/equity-structure/intraday_loop_scanner.py` `classify()` → LIQUIDITY_VACUUM / COMPRESSION_COIL / ACCEPTANCE_SHELF / DISLOCATION_HOLD(=continuation) / FAILED_BREAKOUT / RECLAIM / PARABOLIC_EXPANSION + action + confidence; computes session VWAP, realized-vol, slope, volume-expansion, vwap-dist, dist-from-session-H/L, spread; already ingests a Robinhood quote snapshot | **Port as the §3.3 state-map seed.** Re-home onto the real feed; add the §3.5 transition graph + hysteresis (new). |
| RS ranking (§5) | `tools/equity-structure/relative_strength.py` | **Port** (verify formula vs §5). |
| Universe + breadth (§1) | `tools/equity-structure/universe_tiers.json`, `market_structure_scanner.py`, `breadth_engine.py` | **Port** universe tiers + breadth. |
| Coordinates / utils (§3.1) | `tools/equity-structure/common.py` — `atr()` (Wilder), `linreg_slope()`, `percentile_rank()`; `gap_classifier.py`, `liquidity_scanner.py`, `earnings_regime.py` | **Port as-is.** |
| Regime overlay + gate/hysteresis pattern | perp: `app/Edges/Crypto/Perp/PerpBasisScalpV0Service.php` `regime_micro` (REPRICE_CONTINUATION / OVERSOLD_EXHAUSTION / LIQUIDITY_VACUUM / NORMAL) + `regime_sess_vol` (RV terciles); `app/Edges/Crypto/Dislocation/GeometryMarketDislocationPrimitive.php` (BOOK_AHEAD / LAGGING / TRAJECTORY_STALE / COHERENT + confidence tiers); `app/Edges/Crypto/Perp/StateMachine/` child-priority architecture | **Portable concept only** (PHP/Kalshi-specific): adopt the three-tier conditioning (session-vol × micro-regime × coherence), confidence-tier-separate-from-state, and priority-ordered child + conditional-skip-gate hysteresis. Re-implement, don't import. |

**Gaps — BUILT 2026-06-12** (as feed-agnostic pure functions, tested in the greeks container): expansion-velocity `Ċ`, drive-efficiency `E` (Kaufman ER, signed), acceleration `Ẍ`, **true RVOL** (median of the same time-of-day bucket across trailing sessions, current session excluded), volume-profile **acceptance** `A` (time-at-price × two-sided rotation × (1 − wick rejection)), **anchored-VWAP** (+ signed ATR distance), and **halt detection** (stale-stream / no-prints) live in `tools/equity-structure/coordinates.py`; the **transition graph + hysteresis + `expected_transition`** (§3.5) lives in `tools/equity-structure/transitions.py` (legal-edge lifecycle, gate overlay with danger > vacuum > structure, persistence-k with decisive-cross override, prototype-label mapping). Tests: `tools/equity-structure/tests/`. Numeric cutoffs, persistence k, and edge priors remain §12 calibration items.

**Net:** the structure classifier and RS are now fully prototyped at the primitive level; theta's remaining net-new work is re-homing the logic from yfinance batch onto the §2 real-time feed inside the deterministic hot engine (ingest → vectorized per-bar loop), then calibrating thresholds by forward-return separation (§3.4). The two timeframe classifiers compose cleanly — `trend_geometry` as the daily/HTF bias, `intraday_loop_scanner` as the hot-loop state-map, `transitions.step()` as the lifecycle layer over it.
