"""Tests for the §3.5 transition graph, gate overlay, and hysteresis."""
from __future__ import annotations

import sys
from pathlib import Path

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from transitions import (  # noqa: E402
    GATE_STATES,
    LEGAL_EDGES,
    STRUCTURAL_STATES,
    expected_transition,
    initial_state,
    normalize_state,
    step,
)


def advance(prev, raw, bars, **kwargs):
    for _ in range(bars):
        prev = step(prev, raw, **kwargs)
    return prev


class TestNormalization:
    def test_prototype_labels_map_to_canonical(self):
        assert normalize_state("DISLOCATION_HOLD") == "continuation"
        assert normalize_state("RECLAIM") == "continuation"
        assert normalize_state("COMPRESSION_COIL") == "compression"
        assert normalize_state("FAILED_BREAKOUT") == "compression"
        assert normalize_state("LIQUIDITY_VACUUM") == "liquidity_vacuum"

    def test_canonical_passthrough_and_unknown(self):
        assert normalize_state("coil_expansion") == "coil_expansion"
        assert normalize_state("definitely_not_a_state") is None

    def test_unknown_raw_keeps_current_state(self):
        out = step(initial_state("compression"), "definitely_not_a_state")
        assert out["state"] == "compression"
        assert out["raw_state_unrecognized"] is True


class TestHysteresis:
    def test_legal_flip_requires_k_bars(self):
        tracker = initial_state("compression")

        tracker = step(tracker, "coil_expansion", k_persistence=3)
        assert tracker["state"] == "compression"
        assert tracker["pending_state"] == "coil_expansion"
        assert tracker["pending_bars"] == 1

        tracker = step(tracker, "coil_expansion", k_persistence=3)
        assert tracker["state"] == "compression"
        assert tracker["pending_bars"] == 2

        tracker = step(tracker, "coil_expansion", k_persistence=3)
        assert tracker["state"] == "coil_expansion"
        assert tracker["changed"] is True
        assert tracker["edge"] == "entry"
        assert tracker["since_bars"] == 1

    def test_pending_resets_when_raw_reverts(self):
        tracker = initial_state("compression")
        tracker = step(tracker, "coil_expansion", k_persistence=3)
        tracker = step(tracker, "compression", k_persistence=3)
        assert tracker["pending_state"] is None
        assert tracker["pending_bars"] == 0

        tracker = step(tracker, "coil_expansion", k_persistence=3)
        assert tracker["pending_bars"] == 1

    def test_decisive_cross_flips_immediately(self):
        tracker = step(initial_state("compression"), "coil_expansion", decisive=True)
        assert tracker["state"] == "coil_expansion"
        assert tracker["changed"] is True

    def test_self_transition_accumulates_persistence(self):
        tracker = advance(initial_state("continuation"), "continuation", 4)
        assert tracker["state"] == "continuation"
        assert tracker["since_bars"] == 5
        assert tracker["changed"] is False


class TestIllegalTeleports:
    def test_compression_to_exhaustion_is_suppressed(self):
        tracker = initial_state("compression")
        for _ in range(5):
            tracker = step(tracker, "exhaustion", k_persistence=3)
            assert tracker["state"] == "compression"
            assert tracker["suppressed_teleport"] == "exhaustion"
            assert tracker["pending_bars"] == 0

    def test_gate_edge_without_gate_flag_is_suppressed(self):
        # parabolic -> danger is listed for pre-arm context, but entering a
        # gate state requires the gate flag, not a raw label.
        tracker = step(initial_state("parabolic"), "danger", k_persistence=1)
        assert tracker["state"] == "parabolic"
        assert tracker["suppressed_teleport"] == "danger"


class TestGateOverlay:
    def test_danger_interrupts_any_state_immediately(self):
        for state in STRUCTURAL_STATES:
            out = step(initial_state(state), state, gates={"danger": True})
            assert out["state"] == "danger"
            assert out["changed"] is True
            assert out["edge"] == "gate"
            assert out["structural_state"] == state

    def test_danger_outranks_vacuum(self):
        out = step(initial_state("continuation"), "continuation", gates={"danger": True, "liquidity_vacuum": True})
        assert out["state"] == "danger"

    def test_gate_clear_hands_back_fresh_classification(self):
        tracker = step(initial_state("continuation"), "continuation", gates={"liquidity_vacuum": True})
        assert tracker["state"] == "liquidity_vacuum"

        # Cleared: adopt the raw structural state immediately, no hysteresis,
        # even though vacuum -> shelf is not a structural lifecycle edge.
        tracker = step(tracker, "shelf")
        assert tracker["state"] == "shelf"
        assert tracker["changed"] is True
        assert tracker["edge"] == "gate_cleared"

    def test_persistent_gate_accumulates_without_flapping(self):
        tracker = step(initial_state("continuation"), "continuation", gates={"danger": True})
        tracker = step(tracker, "continuation", gates={"danger": True})
        assert tracker["state"] == "danger"
        assert tracker["changed"] is False
        assert tracker["since_bars"] == 2


class TestExpectedTransition:
    def test_probabilities_normalize_to_one(self):
        for state in LEGAL_EDGES:
            total = sum(item["p"] for item in expected_transition(state))
            assert total == pytest.approx(1.0, abs=0.001)

    def test_parabolic_dominant_successor_is_exhaustion(self):
        ranked = expected_transition("parabolic")
        assert ranked[0]["to"] == "exhaustion"
        assert ranked[0]["edge"] == "exit"

    def test_compression_pre_arms_the_coil_entry(self):
        ranked = expected_transition("compression")
        top = ranked[0]
        assert top["to"] == "coil_expansion"
        assert top["edge"] == "entry"
        assert "RVOL" in top["trigger"]

    def test_step_output_carries_expected_transition(self):
        out = step(initial_state("compression"), "compression")
        assert out["expected_transition"] == expected_transition("compression")

    def test_custom_priors_override_defaults(self):
        ranked = expected_transition("compression", priors={("compression", "neutral"): 10.0})
        assert ranked[0]["to"] == "neutral"


class TestGraphShape:
    def test_every_edge_target_is_a_known_state(self):
        for state, successors in LEGAL_EDGES.items():
            assert state in STRUCTURAL_STATES
            for target, spec in successors.items():
                assert target in STRUCTURAL_STATES or target in GATE_STATES
                assert spec["edge"] in {"entry", "exit", "hold", "manage", "gate"}

    def test_entry_and_exit_edges_match_the_anatomy_doc(self):
        assert LEGAL_EDGES["compression"]["coil_expansion"]["edge"] == "entry"
        assert LEGAL_EDGES["shelf"]["coil_expansion"]["edge"] == "entry"
        assert LEGAL_EDGES["acceptance"]["continuation"]["edge"] == "entry"
        assert LEGAL_EDGES["coil_expansion"]["exhaustion"]["edge"] == "exit"
        assert LEGAL_EDGES["coil_expansion"]["compression"]["edge"] == "exit"
        assert LEGAL_EDGES["continuation"]["exhaustion"]["edge"] == "exit"
        # Chase guard: parabolic is manage-only from momentum states.
        assert LEGAL_EDGES["coil_expansion"]["parabolic"]["edge"] == "manage"
        assert LEGAL_EDGES["continuation"]["parabolic"]["edge"] == "manage"

    def test_initial_state_from_gate_label_starts_neutral(self):
        assert initial_state("LIQUIDITY_VACUUM")["state"] == "neutral"
