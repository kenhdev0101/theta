#!/usr/bin/env python3
"""
pop_from_delta.py — convert option deltas to probabilities for common structures.

Delta-as-probability is an approximation (assumes log-normal dynamics, no
drift). Useful as a first-cut POP estimate for the 5-second sanity check before
sizing.

Usage:
    pop_from_delta.py --short-delta 0.30 --structure credit_spread
    pop_from_delta.py --short-delta 0.16 --long-delta 0.05 --structure iron_condor
    pop_from_delta.py --delta 0.40 --structure long_call
"""
import argparse
import json
import sys


def main() -> None:
    p = argparse.ArgumentParser()
    p.add_argument("--structure", required=True,
                   choices=["credit_spread", "debit_spread", "iron_condor", "short_strangle",
                            "long_call", "long_put", "short_put", "short_call"])
    p.add_argument("--delta", type=float, help="for naked long/short single-leg")
    p.add_argument("--short-delta", type=float, help="for spreads / condors / strangles")
    p.add_argument("--long-delta", type=float, help="long-leg delta for iron condors only")
    args = p.parse_args()

    out: dict = {"structure": args.structure, "warnings": []}
    out["notes"] = (
        "Delta-as-probability assumes log-normal dynamics with no drift. "
        "Real POP differs slightly due to vol smile, term structure, and expected drift. "
        "Use this as a first-cut, not a final sizing input."
    )

    if args.structure in ("long_call", "long_put"):
        if args.delta is None:
            print(json.dumps({"error": "--delta required for long_call/long_put"}))
            sys.exit(1)
        d = abs(args.delta)
        out["delta"] = round(d, 4)
        out["pop_breakeven"] = round(d, 4)
        out["pop_finishes_itm"] = round(d, 4)
        out["expected_loss_if_finished_otm"] = "100% of premium paid"
        out["warning_lifetime_pop_higher"] = (
            "POP at expiry; lifetime touch-prob ≈ 2x delta (mean-reverts toward 0.5 for ATM)"
        )

    elif args.structure in ("short_put", "short_call"):
        if args.short_delta is None:
            print(json.dumps({"error": "--short-delta required"}))
            sys.exit(1)
        d = abs(args.short_delta)
        out["short_delta"] = round(d, 4)
        out["pop"] = round(1 - d, 4)
        out["expiry_otm_prob"] = round(1 - d, 4)
        out["assignment_risk_at_expiry"] = round(d, 4)

    elif args.structure == "credit_spread":
        if args.short_delta is None:
            print(json.dumps({"error": "--short-delta required"}))
            sys.exit(1)
        d = abs(args.short_delta)
        out["short_delta"] = round(d, 4)
        out["pop"] = round(1 - d, 4)
        out["pop_method"] = "1 - short_delta"

    elif args.structure == "debit_spread":
        if args.short_delta is None or args.long_delta is None:
            print(json.dumps({"error": "--short-delta and --long-delta both required for debit_spread"}))
            sys.exit(1)
        long_d = abs(args.long_delta)
        short_d = abs(args.short_delta)
        out["long_delta"] = round(long_d, 4)
        out["short_delta"] = round(short_d, 4)
        out["pop_breakeven"] = round((long_d + short_d) / 2, 4)
        out["pop_max_profit"] = round(short_d, 4)
        out["notes_extra"] = "POP depends on whether you exit at breakeven or hold for max profit; both shown"

    elif args.structure == "iron_condor":
        if args.short_delta is None:
            print(json.dumps({"error": "--short-delta required (assumes symmetric wings)"}))
            sys.exit(1)
        d = abs(args.short_delta)
        out["short_delta_per_wing"] = round(d, 4)
        out["pop"] = round(1 - 2 * d, 4)
        out["pop_method"] = "1 - 2 * short_delta (assumes symmetric wings)"
        if 1 - 2 * d <= 0:
            out["warnings"].append("short_delta too high — implied POP <= 0; check inputs")

    elif args.structure == "short_strangle":
        if args.short_delta is None:
            print(json.dumps({"error": "--short-delta required"}))
            sys.exit(1)
        d = abs(args.short_delta)
        out["short_delta_per_side"] = round(d, 4)
        out["pop"] = round(1 - 2 * d, 4)
        out["pop_method"] = "1 - 2 * short_delta (call side + put side)"
        out["risk_warning"] = "undefined risk; size for tail event, not POP"

    print(json.dumps(out, indent=2))


if __name__ == "__main__":
    main()
