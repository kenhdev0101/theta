#!/usr/bin/env python3
"""Market breadth engine: participation first, index price second."""
from __future__ import annotations

import argparse
import json

from common import close_series, fetch_history, now_utc, parse_csv, pct_return, safe_round

DEFAULT_UNIVERSE = [
    "AAPL", "MSFT", "NVDA", "AMZN", "META", "GOOGL", "AVGO", "TSLA", "COST", "NFLX",
    "AMD", "CRM", "ADBE", "INTC", "QCOM", "TXN", "AMAT", "LRCX", "MU", "SMCI",
    "JPM", "BAC", "GS", "XOM", "CVX", "LLY", "UNH", "CAT", "GE", "HD",
]


def symbol_state(frame) -> dict:
    close = close_series(frame)
    if len(close) < 60:
        return {"error": f"insufficient bars: {len(close)}"}
    ma20 = close.rolling(20).mean().iloc[-1]
    ma50 = close.rolling(50).mean().iloc[-1]
    high_20 = close.iloc[-21:-1].max()
    high_55 = close.iloc[-56:-1].max()
    ret_1 = pct_return(close, 1)
    return {
        "advancing_1d": ret_1 is not None and ret_1 > 0,
        "above_ma20": bool(close.iloc[-1] > ma20),
        "above_ma50": bool(close.iloc[-1] > ma50),
        "new_high_20d": bool(close.iloc[-1] > high_20),
        "new_high_55d": bool(close.iloc[-1] > high_55),
        "return_1d": safe_round(ret_1, 4),
    }


def main() -> None:
    p = argparse.ArgumentParser()
    p.add_argument("--universe", help="comma-separated tickers")
    p.add_argument("--index", default="SPY")
    p.add_argument("--period", default="6mo")
    args = p.parse_args()

    universe = parse_csv(args.universe, DEFAULT_UNIVERSE)
    tickers = sorted(set(universe + [args.index.upper()]))
    history = fetch_history(tickers, period=args.period)
    states = {ticker: symbol_state(history[ticker]) for ticker in universe if ticker in history}
    usable = [s for s in states.values() if "error" not in s]
    n = len(usable)

    def frac(key: str) -> float | None:
        return None if n == 0 else sum(1 for s in usable if s[key]) / n

    index_close = close_series(history[args.index.upper()])
    index_ret_1d = pct_return(index_close, 1)
    adv = frac("advancing_1d")
    if index_ret_1d and index_ret_1d > 0 and adv is not None and adv < 0.45:
        regime = "index_up_breadth_weak"
    elif abs(index_ret_1d or 0) < 0.002 and adv is not None and adv > 0.60:
        regime = "index_flat_breadth_exploding"
    elif index_ret_1d and index_ret_1d < 0 and adv is not None and adv > 0.50:
        regime = "index_down_rotation_under_surface"
    else:
        regime = "aligned_or_mixed"

    print(json.dumps({
        "computed_at_utc": now_utc(),
        "index": args.index.upper(),
        "index_return_1d": safe_round(index_ret_1d, 4),
        "universe_count": n,
        "breadth": {
            "advancing_pct": safe_round(adv, 4),
            "above_ma20_pct": safe_round(frac("above_ma20"), 4),
            "above_ma50_pct": safe_round(frac("above_ma50"), 4),
            "new_high_20d_pct": safe_round(frac("new_high_20d"), 4),
            "new_high_55d_pct": safe_round(frac("new_high_55d"), 4),
        },
        "breadth_regime": regime,
        "symbol_states": states,
    }, indent=2))


if __name__ == "__main__":
    main()

