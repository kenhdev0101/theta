#!/usr/bin/env python3
"""Trend geometry primitives: acceptance shell, compression, coil, parabolic expansion."""
from __future__ import annotations

import argparse
import json

from common import atr, close_series, fetch_history, linreg_slope, now_utc, parse_csv, percentile_rank, safe_round


def geometry(symbol: str, frame) -> dict:
    clean = frame.dropna(how="all")
    close = close_series(clean)
    if len(close) < 60:
        return {"symbol": symbol, "error": f"insufficient bars: {len(close)}"}

    atr_series = atr(clean)
    atr_pct = atr_series / close
    atr_pctile = percentile_rank(atr_pct.iloc[-80:], float(atr_pct.iloc[-1]))
    ma20 = close.rolling(20).mean()
    ma50 = close.rolling(50).mean()
    slope20 = linreg_slope(close.pct_change().fillna(0).cumsum(), 20)
    dist_ma20_atr = (close.iloc[-1] - ma20.iloc[-1]) / atr_series.iloc[-1]
    bb_mid = ma20
    bb_std = close.rolling(20).std()
    bb_width = (4 * bb_std) / bb_mid
    bb_pctile = percentile_rank(bb_width.iloc[-100:], float(bb_width.iloc[-1]))
    inside_days = 0
    for i in range(1, min(8, len(clean))):
        if clean["High"].iloc[-i] <= clean["High"].iloc[-i - 1] and clean["Low"].iloc[-i] >= clean["Low"].iloc[-i - 1]:
            inside_days += 1
        else:
            break

    compression = (atr_pctile is not None and atr_pctile <= 0.25) or (bb_pctile is not None and bb_pctile <= 0.25)
    coil = compression and inside_days >= 1 and abs(dist_ma20_atr) <= 1.2
    parabolic = abs(dist_ma20_atr) >= 2.5 and atr_pctile is not None and atr_pctile >= 0.75
    acceptance = "above_acceptance" if close.iloc[-1] > ma20.iloc[-1] > ma50.iloc[-1] else (
        "below_acceptance" if close.iloc[-1] < ma20.iloc[-1] < ma50.iloc[-1] else "inside_acceptance_shell"
    )

    if parabolic:
        posture = "expansion_risk_manage_chase_only_on_pullback"
    elif coil:
        posture = "coil_watch_for_breakaway_gap_or_volume_unlock"
    elif acceptance != "inside_acceptance_shell" and slope20 and slope20 > 0:
        posture = "trend_continuation_long_bias"
    elif acceptance != "inside_acceptance_shell" and slope20 and slope20 < 0:
        posture = "trend_continuation_short_bias"
    else:
        posture = "mixed_no_clean_geometry"

    return {
        "symbol": symbol,
        "spot": safe_round(float(close.iloc[-1]), 4),
        "acceptance_shell": acceptance,
        "compression": {
            "active": bool(compression),
            "atr_pctile": safe_round(atr_pctile, 3),
            "bb_width_pctile": safe_round(bb_pctile, 3),
        },
        "coil": {"active": bool(coil), "inside_days": inside_days},
        "parabolic_expansion": {
            "active": bool(parabolic),
            "distance_from_ma20_atr": safe_round(float(dist_ma20_atr), 2),
        },
        "trend": {
            "ma20": safe_round(float(ma20.iloc[-1]), 4),
            "ma50": safe_round(float(ma50.iloc[-1]), 4),
            "slope20_cum_return_per_day": safe_round(slope20, 6),
        },
        "execution_posture": posture,
    }


def main() -> None:
    p = argparse.ArgumentParser()
    p.add_argument("--tickers", default="AAPL,NVDA,QQQ,SOXX,SPY")
    p.add_argument("--period", default="6mo")
    args = p.parse_args()
    tickers = parse_csv(args.tickers)
    history = fetch_history(tickers, period=args.period)
    print(json.dumps({
        "computed_at_utc": now_utc(),
        "results": [geometry(ticker, history[ticker]) for ticker in tickers if ticker in history],
    }, indent=2))


if __name__ == "__main__":
    main()

