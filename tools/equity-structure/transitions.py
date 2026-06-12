#!/usr/bin/env python3
"""Structural transition graph + hysteresis (anatomy §3.5).

Two layers: the structural lifecycle graph (7 states + neutral) whose legal
edges ARE the signal, and a gate overlay (liquidity_vacuum / danger) that
interrupts any structural state immediately and, on clearing, hands control
back to fresh structural classification. Gates beat structure, always.

Hysteresis: a structural flip fires only when (a) it is a legal edge and
(b) the destination persists >= k bars, or the caller marks the bar as a
decisive threshold-cross. Illegal teleports (e.g. compression -> exhaustion)
are suppressed, forcing the path through the lifecycle.

Pure state-in/state-out: step(prev, raw_state, gates) -> new dict. No I/O,
no clocks, no feed dependencies — the hot engine owns the loop. Edge priors
and persistence k are build-time calibration items (§12.1); the defaults
here are starting points.
"""
from __future__ import annotations

from typing import Any

STRUCTURAL_STATES = frozenset(
    {
        "neutral",
        "compression",
        "shelf",
        "coil_expansion",
        "continuation",
        "acceptance",
        "parabolic",
        "exhaustion",
    }
)

GATE_STATES = frozenset({"liquidity_vacuum", "danger"})

# Edge action vocabulary: entry (E), exit (X), hold, manage (chase guard —
# trail, don't add), gate (overlay successor listed for pre-arm context).
LEGAL_EDGES: dict[str, dict[str, dict[str, str]]] = {
    "neutral": {
        "compression": {"edge": "hold", "trigger": "volatility contracts"},
        "shelf": {"edge": "hold", "trigger": "ledge forms on a level"},
        "continuation": {"edge": "entry", "trigger": "trend resumes"},
        "acceptance": {"edge": "hold", "trigger": "value builds"},
    },
    "compression": {
        "coil_expansion": {"edge": "entry", "trigger": "C-dot rising + RVOL > 1"},
        "shelf": {"edge": "hold", "trigger": "holds a defined level"},
        "neutral": {"edge": "hold", "trigger": "coil decays"},
    },
    "shelf": {
        "coil_expansion": {"edge": "entry", "trigger": "level break with volume"},
        "compression": {"edge": "hold", "trigger": "tightens further"},
        "continuation": {"edge": "entry", "trigger": "trend resumes off the shelf"},
        "neutral": {"edge": "hold", "trigger": "loses the level"},
    },
    "coil_expansion": {
        "continuation": {"edge": "entry", "trigger": "expansion matures"},
        "parabolic": {"edge": "manage", "trigger": "accelerates vertically"},
        "exhaustion": {"edge": "exit", "trigger": "fails / climax"},
        "compression": {"edge": "exit", "trigger": "failed breakout"},
    },
    "continuation": {
        "acceptance": {"edge": "hold", "trigger": "pauses and builds value"},
        "parabolic": {"edge": "manage", "trigger": "accelerates"},
        "exhaustion": {"edge": "exit", "trigger": "climax"},
        "compression": {"edge": "exit", "trigger": "momentum bleed (soft)"},
    },
    "acceptance": {
        "coil_expansion": {"edge": "entry", "trigger": "re-ignites"},
        "continuation": {"edge": "entry", "trigger": "trend resumes"},
        "compression": {"edge": "hold", "trigger": "tightens"},
        "exhaustion": {"edge": "exit", "trigger": "fails to hold the level"},
    },
    "parabolic": {
        "exhaustion": {"edge": "exit", "trigger": "dominant successor: climax reversal"},
        "continuation": {"edge": "hold", "trigger": "orderly cool-down (rare)"},
        "danger": {"edge": "gate", "trigger": "blowoff"},
    },
    "exhaustion": {
        "neutral": {"edge": "hold", "trigger": "cool-down reset"},
        "compression": {"edge": "hold", "trigger": "cool-down into coil"},
        "liquidity_vacuum": {"edge": "gate", "trigger": "post-climax thinning"},
        "danger": {"edge": "gate", "trigger": "cascade"},
    },
}

# Starting transition priors (relative weights among a state's successors).
# Calibration (§12.1) owns these; only deliberate asymmetries are encoded —
# notably parabolic -> exhaustion as the dominant successor.
DEFAULT_PRIORS: dict[tuple[str, str], float] = {
    ("parabolic", "exhaustion"): 3.0,
    ("compression", "coil_expansion"): 1.5,
    ("shelf", "coil_expansion"): 1.5,
    ("coil_expansion", "continuation"): 1.5,
}

# Prototype (2026-06-09 intraday_loop_scanner) label -> canonical state.
# Starting calibration artifact; retune freely. RECLAIM is the continuation
# entry trigger; FAILED_BREAKOUT is the coil_expansion -> compression exit
# observed from the raw side.
PROTOTYPE_STATE_MAP: dict[str, str] = {
    "LIQUIDITY_VACUUM": "liquidity_vacuum",
    "NO_EDGE": "neutral",
    "COMPRESSION_COIL": "compression",
    "ACCEPTANCE_SHELF": "acceptance",
    "DISLOCATION_HOLD": "continuation",
    "RECLAIM": "continuation",
    "FAILED_BREAKOUT": "compression",
    "PARABOLIC_EXPANSION": "parabolic",
}


def normalize_state(raw: str | None) -> str | None:
    """Canonical structural/gate state for a raw label, else None."""
    if not raw:
        return None
    candidate = raw.strip().lower()
    if candidate in STRUCTURAL_STATES or candidate in GATE_STATES:
        return candidate
    return PROTOTYPE_STATE_MAP.get(raw.strip().upper())


def initial_state(raw_state: str | None = None) -> dict[str, Any]:
    state = normalize_state(raw_state) or "neutral"
    if state in GATE_STATES:
        state = "neutral"
    return {
        "state": state,
        "since_bars": 1,
        "pending_state": None,
        "pending_bars": 0,
        "structural_state": state,
    }


def expected_transition(
    state: str,
    priors: dict[tuple[str, str], float] | None = None,
) -> list[dict[str, Any]]:
    """Legal successors of `state` with edge type, trigger, and normalized
    prior probability — what the policy pre-arms against (§3.5)."""
    successors = LEGAL_EDGES.get(state, {})
    if not successors:
        return []

    weights = priors if priors is not None else DEFAULT_PRIORS
    raw = {to: float(weights.get((state, to), 1.0)) for to in successors}
    total = sum(raw.values())

    ranked = [
        {
            "to": to,
            "edge": successors[to]["edge"],
            "trigger": successors[to]["trigger"],
            "p": round(raw[to] / total, 4),
        }
        for to in successors
    ]
    ranked.sort(key=lambda item: (-item["p"], item["to"]))
    return ranked


def step(
    prev: dict[str, Any] | None,
    raw_state: str,
    gates: dict[str, Any] | None = None,
    *,
    k_persistence: int = 3,
    decisive: bool = False,
    priors: dict[tuple[str, str], float] | None = None,
) -> dict[str, Any]:
    """Advance one bar. Returns the new tracker dict plus per-bar outputs:
    changed, edge, suppressed_teleport, gate_active, expected_transition.

    gates: {"danger": bool, "liquidity_vacuum": bool} — quality flags from
    the L coordinate / halt detection. Danger outranks vacuum; both outrank
    structure and flip with NO hysteresis.
    """
    if prev is None:
        prev = initial_state()

    gates = gates or {}
    current = prev["state"]
    structural = prev.get("structural_state", current if current in STRUCTURAL_STATES else "neutral")
    normalized_raw = normalize_state(raw_state)

    out: dict[str, Any] = {
        "prior_state": current,
        "changed": False,
        "edge": None,
        "suppressed_teleport": None,
        "raw_state": raw_state,
        "raw_state_unrecognized": normalized_raw is None,
    }

    # 1. Gate overlay first — interrupts anything, immediately.
    gate_target = "danger" if gates.get("danger") else ("liquidity_vacuum" if gates.get("liquidity_vacuum") else None)
    if gate_target is not None:
        changed = current != gate_target
        out.update(
            {
                "state": gate_target,
                "since_bars": 1 if changed else prev["since_bars"] + 1,
                "pending_state": None,
                "pending_bars": 0,
                "structural_state": structural,
                "changed": changed,
                "edge": "gate" if changed else None,
                "gate_active": gate_target,
                "expected_transition": expected_transition(structural, priors),
            }
        )
        return out

    out["gate_active"] = None

    # 2. Gate cleared — fresh structural classification, no hysteresis.
    if current in GATE_STATES:
        target = normalized_raw if normalized_raw in STRUCTURAL_STATES else "neutral"
        out.update(
            {
                "state": target,
                "since_bars": 1,
                "pending_state": None,
                "pending_bars": 0,
                "structural_state": target,
                "changed": True,
                "edge": "gate_cleared",
                "expected_transition": expected_transition(target, priors),
            }
        )
        return out

    # 3. Structural lifecycle with hysteresis.
    # A raw gate label without the gate flag is a suppressed teleport: gate
    # states are entered through the overlay flags, never through raw labels.
    if normalized_raw in GATE_STATES:
        out.update(
            {
                "state": current,
                "since_bars": prev["since_bars"] + 1,
                "pending_state": None,
                "pending_bars": 0,
                "structural_state": current,
                "suppressed_teleport": normalized_raw,
                "expected_transition": expected_transition(current, priors),
            }
        )
        return out

    target = normalized_raw if normalized_raw in STRUCTURAL_STATES else None

    if target is None or target == current:
        out.update(
            {
                "state": current,
                "since_bars": prev["since_bars"] + 1,
                "pending_state": None,
                "pending_bars": 0,
                "structural_state": current,
                "expected_transition": expected_transition(current, priors),
            }
        )
        return out

    successors = LEGAL_EDGES.get(current, {})
    if target not in successors or successors[target]["edge"] == "gate":
        # Illegal teleport (or gate-only edge without the gate flag):
        # suppressed — the path must go through the lifecycle.
        out.update(
            {
                "state": current,
                "since_bars": prev["since_bars"] + 1,
                "pending_state": None,
                "pending_bars": 0,
                "structural_state": current,
                "suppressed_teleport": target,
                "expected_transition": expected_transition(current, priors),
            }
        )
        return out

    pending_bars = prev["pending_bars"] + 1 if prev.get("pending_state") == target else 1

    if decisive or pending_bars >= k_persistence:
        out.update(
            {
                "state": target,
                "since_bars": 1,
                "pending_state": None,
                "pending_bars": 0,
                "structural_state": target,
                "changed": True,
                "edge": successors[target]["edge"],
                "expected_transition": expected_transition(target, priors),
            }
        )
        return out

    out.update(
        {
            "state": current,
            "since_bars": prev["since_bars"] + 1,
            "pending_state": target,
            "pending_bars": pending_bars,
            "structural_state": current,
            "expected_transition": expected_transition(current, priors),
        }
    )
    return out
