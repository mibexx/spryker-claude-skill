# Fehlerbehandlung, Logging, Monitoring und Debugging (Recherche T-23)

> Status: Release 202610.0 (spryker/kernel 3.87.1, Symfony 6.4.48). Inhalte am installierten Code (`vendor/`, `src/Pyz`, `config/`) verifiziert. Doku-Seiten (docs.spryker.com) wurden in der Recherche nicht abgerufen: `[code-verifiziert, Doku nicht abgeglichen]` gilt für die gesamte Datei. Konsolenbefehle sind gegen die Live-Befehlslisten (`docker/sdk console list`, `vendor/bin/yves list`, `vendor/bin/glue list`) geprüft `[live verifiziert (202610.0)]` (ausgeführt); nicht registrierte Befehle wurden entfernt. Beim Code-Abgleich korrigiert: `LogHandlerPluginInterface` ist ein Monolog-`HandlerInterface` (keine `getHandler()`-Methode, siehe Beispiele).

## Zweck

Wie Fehler im Spryker-Stack gefangen, gerendert und geloggt werden, wie man Exceptions, Logs und Audit-Logs im Projekt (`Pyz`) ergänzt, wie Profiler/Xdebug/Monitoring aktiviert werden und wo man bei Fehlersuche zuerst nachsieht.

## Konzepte

**ErrorHandler (`spryker/error-handler`).**
- `Spryker\Shared\ErrorHandler\ErrorHandler`: `handleException()`/`handleFatal()` loggen über `ErrorLoggerInterface`, rendern über einen `ErrorRendererInterface` und setzen Statuscode (404/500). Die Exception-Nachricht wird per `UtilSanitizeService` bereinigt.
- `ErrorLogger::log()` meldet an das APM (`MonitoringService::setError()`) und loggt `critical` mit `['exception' => $exception]`. `logSuppressed()` loggt nur `notice`.
- Renderer (`Shared/ErrorHandler/ErrorRenderer/`): `WebHtmlErrorRenderer` (Produktion), `WebExceptionErrorRenderer` (Debug mit Stacktrace), `ApiErrorRenderer`, `ApiDebugErrorRenderer` (Glue), `CliErrorRenderer`.
- Einbindung als Application-Plugin: Zed `Spryker\Zed\ErrorHandler\Communication\Plugin\Application\ErrorHandlerApplicationPlugin` (`src/Pyz/Zed/Application/ApplicationDependencyProvider.php`), Yves `Spryker\Yves\ErrorHandler\Plugin\Application\ErrorHandlerApplicationPlugin` (`src/Pyz/Yves/ShopApplication/ShopApplicationDependencyProvider.php`).
- Konstanten (`Spryker\Shared\ErrorHandler\ErrorHandlerConstants`): `ERROR_LEVEL`, `ERROR_LEVEL_LOG_ONLY`, `ERROR_RENDERER`, `API_ERROR_RENDERER`, `IS_PRETTY_ERROR_HANDLER_ENABLED`, `DISPLAY_ERRORS`, `PATTERN_IDE_LINK`, `USER_BASE_PATH`, `SERVER_BASE_PATH`, `ZED_ERROR_PAGE`, `YVES_ERROR_PAGE`, `AS_AJAX`.
- Defaults `config/Shared/config_default.php`: `ERROR_LEVEL = E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED`, `ERROR_LEVEL_LOG_ONLY = E_DEPRECATED | E_USER_DEPRECATED`. Deprecations werden nur geloggt.
- Dev (`config/Shared/config_default-docker.dev.php`): mit `SPRYKER_DEBUG_ENABLED` gelten `WebExceptionErrorRenderer`, `ApiDebugErrorRenderer`, `IS_PRETTY_ERROR_HANDLER_ENABLED = true`. `SPRYKER_DEBUG_DEPRECATIONS_ENABLED` setzt `ERROR_LEVEL = E_ALL`.

**Fehlerseiten.**
- Zed: `SubRequestExceptionHandlerStrategyPlugin` (`ExceptionHandlerStrategyPluginInterface` aus `error-handler-extension`: `canHandle(Throwable)`, `handleException(FlattenException)`) rendert `@ErrorHandler/Error/error<Status>.twig`. Statuscodes: `ErrorHandlerConfig::getValidSubRequestExceptionStatusCodes()`; `Pyz\Zed\ErrorHandler\ErrorHandlerConfig` ergänzt `429`. Liste in `Pyz\Zed\ErrorHandler\ErrorHandlerDependencyProvider::getExceptionHandlerStrategyPlugins()`.
- Yves (`spryker-shop/error-page`): Controller `Error403Controller`, `Error404Controller`, `Error429Controller` (+ `*CacheableController`), Routen über `ErrorPageRouteProviderPlugin`; Handler-Plugins (`ExceptionHandlerPluginInterface`: `canHandle($statusCode)`, `handleException(FlattenException)`) `RedirectExceptionHandlerPlugin`, `SubRequestExceptionHandlerPlugin` in `Pyz\Yves\ErrorPage\ErrorPageDependencyProvider::getExceptionHandlerPlugins()`. Stacktrace auf 404 nur mit `ErrorPageConstants::ENABLE_ERROR_404_STACK_TRACE` (Dev: `SPRYKER_DEBUG_ENABLED`). Projekt-Templates: `src/Pyz/Yves/ErrorPage/Theme/default/{error403,error404}/index.twig` (Theme-Mechanik → [Atomic Frontend](15-yves-frontend-atomic.md)).

**Eigene Exceptions.** Klasse in `Business/Exception/` des eigenen Moduls (Vorbild `Spryker\Zed\Acl\Business\Exception\GroupExistsException`). HTTP-Status steuert, wer `Symfony\Component\HttpKernel\Exception\HttpExceptionInterface` implementiert (Vorbild `Spryker\Zed\Kernel\Exception\Controller\InvalidIdException`: `getStatusCode()` = 404, `getHeaders()` = `[]`). Die Statusableitung im Fatal-Pfad wurde nicht bis ins Detail gelesen `[Annahme]`.

**Logging (`spryker/log`, Monolog/PSR-3).**
- `Spryker\Shared\Log\LoggerTrait::getLogger(?LoggerConfigInterface $loggerConfig = null): LoggerInterface`. `LoggerFactory` wählt die Config in der Reihenfolge Yves, ZedCli, Zed, Glue, Default; je Kanalname genau eine `Monolog\Logger`-Instanz.
- Konfig-Klassen in `config_default.php`: `LogConstants::LOGGER_CONFIG`, `LOGGER_CONFIG_ZED` (`ZedLoggerConfigPlugin`), `LOGGER_CONFIG_YVES`, `LOGGER_CONFIG_GLUE`.
- Handler/Prozessoren je Applikation in den Pyz-`LogDependencyProvider`: `src/Pyz/Zed/Log/LogDependencyProvider.php` (`getLogHandlers()`, `getLogProcessors()`), `src/Pyz/Yves/Log/LogDependencyProvider.php` (`getLogHandlers()`, `getProcessors()`), `src/Pyz/Glue/Log/LogDependencyProvider.php`. Die Methodennamen für Prozessoren unterscheiden sich zwischen Zed und Yves.
- Ausgabe: `LOG_LEVEL = Logger::INFO`; Application-Logs auf `getenv('SPRYKER_LOG_STDOUT') ?: 'php://stderr'`, Exception-Logs auf `getenv('SPRYKER_LOG_STDERR') ?: 'php://stderr'`. Dateipfade (`data/logs/{YVES,ZED,GLUE}/…`) stehen in `config/Shared/common/config_logs-files.php`; welche Datei gilt, hängt von der Ladereihenfolge ab (→ [Konfiguration](06-konfiguration.md)). Im Docker-SDK-Setup wird stderr/stdout genutzt `[Annahme]`.
- Sanitizing: `LogConstants::LOG_SANITIZE_FIELDS = ['password']`, `AUDIT_LOG_SANITIZE_FIELDS = ['password']`.
- Queue-Logging: `LOG_QUEUE_NAME = 'log-queue'`, `LOG_ERROR_QUEUE_NAME = 'error-log-queue'` (`QueueHandler`).

**Audit-Logs.** `Spryker\Shared\Log\AuditLoggerTrait::getAuditLogger(AuditLoggerConfigCriteriaTransfer $criteria): LoggerInterface`; Kanal über `setChannelName()` (`LogConfig::AUDIT_LOGGER_CHANNEL_NAME_SECURITY = 'security'`); Tags über Kontextschlüssel `tags` (`TagFilterBufferedStreamHandler`). Config-Plugins je App: `LogConstants::AUDIT_LOGGER_CONFIG_PLUGINS_{YVES,ZED,GLUE,GLUE_BACKEND}`; Handler/Prozessoren in den Pyz-Providern (`getZedSecurityAuditLogHandlerPlugins()`, `getZedSecurityAuditLogProcessorPlugins()`, `getYvesSecurityAuditLog…`). Zusätzlich `AUDIT_LOG_TAG_DISALLOW_LIST`.

**Profiler/Debug.** `spryker/web-profiler`, `spryker-shop/web-profiler-widget`, `spryker/profiler`; Schalter `WebProfilerConstants::IS_WEB_PROFILER_ENABLED`, `WebProfilerWidgetConstants::IS_WEB_PROFILER_ENABLED` (Dev, `config_default-docker.dev.php`: `(bool)getenv('SPRYKER_DEBUG_ENABLED')`; `config_default-docker.php` setzt `false`, wird aber bei `docker.dev` nicht geladen, maßgeblich ist die `.dev`-Datei). Registrierung mit `class_exists`-Guard (Dev-Abhängigkeit): `WebProfilerApplicationPlugin`, `ProfilerRequestEventDispatcherPlugin`. `MonitoringConstants::IGNORABLE_TRANSACTIONS = ['_profiler', '_wdt']`.

**Debug-Schalter.** `deploy.yml`: `docker.debug.enabled: false`, `xdebug.enabled: false`; `deploy.dev.yml`: beide `true`. Das erzeugt `SPRYKER_DEBUG_ENABLED`; zusätzlich `SPRYKER_DEBUG_PROPEL_ENABLED` (`PropelConstants::PROPEL_DEBUG`), und unter `SPRYKER_DEBUG_ENABLED` auch `ApplicationConstants::ENABLE_APPLICATION_DEBUG`, `ShopApplicationConstants::ENABLE_APPLICATION_DEBUG`, `CalculationPageConstants::ENABLE_CART_DEBUG`, `GlueApplicationConstants::GLUE_APPLICATION_REST_DEBUG`. Xdebug-Aufruf pro Befehl (`-x`), Port und PhpStorm-Mapping → [Docker SDK Workflow](30-docker-sdk-workflow.md). IDE-Link im Fehlerbildschirm: `ErrorHandlerConstants::PATTERN_IDE_LINK`, `USER_BASE_PATH`, `SERVER_BASE_PATH`.

**DI-Container debuggen.** Kein kompilierter Symfony-Container, sondern `Spryker\Zed\Kernel\Container` (Pimple-Basis) plus DependencyProvider; fehlende Schlüssel werfen `ContainerKeyNotFoundException`. Hilfsbefehle (registriert): `dev:plugin-usage:dump`, `cache:class-resolver:build`; Routen: `vendor/bin/yves router:debug` (Yves) und `vendor/bin/glue router:debug backend|storefront` (Glue). Die Zed-Konsole kennt **kein** `router:debug` (auch nicht `router:debug:backoffice|backend-gateway|backend-api`); `dev:dependency:*` sind nicht registriert. Ein Befehl zum Dump des Container-Inhalts wurde nicht gefunden.

**Monitoring/APM.** `Spryker\Service\Monitoring\MonitoringService` mit `MonitoringExtensionPluginInterface` (`setError`, `setApplicationName`, `setTransactionName`, `markStartTransaction`, `markEndOfTransaction`, `markIgnoreTransaction`, `markAsConsoleCommand`, `addCustomParameter`, `addCustomTracer`). Im Release gemischt: `src/Pyz/Service/Monitoring/MonitoringDependencyProvider.php` → `OpentelemetryMonitoringExtensionPlugin` (PHP-Extension `otel` in `deploy.yml`), `src/Pyz/Yves/Monitoring/MonitoringDependencyProvider.php` → `SprykerEco\Service\NewRelic\Plugin\NewRelicMonitoringExtensionPlugin`. New Relic ist in den Install-Recipes aus (`NEW_RELIC_ENABLED: 0`); Agent-Konfig `newrelic.ini`. Request-Transaktionen: `MonitoringRequestTransactionEventDispatcherPlugin`, `GatewayMonitoringRequestTransactionEventDispatcherPlugin`. Wirkung des Yves-New-Relic-Plugins neben dem Service-OpenTelemetry-Plugin ist nicht verifiziert `[Annahme]`.

**Health-Check.** `spryker/health-check`; Plugins in `Pyz\Zed\HealthCheck\HealthCheckDependencyProvider::getHealthCheckPlugins()` (Session, KeyValueStore, Search, Database, drei Publish-&-Sync-Plugins); Interface `Spryker\Shared\HealthCheckExtension\Dependency\Plugin\HealthCheckPluginInterface`; Route `/health-check`, Zed `health-check/index` (SSL-Ausnahme `ZED_SSL_EXCLUDED`).

## Anleitung

1. **Fehler suchen.** Im Docker-SDK-Setup landen Logs und Exceptions auf stderr/stdout der Container: `docker logs <container>` (z. B. `docker logs -f spryker_b2b_dev_boffice_eu_1`; Namen mit `docker ps`). `docker/sdk logs` tailt (intern per `tail -f`) nur `exception.log`-Dateien unter `SPRYKER_LOG_DIRECTORY` und hat keine eigene Option (kein `-f`); da Exceptions auf stderr gehen, ist es hier meist leer. Dateien unter `data/logs/` nur in Nicht-Docker-Setups.
2. **Debug einschalten** (nur Dev): `docker.debug.enabled: true` in `deploy.dev.yml`, `docker/sdk bootstrap deploy.dev.yml && docker/sdk up`. Deprecations sichtbar machen: `SPRYKER_DEBUG_DEPRECATIONS_ENABLED`.
3. **Eigene Exception** anlegen (Beispiel 1), im Business-Modell werfen, im Controller/Plugin nicht fangen, wenn der ErrorHandler rendern soll.
4. **Loggen** per `LoggerTrait`; Exceptions immer als `['exception' => $e]` im Kontext (Beispiel 2).
5. **Handler/Prozessor ergänzen** nur über die Pyz-`LogDependencyProvider`-Listen. Ein Handler-Plugin erweitert `Spryker\Zed\Log\Communication\Plugin\Handler\AbstractHandlerPlugin` und implementiert `getHandler(): Monolog\Handler\HandlerInterface` (Beispiel 3).
6. **Sensible Felder** zu `LOG_SANITIZE_FIELDS`/`AUDIT_LOG_SANITIZE_FIELDS` hinzufügen (Standard nur `password`).
7. **Audit-Log** nur mit Kanal und `tags` (Beispiel 4).
8. **Fehlerseite anpassen** im Pyz-Theme/Pyz-Twig, nie im Core.
9. **Klasse wird nicht gefunden** nach Neuanlage: `docker/sdk cli console cache:class-resolver:build` `[live verifiziert (202610.0)]` (→ [Docker SDK Workflow](30-docker-sdk-workflow.md)).

## Beispiele

**1. Eigene Exception mit HTTP-Status** (`src/Pyz/Zed/ProductBadge/Business/Exception/ProductBadgeNotFoundException.php`; Muster `Spryker\Zed\Kernel\Exception\Controller\InvalidIdException`):
```php
<?php

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadge\Business\Exception;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ProductBadgeNotFoundException extends RuntimeException implements HttpExceptionInterface
{
    public function getStatusCode(): int
    {
        return Response::HTTP_NOT_FOUND;
    }

    /**
     * @return array<string, mixed>
     */
    public function getHeaders(): array
    {
        return [];
    }
}
```

**2. Logging mit `LoggerTrait`** (Muster aus `ErrorLogger`; `getLogger()->critical($message, ['exception' => $e])` ist im Core belegt):
```php
<?php

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadge\Business\Writer;

use Generated\Shared\Transfer\ProductBadgeTransfer;
use Spryker\Shared\Log\LoggerTrait;
use Throwable;

class ProductBadgeLogWriter
{
    use LoggerTrait;

    public function log(ProductBadgeTransfer $productBadgeTransfer): void
    {
        try {
            // ...
        } catch (Throwable $exception) {
            // PsrLogMessageProcessorPlugin ersetzt {badgeKey}
            $this->getLogger()->error('Processing of {badgeKey} failed.', [
                'badgeKey' => $productBadgeTransfer->getBadgeKeyOrFail(),
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }
}
```

**3. Eigenes Handler-Plugin** (`src/Pyz/Zed/ProductBadge/Communication/Plugin/Log/ProductBadgeStreamHandlerPlugin.php`; Aufbau nach `Spryker\Zed\Log\Communication\Plugin\Handler\StreamHandlerPlugin`; Handler-Auswahl und Format sind Beispielannahme, nicht gegen einen Lauf geprüft):
```php
<?php

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadge\Communication\Plugin\Log;

use Monolog\Handler\HandlerInterface;
use Monolog\Handler\StreamHandler;
use Spryker\Zed\Log\Communication\Plugin\Handler\AbstractHandlerPlugin;

class ProductBadgeStreamHandlerPlugin extends AbstractHandlerPlugin
{
    protected function getHandler(): HandlerInterface
    {
        if (!$this->handler) {
            $this->handler = new StreamHandler('php://stderr');
        }

        return $this->handler;
    }
}
```
Eintragen in `src/Pyz/Zed/Log/LogDependencyProvider.php`:
```php
protected function getLogHandlers(): array
{
    return [
        new StreamHandlerPlugin(),
        new ProductBadgeStreamHandlerPlugin(),
    ];
}
```

**4. Audit-Log** (Muster `vendor/spryker/customer-data-change-request/.../AuditLogger.php`):
```php
use Generated\Shared\Transfer\AuditLoggerConfigCriteriaTransfer;
use Spryker\Shared\Log\AuditLoggerTrait;

class ProductBadgeAuditLogger
{
    use AuditLoggerTrait;

    protected const string AUDIT_LOGGER_CHANNEL_NAME_SECURITY = 'security';

    protected const string AUDIT_LOGGER_RECORD_KEY_CONTEXT_TAGS = 'tags';

    public function logBadgeCreated(): void
    {
        $this->getAuditLogger(
            (new AuditLoggerConfigCriteriaTransfer())->setChannelName(static::AUDIT_LOGGER_CHANNEL_NAME_SECURITY),
        )->info('Product badge created', [static::AUDIT_LOGGER_RECORD_KEY_CONTEXT_TAGS => ['product_badge_created']]);
    }
}
```

**5. Projekt-Config per Vererbung** (`src/Pyz/Zed/ErrorHandler/ErrorHandlerConfig.php`, echt im Release):
```php
class ErrorHandlerConfig extends SprykerErrorHandlerConfigAlias
{
    public function getValidSubRequestExceptionStatusCodes(): array
    {
        return array_merge(
            parent::getValidSubRequestExceptionStatusCodes(),
            [Response::HTTP_TOO_MANY_REQUESTS],
        );
    }
}
```

**6. Health-Check-Registrierung** (`src/Pyz/Zed/HealthCheck/HealthCheckDependencyProvider.php`, Kern): `getHealthCheckPlugins()` liefert `SessionHealthCheckPlugin`, `KeyValueStoreHealthCheckPlugin`, `SearchHealthCheckPlugin`, `DatabaseHealthCheckPlugin`, `PublishAndSynchronizeWriteHealthCheckPlugin`, `PublishAndSynchronizeHealthCheckSearchPlugin`, `PublishAndSynchronizeHealthCheckStoragePlugin`.

**7. Logging-Konfiguration** (`config/Shared/config_default.php`):
```php
$config[LogConstants::LOGGER_CONFIG_ZED] = ZedLoggerConfigPlugin::class;
$config[LogConstants::LOG_LEVEL] = Logger::INFO;
$config[LogConstants::LOG_SANITIZE_FIELDS] = ['password'];
$config[LogConstants::LOG_FILE_PATH_ZED] = getenv('SPRYKER_LOG_STDOUT') ?: 'php://stderr';
```

Befehle (alle ausgeführt `[live verifiziert (202610.0)]`):
```
docker/sdk cli console log:clear                       # DeleteLogFilesConsole; im Container Exit 255 (IOException: rmdir /tmp), im Dev-Setup nicht nutzbar
docker/sdk cli console cache:class-resolver:build
docker/sdk cli console dev:plugin-usage:dump
docker/sdk cli vendor/bin/yves router:debug            # Yves-Routen; Zed hat kein router:debug
docker/sdk cli vendor/bin/glue router:debug backend    # oder storefront
docker logs <container>                                # Exceptions/Logs (stderr)
```

## Fallstricke

- Logs gehen im Docker-Setup auf stderr/stdout, nicht in `data/logs/*`. `docker logs <container>` statt Datei suchen (`docker/sdk logs` zeigt den Hinweis auf die Log-Oberfläche `http://spryker.local/logs`, fragt `(y/n)` und tailt bei `y` nur `/var/log/spryker/exception.log`; Abbruch mit Strg-C).
- `ERROR_LEVEL` blendet Deprecations aus; mit `SPRYKER_DEBUG_DEPRECATIONS_ENABLED` einschalten.
- Core-Handler/-Prozessoren nie ändern, Pyz-Listen erweitern. Zed `getLogProcessors()` vs. Yves `getProcessors()`.
- `LogHandlerPluginInterface` ist ein Monolog-Handler; für Zed `AbstractHandlerPlugin` nutzen, nicht ein erfundenes `getHandler()`-Interface.
- Nur `getMessage()` zu loggen verliert den Stacktrace: `['exception' => $e]` übergeben.
- Fehlerseiten-Templates nicht im Core, sondern im Pyz-Theme ändern.
- WebProfiler nie in Produktion; deshalb `class_exists`-Guards und `false` in `config_default-docker.php` (bei `docker.dev` nicht geladen; dort `config_default-docker.dev.php`). Profiler-Pakete sind Dev-Abhängigkeiten.
- Audit-Logs ohne Kanal/`tags` greifen im Tag-Filter nicht `[Annahme]` (aus dem Tag-Schlüssel abgeleitet).
- Monitoring pro Applikation prüfen: Service nutzt OpenTelemetry, Yves New Relic.
- Mailversand-Fehler werden nur geloggt, nicht geworfen (→ [Mail](24-mail-message-broker.md)).

## Checkliste

- [ ] Eigene Exceptions in `Business/Exception/`, bei gewünschtem HTTP-Status `HttpExceptionInterface`
- [ ] Logging per `LoggerTrait`, Exception im Kontext, PSR-3-Platzhalter
- [ ] Handler/Prozessoren nur in Pyz-`LogDependencyProvider`
- [ ] Sensible Felder in Sanitize-Listen
- [ ] Audit-Logs mit Kanal und `tags`
- [ ] Debug/WebProfiler nur Dev; Produktion: `false`
- [ ] Fehlerseiten im Pyz-Theme
- [ ] Health-Check-Plugins für neue Infrastruktur ergänzt

## Quellen

Doku (nicht abgerufen, aus Rechercheplan; Basis https://docs.spryker.com/docs/): `dg/dev/backend-development/messages-and-errors/handling-errors-with-errorhandler.html`, `dg/dev/backend-development/zed/business-layer/custom-exceptions.html`, `dg/dev/backend-development/extend_spryker/logging.html`, `dg/dev/backend-development/audit-logs/`, `dg/dev/set-up-spryker-locally/configure-after-installing/configure-debugging/configure-debugging.html`, `dg/dev/integrate-and-configure/Integrate-profiler-module.html`, `dg/dev/guidelines/performance-guidelines/monitoring.html`.

Code: `vendor/spryker/error-handler/src/Spryker/{Shared,Yves,Zed}/ErrorHandler/`, `vendor/spryker/log/src/Spryker/{Shared,Zed}/Log/` (`LoggerTrait`, `AuditLoggerTrait`, `LogHandlerPluginInterface`, `Zed/Log/Communication/Plugin/Handler/AbstractHandlerPlugin.php`), `vendor/spryker-shop/error-page/`, `vendor/spryker/monitoring*`, `vendor/spryker/opentelemetry`, `vendor/spryker/web-profiler`, `vendor/spryker/health-check`, `src/Pyz/Zed/{ErrorHandler,Log,HealthCheck,Application,EventDispatcher}/`, `src/Pyz/Yves/{Log,ErrorPage,Monitoring,ShopApplication}/`, `config/Shared/config_default*.php`, `deploy.yml`, `deploy.dev.yml`, `newrelic.ini`.

Verweise: [Konfiguration](06-konfiguration.md), [Console/Cron/Queue](22-console-cron-queue.md), [Performance](25-performance-caching.md), [Tests](26-tests.md), [Docker SDK Workflow](30-docker-sdk-workflow.md).

Deprecations: `Spryker\Shared\ErrorHandler\Plugin\ServiceProvider\*` und `Spryker\Zed\Log\Communication\Plugin\ServiceProvider\*` (Legacy, ErrorHandler-Provider tragen `@deprecated Use {@link ...ErrorHandlerApplicationPlugin}`); `SprykerShop\Yves\ErrorPage\Plugin\Provider\ErrorPageControllerProvider` (`@deprecated` → `ErrorPageRouteProviderPlugin`) und `ErrorPageServiceProvider` (`@deprecated` → `ErrorPageEventDispatcherPlugin`). Alte Befehlsnamen `dev:ide:generate-*-auto-completion` existieren nur noch als `OLD_COMMAND_NAME`. Ob New Relic mittelfristig durch OpenTelemetry ersetzt wird, ist nicht belegt.
