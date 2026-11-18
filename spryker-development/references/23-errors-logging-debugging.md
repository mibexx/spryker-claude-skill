# Error handling, logging, monitoring and debugging

> Status: release 202610.0 (spryker/kernel 3.87.1, Symfony 6.4.48). Content verified against the installed code (`vendor/`, `src/Pyz`, `config/`). Docs pages (docs.spryker.com) were not retrieved: `[code-verified, docs not cross-checked]` applies to the entire file. Console commands are checked against the live command lists (`docker/sdk console list`, `vendor/bin/yves list`, `vendor/bin/glue list`) `[live verified (202610.0)]` (executed); unregistered commands were removed. Corrected during the code cross-check: `LogHandlerPluginInterface` extends Monolog's `HandlerInterface` and declares no `getHandler()` method of its own; `getHandler()` is the protected hook of the base class `AbstractHandlerPlugin` (see example 3).

## Purpose

How errors are caught, rendered and logged in the Spryker stack, how to add exceptions, logs and audit logs in the project (`Pyz`), how to enable profiler/Xdebug/monitoring and where to look first when troubleshooting.

## Concepts

**ErrorHandler (`spryker/error-handler`).**
- `Spryker\Shared\ErrorHandler\ErrorHandler`: `handleException()`/`handleFatal()` log via `ErrorLoggerInterface`, render via an `ErrorRendererInterface` and set the status code (404/500). The exception message is sanitized via `UtilSanitizeService`.
- `ErrorLogger::log()` reports to the APM (`MonitoringService::setError()`) and logs `critical` with `['exception' => $exception]`. `logSuppressed()` only logs `notice`.
- Renderers (`Shared/ErrorHandler/ErrorRenderer/`): `WebHtmlErrorRenderer` (production), `WebExceptionErrorRenderer` (debug with stack trace), `ApiErrorRenderer`, `ApiDebugErrorRenderer` (Glue), `CliErrorRenderer`.
- Integration as an application plugin: Zed `Spryker\Zed\ErrorHandler\Communication\Plugin\Application\ErrorHandlerApplicationPlugin` (`src/Pyz/Zed/Application/ApplicationDependencyProvider.php`), Yves `Spryker\Yves\ErrorHandler\Plugin\Application\ErrorHandlerApplicationPlugin` (`src/Pyz/Yves/ShopApplication/ShopApplicationDependencyProvider.php`).
- Constants (`Spryker\Shared\ErrorHandler\ErrorHandlerConstants`): `ERROR_LEVEL`, `ERROR_LEVEL_LOG_ONLY`, `ERROR_RENDERER`, `API_ERROR_RENDERER`, `IS_PRETTY_ERROR_HANDLER_ENABLED`, `DISPLAY_ERRORS`, `PATTERN_IDE_LINK`, `USER_BASE_PATH`, `SERVER_BASE_PATH`, `ZED_ERROR_PAGE`, `YVES_ERROR_PAGE`, `AS_AJAX`.
- Defaults `config/Shared/config_default.php`: `ERROR_LEVEL = E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED`, `ERROR_LEVEL_LOG_ONLY = E_DEPRECATED | E_USER_DEPRECATED`. Deprecations are only logged.
- Dev (`config/Shared/config_default-docker.dev.php`): with `SPRYKER_DEBUG_ENABLED`, `WebExceptionErrorRenderer`, `ApiDebugErrorRenderer`, `IS_PRETTY_ERROR_HANDLER_ENABLED = true` apply. `SPRYKER_DEBUG_DEPRECATIONS_ENABLED` sets `ERROR_LEVEL = E_ALL`.

**Error pages.**
- Zed: `SubRequestExceptionHandlerStrategyPlugin` (`ExceptionHandlerStrategyPluginInterface` from `error-handler-extension`: `canHandle(Throwable)`, `handleException(FlattenException)`) renders `@ErrorHandler/Error/error<Status>.twig`. Status codes: `ErrorHandlerConfig::getValidSubRequestExceptionStatusCodes()`; `Pyz\Zed\ErrorHandler\ErrorHandlerConfig` adds `429`. List in `Pyz\Zed\ErrorHandler\ErrorHandlerDependencyProvider::getExceptionHandlerStrategyPlugins()`.
- Yves (`spryker-shop/error-page`): controllers `Error403Controller`, `Error404Controller`, `Error429Controller` (+ `*CacheableController`), routes via `ErrorPageRouteProviderPlugin`; handler plugins (`ExceptionHandlerPluginInterface`: `canHandle($statusCode)`, `handleException(FlattenException)`) `RedirectExceptionHandlerPlugin`, `SubRequestExceptionHandlerPlugin` in `Pyz\Yves\ErrorPage\ErrorPageDependencyProvider::getExceptionHandlerPlugins()`. Stack trace on 404 only with `ErrorPageConstants::ENABLE_ERROR_404_STACK_TRACE` (dev: `SPRYKER_DEBUG_ENABLED`). Project templates: `src/Pyz/Yves/ErrorPage/Theme/default/{error403,error404}/index.twig` (theme mechanics → [Atomic Frontend](15-yves-frontend-atomic.md)).

**Custom exceptions.** Class in `Business/Exception/` of your own module (model `Spryker\Zed\Acl\Business\Exception\GroupExistsException`). The HTTP status is controlled by implementing `Symfony\Component\HttpKernel\Exception\HttpExceptionInterface` (model `Spryker\Zed\Kernel\Exception\Controller\InvalidIdException`: `getStatusCode()` = 404, `getHeaders()` = `[]`). The status derivation in the fatal path was not read in detail `[Assumption]`.

**Logging (`spryker/log`, Monolog/PSR-3).**
- `Spryker\Shared\Log\LoggerTrait::getLogger(?LoggerConfigInterface $loggerConfig = null): LoggerInterface`. `LoggerFactory` selects the config in the order Yves, ZedCli, Zed, Glue, Default; exactly one `Monolog\Logger` instance per channel name.
- Config classes in `config_default.php`: `LogConstants::LOGGER_CONFIG`, `LOGGER_CONFIG_ZED` (`ZedLoggerConfigPlugin`), `LOGGER_CONFIG_YVES`, `LOGGER_CONFIG_GLUE`.
- Handlers/processors per application in the Pyz `LogDependencyProvider`: `src/Pyz/Zed/Log/LogDependencyProvider.php` (`getLogHandlers()`, `getLogProcessors()`), `src/Pyz/Yves/Log/LogDependencyProvider.php` (`getLogHandlers()`, `getProcessors()`), `src/Pyz/Glue/Log/LogDependencyProvider.php`. The method names for processors differ between Zed and Yves.
- Output: `LOG_LEVEL = Logger::INFO`; application logs to `getenv('SPRYKER_LOG_STDOUT') ?: 'php://stderr'`, exception logs to `getenv('SPRYKER_LOG_STDERR') ?: 'php://stderr'`. File paths (`data/logs/{YVES,ZED,GLUE}/…`) are in `config/Shared/common/config_logs-files.php`; which file applies depends on the load order (→ [Configuration](06-configuration.md)). In the Docker SDK setup stderr/stdout is used `[Assumption]`.
- Sanitizing: `LogConstants::LOG_SANITIZE_FIELDS = ['password']`, `AUDIT_LOG_SANITIZE_FIELDS = ['password']`.
- Queue logging: `LOG_QUEUE_NAME = 'log-queue'`, `LOG_ERROR_QUEUE_NAME = 'error-log-queue'` (`QueueHandler`).

**Audit logs.** `Spryker\Shared\Log\AuditLoggerTrait::getAuditLogger(AuditLoggerConfigCriteriaTransfer $criteria): LoggerInterface`; channel via `setChannelName()` (`LogConfig::AUDIT_LOGGER_CHANNEL_NAME_SECURITY = 'security'`); tags via the context key `tags` (`TagFilterBufferedStreamHandler`). Config plugins per app: `LogConstants::AUDIT_LOGGER_CONFIG_PLUGINS_{YVES,ZED,GLUE,GLUE_BACKEND}`; handlers/processors in the Pyz providers (`getZedSecurityAuditLogHandlerPlugins()`, `getZedSecurityAuditLogProcessorPlugins()`, `getYvesSecurityAuditLog…`). Additionally `AUDIT_LOG_TAG_DISALLOW_LIST`.

**Profiler/debug.** `spryker/web-profiler`, `spryker-shop/web-profiler-widget`, `spryker/profiler`; switches `WebProfilerConstants::IS_WEB_PROFILER_ENABLED`, `WebProfilerWidgetConstants::IS_WEB_PROFILER_ENABLED` (dev, `config_default-docker.dev.php`: `(bool)getenv('SPRYKER_DEBUG_ENABLED')`; `config_default-docker.php` sets `false`, but is not loaded for `docker.dev`; the `.dev` file is authoritative). Registration with a `class_exists` guard (dev dependency): `WebProfilerApplicationPlugin`, `ProfilerRequestEventDispatcherPlugin`. `MonitoringConstants::IGNORABLE_TRANSACTIONS = ['_profiler', '_wdt']`.

**Debug switches.** `deploy.yml`: `docker.debug.enabled: false`, `xdebug.enabled: false`; `deploy.dev.yml`: both `true`. This produces `SPRYKER_DEBUG_ENABLED`; additionally `SPRYKER_DEBUG_PROPEL_ENABLED` (`PropelConstants::PROPEL_DEBUG`), and under `SPRYKER_DEBUG_ENABLED` also `ApplicationConstants::ENABLE_APPLICATION_DEBUG`, `ShopApplicationConstants::ENABLE_APPLICATION_DEBUG`, `CalculationPageConstants::ENABLE_CART_DEBUG`, `GlueApplicationConstants::GLUE_APPLICATION_REST_DEBUG`. Xdebug invocation per command (`-x`), port and PhpStorm mapping → [Docker SDK workflow](30-docker-sdk-workflow.md). IDE link on the error screen: `ErrorHandlerConstants::PATTERN_IDE_LINK`, `USER_BASE_PATH`, `SERVER_BASE_PATH`.

**Debugging the DI container.** No compiled Symfony container, but `Spryker\Zed\Kernel\Container` (Pimple-based) plus DependencyProviders; missing keys throw `ContainerKeyNotFoundException`. Helper commands (registered): `dev:plugin-usage:dump`, `cache:class-resolver:build`; routes: `vendor/bin/yves router:debug` (Yves) and `vendor/bin/glue router:debug backend|storefront` (Glue). The Zed console has **no** `router:debug` (nor `router:debug:backoffice|backend-gateway|backend-api`); `dev:dependency:*` are not registered. No command to dump the container contents was found.

**Monitoring/APM.** `Spryker\Service\Monitoring\MonitoringService` with `MonitoringExtensionPluginInterface` (`setError`, `setApplicationName`, `setTransactionName`, `markStartTransaction`, `markEndOfTransaction`, `markIgnoreTransaction`, `markAsConsoleCommand`, `addCustomParameter`, `addCustomTracer`). Mixed in the release: `src/Pyz/Service/Monitoring/MonitoringDependencyProvider.php` → `OpentelemetryMonitoringExtensionPlugin` (PHP extension `otel` in `deploy.yml`), `src/Pyz/Yves/Monitoring/MonitoringDependencyProvider.php` → `SprykerEco\Service\NewRelic\Plugin\NewRelicMonitoringExtensionPlugin`. New Relic is off in the install recipes (`NEW_RELIC_ENABLED: 0`); agent config `newrelic.ini`. Request transactions: `MonitoringRequestTransactionEventDispatcherPlugin`, `GatewayMonitoringRequestTransactionEventDispatcherPlugin`. The effect of the Yves New Relic plugin alongside the service OpenTelemetry plugin is not verified `[Assumption]`.

**Health check.** `spryker/health-check`; plugins in `Pyz\Zed\HealthCheck\HealthCheckDependencyProvider::getHealthCheckPlugins()` (Session, KeyValueStore, Search, Database, three Publish & Synchronization plugins); interface `Spryker\Shared\HealthCheckExtension\Dependency\Plugin\HealthCheckPluginInterface`; route `/health-check`, Zed `health-check/index` (SSL exception `ZED_SSL_EXCLUDED`).

## Guide

1. **Find errors.** In the Docker SDK setup, logs and exceptions end up on the containers' stderr/stdout: `docker logs <container>` (e.g. `docker logs -f spryker_b2b_dev_boffice_eu_1`; names via `docker ps`). `docker/sdk logs` tails (internally via `tail -f`) only `exception.log` files under `SPRYKER_LOG_DIRECTORY` and has no option of its own (no `-f`); since exceptions go to stderr, it is usually empty here. Files under `data/logs/` only in non-Docker setups.
2. **Enable debug** (dev only): `docker.debug.enabled: true` in `deploy.dev.yml`, `docker/sdk bootstrap deploy.dev.yml && docker/sdk up`. Make deprecations visible: `SPRYKER_DEBUG_DEPRECATIONS_ENABLED`.
3. **Create a custom exception** (example 1), throw it in the business model, do not catch it in the controller/plugin if the ErrorHandler should render it.
4. **Log** via `LoggerTrait`; always pass exceptions as `['exception' => $e]` in the context (example 2).
5. **Add a handler/processor** only via the Pyz `LogDependencyProvider` lists. A handler plugin extends `Spryker\Zed\Log\Communication\Plugin\Handler\AbstractHandlerPlugin` and implements its protected method `getHandler(): Monolog\Handler\HandlerInterface` (example 3).
6. **Sensitive fields**: add them to `LOG_SANITIZE_FIELDS`/`AUDIT_LOG_SANITIZE_FIELDS` (default only `password`).
7. **Audit log** only with channel and `tags` (example 4).
8. **Customize the error page** in the Pyz theme/Pyz Twig, never in the core.
9. **Class not found** after creating it: `docker/sdk cli console cache:class-resolver:build` `[live verified (202610.0)]` (→ [Docker SDK workflow](30-docker-sdk-workflow.md)).

## Examples

**1. Custom exception with HTTP status** (`src/Pyz/Zed/ProductBadge/Business/Exception/ProductBadgeNotFoundException.php`; pattern `Spryker\Zed\Kernel\Exception\Controller\InvalidIdException`):
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

**2. Logging with `LoggerTrait`** (pattern from `ErrorLogger`; `getLogger()->critical($message, ['exception' => $e])` is evidenced in the core):
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
            // PsrLogMessageProcessorPlugin replaces {badgeKey}
            $this->getLogger()->error('Processing of {badgeKey} failed.', [
                'badgeKey' => $productBadgeTransfer->getBadgeKeyOrFail(),
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }
}
```

**3. Custom handler plugin** (`src/Pyz/Zed/ProductBadge/Communication/Plugin/Log/ProductBadgeStreamHandlerPlugin.php`; structured like `Spryker\Zed\Log\Communication\Plugin\Handler\StreamHandlerPlugin`; handler choice and format are an example assumption, not checked against a run):
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
Register in `src/Pyz/Zed/Log/LogDependencyProvider.php`:
```php
protected function getLogHandlers(): array
{
    return [
        new StreamHandlerPlugin(),
        new ProductBadgeStreamHandlerPlugin(),
    ];
}
```

**4. Audit log** (pattern `vendor/spryker/customer-data-change-request/.../AuditLogger.php`):
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

**5. Project config via inheritance** (`src/Pyz/Zed/ErrorHandler/ErrorHandlerConfig.php`, real in the release):
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

**6. Health check registration** (`src/Pyz/Zed/HealthCheck/HealthCheckDependencyProvider.php`, core part): `getHealthCheckPlugins()` returns `SessionHealthCheckPlugin`, `KeyValueStoreHealthCheckPlugin`, `SearchHealthCheckPlugin`, `DatabaseHealthCheckPlugin`, `PublishAndSynchronizeWriteHealthCheckPlugin`, `PublishAndSynchronizeHealthCheckSearchPlugin`, `PublishAndSynchronizeHealthCheckStoragePlugin`.

**7. Logging configuration** (`config/Shared/config_default.php`):
```php
$config[LogConstants::LOGGER_CONFIG_ZED] = ZedLoggerConfigPlugin::class;
$config[LogConstants::LOG_LEVEL] = Logger::INFO;
$config[LogConstants::LOG_SANITIZE_FIELDS] = ['password'];
$config[LogConstants::LOG_FILE_PATH_ZED] = getenv('SPRYKER_LOG_STDOUT') ?: 'php://stderr';
```

Commands (all executed `[live verified (202610.0)]`):
```
docker/sdk cli console log:clear                       # DeleteLogFilesConsole; exit 255 in the container (IOException: rmdir /tmp), not usable in the dev setup
docker/sdk cli console cache:class-resolver:build
docker/sdk cli console dev:plugin-usage:dump
docker/sdk cli vendor/bin/yves router:debug            # Yves routes; Zed has no router:debug
docker/sdk cli vendor/bin/glue router:debug backend    # or storefront
docker logs <container>                                # exceptions/logs (stderr)
```

## Pitfalls

- In the Docker setup, logs go to stderr/stdout, not to `data/logs/*`. Use `docker logs <container>` instead of looking for a file (`docker/sdk logs` shows the hint to the log UI `http://spryker.local/logs`, asks `(y/n)` and on `y` tails only `/var/log/spryker/exception.log`; abort with Ctrl-C).
- `ERROR_LEVEL` hides deprecations; enable them with `SPRYKER_DEBUG_DEPRECATIONS_ENABLED`.
- Never change core handlers/processors; extend the Pyz lists. Zed `getLogProcessors()` vs. Yves `getProcessors()`.
- `LogHandlerPluginInterface` is a Monolog handler; for Zed extend `AbstractHandlerPlugin` and implement its protected `getHandler()`; do not expect `getHandler()` on the interface itself.
- Logging only `getMessage()` loses the stack trace: pass `['exception' => $e]`.
- Change error page templates in the Pyz theme, not in the core.
- WebProfiler never in production; hence the `class_exists` guards and `false` in `config_default-docker.php` (not loaded for `docker.dev`; there `config_default-docker.dev.php`). Profiler packages are dev dependencies.
- Audit logs without channel/`tags` are not matched by the tag filter `[Assumption]` (derived from the tag key).
- Check monitoring per application: Service uses OpenTelemetry, Yves New Relic.
- Mail sending errors are only logged, not thrown (→ [Mail](24-mail-message-broker.md)).

## Checklist

- [ ] Custom exceptions in `Business/Exception/`, `HttpExceptionInterface` if a specific HTTP status is desired
- [ ] Logging via `LoggerTrait`, exception in the context, PSR-3 placeholders
- [ ] Handlers/processors only in the Pyz `LogDependencyProvider`
- [ ] Sensitive fields in the sanitize lists
- [ ] Audit logs with channel and `tags`
- [ ] Debug/WebProfiler dev only; production: `false`
- [ ] Error pages in the Pyz theme
- [ ] Health check plugins added for new infrastructure

## Sources

Docs (not retrieved; base https://docs.spryker.com/docs/): `dg/dev/backend-development/messages-and-errors/handling-errors-with-errorhandler.html`, `dg/dev/backend-development/zed/business-layer/custom-exceptions.html`, `dg/dev/backend-development/extend_spryker/logging.html`, `dg/dev/backend-development/audit-logs/`, `dg/dev/set-up-spryker-locally/configure-after-installing/configure-debugging/configure-debugging.html`, `dg/dev/integrate-and-configure/Integrate-profiler-module.html`, `dg/dev/guidelines/performance-guidelines/monitoring.html`.

Code: `vendor/spryker/error-handler/src/Spryker/{Shared,Yves,Zed}/ErrorHandler/`, `vendor/spryker/log/src/Spryker/{Shared,Zed}/Log/` (`LoggerTrait`, `AuditLoggerTrait`, `LogHandlerPluginInterface`, `Zed/Log/Communication/Plugin/Handler/AbstractHandlerPlugin.php`), `vendor/spryker-shop/error-page/`, `vendor/spryker/monitoring*`, `vendor/spryker/opentelemetry`, `vendor/spryker/web-profiler`, `vendor/spryker/health-check`, `src/Pyz/Zed/{ErrorHandler,Log,HealthCheck,Application,EventDispatcher}/`, `src/Pyz/Yves/{Log,ErrorPage,Monitoring,ShopApplication}/`, `config/Shared/config_default*.php`, `deploy.yml`, `deploy.dev.yml`, `newrelic.ini`.

References: [Configuration](06-configuration.md), [Console/Cron/Queue](22-console-cron-queue.md), [Performance](25-performance-caching.md), [Tests](26-tests.md), [Docker SDK workflow](30-docker-sdk-workflow.md).

Deprecations: `Spryker\Shared\ErrorHandler\Plugin\ServiceProvider\*` and `Spryker\Zed\Log\Communication\Plugin\ServiceProvider\*` (legacy, the ErrorHandler providers carry `@deprecated Use {@link ...ErrorHandlerApplicationPlugin}`); `SprykerShop\Yves\ErrorPage\Plugin\Provider\ErrorPageControllerProvider` (`@deprecated` → `ErrorPageRouteProviderPlugin`) and `ErrorPageServiceProvider` (`@deprecated` → `ErrorPageEventDispatcherPlugin`). The old command names `dev:ide:generate-*-auto-completion` only exist as `OLD_COMMAND_NAME`. Whether New Relic will be replaced by OpenTelemetry in the medium term is not evidenced.
