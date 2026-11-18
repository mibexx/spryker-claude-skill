# Performance and caching

> Status: release 202610.0 (spryker/kernel 3.87.1). Constants, defaults and classes verified against the code. The detailed docs pages on chunk size and slow P&S could not be read for content (navigation only), hence `[code-verified, docs not cross-checked]`; whatever follows only from the docs outline is `[Assumption]`. Console commands executed in the container `[live verified (202610.0)]`; unregistered commands (`cache:delete-all`, `router:cache:warm-up:merchant-portal`, `benchmark:run`) were removed or marked as not registered. Corrected during the code cross-check: the command to remove the navigation cache is `navigation:cache:remove` (not `navigation:remove-cache`).

## Purpose

Overview of the performance rules backed by the code, of all cache types including build/clear commands, chunk sizes in Publish & Synchronization (P&S), the Propel replication cache and performance measurement.

## Concepts

**Rules backed by the code** (docs: `architecture-performance-guidelines`, outline: widgets, Zed WebProfiler, Redis/Valkey, N+1, batches, chunk size, workers, external requests, APM):
- Yves only reads from Redis/OpenSearch, no Zed call in the request path (→ [Client/Storage/Search](08-client-storage-search.md), [Architecture](01-architecture.md)). ZedRequest only for write actions (cart, checkout).
- Multiple reads: `StorageClientInterface::getMulti(array $keys)` instead of single `get` calls in loops.
- Storage request cache in the Client: `StorageConfig::isStorageCachingEnabled()` = true, TTL `getStorageCacheTtl()` = 86400, strategies `STORAGE_CACHE_STRATEGY_REPLACE|INCREMENTAL|INACTIVE`; incremental from key size 1000 (`getStorageCacheIncrementalStrategyKeySizeLimit`). Detailed semantics `[Assumption]`.
- Avoid N+1: bulk plugins/repositories instead of loops with single queries (examples `*SynchronizationDataBulkPlugin`/`…BulkRepositoryPlugin` in `src/Pyz/Zed/Synchronization/SynchronizationDependencyProvider.php`).

**Cache types.**

| Cache | Mechanism | Switch / command |
|---|---|---|
| Router Yves | `Spryker\Yves\Router\RouterConfig::getRouterConfiguration()` sets `cache_dir` (CompiledUrlMatcher); default `src/Generated/Yves/Router/codeBucket<BUCKET>/…`; in the dev router `cache_dir` is null | `RouterConstants::YVES_IS_CACHE_ENABLED` (default true), `YVES_CACHE_PATH`; `vendor/bin/yves router:cache:warm-up` |
| Router Zed/Backoffice/Backend Gateway | `Spryker\Zed\Router\RouterConfig`; warmers `BackofficeCacheWarmer`, `BackendGatewayCacheWarmer`, `MerchantPortalCacheWarmer` | `RouterConstants::BACKOFFICE_IS_CACHE_ENABLED`, `BACKEND_GATEWAY_IS_CACHE_ENABLED` (default true); legacy `isRoutingCacheEnabled()` (core false, Pyz overrides to true); `router:cache:warm-up`, `router:cache:warm-up:backoffice`, `router:cache:warm-up:backend-gateway` (`router:cache:warm-up:merchant-portal` is not registered, there is no Merchant Portal command in `console list`) |
| Twig | `Twig\Cache\FilesystemCache` (`src/Generated/<App>/Twig/codeBucket`); production: `config_default-docker.production.php:157-178` (`TwigConstants::ZED_TWIG_OPTIONS`/`YVES_TWIG_OPTIONS` → `data/<Store/Region>/cache/<App>/twig`, `FORCE_BYTECODE_INVALIDATION`) | `twig:cache:warmer`, `twig:template:warmer` (`vendor/bin/yves twig:template:warmer` for Yves); `TwigConstants::YVES_PATH_CACHE_ENABLED`/`ZED_PATH_CACHE_ENABLED` |
| Class resolver | `AbstractClassResolver` caches class names/instances | `KernelConstants::RESOLVABLE_CLASS_NAMES_CACHE_ENABLED`, `RESOLVED_INSTANCE_CACHE_ENABLED` (both true, `config_default.php:161-162`); `cache:class-resolver:build` |
| Navigation (Zed) | `BuildNavigationConsole` | `navigation:build-cache`, `navigation:cache:remove` |
| Translator | `GenerateTranslationCacheConsole`, `CleanTranslationCacheConsole` | `translator:generate-cache`, `translator:clean-cache` |
| OMS process | `ProcessCacheWarmUpConsole` | `oms:process-cache:warm-up` (→ [OMS](10-oms-state-machine.md)) |
| REST validation | `BuildRestApiValidationCacheConsole` | `rest-api:build-request-validation-cache`, `rest-api:remove-validation-cache` |
| Glue controller | `config/install/ci.restore-db.stage2.yml` | `vendor/bin/glue glue-api:controller:cache:warm-up` (without argument; an application name overwrites the shared cache with only that application's part) |
| File system (Zed) | `vendor/spryker/cache` (`CacheConfig`: `data/{STORE}/cache`, `data/{STORE}/autoloader`, code buckets; hook `getProjectSpecificCache(): array`) | `cache:empty-all` (`cache:delete-all` is not registered) |
| Propel replication cache | see below | `PropelReplicationCacheConstants::*` |

Own cache in the project: path/switch via config class + constant (→ [Configuration](06-configuration.md)), warm-up as a console command (→ [Console/Cron/Queue](22-console-cron-queue.md)) and hooked into `config/install/*.yml` (→ [Docker SDK workflow](30-docker-sdk-workflow.md)). Project directories for `cache:empty-all` via inheritance of `Spryker\Zed\Cache\CacheConfig::getProjectSpecificCache()` `[Assumption]` (`src/Pyz/Zed/Cache/` does not exist in the release).

**Chunk sizes in P&S** (defaults from the code; none set in the project except CI):

| Constant | Default | Effect |
|---|---|---|
| `EventConstants::EVENT_CHUNK` | not set in the core; `config_default-ci.php:181` = 5000 | event messages per bulk processing |
| `EventConstants::ENQUEUE_EVENT_CHUNK` | – | chunk when enqueuing |
| `EventBehaviorConstants::EVENT_BEHAVIOR_CHUNK_SIZE` | 10000 (`EventBehaviorConfig::DEFAULT_CHUNK_SIZE`) | event resource iteration |
| `EventBehaviorConstants::TRIGGER_CHUNK_SIZE` | 1000 | `publish:trigger-events` |
| `EventBehaviorConstants::TRIGGER_CHUNK_SLEEP_SECONDS` | 5 | pause between trigger chunks |
| `SynchronizationConstants::DEFAULT_SYNC_STORAGE_QUEUE_MESSAGE_CHUNK_SIZE` | 10000 | sync storage queue |
| `SynchronizationConstants::DEFAULT_SYNC_SEARCH_QUEUE_MESSAGE_CHUNK_SIZE` | 10000 | sync search queue |
| `SynchronizationConstants::EXPORT_MESSAGE_CHUNK_SIZE` | 100 | export (`sync:data`) |

Tuning `[Assumption]` from the docs outline: large chunks = fewer queries, more RAM. Measure instead of guessing. Write side → [Publish & Sync](07-publish-sync.md).

**Propel replication cache** (`vendor/spryker/propel-replication-cache`). Purpose: with DB read replicas (`PropelConstants::ZED_DB_REPLICAS`) it prevents reading from a not-yet-synchronized replica directly after a write. Plugins in `src/Pyz/Zed/PropelOrm/PropelOrmDependencyProvider.php`: `FindExtensionPlugin`, `PostSaveExtensionPlugin`, `PostUpdateExtensionPlugin`, `PostDeleteExtensionPlugin` (`Spryker\Zed\PropelReplicationCache\Communication\Plugin\PropelOrm`). Save/update/delete set a Redis key `replication_<EntityName>` with TTL via `PropelReplicationCacheFacade::getInstance()->setKey($class)`; `FindExtensionPlugin` uses `Propel::getWriteConnection()` when `hasKey($class)`, otherwise `getReadConnection()`. Configuration: `CACHE_TTL` = 2 s (`config_default.php:388`); **`IS_REPLICATION_ENABLED` is not set anywhere in the project, default `false`**, `setKey/hasKey` are then no-ops. After changing the plugin list, rebuild the Propel code (`propel:model:build`), the plugins are baked into the query classes.

**Measuring.**
- Benchmark: `spryker-sdk/benchmark` (`require-dev`, `^0.2.2`), command `benchmark:run` (`BenchmarkRunConsole::COMMAND_NAME` verified), options `--path`, `--iterations`, `--revs`. Not registered in `ConsoleDependencyProvider`, `tests/Benchmark` does not exist; register the console before use (class path `SprykerSdk\Zed\Benchmark\Communication\Console\BenchmarkRunConsole`), create your own tests. Based on PhpBench `[Assumption]`.
- APM: `spryker/monitoring` + New Relic/OpenTelemetry plugins (→ [Errors/logging](23-errors-logging-debugging.md)). `NEW_RELIC_ENABLED: 0` in the recipes. Docs: `apm-newrelic-based-troubleshooting`.
- WebProfiler/Xdebug profiling → [Errors/logging](23-errors-logging-debugging.md).

## Instructions

1. **Check read access in the request path:** Yves only Client → Storage/Search; multiple keys via `getMulti`. Replace Zed calls for read pages with P&S.
2. **Check for N+1:** replace loops with Propel queries with a join/`filterByIdIn`/bulk repository (→ [Propel](05-propel-persistence.md)).
3. **Warm caches after deploy** (recipe `docker`, section `build`; command order → [Docker SDK workflow](30-docker-sdk-workflow.md)). The order of the warm-ups is in `config/install/ci.yml` (section `cache`) and `ci.restore-db.stage2.yml`.
4. **Route/code change:** `docker/sdk cli console cache:empty-all` plus warm-up. In dev the Yves router is uncached anyway. `cache:empty-all` clears routers (Yves, Backoffice, Backend Gateway, Glue), Glue controller cache, Twig, navigation and translator (live: the shop keeps responding with 200 afterwards, the first requests take ~5 s; Glue routers rebuild on the first request); warm-up of the `build` section via `vendor/bin/install -r docker -s build -s build-development`. The Glue controller cache does **not** belong to the `build` section (only to `demodata`): after `cache:empty-all` and after `docker/sdk up --build` (deletes `src/Generated`) run `vendor/bin/glue glue-api:controller:cache:warm-up` separately. Caches are separated per region/code bucket; console commands run in the default region EU, for US prepend `SPRYKER_CURRENT_REGION=US SPRYKER_REGION=US APPLICATION_STORE=US` `[live verified (202610.0)]`.
5. **Replica setup:** set `IS_REPLICATION_ENABLED` deliberately (example 2), then `propel:model:build`.
6. **Chunk sizes:** change only after measuring and only in `config/Shared/config_default*.php`.
7. **Benchmark:** only call after registering the console (`benchmark:run` is not registered in the release and is therefore not in the command list below).

Commands (in the container `docker/sdk cli …` or `docker/sdk cli console …`; all executed `[live verified (202610.0)]`):
```
docker/sdk cli console cache:empty-all
docker/sdk cli console cache:class-resolver:build
docker/sdk cli vendor/bin/yves router:cache:warm-up
docker/sdk cli console router:cache:warm-up:backoffice
docker/sdk cli console router:cache:warm-up:backend-gateway
docker/sdk cli console twig:cache:warmer
docker/sdk cli console twig:template:warmer         # exit 0, reports "compiled: 1707, failed: 6" in dev (only WebProfiler/log collector templates)
docker/sdk cli console navigation:build-cache
docker/sdk cli console translator:generate-cache
docker/sdk cli console oms:process-cache:warm-up
docker/sdk cli console rest-api:build-request-validation-cache
docker/sdk cli vendor/bin/glue glue-api:controller:cache:warm-up   # without argument, all applications
```
Two classes define `router:cache:warm-up:backoffice` (`BackofficeRouterCacheWarmUpConsole`, `RouterCacheWarmUpBackofficeConsole`); `BackofficeRouterCacheWarmUpConsole` is registered, the other one is probably legacy `[Assumption]`.

## Examples

**1. Config via inheritance instead of a core change** (`src/Pyz/Zed/Router/RouterConfig.php`, real in the release):
```php
<?php

declare(strict_types = 1);

namespace Pyz\Zed\Router;

use Spryker\Zed\Router\RouterConfig as SprykerRouterConfig;

class RouterConfig extends SprykerRouterConfig
{
    public function getControllerDirectories(): array
    {
        $controllerDirectories = parent::getControllerDirectories();
        $controllerDirectories[] = sprintf('%s/spryker-sdk/*/src/*/Zed/*/Communication/Controller/', APPLICATION_VENDOR_DIR);
        $controllerDirectories[] = sprintf('%s/spryker-eco/*/src/*/Zed/*/Communication/Controller/', APPLICATION_VENDOR_DIR);

        return array_filter($controllerDirectories, 'glob');
    }

    public function isRoutingCacheEnabled(): bool
    {
        return true;
    }
}
```

**2. Enable the replication cache** (example, **not** set in the project; `config/Shared/config_default.php`, `use Spryker\Shared\PropelReplicationCache\PropelReplicationCacheConstants;` is imported there):
```php
$config[PropelReplicationCacheConstants::IS_REPLICATION_ENABLED] = true;
$config[PropelReplicationCacheConstants::CACHE_TTL] = 2;
```

**3. Batch reading from storage** (signature `StorageClientInterface::getMulti(array $keys)`; the key format of the running example is defined by the template [publisher-and-synchronization](../templates/publisher-and-synchronization/README.md)):
```php
$storageData = $this->storageClient->getMulti($storageKeys);
```

**4. Production Twig cache** (real): `config/Shared/config_default-docker.production.php:157-178` sets `TwigConstants::ZED_TWIG_OPTIONS`/`YVES_TWIG_OPTIONS` to `data/<Store|Region>/cache/<App>/twig` with `FORCE_BYTECODE_INVALIDATION`.

## Pitfalls

- Zed call from Yves for read pages instead of P&S.
- Single `get` in a loop instead of `getMulti`; Propel query in a loop.
- Caches not warmed after deploy → first requests slow.
- Stale router/Twig caches after a route/code change: `cache:empty-all` plus warm-up.
- Replication cache expected, but `IS_REPLICATION_ENABLED` not set (default `false`).
- Two switch families in the Zed router (`isRoutingCacheEnabled()` vs. `BACKOFFICE_IS_CACHE_ENABLED`); the docs only name the constants, `isRoutingCacheEnabled()` is probably a legacy leftover `[Assumption]`.
- Chunk sizes too high: memory limit in worker/console; too low: many queries.
- Changing core classes to toggle caches: use config inheritance/constants instead (→ [Extending the core](03-extending-the-core.md), [Upgradability](28-upgradability.md)).
- New Relic only works with `NEW_RELIC_ENABLED` active and the extension plugin registered.
- `benchmark:run` and `cache:delete-all` are not registered (not in `console list`); neither is `router:cache:warm-up:merchant-portal`.

## Checklist

- [ ] Yves read path without Zed call, batch instead of single `get`
- [ ] No N+1 queries in new code
- [ ] Warm-ups included in install recipe/deploy
- [ ] Router/Twig/class resolver caches built after deploy
- [ ] Replication cache flag set deliberately (if replicas)
- [ ] Chunk sizes changed only after measuring
- [ ] APM plugin checked per application

## Sources

Docs (base https://docs.spryker.com/docs/; detailed content not readable): `dg/dev/guidelines/performance-guidelines/architecture-performance-guidelines.html`, `dg/dev/architecture/module-api/performance-and-scalability.html`, `dg/dev/backend-development/data-manipulation/data-publishing/configurartion/chunk-size-configuration.html`, `dg/dev/troubleshooting/troubleshooting-performance-issues/slow-publish-and-synchronization.html`, `dg/dev/guidelines/performance-guidelines/apm-newrelic-based-troubleshooting.html`.

Code: `vendor/spryker/cache/src/Spryker/Zed/Cache/CacheConfig.php`, `vendor/spryker/propel-replication-cache/…`, `vendor/spryker/router/src/Spryker/{Yves,Zed,Shared}/Router/`, `vendor/spryker/twig/src/Spryker/Shared/Twig/`, `vendor/spryker/kernel/src/Spryker/Shared/Kernel/KernelConstants.php`, `vendor/spryker/storage/src/Spryker/Client/Storage/StorageConfig.php`, `vendor/spryker/{event,event-behavior,synchronization}/`, `vendor/spryker-sdk/benchmark/`, `src/Pyz/Zed/Router/RouterConfig.php`, `src/Pyz/Zed/PropelOrm/PropelOrmDependencyProvider.php`, `config/Shared/config_default.php` (161-162, 384-388), `config/Shared/config_default-docker.production.php`, `config/install/docker.yml`.

References: [Publish & Sync](07-publish-sync.md), [Client/Storage/Search](08-client-storage-search.md), [Errors/logging](23-errors-logging-debugging.md), [Docker SDK workflow](30-docker-sdk-workflow.md).

Deprecations: `KernelConstants::AUTO_LOADER_UNRESOLVABLE_CACHE_ENABLED`/`_PROVIDER` → `RESOLVABLE_CLASS_NAMES_CACHE_ENABLED`; `StorageConstants::STORAGE_REDIS_DATABASE` (`YVES_STORAGE_REDIS_DATABASE`) → `StorageRedisConstants::STORAGE_REDIS_DATABASE`.
