#!/usr/bin/env python3
"""
fringe_response.py — napkin fringe-response under a spot move + skew rewrite.

Stepping-stone, NOT a final home: this is napkin math to get the fringe lane
moving. The real consumer is programmatic (PHP -> /run/fringe_response, and
later a proper module), so the contract here is "numbers in -> JSON out" like
the other one-off scripts in this dir. Don't build ceremony around it.

Model (peer-reviewed v0): a deep-OTM fringe doesn't pay mainly from raw delta;
it pays when a violent underlying move REWRITES the implied-vol the market
charges that strike. So we don't trust a greek-Taylor sum as the price — we
predict a destination IV from an explicit surface rule, then REPRICE the same
strike/expiry at the destination spot and IV with Black-Scholes. The greek
expansion (incl. cross-greeks vanna/volga) rides along only as attribution.

Destination-IV rule, for a FIXED contract strike K (so dK=0; it's log-moneyness
k=ln(K/F) that moves), decomposed over dlnF = ln(S'/S):

    d_sigma_K = beta_t * dlnF            # ATM/level response (equity leverage: <0)
              - skew_slope * dlnF        # fixed-shape moneyness reanchoring
                                         #   (skew_slope = d_sigma/d_k, equity: <0)

Three stickiness regimes are run as a FAN, not a single truth (the sign of a
fringe-call IV move on an up-move is genuinely ambiguous until we fit beta_t
from our own surface history):
    sticky_strike      d_sigma_K = 0
    sticky_moneyness   d_sigma_K = -skew_slope * dlnF        (beta_t = 0)
    leverage           d_sigma_K = (beta_t - skew_slope) * dlnF

beta_t / skew_slope defaults are PLACEHOLDERS to be replaced by a local
linear-in-log-moneyness skew fit off live quotes — do not treat them as real.

Time uses calendar years (DTE/365), the standard BS convention. NOTE this
diverges from single_leg_ev.py which scales calendar DTE by 252; reconcile
later. Greeks are per contract where dollarized (multiplier default 100).

Usage:
    fringe_response.py --side call --spot 350 --strike 700 --iv 0.90 --dte 21 \\
        --premium 0.45 --skew-slope -0.40 --beta-t -1.0 --moves 0.20,0.35,0.50
"""
import argparse
import json
import math
from datetime import datetime, timezone

DAYS_PER_YEAR = 365.0
SIGMA_FLOOR = 0.01            # don't let a destination IV go <=0
MIN_T_YEARS = 0.25 / DAYS_PER_YEAR  # ~quarter-day floor so repricing stays finite


def _norm_cdf(x: float) -> float:
    return 0.5 * (1.0 + math.erf(x / math.sqrt(2.0)))


def _norm_pdf(x: float) -> float:
    return math.exp(-0.5 * x * x) / math.sqrt(2.0 * math.pi)


def _bs(side: str, S: float, K: float, sigma: float, T: float, r: float, q: float) -> dict:
    """Black-Scholes price + first- and second-order greeks for one leg.

    sigma is annualized decimal; T in years. vega/vanna/volga are per UNIT
    sigma (per 1.00 vol, not per vol-point) — convert at the edges.
    """
    if sigma <= 0 or T <= 0 or S <= 0 or K <= 0:
        return {"error": "S, K, sigma, T must all be > 0"}

    sqrt_t = math.sqrt(T)
    d1 = (math.log(S / K) + (r - q + 0.5 * sigma * sigma) * T) / (sigma * sqrt_t)
    d2 = d1 - sigma * sqrt_t
    disc_q = math.exp(-q * T)
    disc_r = math.exp(-r * T)
    pdf_d1 = _norm_pdf(d1)

    if side == "call":
        price = S * disc_q * _norm_cdf(d1) - K * disc_r * _norm_cdf(d2)
        delta = disc_q * _norm_cdf(d1)
        theta_year = (-(S * disc_q * pdf_d1 * sigma) / (2 * sqrt_t)
                      - r * K * disc_r * _norm_cdf(d2)
                      + q * S * disc_q * _norm_cdf(d1))
    else:  # put
        price = K * disc_r * _norm_cdf(-d2) - S * disc_q * _norm_cdf(-d1)
        delta = -disc_q * _norm_cdf(-d1)
        theta_year = (-(S * disc_q * pdf_d1 * sigma) / (2 * sqrt_t)
                      + r * K * disc_r * _norm_cdf(-d2)
                      - q * S * disc_q * _norm_cdf(-d1))

    gamma = disc_q * pdf_d1 / (S * sigma * sqrt_t)
    vega = S * disc_q * pdf_d1 * sqrt_t              # per unit sigma
    vanna = -disc_q * pdf_d1 * d2 / sigma            # d(delta)/d(sigma) = d(vega)/dS
    volga = vega * d1 * d2 / sigma                   # d(vega)/d(sigma)

    return {
        "price": price,
        "delta": delta,
        "gamma": gamma,
        "vega_per_unit": vega,
        "theta_year": theta_year,
        "vanna": vanna,
        "volga": volga,
        "d1": d1,
        "d2": d2,
    }


def _d_sigma(regime: str, dlnf: float, skew_slope: float, beta_t: float) -> float:
    if regime == "sticky_strike":
        return 0.0
    if regime == "sticky_moneyness":
        return -skew_slope * dlnf
    if regime == "leverage":
        return (beta_t - skew_slope) * dlnf
    raise ValueError(f"unknown regime: {regime}")


def compute(
    side: str,
    spot: float,
    strike: float,
    iv: float,
    dte: int,
    premium: float,
    moves: list[float],
    skew_slope: float = -0.40,
    beta_t: float = -1.0,
    hold_days: float = 0.0,
    mult: float = 100.0,
    r: float = 0.0,
    q: float = 0.0,
    regimes: tuple = ("sticky_strike", "sticky_moneyness", "leverage"),
) -> dict:
    if side not in ("call", "put"):
        return {"error": "side must be 'call' or 'put'"}
    if min(spot, strike, iv, premium) <= 0 or dte <= 0:
        return {"error": "spot, strike, iv, premium, dte must all be > 0"}

    T = dte / DAYS_PER_YEAR
    base = _bs(side, spot, strike, iv, T, r, q)
    if "error" in base:
        return base

    # delta-as-POP first-cut, and the dollar-gamma exposure metric (LABELLED:
    # it is dollar-delta change per 1% move, NOT convexity P&L, which is half as
    # much: 0.005 * gamma * S^2).
    greeks = {
        "price_model": round(base["price"], 4),
        "market_premium": round(premium, 4),
        "basis_gap_model_minus_market": round(base["price"] - premium, 4),
        "delta": round(base["delta"], 4),
        "abs_delta_as_pop": round(abs(base["delta"]), 4),
        "gamma": round(base["gamma"], 6),
        "vega_per_vol_point": round(base["vega_per_unit"] * 0.01, 4),
        "theta_per_day": round(base["theta_year"] / DAYS_PER_YEAR, 4),
        "vanna_per_vol_point": round(base["vanna"] * 0.01, 6),
        "volga_per_vol_point2": round(base["volga"] * 0.0001, 6),
        "dollar_delta_change_per_1pct": round(base["gamma"] * mult * spot * spot * 0.01, 2),
        "convexity_pnl_per_1pct": round(0.005 * base["gamma"] * mult * spot * spot, 2),
    }

    T_dest = max(MIN_T_YEARS, (dte - hold_days) / DAYS_PER_YEAR)
    scenarios = []
    for m in moves:
        spot_dest = spot * (1.0 + m)
        dlnf = math.log(spot_dest / spot)
        ds = spot_dest - spot
        regime_out = {}
        for regime in regimes:
            d_sig = _d_sigma(regime, dlnf, skew_slope, beta_t)
            iv_dest = max(SIGMA_FLOOR, iv + d_sig)
            priced = _bs(side, spot_dest, strike, iv_dest, T_dest, r, q)
            v_dest = priced["price"]
            payoff_ct = (v_dest - premium) * mult
            # greek attribution (dollars/contract) vs the repriced truth
            theta_term = base["theta_year"] * (hold_days / DAYS_PER_YEAR) * mult
            delta_term = base["delta"] * ds * mult
            gamma_term = 0.5 * base["gamma"] * ds * ds * mult
            vega_term = base["vega_per_unit"] * d_sig * mult
            vanna_term = base["vanna"] * ds * d_sig * mult
            volga_term = 0.5 * base["volga"] * d_sig * d_sig * mult
            taylor_total = theta_term + delta_term + gamma_term + vega_term + vanna_term + volga_term
            repriced_dV = (v_dest - base["price"]) * mult
            regime_out[regime] = {
                "d_sigma": round(d_sig, 4),
                "iv_dest": round(iv_dest, 4),
                "price_dest": round(v_dest, 4),
                "payoff_per_contract": round(payoff_ct, 2),
                "return_multiple": round(v_dest / premium - 1.0, 3),
                "attribution_usd": {
                    "theta": round(theta_term, 2),
                    "delta": round(delta_term, 2),
                    "gamma": round(gamma_term, 2),
                    "vega": round(vega_term, 2),
                    "vanna": round(vanna_term, 2),
                    "volga": round(volga_term, 2),
                    "taylor_total": round(taylor_total, 2),
                    "repriced_truth": round(repriced_dV, 2),
                    "taylor_residual": round(repriced_dV - taylor_total, 2),
                },
            }
        scenarios.append({
            "move_pct": round(m, 4),
            "spot_dest": round(spot_dest, 4),
            "regimes": regime_out,
        })

    return {
        "inputs": {
            "side": side, "spot": round(spot, 4), "strike": round(strike, 4),
            "iv": round(iv, 4), "dte": dte, "premium": round(premium, 4),
            "skew_slope_d_sigma_d_k": skew_slope, "beta_t": beta_t,
            "hold_days": hold_days, "multiplier": mult, "r": r, "q": q,
            "log_moneyness_k": round(math.log(strike / spot), 4),
        },
        "base_greeks": greeks,
        "scenarios": scenarios,
        "notes": [
            "PRICE comes from repricing at destination (spot', iv'); the greek "
            "attribution is an audit trail, not the forecast. Compare attribution vs "
            "repriced_truth — a large taylor_residual means the local expansion is "
            "unreliable for that move (expected for big moves / deep-OTM).",
            "Three stickiness regimes are a FAN, not a prediction. The fringe-call IV "
            "sign on an up-move is set by beta_t (level, <0 pulls IV down) vs skew_slope "
            "reanchoring (>0 pushes IV up); net is regime-dependent.",
            "beta_t and skew_slope are PLACEHOLDERS. Replace with a robust local "
            "linear-in-log-moneyness skew fit off live quotes; mark low-confidence if the "
            "fringe sits outside the well-quoted neighborhood.",
            "dollar_delta_change_per_1pct is exposure (gamma*mult*S^2*0.01), NOT P&L. "
            "Convexity P&L for a 1% move is 0.005*gamma*mult*S^2 (half).",
            "Time scaled by calendar DTE/365 (standard BS); single_leg_ev.py uses /252 — "
            "reconcile the convention later.",
            "Napkin stepping-stone: consumed programmatically via /run/fringe_response; "
            "not the final home for this math.",
        ],
        "computed_at_utc": datetime.now(timezone.utc).isoformat(),
    }


def main() -> None:
    p = argparse.ArgumentParser()
    p.add_argument("--side", required=True, choices=["call", "put"])
    p.add_argument("--spot", type=float, required=True)
    p.add_argument("--strike", type=float, required=True)
    p.add_argument("--iv", type=float, required=True, help="annualized IV decimal (e.g. 0.90)")
    p.add_argument("--dte", type=int, required=True, help="calendar days to expiry")
    p.add_argument("--premium", type=float, required=True, help="entry cost per share (market ask)")
    p.add_argument("--moves", default="0.05,0.10,0.20,0.35",
                   help="comma list of underlying move fractions (0.20 = +20%)")
    p.add_argument("--skew-slope", type=float, default=-0.40,
                   help="local d_sigma/d_k in log-moneyness (equity skew < 0); PLACEHOLDER")
    p.add_argument("--beta-t", type=float, default=-1.0,
                   help="ATM-IV response to dlnF (equity leverage < 0); PLACEHOLDER")
    p.add_argument("--hold-days", type=float, default=0.0, help="calendar days elapsed in the scenario")
    p.add_argument("--mult", type=float, default=100.0)
    p.add_argument("--r", type=float, default=0.0)
    p.add_argument("--q", type=float, default=0.0)
    args = p.parse_args()

    try:
        moves = [float(x) for x in args.moves.split(",") if x.strip() != ""]
    except ValueError:
        print(json.dumps({"error": "--moves must be a comma list of floats"}))
        raise SystemExit(1)

    out = compute(
        side=args.side, spot=args.spot, strike=args.strike, iv=args.iv,
        dte=args.dte, premium=args.premium, moves=moves,
        skew_slope=args.skew_slope, beta_t=args.beta_t, hold_days=args.hold_days,
        mult=args.mult, r=args.r, q=args.q,
    )
    print(json.dumps(out, indent=2))


if __name__ == "__main__":
    main()
