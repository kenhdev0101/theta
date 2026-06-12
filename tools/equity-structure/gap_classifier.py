#!/usr/bin/env python3
"""Gap classifier with size, location, volume participation, and prior structure."""
from __future__ import annotations

import argparse
import json

from common import atr, close_series, fetch_history, now_utc, parse_csv, safe_round


def prior_structure(frame) -> str:
    close = close_series(frame)
    if len(close) < 25:
        return "unknown"
    ret_20 = close.iloc[-2] / close.iloc[-22] - 1.0
    range_10 = (frame["High"].iloc[-11:-1].max() - frame["Low"].iloc[-11:-1].min()) / close.iloc[-2]
    atr_pct = atr(frame).iloc[-2] / close.iloc[-2]
    if abs(ret_20) < 0.03 and range_10 < atr_pct * 8:
        return "coil_or_compression"
    if ret_20 > 0.08:
        return "prior_uptrend"
    if ret_20 < -0.08:
        return "prior_downtrend"
    return "range_or_mixed"


def classify(frame) -> dict:
    clean = frame.dropna(how="all")
    if len(clean) < 30:
        return {"error": f"insufficient bars: {len(clean)}"}

    today = clean.iloc[-1]
    prev = clean.iloc[-2]
    gap = float(today["Open"] - prev["Close"])
    gap_pct = gap / float(prev["Close"])
    atr_series = atr(clean)
    atr_prev = float(atr_series.iloc[-2])
    gap_atr = gap / atr_prev if atr_prev > 0 else None
    avg_vol_20 = float(clean["Volume"].iloc[-21:-1].mean())
    vol_participation = float(today["Volume"] / avg_vol_20) if avg_vol_20 > 0 else None
    prev_high = float(prev["High"])
    prev_low = float(prev["Low"])
    high_20 = float(clean["High"].iloc[-21:-1].max())
    low_20 = float(clean["Low"].iloc[-21:-1].min())

    if today["Open"] > high_20:
        location = "above_20d_range"
    elif today["Open"] < low_20:
        location = "below_20d_range"
    elif today["Open"] > prev_high:
        location = "outside_above_prior_bar"
    elif today["Open"] < prev_low:
        location = "outside_below_prior_bar"
    else:
        location = "inside_prior_range"

    structure = prior_structure(clean)
    abs_gap_atr = abs(gap_atr or 0.0)
    high_volume = (vol_participation or 0.0) >= 1.5
    direction = "up" if gap > 0 else "down"

    if abs_gap_atr >= 0.75 and high_volume and structure in {"coil_or_compression", "range_or_mixed"} and "20d_range" in location:
        gap_type = "breakaway"
    elif abs_gap_atr >= 1.0 and high_volume and structure in {"prior_uptrend", "prior_downtrend"}:
        gap_type = "exhaustion_risk"
    elif abs_gap_atr >= 0.35 and location.startswith("outside") and vol_participation and vol_participation >= 0.8:
        gap_type = "common_continuation"
    else:
        gap_type = "common_gap"

    participation = "confirmed" if high_volume else ("thin" if (vol_participation or 0) < 0.7 else "normal")
    return {
        "gap_direction": direction,
        "gap_size": safe_round(gap, 4),
        "gap_pct": safe_round(gap_pct, 4),
        "gap_atr": safe_round(gap_atr, 3),
        "gap_location": location,
        "previous_structure": structure,
        "volume_participation": {
            "current_volume": int(today["Volume"]),
            "avg_volume_20d": int(avg_vol_20),
            "ratio": safe_round(vol_participation, 2),
            "engine": participation,
        },
        "classification": gap_type,
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
        "period": args.period,
        "results": {ticker: classify(history[ticker]) for ticker in tickers if ticker in history},
    }, indent=2))


if __name__ == "__main__":
    main()

