#!/usr/bin/env python3
"""
iv_rank.py — pull current ~30-DTE IV from yfinance and rank against trailing HV30.

Usage:
    iv_rank.py --ticker NVDA [--lookback 252]

Outputs JSON with current IV30 (from option chain), HV30 (from OHLC), and an
HV-rank percentile that serves as a regime proxy. True IV-rank requires a paid
historical IV feed; this is the manual-track approximation.
"""
import argparse
import json
import sys
import warnings
from datetime import datetime, timezone

warnings.filterwarnings("ignore")

import numpy as np
import pandas as pd
import yfinance as yf
from curl_cffi import requests as curl_requests

_SESSION = curl_requests.Session(impersonate="chrome")  # Yahoo blocks vanilla requests; impersonate a browser


def hv_annualized(close: pd.Series, window: int = 30) -> pd.Series:
    log_returns = np.log(close / close.shift(1))
    return log_returns.rolling(window).std() * np.sqrt(252)


def nearest_expiry(expirations: list[str], target_dte: int = 30) -> tuple[str, int]:
    today = datetime.now(timezone.utc).date()
    best = None
    for exp_str in expirations:
        exp_date = datetime.strptime(exp_str, "%Y-%m-%d").date()
        dte = (exp_date - today).days
        if dte <= 0:
            continue
        if best is None or abs(dte - target_dte) < abs(best[1] - target_dte):
            best = (exp_str, dte)
    return best if best else ("", 0)


def atm_iv(chain_calls: pd.DataFrame, chain_puts: pd.DataFrame, spot: float) -> float:
    """Average of nearest-strike call IV and put IV."""
    call_idx = (chain_calls["strike"] - spot).abs().idxmin()
    put_idx = (chain_puts["strike"] - spot).abs().idxmin()
    call_iv = chain_calls.loc[call_idx, "impliedVolatility"]
    put_iv = chain_puts.loc[put_idx, "impliedVolatility"]
    return float((call_iv + put_iv) / 2)


def main() -> None:
    p = argparse.ArgumentParser()
    p.add_argument("--ticker", required=True)
    p.add_argument("--lookback", type=int, default=252)
    p.add_argument("--target-dte", type=int, default=30)
    args = p.parse_args()

    out: dict = {"ticker": args.ticker.upper(), "as_of": datetime.now(timezone.utc).isoformat(), "warnings": []}

    try:
        t = yf.Ticker(args.ticker, session=_SESSION)
        hist = t.history(period=f"{args.lookback + 60}d", interval="1d")
        if hist.empty:
            out["error"] = "yfinance returned no price history"
            print(json.dumps(out, indent=2))
            sys.exit(1)

        spot = float(hist["Close"].iloc[-1])
        out["spot"] = round(spot, 4)

        hv30 = hv_annualized(hist["Close"], window=30).dropna()
        if len(hv30) < args.lookback:
            out["warnings"].append(f"HV30 series only {len(hv30)} days; lookback truncated")
        hv30_window = hv30.iloc[-args.lookback:]
        hv30_now = float(hv30_window.iloc[-1])
        hv_rank_pct = float((hv30_window <= hv30_now).sum() / len(hv30_window) * 100)

        out["hv30_current"] = round(hv30_now, 4)
        out["hv30_lookback_days"] = int(len(hv30_window))
        out["hv_rank_pct"] = round(hv_rank_pct, 1)

        expirations = list(t.options)
        if not expirations:
            out["warnings"].append("no option expirations available from yfinance")
            out["iv30_current"] = None
            out["iv_hv_premium_pct"] = None
        else:
            exp_str, dte = nearest_expiry(expirations, args.target_dte)
            chain = t.option_chain(exp_str)
            iv_atm = atm_iv(chain.calls, chain.puts, spot)
            out["iv30_current"] = round(iv_atm, 4)
            out["iv30_source"] = f"yfinance ATM avg(call+put), expiry={exp_str}, dte={dte}"
            out["iv_hv_premium"] = round(iv_atm - hv30_now, 4)
            out["iv_hv_premium_pct"] = round((iv_atm / hv30_now - 1) * 100, 1) if hv30_now > 0 else None

        if hv_rank_pct >= 60:
            out["regime_label"] = "high_vol"
            out["structure_hint"] = "favor short premium (iron condors, credit spreads, short strangles)"
        elif hv_rank_pct <= 30:
            out["regime_label"] = "low_vol"
            out["structure_hint"] = "favor long premium (debit spreads, long calls/puts)"
        else:
            out["regime_label"] = "mid_vol"
            out["structure_hint"] = "no strong vol-regime tilt; let directional/event read drive structure"

        print(json.dumps(out, indent=2))
    except Exception as e:
        out["error"] = f"{type(e).__name__}: {e}"
        print(json.dumps(out, indent=2))
        sys.exit(1)


if __name__ == "__main__":
    main()
