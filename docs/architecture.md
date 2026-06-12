# Agentic Execution Harness — Architecture

Owner: operator
Agent ID: lead-codex
Scope: Robinhood agentic execution harness (equity substrate now, options target) — system architecture and inference/determinism contract
Status: ACTIVE — stand-up architecture
Date: 2026-06-09
Last updated: 2026-06-12

Decisions locked this revision:
- **Build language: PHP, reuse the edge-bot stack** (Laravel scheduler, Horizon queues, JSONL system-of-record, Mission Control conventions) — *not* a separate Python execution service. (Reverses the §9 draft.)
- **Execution client: native PHP MCP client** (A1) — a typed JSON-RPC-over-HTTPS client to the `robinhood-trading` MCP, holding the OAuth token. No Python on the order path; this preserves the firm invariant (all order submission is PHP; Python is stateless compute behind HTTP).
- **Host: same box as edge-bot prod (`edge-bot-prod-us-1`, `ash`/us-east), as its own Docker Compose stack** — *not* a separate VM. The `ccx33` upgrade (8 dedicated vCPU / 32 GB, ~26 GiB free) removed the strain premise that drove the separate-box plan; container-level isolation (own Redis, own Horizon, own MySQL, resource limits) gives service isolation without a second machine. A second box is a deferred scale option, cheap to take later *because the repo stays separate*. (Revises the §9.2 separate-node draft.)
- **Repo: separate repo + separate compose project** — the deploy-blast-radius and operating-context isolation are repo-level concerns, independent of box size, so they stay. theta never shares the perp Redis or queues.
- **Theta deploys do not restart Kalshi/perp** — deployment uses theta's bare repo, theta worktree, theta database, and `docker compose -p theta` only. The shared host is an allowed placement; the theta repository and compose project are the boundary solution.
- **Docker-first build/deploy** — the local implementation environment and Hetzner deployment environment are the same theta Docker Compose stack. Dependency installation and framework commands run inside theta containers, not directly on the Mac or host.
- **Market data: Alpaca Algo Trader Plus / SIP for v0** — `feed=sip`, `https://data.alpaca.markets`, `wss://stream.data.alpaca.markets/v2/sip`; keys live in local `.env` as `ALPACA_DATA_*`. Robinhood MCP remains the broker/control plane, not the scanner feed.
- **Live path: agent-account equity execution first** — the Robinhood agentic cash account is already the sandbox. theta proves itself by placing bounded live equity orders through that account under deterministic rails, then reading broker/account truth.
- **Next characterization item: headless-auth / session-TTL** (§13) — measure the token lifecycle for always-on operation; this does not block live equity bring-up, data plane, rails, journal, or telemetry build-out.

## References (this doc ingests, does not duplicate)

- Trading brain / strategy logic: `docs/intraday-structure-engine.md` (the structure-momentum engine anatomy — what trades; this doc owns *how it's built*)
- Repo bootstrap / warm-start scaffolding: `docs/bootstrap.md`
- Candidate / math / journal layer: `docs/options-infrastructure-plan.md`
- Deterministic primitives (already built): `tools/options-math/` — `single_leg_ev.py`, `ev_defined_risk.py`, `kelly_fraction.py`, `iv_rank.py`, `expected_move.py`, `regime_classify.py`, `pop_from_delta.py`
- Durable journal: `app/Edges/Models/RobinhoodTradeJournal.php`, `database/migrations/2026_06_09_000001_create_robinhood_trade_journal_table.php`
- Manual playbook pipeline (ingest from, do not replace): `docs/playbook/`
- Execution venue: `robinhood-trading` MCP server
- Reused conventions (not codebase): edge-bot state-machine pattern — JSONL system-of-record, broker-truth reconciliation, Mission Control read-only cockpit

## 0. Purpose

Build the autonomous execution harness that "multiplies the operator": it encodes the operator's options/equities process — candidate selection, Greek evaluation, entry timing, all-day lifecycle monitoring, exit — and runs it live in the sandboxed agent account without the human failure modes (emotion, slowness, fatigue, inconsistency, the 6:30 PT wake-up, the Sunday 4-hour grind).

The options *order* path is broker-capability gated (requires `get_option_instruments` / `review_option_order` / `place_option_order` and account permission). Therefore the harness is **built and hardened on the live equity path now**, while the options-specific candidate, journal, math, resolver, and order-path pieces are built in parallel. Nothing in this architecture waits on Robinhood except the final option order submission switch.

## 1. Operating thesis

Done correctly, autonomous execution is **safer** than the human-in-the-loop default, not riskier. The safety comes from **precommitment**: the operator makes the risk decisions once — caps, kill conditions, sizing curve, sit-out regimes — while calm and rigorous, and the machine executes that judgment with zero drift. The human's *judgment* is preserved (it is literally written into the rails); the human's *failure modes* are amputated.

This raises the bar rather than lowering it. Removing the human removes the last reflexive circuit-breaker, so the deterministic rails must be **stricter than Robinhood's human-in-the-loop default.** We earn autonomy by replacing a soft human gate with a harder machine one. Eval + tool-call observability is what proves the encoded judgment stays faithful to intent — without it, "autonomous" is just "unsupervised."

## 2. The central architectural law — inference/determinism contract

Two layers, separated by a hard wall:

- **Decision layer (model-in-the-loop):** forms judgment and produces a *structured intent object*. Allowed to use a model. Its only outputs are (a) a candidate/play with a thesis and (b) a natural-language *rationale*. **It never computes numbers that move money and it never calls Robinhood.**
- **Execution layer (zero model):** deterministic code. Validates intent against rails, then calls Robinhood as a typed API client. No model is present to interpret venue guidance.

The contract, stated as law:

| Function | Layer | Who decides |
|---|---|---|
| Catalyst / candidate conviction, thesis, regime read | Decision | Model judgment (over a deterministic shortlist) |
| Greeks, pricing, IV, breakeven, POP, expected move, EV | Primitive | Deterministic script (`tools/options-math/`) |
| Strike / expiry / contract selection | Primitive | Deterministic screen + EV gate |
| Sizing | Primitive | Deterministic (quarter-Kelly upper anchor) |
| Entry trigger | Execution | Deterministic precommitted gates |
| Order placement, cancel, idempotency | Execution | Deterministic, pinned account + persisted ref_id |
| Lifecycle re-mark vs exits | Execution | Deterministic monitor loop |
| "Thesis materially broke" override | Decision | Model judgment (escape hatch only) |

**Narration ≠ computation.** Every rationale shown in the playbook is the model *describing* what a deterministic primitive already decided. The model can explain why a strike was chosen; it cannot move the strike. This is the rule that makes the system auditable and the reason almost every tedious step the operator wants gone is on the safe (deterministic) side.

## 3. System topology

All of the below runs as **theta's own Docker Compose project locally and on the existing `edge-bot-prod-us-1` (`ash`/us-east) box** — same container definitions, separate containers, separate network, separate Redis/MySQL/Horizon from the perp compose stack (not a separate VM; §9.2). The decision, rail, and execution layers are **PHP (reused edge-bot/Laravel stack)**; only the Greeks/EV primitives are **Python, reached over local HTTP** (the firm's stateless-compute-behind-HTTP pattern), never on the order path.

```
   ╔══════════════════ theta compose project on edge-bot-prod-us-1 (own network/Redis/MySQL) ═══════════════╗
   ║                                                                                                      ║
   ║                     ┌─────────────────────────────────────────────┐                                 ║
   ║  nightly schedule ─▶│  DECISION LAYER (PHP orch. + model-in-loop) │  Laravel scheduler/Horizon       ║
   ║  intraday signal ──▶│  - ingest playbook + scanner                │  calls model API for             ║
   ║                     │  - rank shortlist on thesis/catalyst        │  intent + narration only         ║
   ║                     │  - emit STRUCTURED INTENT + narration       │                                  ║
   ║                     └──────────────────┬──────────────────────────┘                                 ║
   ║                                        │ intent object (typed)                                       ║
   ║  Python Greeks svc ┌──────────────────▼──────────────────────────┐                                 ║
   ║  (FastAPI, local ─▶│  PRIMITIVE LAYER (deterministic)            │  PHP calls Python over local     ║
   ║   HTTP, no orders) │  Greeks, EV, IV-rank, expected-move,        │  HTTP; tools/options-math/       ║
   ║                    │  sizing, contract selection                 │                                  ║
   ║                    └──────────────────┬──────────────────────────┘                                 ║
   ║                                       │ validated, priced intent                                    ║
   ║                    ┌──────────────────▼──────────────────────────┐                                 ║
   ║                    │  RAIL PIPELINE (PHP, deterministic chain)   │                                  ║
   ║                    │  size · settled-cash · session · halt ·     │                                  ║
   ║                    │  dup/idempotency · allocation · kill-switch │                                  ║
   ║                    └──────────────────┬──────────────────────────┘                                 ║
   ║                                       │ approved order (or block + reason)                          ║
   ║                    ┌──────────────────▼──────────────────────────┐                                 ║
   ║                    │  EXECUTION CLIENT (PHP, zero model)         │                                  ║
   ║                    │  RobinhoodMcpClient — native PHP MCP client │  JSON-RPC / streamable           ║
   ║                    │  pinned account, persisted ref_id, OAuth    │  HTTP, holds OAuth token         ║
   ║                    └──────────────────┬──────────────────────────┘                                 ║
   ╚═══════════════════════════════════════│══════════════════════════════════════════════════════════╝
                                           │ HTTPS
            ┌──────────────────────────────┼──────────────────────────────┐
            ▼                               ▼                              ▼
   robinhood-trading MCP        JSONL system-of-record          Mission Control panel
   (broker = truth)             + RobinhoodTradeJournal          (decision log, rationale,
   placed_agent='agentic'       (history independent of broker)   history — broker-independent)
   @ agent.robinhood.com
```

## 4. The Robinhood "guidance" question — resolved

Robinhood's MCP embeds operating guidance in tool descriptions and `guide` fields ("call review first, get explicit user confirmation," "account must come from the user, never default from `get_accounts`," "confirm before calling"). **This guidance is prompt-layer, aimed at a model reading the schema — it is not a server-side rail.** Structural proof: `place_equity_order` has **no confirmation token / `user_approved` parameter** — the server cannot tell whether a human confirmed. What the server actually enforces is `agentic_allowed=true`, order validity, buying power, and `ref_id` dedupe. Those are the real rails; we respect them and exceed them.

Resolution: **we do not "get around" the guidance — we make it irrelevant by putting no model on the execution path.** The decision-layer model emits a structured intent; deterministic code consumes the MCP as a typed client. There is no model at execution time to read "please confirm with the user." The things the guidance says to "ask the user for" are supplied programmatically:

- **account** → pinned config constant (never model-selected)
- **confirmation** → the deterministic rail-pipeline verdict
- **ref_id** → harness-persisted idempotency key (persisted *before* the place call; replayed verbatim on transport retry)

**Wrapper count:** one deterministic adapter module — `RobinhoodMcpClient` (native PHP MCP client; JSON-RPC over streamable HTTP, holds the OAuth token) — with a thin typed method per tool actually used. ~8 now (`review`, `place`, `cancel`, `get_orders`, `get_positions`, `get_portfolio`, `get_quotes`, `tradability`, `search`); ~+5 when options lands (`get_option_instruments`, `review_option_order`, `place_option_order`, `get_option_orders`, `get_option_positions`). Each method injects pinned account + persisted ref_id, normalizes the response, emits telemetry. The wrappers are thin; the engineering weight is the single rail pipeline + the decision-log layer + the MCP transport/OAuth layer, not the per-tool wrappers.

**Stay on the sanctioned lane — do not bypass the MCP to Robinhood's private REST.** The MCP is Robinhood's blessed agentic interface: it tags orders `placed_agent='agentic'` and gates on the `agentic_allowed` account. That tagging is exactly what keeps auto-orders reconciled and inside RH's intended-use envelope. Reverse-engineering private REST to dodge `agentic_allowed` is fragile (undocumented, rotating auth, breaks on their changes) and off-book. We consume the MCP *programmatically as a client* — sanctioned rails, typed surface, minus the model-facing guidance.

## 5. Capital firewall — enforced by account topology

The operator's existing two-account split *is* the firewall — no soft accounting partition needed:

| Account | Type | Options | Agentic | Role |
|---|---|---|---|---|
| `872449178` ("Agentic") | cash | not enabled (`option_level:""`) | `agentic_allowed=true` | **System owns 100%.** Pinned trading account. |
| `717293732` (default) | margin | `option_level_3` | `agentic_allowed=false` | **Operator manual reserve.** Harness physically cannot reach it (server-enforced). |

Consequences baked into the architecture:

1. The trading account is **cash**, not margin → no leverage, no shorting, and **cash settlement**: the sizing/cadence rail must track **settled vs. unsettled cash**, not just `buying_power`, to avoid good-faith / free-riding violations. Upside: cash accounts are PDT-exempt, so intraday round-trips don't trip pattern-day-trader.
2. **Buying power comes from `get_portfolio`**, never `get_accounts` (the schema is explicit it is unreliable there).
3. **Options gating problem (known, account-permission, not engineerable):** the agentic account has options disabled; the L3 options account is agentic-blocked. When RH lands agentic options, this account must be upgraded via the `upgrade_options` apply flow before it can trade them. Flag now; it is a prerequisite, not a code task.

## 6. Execution rails — the pre-trade pipeline

Every `place` passes the full chain; any block returns a named reason that is logged, never silently dropped:

1. **Allocation** — order fits the system's configured bankroll slice; reserve untouched (firewall already guarantees this across accounts).
2. **Settled-cash** — debit ≤ settled cash; unsettled proceeds are not reused.
3. **Sizing** — within per-play and per-day max debit; quarter-Kelly is an upper anchor, not a mandate.
4. **Session / tradability** — `get_equity_tradability` per session; fractional/dollar orders only `regular_hours`; respect halts.
5. **Pre-trade review** — `review_equity_order` consumed programmatically; parse alerts (buying power, PDT, halt) into block/allow.
6. **Idempotency / duplicate** — fresh persisted `ref_id` per logical order; scan open `placed_agent='agentic'` orders/positions to prevent duplicate or orphaned protective legs.
7. **Kill-switch** — global arm flag + daily-loss / error-rate circuit breaker; tripped → no placement, alert.

No native brackets/OCO exist on this venue → protective exits are **separate harness-placed orders**, and orphan-prevention on crash is a first-class harness responsibility (persist intended protective legs before placing the entry; reconcile on restart).

## 7. Execution-state discipline

Broker truth outranks logs. A row is live only when broker-truth fields prove it. Reconciliation key is `get_equity_orders(placed_agent='agentic')` — cleanly separates harness orders from operator manual fills (the three current seed positions BAC/HD/GE are operator-owned and must never be adopted by the harness). Classify every order row explicitly (filled / posted-not-filled / rejected / cancelled / risk-aborted); a `filled` label with `filled_qty=0` is a label conflict, not a fill. Reconcile harness JSONL against MCP order/position/portfolio reads every cycle.

## 8. Daily lifecycle — nightly playbook + auto

Replaces both operator pain windows (the Sunday evaluation grind and the 6:30 PT open scramble):

1. **Nightly (scheduled, reasonable PT hour):** decision layer ingests the playbook/scanner, runs the deterministic primitives, and produces the **playbook artifact** — a ranked plan with per-ticker and per-strike rationale (narration over deterministic tools).
2. **Operator visibility at leisure:** the plan is available in the morning, finished, with veto/kill controls.
3. **Auto mode:** plan **arms at open unless vetoed within a bounded window**. Silence = consent inside the sandboxed agent account; one-tap kill removes any play. The operator can veto/remove individual plays without blocking the rest.
4. **Arm → execution layer** runs the plan through deterministic rails at the scheduled trigger.

## 9. Intraday lane + deployment — PHP sibling, own compose stack on the existing box

Intraday execution speed is where programmatic value is highest (the all-day watching and lifecycle Greek re-checks are pure deterministic monitoring the operator wants amputated). V1 instrument scope is **single-leg long calls/puts** — defined risk, no assignment/margin complexity, and the only form the cash account + current MCP surface support anyway. The value is not the instrument; it is the execution speed + lifecycle monitor.

### 9.1 Execution boundary — reuse the PHP stack, isolate from the hot path

**Decision (reverses the earlier draft): build in PHP, reusing the edge-bot/Laravel stack — not a separate Python execution service.** The earlier draft put execution in Python to keep it out of the Kalshi state machine; that conflated two different kinds of isolation. We get the isolation we actually need from **repo + compose-project separation** (separate git repo, separate Docker Compose stack, own Redis/MySQL/Horizon — §9.2), not language separation — and reusing the PHP stack buys the scheduler, Horizon queues, JSONL system-of-record, broker-truth reconciliation, and Mission Control conventions outright instead of reimplementing them in Python.

This also keeps the **firm invariant** intact: *all live order submission is PHP; Python is stateless compute behind HTTP and never submits orders, never writes trading state.* The harness honors that exactly —

- **PHP owns:** the decision orchestrator (calls the model API for intent + narration only), the rail pipeline, the execution client (`RobinhoodMcpClient`, native MCP), the lifecycle monitor, reconciliation, JSONL/journal writes, and the Mission Control panel.
- **Python owns:** the Greeks/EV primitives (`tools/options-math/`), exposed as a **local FastAPI compute service** the PHP layer calls over HTTP. Pure functions in → numbers out. No state, no orders, no Redis.

Robinhood is modeled as **another venue/adapter on the state-machine pattern**, deployed as a **sibling command surface** (its own Laravel commands, scheduler entries, queue connection) — *not* injected into the live Kalshi/perp execution loop. We reuse the framework and the codebase patterns; we do not touch the running perp hot-path process, its scheduler entries, or its queues.

### 9.2 Host placement — same box, isolated compose stack

**Decision: run on the existing `edge-bot-prod-us-1` box (`ash`/us-east) as theta's own Docker Compose project — not a separate VM.** The earlier draft provisioned a second `ash` node to resolve the operator's strain concern. The `ccx33` upgrade (8 dedicated vCPU / 32 GB RAM, ~26 GiB free after the swap-pressure fix) removed that premise: the box now absorbs theta's modest footprint (a small MySQL schema, a light Greeks FastAPI, a few queue workers, a telemetry API) with surplus. A second VM solely to dodge load the box now carries easily is ongoing ops cost (another host to provision, Tailscale-join, patch, monitor) buying nothing at this stage.

Because prod runs on **Docker Compose**, same-box does not mean shared-services. theta is a **separate compose project** — its own containers, its own bridge network, joined to nothing in the perp stack. That yields container-level service isolation without a second machine:

- **Hard requirement — theta gets its own Redis container.** It **never** shares the perp hot path's Redis. A key collision, memory bloat, or a stray `FLUSHALL` in an early-stage harness must not be able to reach the live book's `kalshi:live:*` keys or perp queues. Separate compose project makes this automatic.
- **Hard requirement — CPU/memory limits on theta's containers** (compose `deploy.resources` / `mem_limit` + `cpus`), so a runaway theta process cannot starve the perp path at market open. Cheap insurance against the one shared resource (kernel/CPU/RAM).
- **Own MySQL, own Horizon, own scheduler** — separate containers/connections; theta's queues and cron never mingle with perp's.
- **Deploy isolation requirement — no Kalshi/perp restarts.** A theta deployment may recreate theta containers only. It must not invoke the perp compose project, restart edge-bot services, run perp migrations, or attach theta to the perp network.
- **Docker parity requirement — local equals deploy.** Build, dependency installation, migrations, tests, and service commands run through `docker compose -p theta` locally first, then the same compose project is deployed on Hetzner. Host-level Composer/npm/pip installs are not the implementation path.

**Isolation we keep vs. give up:**
- *Keep:* deploy blast-radius isolation (separate repo + separate hook — a theta push cannot deploy/migrate toward the perp stack), operating-context isolation, and container-level service isolation (Redis/MySQL/queues).
- *Give up (accepted at v0):* **hardware fault isolation** — a box reboot or failure now takes perp *and* theta down together (and reboots here need the known `docker compose down && up -d` network-rebuild dance — see the Hetzner reboot gotcha). Tolerable for the first live equity phase; both trade overlapping US hours but neither is so critical that correlated downtime is unacceptable yet.

**Scale option:** splitting theta back onto its own `ash` box later is a provision-plus-deploy-retarget — hours, not a code reshuffle — *precisely because the repo and compose stack stay separate now*. We preserve the option without paying for it until capacity pressure or capital scale warrants hardware fault isolation. Live `ash` capacity for that future box is confirmed open (all CPX/CCX `Available: true` as of 2026-06-11).

### 9.3 Auth & session model — always-on characterization

The harness holds Robinhood's OAuth credential directly (it's *our* native MCP client, not Claude Code's). From Robinhood's own docs, exactly one hard gate is published, and it's narrow:

> "You can only open an agentic account and authenticate your agent on a **desktop device**."

That is the **one-time human authorization** (open the agentic account, grant the agent). Robinhood does **not** publish session TTL, refresh-token behavior, or rate limits for the agentic MCP. (Do **not** model ours on the old unofficial `robin_stocks`/`pyrh` password API's "8.5-day token" or the "phone-2FA-every-login" community servers — different auth path; the agentic MCP is OAuth at `agent.robinhood.com/mcp/trading`.)

The always-on measurement is **whether the access token re-mints headlessly from a refresh token, and the TTL of each.** Standard OAuth says refresh tokens exist precisely for unattended re-mint, but a financial agentic surface may scope it tighter — so theta measures rather than assumes. This is §13 task #1: capture the token grant (access TTL / refresh TTL / scope), then run a controlled expiry probe so the live harness can refresh cleanly. The capital firewall (agent can read all accounts, trade only the `agentic_allowed` cash account) and auto-mode (place without per-trade confirmation) are both already confirmed server-side, so this auth-lifecycle probe is an always-on hardening task, not a reason to avoid live equity execution.

## 10. Observability & dashboard — independent of broker

Broker stays truth for reconciliation, but the operator's history, rationale, and decision log live in the harness's own store and survive independent of anything Robinhood shows:

- **System-of-record:** JSONL append log + `robinhood_trade_journal` (durable candidate→outcome ledger; schema already exists and is being widened).
- **Decision log:** every intent records inputs, the deterministic primitive outputs (Greeks snapshot at decision time), the rail verdicts, the chosen contract, and the model's rationale. Rejected and watchlisted-but-not-entered candidates are retained (needed to measure whether gates are too strict/loose — per companion plan §Performance Tracking).
- **Mission Control panel:** read-only cockpit rendering the decision log, per-entry rationale, and full history; reconciles against `placed_agent='agentic'` broker reads.

## 11. Options lane (broker-capability gated; build now)

Build now, while live equity execution proves the harness:

- The deterministic options brain — Greeks engine (`tools/options-math/`) + the lifecycle monitor — is pure computation; unit-test it against live market data now.
- The candidate/journal/watchlist infrastructure — fully specified in the companion plan `docs/options-infrastructure-plan.md`; build the journal writer, math-payload normalizer, playbook→journal extractor, validation command, and watchlist-pending report now.

Option order submission activates when Robinhood exposes the required tools (`get_option_instruments`, `review_option_order`, `place_option_order`, `get_option_orders`) and the agentic account has options permission. That is a broker/account capability gate, not a reason to slow the equity harness.

## 12. Phasing / build sequence

1. **Live equity substrate harness:** `RobinhoodExecutionClient` + rail pipeline + idempotency persistence + reconciliation + JSONL/journal + MC panel, driven by live bounded equity intent in the sandboxed agent account. **This is the spine; everything else plugs into it.**
2. **Deterministic options brain (parallel):** wrap `tools/options-math/` as the primitive layer; build the lifecycle monitor; validate against market data while equity execution is live.
3. **Nightly playbook + auto:** scheduler, plan artifact, veto window — on the live equity path first.
4. **Intraday PHP sibling:** state-machine-pattern venue on the reused Laravel stack (own compose project on `edge-bot-prod-us-1`), lifecycle monitor live on equities.
5. **Options order path:** when RH exposes the tools and the account is upgraded, slot the options adapters into the proven spine.

## 13. Open boundaries to hash out

1. **Headless auth / session-TTL characterization.** Empirically pin the agentic-MCP OAuth lifecycle: access-token TTL, whether a refresh token is issued and its TTL, whether re-mint works without desktop presence, and the failure mode on expiry. Capture the grant, then run a controlled expiry probe so always-on operation is hardened. (§9.3.)
2. Decision-layer ↔ execution-layer **intent object schema** (the typed PHP contract between the model orchestrator and the rail/execution layers).
3. **PHP ↔ Python compute boundary** — the request/response schema for the local Greeks/EV FastAPI service (stateless, no orders), and how its outputs are stamped into the decision log.
4. **Lifecycle monitor placement** — its own Laravel command/queue vs. folded into the intraday loop; crash/restart ownership of protective legs.
5. **Bankroll allocation config** — exact per-day / per-play caps and the settled-cash accounting model for the cash account.
6. **Eval harness** — the reference-vs-clone evals that prove the encoded judgment matches operator intent.
7. ~~One-time confirmation of the Kalshi box region~~ **— RESOLVED 2026-06-11.** `hcloud` confirms `edge-bot-prod-us-1` is in `ash` (us-east); it's now `ccx33` (8 dedicated vCPU / 32 GB). This is the box theta co-locates on (§9.2).

## 14. Non-goals

- No model on the execution path. The model proposes intent; deterministic rails decide.
- Do not bypass the MCP to Robinhood private REST.
- Do not route option orders until RH review/place tools exist (companion plan §Non-Goals).
- Reuse the PHP/Laravel stack and patterns, but do **not** inject this venue into the running Kalshi/perp hot-path process, its scheduler entries, or its queues — it is a sibling command surface in its **own Docker Compose project** on the same box (§9.1–9.2).
- **Never share the perp hot path's Redis or queues.** theta runs its own Redis/MySQL/Horizon containers; no logical-DB sharing of the live book's `kalshi:live:*` keyspace. Enforce CPU/memory limits on theta's containers.
- **Never restart Kalshi/perp services as part of theta deployment.** The deploy boundary is theta repo → theta worktree → theta compose project.
- Keep Python stateless: the Greeks/EV service is compute-behind-HTTP only — it never submits orders or writes trading state (firm invariant).
- Do not adopt operator manual positions (the harness only touches its own `placed_agent='agentic'` orders).
- Do not loosen the existing EV / kill-trigger rules to manufacture volume.
