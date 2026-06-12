#!/usr/bin/env python3
"""Intraday deterministic loop scanner for bounded equity execution.

This script is intentionally execution-agnostic: it computes features and
classifies structure, but Robinhood review/place remains the execution layer.
"""
from __future__ import annotations

import argparse
import json
import math
from datetime import datetime, time, timezone
from zoneinfo import ZoneInfo

import numpy as np
import pandas as pd

from common import fetch_history, now_utc, parse_csv, safe_round

EASTERN = ZoneInfo("America/New_York")
MARKET_CLOSE = time(16, 0)


def minutes_until_close(now: datetime | None = None) -> int:
    current = now or datetime.now(EASTERN)
    close_dt = datetime.combine(current.date(), MARKET_CLOSE, tzinfo=EASTERN)
    return max(0, int((close_dt - current).total_seconds() // 60))


def session_frame(frame: pd.DataFrame) -> pd.DataFrame:
    data = frame.dropna(how="all").copy()
    if data.empty:
        return data
    if data.index.tz is None:
        data.index = data.index.tz_localize("UTC")
    data.index = data.index.tz_convert(EASTERN)
    latest_day = data.index[-1].date()
    return data[data.index.date == latest_day]


def vwap(frame: pd.DataFrame) -> float | None:
    typical = (frame["High"].astype(float) + frame["Low"].astype(float) + frame["Close"].astype(float)) / 3.0
    volume = frame["Volume"].astype(float)
    denom = float(volume.sum())
    if denom <= 0:
        return None
    return float((typical * volume).sum() / denom)


def realized_volatility(frame: pd.DataFrame) -> float | None:
    close = frame["Close"].dropna().astype(float)
    returns = np.log(close / close.shift(1)).dropna()
    if len(returns) < 5:
        return None
    return float(returns.std() * math.sqrt(390))


def trend_slope(frame: pd.DataFrame, window: int = 20) -> float | None:
    close = frame["Close"].dropna().astype(float)
    if len(close) < window:
        return None
    y = np.log(close.iloc[-window:].values)
    x = np.arange(len(y))
    slope, _ = np.polyfit(x, y, 1)
    return float(slope)


def volume_expansion(frame: pd.DataFrame, window: int = 20) -> float | None:
    volume = frame["Volume"].dropna().astype(float)
    if len(volume) < window + 1:
        return None
    nonzero = volume[volume > 0]
    if len(nonzero) < window + 1:
        return None
    current = float(nonzero.iloc[-1])
    baseline = float(nonzero.iloc[-window - 1:-1].mean())
    if baseline <= 0:
        return None
    return current / baseline


def classify(features: dict) -> tuple[str, str, float]:
    vwap_dist = features.get("vwap_distance_pct")
    high_dist = features.get("distance_from_session_high_pct")
    low_dist = features.get("distance_from_session_low_pct")
    vol_exp = features.get("volume_expansion")
    slope = features.get("trend_slope_20m_log")
    spread = features.get("spread_pct")
    rv = features.get("realized_volatility_intraday")

    if spread is not None and spread > 0.0015:
        return "LIQUIDITY_VACUUM", "no_trade", 0.2
    if vwap_dist is None or slope is None or vol_exp is None:
        return "NO_EDGE", "no_trade", 0.1
    if high_dist is not None and high_dist > -0.0015 and slope > 0.0005 and vol_exp > 1.5:
        return "DISLOCATION_HOLD", "hold_or_buy_pullback", 0.62
    if low_dist is not None and low_dist < 0.002 and slope < -0.0005 and vol_exp > 1.5:
        return "FAILED_BREAKOUT", "reduce_or_exit", 0.58
    if abs(vwap_dist) < 0.0015 and abs(slope) < 0.00025 and vol_exp < 0.8:
        return "COMPRESSION_COIL", "hold", 0.48
    if vwap_dist > 0.002 and slope > 0:
        return "ACCEPTANCE_SHELF", "hold_or_buy_limit", 0.55
    if vwap_dist < -0.002 and slope > 0 and rv is not None and rv > 0.15:
        return "RECLAIM", "watch_reclaim", 0.5
    if abs(vwap_dist) > 0.01 and vol_exp > 2.0:
        return "PARABOLIC_EXPANSION", "reduce_or_no_chase", 0.54
    return "NO_EDGE", "no_trade", 0.25


def scan_symbol(symbol: str, frame: pd.DataFrame, quote: dict | None, close_minutes: int) -> dict:
    session = session_frame(frame)
    if session.empty:
        return {"symbol": symbol, "error": "no intraday bars"}

    last = float(session["Close"].iloc[-1])
    day_high = float(session["High"].max())
    day_low = float(session["Low"].min())
    computed_vwap = vwap(session)
    bid = quote.get("bid") if quote else None
    ask = quote.get("ask") if quote else None
    spread_pct = None
    if bid is not None and ask is not None and ask >= bid and last > 0:
        spread_pct = (ask - bid) / last

    features = {
        "last": safe_round(last, 4),
        "vwap": safe_round(computed_vwap, 4),
        "vwap_distance_pct": safe_round((last / computed_vwap - 1.0) if computed_vwap else None, 5),
        "spread_pct": safe_round(spread_pct, 6),
        "dollar_volume_session": safe_round(float((session["Close"] * session["Volume"]).sum()), 2),
        "volume_expansion": safe_round(volume_expansion(session), 3),
        "realized_volatility_intraday": safe_round(realized_volatility(session), 4),
        "trend_slope_20m_log": safe_round(trend_slope(session), 6),
        "distance_from_session_high_pct": safe_round(last / day_high - 1.0, 5),
        "distance_from_session_low_pct": safe_round(last / day_low - 1.0, 5),
        "minutes_until_close": close_minutes,
        "bars": int(len(session)),
    }
    structure, action, confidence = classify(features)
    return {
        "symbol": symbol,
        "features": features,
        "structure": structure,
        "action": action,
        "confidence": confidence,
    }


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--tickers", required=True)
    parser.add_argument("--quotes-json", default=None, help="Optional Robinhood quote snapshot keyed by symbol")
    parser.add_argument("--period", default="1d")
    parser.add_argument("--interval", default="1m")
    args = parser.parse_args()

    tickers = parse_csv(args.tickers)
    quotes = json.loads(args.quotes_json) if args.quotes_json else {}
    history = fetch_history(tickers, period=args.period, interval=args.interval)
    close_minutes = minutes_until_close()
    results = [
        scan_symbol(symbol, history[symbol], quotes.get(symbol, {}), close_minutes)
        for symbol in tickers
        if symbol in history
    ]
    print(json.dumps({
        "computed_at_utc": now_utc(),
        "period": args.period,
        "interval": args.interval,
        "results": results,
    }, indent=2))


if __name__ == "__main__":
    main()
