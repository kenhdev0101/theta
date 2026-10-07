# Perplexity grounding — where "pure convexity" lives (surface geography)

**Owner:** model-lane / claude &nbsp;|&nbsp; **Agent ID:** claude/theta-fringe (model-lane) &nbsp;|&nbsp;
**Scope:** map, by underlying class, where a quotable deep tail (2–2.5σ) AND a down-sloping call wing (vega tailwind on a rally) co-exist &nbsp;|&nbsp; **Status:** research grounding (greenfield); pairs with the empirical 15-name survey &nbsp;|&nbsp; **Date:** 2026-10-06

## Headline
The convexity intersection (quotable deep tail + down-sloping call wing) most reliably lives in **broad index: SPX first, SPY next, QQQ conditional** — NOT in energy-commodity or speculative single-name calls. **"Pure long-call convexity is structurally scarce"** by construction: demand and market-making concentrate near ATM, and the far call wing is exactly where lottery demand lifts IV and quotes thin out. Our observed thin single-name/sector-ETF tail past ~1.8σ with an up-local-wing (vega headwind) is a "very plausible base case," not a defect.

## Right-call-wing signature by class (expected; verify empirically)
| Class | right-wing sign | note |
|---|---|---|
| SPX / SPY | down from ATM (calls cheaper) — best prior | but NOT guaranteed to 2–2.5σ; a short-dated SPX preprint finds a U-shaped surface (far call wing turns UP above the trough); long-dated more monotone-down |
| QQQ / Nasdaq | down but regime-sensitive | growth/call demand can flatten or reverse the right wing |
| Sector ETFs (XLF/XLP/XLE) | flat-to-down working prior | weaker intersection + weaker far-tail liquidity than index |
| Large single names (avg) | left smirk ~73% @90d, >80% @1y → down-call-wing prior | far tail undependable |
| Momentum / retail-favorite | flatten or turn UP (call skew) | lottery demand → upside expensive → vega HEADWIND |
| Crude proxies USO/BNO | call-SKEWED on supply shock | quotable but EXPENSIVE → headwind |
| **Energy equities XLE/XOP** | **put-skewed** | DON'T equate oil with oil stocks — XLE ≠ USO |
| Staples XLP/KO/PG | low ATM-vol LEVEL, not necessarily flat wing | "low vol" ≠ flat skew or tradable tail |
| Financials XLF/banks | down/put-skewed (crash skew) | flattens in squeeze/event regimes |

## Deep-tail quotability
- 2σ call ≈ 2.3Δ, 2.5σ call ≈ 0.6Δ — far beyond the 25Δ wing. A liquid 25Δ quote says nothing about a 1–2Δ contract.
- SPX/SPY/QQQ = best odds of a two-sided quote (not a guarantee of size/economic depth). Sector ETFs weaker/series-specific. Single names heterogeneous, attention near ATM. Weekly-vs-monthly: no universal winner — compare the specific series. OI ≠ executable depth. OTM effective spread ~12.6% vs ATM ~6.4% (and that's only the 12.5–37.5Δ bucket; the 1–2Δ tail is worse).

## Four mental-model corrections
1. Don't extrapolate 25Δ skew to 2–2.5σ — different part of the surface (U-shape possible even on index).
2. "Call IV below ATM" is NOT a guaranteed rally vega tailwind — needs a surface-transport assumption; under sticky-strike a fixed strike's IV doesn't lift on a rally.
3. OI / a printed NBBO ≠ executable depth (need current two-sided size + a realistic exit).
4. Don't generalize USO → XLE (crude call-skew vs energy-equity put-skew price different risks).

## Implication for our universe
Our 15 names (value / big-tech / sector-ETF) are mostly the WEAK intersection per this map; the real pond is index (SPY/QQQ), which our survey didn't include. Next: add SPY/QQQ to the survey and test the ACTUAL 1–5Δ far wing (slope + executable quote), not the 25Δ read. Expect the intersection to be rare everywhere.

## Empirical 15-name survey (2026-10-06, 45 DTE) — confirms scarcity

Measured tail reach + right-wing slope live. **"Good convexity zone" (tail ≥2σ AND down-sloping wing): EMPTY.** The two properties are ANTI-CORRELATED across the 15:
- **Deep tails, all HEADWIND:** big-tech deepest (AAPL 2.86σ, AMZN 2.57, GOOGL 2.47, MSFT 2.36, META 2.13 — all up-wing, slopes +0.05…+0.20); value MCD 2.95 / WMT 2.21 / PG 1.92 (up-wing); XLF 2.52 but STEEPEST up-wing (+0.52, strongest headwind of all).
- **Down-wing (TAILWIND) names, all shallow + illiquid:** XLK (−0.19, deserts 1.44σ, OI 12) and XLP (−1.46, deserts 1.17σ, 4 toxic points) — low-confidence, not tradeable-grade.
- **Divergences from the class priors:** XLE (energy equity) call wing mild UP (+0.17, noisy R²0.49), not the put-skew down-wing the "XLE≠USO" prior suggested; XLF's downside put skew coexists with the steepest UP *call* wing. Confirms "don't extrapolate; verify the actual call wing."
- **Caveat:** ATM IV earnings-inflated for CAT/META/AMZN/GOOGL (Q3 in the 11-20 window) compresses σ-scale; several big-tech/value tails are ladder-floor-limited (true edge deeper than the quoted cutoff).

Verdict: pure convexity (deep quotable tail + rally vega tailwind) is void in this universe — not a defect, a structural fact. The one untested, highest-probability pond is broad INDEX (SPY/QQQ); survey them next and test the ACTUAL 1–5Δ wing (the grounding warns index can go U-shaped/up in the short-dated far wing).

## Index pond tested — BOUNDARY UNIVERSAL (2026-10-06, cartography complete)

SPY/QQQ/IWM × 45/73/101 DTE (9 obs; feature matrix at scratchpad/index_feature_matrix.csv, 41 cols). **0/9 green-zone.** Deep tails ARE quotable (7/9 ≥2σ; deep+liquid at longer tenor — SPY@101 δ0.02/OI25.5k, IWM@73 δ0.015/OI16.4k), near-0.20Δ entry cheap (achievable 5–35 bps, fill ~0.90), but the deep **1–5Δ call wing is a vega HEADWIND (slope +0.16…+0.34) in every case.** Index downside skew does NOT give a down-sloping call wing at 1–5Δ — it goes U-shaped / turns UP (SPY@101: ATM 13.6% → trough ~11.6% @δ0.10 → 13.4% @δ0.007). 25Δ RR negative everywhere (puts bid) misses the far turn-up. Longer tenor deepens the tail but never flips the wing sign.

**Universal structural law:** pure long-call convexity (quotable ≥2σ tail AND rally vega tailwind) does NOT exist in the liquid US surface across single-names, sectors, and indices (0 of ~24 observations). The surface is a coherent efficient machine — no free deep-tail convexity; the far wing is universally bid up so a rally crushes a fixed strike's IV. The realizable lane is the near-0.20Δ wing: a directional-gamma expression in the latency gap, not a deep-vega harvest. Validates the wrapper thesis (express, don't predict) and the primitives (they mapped the boundary precisely). If the vega-convexity lane is still wanted, the only escape is a surface whose far wing is flat-to-down — none of the liquid US cohort qualifies; a different cohort (event-driven / different regime) would be the next cartography test.

## Sources (named)
Cboe options-industry / liquidity-concentration reports; Hasbrouck illiquidity-premia-in-options; S&P-constituent OTM-vs-ATM effective-spread study (RFS); Bakshi–Kapadia–Madan skewness; Dallas Fed left-smirk cross-section of single-stock surfaces; ORATS crude (USO/XLE) skew note; SPX U-shaped short-dated surface preprint; retail-OTM-call-demand papers. Full URLs in the session transcript's 2026-10-06 geography perplexity_reason result.
