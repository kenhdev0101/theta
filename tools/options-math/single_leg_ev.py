#!/usr/bin/env python3
"""
single_leg_ev.py — expected-value calc for single-leg long options (long call, long put).

For a long option, the debit is paid in EVERY scenario (no path skips it). The
correct EV decomposition is:

    EV = POP_ITM * (avg_intrinsic_if_ITM - debit) + (1 - POP_ITM) * (-debit)
       = POP_ITM * avg_intrinsic_if_ITM - debit

The trailing term is `debit`, NOT `(1 - POP) * debit`. Using the latter
double-counts the debit benefit on the loss side and overstates EV by
`POP * debit` per share. This script enforces the correct formula.

Also distinguishes:
    - POP_ITM = P(S_T crosses strike) — used for naive delta proxy
    - P(profit) = P(S_T crosses strike + debit for calls / strike - debit for puts)
      — the correct input for Kelly sizing

Two modes:
    Mode A (manual): pass spot, strike, IV, DTE, debit explicitly.
        single_leg_ev.py --side put --spot 550 --strike 550 --iv 0.30 --dte 8 --debit 8.75

    Mode B (auto): autofetch ATM straddle and IV from yfinance.
        single_leg_ev.py --side put --ticker NOC --strike 550 --expiry 2026-05-15 --debit 8.75

Optional flags:
    --drift-pct-per-day -0.0077      apply directional drift (e.g. from regime_classify slope)
    --iv-haircut 0.30                fraction to haircut IV30 down to estimate term-structure-adjusted IV
                                     (e.g. 0.30 -> use 70% of IV30 as the 8-day IV)
"""
import argparse
import json
import math
import sys
import warnings
from datetime import datetime, timezone

warnings.filterwarnings("ignore")


def _norm_cdf(x: float) -> float:
    return 0.5 * (1.0 + math.erf(x / math.sqrt(2.0)))


def _norm_pdf(x: float) -> float:
    return math.exp(-0.5 * x * x) / math.sqrt(2.0 * math.pi)


def _truncated_lognormal_mean_below(spot: float, strike: float, sigma: float, drift: float = 0.0) -> float:
    """E[strike - S | S < strike] under lognormal with optional drift, expressed in $ payoff terms.

    sigma here is the period-volatility (already scaled by sqrt(T)).
    drift is the period drift (mean log return over the period).

    Returns the conditional expected put intrinsic given the put finishes ITM.
    """
    if sigma <= 0 or spot <= 0 or strike <= 0:
        return 0.0

    # Under lognormal: ln(S_T) ~ N(ln(spot) + drift, sigma^2)
    # Standardize: z = (ln(K) - ln(spot) - drift) / sigma
    z = (math.log(strike) - math.log(spot) - drift) / sigma
    cdf_z = _norm_cdf(z)
    if cdf_z <= 1e-9:
        return 0.0

    # E[S | S < K] = spot * exp(drift + sigma^2/2) * Phi(z - sigma) / Phi(z)
    e_s_given_below = spot * math.exp(drift + 0.5 * sigma * sigma) * _norm_cdf(z - sigma) / cdf_z
    avg_intrinsic = strike - e_s_given_below
    return max(0.0, avg_intrinsic)


def _truncated_lognormal_mean_above(spot: float, strike: float, sigma: float, drift: float = 0.0) -> float:
    """E[S - strike | S > strike] under lognormal with optional drift."""
    if sigma <= 0 or spot <= 0 or strike <= 0:
        return 0.0

    z = (math.log(strike) - math.log(spot) - drift) / sigma
    surv_z = 1.0 - _norm_cdf(z)
    if surv_z <= 1e-9:
        return 0.0

    e_s_given_above = spot * math.exp(drift + 0.5 * sigma * sigma) * (1.0 - _norm_cdf(z - sigma)) / surv_z
    avg_intrinsic = e_s_given_above - strike
    return max(0.0, avg_intrinsic)


def _pop_below(spot: float, strike: float, sigma: float, drift: float = 0.0) -> float:
    """P(S_T < strike) under lognormal."""
    if sigma <= 0:
        return 0.0
    z = (math.log(strike) - math.log(spot) - drift) / sigma
    return _norm_cdf(z)


def _pop_above(spot: float, strike: float, sigma: float, drift: float = 0.0) -> float:
    return 1.0 - _pop_below(spot, strike, sigma, drift)


def compute(
    side: str,
    spot: float,
    strike: float,
    iv_annual: float,
    dte: int,
    debit: float,
    drift_per_day: float = 0.0,
    iv_haircut: float = 0.0,
) -> dict:
    if side not in ("call", "put"):
        return {"error": "side must be 'call' or 'put'"}
    if spot <= 0 or strike <= 0 or iv_annual <= 0 or dte <= 0 or debit <= 0:
        return {"error": "spot, strike, iv, dte, debit must all be > 0"}
    if not (0.0 <= iv_haircut < 1.0):
        return {"error": "iv-haircut must be in [0, 1)"}

    iv_effective = iv_annual * (1.0 - iv_haircut)
    t_years = dte / 252.0
    sigma_period = iv_effective * math.sqrt(t_years)
    drift_period = drift_per_day * dte  # already in log-return space if user passes it that way

    # Risk-neutral case: drift = 0
    # Drift-adjusted case: drift = drift_period

    if side == "put":
        breakeven = strike - debit
        # POP_ITM and P(profit) under risk-neutral
        pop_itm_rn = _pop_below(spot, strike, sigma_period, drift=0.0)
        p_profit_rn = _pop_below(spot, breakeven, sigma_period, drift=0.0)
        avg_intrinsic_rn = _truncated_lognormal_mean_below(spot, strike, sigma_period, drift=0.0)
        # Drift-adjusted
        pop_itm_drift = _pop_below(spot, strike, sigma_period, drift=drift_period)
        p_profit_drift = _pop_below(spot, breakeven, sigma_period, drift=drift_period)
        avg_intrinsic_drift = _truncated_lognormal_mean_below(spot, strike, sigma_period, drift=drift_period)
    else:  # call
        breakeven = strike + debit
        pop_itm_rn = _pop_above(spot, strike, sigma_period, drift=0.0)
        p_profit_rn = _pop_above(spot, breakeven, sigma_period, drift=0.0)
        avg_intrinsic_rn = _truncated_lognormal_mean_above(spot, strike, sigma_period, drift=0.0)
        pop_itm_drift = _pop_above(spot, strike, sigma_period, drift=drift_period)
        p_profit_drift = _pop_above(spot, breakeven, sigma_period, drift=drift_period)
        avg_intrinsic_drift = _truncated_lognormal_mean_above(spot, strike, sigma_period, drift=drift_period)

    # CORRECT EV formula: EV = POP_ITM * avg_intrinsic_if_ITM - debit
    ev_rn = pop_itm_rn * avg_intrinsic_rn - debit
    ev_drift = pop_itm_drift * avg_intrinsic_drift - debit

    # Kelly inputs: use P(profit), not POP_ITM
    # avg_payoff_when_winning = E[intrinsic | intrinsic > debit] - debit
    # Approximation: use avg_intrinsic_if_ITM as a proxy (slight overstatement of conditional mean
    # given S already past breakeven; for tight strikes this bias is small)
    def _kelly(p: float, avg_intrinsic: float, debit: float) -> dict:
        if p <= 0 or avg_intrinsic <= debit:
            return {"kelly_full": 0.0, "kelly_quarter": 0.0, "verdict": "NO_EDGE"}
        b = (avg_intrinsic - debit) / debit
        q = 1.0 - p
        k_full = p - q / b if b > 0 else 0.0
        return {
            "p_profit": round(p, 4),
            "reward_risk_when_winning": round(b, 4),
            "kelly_full": round(k_full, 4),
            "kelly_quarter": round(max(0.0, k_full / 4.0), 4),
            "verdict": "POSITIVE_EDGE" if k_full > 0 else "NEGATIVE_EDGE",
        }

    return {
        "inputs": {
            "side": side,
            "spot": round(spot, 4),
            "strike": round(strike, 4),
            "iv_annual_input": round(iv_annual, 4),
            "iv_haircut": round(iv_haircut, 4),
            "iv_effective": round(iv_effective, 4),
            "dte": dte,
            "debit": round(debit, 4),
            "drift_per_day": round(drift_per_day, 6),
            "sigma_period": round(sigma_period, 4),
            "drift_period_log": round(drift_period, 6),
            "breakeven": round(breakeven, 4),
        },
        "risk_neutral": {
            "pop_itm": round(pop_itm_rn, 4),
            "p_profit": round(p_profit_rn, 4),
            "avg_intrinsic_if_ITM": round(avg_intrinsic_rn, 4),
            "ev_per_share": round(ev_rn, 4),
            "ev_per_contract": round(ev_rn * 100, 2),
            "verdict": "POSITIVE_EV" if ev_rn > 0 else ("BREAKEVEN_EV" if ev_rn == 0 else "NEGATIVE_EV"),
            "kelly": _kelly(p_profit_rn, avg_intrinsic_rn, debit),
        },
        "drift_adjusted": {
            "pop_itm": round(pop_itm_drift, 4),
            "p_profit": round(p_profit_drift, 4),
            "avg_intrinsic_if_ITM": round(avg_intrinsic_drift, 4),
            "ev_per_share": round(ev_drift, 4),
            "ev_per_contract": round(ev_drift * 100, 2),
            "verdict": "POSITIVE_EV" if ev_drift > 0 else ("BREAKEVEN_EV" if ev_drift == 0 else "NEGATIVE_EV"),
            "kelly": _kelly(p_profit_drift, avg_intrinsic_drift, debit),
        },
        "notes": [
            "EV formula: POP_ITM * avg_intrinsic_if_ITM - debit. Trailing term is debit (paid in every scenario), not (1-POP)*debit.",
            "Kelly inputs use P(profit) = P(S crosses breakeven), not POP_ITM. These differ by ~debit/sigma in SD units.",
            "avg_intrinsic uses lognormal model with optional drift. iv_haircut applies a multiplicative reduction to IV (e.g. 0.30 = use 70% of supplied IV).",
            "drift-adjusted scenario stacks two debatable assumptions (drift persists AND distribution is lognormal-with-drift). Treat as upper-bound EV, not central.",
            "If both risk_neutral and drift_adjusted return NEGATIVE_EV, do not slate.",
        ],
        "computed_at_utc": datetime.now(timezone.utc).isoformat(),
    }


def _autofetch(ticker: str, expiry: str, strike: float) -> dict:
    """Pull spot, IV30 (ATM straddle implied), and DTE from yfinance."""
    import yfinance as yf
    from curl_cffi import requests as curl_requests

    session = curl_requests.Session(impersonate="chrome")
    tkr = yf.Ticker(ticker, session=session)

    info = tkr.history(period="1d")
    if info.empty:
        return {"error": f"could not fetch spot for {ticker}"}
    spot = float(info["Close"].iloc[-1])

    try:
        chain = tkr.option_chain(expiry)
    except Exception as e:
        return {"error": f"could not fetch chain for {ticker} {expiry}: {e}"}

    # ATM IV: average call+put at the closest-to-ATM strike
    calls = chain.calls
    puts = chain.puts
    if calls.empty or puts.empty:
        return {"error": f"empty chain for {ticker} {expiry}"}

    closest_call = calls.iloc[(calls["strike"] - spot).abs().argsort().iloc[0]]
    closest_put = puts.iloc[(puts["strike"] - spot).abs().argsort().iloc[0]]
    iv_atm = (float(closest_call["impliedVolatility"]) + float(closest_put["impliedVolatility"])) / 2.0

    # DTE
    expiry_dt = datetime.strptime(expiry, "%Y-%m-%d").replace(tzinfo=timezone.utc)
    today = datetime.now(timezone.utc)
    dte = max(1, (expiry_dt - today).days)

    return {"spot": spot, "iv_annual": iv_atm, "dte": dte}


def main() -> None:
    p = argparse.ArgumentParser()
    p.add_argument("--side", required=True, choices=["call", "put"])
    p.add_argument("--spot", type=float)
    p.add_argument("--strike", type=float, required=True)
    p.add_argument("--iv", type=float, help="annualized IV (e.g. 0.30)")
    p.add_argument("--dte", type=int)
    p.add_argument("--debit", type=float, required=True)
    p.add_argument("--ticker", help="autofetch spot/IV/DTE from yfinance")
    p.add_argument("--expiry", help="YYYY-MM-DD, required when --ticker is used")
    p.add_argument("--drift-pct-per-day", type=float, default=0.0,
                   help="period drift (e.g. -0.0077 for -0.77 pct/day from regime_classify slope)")
    p.add_argument("--iv-haircut", type=float, default=0.0,
                   help="multiplicative haircut to IV (e.g. 0.30 = use 70 pct of supplied IV)")
    args = p.parse_args()

    if args.ticker:
        if not args.expiry:
            print(json.dumps({"error": "--expiry required with --ticker"}))
            sys.exit(1)
        fetched = _autofetch(args.ticker, args.expiry, args.strike)
        if "error" in fetched:
            print(json.dumps(fetched))
            sys.exit(1)
        spot = fetched["spot"]
        iv_annual = fetched["iv_annual"]
        dte = fetched["dte"]
    else:
        if args.spot is None or args.iv is None or args.dte is None:
            print(json.dumps({"error": "must supply --spot, --iv, --dte (or use --ticker --expiry)"}))
            sys.exit(1)
        spot = args.spot
        iv_annual = args.iv
        dte = args.dte

    out = compute(
        side=args.side,
        spot=spot,
        strike=args.strike,
        iv_annual=iv_annual,
        dte=dte,
        debit=args.debit,
        drift_per_day=args.drift_pct_per_day,
        iv_haircut=args.iv_haircut,
    )
    print(json.dumps(out, indent=2))


if __name__ == "__main__":
    main()
