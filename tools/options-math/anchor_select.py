#!/usr/bin/env python3
"""
anchor_select.py — thesis-free anchor + fringe strike picker (napkin).

Removes the human-generated thesis as a dependency: if we're harvesting
convexity, the anchor is computable from the vol cone, not handed to us. The
anchor sits at ~1 sigma (the plausible "it could run here" level, where delta
is about to accelerate toward ATM); the fringe sits deep in the tail (~2.5
sigma) where a violent move reprices the skew. Both are derived from
{spot, ATM IV, DTE} via standardized moneyness  x = ln(K/F)/(sigma*sqrt(T)):

    K(x) = spot * exp(x * atm_iv * sqrt(T))            (F ~ spot, r=q=0)

NOT authoritative — napkin baseline posture. The market is the judge; we test
with small clips and iterate.

Three input modes:
  computed  : --spot --atm-iv --dte             (prices legs flat at ATM IV)
  broker    : --rows '<json>' --spot --dte       (snaps to listed strikes, uses
              real per-strike iv/delta/gamma/premium; ATM IV read from the chain)
  shim      : --thesis-json '{"anchor_strike":..,"fringe_strike":..}'  (operator
              override to probe a specific idea; may also give anchor_x/fringe_x)

rows JSON = list of {strike, iv, delta?, gamma?, premium?}. Premium/greeks from
the row win when present; otherwise computed flat at the row's iv.

Usage:
    anchor_select.py --side call --spot 177 --atm-iv 0.45 --dte 24
    anchor_select.py --side call --spot 177 --dte 24 --rows '[{"strike":180,"iv":0.44,...}]'
    anchor_select.py --side call --spot 177 --atm-iv 0.45 --dte 24 \\
        --thesis-json '{"anchor_strike":190,"fringe_strike":230}'
"""
import argparse
import json
import math
import os
import sys
from datetime import datetime, timezone

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import fringe_response as fr  # noqa: E402


def _strike_at_x(spot: float, x: float, atm_iv: float, T: float) -> float:
    return spot * math.exp(x * atm_iv * math.sqrt(T))


def _nearest_row(rows: list, target_strike: float) -> dict:
    return min(rows, key=lambda r: abs(float(r["strike"]) - target_strike))


def _leg(side, spot, strike, iv, dte, T, mult, r, q, row=None) -> dict:
    g = fr._bs(side, spot, strike, iv, T, r, q)
    premium = float(row["premium"]) if row and row.get("premium") not in (None, "") else max(0.01, g["price"])
    delta = float(row["delta"]) if row and row.get("delta") not in (None, "") else g["delta"]
    gamma = float(row["gamma"]) if row and row.get("gamma") not in (None, "") else g["gamma"]
    x = math.log(strike / spot) / (iv * math.sqrt(T)) if iv > 0 else None
    return {
        "strike": round(strike, 2),
        "x_std_moneyness": round(x, 3) if x is not None else None,
        "iv": round(iv, 4),
        "abs_delta": round(abs(delta), 4),
        "gamma": round(gamma, 6),
        "premium": round(premium, 4),
        "model_price_flat": round(g["price"], 4),
        # delta gained per 1% move — the robust "delta is accelerating" scalar (grounding Q1)
        "delta_accel_per_1pct": round(gamma * 0.01 * spot, 4),
        "dollar_gamma_exposure_per_1pct": round(gamma * mult * spot * spot * 0.01, 2),
        "source": "broker_row" if row else "computed_flat",
    }


def compute(side, spot, dte, atm_iv=None, rows=None, thesis=None,
            x_anchor=1.0, x_fringe=2.5, mult=100.0, r=0.0, q=0.0) -> dict:
    if side not in ("call", "put"):
        return {"error": "side must be 'call' or 'put'"}
    if spot <= 0 or dte <= 0:
        return {"error": "spot and dte must be > 0"}
    T = dte / fr.DAYS_PER_YEAR
    sign = 1.0 if side == "call" else -1.0

    # ATM IV: from the chain (nearest-spot row) in broker mode, else the supplied scalar.
    if rows:
        atm_row = _nearest_row(rows, spot)
        atm_iv = float(atm_row["iv"])
    if not atm_iv or atm_iv <= 0:
        return {"error": "atm_iv required (or derivable from rows)"}

    expected_move_1sigma = spot * atm_iv * math.sqrt(T)

    # Target strikes: shim override wins, else compute from the vol cone.
    thesis = thesis or {}
    def _resolve(which, default_x):
        key_k, key_x = f"{which}_strike", f"{which}_x"
        if thesis.get(key_k) not in (None, ""):
            return float(thesis[key_k]), "shim_strike"
        xt = float(thesis[key_x]) if thesis.get(key_x) not in (None, "") else default_x
        return _strike_at_x(spot, sign * abs(xt), atm_iv, T), "computed_x"

    a_strike, a_src = _resolve("anchor", x_anchor)
    f_strike, f_src = _resolve("fringe", x_fringe)

    # Snap to listed strikes + pull real greeks in broker mode.
    a_row = f_row = None
    a_iv = f_iv = atm_iv
    if rows:
        a_row = _nearest_row(rows, a_strike); a_strike = float(a_row["strike"]); a_iv = float(a_row["iv"])
        f_row = _nearest_row(rows, f_strike); f_strike = float(f_row["strike"]); f_iv = float(f_row["iv"])

    anchor = _leg(side, spot, a_strike, a_iv, dte, T, mult, r, q, a_row)
    fringe = _leg(side, spot, f_strike, f_iv, dte, T, mult, r, q, f_row)
    anchor["target_source"] = a_src
    fringe["target_source"] = f_src
    fringe["skew_residual_vs_atm"] = round(f_iv - atm_iv, 4)
    anchor["skew_residual_vs_atm"] = round(a_iv - atm_iv, 4)

    return {
        "inputs": {
            "side": side, "spot": round(spot, 4), "dte": dte, "atm_iv": round(atm_iv, 4),
            "x_anchor": x_anchor, "x_fringe": x_fringe, "mode": "broker" if rows else "computed",
            "shim": thesis or None, "mult": mult,
        },
        "expected_move_1sigma": round(expected_move_1sigma, 4),
        "expected_move_pct": round(atm_iv * math.sqrt(T), 4),
        "anchor": anchor,
        "fringe": fringe,
        "notes": [
            "Anchor computed from the vol cone (standardized moneyness), NOT a human thesis; "
            "--thesis-json shims an override when probing a specific idea.",
            "Anchor at ~1 sigma = plausible run target where delta accelerates; fringe at ~2.5 sigma "
            "= deep tail that pays on a skew reanchor. delta_accel_per_1pct = gamma*0.01*spot.",
            "NAPKIN baseline, not authoritative. In computed mode legs are priced flat at ATM IV (no "
            "skew); feed broker rows for real per-strike iv/greeks. The market is the judge — test small.",
        ],
        "computed_at_utc": datetime.now(timezone.utc).isoformat(),
    }


def main() -> None:
    p = argparse.ArgumentParser()
    p.add_argument("--side", required=True, choices=["call", "put"])
    p.add_argument("--spot", type=float, required=True)
    p.add_argument("--dte", type=int, required=True)
    p.add_argument("--atm-iv", type=float, help="ATM IV decimal (required unless --rows given)")
    p.add_argument("--rows", help="JSON list of {strike,iv,delta?,gamma?,premium?} broker contracts")
    p.add_argument("--thesis-json", help="shim override {anchor_strike|anchor_x, fringe_strike|fringe_x}")
    p.add_argument("--x-anchor", type=float, default=1.0)
    p.add_argument("--x-fringe", type=float, default=2.5)
    p.add_argument("--mult", type=float, default=100.0)
    p.add_argument("--r", type=float, default=0.0)
    p.add_argument("--q", type=float, default=0.0)
    args = p.parse_args()

    rows = thesis = None
    try:
        if args.rows:
            rows = json.loads(args.rows)
        if args.thesis_json:
            thesis = json.loads(args.thesis_json)
    except json.JSONDecodeError as e:
        print(json.dumps({"error": f"bad JSON: {e}"}))
        raise SystemExit(1)

    out = compute(side=args.side, spot=args.spot, dte=args.dte, atm_iv=args.atm_iv,
                  rows=rows, thesis=thesis, x_anchor=args.x_anchor, x_fringe=args.x_fringe,
                  mult=args.mult, r=args.r, q=args.q)
    print(json.dumps(out, indent=2))


if __name__ == "__main__":
    main()
