#!/usr/bin/env python3
"""
expected_move.py — compute the implied 1-SD move from an ATM straddle.

Two modes:
    expected_move.py --underlying 100 --straddle-mid 5.20 --dte 14
    expected_move.py --ticker NVDA --expiry 2026-05-15

Mode A is the manual-track default — operator pastes the straddle mid from
Robinhood. Mode B autofetches from yfinance.

Implied move uses the rule-of-thumb factor 0.85 on the straddle (closer to true
ATM 1-SD than the naive straddle = 1-SD assumption). Also back-solves an
implied annualized vol for cross-checking against iv_rank.py output.
"""
import argparse
import json
import math
import sys
import warnings
from datetime import datetime, timezone

warnings.filterwarnings("ignore")

import yfinance as yf
from curl_cffi import requests as curl_requests

_SESSION = curl_requests.Session(impersonate="chrome")  # Yahoo blocks vanilla requests; impersonate a browser

STRADDLE_TO_1SD = 0.85  # standard market-maker rule-of-thumb


def compute(underlying: float, straddle: float, dte: int) -> dict:
    if underlying <= 0 or straddle <= 0 or dte <= 0:
        return {"error": "underlying, straddle, dte must all be > 0"}

    implied_move_dollars = straddle * STRADDLE_TO_1SD
    implied_move_pct = implied_move_dollars / underlying * 100
    # Brenner-Subrahmanyam: ATM straddle ≈ 0.8 * S * IV * sqrt(T)
    # → IV = (straddle / S) / sqrt(T) / 0.8
    iv_implied = (straddle / underlying) / math.sqrt(dte / 365) / 0.8

    return {
        "underlying": round(underlying, 4),
        "straddle_mid": round(straddle, 4),
        "dte": dte,
        "implied_move_dollars": round(implied_move_dollars, 4),
        "implied_move_pct": round(implied_move_pct, 2),
        "breakeven_up": round(underlying + implied_move_dollars, 4),
        "breakeven_down": round(underlying - implied_move_dollars, 4),
        "iv_annualized_implied": round(iv_implied, 4),
        "method": "straddle_mid * 0.85 (market-maker rule of thumb for 1-SD move)",
    }


def fetch_atm_straddle(ticker: str, expiry: str) -> dict:
    t = yf.Ticker(ticker, session=_SESSION)
    hist = t.history(period="5d", interval="1d")
    if hist.empty:
        return {"error": "yfinance returned no price history"}
    spot = float(hist["Close"].iloc[-1])

    if expiry not in t.options:
        return {"error": f"expiry {expiry} not in available expirations: {list(t.options)[:5]}..."}

    chain = t.option_chain(expiry)
    call_idx = (chain.calls["strike"] - spot).abs().idxmin()
    put_idx = (chain.puts["strike"] - spot).abs().idxmin()
    call_mid = float((chain.calls.loc[call_idx, "bid"] + chain.calls.loc[call_idx, "ask"]) / 2)
    put_mid = float((chain.puts.loc[put_idx, "bid"] + chain.puts.loc[put_idx, "ask"]) / 2)
    straddle = call_mid + put_mid
    strike = float(chain.calls.loc[call_idx, "strike"])

    today = datetime.now(timezone.utc).date()
    exp_date = datetime.strptime(expiry, "%Y-%m-%d").date()
    dte = (exp_date - today).days

    return {
        "underlying": spot,
        "straddle": straddle,
        "dte": dte,
        "atm_strike": strike,
        "call_mid": round(call_mid, 4),
        "put_mid": round(put_mid, 4),
    }


def main() -> None:
    p = argparse.ArgumentParser()
    p.add_argument("--underlying", type=float)
    p.add_argument("--straddle-mid", type=float)
    p.add_argument("--dte", type=int)
    p.add_argument("--ticker")
    p.add_argument("--expiry", help="YYYY-MM-DD; required with --ticker")
    args = p.parse_args()

    if args.ticker:
        if not args.expiry:
            print(json.dumps({"error": "--expiry required with --ticker"}))
            sys.exit(1)
        fetched = fetch_atm_straddle(args.ticker, args.expiry)
        if "error" in fetched:
            print(json.dumps(fetched, indent=2))
            sys.exit(1)
        out = compute(fetched["underlying"], fetched["straddle"], fetched["dte"])
        out["ticker"] = args.ticker.upper()
        out["expiry"] = args.expiry
        out["atm_strike"] = fetched["atm_strike"]
        out["call_mid"] = fetched["call_mid"]
        out["put_mid"] = fetched["put_mid"]
        out["source"] = "yfinance option chain (ATM bid/ask midpoint)"
    else:
        if args.underlying is None or args.straddle_mid is None or args.dte is None:
            print(json.dumps({"error": "either --ticker+--expiry or --underlying+--straddle-mid+--dte required"}))
            sys.exit(1)
        out = compute(args.underlying, args.straddle_mid, args.dte)
        out["source"] = "operator-supplied"

    print(json.dumps(out, indent=2))


if __name__ == "__main__":
    main()
