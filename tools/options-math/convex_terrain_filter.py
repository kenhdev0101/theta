#!/usr/bin/env python3
"""
convex_terrain_filter.py — thesis-free "cheap-convexity terrain" gate (napkin).

FAIL-CLOSED admissibility screen. A ticker must pass EVERY structural signal or
the system does NOT build a convex probe around it — no exceptions for hot names.
This is the inversion of the crowd's "pick the exciting stock" workflow: we only
express convexity where the surface makes it structurally cheap to express.

Signals (ref docs/research/fringe-convexity/perplexity_latency-and-terrain.md):
  1. conventional downward smirk  — 25Δ call IV materially BELOW 25Δ put IV
                                     (risk-reversal < 0). A rising right-wing
                                     smile (NVDA-style) FAILS here.
  2. IV/realized ratio (DIAGNOSTIC) — reported, NOT gated. A cheapness read; the
                                     wrapper thesis gates on expressibility, not cheapness.
  3. quiet surface (vol-of-vol)   — cross-sectional IV dispersion low (PROXY;
                                     true vol-of-vol needs an IV time series).
  4. executable wing              — ACHIEVABLE entry cost (mid-anchored re-peg via
                                     convex_fill_sim), NOT the raw quoted spread — a
                                     wide quote we can still enter cheaply is not a reject.
  5. no catalyst (DIAGNOSTIC)     — reported, NOT gated; the surface already prices
                                     known catalysts and we never hand-feed a calendar.

NAPKIN thresholds — starting points to retune against live fills, not law.
Numbers in -> JSON verdict out, like the other one-off scripts here.

Usage:
    convex_terrain_filter.py --ticker KO --spot 68.2 --dte 24 \\
        --call-rows '<json>' --put-rows '<json>' --realized-vol 0.18 --catalyst-days 40
"""
import argparse
import json
import math
import os
import sys
from datetime import datetime, timezone

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import convex_fill_sim as cfs  # noqa: E402

# Napkin thresholds (override via flags).
RR_MAX = -0.01           # 25d call IV must be >= 1 vol pt below 25d put IV
IV_RV_MAX = 1.10         # ATM IV at discount/parity to realized
DISPERSION_MAX = 0.06    # stdev of wing IVs (proxy for a "quiet" surface)
FAR_SPREAD_BPS_MAX = 3500  # far-wing spread ceiling in bps of mid
FAR_DELTA = 0.20         # "far wing" spread sampled near this call delta — pulled INWARD
                         # from 0.12: deep penny strikes always look wide, so 0.20d is the
                         # representative tradeable-wing read. NB: coarse-strike names (e.g.
                         # low-price ETFs) may have NO ~0.20d strike, so the read can snap
                         # deeper and still show wide — verified on XLK (lands at 0.14d).
TOXIC_BPS = 15000        # quote-quality cutoff: strip strikes wider than this (garbage)
FRINGE_DEPTH_DELTA = 0.10  # a genuine fringe needs a tradeable call at/below this delta
ACH_COST_BPS_MAX = 1500  # achievable (mid-anchored re-peg) entry-cost budget at the far wing
MIN_FILL_PROB = 0.50     # min fill probability to call the wing executable


def _nearest(rows, key, target):
    rows = [r for r in rows if r.get(key) not in (None, "")]
    return min(rows, key=lambda r: abs(abs(float(r[key])) - target)) if rows else None


def _spread_bps(r):
    bid, ask = float(r.get("bid", 0) or 0), float(r.get("ask", 0) or 0)
    mid = (bid + ask) / 2
    return round(10000 * (ask - bid) / mid, 1) if mid > 0 else None


def _clean(rows):
    """Quote-quality pre-filter: drop strikes that would poison the signals —
    null/zero IV, zero/missing bid, crossed (ask < bid), or toxic-wide spread.
    Run BEFORE any signal so one garbage wing can't pollute dispersion / selection
    (the XLV/XLP lesson). Returns (clean_rows, n_stripped)."""
    clean = []
    for r in rows:
        iv = r.get("iv")
        bid = float(r.get("bid", 0) or 0)
        ask = float(r.get("ask", 0) or 0)
        if iv in (None, "") or float(iv) <= 0:
            continue
        if bid <= 0 or ask < bid:
            continue
        sb = _spread_bps(r)
        if sb is None or sb > TOXIC_BPS:
            continue
        clean.append(r)
    return clean, len(rows) - len(clean)


def evaluate(ticker, spot, dte, call_rows, put_rows, realized_vol=None, catalyst_days=None,
             rr_max=RR_MAX, iv_rv_max=IV_RV_MAX, dispersion_max=DISPERSION_MAX,
             far_spread_bps_max=FAR_SPREAD_BPS_MAX, far_delta=FAR_DELTA, rich_max=0.02,
             ach_cost_bps_max=ACH_COST_BPS_MAX, min_fill_prob=MIN_FILL_PROB,
             repeg_cap_frac=cfs.REPEG_CAP_FRAC):
    # Quote-quality pre-filter FIRST — strip garbage strikes before any signal is computed.
    call_rows, n_call_stripped = _clean(call_rows)
    put_rows, n_put_stripped = _clean(put_rows)
    signals = {}

    # 1. conventional downward smirk (25d risk reversal)
    c25 = _nearest(call_rows, "delta", 0.25)
    p25 = _nearest(put_rows, "delta", 0.25)
    rr = (float(c25["iv"]) - float(p25["iv"])) if c25 and p25 else None
    signals["conventional_smirk"] = {
        "risk_reversal_25d": round(rr, 4) if rr is not None else None,
        "call25_iv": round(float(c25["iv"]), 4) if c25 else None,
        "put25_iv": round(float(p25["iv"]), 4) if p25 else None,
        "threshold": f"<= {rr_max}",
        "pass": (rr is not None and rr <= rr_max),
        "pending": rr is None,
        "note": None if rr is not None else "no clean 25d call/put after quote-quality filter — not assessable",
    }

    # 1b. fringe not rich — the DEEP-OTM call (where we'd actually buy) must not carry IV
    #     materially ABOVE ATM. A rising far wing = lottery/squeeze demand = expensive convexity.
    #     (The 25d RR misses this: a name can look conventional at 25d yet smile up at 2-3d.)
    atm_c = _nearest(call_rows, "strike", spot)
    fringe_c = min([r for r in call_rows if r.get("delta") not in (None, "")],
                   key=lambda r: abs(float(r["delta"])), default=None)
    rich = (float(fringe_c["iv"]) - float(atm_c["iv"])) if (atm_c and fringe_c) else None
    fringe_delta = abs(float(fringe_c["delta"])) if (fringe_c and fringe_c.get("delta") not in (None, "")) else None
    # pending (not fail) when cleaning left no tradeable DEEP call — we can't assess the fringe.
    fringe_pending = (rich is None) or (fringe_delta is not None and fringe_delta > FRINGE_DEPTH_DELTA)
    signals["fringe_not_rich"] = {
        "fringe_strike": float(fringe_c["strike"]) if fringe_c else None,
        "fringe_iv": round(float(fringe_c["iv"]), 4) if fringe_c else None,
        "fringe_delta": round(fringe_delta, 4) if fringe_delta is not None else None,
        "atm_iv": round(float(atm_c["iv"]), 4) if atm_c else None,
        "richness_vs_atm": round(rich, 4) if rich is not None else None,
        "threshold": f"<= {rich_max}",
        "pass": (rich is not None and rich <= rich_max and not fringe_pending),
        "pending": fringe_pending,
        "note": None if not fringe_pending else
                f"no tradeable deep-OTM call after cleaning (deepest delta > {FRINGE_DEPTH_DELTA}) — fringe not assessable",
    }

    # 2. IV / realized ratio (ATM IV from nearest-spot call)
    atm = _nearest(call_rows, "strike", spot)
    atm_iv = float(atm["iv"]) if atm else None
    ratio = (atm_iv / realized_vol) if (atm_iv and realized_vol) else None
    signals["iv_rv_ratio"] = {
        "atm_iv": round(atm_iv, 4) if atm_iv else None,
        "realized_vol": realized_vol, "ratio": round(ratio, 3) if ratio else None,
        "threshold": f"<= {iv_rv_max}",
        "pass": (ratio is not None and ratio <= iv_rv_max),
        "diagnostic": True,
        "note": "DIAGNOSTIC — reported, NOT gated. IV/RV is a cheapness read; the wrapper thesis "
                "gates on expressibility (shape/execution/catalyst), not cheapness. fringe_not_rich "
                "stays hard for lottery avoidance.",
    }

    # 3. quiet surface — cross-sectional IV dispersion (PROXY for vol-of-vol)
    ivs = [float(r["iv"]) for r in call_rows if r.get("iv") not in (None, "")]
    disp = None
    if len(ivs) >= 3:
        m = sum(ivs) / len(ivs)
        disp = math.sqrt(sum((v - m) ** 2 for v in ivs) / len(ivs))
    signals["quiet_surface_proxy"] = {
        "wing_iv_dispersion": round(disp, 4) if disp is not None else None,
        "n_clean_points": len(ivs),
        "threshold": f"<= {dispersion_max}",
        "pass": (disp is not None and disp <= dispersion_max),
        "pending": disp is None,
        "note": "cross-sectional IV stdev (PROXY; true vol-of-vol needs a time series)"
                + ("" if disp is not None else " — <3 clean strikes, not assessable"),
    }

    # 4. executable wing — a programmatic price-taker posts at mid and re-pegs toward the ask,
    #    so gate on ACHIEVABLE cost (convex_fill_sim), NOT the raw quoted spread. This is the
    #    whole point of the wrapper thesis: a wide quote we can still enter cheaply is not a reject.
    far = _nearest(call_rows, "delta", far_delta)
    fill = cfs.simulate(far["bid"], far["ask"],
                        volume=far.get("volume"), open_interest=far.get("open_interest"),
                        budget_bps=ach_cost_bps_max, min_fill_prob=min_fill_prob,
                        repeg_cap_frac=repeg_cap_frac) if far else {}
    signals["executable_wing"] = {
        "far_call_strike": float(far["strike"]) if far else None,
        "far_call_delta": round(abs(float(far["delta"])), 4) if far and far.get("delta") not in (None, "") else None,
        "quoted_spread_bps": fill.get("quoted_spread_bps"),
        "achievable_cost_bps": fill.get("achievable_cost_bps"),
        "fill_probability": fill.get("fill_probability"),
        "availability": fill.get("availability"),
        "liquidity_factor": fill.get("liquidity_factor"),
        "threshold": f"achievable <= {ach_cost_bps_max} bps AND fill_prob >= {min_fill_prob}",
        "pass": bool(fill.get("executable")),
        "pending": far is None,
        "note": "mid-anchored re-peg achievable cost (convex_fill_sim), not raw quoted spread"
                + ("" if far else " — no far-wing strike after cleaning"),
    }

    # 5. no impending binary catalyst inside the option's life
    signals["no_catalyst"] = {
        "catalyst_days": catalyst_days, "dte": dte,
        "pass": (catalyst_days is None or catalyst_days > dte),
        "diagnostic": True,
        "note": "DIAGNOSTIC — reported, NOT gated. The surface already prices known catalysts "
                "(earnings richness shows in fringe_not_rich / the iv_rv diagnostic); we don't "
                "hand-feed an earnings calendar or limit the universe to earnings-scheduled names.",
    }

    diagnostics = [k for k, v in signals.items() if v.get("diagnostic")]
    pending = [k for k, v in signals.items() if v.get("pending") and not v.get("diagnostic")]
    failed = [k for k, v in signals.items() if not v["pass"] and not v.get("pending") and not v.get("diagnostic")]
    verdict = "ADMIT" if (not failed and not pending) else "REJECT"  # fail-closed; diagnostics never gate

    return {
        "ticker": ticker, "spot": round(spot, 4), "dte": dte,
        "verdict": verdict, "failed_signals": failed, "pending_signals": pending,
        "diagnostics": diagnostics,
        "quote_quality": {"calls_kept": len(call_rows), "calls_stripped": n_call_stripped,
                          "puts_kept": len(put_rows), "puts_stripped": n_put_stripped},
        "signals": signals,
        "notes": [
            "FAIL-CLOSED: a ticker is ADMIT only if every signal passes. A hot name with a "
            "rising right-wing smile (risk_reversal >= 0) REJECTS here by design.",
            "Thesis-free: no view on direction, no name picking — purely structural surface signals.",
            "Quote-quality pre-filter strips zero-bid / null-IV / crossed / toxic (> %d bps) strikes "
            "before any signal; far-wing spread sampled at ~%.2f delta (pulled inward)." % (TOXIC_BPS, far_delta),
            "Napkin thresholds; retune against live fills. A signal with missing/insufficient input is "
            "'pending' (distinct from a data-bearing fail) and still blocks ADMIT (fail-closed).",
        ],
        "computed_at_utc": datetime.now(timezone.utc).isoformat(),
    }


def main() -> None:
    p = argparse.ArgumentParser()
    p.add_argument("--ticker", required=True)
    p.add_argument("--spot", type=float, required=True)
    p.add_argument("--dte", type=int, required=True)
    p.add_argument("--call-rows", required=True, help="JSON list of {strike,iv,delta,bid,ask}")
    p.add_argument("--put-rows", required=True, help="JSON list of {strike,iv,delta,bid,ask}")
    p.add_argument("--realized-vol", type=float)
    p.add_argument("--catalyst-days", type=int)
    p.add_argument("--rr-max", type=float, default=RR_MAX)
    p.add_argument("--iv-rv-max", type=float, default=IV_RV_MAX)
    p.add_argument("--dispersion-max", type=float, default=DISPERSION_MAX)
    p.add_argument("--far-spread-bps-max", type=float, default=FAR_SPREAD_BPS_MAX)
    args = p.parse_args()

    try:
        call_rows = json.loads(args.call_rows)
        put_rows = json.loads(args.put_rows)
    except json.JSONDecodeError as e:
        print(json.dumps({"error": f"bad JSON: {e}"}))
        raise SystemExit(1)

    out = evaluate(args.ticker, args.spot, args.dte, call_rows, put_rows,
                   realized_vol=args.realized_vol, catalyst_days=args.catalyst_days,
                   rr_max=args.rr_max, iv_rv_max=args.iv_rv_max,
                   dispersion_max=args.dispersion_max, far_spread_bps_max=args.far_spread_bps_max)
    print(json.dumps(out, indent=2))


if __name__ == "__main__":
    main()
