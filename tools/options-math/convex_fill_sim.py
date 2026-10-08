#!/usr/bin/env python3
"""
convex_fill_sim.py — mid-anchored re-peg fill simulator, liquidity-aware (napkin).

Standalone green-toolkit primitive. The quoted spread is NOT what a programmatic
price-taker pays: we post at mid and re-peg toward the ask on a bounded schedule,
so a wide far-wing quote can still be entered at a small ACHIEVABLE cost. But a
thin, toxic wing should NOT get a free pass — so the fill probability is scaled
DOWN by how wide the quote is and how little volume / open interest backs it.

Model (transparent, tunable — NOT calibrated; napkin):
  - Post a buy limit at mid, re-pegging up toward the ask in n_steps, capped at
    repeg_cap_frac of the mid→ask distance (0.5 = halfway; we never cross the ask).
  - Per-level base willingness rises with aggression: w(f)=passive+(1−passive)·f
    (f=0 at mid → f=1 at ask).
  - AVAILABILITY scales the whole curve: avail = spread_factor · liquidity_factor.
      spread_factor  = max(0.10, 1 − quoted_spread_bps / SPREAD_PENALTY_BPS)
      liquidity_factor = clamp(0.20..1.0, (OI + 5·vol) / LIQ_REF)   [1.0 if OI/vol absent]
    q(f) = min(0.98, avail · w(f)). Wide and/or thin ⇒ low q at every level ⇒ low
    fill probability, so toxic wings fail the fill-prob half of the gate.
  - Achievable entry cost above mid = Σ P(first fill at level)·(f·half), reported
    conditional on filling, in bps of mid and $/contract.

Numbers in → JSON out, like the other one-off scripts.

Usage:
    convex_fill_sim.py --bid 0.06 --ask 0.10 --volume 110 --open-interest 1114
"""
import argparse
import json
from datetime import datetime, timezone

REPEG_CAP_FRAC = 0.5       # cap posts halfway from mid to ask
N_STEPS = 4                # re-peg steps from mid up to the cap
PASSIVE_FILL_PROB = 0.15   # base fill willingness AT MID in a tight, liquid market
BUDGET_BPS = 1500.0        # achievable-cost budget for the "executable" flag
MIN_FILL_PROB = 0.50       # minimum fill probability to call a wing executable
SPREAD_PENALTY_BPS = 12000.0  # quoted spread at which spread_factor hits its 0.10 floor
LIQ_REF = 1500.0           # (OI + 5*vol) at/above which liquidity_factor saturates to 1.0
MAX_Q = 0.98               # cap on any single-level fill prob


def simulate(bid, ask, repeg_cap_frac=REPEG_CAP_FRAC, n_steps=N_STEPS,
             passive_fill_prob=PASSIVE_FILL_PROB, mult=100.0,
             budget_bps=BUDGET_BPS, min_fill_prob=MIN_FILL_PROB,
             volume=None, open_interest=None) -> dict:
    bid = float(bid); ask = float(ask)
    if not 0 <= repeg_cap_frac <= 1 or n_steps < 1:
        return {"error": "need 0 <= repeg_cap_frac <= 1 and n_steps >= 1"}
    if bid <= 0 or ask <= 0 or ask < bid:
        return {"error": "need 0 < bid <= ask"}
    spread = ask - bid
    mid = (bid + ask) / 2.0
    half = spread / 2.0
    if mid <= 0:
        return {"error": "mid <= 0"}
    quoted_spread_bps = 10000.0 * spread / mid

    # availability: damp the whole fill curve for wide and/or thin wings
    spread_factor = max(0.10, 1.0 - quoted_spread_bps / SPREAD_PENALTY_BPS)
    if volume is None and open_interest is None:
        liquidity_factor = 1.0  # not supplied → spread-only availability
    else:
        liq = (float(open_interest) if open_interest else 0.0) + 5.0 * (float(volume) if volume else 0.0)
        liquidity_factor = max(0.20, min(1.0, liq / LIQ_REF))
    avail = spread_factor * liquidity_factor

    levels = [repeg_cap_frac * i / n_steps for i in range(n_steps + 1)]
    survive = 1.0
    fill_prob = 0.0
    exp_cost_above_mid = 0.0
    for f in levels:
        w = passive_fill_prob + (1.0 - passive_fill_prob) * f
        q = min(MAX_Q, avail * w)
        p_first = survive * q
        fill_prob += p_first
        exp_cost_above_mid += p_first * (f * half)
        survive *= (1.0 - q)

    cond_cost_above_mid = (exp_cost_above_mid / fill_prob) if fill_prob > 0 else half
    achievable_cost_bps = 10000.0 * cond_cost_above_mid / mid
    cross_cost_bps = 10000.0 * half / mid

    return {
        "inputs": {"bid": round(bid, 4), "ask": round(ask, 4), "mid": round(mid, 4),
                   "volume": volume, "open_interest": open_interest,
                   "repeg_cap_frac": repeg_cap_frac, "n_steps": n_steps,
                   "passive_fill_prob": passive_fill_prob, "mult": mult},
        "quoted_spread_bps": round(quoted_spread_bps, 1),
        "cross_cost_bps": round(cross_cost_bps, 1),
        "achievable_cost_bps": round(achievable_cost_bps, 1),
        "achievable_cost_usd_per_contract": round(cond_cost_above_mid * mult, 2),
        "savings_vs_cross_bps": round(cross_cost_bps - achievable_cost_bps, 1),
        "fill_probability": round(fill_prob, 3),
        "spread_factor": round(spread_factor, 3),
        "liquidity_factor": round(liquidity_factor, 3),
        "availability": round(avail, 3),
        "expected_fill_price_if_filled": round(mid + cond_cost_above_mid, 4),
        "executable": bool(achievable_cost_bps <= budget_bps and fill_prob >= min_fill_prob),
        "budget_bps": budget_bps, "min_fill_prob": min_fill_prob,
        "notes": [
            "fill_probability now scales with quoted-spread width AND volume/OI (availability) — a "
            "thin/wide wing gets a low fill prob and fails the executable gate; no free pass.",
            "ACHIEVABLE cost is conditional on filling within the re-peg schedule; pair with fill_probability.",
            "NAPKIN: SPREAD_PENALTY_BPS / LIQ_REF / passive_fill_prob are uncalibrated starting points — "
            "fit to real fills. liquidity_factor defaults to 1.0 when OI/vol are not supplied (spread-only).",
        ],
        "computed_at_utc": datetime.now(timezone.utc).isoformat(),
    }


def main() -> None:
    p = argparse.ArgumentParser()
    p.add_argument("--bid", type=float, required=True)
    p.add_argument("--ask", type=float, required=True)
    p.add_argument("--volume", type=float)
    p.add_argument("--open-interest", type=float)
    p.add_argument("--repeg-cap-frac", type=float, default=REPEG_CAP_FRAC)
    p.add_argument("--n-steps", type=int, default=N_STEPS)
    p.add_argument("--passive-fill-prob", type=float, default=PASSIVE_FILL_PROB)
    p.add_argument("--mult", type=float, default=100.0)
    p.add_argument("--budget-bps", type=float, default=BUDGET_BPS)
    p.add_argument("--min-fill-prob", type=float, default=MIN_FILL_PROB)
    args = p.parse_args()
    out = simulate(args.bid, args.ask, repeg_cap_frac=args.repeg_cap_frac, n_steps=args.n_steps,
                   passive_fill_prob=args.passive_fill_prob, mult=args.mult,
                   budget_bps=args.budget_bps, min_fill_prob=args.min_fill_prob,
                   volume=args.volume, open_interest=args.open_interest)
    print(json.dumps(out, indent=2))


if __name__ == "__main__":
    main()
