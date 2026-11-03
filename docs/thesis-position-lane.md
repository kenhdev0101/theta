# Thesis-Position Lane — Design

Owner: operator
Agent ID: claude/theta-options-20260816-a
Scope: The watched-thesis options lane — 1–3 concurrent positions, days-horizon holds, frictionless morning approval, open-execution without the 6 AM PT wake-up
Status: DRAFT — design accepted direction, pre-implementation
Date: 2026-08-16

## 0. Mission

Encode the firm's actually-profitable options style: thesis-driven single-leg
positions (the Warsh-stack shape — see edge-bot
`docs/research/options/playbook/`), 1–3 open at a time, 30–70 DTE matched to
thesis horizon, entered at the open, watched for one to several days against
precommitted kill triggers. The operator's morning obligation collapses to one
tap. The machine owns placement, fill confirmation, lifecycle marking, and
trigger-driven exits.

This is the arch doc §8 lane (`docs/architecture.md`) that the June sprint
skipped in favor of the intraday scalp lane. The June live run proved the
execution machine (rails, idempotency, review→place, reconciliation — 46
broker-reconciled fills on 2026-06-16) and disproved the scalp strategy
(cash $482.47 → $314.07 over Jun 15–18; 1-DTE churn paying the spread each
round trip). Machine stays; strategy changes. The scalp lane parks default-off.

## 1. The Robinhood MCP challenge (measured, not assumed)

theta consumes Robinhood's agentic MCP **programmatically as a typed client**.
The surface was designed for a model-in-the-loop reading tool descriptions, so
the usual broker-API assumptions fail in specific, measured ways:

1. **The contract is prompt-layer, not API-layer.** Operating guidance
   ("confirm with the user", "account must come from the user") lives in tool
   descriptions aimed at a model; the server enforces only `agentic_allowed`,
   order validity, buying power, and `ref_id` dedupe (arch §4). Consuming it
   programmatically means our deterministic rails must *replace* — and exceed —
   the guidance the server assumes a model is following.
2. **No versioned schema; shapes are empirical.** Payload shapes had to be
   live-measured (commit 761b5d8) and can drift without a deprecation cycle.
   Tool inventory itself moves without notice — the option order tools appeared
   between 2026-06-09 and 2026-06-12. `theta:options-capability-readback` is
   therefore a **daily preflight**, not a one-time integration test.
3. **Auth is desktop-granted, undocumented lifecycle, and it kills the harness
   silently.** Measured: OAuth grant minted 2026-06-12 ~10:16Z; last good call
   2026-06-19 21:04:03Z; first `HTTP 401` 21:05:01Z — **~7.4-day session**.
   The client registered `grant_types: [authorization_code, refresh_token]`
   and the store persists `refresh_token`, but **refresh was never
   implemented** — headless re-mint has never been attempted. The harness then
   hammered 12,256 failing calls on Jun 22 with no escalation. Both halves are
   ours to fix: implement refresh + proactive re-mint, and make auth loss a
   loud pre-open operator alert instead of a silent death.
4. **Polling control plane, no push.** No fill webhooks, no streaming, no
   documented rate limits or `Retry-After`. Fill confirmation is a bounded
   polling loop; Alpaca stays the data plane; every MCP call is budgeted and
   journaled (`mcp_calls` stream).
5. **Errors arrive as prose-ish JSON-RPC strings** (e.g. Go unmarshal text in
   the capability log), not typed error codes. Failure classification is
   empirical and must be maintained as a mapping table, fail-closed on unknown.
6. **The sanctioned lane is the moat.** `placed_agent='agentic'` tagging + the
   `agentic_allowed` cash account (`872449178`, option_level_2 since
   2026-06-12) keep us inside Robinhood's intended envelope. No private-REST
   bypass, ever (arch §4).

Consequence for this lane: the morning flow must **front-load every
MCP-surface risk into a pre-open preflight** so nothing is discovered at
6:30 AM with an order in hand.

## 2. Lane shape

```
nightly/standing THESIS INTENTS (operator-authored or model-drafted)
        │
        ▼  T-45m (5:45 AM PT)
PRE-OPEN ASSEMBLY  — auth preflight → capability readback → resolve chain →
                     derive strike/expiry vs LIVE spot → EV math (greeks svc) →
                     size within rails → SLATE artifact
        │
        ▼  push
FRICTIONLESS APPROVAL — one-tap arm from the phone (§3); silence = no trade
        │
        ▼  6:30 AM PT
OPEN EXECUTOR — place limit, work fill in precommitted bounds, confirm, journal
        │
        ▼  RTH, minutes cadence
LIFECYCLE MONITOR — mark positions, evaluate kill triggers at thesis cadence,
                    exit through the same rails, push outcomes
```

**Thesis intent schema** (durable row; journal-linked): underlying, direction,
structure (`single_leg_long_call|put`), DTE band, entry limit derivation rule,
max debit, thesis text + catalyst window, and all four triggers — price stop,
time stop, profit target, thesis stop. Strikes are **never** pre-derived
(playbook lesson #1: pre-derived strikes die on overnight gaps); structure + EV
thresholds precommit, exact strikes derive at assembly against live spot.

**Concurrency rail:** hard max 3 open thesis positions; a new slate play that
would breach it is blocked with a named reason, never silently dropped.

## 3. Frictionless approval (the operator contract)

Design target: **one tap from the lock screen** in the common case; zero taps
means zero trades (approval mode is fail-closed).

1. **T-45 slate push** via the existing `MonitorNotifier` webhook. ntfy is the
   preferred channel because it supports notification **action buttons**: the
   push carries `Arm slate` (HTTP action → signed approve endpoint) and
   `Review` (opens the slate page). Slack/discord/raw fall back to the signed
   link only. Local/prod currently have no `HARNESS_MONITOR_WEBHOOK_URL`
   configured — channel choice is an arm-checklist item.
2. **Slate page** (mobile-first, served by theta web): 1–3 plays, each showing
   contract, limit, max debit, EV verdict, kill triggers; per-play veto
   toggles; single `Arm` button. Signed, expiring Laravel route. theta web is
   loopback-only today — phone reach requires tailnet exposure (Tailscale
   serve or binding to the tailnet interface). Never public internet.
3. **Approval semantics:** approvals bind to the slate's content hash (an
   edited slate re-requires approval), expire at the entry-window close
   (default 7:15 AM PT), and are journaled with timestamp + source. Partial
   approval is first-class: veto one play, arm the rest.
4. **Auth failure = actionable, not silent.** If the T-45 preflight hits 401
   or refresh fails, the push says `RE-AUTH BEFORE OPEN` with the bootstrap
   command — converting the June failure mode (discovering auth death after
   the open) into a 45-minute desktop fix window.
5. **Thesis stop stays human, but frictionless:** every open position on the
   cockpit/slate page carries a one-tap `Exit now` (signed, confirm-on-tap)
   that routes through the exit rails. Operator judgment executes at machine
   speed.
6. **Auto mode later:** silence-veto (arch §8) is earned after a clean
   approval-mode live record; not part of v1.

## 4. Auth lifecycle workplan (gates unattended; buildable now)

1. Implement `refresh_token` re-mint in the OAuth client (registered, unused).
2. Proactive re-mint at ~80% of measured session life (start from the ~7.4-day
   observation; re-measure — this is arch §13 task #1 finally instrumented).
3. Controlled expiry probe: let one grant age past re-mint failure in a
   read-only window to learn refresh-token TTL and failure shape.
4. Escalation policy on auth loss: kill switch engages, MCP call cadence drops
   to a 15-minute probe, urgent push fires. No more 12k-call 401 storms.

## 5. Build sequence

1. **S1 — schema + config:** thesis intent model/migration, lane config block
   (approval window, concurrency, debit caps), scalp-lane park (default-off).
2. **S2 — OAuth refresh + escalation** (§4 items 1, 2, 4).
3. **S3 — pre-open assembly:** preflight chain + strike derivation + EV gate +
   slate artifact + push.
4. **S4 — approval surface:** signed routes, slate page, arm/veto/exit-now,
   content-hash binding.
5. **S5 — open executor:** limit placement, bounded fill-working (re-peg to
   mid within a max-chase cap; cancel + notify at window close — the IWM
   no-chase rule), fill confirmation push.
6. **S6 — lifecycle monitor:** thesis-cadence trigger evaluation, exits
   through rails, outcome pushes, EOD digest.

Each slice ships with tests (fixture transports in unit tests only;
integration proof is live broker truth per repo standard) and lands
default-off behind the lane flag until the arm checklist clears.

## 6. Arm checklist (operator-authority items)

- [ ] Robinhood OAuth re-auth (desktop, operator-only) — nothing runs before this
- [ ] Push channel chosen + `HARNESS_MONITOR_WEBHOOK_URL`/format set (ntfy preferred for action buttons)
- [ ] Tailnet exposure decision for the slate page
- [ ] Capital top-up decision (account ~$314 after the June run; 30–70 DTE playbook sizing wants more)
- [ ] `HARNESS_MODE` semantics for the lane: approval mode explicit (current env says `auto` — must not leak into this lane)
- [ ] First slate: operator-authored theses (model-drafted slates are a later slice)

## 7. Build status (as of 2026-08-18 ~02:00 PT)

Slices S1 (schema/CLI/park), S3 (assembly), and S5 (executor) are BUILT and
review-certified. Commits: 51513f5 (S1), a11e9c6/6953626/ef8279f (S3+S5),
82051c9/54825e3/948f3fb (review round-1 fixes), f56dee8/c40b0f8 (round-2
fixes). Review record: two-round adversarial pass — round 1 found 3 blockers
(re-peg double-order race, blocked-play abandonment with a live gfd order,
scalp exit engine liquidating thesis positions) + 7 majors, all fixed; round 2
verified every fix against its original attack and caught 1 new blocker in a
fix (re-peg place-throw retiring the play on the stale cancelled order id)
+ 3 majors, all fixed and verified by focused arbiter review. Final gates:
302 tests / 3128 assertions, PHPStan zero findings in every lane file, Pint
clean. Live-verified read-only: OAuth grant (expires 2026-08-25T15:59:21Z),
`cancel_option_order` present in the 54-tool production inventory, and three
assembly dry-runs against live quotes each ending in a correct named skip.

Mode 1 semantics locked: operator authoring + `--activate` IS the standing
approval; the machine assembles at 08:45 ET (fallback tick 09:31), works the
fill 09:30–10:15, and supervises every order to broker-confirmed death.
Mode 2 (model-drafted theses -> draft + push -> one-tap arm) is designed
(§3), not yet built. S2 (OAuth refresh), S4 (slate page/push), S6 (lifecycle
monitor for held positions) remain.

Arm checklist (operator): capital top-up (account read $14.07 on
2026-08-17); author real theses via `theta:thesis-add ... --activate`
(max_debit must cover the name's premium AND fit the lane per-play cap,
default $500 — raise via HARNESS_THESIS_PER_PLAY_MAX_DEBIT if the thesis
warrants); set `HARNESS_THESIS_ENABLED=true` in `.env`; start the scheduler
(`docker compose -p theta up -d scheduler`) with the Mac awake through the
entry window. Positions entered before S6 lands are watched by the operator —
kill triggers on HELD positions are not yet machine-evaluated.

## 8. Non-goals

- No spreads/short premium until an explicit later pass (Level 2 boundary).
- No auto mode in v1; no loosening EV/kill-trigger rules for volume.
- No private-REST bypass; no model on the execution path (arch law §2).
- The scalp lane is parked, not deleted — it remains the execution testbed.
