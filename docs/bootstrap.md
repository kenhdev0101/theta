# Agentic Execution Harness — Repo Bootstrap Spec

Owner: operator
Agent ID: lead-codex
Scope: Stand up the new standalone repo (`theta`) for the Robinhood agentic execution harness — the one-time "warm-start" scaffolding
Status: ACTIVE — bootstrap in progress
Date: 2026-06-11
Last updated: 2026-06-12

Decisions locked this revision:
- **Repo name: `theta`.**
- **Host: same box as edge-bot prod (`edge-bot-prod-us-1`, `ash`), as theta's own Docker Compose project** — *not* a separate VM. The `ccx33` upgrade gave the box the headroom; container-level isolation (own Redis/MySQL/Horizon + resource limits) replaces host separation. (Revises §5/§7/§11 from the separate-node draft.)
- **Repo stays separate** (separate git repo + separate compose project) — deploy-blast-radius and operating-context isolation are repo-level, independent of box size.
- **Deployment boundary: theta only** — a theta deploy may update `/srv/theta`, run theta migrations, and restart/recreate containers in compose project `theta` only. It must not restart, reload, migrate, or otherwise touch the Kalshi/perp compose project.

## 0. Relationship to the architecture doc

This is the **how-to-stand-it-up** companion to `docs/architecture.md` (the what/why). That doc defines the inference/determinism law, the rails, the capital firewall, and the deployment topology (own Docker Compose stack on the existing `edge-bot-prod-us-1` box — arch §9.2). This doc is the executable scaffolding plan: repo identity, what moves over, directory layout, deploy mechanic, the same-host telemetry contract, and the ordered bootstrap sequence.

Decision settled (see arch doc §9.1–9.2): **new repo + own compose stack, same box** — not a fork of edge-bot, not a monorepo subtree, and not a second VM. Rationale recap: the harness's whole premise is isolation from the live Kalshi hot path. The *repo* split prevents deploy/operating-context re-coupling (a theta push can't deploy or migrate toward the perp stack); the *separate compose project* prevents service re-coupling (own Redis/MySQL/queues, never the perp's). The `ccx33` box now has the CPU/RAM headroom to host both, so a second VM would be recurring ops cost buying only hardware-fault isolation — deferred as a cheap-later scale option. We pay the one-time repo+compose tax; we skip the recurring second-box tax.

## 1. What "warm-started" means

We do **not** start cold. The genuinely Robinhood-specific code already written into edge-bot is verified-portable (no edge-bot base-class coupling) and moves over intact. Everything else is *patterns we re-apply*, not libraries we import. Edge-bot stays 100% Kalshi after the move.

### 1.1 Moves over (verified clean to relocate)

| Source (edge-bot) | Destination (new repo) | Change needed |
|---|---|---|
| `app/Edges/Models/RobinhoodTradeJournal.php` | `app/Models/RobinhoodTradeJournal.php` | Renamespace `App\Edges\Models` → `App\Models`. No other edits. |
| `database/migrations/2026_06_09_000001_create_robinhood_trade_journal_table.php` | `database/migrations/` | None (idempotent, no cross-table FK). |
| `database/migrations/2026_06_09_000002_widen_*_state_columns.php` | `database/migrations/` | None. |
| `database/migrations/2026_06_09_000003_widen_*_experiment_labels.php` | `database/migrations/` | None. |
| `tools/options-math/` (all scripts + `setup.sh` + `requirements.txt` + README) | `tools/options-math/` | None; rebuild `.venv` via `setup.sh` (or bake into the greeks container image). |
| `tools/equity-structure/` (trend_geometry, intraday_loop_scanner, relative_strength, breadth_engine, gap_classifier, liquidity_scanner, market_structure_scanner, earnings_regime, common, universe_tiers.json, README) | `tools/equity-structure/` | None to move; this is the prototyped structure-classifier + RS + universe substrate (anatomy doc §13). Re-home onto the real feed + add the transition layer in the engine, not here. |
| `docs/architecture.md` | `docs/architecture.md` | Move; fix relative refs. |
| `docs/options-infrastructure-plan.md` | `docs/options-infrastructure-plan.md` | Move. |
| `docs/bootstrap.md` (this file) | `docs/bootstrap.md` | Move once the repo exists. |
| `docs/playbook/` | `docs/playbook/` | Move (manual-track artifacts; ingest source). |

### 1.2 Ported, not moved (copy; leave edge-bot's original intact)

| Source | Why copy | Note |
|---|---|---|
| `app/Services/KellyService.php` | The Python `kelly_fraction.py` declares numerical parity with it; keep the harness self-contained so the parity invariant holds locally. | Leave edge-bot's copy untouched; the two are independent going forward. |

### 1.3 Re-implemented as patterns (NOT copied — built fresh against this repo's models)

- **JSONL system-of-record** writer/reader (the broker-truth-outranks-logs append log).
- **Broker-truth reconciliation** loop (`get_*_orders(placed_agent='agentic')` vs JSONL/journal).
- **Mission Control read-model** pattern (a never-throws tailing reader, like `PerpStateMachineReader`).
- **Post-receive deploy hook** (mirror edge-bot's bare-repo + checkout pattern; see §5).
- **Config-propagation test discipline** (test the command `$cfg` assembly, not just the service with a hand-built array — the firm's known inert-patch failure mode).

### 1.4 Stays in edge-bot (does not move)

Everything Kalshi/perp: the state machine, perp combination ledger, agent coordination queues, the war-room `CLAUDE.md`. None of it is in the harness's path.

## 2. Repo identity & conventions

| Item | Value / decision |
|---|---|
| **Name** | `theta` (**locked**). Pairs with edge-bot's vocabulary; names the thing it harvests (time decay). |
| **Visibility** | Private. |
| **Git host** | Bare repo on `edge-bot-prod-us-1` for deploy (`~/theta.git`, matching the existing `~/edge-bot.git` remote convention). GitHub origin is additive for backup/CI, not a bootstrap blocker. |
| **Branch model** | Single `main`, deploy-on-push to the `theta` remote only. No improvised feature branches; separate streams by staged files (matches operator practice). The Kalshi `hetzner` remote is **never** added here. |
| **License** | Private/none. |

## 3. Tech baseline (mirror edge-bot's stack)

| Layer | Choice | Pinned from edge-bot |
|---|---|---|
| Language | PHP | `^8.2` |
| Framework | Laravel | `^12.0` |
| Queues/workers | Horizon | `^5.46` |
| API auth (cross-box) | Sanctum | `^4.0` |
| **Decision-layer model client** | `laravel/ai` | `^0.2` — already a firm dep; the model orchestrator uses this, no new SDK |
| Lint/static | Pint, PHPStan | `^1.13`, `^2.1` |
| Tests | PHPUnit | `^11.0` |
| Greeks/EV compute | Python 3 + venv | standalone `tools/options-math/` |
| **Frontend** | **None in this repo** (headless) | see §6 — dashboard is a panel in edge-bot MC |

**Dropped vs edge-bot:** `inertiajs/inertia-laravel`, `tightenco/ziggy` (no frontend here), `ratchet/pawl` (no Kalshi WS), `spatie/laravel-backup` (optional later). The headless choice removes the vite build from the deploy hook — smaller, faster, lower blast radius.

## 4. Target directory structure

```
theta/
├── app/
│   ├── Models/
│   │   └── RobinhoodTradeJournal.php        # moved
│   ├── Robinhood/
│   │   ├── Mcp/
│   │   │   ├── RobinhoodMcpClient.php       # native PHP MCP client (JSON-RPC/HTTP, OAuth)
│   │   │   ├── McpTransport.php             # streamable-HTTP transport + retry
│   │   │   └── OAuthTokenStore.php          # encrypted token at rest; refresh lifecycle (arch §13 #1)
│   │   ├── Decision/
│   │   │   ├── DecisionOrchestrator.php     # laravel/ai → structured intent + narration ONLY
│   │   │   └── IntentObject.php             # typed contract (arch §13 #2)
│   │   ├── Primitive/
│   │   │   └── GreeksClient.php             # PHP → local Python FastAPI over HTTP
│   │   ├── Rails/
│   │   │   ├── RailPipeline.php             # the gate chain (arch §6)
│   │   │   └── Gates/ (Allocation, SettledCash, Sizing, Session, Review, Idempotency, KillSwitch)
│   │   ├── Execution/
│   │   │   └── EquityExecutor.php           # pinned account + persisted ref_id
│   │   ├── Lifecycle/
│   │   │   └── LifecycleMonitor.php         # re-mark vs exit loop; orphan-leg reconciliation
│   │   ├── Reconciliation/
│   │   │   └── BrokerTruthReconciler.php    # JSONL/journal vs MCP reads
│   │   └── Telemetry/
│   │       ├── JsonlRecorder.php            # system-of-record
│   │       └── DecisionLog.php              # inputs + primitive outputs + rail verdicts + rationale
│   ├── Console/Commands/                    # nightly playbook, intraday loop, reconcile, arm/kill
│   └── Http/Controllers/Api/                # read-only telemetry API for the MC panel (§6)
├── routes/
│   ├── console.php                          # scheduler entries (nightly + intraday cadence)
│   └── api.php                              # Sanctum-gated read-only telemetry endpoints
├── services/greeks/                         # thin FastAPI app wrapping tools/options-math
├── tools/options-math/                      # moved
├── config/harness.php                       # account pin, caps, flags, MCP endpoint
├── database/migrations/                     # moved
├── docker-compose.yml                       # theta's own compose project (php, horizon, scheduler, greeks, redis, mysql)
├── tests/
├── docs/
└── deploy/post-receive                      # the theta deploy hook (committed; installed on box)
```

## 5. Deploy mechanic (own compose project on the same box)

Same `git push`-to-prod muscle the operator already has, pointed at a **separate bare repo + separate compose project** on `edge-bot-prod-us-1`:

```
git remote add theta edge-bot:~/theta.git   # bare repo, own path
git push theta HEAD:main
```

Bare repo + work-tree checkout (matches edge-bot's `--git-dir` setup). `deploy/post-receive` runs, in order:

1. `git --work-tree=/srv/theta --git-dir=/home/edge/theta.git checkout -f main`
2. `docker compose -p theta -f /srv/theta/docker-compose.yml build`
3. `docker compose -p theta ... run --rm app php artisan migrate --force`
4. `docker compose -p theta ... run --rm app php artisan config:cache route:cache`
5. `docker compose -p theta ... up -d` (recreates only changed theta containers; Horizon/scheduler pick up new code on restart)

**No vite/npm step** (headless repo). Isolation that matters: `-p theta` is a **distinct compose project** — its own containers, network, Redis, and MySQL. This hook touches only theta's project; it cannot reach the perp stack's containers, network, or Redis. A theta migration runs against theta's MySQL only. The `hetzner` remote is never added here, so a theta push has no path to the perp deploy at all.

Deployment access criteria:

```text
allowed: git checkout into /srv/theta
allowed: docker compose -p theta ...
allowed: theta MySQL migrations only
allowed: theta Redis/Horizon/scheduler/app/web/greeks container restart or recreate
forbidden: docker compose against the Kalshi/perp project
forbidden: restarting edge-bot/perp workers, scheduler, Horizon, Redis, MySQL, or web containers
forbidden: migrations against edge-bot/perp databases
forbidden: joining theta containers to the perp Docker network
```

Same box is acceptable because the repository and compose project are the boundary solution. The deployment command itself must carry that boundary by using `-p theta` and theta paths only.

## 6. Telemetry contract — the "one dashboard" path

Same box, but separate compose projects sit on **separate Docker networks**, so "one pane of glass" is still a deliberate transport (not a shared DB read). Chosen model: **pull**, read-only, additive.

- **theta side:** expose `GET /api/telemetry/*`, Sanctum-token gated, published on a host port (e.g. `127.0.0.1:8790`):
  - `/positions` — open `placed_agent='agentic'` positions + marks
  - `/intents` — in-flight intents + rail verdicts (allow/block + reason)
  - `/decisions?since=` — decision-log tail (inputs, primitive outputs, chosen contract, rationale)
  - `/health` — kill-switch state, settled vs unsettled cash, error/loss-rate counters
- **edge-bot side:** add **one additive, read-only Inertia panel** to the existing Mission Control that fetches the above over the host loopback (`127.0.0.1:8790`, or `host.docker.internal` from inside the perp containers). It renders theta state; it cannot write or touch the perp path or theta's services. Blast radius ≈ "a panel might show an error." Read-only over a published port keeps the two compose projects otherwise fully isolated.
- **Fallback:** a standalone minimal Blade cockpit in the theta repo is available only if the MC panel creates an actual delivery conflict. Default remains the additive read-only Mission Control panel.

## 7. Runtime topology — theta's compose project on `edge-bot-prod-us-1`

All services are containers under compose project `theta`, on theta's own bridge network — **none joined to the perp stack**. Each carries a CPU/memory limit (`deploy.resources` / `cpus` + `mem_limit`) so theta can't starve the perp path at market open:

| Container | Role | Isolation note |
|---|---|---|
| `app` (php-fpm) + `web` (nginx) | telemetry API only; no public frontend | publishes `127.0.0.1:8790` for the MC panel |
| `horizon` | queue workers (decision, execution, reconcile) | own queues; never the perp's |
| `scheduler` (`schedule:work`) | nightly playbook + intraday cadence | own cron; never the perp's |
| `greeks` (Python FastAPI) | Greeks/EV compute, stateless, no orders | reachable only on theta's network |
| `redis` | queue + KV bus | **own container — NEVER the perp Redis** (`kalshi:live:*` protection) |
| `mysql` | `robinhood_trade_journal` + harness state | own container/volume |

Host-level Tailscale (already on the box) covers admin SSH; theta needs no Tailscale of its own. The only resources shared with perp are kernel/CPU/RAM/disk — bounded by the container limits and the `ccx33` headroom.

Operational boundary readback after every deploy:

```text
docker compose -p theta ps
docker compose -p theta logs --tail=<n> <changed-service>
confirm no edge-bot/perp container restart timestamps changed
confirm theta is not attached to the perp network
```

## 8. Config & secrets (`config/harness.php` + `.env`)

| Key | Purpose |
|---|---|
| `HARNESS_MCP_ENDPOINT` | `https://agent.robinhood.com/mcp/trading` |
| `HARNESS_OAUTH_TOKEN` (encrypted at rest) | RH agentic OAuth credential; **not in git**; refresh lifecycle = arch §13 #1 |
| `HARNESS_AGENTIC_ACCOUNT` | pinned cash account `872449178` (never model-selected) |
| `HARNESS_MODE` | `approval` \| `auto` (the nightly gate) |
| `HARNESS_ALLOC_PER_DAY` / `_PER_PLAY` | bankroll caps (settled-cash accounting) |
| `HARNESS_KILL_SWITCH` | global arm flag |
| `HARNESS_TELEMETRY_TOKEN` | Sanctum token for the edge-bot MC panel |
| `ALPACA_DATA_KEY_ID` | Alpaca market-data API key ID for the v0 SIP data plane |
| `ALPACA_DATA_SECRET_KEY` | Alpaca market-data API secret; **not in git** |
| `ALPACA_DATA_FEED` | `sip` for consolidated real-time equities data |
| `ALPACA_DATA_BASE_URL` | `https://data.alpaca.markets` |
| `ALPACA_DATA_STREAM_URL` | `wss://stream.data.alpaca.markets/v2/sip` |

OAuth token handling is the security-sensitive piece: store encrypted (Laravel `Crypt`), load into the MCP client at boot, and tie its refresh to the §13 #1 probe outcome before any unattended run.

## 9. Operating context — its own CLAUDE.md

The new repo gets a **scoped** `CLAUDE.md`, seeded from the architecture doc's laws — **not** the perp war-room standard (combination ledger, ARM_NOW queues, agent-ID coordination don't apply to a solo unattended equity/options harness). Carry over only the universally-true invariants:

- No model on the execution path; model proposes intent, deterministic rails decide.
- PHP submits orders; Python is stateless compute behind HTTP.
- Broker truth outranks logs; classify execution state explicitly; `filled_qty=0` is a label conflict.
- Capital firewall: only the pinned `agentic_allowed` cash account is reachable.
- Cost-stack / settled-cash discipline before any economic verdict.

## 10. CI / tests / local dev

- **Local dev loop:** sqlite + `php artisan serve` + fixture-backed broker transport for unit tests. Integration proof is live against the sandboxed Robinhood agent account.
- **CI:** Pint + PHPStan + PHPUnit. Use a local pre-push script immediately; add GitHub Actions when a GitHub origin is created.
- **Required test classes:** rail-pipeline exact-outcome tests, idempotency/ref_id replay, reconciliation label-conflict detection, and the **command `$cfg`-assembly regression** (the firm's inert-patch guard).

## 11. Bootstrap sequence (ordered — the actual checklist)

1. Execute this bootstrap sequence. Name, host, local bare repo, and data vendor are locked; dashboard/GitHub choices are additive and do not block scaffold work.
2. `laravel new theta` (Laravel 12); commit the clean skeleton.
3. Add deps (§3); remove the frontend deps; `composer install`.
4. **Move** the §1.1 files in; renamespace the journal model; `composer dump-autoload`.
5. **Port** `KellyService` (§1.2); build the Python FastAPI Greeks wrapper around `tools/options-math/`.
6. Write `config/harness.php` + `.env` (§8); seed the scoped `CLAUDE.md` (§9).
7. Author `docker-compose.yml` (§7): `app`/`web`/`horizon`/`scheduler`/`greeks`/`redis`/`mysql`, theta's own network, **per-container CPU/mem limits**, telemetry published on `127.0.0.1:8790`. Local dev uses fixtures for unit tests; integration proof uses the live agent account.
8. `php artisan migrate` (local) → journal table exists.
9. Build the **spine** from arch doc §12.1: `RobinhoodMcpClient` + `RailPipeline` + idempotency persistence + reconciliation + JSONL/journal, driven by a bounded live equity intent in the sandboxed agent account. Green focused tests first, then live broker/account truth.
10. On `edge-bot-prod-us-1`: create the bare repo `~/theta.git` + `deploy/post-receive` (§5); first `git push theta`. Verify the `theta` compose project comes up with its **own** Redis/MySQL — confirm no container/network/Redis overlap with the perp project (`docker compose -p theta ps`; check networks).
11. **First live broker pass:** point `RobinhoodMcpClient` at the real MCP, validate OAuth/account pin with `get_portfolio` and `get_equity_positions`, then run the first bounded live equity order through review → rail verdict → place → reconcile.
12. Add the read-only telemetry API + the edge-bot MC panel (§6). One dashboard.

After step 12 the spine is live, isolated, observable, and producing broker-truth equity readbacks from the sandboxed agent account; the deterministic options brain plugs into the same rail/reconciliation spine (arch §12.2).

## 12. Resolved Decisions And Additive Choices

Resolved:
- ~~Repo name~~ → **`theta`**.
- ~~Box sizing / separate node~~ → **same box** (`edge-bot-prod-us-1`, `ccx33`), own compose project. No new VM (arch §9.2). Second `ash` box deferred (capacity confirmed open if ever needed).
- ~~Local bare repo~~ → **`edge-bot:~/theta.git`**, remote name `theta`.
- ~~Market data vendor~~ → **Alpaca Algo Trader Plus / SIP** for v0; keys live in local `.env` as `ALPACA_DATA_*`.

Additive choices after scaffold:
1. **GitHub origin** — add for CI/backup when useful; bare-repo-on-box is sufficient for first stand-up.
2. **Dashboard** — additive panel in edge-bot MC is the default; standalone cockpit is a fallback only if the panel creates a real delivery conflict.

## 13. Non-goals (bootstrap-specific)

- Do not add the Kalshi `hetzner` remote to this repo.
- Do not copy edge-bot's perp/Kalshi code, ledgers, or war-room CLAUDE.md.
- **Do not share the perp compose project's Redis, MySQL, or network.** theta is its own `-p theta` project with its own services; never point theta at the perp Redis or join its network. Always set per-container CPU/mem limits.
- **Do not restart or reload Kalshi/perp services during theta deploys.** A theta deploy targets only theta's worktree, theta's database, and theta's compose project.
- Do not stand up a frontend build in the theta repo unless the dashboard decision flips to the standalone cockpit.
- Do not bypass the spine, reconciliation, or idempotency path for any live order. Live orders go through the deterministic harness, not ad hoc calls.
