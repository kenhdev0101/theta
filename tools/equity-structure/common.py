#!/usr/bin/env python3
"""Shared primitives for the equity-structure scanner scripts."""
from __future__ import annotations

import json
import math
from datetime import datetime, timezone
from typing import Iterable

import numpy as np
import pandas as pd
import yfinance as yf
from curl_cffi import requests as curl_requests

SESSION = curl_requests.Session(impersonate="chrome")


def now_utc() -> str:
    return datetime.now(timezone.utc).isoformat()


def fail(message: str) -> None:
    print(json.dumps({"error": message, "computed_at_utc": now_utc()}, indent=2))
    raise SystemExit(1)


def parse_csv(value: str | None, default: list[str] | None = None) -> list[str]:
    if not value:
        return default or []
    return [part.strip().upper() for part in value.split(",") if part.strip()]


def fetch_history(tickers: Iterable[str], period: str = "6mo", interval: str = "1d") -> dict[str, pd.DataFrame]:
    symbols = sorted({t.upper() for t in tickers if t})
    if not symbols:
        fail("no tickers supplied")

    raw = yf.download(
        " ".join(symbols),
        period=period,
        interval=interval,
        group_by="ticker",
        auto_adjust=False,
        progress=False,
        threads=True,
        session=SESSION,
    )
    out: dict[str, pd.DataFrame] = {}
    if raw.empty:
        fail(f"yfinance returned no history for {symbols}")

    for symbol in symbols:
        if isinstance(raw.columns, pd.MultiIndex):
            if symbol not in raw.columns.get_level_values(0):
                continue
            frame = raw[symbol].copy()
        else:
            frame = raw.copy()
        frame = frame.dropna(how="all")
        if not frame.empty:
            out[symbol] = frame
    return out


def close_series(frame: pd.DataFrame) -> pd.Series:
    col = "Adj Close" if "Adj Close" in frame.columns else "Close"
    return frame[col].dropna().astype(float)


def latest_bar(frame: pd.DataFrame) -> dict:
    row = frame.dropna(how="all").iloc[-1]
    return {k.lower().replace(" ", "_"): float(v) for k, v in row.items() if pd.notna(v)}


def pct_return(close: pd.Series, days: int) -> float | None:
    if len(close) <= days:
        return None
    return float(close.iloc[-1] / close.iloc[-days - 1] - 1.0)


def linreg_slope(values: pd.Series, window: int) -> float | None:
    values = values.dropna().astype(float)
    if len(values) < window:
        return None
    y = values.iloc[-window:].values
    x = np.arange(len(y))
    slope, _ = np.polyfit(x, y, 1)
    return float(slope)


def atr(frame: pd.DataFrame, period: int = 14) -> pd.Series:
    high = frame["High"].astype(float)
    low = frame["Low"].astype(float)
    close = frame["Close"].astype(float)
    tr = pd.concat(
        [
            high - low,
            (high - close.shift(1)).abs(),
            (low - close.shift(1)).abs(),
        ],
        axis=1,
    ).max(axis=1)
    return tr.ewm(alpha=1 / period, adjust=False).mean()


def percentile_rank(series: pd.Series, value: float) -> float | None:
    clean = series.dropna().astype(float)
    if clean.empty:
        return None
    return float((clean <= value).mean())


def safe_round(value: float | None, digits: int = 4) -> float | None:
    if value is None or not math.isfinite(float(value)):
        return None
    return round(float(value), digits)


def quote_snapshot(symbol: str) -> dict:
    try:
        t = yf.Ticker(symbol, session=SESSION)
        fast = dict(t.fast_info or {})
    except Exception as exc:
        return {"quote_error": f"{type(exc).__name__}: {exc}"}
    bid = fast.get("bid")
    ask = fast.get("ask")
    last = fast.get("last_price") or fast.get("lastPrice")
    spread = None
    spread_bps = None
    if bid and ask and last and ask >= bid and last > 0:
        spread = float(ask) - float(bid)
        spread_bps = spread / float(last) * 10000
    return {
        "bid": safe_round(float(bid), 4) if bid else None,
        "ask": safe_round(float(ask), 4) if ask else None,
        "last": safe_round(float(last), 4) if last else None,
        "quoted_spread": safe_round(spread, 4),
        "quoted_spread_bps": safe_round(spread_bps, 2),
    }


def classify_strength(rs_return_20d: float | None, rs_slope_20d: float | None, accel: float | None) -> tuple[str, str]:
    if rs_return_20d is None or rs_slope_20d is None:
        return "unknown", "unknown"
    if rs_return_20d > 0.02 and rs_slope_20d > 0:
        state = "outperforming"
    elif rs_return_20d < -0.02 and rs_slope_20d < 0:
        state = "underperforming"
    else:
        state = "mixed"

    if accel is None:
        phase = "unknown"
    elif accel > 0.001:
        phase = "accelerating"
    elif accel < -0.001:
        phase = "decelerating"
    else:
        phase = "stable"
    return state, phase

