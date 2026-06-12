#!/usr/bin/env python3
"""Normalized geometric coordinates for the structure classifier (anatomy §3.1).

Fills the gaps the 2026-06-09 prototypes left open: expansion velocity (C-dot),
drive efficiency (Kaufman ER, signed), acceleration (X-double-dot), true RVOL
(vs time-of-day expectation), volume-profile acceptance (A), anchored VWAP,
and halt detection.

Deliberately feed-agnostic: pure functions over pandas frames/series the
caller supplies — no yfinance, no network, no session state — so the hot
engine can re-home them onto the real-time feed unchanged. Every function
returns None (or a flagged dict) on insufficient data; UNKNOWN is never
reported as zero. Fully observed degenerate cases are different: a flat
tape is observed-zero drive (E = 0.0), not unknown.

Timezone rule: timestamp comparisons align a naive side to the aware side's
zone (assumes both describe the same market clock); two aware zones are
converted. Feed adapters should supply tz-aware market-local indexes.

Frame convention: DatetimeIndex; columns High/Low/Close/Volume (Open unused).
Exact window lengths and cutoffs are build-time calibration items (§12.2),
tuned by forward-return separation — the defaults here are starting points.
"""
from __future__ import annotations

from datetime import datetime
from typing import Any

import numpy as np
import pandas as pd

# ---------------------------------------------------------------------------
# Internal helpers (kept local so this module has zero scanner dependencies).
# ---------------------------------------------------------------------------


def _atr(frame: pd.DataFrame, period: int = 14) -> pd.Series:
    high = frame["High"].astype(float)
    low = frame["Low"].astype(float)
    close = frame["Close"].astype(float)
    tr = pd.concat(
        [high - low, (high - close.shift(1)).abs(), (low - close.shift(1)).abs()],
        axis=1,
    ).max(axis=1)
    return tr.ewm(alpha=1 / period, adjust=False).mean()


def _linreg_slope(values: np.ndarray) -> float:
    x = np.arange(len(values), dtype=float)
    slope, _ = np.polyfit(x, values, 1)
    return float(slope)


def _finite_or_none(value: float | None) -> float | None:
    if value is None or not np.isfinite(value):
        return None
    return float(value)


def _align_timezones(a: pd.Timestamp, b: pd.Timestamp) -> tuple[pd.Timestamp, pd.Timestamp]:
    """Make two timestamps comparable: a naive side adopts the aware side's
    zone (same-market-clock assumption); two aware zones compare absolutely."""
    if a.tzinfo is None and b.tzinfo is not None:
        a = a.tz_localize(b.tzinfo)
    elif b.tzinfo is None and a.tzinfo is not None:
        b = b.tz_localize(a.tzinfo)
    return a, b


# ---------------------------------------------------------------------------
# C-dot — expansion velocity: delta band-width over k bars, ATR-normalized,
# signed (+ = expanding). Width basis: Bollinger total width (4 * rolling std).
# ---------------------------------------------------------------------------


def expansion_velocity(
    frame: pd.DataFrame,
    width_window: int = 20,
    k: int = 5,
    atr_period: int = 14,
) -> float | None:
    close = frame["Close"].dropna().astype(float)
    if len(close) < width_window + k or len(frame) < atr_period + 1:
        return None

    width = 4.0 * close.rolling(width_window).std(ddof=0)
    width_now = width.iloc[-1]
    width_then = width.iloc[-1 - k]
    atr_now = _atr(frame, atr_period).iloc[-1]
    if not np.isfinite(width_now) or not np.isfinite(width_then) or not atr_now or atr_now <= 0:
        return None

    return _finite_or_none((width_now - width_then) / atr_now)


# ---------------------------------------------------------------------------
# E — drive efficiency: signed Kaufman efficiency ratio in [-1, 1].
# net move / sum of absolute bar moves; sign carries direction.
# ---------------------------------------------------------------------------


def drive_efficiency(close: pd.Series, window: int = 20) -> float | None:
    clean = close.dropna().astype(float)
    if len(clean) < window + 1:
        return None

    segment = clean.iloc[-(window + 1):]
    net = segment.iloc[-1] - segment.iloc[0]
    path = segment.diff().abs().sum()
    if not np.isfinite(path) or path <= 0:
        # Deliberate: path == 0 means a fully OBSERVED flat tape — that is
        # zero drive (the degenerate ER limit the compression signature
        # needs), distinct from unknown/insufficient data which returns
        # None above.
        return 0.0

    return _finite_or_none(net / path)


# ---------------------------------------------------------------------------
# X-double-dot — acceleration: change in regression slope over k bars,
# ATR-normalized, signed.
# ---------------------------------------------------------------------------


def acceleration(
    frame: pd.DataFrame,
    slope_window: int = 10,
    k: int = 5,
    atr_period: int = 14,
) -> float | None:
    close = frame["Close"].dropna().astype(float)
    if len(close) < slope_window + k or len(frame) < atr_period + 1:
        return None

    slope_now = _linreg_slope(close.iloc[-slope_window:].values)
    slope_then = _linreg_slope(close.iloc[-(slope_window + k):-k].values)
    atr_now = _atr(frame, atr_period).iloc[-1]
    if not atr_now or atr_now <= 0:
        return None

    return _finite_or_none((slope_now - slope_then) / atr_now)


# ---------------------------------------------------------------------------
# P — true RVOL: current bar volume vs the median volume of the SAME
# time-of-day bucket over trailing sessions (the prototype's
# volume_expansion used a rolling 20-bar mean, which conflates time-of-day
# shape with participation).
# ---------------------------------------------------------------------------


def true_rvol(
    history: pd.DataFrame,
    current_ts: datetime | pd.Timestamp,
    current_volume: float,
    min_observations: int = 5,
) -> float | None:
    """history: intraday bars spanning multiple sessions (DatetimeIndex,
    Volume column). The expected volume for current_ts's minute-of-day is the
    median across prior sessions at that same minute.

    Buckets are wall-clock minutes in the history index's timezone; supply a
    tz-aware market-local index so DST does not skew buckets. A naive side is
    localized to the aware side's zone; two aware zones are converted.

    min_observations is a build-time calibration tunable (§12.2): 5 demands
    ~a week of same-minute history (conservative); tune down for young
    symbols / early-session reads, up to reduce noise.
    """
    if "Volume" not in history.columns or history.empty:
        return None

    ts = pd.Timestamp(current_ts)
    volumes = history["Volume"].dropna().astype(float)
    if volumes.empty:
        return None

    index = volumes.index
    if index.tz is not None and ts.tzinfo is None:
        ts = ts.tz_localize(index.tz)
    elif index.tz is not None and ts.tzinfo is not None:
        ts = ts.tz_convert(index.tz)
    elif index.tz is None and ts.tzinfo is not None:
        # Compare in the index's naive wall clock.
        ts = ts.tz_localize(None)
    same_bucket = volumes[(index.hour == ts.hour) & (index.minute == ts.minute)]
    # Exclude the current session's own bar from the expectation.
    same_bucket = same_bucket[same_bucket.index.normalize() != ts.normalize()]

    if len(same_bucket) < min_observations:
        return None

    expected = float(same_bucket.median())
    if expected <= 0:
        return None

    return _finite_or_none(float(current_volume) / expected)


# ---------------------------------------------------------------------------
# A — acceptance: value build vs rejection at a level, in [0, 1].
# time-at-price (closes inside the band) x two-sided rotation, discounted by
# wick rejection (bars that probed the band but closed well outside).
# ---------------------------------------------------------------------------


def acceptance(
    frame: pd.DataFrame,
    level: float,
    window: int = 30,
    band_atr_fraction: float = 0.25,
    atr_period: int = 14,
) -> float | None:
    if len(frame) < max(window, atr_period + 1) or level <= 0:
        return None

    atr_now = _atr(frame, atr_period).iloc[-1]
    if not atr_now or atr_now <= 0:
        return None

    band = band_atr_fraction * atr_now
    recent = frame.iloc[-window:]
    high = recent["High"].astype(float)
    low = recent["Low"].astype(float)
    close = recent["Close"].astype(float)

    inside = (close - level).abs() <= band
    time_at_price = float(inside.mean())

    closes_above = ((close > level) & inside).any() or (close > level + band).any()
    closes_below = ((close < level) & inside).any() or (close < level - band).any()
    touched_both_sides = bool((close >= level).any() and (close <= level).any())
    rotation = 1.0 if (touched_both_sides or (closes_above and closes_below)) else 0.5

    touched = (high >= level - band) & (low <= level + band)
    rejected = touched & ((close - level).abs() > 2.0 * band)
    wick_rejection = float(rejected.sum() / max(int(touched.sum()), 1))

    score = time_at_price * rotation * (1.0 - wick_rejection)
    return _finite_or_none(min(max(score, 0.0), 1.0))


# ---------------------------------------------------------------------------
# Anchored VWAP — volume-weighted typical price from an explicit anchor
# (key level / event bar), plus the signed ATR-normalized distance used as
# the extension coordinate X against that anchor.
# ---------------------------------------------------------------------------


def anchored_vwap(frame: pd.DataFrame, anchor_ts: datetime | pd.Timestamp) -> float | None:
    anchored = frame[frame.index >= pd.Timestamp(anchor_ts)]
    if anchored.empty or "Volume" not in anchored.columns:
        return None

    typical = (
        anchored["High"].astype(float)
        + anchored["Low"].astype(float)
        + anchored["Close"].astype(float)
    ) / 3.0
    volume = anchored["Volume"].astype(float)
    total = volume.sum()
    if not np.isfinite(total) or total <= 0:
        return None

    return _finite_or_none(float((typical * volume).sum() / total))


def avwap_distance_atr(
    frame: pd.DataFrame,
    anchor_ts: datetime | pd.Timestamp,
    atr_period: int = 14,
) -> float | None:
    vwap_value = anchored_vwap(frame, anchor_ts)
    if vwap_value is None or len(frame) < atr_period + 1:
        return None

    atr_now = _atr(frame, atr_period).iloc[-1]
    if not atr_now or atr_now <= 0:
        return None

    last_close = float(frame["Close"].dropna().astype(float).iloc[-1])
    return _finite_or_none((last_close - vwap_value) / atr_now)


# ---------------------------------------------------------------------------
# Halt detection — mechanical, from the bar stream itself. The caller owns
# session truth (only invoke while the session is open); this flags the two
# observable signatures: the stream stopped, or prints froze.
# ---------------------------------------------------------------------------


def halt_flag(
    frame: pd.DataFrame,
    now: datetime | pd.Timestamp,
    expected_interval_seconds: int = 60,
    stall_bars: int = 3,
) -> dict[str, Any]:
    if frame.empty:
        return {"halted": True, "reason": "no_bars", "last_bar_age_seconds": None}

    now_ts, last_ts = _align_timezones(pd.Timestamp(now), pd.Timestamp(frame.index[-1]))
    age = float((now_ts - last_ts).total_seconds())

    if age > stall_bars * expected_interval_seconds:
        return {"halted": True, "reason": "stale_or_halted_stream", "last_bar_age_seconds": age}

    if len(frame) >= stall_bars:
        tail = frame.iloc[-stall_bars:]
        zero_volume = (tail["Volume"].astype(float) <= 0).all()
        zero_range = ((tail["High"].astype(float) - tail["Low"].astype(float)) <= 0).all()
        if zero_volume and zero_range:
            return {"halted": True, "reason": "no_prints", "last_bar_age_seconds": age}

    return {"halted": False, "reason": None, "last_bar_age_seconds": age}
