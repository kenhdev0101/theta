# options-math toolkit

Tiny Python utilities for the manual-execution options playbook track. Each
script is standalone, takes structured CLI args, and emits JSON. The
`options-research-analyst` agent invokes them via Bash to inject deterministic
math into an otherwise prose-y catalyst-research pass.

This toolkit is **read-only and idempotent** — no orders, no DB writes, no
state outside the operator's terminal session. Live data sourcing is limited
to yfinance (Yahoo Finance OHLC + option chains); no broker APIs, no live
ticks.

## Setup (once)

```
./tools/options-math/setup.sh
```

Builds a venv at `tools/options-math/.venv` and installs `requirements.txt`.

## Scripts

| Script | What it does | Inputs | Output |
|---|---|---|---|
| `iv_rank.py` | Pull current ~30-DTE IV from yfinance + rank against trailing HV30 | `--ticker NVDA [--lookback 252]` | IV30, HV30, hv-rank percentile, regime label |
| `expected_move.py` | Implied 1-SD move from ATM straddle | `--underlying X --straddle-mid Y --dte N` OR `--ticker X --expiry YYYY-MM-DD` | implied move $/%, breakevens, back-solved IV |
| `pop_from_delta.py` | Convert option delta(s) to POP for common structures | `--structure {credit_spread\|iron_condor\|...} --short-delta D [--long-delta D]` | POP, assignment risk, structural caveats |
| `ev_defined_risk.py` | EV calc for defined-risk structures — catches high-POP-low-EV traps | `--max-profit X --max-loss Y --pop P` OR `--credit C --width W --pop P` | EV $, EV per unit risked, breakeven POP, verdict |
| `single_leg_ev.py` | EV calc for single-leg long calls/puts (Robinhood Level 2). Enforces the correct formula `EV = POP_ITM × avg_intrinsic_if_ITM − debit` (NOT `POP × payoff − (1−POP) × debit` — that double-counts debit and overstates EV by `POP × debit`/share). Reports both risk-neutral and drift-adjusted scenarios; uses P(profit) for Kelly inputs (not POP_ITM). | `--side put --spot S --strike K --iv σ --dte N --debit D [--drift-pct-per-day x] [--iv-haircut h]` OR `--side put --ticker NOC --strike 550 --expiry 2026-05-15 --debit 8.75` | RN + drift-adjusted EV $/share + $/contract, P(profit), avg intrinsic if ITM, Kelly fractions, verdict |
| `kelly_fraction.py` | Kelly position-size fraction (full / half / **quarter** = policy default) | `--win-prob P --reward-risk B` OR `--win-prob P --avg-win X --avg-loss Y` | full/half/quarter Kelly, % of bankroll recommendation |
| `regime_classify.py` | Trend vs range classifier from OHLC (ADX(14) + MA slope + Hurst) | `--ticker SPY [--lookback 60]` | regime label + structure hint + reasoning |

## Invocation pattern (the agent uses this)

```
./tools/options-math/.venv/bin/python tools/options-math/iv_rank.py --ticker NVDA
```

All scripts emit JSON on stdout; non-zero exit on error (with JSON `error` field).

## Design choices

- **No shared module.** Each script is fully self-contained for easy operator
  inspection and so a single broken script doesn't block the others.
- **HV rank, not true IV rank.** True IV rank requires a paid historical IV
  feed. We compute HV rank from OHLC as a manual-track proxy and report current
  IV30 separately so the operator can compare. Document this honestly in
  artifacts.
- **Quarter-Kelly default.** Mirrors `app/Services/KellyService.php` which
  constructs with a 0.25 multiplier. Full Kelly is reported but not
  recommended.
- **Delta-as-probability is approximate.** Documented in every `pop_from_delta`
  output. Real POP differs slightly due to vol smile, term structure, drift.
- **yfinance is flaky.** Scripts wrap calls in try/except and emit JSON-shaped
  errors rather than tracebacks. The agent should still treat errors as gaps
  in the artifact, not silent failures.

## What this is NOT

- Not a backtester. Use `ml/` and `tests/` infrastructure for that.
- Not a live data feed. yfinance is delayed 15+ minutes and can lag intraday.
- Not an order-routing surface. Operator places trades manually on Robinhood.
- Not a replacement for the operator's judgment. These outputs are inputs to
  an artifact, not decisions.

## Reuse note

The `KellyService` in PHP (`app/Services/KellyService.php`) is the
production-side Kelly implementation, used by the programmatic strategies.
This Python `kelly_fraction.py` is a port of the same formula with the same
quarter-Kelly default — they should agree numerically. If they diverge,
that's a bug; fix the toolkit, not the production code.
