#!/usr/bin/env python3
"""
convex_precompute.py — fire-ready convex row (napkin).

The alt thesis: we WRAP the venue and EXPRESS convexity — we don't predict, and
the Greeks are supplementary, not authoritative. The edge is expression LATENCY
vs retail reaction, so at the fire instant the decision must be a LOOKUP, not a
computation. This composer precomputes, per candidate, the one row a fast loop
would read: the anchor delta-acceleration trigger, the fringe, the model-light
convexity score of its reprice curve, and the flip move per regime.

It composes the existing napkin primitives (no new math): anchor_select picks the
legs from the vol cone (thesis-free; JSON shim to override), fringe_response
reprices the fringe across the move spectrum under the 3 regimes. The convexity
score is read off the realized reprice curve, NOT off a Greek — Greeks ride along
only as fringe_response's attribution.

In broker mode the local skew slope is finite-differenced from the real per-strike
IV (so a call-skew smile flips the regime sign correctly). NOT authoritative —
baseline posture, tested live with small clips.

Usage:
    convex_precompute.py --side call --spot 239.17 --dte 24 --rows '<json>'
    convex_precompute.py --side call --spot 350 --atm-iv 0.70 --dte 24 --skew-slope -0.40
"""
import argparse
import json
import math
import os
import sys
from datetime import datetime, timezone

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import anchor_select as asel  # noqa: E402
import fringe_response as fr   # noqa: E402

REGIMES = ("sticky_strike", "sticky_moneyness", "leverage")


def local_skew_slope(rows, spot, strike):
    """Finite-difference d_sigma/d_k from real per-strike IV around `strike`."""
    pts = sorted(
        [(float(r["strike"]), float(r["iv"])) for r in rows if r.get("iv") not in (None, "")],
        key=lambda p: p[0],
    )
    if len(pts) < 2:
        return None
    idx = min(range(len(pts)), key=lambda i: abs(pts[i][0] - strike))
    lo, hi = max(0, idx - 1), min(len(pts) - 1, idx + 1)
    (klo, slo), (khi, shi) = pts[lo], pts[hi]
    a, b = math.log(klo / spot), math.log(khi / spot)
    return None if b == a else round((shi - slo) / (b - a), 4)


def convexity_from_curve(scenarios, regime):
    """Model-light convexity read off the realized reprice curve (not a Greek)."""
    pts = sorted([(s["move_pct"], s["regimes"][regime]["return_multiple"]) for s in scenarios],
                 key=lambda p: p[0])
    rets = [r for _, r in pts]
    maxfav, maxadv = max(rets), min(rets)
    # flip: smallest move where the long crosses 0
    flip = None
    for (m0, r0), (m1, r1) in zip(pts, pts[1:]):
        if (r0 < 0) != (r1 < 0) and r1 != r0:
            flip = round(m0 + (0 - r0) * (m1 - m0) / (r1 - r0), 4)
            break
    # convex acceleration: does the up-move return grow FASTER than linear?
    ups = [(m, r) for m, r in pts if m > 0 and r > 0]
    accel = None
    if len(ups) >= 2:
        (m1, r1), (m2, r2) = ups[0], ups[-1]
        lin = r1 * (m2 / m1)
        accel = round(r2 / lin, 2) if lin > 0 else None
    return {
        # lopsidedness of the expressed shape: x of uncapped upside per x of capped downside
        "asymmetry_ratio": round(maxfav / max(abs(maxadv), 0.01), 2),
        "convex_accel": accel,  # >1 = upside accelerates (genuinely convex), ~1 = linear
        "flip_move": flip,
        "max_favorable_return": round(maxfav, 3),
        "max_adverse_return": round(maxadv, 3),
    }


def compute(side, spot, dte, rows=None, atm_iv=None, thesis=None, x_anchor=1.0, x_fringe=2.5,
            moves=None, skew_slope=-0.40, beta_t=-1.0, mult=100.0, r=0.0, q=0.0):
    if moves is None:
        moves = [round(-0.20 + 0.05 * i, 2) for i in range(15)]
    sel = asel.compute(side, spot, dte, atm_iv=atm_iv, rows=rows, thesis=thesis,
                       x_anchor=x_anchor, x_fringe=x_fringe, mult=mult, r=r, q=q)
    if "error" in sel:
        return sel
    anchor, fringe = sel["anchor"], sel["fringe"]

    slope = local_skew_slope(rows, spot, fringe["strike"]) if rows else skew_slope
    if slope is None:
        slope = skew_slope

    fres = fr.compute(side, spot, fringe["strike"], fringe["iv"], dte, fringe["premium"], moves,
                      skew_slope=slope, beta_t=beta_t, mult=mult, r=r, q=q, regimes=REGIMES)
    conv = {rg: convexity_from_curve(fres["scenarios"], rg) for rg in REGIMES}
    now = datetime.now(timezone.utc).isoformat()

    return {
        # at fire time this row is a LOOKUP, not a computation — recompute it continuously upstream
        "fire_row": {
            "ts": now, "side": side, "spot": round(spot, 4), "dte": dte,
            "atm_iv": sel["inputs"]["atm_iv"], "mode": sel["inputs"]["mode"],
            "anchor": {"strike": anchor["strike"], "abs_delta": anchor["abs_delta"],
                       "delta_accel_per_1pct": anchor["delta_accel_per_1pct"]},
            "fringe": {"strike": fringe["strike"], "iv": fringe["iv"], "premium": fringe["premium"],
                       "abs_delta": fringe["abs_delta"], "skew_residual_vs_atm": fringe["skew_residual_vs_atm"]},
            "local_skew_slope_used": slope,
            "convexity": conv,
            "trigger": {
                "watch": "anchor.delta_accel_per_1pct",
                "fire_when": "underlying running toward the anchor strike (anchor delta accelerating)",
                "note": "convex row precomputed; fire is a table lookup, not a computation.",
            },
        },
        "notes": [
            "Convexity is read off the realized reprice curve (asymmetry + convex_accel), NOT off a Greek. "
            "Greeks are supplementary — see fringe_response attribution if you want the decomposition.",
            "asymmetry_ratio = uncapped-upside x per capped-downside x (shape, NOT expected value — we express, "
            "the market prices the probability). convex_accel>1 = upside accelerates.",
            "broker mode: local_skew_slope finite-differenced from real per-strike IV (a call-skew smile gives a "
            "positive slope, which flips the sticky-moneyness regime sign vs the downside default).",
            "NAPKIN, not authoritative. Edge is expression latency vs retail, not prediction. Test small, iterate.",
        ],
        "computed_at_utc": now,
    }


def main() -> None:
    p = argparse.ArgumentParser()
    p.add_argument("--side", required=True, choices=["call", "put"])
    p.add_argument("--spot", type=float, required=True)
    p.add_argument("--dte", type=int, required=True)
    p.add_argument("--atm-iv", type=float)
    p.add_argument("--rows", help="JSON list of {strike,iv,delta?,gamma?,premium?}")
    p.add_argument("--thesis-json", help="shim override {anchor_strike|anchor_x, fringe_strike|fringe_x}")
    p.add_argument("--moves", default="")
    p.add_argument("--x-anchor", type=float, default=1.0)
    p.add_argument("--x-fringe", type=float, default=2.5)
    p.add_argument("--skew-slope", type=float, default=-0.40, help="used in computed mode; broker mode finite-diffs it")
    p.add_argument("--beta-t", type=float, default=-1.0)
    p.add_argument("--mult", type=float, default=100.0)
    p.add_argument("--r", type=float, default=0.0)
    p.add_argument("--q", type=float, default=0.0)
    args = p.parse_args()

    rows = thesis = None
    moves = None
    try:
        if args.rows:
            rows = json.loads(args.rows)
        if args.thesis_json:
            thesis = json.loads(args.thesis_json)
        if args.moves.strip():
            moves = [float(m) for m in args.moves.split(",") if m.strip()]
    except json.JSONDecodeError as e:
        print(json.dumps({"error": f"bad JSON: {e}"}))
        raise SystemExit(1)

    out = compute(side=args.side, spot=args.spot, dte=args.dte, atm_iv=args.atm_iv, rows=rows,
                  thesis=thesis, x_anchor=args.x_anchor, x_fringe=args.x_fringe, moves=moves,
                  skew_slope=args.skew_slope, beta_t=args.beta_t, mult=args.mult, r=args.r, q=args.q)
    print(json.dumps(out, indent=2))


if __name__ == "__main__":
    main()
