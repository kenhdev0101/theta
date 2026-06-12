# Theta Implementation Kickoff Prompt

Use this prompt for the implementation agent.

```text
You are implementing theta, not planning theta.

Repository:
- Local repo: /Users/kendallhayes/projects/theta
- GitHub origin: git@github.com:kenhdev0101/theta.git
- Deploy remote: edge-bot:~/theta.git
- Deployment target: shared edge-bot production box, but theta is its own boundary:
  - worktree: /srv/theta
  - compose project: docker compose -p theta
  - services: app/web/horizon/scheduler/greeks/redis/mysql
  - do not restart, reload, migrate, or touch Kalshi/perp services, Redis, MySQL, workers, Horizon, scheduler, web, or Docker networks.

Read first:
- CLAUDE.md
- docs/architecture.md
- docs/bootstrap.md
- docs/intraday-structure-engine.md
- docs/options-infrastructure-plan.md

Operating posture:
We are going live through the sandboxed Robinhood agent account. Do not introduce shadow/no-op/review-only staging language. The guardrails are the sandboxed agent account, deterministic rails, idempotency, settled-cash checks, kill switch, and broker-truth reconciliation. Fixture transports are for unit tests only. Integration proof is live broker/account truth.

Docker rule:
We use Docker. The same theta containers used locally are the containers deployed on the Hetzner box. Do not install Composer packages, Laravel, npm packages, Python packages, or Python virtualenvs directly on the Mac or Hetzner host as the implementation path.

The first implementation artifact is Docker:
- docker-compose.yml
- docker/app/Dockerfile
- docker/web/default.conf
- services/greeks/Dockerfile

All dependency/framework commands must run inside theta containers, for example:
- docker compose -p theta build
- docker compose -p theta up -d
- docker compose -p theta run --rm app composer create-project laravel/laravel /tmp/theta-laravel
- docker compose -p theta run --rm app composer require ...
- docker compose -p theta run --rm app php artisan ...
- docker compose -p theta run --rm app vendor/bin/phpunit
- docker compose -p theta run --rm greeks pip install -r requirements.txt

If a host-level command is used as an emergency diagnostic, capture the result back into Dockerfiles, docker-compose.yml, composer.json, composer.lock, requirements.txt, or committed source immediately. The container is the source of truth.

Implement the bootstrap across the board.

Required first implementation pass:

1. Create the theta Docker scaffold first:
   - docker-compose.yml
   - docker/app/Dockerfile
   - docker/web/default.conf
   - services/greeks/Dockerfile
   - isolated theta network and volumes
   - app/web/horizon/scheduler/greeks/redis/mysql services
   - per-container CPU/memory limits
   - telemetry port on 127.0.0.1:8790
2. Bootstrap Laravel 12 inside the app container into a temporary container path, then copy the skeleton into the repo without overwriting existing docs, CLAUDE.md, .env, .gitignore, or git metadata.
3. Add the headless stack dependencies from docs/bootstrap.md from inside the app container:
   - Horizon
   - Sanctum
   - laravel/ai if applicable
   - Pint/PHPStan/PHPUnit baseline
   - no frontend build unless required for telemetry API only.
4. Add config/harness.php with:
   - HARNESS_MCP_ENDPOINT
   - HARNESS_AGENTIC_ACCOUNT
   - HARNESS_MODE=auto default
   - allocation caps
   - kill switch
   - Alpaca env keys already present in .env:
     ALPACA_DATA_KEY_ID
     ALPACA_DATA_SECRET_KEY
     ALPACA_DATA_FEED=sip
     ALPACA_DATA_BASE_URL
     ALPACA_DATA_STREAM_URL
5. Port/copy the Robinhood journal model and migrations from edge-bot as specified in docs/bootstrap.md.
6. Port/copy tools/options-math and tools/equity-structure from edge-bot.
7. Add the first service skeletons:
   - app/Robinhood/Mcp/RobinhoodMcpClient.php
   - app/Robinhood/Mcp/McpTransport.php
   - app/Robinhood/Mcp/OAuthTokenStore.php
   - app/Robinhood/Mcp/OAuthBootstrapClient.php
   - app/Robinhood/Rails/RailPipeline.php
   - app/Robinhood/Execution/EquityExecutor.php
   - app/Robinhood/Reconciliation/BrokerTruthReconciler.php
   - app/Robinhood/Telemetry/JsonlRecorder.php
   - app/MarketData/AlpacaMarketDataClient.php
8. Add commands:
   - theta:alpaca-smoke-test
   - theta:rh-oauth-init
   - theta:rh-oauth-listen
   - theta:rh-oauth-complete
   - theta:rh-oauth-status
   - theta:broker-readback
   - theta:live-equity-probe
   - theta:reconcile
9. Add focused tests for:
   - config propagation into command/service cfg arrays
   - rail pipeline allow/block outcomes
   - idempotency ref_id persistence/reuse
   - reconciliation label conflict for filled_qty=0
   - Alpaca client config assembly without exposing secrets
10. Add deploy/post-receive that only touches theta:
   - checkout /srv/theta from /home/edge/theta.git
   - docker compose -p theta -f /srv/theta/docker-compose.yml build
   - docker compose -p theta -f /srv/theta/docker-compose.yml run --rm app composer install --no-dev --optimize-autoloader
   - migrate theta DB only
   - cache theta config/routes only
   - up -d theta services only
   - never invoke edge-bot/perp compose project.

Do not stop after proposing. Implement files, run focused tests or the closest available verification, commit, and push to both:
- origin main
- theta main

If a command fails because dependencies are missing, add the missing dependency to the Docker image or project manifests and rebuild the container. If network or permission blocks a required command, request the exact approval and continue.

OAuth readback already proven on 2026-06-12:

```text
Robinhood MCP endpoint: https://agent.robinhood.com/mcp/trading
OAuth scope: internal
authorization endpoint: https://robinhood.com/oauth
PKCE: S256
local redirect/listener: http://127.0.0.1:8787/callback
encrypted grant: storage/harness/oauth_token.enc
broker readback: succeeded against pinned account 872449178
```

Do not ask the next agent to hunt for Codex/Claude connector tokens. Their MCP settings contain the Robinhood server URL, but not an exportable Robinhood bearer. theta owns its own OAuth grant through `theta:rh-oauth-*`.

Final readback must include:
- files changed
- Docker commands run
- tests/verification run
- whether .env stayed untracked
- current commit hash
- confirmation that origin/main and theta/main match
- next concrete live step
```

## Status Addendum — 2026-06-12

The bootstrap above is implemented and live. Options addendum:

- Options approval blocker cleared 2026-06-12: pinned Agentic account
  872449178 reports `option_level_2`, `agentic_allowed=true` (live
  capability readback: OPTIONS_READY).
- Supported first strategies: long calls / long puts (BTO), sell-to-close.
  No multi-leg spreads. No model contract selection — the deterministic
  resolver picks the contract.
- Review probes (`theta:option-review-probe`) are allowed; live placement
  waits for explicit operator invocation of `theta:option-live-place`.
- Broker truth remains the source of truth; option fills require
  broker_order_id + option_id + filled qty/price > 0.
