#!/usr/bin/env python3
"""Composite market structure scanner for execution triage."""
from __future__ import annotations

import argparse
import json

from common import fetch_history, now_utc, parse_csv
from gap_classifier import classify as classify_gap
from liquidity_scanner import scan as scan_liquidity
from relative_strength import analyze_pair
from trend_geometry import geometry


def recommendation(gap: dict, geom: dict, liq: dict, rs: dict | None) -> dict:
    blockers = []
    if liq.get("liquidity_tier") == "avoid_for_options_execution":
        blockers.append("liquidity")
    if geom.get("parabolic_expansion", {}).get("active") and gap.get("classification") == "exhaustion_risk":
        blockers.append("exhaustion_after_parabolic_extension")

    rs_state = (rs or {}).get("state")
    rs_phase = (rs or {}).get("phase")
    if gap.get("classification") == "breakaway" and rs_state == "outperforming" and rs_phase in {"accelerating", "stable"}:
        action = "watchlist_for_directional_long_structure"
    elif gap.get("classification") == "common_continuation" and geom.get("acceptance_shell") == "above_acceptance":
        action = "watchlist_for_pullback_continuation"
    elif blockers:
        action = "avoid_or_manual_only"
    else:
        action = "observe_no_trade_structure_yet"

    return {
        "action": action,
        "blockers": blockers,
        "order_bias": "limit_only",
        "notes": [
            "This scanner classifies structures; it does not place orders.",
            "Use Robinhood order review before any equity execution.",
        ],
    }


def main() -> None:
    p = argparse.ArgumentParser()
    p.add_argument("--tickers", default="AAPL,NVDA")
    p.add_argument("--benchmarks", default="AAPL:QQQ,NVDA:SOXX", help="asset:benchmark pairs")
    p.add_argument("--period", default="6mo")
    args = p.parse_args()

    tickers = parse_csv(args.tickers)
    pairs = {}
    all_symbols = set(tickers)
    for raw in args.benchmarks.split(","):
        if ":" in raw:
            asset, bench = [p.strip().upper() for p in raw.split(":", 1)]
            pairs[asset] = bench
            all_symbols.update([asset, bench])

    history = fetch_history(all_symbols, period=args.period)
    results = []
    for ticker in tickers:
        if ticker not in history:
            continue
        gap = classify_gap(history[ticker])
        geom = geometry(ticker, history[ticker])
        liq = scan_liquidity(ticker, history[ticker])
        rs = None
        bench = pairs.get(ticker)
        if bench and bench in history:
            rs = analyze_pair(ticker, bench, history)
        results.append({
            "symbol": ticker,
            "relative_strength": rs,
            "gap": gap,
            "trend_geometry": geom,
            "liquidity": liq,
            "execution_recommendation": recommendation(gap, geom, liq, rs),
        })

    print(json.dumps({
        "computed_at_utc": now_utc(),
        "period": args.period,
        "results": results,
    }, indent=2))


if __name__ == "__main__":
    main()

