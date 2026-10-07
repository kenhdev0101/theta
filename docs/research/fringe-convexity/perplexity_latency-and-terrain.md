# Perplexity grounding — expression-latency gap + where convexity is cheapest to express

**Owner:** model-lane / claude &nbsp;|&nbsp; **Agent ID:** claude/theta-fringe (model-lane) &nbsp;|&nbsp;
**Scope:** greenfield terrain-widening for the convex-wrapper lane — is the latency edge real/measurable, and where on the surface is convexity structurally cheap vs expensive to express &nbsp;|&nbsp;
**Status:** research grounding (greenfield; the live market is the judge). Both queries CORRECTED a working premise. &nbsp;|&nbsp; **Date:** 2026-10-06

Two value-seeking, thesis-free, senior-peer queries (perplexity_reason, high). Distilled; sources at bottom.

## Q-A — The expression-latency gap (corrected)

Honest boundary: **"automation can beat a human by seconds, but must beat the retail stack AND arrive in the short interval before electronic repricing to capture a stale quote — the first clause is credible, the second is not established."**

- **Quote AGE ≠ stale mispricing ≠ fillable.** A far-wing quote can sit unchanged for seconds because its economics barely moved / tiny delta / wide-thin market — not because it is exploitably wrong. (EUREX 2012 quote-lifetime study: deep-OTM less active, flicker peaks ~20-30ms / 1s / 15s; no US single-name stale-window estimate.)
- **Human vs automated reaction IS a real, large gap:** CFTC E-mini study — manual median 31.3s / algo median 0.21s (half of algo < 200ms, half of manual > 9s). So a tens-of-seconds human-workflow gap (notice → unlock app → route → fill) is credible.
- **A genuinely stale/mispriced far-wing quote is contested at microsecond–millisecond scale** (IEX options filing describes MM stale-quote exposure in microseconds). That window is NOT ours.
- **Robinhood path:** API latency disclosed, no SLO; options route through broker-dealers to exchanges with PFOF / price-improvement. Faster submission ≠ faster/better execution; the displayed NBBO is not a guaranteed fill. A fill can be adverse selection, not edge (stock-HFT aggression → wider options spreads; stale-quote-arb risk is priced into liquidity).

**Thesis consequence:** our edge is NOT stale-quote latency-arb (that's MM microseconds). It is the **human-workflow gap** — we win by having the convex decision PRECOMPUTED so firing lands in the tens-of-seconds a human needs, as a **price-taker** (mid-anchored re-peg, not lifting a mispriced quote). This validates the precompute architecture and kills the stale-quote-arb framing.

## Q-B — Where convexity is cheapest to express (terrain map)

Durable relative-value map: **index ATM variance + index downside wings = structurally RICHER (expensive); ordinary single-name ATM + the call wing of a CONVENTIONAL equity smirk = relatively CHEAPER.** "Cheaper" = relative implied pricing, not a positive-return forecast.

- **Main reversal = single-name upside tails.** Short-dated OTM calls in lottery / high-sentiment / squeeze names are EXPENSIVE even when the ordinary surface has downside skew. Overpricing strongest at 3-6mo, weaker at 12mo; sentiment intensifies. The NVDA rich-call-smile is compatible with this — it is the EXCEPTION, not the template.
- **Index vs single-name:** single-name ATM variance is the cleaner "cheaper than index" zone (larger negative index VRP + priced correlation risk), concentrated ~30-90d ATM/variance — not across the whole surface. Reverses in short-dated OTM calls, earnings expiries, high vol-of-vol names.
- **Screenable signals (and traps):** IV-vs-realized (Goyal-Saretto, useful but misses forthcoming jumps); matched-delta skew (relative, not absolute cheapness); net option demand (single-stock end-users net suppliers → relatively cheaper; local call-demand squeeze overrides); vol-of-vol (high → lower hedged returns); spread/depth/OI (liquidity ≠ cheapness; OTM wide %-spreads make wing IV a bad mark); event calendar (classic false-cheapness trap).

**Thesis consequence (important):** our instinct to harvest convexity on far-OTM CALL wings of momentum/semi names (NVDA) points straight at the MOST-overpriced zone. The market prices the convexity we want efficiently exactly where the violent up-move is most likely. So "cheap convexity to harvest" is NOT lying around. Where convexity is structurally cheap (conventional-smirk names, low IV-vs-RV, negative net call demand, low vol-of-vol, tight far-wing spreads, no near catalyst), the violent up-move is less likely. The scanner should hunt that conventional-skew terrain, NOT momentum darlings — and the real edge remains expression + the specific regime where an up-move reanchors IV faster than the surface already prices.

## Terrain filter v0 + first real run (2026-10-06, convex_terrain_filter.py)

Built the fail-closed, thesis-free admissibility gate (6 signals): conventional_smirk (25Δ RR), **fringe_not_rich** (deep-OTM-call IV vs ATM — added mid-run, see below), iv_rv_ratio (pending — needs historicals), quiet_surface (IV-dispersion proxy for vol-of-vol), far_wing_spread, no_catalyst. A ticker is ADMIT only if every signal passes; pending inputs also block (fail-closed).

Real 24-DTE run — NVDA (momentum control), KO + JNJ (blue chips) — **all three REJECT**:
- **NVDA**: only `fringe_not_rich` catches it (fringe IV +0.037 over ATM). Its 25Δ RR is *conventional* (−0.024), so the cross-wing RR MISSES the lottery smile that lives at 2–3Δ. This gate was added mid-run after the real data exposed the gap — the key lesson.
- **KO**: RR +0.007 (flat/slightly-positive skew, not conventional), far wing 5000 bps wide (0.08/0.26 on the 95c — cost eats the convexity), earnings 2026-10-27 in window.
- **JNJ**: earnings 2026-10-13 in window, fringe +0.025 rich, far wing 3660 bps.

Lessons: (1) **cheap convexity is rare** — a strict structural gate rejects all three reasonable names; never force a build. (2) the call-fringe richness test must be deep-OTM-call-vs-ATM, not the 25Δ RR. (3) blue-chip far wings are often too wide to express convexity cheaply, and they carry earnings into an October window. (4) iv_rv gate is pending — wire realized vol from historicals to bind it.

Pipeline shape: `convex_terrain_filter` (admissibility) → `anchor_select` (vol-cone legs) → `convex_precompute` (fire row). All napkin, callable via greeks `/run/*`.

## 15-name grid scan (2026-10-06, expiry 2026-10-30, DTE 24)

Ran convex_terrain_filter across 3 buckets (value: WMT/PPG/CAT/MCD/PG; big-tech: MSFT/GOOGL/AAPL/AMZN/META; sector ETF: XLF/XLV/XLP/XLE/XLK). **0 of 15 ADMIT** (all also pending iv_rv). But the result is clarifying, not just sparse — two different binding constraints on two sides of the universe:

- **Proof the surface shape EXISTS: XLK.** Textbook conventional downward smirk (RR25 −0.026, right wing IV falling 0.246→0.210, fringe −2.8 vol-pts CHEAPER than ATM), no catalyst. Its ONLY structural failure is far_wing_spread (7792 bps). So the thesis's surface condition is satisfiable; the remaining blocker there is execution/microstructure, not skew.
- **Lone point in the admit box (tight wing AND cheap fringe): AMZN** (335 bps, fringe +1.1 vol-pts) — but pre-earnings. ALL big-tech reports ~10/28–29 (inside the 10/30 window), so bucket 2 is uniformly catalyst-blocked and the post-earnings cheap-wing thesis is un-testable until after the print. Tech also has the tightest wings in the universe (AMZN 335, META 461, MSFT 896 bps) — liquidity is NOT its blocker; earnings + a rich lottery fringe is (GOOGL +7.3, META +4.7 vol-pts).
- **Value/ETF**: cheap-shaped fringes but WIDE far wings (6k–19k bps). far_wing_spread is the most common blocker overall (11/15).

Answer to "sparse or thresholds too tight": the shape thesis is satisfiable (XLK), so not purely sparse; and loosening fringe_rich / RR would admit the lottery/rising-wing names we deliberately reject. The two real binding constraints are (1) earnings season (temporary — tech un-testable until post-print) and (2) far-wing execution cost (microstructure). The one threshold worth revisiting is far_wing_spread (sample at ~0.20Δ not ~0.12Δ, or add a $-cap, or pair with the mid-anchored-fill assumption from the latency work).

**Filter robustness bug found:** XLV/XLP far call wings are illiquid (zero-bid / null-IV strikes with garbage greeks); those polluted the dispersion signal (XLP quiet_surface_proxy 0.10 is an artifact). The filter needs a **quote-quality pre-filter** (drop zero-bid / null-IV / crossed strikes before computing any signal). Hardening item.

## Hardened filter + RV-bound re-run (2026-10-06)

Three structural fixes landed (quote-quality pre-filter; far-wing sampled 0.20Δ; pending≠fail), plus two from the re-run (false "XLK clears" comment corrected; smirk→pending when no clean 25Δ). Then bound realized vol (20-trading-day close/close × √252) across the 15 names — the 6th gate is now live.

Result: still **0/15 ADMIT**, now for a sharper reason. **IV/RV is the dominant gate: 12/15 are IV-rich (ratio > 1.10)** — the current vol regime is broadly expensive (only WMT 1.06, MCD 0.97, META 0.80 at/under parity). The admit zone on (IV/RV ≤ 1.10 AND far-wing ≤ 3500 bps) is NOT empty — it holds MCD and META — but both carry rich/rising fringes (lottery shape), so they fail fringe_not_rich. Every clean-fringe name (WMT, AMZN, XLE, XLK) sits OUTSIDE the zone: IV-rich (AMZN 1.91, XLE 1.25, XLK 1.36) and/or wide-wing (WMT 5926, XLK 7792 bps). No name is simultaneously cheap-IV + tight-wing + clean-fringe + no-catalyst.

XLK did NOT clear with the 0.20Δ pull-in — coarse ETF strikes (delta jumps 0.27→0.14) leave no 0.20Δ strike, so the far read snaps to 0.14Δ/7792 bps; it also fails IV/RV (1.36). XLK's blocker is purely execution (wide wing) + current regime (IV-rich), NOT the surface shape — so the mid-anchored-fill / expression-latency primitive is what would convert XLK-shaped names from reject to candidate. XLV/XLP now correctly read as fringe/surface PENDING (pre-filter stripped their illiquid wings) rather than garbage-failing.

Net: this is a regime + microstructure barrier, not a threshold-tightness problem. The filter is behaving; the terrain is genuinely barren at this tenor in this vol regime.

## XLK Dec-18 admit anatomy (2026-10-06) — the prize is gamma, not vega

Characterized the first admit with real XLK Dec-18 quotes. Anchor 225C (0.19Δ, 1σ, OI 1851), fringe 245C (0.05Δ, 1.8σ, OI 82). Reprice across −10…+15% (sticky-moneyness):
- **Convexity is accelerating GAMMA, not vega/skew.** Anchor +10% repriced ΔV ≈ 88% directional (delta 52% + gamma 36%), vol only +5% (flat near-ATM skew). Fringe +10%: gamma ~90–95% of a small net, vol a HEADWIND −90…−113%.
- **Fringe vega is INVERTED (headwind, not tailwind).** The quotable right wing slopes UP (local slope +0.55), so a rally rolls the fixed 245 strike down-smile → its IV falls → positive vega subtracts. The "violent move rewrites fringe IV up" story is inverted for XLK Dec-18. (Caveat: slope is a one-sided diff off a thin 245 quote — magnitude suspect, direction = not-a-tailwind holds; carry as a range.)
- **True 2.5σ tail unquotable** (null IV / zero bid at 250–275). Deepest priceable fringe = 245 (1.77σ). The deep-tail convexity harvest is liquidity/capability-blocked here, not disproven.
- **Entry cost dominates:** fringe ask/mark ≈ 1.9×, break-even +6.7% (anchor +1.4%). Both legs start underwater at 0% move. The earlier executable_wing pass was on the 225 ANCHOR wing (OI 1851); the deep fringe (245) is thin (vol 0, OI 82).
- **Leverage regime:** the fringe NEVER pays (−95% at +10%, −100% at +15% — vol crush buries it); anchor keeps ~half its sticky-moneyness payoff.

Net: the first admit is a ~0.19Δ 73-DTE gamma call, not the deep-OTM vega-convexity the thesis chased. Solution-oriented next: screen the universe for deep-tail (2.5σ) quotability + a DOWN-sloping right wing (so vega is a tailwind), and carry the fringe slope / vega sign as a scenario range.

## Sources (named)
CFTC Fishe–Haynes–Onur (manual vs algo order latency); IEX options SR-IEX-2025-02 filing (microsecond stale-quote exposure); EUREX equity-option quote-lifetime study (Wiley J.Futures 2026); SEC 2021 equity-options market-structure staff report; Robinhood Customer Agreement (API latency / routing / PFOF); Bakshi-Kapadia-Madan & the differential-pricing-of-individual-equity-options literature (call-vs-put, index-vs-single-name skew); Garleanu-Pedersen-Poteshman (demand-based option pricing / net end-user demand); Boyer-Vorkink & lottery/sentiment OTM-call overpricing; Goyal-Saretto (IV-vs-realized); vol-of-vol option-return literature. Full URLs in the session transcript's two 2026-10-06 perplexity_reason results.
