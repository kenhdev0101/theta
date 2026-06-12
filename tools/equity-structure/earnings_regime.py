#!/usr/bin/env python3
"""Earnings timing tool: days until/since earnings when yfinance exposes dates."""
from __future__ import annotations

import argparse
import json
from datetime import datetime, timezone

import pandas as pd
import yfinance as yf

from common import SESSION, now_utc, parse_csv


def extract_earnings_dates(symbol: str) -> dict:
    today = datetime.now(timezone.utc).date()
    out = {"symbol": symbol}
    try:
        t = yf.Ticker(symbol, session=SESSION)
        dates = t.get_earnings_dates(limit=12)
    except Exception as exc:
        out["error"] = f"{type(exc).__name__}: {exc}"
        return out

    if dates is None or len(dates) == 0:
        out["error"] = "no earnings dates returned"
        return out

    idx = pd.to_datetime(dates.index)
    future = sorted([d.date() for d in idx if d.date() >= today])
    past = sorted([d.date() for d in idx if d.date() < today], reverse=True)
    next_date = future[0] if future else None
    prev_date = past[0] if past else None
    days_until = (next_date - today).days if next_date else None
    days_since = (today - prev_date).days if prev_date else None

    if days_until is not None and days_until <= 7:
        regime = "pre_earnings_event_window"
    elif days_since is not None and days_since <= 5:
        regime = "post_earnings_digestion"
    elif days_until is not None and days_until <= 21:
        regime = "earnings_runup_window"
    else:
        regime = "normal"

    out.update({
        "previous_earnings_date": prev_date.isoformat() if prev_date else None,
        "next_earnings_date": next_date.isoformat() if next_date else None,
        "days_since_earnings": days_since,
        "days_until_earnings": days_until,
        "earnings_regime": regime,
        "execution_note": "avoid naked premium into binary event unless event risk is the explicit thesis",
    })
    return out


def main() -> None:
    p = argparse.ArgumentParser()
    p.add_argument("--tickers", default="AAPL,NVDA")
    args = p.parse_args()
    tickers = parse_csv(args.tickers)
    print(json.dumps({
        "computed_at_utc": now_utc(),
        "results": [extract_earnings_dates(ticker) for ticker in tickers],
    }, indent=2))


if __name__ == "__main__":
    main()

