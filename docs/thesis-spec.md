# Thesis Spec — JSON Encoding for Durable Positions

Owner: horizon
Agent ID: claude/theta-opus-20261005
Scope: The asset-class-agnostic JSON encoding of a firm thesis — the durable-position
primitive the equity lane consumes first and the options lane reuses.
Status: E1 BUILT (spec + validator + `theta:thesis-validate`, offline). E2–E3 pending.
Date: 2026-10-05

## Why this exists

Today a thesis is a relational `thesis_intents` row authored one CLI flag at a
time (`theta:thesis-add`), and it is options-only. This spec is the JSON front
end: one declarative document that precommits **what to hold, how to size it,
how to enter, and — mandatory — how it is invalidated**, for one or more legs
that may mix equity and option. It is the reusable primitive behind two wins:

1. **Durable equity positions, live now.** Fractional/notional sizing scales to
   any balance (commission-free; the only cost is spread/slippage, tiny for
   liquid ETFs). A $5 QQQ position validates the shared spine — rails, durable
   idempotency, reconciliation, the held-position manager — against real broker
   fills at an order of magnitude less risk than one option contract.
2. **One spec shape for both lanes.** The same document carries option legs,
   which reuse the existing single-leg machinery, so the encoding and the
   held-position manager drop straight back into the options lane (and finally
   close its unbuilt S6 "evaluate kill triggers on held positions" gap).

Nothing in the spec touches the broker or derives a price. The concrete limit
(and, for options, the strike) is derived at execution against the live quote —
the equity analogue of the options rule *"strikes are never pre-derived; they
die on overnight gaps."*

## Schema (spec_version "1")

```jsonc
{
  "spec_version": "1",                 // must be a supported version
  "thesis_id": "ai-midterm-2026",      // slug: ^[a-z0-9][a-z0-9._-]{1,62}$ — also the durable idempotency key component
  "owner": "horizon",                  // optional, artifact ownership (CLAUDE.md)
  "agent_id": "claude/theta-opus-...", // optional
  "thesis": "the why — required",      // free text; required to be coherent
  "catalyst": "optional context",
  "catalyst_window": { "start": "YYYY-MM-DD", "end": "YYYY-MM-DD" },
  "legs": [ /* one or more; see below */ ]
}
```

### Leg

```jsonc
{
  "leg_id": "qqq-core",                // stable id; falls back to symbol
  "asset_class": "equity",             // equity | option
  "symbol": "QQQ",
  "direction": "long",                 // long | short — short is blocked for equity (cash account, no short stock)
  "sizing": { /* one mode, see below */ },
  "entry":  { "type": "marketable_limit", "offset_bps": 10, "max_chase_bps": 50 },
  "invalidation": {                    // at least ONE trigger is mandatory
    "price_stop": 690.0,               // underlying level that kills the thesis
    "time_stop": "2026-11-20",         // Y-m-d, not in the past
    "target_pct": 15.0,                // profit target, percent
    "thesis_stop": "free-text condition the operator watches"
  },

  // option-only:
  "structure": "single_leg_long_put",  // single_leg_long_call | single_leg_long_put
  "dte": { "min": 30, "max": 70 }
}
```

### Sizing modes

| mode | fields | meaning |
|---|---|---|
| `notional` | `target_usd`, `max_usd?` | fixed USD exposure, fractional shares |
| `shares` | `shares`, `max_shares?` | explicit (fractional) share quantity |
| `balance_fraction` | `fraction` (0–1], `max_usd?` | **scales with settled balance** — size grows with the account |

`balance_fraction` is the mode that lets the lane "breathe": the same spec sizes
$4 at a $14 balance and $400 at a $1,400 balance, with `max_usd` as an absolute
ceiling. A `balance_fraction` leg with no `max_usd` is valid but warned.

## Validation

`App\Robinhood\Thesis\ThesisSpecValidator` mirrors
`ThesisIntent::activationBlockers()`: it returns **named blockers** (empty =
armable), fail-closed. Two scopes:

- **structural** (config-independent, hard): supported version, slug-safe
  `thesis_id`, thesis text, ≥1 leg, per leg a supported asset class + direction
  (long-only equity), a sane sizing mode with positive amounts, a supported
  entry type, and **at least one invalidation trigger** — never hold without an
  exit. Option legs additionally require a supported structure and a coherent
  DTE band.
- **cap** (authoring-time sanity, `--caps`): notional legs inside the lane
  per-play / per-day dollar caps and the leg count inside max-open. This is an
  early warning only; the money-moving enforcement is the execution rails
  (`SizingGate` / `AllocationGate` / `SettledCashGate`) against live broker
  truth at placement. Share- and balance-fraction legs can't be dollar-checked
  at authoring, so they surface as warnings.

Validate a spec offline (no broker, no writes):

```bash
docker compose -p theta run --rm app php artisan theta:thesis-validate specs/ai-midterm-2026.json --caps
```

## Planned extension — the options-fringe amplifier

The deterministic fast-exit spine unlocks a region of the options surface a
human can't hold: short-dated, far-OTM convexity, where theta burns on a
wall-clock schedule no person can babysit but a millisecond-cadence manager can.
A future option-leg block will carry an `amplify` knob (moneyness /
expiry-aggressiveness + a hard fast-exit discipline) that the assembler reads
when it derives the strike and the held-position manager backstops with a tight,
machine-evaluated exit. The spec is the amplifier's input; the manager is what
makes the fringe survivable. Not in E1 — the encoding is kept forward-compatible
for it (unknown leg fields parse leniently and are ignored until consumed).

**The fringe leg is also a probe.** A few dollars of far-OTM/short-dated
convexity (e.g. an AMD fringe contract at a fraction of the conventional
`AMD 350 10/30 ≈ $1k` position) is dual-purpose: its convex move is *harvestable*
on a fast exit, and its own marking is a live *signal* on whether the
conventional strike is worth sizing into. So a fringe leg can reference a
sibling conventional leg it scouts for: the probe's realized convexity /
greeks feed a deterministic size-up decision on the sibling, and the probe's
cost caps the information spend. This is why the primitive is one spec with many
legs and one manager — a probe and the position it informs are legs of the same
thesis, not separate trades. (Planned; E1 lays the leg + manager substrate it
needs.)

## Build sequence

- **E1 — BUILT.** `ThesisSpec` / `ThesisSpecLeg` value objects, `ThesisSpecValidator`,
  `theta:thesis-validate` (offline), the `specs/ai-midterm-2026.json` example, tests.
- **E2 — persistence + equity intent.** Load a validated spec into durable rows
  (asset-class discriminator on `thesis_intents` or a sibling table), an equity
  broker-context class, an equity caps overlay mirroring `ThesisLaneConfig::railsConfig()`.
- **E3 — the held-position manager (the PLC).** A scheduled, slow-cadence (fast
  for the amplifier) manager that evaluates each held position's invalidation
  triggers against live quotes and exits through the equity rails; asset-class
  agnostic, so it also closes the options S6 gap. Reconcile keeps terminal-fill
  and realized-P&L ownership.

Everything lands default-off behind a lane flag; arming is capital/operator
authority (CLAUDE.md). The account read $14.07 on 2026-09-25 — enough to
validate the equity spine live at fractional size, not to fund the option leg.
