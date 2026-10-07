#!/usr/bin/env python3
"""
convex_feature_schema.py — lock + validate the green feature-matrix contract.

Guards the feature matrix (emitted by convex_feature_row) against upstream data
drift. Two layers:
  1. COLUMN LOCK — the validated columns must equal convex_feature_row.FEATURE_COLUMNS
     exactly (order + names). The SCHEMA here is asserted against that list at import,
     so a primitive that renames/adds/drops a column fails loudly instead of silently
     corrupting the matrix.
  2. VALUE CONTRACT — every cell must satisfy its type / range / enum / nullability rule
     (e.g. fill_probability ∈ [0,1], verdict ∈ {ADMIT,REJECT}, provenance non-empty,
     reserved outcome columns present-but-nullable).

Validates a CSV file (header + rows) or a single JSON row. Numbers in → report out.
stdlib only. `--emit-schema` prints the canonical contract as JSON (for docs).
"""
import argparse
import csv
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import convex_feature_row as cfr  # noqa: E402

# type tags
F, I, S, B, E = "float", "int", "str", "bool", "enum"

# canonical per-column contract. nz = required (non-null / non-empty); default nullable.
# ex = exclusive lower bound (strictly > min). enum lists the allowed values.
SCHEMA = {
    "ticker": {"t": S, "nz": True}, "bucket": {"t": S, "nz": True}, "snapshot_ts": {"t": S, "nz": True},
    "agent_id": {"t": S, "nz": True}, "policy_version": {"t": S, "nz": True}, "source": {"t": S, "nz": True},
    "spot": {"t": F, "nz": True, "min": 0, "ex": True}, "dte": {"t": I, "nz": True, "min": 1},
    "atm_iv": {"t": F, "min": 0, "ex": True}, "realized_vol": {"t": F, "min": 0}, "iv_rv_ratio": {"t": F, "min": 0},
    "rr25": {"t": F}, "fringe_richness": {"t": F}, "quiet_dispersion": {"t": F, "min": 0},
    "max_quotable_sigma": {"t": F, "min": 0}, "n_quotable_past_2sigma": {"t": I, "min": 0, "nz": True},
    "wing_slope": {"t": F}, "vega_class": {"t": E, "enum": ["TAILWIND", "HEADWIND", "flat"]},
    "far_strike": {"t": F, "min": 0, "ex": True}, "far_delta": {"t": F, "min": 0, "max": 1},
    "quoted_spread_bps": {"t": F, "min": 0}, "achievable_cost_bps": {"t": F, "min": 0},
    "fill_probability": {"t": F, "min": 0, "max": 1}, "availability": {"t": F, "min": 0, "max": 1},
    "anchor_strike": {"t": F, "min": 0, "ex": True}, "anchor_delta_accel": {"t": F},
    "fringe_strike": {"t": F, "min": 0, "ex": True}, "convex_asymmetry": {"t": F}, "convex_accel": {"t": F},
    "flip_move": {"t": F}, "verdict": {"t": E, "nz": True, "enum": ["ADMIT", "REJECT"]},
    "failed_signals": {"t": S}, "pending_signals": {"t": S}, "diagnostics": {"t": S},
    "o_filled": {"t": B}, "o_entry_fill_px": {"t": F, "min": 0, "ex": True}, "o_exit_px": {"t": F, "min": 0, "ex": True},
    "o_pnl_pct": {"t": F}, "o_underlying_move_pct": {"t": F}, "o_exit_reason": {"t": S}, "o_closed_ts": {"t": S},
}

# COLUMN LOCK — the schema and the emitter must agree, or every validation is meaningless.
assert set(SCHEMA) == set(cfr.FEATURE_COLUMNS), (
    "SCHEMA drift vs convex_feature_row.FEATURE_COLUMNS: "
    f"missing={set(cfr.FEATURE_COLUMNS) - set(SCHEMA)} extra={set(SCHEMA) - set(cfr.FEATURE_COLUMNS)}")


def _is_null(v):
    return v is None or (isinstance(v, str) and v.strip() == "")


def _check_cell(col, raw):
    rule = SCHEMA[col]
    if _is_null(raw):
        return f"{col}: required but null/empty" if rule.get("nz") else None
    t = rule["t"]
    if t == S:
        return None  # any non-empty string
    if t == E:
        return None if str(raw) in rule["enum"] else f"{col}: '{raw}' not in {rule['enum']}"
    if t == B:
        return None if str(raw).lower() in ("true", "false", "0", "1") else f"{col}: '{raw}' not bool"
    # numeric
    try:
        x = float(raw)
    except (ValueError, TypeError):
        return f"{col}: '{raw}' not {t}"
    if t == I and float(x) != int(x):
        return f"{col}: '{raw}' not an int"
    lo = rule.get("min")
    if lo is not None:
        if rule.get("ex") and x <= lo:
            return f"{col}: {x} not > {lo}"
        if not rule.get("ex") and x < lo:
            return f"{col}: {x} < {lo}"
    hi = rule.get("max")
    if hi is not None and x > hi:
        return f"{col}: {x} > {hi}"
    return None


def validate_row(row: dict, idx=None):
    viol = []
    cols = list(row.keys())
    if cols != cfr.FEATURE_COLUMNS:
        missing = [c for c in cfr.FEATURE_COLUMNS if c not in row]
        extra = [c for c in cols if c not in SCHEMA]
        order_ok = [c for c in cols if c in SCHEMA] == [c for c in cfr.FEATURE_COLUMNS if c in cols]
        if missing or extra:
            viol.append(f"row {idx}: column set drift — missing={missing} extra={extra}")
        elif not order_ok:
            viol.append(f"row {idx}: column ORDER drift vs FEATURE_COLUMNS")
    for col in cfr.FEATURE_COLUMNS:
        if col in row:
            v = _check_cell(col, row[col])
            if v:
                viol.append(f"row {idx}: {v}")
    return viol


def validate_csv(path):
    with open(path, newline="") as fh:
        reader = csv.reader(fh)
        header = next(reader, [])
        report = {"path": path, "header_matches": header == cfr.FEATURE_COLUMNS, "n_rows": 0, "violations": []}
        if not report["header_matches"]:
            missing = [c for c in cfr.FEATURE_COLUMNS if c not in header]
            extra = [c for c in header if c not in cfr.FEATURE_COLUMNS]
            report["violations"].append(f"header drift — missing={missing} extra={extra} order_ok={header==cfr.FEATURE_COLUMNS}")
        for i, cells in enumerate(reader):
            report["n_rows"] += 1
            if len(cells) != len(header):
                report["violations"].append(f"row {i}: {len(cells)} cells vs {len(header)} header cols")
                continue
            report["violations"].extend(validate_row(dict(zip(header, cells)), idx=i))
        report["ok"] = report["header_matches"] and not report["violations"]
        return report


def main() -> None:
    p = argparse.ArgumentParser()
    p.add_argument("--csv", help="feature-matrix CSV path to validate")
    p.add_argument("--row", help="single feature row as JSON")
    p.add_argument("--emit-schema", action="store_true", help="print the canonical schema as JSON")
    args = p.parse_args()
    if args.emit_schema:
        print(json.dumps({"columns": cfr.FEATURE_COLUMNS, "schema": SCHEMA}, indent=2))
        return
    if args.csv:
        rep = validate_csv(args.csv)
        print(json.dumps(rep, indent=2))
        raise SystemExit(0 if rep["ok"] else 1)
    if args.row:
        row = json.loads(args.row)
        viol = validate_row(row, idx=0)
        print(json.dumps({"ok": not viol, "violations": viol}, indent=2))
        raise SystemExit(0 if not viol else 1)
    p.error("give --csv, --row, or --emit-schema")


if __name__ == "__main__":
    main()
