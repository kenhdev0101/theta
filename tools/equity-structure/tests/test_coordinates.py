"""Tests for the §3.1 coordinate gap-fills. Synthetic bars, no network."""
from __future__ import annotations

import sys
from pathlib import Path

import numpy as np
import pandas as pd
import pytest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from coordinates import (  # noqa: E402
    acceleration,
    acceptance,
    anchored_vwap,
    avwap_distance_atr,
    drive_efficiency,
    expansion_velocity,
    halt_flag,
    true_rvol,
)


def make_frame(closes, highs=None, lows=None, volumes=None, start="2026-06-12 09:30", freq="1min"):
    closes = np.asarray(closes, dtype=float)
    n = len(closes)
    index = pd.date_range(start=start, periods=n, freq=freq)
    return pd.DataFrame(
        {
            "High": np.asarray(highs, dtype=float) if highs is not None else closes + 0.05,
            "Low": np.asarray(lows, dtype=float) if lows is not None else closes - 0.05,
            "Close": closes,
            "Volume": np.asarray(volumes, dtype=float) if volumes is not None else np.full(n, 1000.0),
        },
        index=index,
    )


class TestDriveEfficiency:
    def test_straight_drive_is_one(self):
        close = pd.Series(np.linspace(100, 110, 40))
        assert drive_efficiency(close, window=20) == pytest.approx(1.0)

    def test_straight_decline_is_minus_one(self):
        close = pd.Series(np.linspace(110, 100, 40))
        assert drive_efficiency(close, window=20) == pytest.approx(-1.0)

    def test_round_trip_chop_is_near_zero(self):
        # +1, -1 alternating: net ~0 over an even window.
        close = pd.Series(100 + np.tile([0.0, 1.0], 20))
        value = drive_efficiency(close, window=20)
        assert abs(value) < 0.1

    def test_flat_tape_is_observed_zero_drive_not_unknown(self):
        # Deliberate semantics: a fully observed flat tape is zero drive
        # (the compression signature needs |E| low), distinct from
        # insufficient data which returns None.
        close = pd.Series(np.full(40, 100.0))
        assert drive_efficiency(close, window=20) == 0.0

    def test_insufficient_data_is_none(self):
        assert drive_efficiency(pd.Series([1.0, 2.0]), window=20) is None


class TestExpansionVelocity:
    def test_widening_range_is_positive(self):
        quiet = 100 + 0.05 * np.sin(np.arange(40))
        expanding = 100 + np.linspace(0, 1, 15) * np.sin(np.arange(15) * 2.5) * 4
        frame = make_frame(np.concatenate([quiet, expanding]))
        assert expansion_velocity(frame, width_window=20, k=5) > 0

    def test_contracting_range_is_negative(self):
        wild = 100 + np.sin(np.arange(30) * 2.5) * 4
        quiet = 100 + 0.02 * np.sin(np.arange(30))
        frame = make_frame(np.concatenate([wild, quiet]))
        assert expansion_velocity(frame, width_window=20, k=5) < 0

    def test_insufficient_data_is_none(self):
        assert expansion_velocity(make_frame(np.full(10, 100.0))) is None

    def test_atr_normalization_is_scale_invariant(self):
        # Same shape at 2x price scale: width and ATR both double, so the
        # ATR-normalized coordinate must be unchanged — the normalization
        # invariant of §3.1 (a $5 name and a $500 name share the space).
        closes = 100 + np.concatenate(
            [0.05 * np.sin(np.arange(40)), np.linspace(0, 1, 15) * np.sin(np.arange(15) * 2.5) * 4]
        )
        base = make_frame(closes, highs=closes + 0.1, lows=closes - 0.1)
        scaled = make_frame(closes * 2, highs=(closes + 0.1) * 2, lows=(closes - 0.1) * 2)

        assert expansion_velocity(base) == pytest.approx(expansion_velocity(scaled), rel=1e-6)


class TestAcceleration:
    def test_accelerating_up_is_positive(self):
        close = 100 + 0.01 * np.arange(40) ** 2
        assert acceleration(make_frame(close), slope_window=10, k=5) > 0

    def test_decelerating_is_negative(self):
        close = 100 + 10 * np.sqrt(np.arange(40) + 1)
        assert acceleration(make_frame(close), slope_window=10, k=5) < 0

    def test_constant_slope_is_near_zero(self):
        close = 100 + 0.5 * np.arange(40)
        assert abs(acceleration(make_frame(close), slope_window=10, k=5)) < 1e-6


class TestTrueRvol:
    def make_history(self, days=10, minute_volumes=(1000.0, 2000.0, 3000.0)):
        frames = []
        for d in range(days):
            day = f"2026-06-{2 + d:02d}"
            index = pd.date_range(start=f"{day} 09:30", periods=len(minute_volumes), freq="1min")
            frames.append(pd.DataFrame({"Volume": list(minute_volumes)}, index=index))
        return pd.concat(frames)

    def test_double_expected_volume_reads_two(self):
        history = self.make_history()
        rvol = true_rvol(history, pd.Timestamp("2026-06-12 09:31"), current_volume=4000.0)
        assert rvol == pytest.approx(2.0)

    def test_time_of_day_buckets_are_distinct(self):
        history = self.make_history()
        # 09:30 bucket expectation is 1000, so 1000 reads 1.0 even though the
        # 20-bar mean across buckets would not.
        assert true_rvol(history, pd.Timestamp("2026-06-12 09:30"), 1000.0) == pytest.approx(1.0)

    def test_current_session_excluded_from_expectation(self):
        history = self.make_history(days=6)
        spike_index = pd.date_range("2026-06-12 09:30", periods=1, freq="1min")
        history = pd.concat([history, pd.DataFrame({"Volume": [50000.0]}, index=spike_index)])
        rvol = true_rvol(history, pd.Timestamp("2026-06-12 09:30"), 1000.0)
        assert rvol == pytest.approx(1.0)

    def test_too_few_observations_is_none(self):
        history = self.make_history(days=3)
        assert true_rvol(history, pd.Timestamp("2026-06-12 09:30"), 1000.0) is None

    def test_min_observations_is_tunable(self):
        history = self.make_history(days=3)
        assert true_rvol(history, pd.Timestamp("2026-06-12 09:30"), 2000.0, min_observations=1) == pytest.approx(2.0)
        assert true_rvol(self.make_history(days=10), pd.Timestamp("2026-06-12 09:30"), 1000.0, min_observations=100) is None

    def test_tz_aware_history_with_naive_current_ts(self):
        history = self.make_history().tz_localize("America/New_York")
        rvol = true_rvol(history, pd.Timestamp("2026-06-12 09:31"), 4000.0)
        assert rvol == pytest.approx(2.0)

    def test_aware_current_ts_converts_into_history_zone(self):
        history = self.make_history().tz_localize("America/New_York")
        # 13:31 UTC == 09:31 ET on 2026-06-12 — must land in the 09:31 bucket.
        rvol = true_rvol(history, pd.Timestamp("2026-06-12 13:31", tz="UTC"), 4000.0)
        assert rvol == pytest.approx(2.0)


class TestAnchoredVwap:
    def test_hand_computed_case(self):
        frame = make_frame(
            closes=[10.0, 12.0],
            highs=[11.0, 13.0],
            lows=[9.0, 11.0],
            volumes=[100.0, 300.0],
        )
        # typical prices: 10, 12; weights 100, 300 -> (1000 + 3600) / 400 = 11.5
        assert anchored_vwap(frame, frame.index[0]) == pytest.approx(11.5)

    def test_anchor_excludes_earlier_bars(self):
        frame = make_frame(
            closes=[10.0, 12.0],
            highs=[11.0, 13.0],
            lows=[9.0, 11.0],
            volumes=[100.0, 300.0],
        )
        assert anchored_vwap(frame, frame.index[1]) == pytest.approx(12.0)

    def test_distance_is_signed_and_atr_normalized(self):
        n = 30
        closes = np.full(n, 100.0)
        closes[-1] = 103.0
        frame = make_frame(closes, highs=closes + 1.0, lows=closes - 1.0)
        distance = avwap_distance_atr(frame, frame.index[0])
        assert distance is not None and distance > 0


class TestAcceptance:
    def test_value_build_at_level_scores_high(self):
        rng = np.random.default_rng(7)
        closes = 100 + rng.normal(0, 0.03, 40)
        frame = make_frame(closes, highs=closes + 0.1, lows=closes - 0.1)
        assert acceptance(frame, level=100.0, window=30) > 0.5

    def test_price_far_from_level_scores_low(self):
        closes = np.linspace(110, 120, 40)
        frame = make_frame(closes, highs=closes + 0.1, lows=closes - 0.1)
        assert acceptance(frame, level=100.0, window=30) < 0.1

    def test_wick_rejection_discounts_score(self):
        closes = np.full(40, 100.0)
        accepted = make_frame(closes, highs=closes + 0.1, lows=closes - 0.1)

        rejecting_closes = np.full(40, 103.0)
        # Wicks probe the level but closes finish far above it.
        rejecting = make_frame(rejecting_closes, highs=rejecting_closes + 0.2, lows=np.full(40, 99.9))
        assert acceptance(rejecting, 100.0, window=30) < acceptance(accepted, 100.0, window=30)


class TestHaltFlag:
    def test_fresh_stream_is_not_halted(self):
        frame = make_frame(np.linspace(100, 101, 30))
        verdict = halt_flag(frame, frame.index[-1] + pd.Timedelta(seconds=30))
        assert verdict["halted"] is False

    def test_stale_stream_is_halted(self):
        frame = make_frame(np.linspace(100, 101, 30))
        verdict = halt_flag(frame, frame.index[-1] + pd.Timedelta(minutes=10))
        assert verdict["halted"] is True
        assert verdict["reason"] == "stale_or_halted_stream"

    def test_frozen_prints_are_halted(self):
        closes = np.concatenate([np.linspace(100, 101, 27), np.full(3, 101.0)])
        highs = closes.copy()
        lows = closes.copy()
        volumes = np.concatenate([np.full(27, 1000.0), np.zeros(3)])
        frame = make_frame(closes, highs=highs, lows=lows, volumes=volumes)
        verdict = halt_flag(frame, frame.index[-1] + pd.Timedelta(seconds=30))
        assert verdict["halted"] is True
        assert verdict["reason"] == "no_prints"

    def test_empty_frame_is_flagged(self):
        empty = pd.DataFrame(columns=["High", "Low", "Close", "Volume"])
        assert halt_flag(empty, pd.Timestamp("2026-06-12 10:00"))["halted"] is True

    def test_aware_index_with_naive_now(self):
        frame = make_frame(np.linspace(100, 101, 30)).tz_localize("America/New_York")
        naive_now = pd.Timestamp(frame.index[-1].tz_localize(None)) + pd.Timedelta(seconds=30)
        verdict = halt_flag(frame, naive_now)
        assert verdict["halted"] is False
        assert verdict["last_bar_age_seconds"] == pytest.approx(30.0)

    def test_naive_index_with_aware_now(self):
        frame = make_frame(np.linspace(100, 101, 30))
        aware_now = frame.index[-1].tz_localize("America/New_York") + pd.Timedelta(minutes=10)
        verdict = halt_flag(frame, aware_now)
        assert verdict["halted"] is True
        assert verdict["reason"] == "stale_or_halted_stream"

    def test_two_aware_zones_compare_absolutely(self):
        frame = make_frame(np.linspace(100, 101, 30), start="2026-06-12 09:30").tz_localize("America/New_York")
        # Same instant expressed in UTC, 30 seconds later.
        now_utc = (frame.index[-1] + pd.Timedelta(seconds=30)).tz_convert("UTC")
        verdict = halt_flag(frame, now_utc)
        assert verdict["halted"] is False
        assert verdict["last_bar_age_seconds"] == pytest.approx(30.0)
