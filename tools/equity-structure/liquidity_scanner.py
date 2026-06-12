#!/usr/bin/env python3
"""Relative liquidity scanner: dollar volume, spread, ATR, and capacity tier."""
from __future__ import annotations

import argparse
import json

from common import atr, fetch_history, latest_bar, now_utc, parse_csv, quote_snapshot, safe_round


def classify_liquidity(avg_dollar_volume: float, spread_bps: float | None, atr_pct: float) -> str:
    spread_ok = spread_bps is None or spread_bps <= 8
    if avg_dollar_volume >= 1_000_000_000 and spread_ok and atr_pct >= 0.01:
        return "institutional_liquid"
    if avg_dollar_volume >= 100_000_000 and (spread_bps is None or spread_bps <= 20):
        return "tradeable"
    if avg_dollar_volume >= 25_000_000:
        return "thin_tradeable"
    return "avoid_for_options_execution"


def scan(symbol: str, frame) -> dict:
    clean = frame.dropna(how="all")
    bar = latest_bar(clean)
    close = float(clean["Close"].iloc[-1])
    avg_vol_20 = float(clean["Volume"].iloc[-20:].mean())
    avg_dollar_volume = avg_vol_20 * close
    atr_latest = float(atr(clean).iloc[-1])
    atr_pct = atr_latest / close
    quote = quote_snapshot(symbol)
    tier = classify_liquidity(avg_dollar_volume, quote.get("quoted_spread_bps"), atr_pct)
    return {
        "symbol": symbol,
        "last_close": safe_round(close, 4),
        "avg_volume_20d": int(avg_vol_20),
        "avg_dollar_volume_20d": int(avg_dollar_volume),
        "atr_14": safe_round(atr_latest, 4),
        "atr_pct": safe_round(atr_pct, 4),
        "quote": quote,
        "liquidity_tier": tier,
        "execution_note": "prefer limit orders; widen patience if quoted_spread_bps > 10 or tier is thin_tradeable",
    }


def main() -> None:
    p = argparse.ArgumentParser()
    p.add_argument("--tickers", default="AAPL,NVDA,QQQ,SOXX,SPY")
    p.add_argument("--period", default="3mo")
    args = p.parse_args()
    tickers = parse_csv(args.tickers)
    history = fetch_history(tickers, period=args.period)
    print(json.dumps({
        "computed_at_utc": now_utc(),
        "results": [scan(ticker, history[ticker]) for ticker in tickers if ticker in history],
    }, indent=2))


if __name__ == "__main__":
    main()

