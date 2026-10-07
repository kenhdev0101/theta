#!/usr/bin/env python3
"""
fringe_surface.py — sweep fringe_response across a strikes x tenors x moves grid.

Greenfield exploration harness, NOT a policy. Thin wrapper over
fringe_response.compute: it does not re-derive the math, it loops the grid and
arranges the output the way the grounding said to (docs/research/fringe-convexity/
perplexity_surface-and-lottery.md). Lightweight by design; not a final home.

What it adds on top of the single-scenario primitive:
  1. STANDARDIZED-MONEYNESS axis x = ln(K/F)/(atm_iv*sqrt(T)) so a strikes x tenors
     grid is comparable across expiries (a fixed log-moneyness is a different number
     of SDs at each tenor).
  2. A crude BASIS_GAP flag (modeled market premium / flat-ATM-BS) AND the real
     richness layer, the skew residual d_sigma = iv(K) - atm_iv.
  3. The regime fan across the move spectrum IS the scenario-P&L surface; we surface
     the ZERO-FLIP move per regime (where the long flips losing->winning).
  4. A NON-DESTRUCTIVE delta-floor tag (admit/reject) that KEEPS rejected cells, so we
     can count the convex winners a rigid gate would blind us to. Nothing is dropped.

Napkin surface mode (default): models a single-name skew iv(k)=atm_iv+skew_k*k+curv*k^2
so basis_gap/skew are meaningful without live quotes. Feed real broker per-strike
{iv, premium} rows for the live version later. Premium floored at one tick (0.01).

Usage:
    fringe_surface.py --side call --spot 350 --atm-iv 0.70 --skew-k -0.40 \\
        --strikes 400,500,600,700,800 --dtes 14,21,30 --delta-floor 0.05
"""
import argparse
import json
import math
import os
import sys
from datetime import datetime, timezone

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import fringe_response as fr  # noqa: E402

MIN_TICK = 0.01  # you can't buy below a penny; floors the modeled far-wing premium


def _flip_move(scenarios: list, regime: str) -> dict:
    """Smallest move (by magnitude) where return_multiple crosses 0, linear-interp."""
    pts = [(s["move_pct"], s["regimes"][regime]["return_multiple"]) for s in scenarios]
    pts.sort(key=lambda p: p[0])
    flip = None
    for (m0, r0), (m1, r1) in zip(pts, pts[1:]):
        if (r0 < 0) != (r1 < 0) and (r1 - r0) != 0:
            flip = round(m0 + (0 - r0) * (m1 - m0) / (r1 - r0), 4)
            break
    rets = [r for _, r in pts]
    return {
        "flip_move": flip,
        "max_favorable_return": round(max(rets), 3),
        "max_adverse_return": round(min(rets), 3),
    }


def compute_surface(
    side, spot, strikes, dtes, atm_iv, skew_k, curv, moves,
    delta_floor=0.05, beta_t=-1.0, hold_days=0.0, mult=100.0, r=0.0, q=0.0,
) -> dict:
    regimes = ("sticky_strike", "sticky_moneyness", "leverage")
    cells = []
    for dte in dtes:
        T = dte / fr.DAYS_PER_YEAR
        for K in strikes:
            k = math.log(K / spot)
            iv_k = max(fr.SIGMA_FLOOR, atm_iv + skew_k * k + curv * k * k)
            x = k / (atm_iv * math.sqrt(T))  # standardized moneyness
            flat = fr._bs(side, spot, K, atm_iv, T, r, q)["price"]      # flat-ATM-BS
            skewed = fr._bs(side, spot, K, iv_k, T, r, q)["price"]       # "market" under skew
            premium = max(MIN_TICK, skewed)
            # single skew-slope drives BOTH the static surface and the sticky-moneyness
            # reanchoring under a spot move (same local d_sigma/d_k).
            res = fr.compute(side, spot, K, iv_k, dte, premium, moves,
                             skew_slope=skew_k, beta_t=beta_t, hold_days=hold_days,
                             mult=mult, r=r, q=q, regimes=regimes)
            g = res["base_greeks"]
            cells.append({
                "dte": dte, "strike": round(K, 2),
                "x_std_moneyness": round(x, 3), "k_log_moneyness": round(k, 4),
                "iv_strike": round(iv_k, 4), "skew_residual": round(iv_k - atm_iv, 4),
                "basis_gap_flag": round(premium / flat, 2) if flat > 1e-9 else None,
                "abs_delta": g["abs_delta_as_pop"], "premium": round(premium, 4),
                "admit": g["abs_delta_as_pop"] >= delta_floor,
                "regimes": {rg: _flip_move(res["scenarios"], rg) for rg in regimes},
            })

    # non-destructive gate ledger: what a rigid floor would blind us to
    winners = [c for c in cells if c["regimes"]["sticky_moneyness"]["max_favorable_return"] > 1.0]
    blinded = [c for c in winners if not c["admit"]]
    gate_ledger = {
        "delta_floor": delta_floor,
        "cells_total": len(cells),
        "cells_admitted": sum(1 for c in cells if c["admit"]),
        "convex_winners_gt_1x_sticky_moneyness": len(winners),
        "winners_blinded_by_floor": len(blinded),
        "note": "a 'winner' here is a modeled >100% return under the sticky_moneyness "
                "regime; it is NOT an expected-return claim (no physical measure, no costs). "
                "Shown so the floor's blind spot is countable, per the stratify-don't-gate grounding.",
    }

    return {
        "inputs": {
            "side": side, "spot": spot, "atm_iv": atm_iv, "skew_k": skew_k, "curv": curv,
            "strikes": strikes, "dtes": dtes, "delta_floor": delta_floor,
            "beta_t": beta_t, "hold_days": hold_days, "moves": moves,
            "mode": "modeled_skew (feed broker rows for live)",
        },
        "cells": cells,
        "gate_ledger": gate_ledger,
        "flip_grids": _ascii_grids(cells, strikes, dtes, moves),
        "notes": [
            "x = standardized moneyness ln(K/F)/(atm_iv*sqrt(T)); compare the surface on x, not raw strike.",
            "basis_gap_flag is a CRUDE flag (modeled premium / flat-ATM-BS); far-wing blowups are a tiny-denominator artifact, not richness. skew_residual is the real richness layer.",
            "flip_move = smallest move where the long flips to positive under that regime; null = no flip in the swept range.",
            "gate is a non-destructive TAG; no cells dropped. See gate_ledger.winners_blinded_by_floor.",
            "modeled skew, not live quotes — this proves the representation; feed real broker per-strike iv/premium for the live surface.",
        ],
        "computed_at_utc": datetime.now(timezone.utc).isoformat(),
    }


def _ascii_grids(cells, strikes, dtes, moves) -> dict:
    """Per-regime glanceable flip map: rows=strikes (by x), cols=moves, + = long wins."""
    grids = {}
    for rg in ("sticky_strike", "sticky_moneyness", "leverage"):
        lines = []
        for dte in dtes:
            header = "  x\\move " + " ".join(f"{m:+.2f}" for m in moves)
            lines.append(f"[dte={dte}]")
            lines.append(header)
            for K in strikes:
                c = next((c for c in cells if c["dte"] == dte and abs(c["strike"] - K) < 1e-6), None)
                if not c:
                    continue
                marks = []
                for s_m in moves:
                    # recompute sign cheaply from the regime's max/flip isn't per-move; mark by flip
                    fm = c["regimes"][rg]["flip_move"]
                    win = fm is not None and ((s_m >= fm) if fm >= 0 else (s_m <= fm))
                    marks.append("  +  " if win else "  ·  ")
                tag = "" if c["admit"] else " (rej)"
                lines.append(f"x={c['x_std_moneyness']:+.2f} " + "".join(m[:5] for m in marks) + tag)
        grids[rg] = "\n".join(lines)
    return grids


def main() -> None:
    p = argparse.ArgumentParser()
    p.add_argument("--side", required=True, choices=["call", "put"])
    p.add_argument("--spot", type=float, required=True)
    p.add_argument("--atm-iv", type=float, required=True, help="ATM annualized IV decimal")
    p.add_argument("--skew-k", type=float, default=-0.40, help="static skew slope d_sigma/d_k (equity < 0)")
    p.add_argument("--curv", type=float, default=0.0, help="smile curvature in k^2")
    p.add_argument("--strikes", required=True, help="comma list of strikes")
    p.add_argument("--dtes", required=True, help="comma list of calendar DTEs")
    p.add_argument("--moves", default="", help="comma list of move fractions; default -0.20..+0.50 step 0.05")
    p.add_argument("--delta-floor", type=float, default=0.05, help="admit/reject tag threshold (non-destructive)")
    p.add_argument("--beta-t", type=float, default=-1.0)
    p.add_argument("--hold-days", type=float, default=0.0)
    p.add_argument("--mult", type=float, default=100.0)
    p.add_argument("--r", type=float, default=0.0)
    p.add_argument("--q", type=float, default=0.0)
    args = p.parse_args()

    try:
        strikes = [float(s) for s in args.strikes.split(",") if s.strip()]
        dtes = [int(d) for d in args.dtes.split(",") if d.strip()]
        if args.moves.strip():
            moves = [float(m) for m in args.moves.split(",") if m.strip()]
        else:
            moves = [round(-0.20 + 0.05 * i, 2) for i in range(15)]  # -0.20 .. +0.50
    except ValueError:
        print(json.dumps({"error": "strikes/dtes/moves must be numeric comma lists"}))
        raise SystemExit(1)

    out = compute_surface(
        side=args.side, spot=args.spot, strikes=strikes, dtes=dtes,
        atm_iv=args.atm_iv, skew_k=args.skew_k, curv=args.curv, moves=moves,
        delta_floor=args.delta_floor, beta_t=args.beta_t, hold_days=args.hold_days,
        mult=args.mult, r=args.r, q=args.q,
    )
    print(json.dumps(out, indent=2))


if __name__ == "__main__":
    main()
