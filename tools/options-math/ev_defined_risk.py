#!/usr/bin/env python3
"""
ev_defined_risk.py — expected-value calc for defined-risk option structures.

EV = POP * max_profit - (1 - POP) * max_loss

Catches the classic high-POP-low-EV trap: a 90% POP credit spread paying 0.50
on a 4.50 max loss is break-even (EV ~ 0). Run every defined-risk play through
this.

Usage:
    ev_defined_risk.py --max-profit 0.50 --max-loss 4.50 --pop 0.70
    ev_defined_risk.py --credit 0.50 --width 5 --pop 0.70   (spread shorthand)
"""
import argparse
import json
import sys


def main() -> None:
    p = argparse.ArgumentParser()
    p.add_argument("--max-profit", type=float)
    p.add_argument("--max-loss", type=float)
    p.add_argument("--credit", type=float, help="for credit spreads — premium received per share")
    p.add_argument("--debit", type=float, help="for debit spreads — premium paid per share")
    p.add_argument("--width", type=float, help="strike width in dollars (paired with --credit or --debit)")
    p.add_argument("--pop", type=float, required=True, help="probability of profit, 0..1")
    args = p.parse_args()

    if args.credit is not None and args.width is not None:
        max_profit = args.credit
        max_loss = args.width - args.credit
        structure = f"credit spread, width={args.width}, credit={args.credit}"
    elif args.debit is not None and args.width is not None:
        max_profit = args.width - args.debit
        max_loss = args.debit
        structure = f"debit spread, width={args.width}, debit={args.debit}"
    elif args.max_profit is not None and args.max_loss is not None:
        max_profit = args.max_profit
        max_loss = args.max_loss
        structure = "explicit max_profit / max_loss"
    else:
        print(json.dumps({"error": "supply either (--credit + --width) or (--debit + --width) or (--max-profit + --max-loss)"}))
        sys.exit(1)

    if args.pop < 0 or args.pop > 1:
        print(json.dumps({"error": "--pop must be between 0 and 1"}))
        sys.exit(1)

    if max_loss <= 0:
        print(json.dumps({"error": "max_loss must be > 0 — defined-risk structure required"}))
        sys.exit(1)

    pop = args.pop
    ev = pop * max_profit - (1 - pop) * max_loss
    ev_per_unit_risked = ev / max_loss
    rr = max_profit / max_loss

    breakeven_pop = max_loss / (max_loss + max_profit)

    out = {
        "structure": structure,
        "pop": round(pop, 4),
        "max_profit": round(max_profit, 4),
        "max_loss": round(max_loss, 4),
        "reward_risk_ratio": round(rr, 4),
        "ev": round(ev, 4),
        "ev_per_unit_risked": round(ev_per_unit_risked, 4),
        "ev_per_unit_risked_pct": round(ev_per_unit_risked * 100, 2),
        "breakeven_pop": round(breakeven_pop, 4),
        "edge_vs_breakeven_pop": round(pop - breakeven_pop, 4),
    }

    if ev > 0:
        out["verdict"] = "POSITIVE_EV"
        if rr < 0.2:
            out["caveat"] = (
                "Skewed risk/reward (<0.2): even small POP estimation error flips this to negative EV. "
                "Tighten POP estimate before sizing."
            )
    elif ev == 0:
        out["verdict"] = "BREAKEVEN_EV"
    else:
        out["verdict"] = "NEGATIVE_EV"
        out["recommendation"] = "Do not slate. POP is insufficient given the reward/risk."

    print(json.dumps(out, indent=2))


if __name__ == "__main__":
    main()
