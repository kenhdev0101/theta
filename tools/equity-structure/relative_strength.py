#!/usr/bin/env python3
"""Relative performance scanner: asset vs benchmark, not absolute price."""
from __future__ import annotations

import argparse
import json

import numpy as np

from common import (
    classify_strength,
    close_series,
    fetch_history,
    linreg_slope,
    now_utc,
    pct_return,
    safe_round,
)


def analyze_pair(asset: str, benchmark: str, history: dict) -> dict:
    a_close = close_series(history[asset])
    b_close = close_series(history[benchmark])
    joined = a_close.rename("asset").to_frame().join(b_close.rename("benchmark"), how="inner")
    if len(joined) < 30:
        return {"asset": asset, "benchmark": benchmark, "error": f"insufficient joined bars: {len(joined)}"}

    rs_line = np.log(joined["asset"] / joined["benchmark"])
    slope_20 = linreg_slope(rs_line, 20)
    slope_5 = linreg_slope(rs_line, 5)
    prior_5 = linreg_slope(rs_line.iloc[:-5], 5) if len(rs_line) >= 15 else None
    accel = (slope_5 - prior_5) if slope_5 is not None and prior_5 is not None else None

    rel_5 = pct_return(joined["asset"], 5) - pct_return(joined["benchmark"], 5)
    rel_20 = pct_return(joined["asset"], 20) - pct_return(joined["benchmark"], 20)
    rel_60 = pct_return(joined["asset"], 60) - pct_return(joined["benchmark"], 60) if len(joined) > 61 else None
    state, phase = classify_strength(rel_20, slope_20, accel)

    return {
        "asset": asset,
        "benchmark": benchmark,
        "bars": len(joined),
        "relative_returns": {
            "5d": safe_round(rel_5, 4),
            "20d": safe_round(rel_20, 4),
            "60d": safe_round(rel_60, 4),
        },
        "rs_line": {
            "slope_20d_log_ratio_per_day": safe_round(slope_20, 6),
            "slope_5d_log_ratio_per_day": safe_round(slope_5, 6),
            "acceleration_vs_prior_5d": safe_round(accel, 6),
        },
        "state": state,
        "phase": phase,
    }


def main() -> None:
    p = argparse.ArgumentParser()
    p.add_argument("--pairs", default="AAPL:QQQ,NVDA:SOXX", help="comma list like AAPL:QQQ,NVDA:SOXX")
    p.add_argument("--period", default="6mo")
    args = p.parse_args()

    pairs = []
    tickers = set()
    for raw in args.pairs.split(","):
        if ":" not in raw:
            continue
        asset, benchmark = [part.strip().upper() for part in raw.split(":", 1)]
        pairs.append((asset, benchmark))
        tickers.update([asset, benchmark])

    history = fetch_history(tickers, period=args.period)
    out = {
        "computed_at_utc": now_utc(),
        "period": args.period,
        "pairs": [analyze_pair(asset, benchmark, history) for asset, benchmark in pairs if asset in history and benchmark in history],
    }
    print(json.dumps(out, indent=2))


if __name__ == "__main__":
    main()

