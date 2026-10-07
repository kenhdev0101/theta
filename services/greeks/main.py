"""theta greeks service — stateless compute behind local HTTP.

Wraps the deterministic CLI primitives in tools/options-math. Pure
functions in -> numbers out. This service never submits orders, never
writes trading state, and never holds broker credentials (firm invariant).

Each response carries provenance: script name, argv, timestamps, and
elapsed time, so PHP callers can stamp option_math_payload artifacts.
"""

import json
import os
import subprocess
import sys
import time
from datetime import datetime, timezone

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field

OPTIONS_MATH_DIR = os.environ.get("OPTIONS_MATH_DIR", "/opt/options-math")

# Whitelist of runnable primitives — request scripts by key, never by path.
SCRIPTS = {
    "single_leg_ev": "single_leg_ev.py",
    "ev_defined_risk": "ev_defined_risk.py",
    "kelly_fraction": "kelly_fraction.py",
    "iv_rank": "iv_rank.py",
    "expected_move": "expected_move.py",
    "regime_classify": "regime_classify.py",
    "pop_from_delta": "pop_from_delta.py",
    "fringe_response": "fringe_response.py",
    "fringe_surface": "fringe_surface.py",
    "anchor_select": "anchor_select.py",
    "convex_precompute": "convex_precompute.py",
    "convex_terrain_filter": "convex_terrain_filter.py",
    "convex_fill_sim": "convex_fill_sim.py",
    "convex_feature_row": "convex_feature_row.py",
    "convex_feature_schema": "convex_feature_schema.py",
}

RUN_TIMEOUT_SECONDS = 120

app = FastAPI(title="theta-greeks", docs_url=None, redoc_url=None)


class RunRequest(BaseModel):
    args: list[str] = Field(default_factory=list)


@app.get("/health")
def health() -> dict:
    return {
        "ok": True,
        "service": "theta-greeks",
        "scripts": sorted(SCRIPTS),
        "options_math_dir": OPTIONS_MATH_DIR,
        "ts": datetime.now(timezone.utc).isoformat(),
    }


@app.post("/run/{script}")
def run(script: str, request: RunRequest) -> dict:
    if script not in SCRIPTS:
        raise HTTPException(status_code=404, detail=f"unknown script: {script}")

    path = os.path.join(OPTIONS_MATH_DIR, SCRIPTS[script])
    if not os.path.isfile(path):
        raise HTTPException(status_code=500, detail=f"script missing on disk: {path}")

    argv = [sys.executable, path, *request.args]
    started_at = datetime.now(timezone.utc).isoformat()
    t0 = time.monotonic()

    try:
        proc = subprocess.run(
            argv,
            capture_output=True,
            text=True,
            timeout=RUN_TIMEOUT_SECONDS,
            cwd=OPTIONS_MATH_DIR,
        )
    except subprocess.TimeoutExpired:
        raise HTTPException(status_code=504, detail=f"{script} exceeded {RUN_TIMEOUT_SECONDS}s")

    elapsed_ms = round((time.monotonic() - t0) * 1000, 1)

    stdout_json = None
    if proc.stdout.strip():
        try:
            stdout_json = json.loads(proc.stdout)
        except json.JSONDecodeError:
            stdout_json = None

    return {
        "ok": proc.returncode == 0,
        "script": script,
        "argv": request.args,
        "returncode": proc.returncode,
        "stdout_json": stdout_json,
        "stdout_raw": None if stdout_json is not None else proc.stdout[-8000:],
        "stderr": proc.stderr[-8000:],
        "started_at": started_at,
        "elapsed_ms": elapsed_ms,
    }
