# Performance und Caching (Recherche T-25)

> Status: Release 202610.0 (spryker/kernel 3.87.1). Konstanten, Defaults und Klassen am Code verifiziert. Die Doku-Detailseiten zu Chunk-Size und Slow-P&S ließen sich nicht inhaltlich auslesen (nur Navigation), daher `[code-verifiziert, Doku nicht abgeglichen]`; was nur aus der Doku-Gliederung folgt, ist `[Annahme]`. Konsolenbefehle im Container ausgeführt `[live verifiziert (202610.0)]`; nicht registrierte Befehle (`cache:delete-all`, `router:cache:warm-up:merchant-portal`, `benchmark:run`) sind entfernt bzw. als nicht registriert markiert. Beim Code-Abgleich korrigiert: Der Befehl zum Entfernen des Navigationscaches heißt `navigation:cache:remove` (nicht `navigation:remove-cache`).

## Zweck

Überblick über die Performance-Regeln, die der Code stützt, über alle Cache-Arten samt Aufbau-/Leerbefehlen, Chunk-Größen in Publish & Synchronization (P&S), den Propel-Replication-Cache und die Performance-Messung.

## Konzepte

**Regeln, die der Code stützt** (Doku: `architecture-performance-guidelines`, Gliederung: Widgets, Zed-WebProfiler, Redis/Valkey, N+1, Batches, Chunk-Size, Worker, externe Requests, APM):
- Yves liest nur aus Redis/OpenSearch, kein Zed-Call im Request-Pfad (→ [Client/Storage/Search](08-client-storage-search.md), [Architektur](01-architektur.md)). ZedRequest nur für Schreibaktionen (Warenkorb, Checkout).
- Mehrfach-Lesen: `StorageClientInterface::getMulti(array $keys)` statt Einzel-`get` in Schleifen.
- Storage-Request-Cache im Client: `StorageConfig::isStorageCachingEnabled()` = true, TTL `getStorageCacheTtl()` = 86400, Strategien `STORAGE_CACHE_STRATEGY_REPLACE|INCREMENTAL|INACTIVE`; inkrementell ab Key-Größe 1000 (`getStorageCacheIncrementalStrategyKeySizeLimit`). Detail-Semantik `[Annahme]`.
- N+1 vermeiden: Bulk-Plugins/Repositories statt Schleifen mit Einzelqueries (Beispiele `*SynchronizationDataBulkPlugin`/`…BulkRepositoryPlugin` in `src/Pyz/Zed/Synchronization/SynchronizationDependencyProvider.php`).

**Cache-Arten.**

| Cache | Mechanismus | Schalter / Befehl |
|---|---|---|
| Router Yves | `Spryker\Yves\Router\RouterConfig::getRouterConfiguration()` setzt `cache_dir` (CompiledUrlMatcher); Default `src/Generated/Yves/Router/codeBucket<BUCKET>/…`; im Dev-Router `cache_dir` null | `RouterConstants::YVES_IS_CACHE_ENABLED` (Default true), `YVES_CACHE_PATH`; `vendor/bin/yves router:cache:warm-up` |
| Router Zed/Backoffice/Backend-Gateway | `Spryker\Zed\Router\RouterConfig`; Warmer `BackofficeCacheWarmer`, `BackendGatewayCacheWarmer`, `MerchantPortalCacheWarmer` | `RouterConstants::BACKOFFICE_IS_CACHE_ENABLED`, `BACKEND_GATEWAY_IS_CACHE_ENABLED` (Default true); Legacy `isRoutingCacheEnabled()` (Core false, Pyz überschreibt auf true); `router:cache:warm-up`, `router:cache:warm-up:backoffice`, `router:cache:warm-up:backend-gateway` (`router:cache:warm-up:merchant-portal` ist nicht registriert, es gibt keinen Merchant-Portal-Befehl in `console list`) |
| Twig | `Twig\Cache\FilesystemCache` (`src/Generated/<App>/Twig/codeBucket`); Produktion: `config_default-docker.production.php:157-178` (`TwigConstants::ZED_TWIG_OPTIONS`/`YVES_TWIG_OPTIONS` → `data/<Store/Region>/cache/<App>/twig`, `FORCE_BYTECODE_INVALIDATION`) | `twig:cache:warmer`, `twig:template:warmer` (`vendor/bin/yves twig:template:warmer` für Yves); `TwigConstants::YVES_PATH_CACHE_ENABLED`/`ZED_PATH_CACHE_ENABLED` |
| Class-Resolver | `AbstractClassResolver` cached Klassennamen/Instanzen | `KernelConstants::RESOLVABLE_CLASS_NAMES_CACHE_ENABLED`, `RESOLVED_INSTANCE_CACHE_ENABLED` (beide true, `config_default.php:161-162`); `cache:class-resolver:build` |
| Navigation (Zed) | `BuildNavigationConsole` | `navigation:build-cache`, `navigation:cache:remove` |
| Translator | `GenerateTranslationCacheConsole`, `CleanTranslationCacheConsole` | `translator:generate-cache`, `translator:clean-cache` |
| OMS-Prozess | `ProcessCacheWarmUpConsole` | `oms:process-cache:warm-up` (→ [OMS](10-oms-state-machine.md)) |
| REST-Validation | `BuildRestApiValidationCacheConsole` | `rest-api:build-request-validation-cache`, `rest-api:remove-validation-cache` |
| Glue-Controller | `config/install/ci.restore-db.stage2.yml` | `vendor/bin/glue glue-api:controller:cache:warm-up` (ohne Argument; ein Applikationsname überschreibt den gemeinsamen Cache nur mit dem Anteil dieser Applikation) |
| Dateisystem (Zed) | `vendor/spryker/cache` (`CacheConfig`: `data/{STORE}/cache`, `data/{STORE}/autoloader`, Code-Buckets; Hook `getProjectSpecificCache(): array`) | `cache:empty-all` (`cache:delete-all` ist nicht registriert) |
| Propel-Replication-Cache | siehe unten | `PropelReplicationCacheConstants::*` |

Eigener Cache im Projekt: Pfad/Schalter über Config-Klasse + Constant (→ [Konfiguration](06-konfiguration.md)), Warm-Up als Console-Command (→ [Console/Cron/Queue](22-console-cron-queue.md)) und in `config/install/*.yml` einhängen (→ [Docker SDK Workflow](30-docker-sdk-workflow.md)). Projekt-Verzeichnisse für `cache:empty-all` über Vererbung von `Spryker\Zed\Cache\CacheConfig::getProjectSpecificCache()` `[Annahme]` (`src/Pyz/Zed/Cache/` existiert im Release nicht).

**Chunk-Größen in P&S** (Defaults aus dem Code; im Projekt außer CI keine gesetzt):

| Konstante | Default | Wirkung |
|---|---|---|
| `EventConstants::EVENT_CHUNK` | im Core nicht gesetzt; `config_default-ci.php:181` = 5000 | Event-Messages pro Bulk-Verarbeitung |
| `EventConstants::ENQUEUE_EVENT_CHUNK` | – | Chunk beim Einreihen |
| `EventBehaviorConstants::EVENT_BEHAVIOR_CHUNK_SIZE` | 10000 (`EventBehaviorConfig::DEFAULT_CHUNK_SIZE`) | Event-Resource-Iteration |
| `EventBehaviorConstants::TRIGGER_CHUNK_SIZE` | 1000 | `publish:trigger-events` |
| `EventBehaviorConstants::TRIGGER_CHUNK_SLEEP_SECONDS` | 5 | Pause zwischen Trigger-Chunks |
| `SynchronizationConstants::DEFAULT_SYNC_STORAGE_QUEUE_MESSAGE_CHUNK_SIZE` | 10000 | Sync-Storage-Queue |
| `SynchronizationConstants::DEFAULT_SYNC_SEARCH_QUEUE_MESSAGE_CHUNK_SIZE` | 10000 | Sync-Search-Queue |
| `SynchronizationConstants::EXPORT_MESSAGE_CHUNK_SIZE` | 100 | Export (`sync:data`) |

Tuning `[Annahme]` aus der Doku-Gliederung: große Chunks = weniger Queries, mehr RAM. Messen statt raten. Schreibseite → [Publish & Sync](07-publish-sync.md).

**Propel-Replication-Cache** (`vendor/spryker/propel-replication-cache`). Zweck: Bei DB-Read-Replicas (`PropelConstants::ZED_DB_REPLICAS`) verhindert er, direkt nach einem Schreibvorgang aus einem noch nicht synchronen Replica zu lesen. Plugins in `src/Pyz/Zed/PropelOrm/PropelOrmDependencyProvider.php`: `FindExtensionPlugin`, `PostSaveExtensionPlugin`, `PostUpdateExtensionPlugin`, `PostDeleteExtensionPlugin` (`Spryker\Zed\PropelReplicationCache\Communication\Plugin\PropelOrm`). Save/Update/Delete setzen über `PropelReplicationCacheFacade::getInstance()->setKey($class)` einen Redis-Key `replication_<EntityName>` mit TTL; `FindExtensionPlugin` nutzt bei `hasKey($class)` `Propel::getWriteConnection()`, sonst `getReadConnection()`. Konfiguration: `CACHE_TTL` = 2 s (`config_default.php:388`); **`IS_REPLICATION_ENABLED` ist im Projekt nirgends gesetzt, Default `false`**, `setKey/hasKey` sind dann No-Ops. Nach Änderung der Plugin-Liste Propel-Code neu bauen (`propel:model:build`), die Plugins werden in die Query-Klassen eingebrannt.

**Messen.**
- Benchmark: `spryker-sdk/benchmark` (`require-dev`, `^0.2.2`), Command `benchmark:run` (`BenchmarkRunConsole::COMMAND_NAME` verifiziert), Optionen `--path`, `--iterations`, `--revs`. Nicht in `ConsoleDependencyProvider` registriert, `tests/Benchmark` existiert nicht; vor Nutzung Console registrieren (Klassenpfad `SprykerSdk\Zed\Benchmark\Communication\Console\BenchmarkRunConsole`), eigene Tests anlegen. Basis PhpBench `[Annahme]`.
- APM: `spryker/monitoring` + New Relic/OpenTelemetry-Plugins (→ [Fehler/Logging](23-fehler-logging-debugging.md)). `NEW_RELIC_ENABLED: 0` in den Recipes. Doku: `apm-newrelic-based-troubleshooting`.
- WebProfiler/Xdebug-Profiling → [Fehler/Logging](23-fehler-logging-debugging.md).

## Anleitung

1. **Lesezugriffe im Request-Pfad prüfen:** Yves nur Client → Storage/Search; mehrere Keys per `getMulti`. Zed-Call für Leseseiten durch P&S ersetzen.
2. **N+1 prüfen:** Schleifen mit Propel-Query durch Join/`filterByIdIn`/Bulk-Repository ersetzen (→ [Propel](05-propel-persistenz.md)).
3. **Nach Deploy Caches wärmen** (Recipe `docker`, Sektion `build`; Befehlsreihenfolge → [Docker SDK Workflow](30-docker-sdk-workflow.md)). Reihenfolge der Warm-Ups steht in `config/install/ci.yml` (Sektion `cache`) und `ci.restore-db.stage2.yml`.
4. **Route-/Code-Änderung:** `docker/sdk cli console cache:empty-all` plus Warm-Up. Im Dev ist der Yves-Router ohnehin ungecached. `cache:empty-all` leert Router (Yves, Backoffice, Backend-Gateway, Glue), Glue-Controller-Cache, Twig, Navigation und Translator (live: Shop antwortet danach weiter mit 200, die ersten Requests brauchen ~5 s; Glue-Router bauen sich beim ersten Request neu); Warm-Up der Sektion `build` per `vendor/bin/install -r docker -s build -s build-development`. Der Glue-Controller-Cache gehört **nicht** zur Sektion `build` (nur zu `demodata`): nach `cache:empty-all` und nach `docker/sdk up --build` (löscht `src/Generated`) separat `vendor/bin/glue glue-api:controller:cache:warm-up` ausführen. Caches sind je Region/Code-Bucket getrennt; Konsolenbefehle laufen in der Default-Region EU, für US `SPRYKER_CURRENT_REGION=US SPRYKER_REGION=US APPLICATION_STORE=US` voranstellen `[live verifiziert (202610.0)]`.
5. **Replica-Setup:** `IS_REPLICATION_ENABLED` bewusst setzen (Beispiel 2), danach `propel:model:build`.
6. **Chunk-Größen** nur nach Messung und nur in `config/Shared/config_default*.php` ändern.
7. **Benchmark** erst nach Registrierung der Console aufrufen (`benchmark:run` ist im Release nicht registriert und steht deshalb nicht in der Befehlsliste unten).

Befehle (im Container `docker/sdk cli …` bzw. `docker/sdk cli console …`; alle ausgeführt `[live verifiziert (202610.0)]`):
```
docker/sdk cli console cache:empty-all
docker/sdk cli console cache:class-resolver:build
docker/sdk cli vendor/bin/yves router:cache:warm-up
docker/sdk cli console router:cache:warm-up:backoffice
docker/sdk cli console router:cache:warm-up:backend-gateway
docker/sdk cli console twig:cache:warmer
docker/sdk cli console twig:template:warmer         # Exit 0, meldet in Dev "compiled: 1707, failed: 6" (nur WebProfiler-/Log-Collector-Templates)
docker/sdk cli console navigation:build-cache
docker/sdk cli console translator:generate-cache
docker/sdk cli console oms:process-cache:warm-up
docker/sdk cli console rest-api:build-request-validation-cache
docker/sdk cli vendor/bin/glue glue-api:controller:cache:warm-up   # ohne Argument, alle Applikationen
```
Zwei Klassen definieren `router:cache:warm-up:backoffice` (`BackofficeRouterCacheWarmUpConsole`, `RouterCacheWarmUpBackofficeConsole`); registriert ist `BackofficeRouterCacheWarmUpConsole`, die andere ist vermutlich Legacy `[Annahme]`.

## Beispiele

**1. Config per Vererbung statt Core-Änderung** (`src/Pyz/Zed/Router/RouterConfig.php`, echt im Release):
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

**2. Replication-Cache aktivieren** (Beispiel, **nicht** im Projekt gesetzt; `config/Shared/config_default.php`, `use Spryker\Shared\PropelReplicationCache\PropelReplicationCacheConstants;` ist dort importiert):
```php
$config[PropelReplicationCacheConstants::IS_REPLICATION_ENABLED] = true;
$config[PropelReplicationCacheConstants::CACHE_TTL] = 2;
```

**3. Batch-Lesen aus dem Storage** (Signatur `StorageClientInterface::getMulti(array $keys)`; Key-Format des Laufbeispiels legt das Template [publisher-and-synchronization](../templates/publisher-and-synchronization/README.md) fest):
```php
$storageData = $this->storageClient->getMulti($storageKeys);
```

**4. Produktions-Twig-Cache** (echt): `config/Shared/config_default-docker.production.php:157-178` setzt `TwigConstants::ZED_TWIG_OPTIONS`/`YVES_TWIG_OPTIONS` auf `data/<Store|Region>/cache/<App>/twig` mit `FORCE_BYTECODE_INVALIDATION`.

## Fallstricke

- Zed-Call aus Yves für Leseseiten statt P&S.
- Einzel-`get` in Schleife statt `getMulti`; Propel-Query in Schleife.
- Caches nach Deploy nicht gewärmt → erste Requests langsam.
- Alte Router-/Twig-Caches nach Route-/Code-Änderung: `cache:empty-all` plus Warm-Up.
- Replication-Cache erwartet, aber `IS_REPLICATION_ENABLED` nicht gesetzt (Default `false`).
- Zwei Schalter-Familien im Zed-Router (`isRoutingCacheEnabled()` vs. `BACKOFFICE_IS_CACHE_ENABLED`); Doku nennt nur die Constants, `isRoutingCacheEnabled()` ist vermutlich Altlast `[Annahme]`.
- Chunk-Größen zu hoch: Memory-Limit in Worker/Console; zu niedrig: viele Queries.
- Core-Klassen ändern, um Caches zu schalten: stattdessen Config-Vererbung/Constants (→ [Core erweitern](03-core-erweitern.md), [Upgradability](28-upgradability.md)).
- New Relic wirkt nur mit aktivem `NEW_RELIC_ENABLED` und registriertem Extension-Plugin.
- `benchmark:run` und `cache:delete-all` sind nicht registriert (nicht in `console list`); `router:cache:warm-up:merchant-portal` ebenso.

## Checkliste

- [ ] Yves-Lesepfad ohne Zed-Call, Batch statt Einzel-`get`
- [ ] Keine N+1-Queries in neuem Code
- [ ] Warm-Ups in Install-Recipe/Deploy enthalten
- [ ] Router-/Twig-/Class-Resolver-Caches nach Deploy gebaut
- [ ] Replication-Cache-Flag bewusst gesetzt (falls Replicas)
- [ ] Chunk-Größen nur nach Messung geändert
- [ ] APM-Plugin pro Applikation geprüft

## Quellen

Doku (Basis https://docs.spryker.com/docs/; Detailinhalte nicht auslesbar): `dg/dev/guidelines/performance-guidelines/architecture-performance-guidelines.html`, `dg/dev/architecture/module-api/performance-and-scalability.html`, `dg/dev/backend-development/data-manipulation/data-publishing/configurartion/chunk-size-configuration.html`, `dg/dev/troubleshooting/troubleshooting-performance-issues/slow-publish-and-synchronization.html`, `dg/dev/guidelines/performance-guidelines/apm-newrelic-based-troubleshooting.html`.

Code: `vendor/spryker/cache/src/Spryker/Zed/Cache/CacheConfig.php`, `vendor/spryker/propel-replication-cache/…`, `vendor/spryker/router/src/Spryker/{Yves,Zed,Shared}/Router/`, `vendor/spryker/twig/src/Spryker/Shared/Twig/`, `vendor/spryker/kernel/src/Spryker/Shared/Kernel/KernelConstants.php`, `vendor/spryker/storage/src/Spryker/Client/Storage/StorageConfig.php`, `vendor/spryker/{event,event-behavior,synchronization}/`, `vendor/spryker-sdk/benchmark/`, `src/Pyz/Zed/Router/RouterConfig.php`, `src/Pyz/Zed/PropelOrm/PropelOrmDependencyProvider.php`, `config/Shared/config_default.php` (161-162, 384-388), `config/Shared/config_default-docker.production.php`, `config/install/docker.yml`.

Verweise: [Publish & Sync](07-publish-sync.md), [Client/Storage/Search](08-client-storage-search.md), [Fehler/Logging](23-fehler-logging-debugging.md), [Docker SDK Workflow](30-docker-sdk-workflow.md).

Deprecations: `KernelConstants::AUTO_LOADER_UNRESOLVABLE_CACHE_ENABLED`/`_PROVIDER` → `RESOLVABLE_CLASS_NAMES_CACHE_ENABLED`; `StorageConstants::STORAGE_REDIS_DATABASE` (`YVES_STORAGE_REDIS_DATABASE`) → `StorageRedisConstants::STORAGE_REDIS_DATABASE`.
