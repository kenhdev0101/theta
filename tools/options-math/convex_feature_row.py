#!/usr/bin/env python3
"""
convex_feature_row.py — flatten the green-toolkit pipeline into ONE standard
feature-matrix row per (ticker, tenor, snapshot).

Future-proofing: makes the primitives a modular FEATURE EXTRACTOR. Every sweep
appends rows to a feature matrix (rows = observations, columns = computed
signals + provenance + RESERVED outcome columns, null until a realized fill /
outcome is joined later). This feeds a lightweight OUTCOME-FEEDBACK / calibration
loop — NOT a predictor. Wrapper posture: the venue computes the surface; this is
the clean inference layer reading its boundaries. No forecasting, no GBM.

Composes the existing primitives unchanged (convex_terrain_filter +
convex_precompute) and adds the geography features (tail reach, wing slope).
Numbers in -> flat dict / CSV out.
"""
import argparse
import json
import math
import os
import sys
from datetime import datetime, timezone

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import convex_terrain_filter as ctf  # noqa: E402
import convex_precompute as cpc       # noqa: E402

AGENT_ID = "claude/theta-fringe"
POLICY_VERSION = "convex-wrapper-napkin-v1"

# reserved for the later outcome-feedback join; null at emit time
OUTCOME_COLUMNS = ["o_filled", "o_entry_fill_px", "o_exit_px", "o_pnl_pct",
                   "o_underlying_move_pct", "o_exit_reason", "o_closed_ts"]


def _geography(spot, dte, call_rows, atm_iv):
    """Tail reach + right-call-wing slope from the OTM call wing (geography features)."""
    T = dte / 365.0
    pts = []
    for r in call_rows:
        iv = r.get("iv")
        if iv in (None, "") or float(iv) <= 0:
            continue
        if float(r.get("bid", 0) or 0) <= 0:
            continue
        K = float(r["strike"])
        if K <= spot:
            continue
        k = math.log(K / spot)
        x = k / (atm_iv * math.sqrt(T)) if (atm_iv and atm_iv > 0) else None
        if x is not None:
            pts.append((x, k, float(iv)))
    if not pts:
        return {"max_quotable_sigma": None, "n_quotable_past_2sigma": 0, "wing_slope": None, "vega_class": None}
    max_x = max(p[0] for p in pts)
    n2 = sum(1 for p in pts if p[0] >= 2.0)
    fit = [(k, iv) for (x, k, iv) in pts if x >= 0.3]
    slope = None
    if len(fit) >= 3:  # OLS of iv on k
        n = len(fit); sk = sum(k for k, _ in fit); si = sum(v for _, v in fit)
        skk = sum(k * k for k, _ in fit); ski = sum(k * v for k, v in fit)
        den = n * skk - sk * sk
        slope = (n * ski - sk * si) / den if den != 0 else None
    vc = None
    if slope is not None:
        vc = "TAILWIND" if slope < -0.05 else ("HEADWIND" if slope > 0.05 else "flat")
    return {"max_quotable_sigma": round(max_x, 2), "n_quotable_past_2sigma": n2,
            "wing_slope": round(slope, 4) if slope is not None else None, "vega_class": vc}


def compute(ticker, bucket, spot, dte, call_rows, put_rows, realized_vol=None,
            catalyst_days=None, snapshot_ts=None, repeg_cap_frac=ctf.cfs.REPEG_CAP_FRAC):
    ts = snapshot_ts or datetime.now(timezone.utc).isoformat()
    f = ctf.evaluate(ticker, spot, dte, call_rows, put_rows,
                     realized_vol=realized_vol, catalyst_days=catalyst_days,
                     repeg_cap_frac=repeg_cap_frac)
    s = f["signals"]
    atm_iv = s["iv_rv_ratio"].get("atm_iv") or s["fringe_not_rich"].get("atm_iv")
    geo = _geography(spot, dte, call_rows, atm_iv or 0.0)

    anchor = fringe = conv = {}
    try:
        pc_rows = [{"strike": r["strike"], "iv": r["iv"], "delta": r.get("delta"),
                    "gamma": r.get("gamma"), "premium": r.get("ask")} for r in call_rows]
        fr = cpc.compute(side="call", spot=spot, dte=dte, rows=pc_rows).get("fire_row", {})
        anchor, fringe = fr.get("anchor", {}), fr.get("fringe", {})
        conv = fr.get("convexity", {}).get("sticky_moneyness", {})
    except Exception:
        pass

    ew = s["executable_wing"]
    row = {
        "ticker": ticker, "bucket": bucket, "snapshot_ts": ts,
        "agent_id": AGENT_ID, "policy_version": POLICY_VERSION, "source": "robinhood_mcp",
        "spot": round(spot, 4), "dte": dte,
        "atm_iv": atm_iv, "realized_vol": realized_vol, "iv_rv_ratio": s["iv_rv_ratio"]["ratio"],
        "rr25": s["conventional_smirk"]["risk_reversal_25d"],
        "fringe_richness": s["fringe_not_rich"]["richness_vs_atm"],
        "quiet_dispersion": s["quiet_surface_proxy"]["wing_iv_dispersion"],
        "max_quotable_sigma": geo["max_quotable_sigma"], "n_quotable_past_2sigma": geo["n_quotable_past_2sigma"],
        "wing_slope": geo["wing_slope"], "vega_class": geo["vega_class"],
        "far_strike": ew["far_call_strike"], "far_delta": ew["far_call_delta"],
        "quoted_spread_bps": ew["quoted_spread_bps"], "achievable_cost_bps": ew["achievable_cost_bps"],
        "fill_probability": ew["fill_probability"], "availability": ew.get("availability"),
        "anchor_strike": anchor.get("strike"), "anchor_delta_accel": anchor.get("delta_accel_per_1pct"),
        "fringe_strike": fringe.get("strike"), "convex_asymmetry": conv.get("asymmetry_ratio"),
        "convex_accel": conv.get("convex_accel"), "flip_move": conv.get("flip_move"),
        "verdict": f["verdict"], "failed_signals": "|".join(f["failed_signals"]),
        "pending_signals": "|".join(f["pending_signals"]), "diagnostics": "|".join(f.get("diagnostics", [])),
    }
    for c in OUTCOME_COLUMNS:
        row[c] = None
    return row


FEATURE_COLUMNS = [
    "ticker", "bucket", "snapshot_ts", "agent_id", "policy_version", "source", "spot", "dte",
    "atm_iv", "realized_vol", "iv_rv_ratio", "rr25", "fringe_richness", "quiet_dispersion",
    "max_quotable_sigma", "n_quotable_past_2sigma", "wing_slope", "vega_class",
    "far_strike", "far_delta", "quoted_spread_bps", "achievable_cost_bps", "fill_probability", "availability",
    "anchor_strike", "anchor_delta_accel", "fringe_strike", "convex_asymmetry", "convex_accel", "flip_move",
    "verdict", "failed_signals", "pending_signals", "diagnostics",
] + OUTCOME_COLUMNS


def _csv_cell(v):
    if v is None:
        return ""
    s = str(v)
    return '"' + s.replace('"', '""') + '"' if ("," in s or '"' in s) else s


def main() -> None:
    p = argparse.ArgumentParser()
    p.add_argument("--ticker", required=True)
    p.add_argument("--bucket", default="")
    p.add_argument("--spot", type=float, required=True)
    p.add_argument("--dte", type=int, required=True)
    p.add_argument("--call-rows", required=True, help="JSON list of {strike,iv,delta,gamma,bid,ask,volume,open_interest}")
    p.add_argument("--put-rows", required=True, help="JSON list of {strike,iv,delta,bid,ask}")
    p.add_argument("--realized-vol", type=float)
    p.add_argument("--catalyst-days", type=int)
    p.add_argument("--repeg-cap-frac", type=float, default=ctf.cfs.REPEG_CAP_FRAC)
    p.add_argument("--format", choices=["json", "csv", "csv-header"], default="json")
    args = p.parse_args()

    if args.format == "csv-header":
        print(",".join(FEATURE_COLUMNS))
        return
    try:
        call_rows = json.loads(args.call_rows); put_rows = json.loads(args.put_rows)
    except json.JSONDecodeError as e:
        print(json.dumps({"error": f"bad JSON: {e}"})); raise SystemExit(1)

    row = compute(args.ticker, args.bucket, args.spot, args.dte, call_rows, put_rows,
                  realized_vol=args.realized_vol, catalyst_days=args.catalyst_days,
                  repeg_cap_frac=args.repeg_cap_frac)
    if args.format == "csv":
        print(",".join(_csv_cell(row[c]) for c in FEATURE_COLUMNS))
    else:
        print(json.dumps(row, indent=2))


if __name__ == "__main__":
    main()
