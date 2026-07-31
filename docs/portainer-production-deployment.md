# Portainer production deployment

This runbook deploys the reporting platform as a Git-backed Portainer stack on
Docker Standalone. The Git repository is the source of truth. Do not edit the
Git-backed Compose definition directly in Portainer.

## Architecture

The `report-automation` stack contains:

- `nginx`: localhost-bound HTTP entry point for the host HTTPS proxy;
- `app`: Laravel PHP-FPM application;
- `queue-worker`: long-running report generation worker;
- `scheduler`: Laravel scheduler;
- `report-engine`: private Python and Playwright rendering service;
- `postgres`: private PostgreSQL database;
- `redis`: private sessions, cache, queue and locks.

Only Nginx publishes a host port. PostgreSQL, Redis and the report engine are
reachable only on the internal Docker network. Uploaded workbooks and generated
artifacts use the private `reports_data` volume shared by Laravel and the report
engine.

## Prerequisites

- Docker Engine and Compose v2 on a dedicated or approved host.
- A Portainer account allowed to manage stacks on that environment.
- At least 4 CPU cores, 8 GB RAM and 50 GB persistent storage.
- An internal DNS name and HTTPS reverse proxy.
- Company VPN, private network or approved IP allowlist.
- A private GitHub repository credential restricted to read-only repository
  contents.
- A tested destination for encrypted off-host backups.

## Generate secrets

Generate separate values. Never reuse or commit them:

```bash
openssl rand -base64 48
openssl rand -base64 48
docker compose run --rm app php artisan key:generate --show
```

The first two values can be used for PostgreSQL and Redis. The Laravel key starts
with `base64:`.

## Create the stack

In Portainer:

1. Select the Docker environment.
2. Open **Stacks** and choose **Add stack**.
3. Name the stack `report-automation`.
4. Choose **Git repository**.
5. Use the repository URL
   `https://github.com/Tamanen23/reporting_dashboard.git`.
6. For a private repository, enable authentication and use a fine-grained GitHub
   token restricted to this repository with read-only Contents and Metadata.
7. Use repository reference `refs/heads/main`.
8. Use Compose path `compose.production.yml`.
9. Keep TLS verification enabled.
10. Leave GitOps automatic updates disabled for the first deployment.

Add every variable from `.env.production.example` in Portainer's **Environment
variables** section and replace all placeholder values. Required variables are:

| Variable | Production value |
|---|---|
| `APP_NAME` | Human-readable application name |
| `APP_KEY` | Unique Laravel `base64:` key |
| `APP_URL` | Final HTTPS URL |
| `APP_BIND_ADDRESS` | `127.0.0.1` |
| `APP_PORT` | `8088`, or the approved unused local port |
| `POSTGRES_DB` | `report_automation` |
| `POSTGRES_USER` | Dedicated database user |
| `POSTGRES_PASSWORD` | Unique random secret |
| `REDIS_PASSWORD` | Different unique random secret |
| `REDIS_QUEUE_RETRY_AFTER` | `1800` |

Deploy the stack. The first image build downloads PHP, Node, Python, Playwright
and browser dependencies and can take several minutes.

## Initialize the database

After PostgreSQL and Redis are healthy, open the `app` container's **Console**,
connect with `/bin/sh`, and run:

```bash
php artisan migrate --force
php artisan reports:sync
php artisan optimize
php artisan app:create-admin
```

The administrator command prompts for the password without printing it. It
requires at least 12 characters with mixed case, numbers and symbols.

Never run `php artisan db:seed` as the production bootstrap. Development users
are intentionally not created outside the local environment, but
`reports:sync` is the explicit production operation.

Restart `app`, `queue-worker`, `scheduler` and `nginx` after initialization.

## Configure the host proxy

The stack listens only on `127.0.0.1:8088` by default. Configure the host or
provider reverse proxy to:

- terminate HTTPS for the approved internal hostname;
- redirect HTTP to HTTPS;
- proxy to `http://127.0.0.1:8088`;
- preserve `Host`, `X-Forwarded-For` and `X-Forwarded-Proto`;
- allow request bodies up to 64 MB;
- restrict access through the company VPN/private network/IP allowlist.

Do not publish ports 5432, 6379 or 8000.

## Acceptance checks

All services should be running and the services with health checks should be
healthy. Inspect logs for `app`, `queue-worker`, `scheduler` and
`report-engine`.

Validate:

1. `/up` returns a successful response through HTTPS.
2. Unauthenticated users cannot access report pages or downloads.
3. Login throttling activates after repeated failures.
4. Registration CSV and XLSX reports complete.
5. Payments, Cash Operations and Player Activity reports complete.
6. Overall Performance accepts only compatible source generations.
7. PDF and PNG rendering completes.
8. An empty excluded-date field adds no exclusion.
9. An explicit exclusion is displayed and removed from calculations.
10. A worker restart does not lose queued jobs.

## Updating from Git

Use reviewed commits that passed CI:

1. Back up the database and private reports.
2. In Portainer, open **Stacks → report-automation**.
3. Choose **Pull and redeploy**.
4. Rebuild the application images.
5. Open the new `app` container console and run:

   ```bash
   php artisan migrate --force
   php artisan reports:sync
   php artisan optimize
   php artisan queue:restart
   ```

6. Confirm all health checks and run a sample report.

Enable GitOps polling or a deployment webhook only after backups, migrations,
health checks and rollback have been tested. Automatic deployment should follow
successful CI, not every unreviewed push.

## Backups

The operations administrator can use:

```bash
ENV_FILE=.env.production ./scripts/backup-production.sh
```

This creates a timestamped PostgreSQL custom dump, a compressed copy of private
reports, the deployed Git commit and SHA-256 checksums. Copy the result to an
encrypted off-host destination.

Restore only during an approved maintenance window:

```bash
ENV_FILE=.env.production ./scripts/restore-production.sh /absolute/backup/path
```

The restore requires an explicit `RESTORE` confirmation. Test restoration on a
non-production environment at least monthly.

## Operational security

- Portainer administrators effectively control the Docker host.
- Do not grant Portainer access to ordinary report users.
- Keep repository credentials read-only and rotate them.
- Keep stack environment variables out of screenshots and support tickets.
- Do not mount the Docker socket into application services.
- Do not run services in privileged mode.
- Retain Docker and application logs for the approved audit period.
- Monitor disk usage, failed jobs, queue age, container restarts and backup age.
