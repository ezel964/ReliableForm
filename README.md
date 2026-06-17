# ReliableForm

A small JotForm-style form builder whose **real purpose is the production topology**. It is an SRE lab bench: nginx → replicated PHP web tier → Node status service → MySQL/Redis/gearmand → PHP CLI workers — instrumented so SLIs, health checks, queues, load balancing, and failure modes can be defined and broken on a stack we fully control.

```
                      ┌──────────────────────────┐
 browser ──:8080──▶   │  Nginx (load balancer)   │
                      └────┬──────────┬──────────┘
               /status,/api/*         │ everything else (least_conn)
                      ┌────▼─────┐ ┌──▼───────┐ ┌──────────┐
                      │ status    │ │ web1     │ │ web2     │
                      │ Node :9301│ │ PHP :9001│ │ PHP :9002│
                      └──┬───┬────┘ └──┬───┬───┘ └──┬───┬───┘
                         │   │         │   │        │   │
                   MySQL ◀───┼─────────┘   └───┬────┘   │
                    :3306    │                 │        │
                             ▼                 ▼        ▼
                          Redis :6379  (sessions · cache · queues · heartbeats)
                             ▲               ▲                ▲
                      ┌──────┴─────┐  ┌──────┴───────┐  ┌─────┴──────────┐
                      │ pdf worker │  │ email worker │  │ webhook worker │
                      │  PHP CLI   │  │   PHP CLI    │  │    PHP CLI     │
                      └────────────┘  └──────────────┘  └────────────────┘
```

**Start with [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)** — ports, Redis keys, queue protocol, route table, DB schema, JSON envelopes, worker contract. Everything else builds on that.

## Quick start

**Prerequisites:** Podman + compose provider (default infra mode). Docker is not used.

```bash
# macOS
brew install podman podman-compose
podman machine init && podman machine start

# Linux (Debian/Ubuntu)
sudo apt-get install podman podman-compose
```

Stop host MySQL/Redis if they already bind 3306/6379 (Podman publishes the same ports).

```bash
bash setup.sh    # one time: deps, DB, seed data. --yes for CI
bash launch.sh   # start stack, print ✓/✗ health table. --yes for CI
```

| URL | What |
|---|---|
| http://localhost:8080 | App (through LB) |
| http://localhost:8080/status | Live dashboard |
| http://localhost:8080/v1/forms | REST API v1 — see [docs/API.md](docs/API.md) |

**Demo login:** `demo@reliableform.dev` / `demo1234`  
**API key:** `deadbeefdeadbeefdeadbeefdeadbeefdeadbeef`

`INFRA_RUNTIME` in `.env`: `podman` (default), `auto` (fallback to host), or `host`. The app tier (web, status, workers, nginx) runs as host processes; only MySQL/Redis/gearmand are containerized by default. Optional whole-stack Podman: `bash launch.sh full up|down`.

```bash
bash launch.sh stop|restart|status|logs <svc>|kill <svc>
bash launch.sh stop --infra          # also stop podman backing services
```

Services: `web1 web2 status worker-pdf worker-email worker-webhook otel nginx gearmand`.

## Tests

```bash
bash tests/run-unit.sh
(cd apps/status && npm test)
bash tests/e2e.sh                # needs a running stack — see docs/TESTING.md
```

Optional frontend telemetry build (PHP app runs fine without it):

```bash
cd frontend && pnpm install && pnpm --filter @rf/telemetry-entry build
bash launch.sh restart
```

## Docs

| Doc | Contents |
|---|---|
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Integration contract — read this first |
| [docs/API.md](docs/API.md) | REST v1, webhooks, CSV export |
| [docs/RUNBOOK.md](docs/RUNBOOK.md) | Drills, queue/webhook surgery, migrations |
| [docs/SRE-GUIDE.md](docs/SRE-GUIDE.md) | Telemetry, traces, SLI candidates, loadgen A/B |
| [docs/METRICS.md](docs/METRICS.md) | Every metric, log event, and span |
| [docs/TESTING.md](docs/TESTING.md) | Unit, Node, frontend, and e2e suites |

Failure drills (`launch.sh kill <svc>`, webhook sink, Redis shutdown): [docs/RUNBOOK.md](docs/RUNBOOK.md).

## Layout

```
apps/web/      PHP web app (routes, pages, /v1 API)
apps/status/   Node status & analytics (mysql2 only)
apps/workers/  pdf, email, webhook, otel shipper (PHP CLI)
frontend/      optional pnpm workspace — browser telemetry today
lib/           shared PHP kernel (do not redefine; see ARCHITECTURE)
config/        nginx template, podman compose
db/            schema.sql, seed.sql, migrations/
docs/          operator & developer docs (table above)
storage/       runtime logs, pdfs, mail, uploads
```

No composer, no PHP frameworks. Small and readable on purpose — a lab bench, not a product.
