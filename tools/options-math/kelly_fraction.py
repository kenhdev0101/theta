#!/usr/bin/env python3
"""
kelly_fraction.py — Kelly position-size fraction.

f* = (b*p - q) / b
where b = win/loss ratio, p = win prob, q = 1 - p

Edge Bot's policy default is quarter-Kelly (mirrors app/Services/KellyService.php
which constructs with a 0.25 multiplier). Edge estimates are noisy; full Kelly
overshoots reality. Use the quarter-Kelly figure as the recommendation.

Usage:
    kelly_fraction.py --win-prob 0.65 --reward-risk 1.5
    kelly_fraction.py --win-prob 0.65 --avg-win 150 --avg-loss 100
"""
import argparse
import json
import sys


def main() -> None:
    p = argparse.ArgumentParser()
    p.add_argument("--win-prob", type=float, required=True, help="0..1")
    p.add_argument("--reward-risk", type=float, help="b = avg_win / avg_loss")
    p.add_argument("--avg-win", type=float)
    p.add_argument("--avg-loss", type=float)
    args = p.parse_args()

    if not 0 <= args.win_prob <= 1:
        print(json.dumps({"error": "--win-prob must be between 0 and 1"}))
        sys.exit(1)

    if args.reward_risk is not None:
        b = args.reward_risk
    elif args.avg_win is not None and args.avg_loss is not None:
        if args.avg_loss <= 0:
            print(json.dumps({"error": "--avg-loss must be > 0"}))
            sys.exit(1)
        b = args.avg_win / args.avg_loss
    else:
        print(json.dumps({"error": "either --reward-risk or both --avg-win and --avg-loss required"}))
        sys.exit(1)

    p_win = args.win_prob
    q = 1 - p_win
    if b <= 0:
        print(json.dumps({"error": "reward/risk ratio must be > 0"}))
        sys.exit(1)

    kelly_full = (b * p_win - q) / b
    kelly_half = kelly_full / 2
    kelly_quarter = kelly_full / 4

    out = {
        "win_prob": round(p_win, 4),
        "reward_risk_ratio": round(b, 4),
        "kelly_full": round(kelly_full, 4),
        "kelly_half": round(kelly_half, 4),
        "kelly_quarter": round(kelly_quarter, 4),
        "recommendation_pct_of_bankroll": round(kelly_quarter * 100, 2),
        "policy_note": (
            "Edge Bot default is quarter-Kelly (mirrors app/Services/KellyService.php). "
            "Fractional Kelly is correct because edge estimates are noisy; full Kelly "
            "overshoots when win-prob is overestimated."
        ),
    }

    if kelly_full <= 0:
        out["verdict"] = "NO_EDGE"
        out["recommendation_pct_of_bankroll"] = 0.0
        out["explanation"] = "Negative or zero Kelly — edge is non-existent or unfavorable. Do not size."
    elif kelly_full > 0.25:
        out["verdict"] = "STRONG_EDGE"
        out["caveat"] = (
            "Full Kelly > 25% suggests either a real strong edge or an overestimated win-prob. "
            "Sanity-check the inputs before trusting the recommendation."
        )
    else:
        out["verdict"] = "MODERATE_EDGE"

    print(json.dumps(out, indent=2))


if __name__ == "__main__":
    main()
