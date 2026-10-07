# Perplexity grounding — fringe convexity surface: representation + lottery/convexity boundary

**Owner:** model-lane / claude &nbsp;|&nbsp; **Agent ID:** claude/theta-fringe (model-lane) &nbsp;|&nbsp;
**Scope:** greenfield grounding for the fringe-convexity surface sweep tooling — how to represent the strikes×tenors×move surface and how to treat the deep-OTM admissibility trade-off &nbsp;|&nbsp;
**Status:** research grounding (greenfield; NOT a policy/predicate — the live market is the judge later) &nbsp;|&nbsp; **Date:** 2026-10-06

Two value-seeking, peer-level queries (perplexity_reason, high context). Distilled to load-bearing claims; sources at bottom.

## Q1 — Surface representation & where richness lives

- **AXIS.** Store/fit on forward log-moneyness `k = ln(K/F_T)`; carry total implied variance `w(k,T)=σ²(k,T)·T` (SVI convention). For a cross-tenor grid add **standardized moneyness `x = ln(K/F)/(σ_ref·√T)`** as the comparison axis — a fixed `k` is a different number of SDs at each tenor, so only `x` makes "2σ wing" comparable across expiries. Keep `k` as the stored axis, `x` as the reporting/comparison axis; state `σ_ref` (ATM IV of that expiry) — don't mix conventions.
- **BASIS GAP IS A FLAG, NOT A DECOMPOSITION.** market-premium ÷ flat-ATM-BS is NOT a clean skew/VRP measure — a large ratio can just mean the ATM-flat denominator is tiny. Use it only to flag strikes for investigation. The rigorous, DISTINCT layers: (1) **skew** = `Δσ(k,T)=σ(k,T)−σ(0,T)` (price shape; or a 25Δ risk-reversal); (2) **variance risk premium** = implied minus realized variance, a HORIZON-level quantity, not a strike-local IV premium; (3) **tail pricing** via Breeden–Litzenberger risk-neutral density vs a physical-measure tail. "Surface shape" and "expected return" are separate layers — a skew number alone does not tell you a long option's expected return.
- **NO UNIVERSAL TURN.** There is no fixed moneyness/tenor where long options flip convexity-harvesting → lottery bleed; it's an empirical result for a defined universe/horizon/cost, not a surface feature.

## Q2 — Lottery vs convexity boundary (the gate)

- **NO EMPIRICAL DELTA CUTOFF — it's a gradient.** Highest ex-ante skewness → lowest option returns (Boyer–Vorkink, 10–50%/wk portfolio spread). Lottery richness worse at SHORT tenors (3–6m richer than 12m). Delta-hedged single-name options expensive on average (Cao–Han: ATM delta-hedged call ≈ −0.81%/mo). Mitigants that move the sign: high realized-vol-vs-IV (Goyal–Saretto → higher returns), low vol-of-vol (high vol-of-vol → lower returns), systematic vs idiosyncratic vol interact with moneyness differently.
- **STRATIFY, DON'T GATE.** Established design = panel over `delta × standardized-moneyness × tenor × vol-state × catalyst`. Per cell report: N contracts, N independent events, win rate, mean/median net return, return-on-premium, tail loss; report RAW long-call returns AND delta-hedged separately. One spectacular convex winner must be VISIBLE but must not stand in for favorable EV.
- **CATALYST WIDENS CANDIDATES, NOT A PROVEN EDGE.** Earnings: implied earnings-day vol ≈ 8.22% vs realized ≈ 7.42% (≈80bp premium); pre→post ATM straddle ≈ −7.96% avg. Some positive samples (Gao–Xing–Zhang pre-earnings straddle ≈ +3.34%, stronger for small / high-kurtosis / low-volume names); high expected-announcement-vol dates underperform ≈ −11%. Caveat: event evidence is mostly ATM STRADDLES, not deep-OTM calls — don't conflate. Test = does physical expected jump exceed the priced skew premium after costs.

## Q3 — The "flip profile" representation

- Established form = a **scenario-P&L surface** with the **zero-P&L contour** overlaid. Axes: `ΔS/S` (x), `Δσ_ATM` or named regime (y), P&L as color; separate panels for elapsed time + skew shock. Reprice destination contract at destination surface + remaining maturity, subtract entry, include carry/costs. Sticky-strike / sticky-moneyness / leverage are SCENARIO ASSUMPTIONS, not forecasts; "crash-up vs grind-up" are distinct paths in that map. The "flip" is simply where scenario P&L changes sign.

## Design consequences for the sweep tooling

1. Index the surface by **standardized moneyness `x`**, not raw strike (compute `x` per cell; `σ_ref` = that expiry's ATM IV).
2. Keep `basis_gap` but LABEL it a crude flag; add the **skew residual `Δσ`** as the real richness layer (needs per-strike IV — feed broker rows for the live version).
3. The regime-fan across the move spectrum IS the scenario-P&L surface — emit it with the **zero-flip move** per regime so the desk sees the flip.
4. The delta floor is a **non-destructive admit/reject TAG**, configurable, that keeps rejected cells visible (so we can count the convex winners a rigid gate would blind us to). Do not drop cells.

## First sweep finding (2026-10-06, modeled single-name skew, fringe_surface.py)

Sweeping a realistic equity skew (atm_iv 0.70, skew_k −0.40) across strikes×tenors **overturns the "24× call-wing basis gap = skew richness" premise**:

- Under genuine equity downside skew, an OTM **call**'s IV sits BELOW ATM (skew_residual monotone −0.05 → −0.33 out to x≈+4.9), so an OTM call is **CHEAPER** than a flat-ATM-BS value, not richer. basis_gap_flag is <1 near the money (K=400→0.82, K=500→0.16) and then a nonsense blowup in the deep wing (K=700→30×, K=800→1405×) that is PURELY the penny-floor / tiny-denominator artifact the grounding warned about.
- So the original "24×" (from pricing a 700/350 call against a FLAT 0.90 IV with a supplied 0.45 premium) was **not skew richness** — it was the flat denominator being tiny plus the min-tick floor. Real pay-up-for-skew richness lives on the **PUT wing** (equity downside skew) or in names carrying a **call skew** (squeeze / high-short-interest / parabolic).
- Thesis consequence: the fringe-**call** convexity edge is NOT "buy rich call skew." It is "buy **cheap** convex upside the market underprices, and win when a violent up-move **reanchors** the skew up (sticky_moneyness) rather than crushing it (leverage)." The flip grids show it: deep-fringe cells that flip positive at ~+40% under sticky_moneyness **never flip** under leverage, and shorter tenors (14 DTE) kill the deep wing entirely while 30 DTE lets it flip. Time-vs-convexity and regime are the axes that matter, not a call-skew "richness" read.
- Gate ledger at delta_floor 0.05: 3/15 cells admitted, 10 of 13 modeled >1× "winners" blinded. Quantifies the rigid-gate blind spot the desk asked about — with the honest caveat that those "winners" are modeled sticky_moneyness returns, no physical measure, no costs; the lottery literature says the deep wing bleeds unconditionally.

Next lap: feed **real broker per-strike IV + premium** (replace modeled skew) and test put-wing vs call-wing richness + call-skew names; replace basis_gap_flag with skew_residual off a real local fit.

## Broker-fed finding (2026-10-06, live NVDA call wing, anchor_select.py)

Napkin, not authoritative — baseline posture to test live with small clips. NVDA spot ≈ 239.17, 24 DTE (2026-10-30), ATM IV 0.286.

- **The call wing is a SMILE that turns up**, not a downslope: IV troughs at K≈260 (0.270) then RISES into the wing — 280→0.296, 290→0.322, 300→0.359, 320→0.415. skew_residual vs ATM goes +0.037 (290) → +0.129 (320). Real local right-wing slope ∂σ/∂k ≈ **+0.9** (positive), opposite the −0.40 downside default the modeled surface used.
- **Thesis refinement #3:** on a call-skew / momentum name the fringe is NOT cheap convexity — it carries a vol premium (lottery/call-skew demand, the Boyer–Vorkink skewness premium made concrete). The fringe-call edge exists only if a violent up-move reanchors IV *above* that already-rich level faster than the premium bleeds. "Buy cheap convex upside" holds where the right wing is flat/cheap; it does NOT hold on NVDA-like names.
- **Regime sign flips:** with a positive right-wing slope, the sticky-moneyness reanchoring term (−slope·dlnF) makes a fixed fringe-call's IV FALL as spot rises toward it (it slides toward the lower-IV ATM). Feed the real +0.9 slope into fringe_response/fringe_surface — the downside-skew default inverts the regime read on call-skew names.
- Thesis-free anchor (vol-cone): anchor K=260 (x=1.2σ, Δ0.13, delta-accel 0.030/1%); fringe K=290 (x=2.3σ, Δ0.012). JSON shim overrides both when probing a specific idea.

## Sources (named)
Carr–Wu (equity smirks / standardized moneyness); SVI total-variance parameterization; Breeden–Litzenberger (risk-neutral density); NY Fed SR706 (variance risk premium); Hull–Derman smile-dynamics (sticky-strike vs sticky-delta intermediate, maturity-dependent); Boyer–Vorkink (ex-ante skewness → option returns); Cao–Han (delta-hedged option returns); Goyal–Saretto (vol mispricing); vol-of-vol option-return literature; Gao–Xing–Zhang & related earnings-straddle studies; retail-options / lottery-demand literature. Full URLs in the session transcript's two perplexity_reason results dated 2026-10-06.
