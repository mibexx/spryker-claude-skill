# Release/Deployment Checklist

Tick off before delivery. Derived from [Docker SDK Workflow](../references/30-docker-sdk-workflow.md), [Performance/Caching](../references/25-performance-caching.md), [Tests](../references/26-tests.md), [Coding Guidelines](../references/27-coding-guidelines.md), [Upgradability](../references/28-upgradability.md), [Security/Session](../references/21-security-session.md) and [Configuration](../references/06-configuration.md). All commands were executed in the container `[live verified (202610.0)]` (console, `docker/sdk` lifecycle, Composer, Evaluator); only destructive commands (`reset`, `clean-data`, `up --data`) were not executed.

## Quality and tests (gate)
- [ ] `code:sniff:style`, `code:phpstan` (level 6), `code:sniff:architecture` (core ruleset) and `phpmd` with the project ruleset without findings ([Coding Guidelines](../references/27-coding-guidelines.md))
- [ ] Codeception runs green: functional, api (after `codecept fixtures` and `queue:worker:start --stop-when-empty`), acceptance/Cypress if applicable ([Tests](../references/26-tests.md))
- [ ] Evaluator for the release branch: `vendor/bin/evaluator evaluate --format=compact` without findings in project code; security findings assessed ([Upgradability](../references/28-upgradability.md))
- [ ] `composer.lock` up to date, no dev packages in `require` (production installed with `--no-dev`)

## Configuration and secrets ([Configuration](../references/06-configuration.md))
- [ ] `deploy.yml` (prod) instead of `deploy.dev.yml`; `environment: docker.production`; changes from other deploy files carried over (all are full copies)
- [ ] Debug/WebProfiler/Xdebug off (`docker.debug.enabled: false`, `xdebug.enabled: false`, `IS_WEB_PROFILER_ENABLED` false, no `SPRYKER_DEBUG_ENABLED`)
- [ ] No demo secrets: `change123` (Product Configurator key), `abc123` (OAuth client secret), `password: secret`, `dev_only_*.key`; secrets only via the platform's env variables
- [ ] `config_local*.php`, `console_env_local.php`, `docker/` not in the repository
- [ ] SecurityBlocker, session cookie (`SameSite`), Remember-Me secret, OAuth keys set for production ([Security/Session](../references/21-security-session.md))
- [ ] Mail sender, SMTP variables, message broker endpoints (`SPRYKER_MESSAGE_BROKER_HTTP_CHANNEL_*_BASE_URL`) set or deliberately empty ([Mail](../references/24-mail-message-broker.md))
- [ ] New Relic/OpenTelemetry enabled as needed (`NEW_RELIC_ENABLED`, extension `otel`)

## Build and caches ([Docker SDK Workflow](../references/30-docker-sdk-workflow.md), [Performance/Caching](../references/25-performance-caching.md))
- [ ] Recipe sections `build`, `build-production`, `build-static-production` executed (`transfer:generate`, Propel build, router caches, `oms:process-cache:warm-up`, `twig:cache:warmer`, `navigation:build-cache`, `rest-api:build-request-validation-cache`, `translator:generate-cache`, `cache:class-resolver:build`, `twig:template:warmer`, frontend `-e production`)
- [ ] Autoloader with `--classmap-authoritative`
- [ ] Caches warmed after deploy (router, Twig, class resolver, navigation, OMS, REST validation, Glue controller)
- [ ] `IS_REPLICATION_ENABLED` set deliberately if DB replicas are used
- [ ] Generated folders not changed by hand

## Database and data
- [ ] Schema changes: `propel:diff` and `propel:migrate` checked (migration files in the repo), `propel:migration:check` planned against the maintenance window
- [ ] New queues: `queue:setup`; new search sources: `search:setup:sources`; scheduler: `scheduler:setup` ([Console/Cron/Queue](../references/22-console-cron-queue.md))
- [ ] Glossary/data imports for new texts and configuration data (`data:import glossary` among others)
- [ ] Publish & Sync triggered for changed resources (`publish:trigger-events`), Storage/Search checked ([Publish & Sync](../references/07-publish-sync.md))
- [ ] No `destructive` recipe and no `reset`/`clean-data` on environments with production data

## Operations
- [ ] Health check reachable (`/health-check`), plugins for new infrastructure added ([Errors/Logging](../references/23-errors-logging-debugging.md))
- [ ] Log targets (stderr/stdout vs. files) and sanitize fields checked
- [ ] Scheduler/worker jobs registered for new console commands
- [ ] Rollback path defined (previous image/tag, migrations backward compatible) `[Recommendation]`
