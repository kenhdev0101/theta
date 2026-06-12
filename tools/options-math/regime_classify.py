#!/usr/bin/env python3
"""
regime_classify.py — trend vs range classifier from yfinance OHLC.

Computes ADX(14), 20-day MA slope, and Hurst exponent. Combines into a regime
label so the agent picks the right structure family:
- strong_trend → directional debit verticals or naked directional
- weak_trend   → debit spreads, leaning directional
- range        → iron condors, short strangles, credit spreads
- choppy       → sit out or use very small defined-risk

Usage:
    regime_classify.py --ticker SPY [--lookback 60]
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


def adx(high: pd.Series, low: pd.Series, close: pd.Series, period: int = 14) -> float:
    plus_dm = high.diff()
    minus_dm = -low.diff()
    plus_dm = plus_dm.where((plus_dm > minus_dm) & (plus_dm > 0), 0.0)
    minus_dm = minus_dm.where((minus_dm > plus_dm.replace(0, np.nan).fillna(0)) & (minus_dm > 0), 0.0)

    tr1 = high - low
    tr2 = (high - close.shift(1)).abs()
    tr3 = (low - close.shift(1)).abs()
    tr = pd.concat([tr1, tr2, tr3], axis=1).max(axis=1)
    atr = tr.ewm(alpha=1 / period, adjust=False).mean()

    plus_di = 100 * plus_dm.ewm(alpha=1 / period, adjust=False).mean() / atr
    minus_di = 100 * minus_dm.ewm(alpha=1 / period, adjust=False).mean() / atr
    dx = 100 * (plus_di - minus_di).abs() / (plus_di + minus_di).replace(0, np.nan)
    adx_series = dx.ewm(alpha=1 / period, adjust=False).mean()
    return float(adx_series.iloc[-1])


def ma_slope_pct(close: pd.Series, window: int = 20) -> float:
    ma = close.rolling(window).mean()
    if len(ma.dropna()) < window:
        return float("nan")
    recent = ma.dropna().iloc[-window:]
    x = np.arange(len(recent))
    slope, _ = np.polyfit(x, recent.values, 1)
    return float(slope / recent.iloc[-1] * 100)


def hurst_exponent(close: pd.Series, max_lag: int = 30) -> float:
    """R/S-style estimator. H > 0.5 = trending, H < 0.5 = mean-reverting."""
    log_returns = np.log(close / close.shift(1)).dropna().values
    if len(log_returns) < max_lag + 10:
        return float("nan")
    lags = range(2, max_lag)
    tau = []
    for lag in lags:
        diff = log_returns[lag:] - log_returns[:-lag]
        tau.append(np.sqrt(np.std(diff)))
    tau = np.array(tau)
    valid = tau > 0
    if valid.sum() < 5:
        return float("nan")
    poly = np.polyfit(np.log(np.array(lags)[valid]), np.log(tau[valid]), 1)
    return float(poly[0] * 2)


def classify(adx_val: float, slope_pct: float, hurst: float) -> tuple[str, str, str]:
    """Returns (regime, structure_hint, reasoning)."""
    parts = []
    parts.append(f"ADX(14)={adx_val:.1f}")
    parts.append(f"20-MA slope={slope_pct:+.3f}%/day")
    if not np.isnan(hurst):
        parts.append(f"Hurst={hurst:.2f}")

    if adx_val >= 25 and abs(slope_pct) >= 0.05:
        regime = "strong_trend"
        hint = "directional debit vertical or naked long premium aligned with slope direction"
    elif adx_val >= 20 and abs(slope_pct) >= 0.02:
        regime = "weak_trend"
        hint = "debit spread aligned with slope; size smaller than strong trend"
    elif adx_val < 20 and (np.isnan(hurst) or hurst < 0.5):
        regime = "range"
        hint = "iron condor, short strangle, or credit spread — sell premium into low directional conviction"
    else:
        regime = "choppy"
        hint = "sit out, or use very small defined-risk only — no clean regime read"

    return regime, hint, "; ".join(parts)


def main() -> None:
    p = argparse.ArgumentParser()
    p.add_argument("--ticker", required=True)
    p.add_argument("--lookback", type=int, default=60)
    args = p.parse_args()

    out: dict = {"ticker": args.ticker.upper(), "as_of": datetime.now(timezone.utc).isoformat()}
    try:
        t = yf.Ticker(args.ticker, session=_SESSION)
        hist = t.history(period=f"{args.lookback + 30}d", interval="1d")
        if len(hist) < 30:
            out["error"] = f"insufficient history: {len(hist)} bars"
            print(json.dumps(out, indent=2))
            sys.exit(1)

        adx_val = adx(hist["High"], hist["Low"], hist["Close"])
        slope_pct = ma_slope_pct(hist["Close"])
        hurst = hurst_exponent(hist["Close"])

        regime, hint, reasoning = classify(adx_val, slope_pct, hurst)

        out["lookback_days"] = args.lookback
        out["adx_14"] = round(adx_val, 2)
        out["ma_slope_20_pct_per_day"] = round(slope_pct, 4) if not np.isnan(slope_pct) else None
        out["hurst_exponent"] = round(hurst, 3) if not np.isnan(hurst) else None
        out["regime"] = regime
        out["structure_hint"] = hint
        out["reasoning"] = reasoning
        out["spot"] = round(float(hist["Close"].iloc[-1]), 4)

        print(json.dumps(out, indent=2))
    except Exception as e:
        out["error"] = f"{type(e).__name__}: {e}"
        print(json.dumps(out, indent=2))
        sys.exit(1)


if __name__ == "__main__":
    main()
