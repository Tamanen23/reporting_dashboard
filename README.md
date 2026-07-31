# Betnabiso Report Automation

A queue-driven reporting application that validates XLSX exports and generates
deterministic HTML, PDF, PNG, Parquet and JSON dashboard artifacts.

Laravel handles authentication, report selection, private file storage, history,
downloads and queue orchestration. The Python report engine performs workbook
validation, normalization, KPI calculations, reconciliation, charts and rendering.

## Implemented dashboards

- Registration Dashboard
- Deposits, Withdrawals and Bonus Dashboard
- Cash Operations Dashboard

Player Activity and Overall Performance remain registered but inactive.

## Architecture

```text
Browser → Nginx → Laravel → PostgreSQL
                    │  ↘ private report storage
                    ↓
                 Redis queue → Python report engine → Playwright
```

Raw uploads and generated reports are private runtime data and are never intended
to be committed to Git.

## Requirements

- Docker Desktop with Docker Compose v2
- Git
- At least 4 GB of available Docker memory

## Fresh-clone setup

```bash
git clone <repository-url>
cd report_automation
cp .env.example .env

docker compose build app report-engine
docker compose run --rm app composer install
docker compose run --rm app php artisan key:generate
docker compose up -d postgres redis
docker compose run --rm app php artisan migrate --seed
docker compose up -d
```

Open [http://localhost:8088/login](http://localhost:8088/login).

Local development credentials:

```text
Email: test@example.com
Password: password
```

These credentials and the database password in `.env.example` are for local
development only. Replace them before any shared or production deployment.

## Common commands

```bash
# Container status and logs
docker compose ps
docker compose logs -f app queue-worker report-engine

# Laravel tests
docker compose exec app php artisan test

# Sync report definitions after config changes
docker compose exec app php artisan db:seed --force

# Stop the application
docker compose down
```

Python development outside Docker:

```bash
cd report-engine
python -m venv .venv
source .venv/bin/activate
pip install -e ".[dev,render]"
playwright install chromium
pytest
```

## Upload limits

Nginx and PHP accept requests up to 64 MB. Laravel validates individual workbook
uploads at a maximum of 50 MB.

## Data and secrets

- Never commit `.env`.
- Never commit source workbooks or generated dashboards.
- Runtime reports live under `storage/app/private/reports`.
- Production should use private object storage and managed credentials.
- Reference-image comparisons are audit metadata only and never modify workbook
  calculations.

See [SECURITY.md](SECURITY.md) before sharing the repository.

## Documentation

- [Architecture](docs/architecture.md)
- [Database schema](docs/database-schema.md)
- [Report lifecycle](docs/report-processing-lifecycle.md)
- [Adding a report type](docs/adding-a-report-type.md)
- [Implementation audit](docs/current-implementation-audit.md)
- [Portainer production deployment](docs/portainer-production-deployment.md)

## Production deployment

Production uses the separate `compose.production.yml` stack. It builds immutable
application images, keeps PostgreSQL, Redis and the report engine private, binds
Nginx to localhost, persists report data, and runs dedicated queue and scheduler
services. Do not deploy the development `docker-compose.yml` to a server.

Validate the production definition locally:

```bash
docker compose --env-file .env.production.example -f compose.production.yml config --quiet
docker compose --env-file .env.production.example -f compose.production.yml build
```
