# Configuration and environments

> Status: release 202610.0 (spryker/kernel 3.87.1). `[code-verified, docs not cross-checked]`: the docs page "configuration-management" (as of 2021) could not be extracted; the Docker SDK reference was cross-read locally (`docker/docs/`). Gaps: load order only read from code, not confirmed via debugging in the container; secret management in Spryker Cloud (PaaS) cannot be checked locally; module `configuration` (Back Office configuration) not installed. Commands: `docker/sdk cli` and `docker/sdk console` were executed live `[live verified (202610.0)]`, `docker/sdk up` and `up --build` as well `[live verified (202610.0)]` (TTY required). **Note:** `src/SprykerConfig/CodeBucketConfig.php` exists in the release, see section Code bucket files.

## Purpose
Where configuration values are defined, loaded and read (`config/Shared/*.php`, `*Constants`, `*Config`), how environments and secrets are handled. `deploy.yml`, Docker SDK, install recipes: [30](30-docker-sdk-workflow.md). Config as an extension strategy: [03](03-extending-the-core.md). Stores from a business perspective: [19](19-multi-store-i18n.md).

## Concepts

### Load order
`vendor/spryker/config/src/Spryker/Shared/Config/Config.php::init()`. All files are located in `config/Shared/`, are named `config_<type>.php` and are loaded via `include` into the same `$config` ArrayObject; missing files are skipped, later ones overwrite earlier keys.

| # | File | Condition |
|---|---|---|
| 1 | `config_default.php` | always |
| 2 | `config_default-<APPLICATION_ENV>.php` | e.g. `config_default-docker.dev.php` |
| 3 | `config_default_<CODE_BUCKET>.php` | only if `APPLICATION_CODE_BUCKET !== ''` |
| 4 | `config_default-<ENV>_<CODE_BUCKET>.php` | only with code bucket |
| 5 | `config_local_test.php` | optional |
| 6 | `config_local.php` | optional, gitignored |
| 7 | `config_local_<CODE_BUCKET>.php` | only with code bucket |
| 8 | `config_propel.php` | always last, builds the Propel DSN from the previously set `PropelConstants::ZED_DB_*` |

Consequences:
- `config_default-docker.php` is loaded **only** with `APPLICATION_ENV=docker`. With `docker.dev`, step 2 loads `config_default-docker.dev.php`, and that file contains no `require` of the base file. Settings for all Docker environments belong in `config_default.php`.
- Nested environments only via `require` in the file itself: `config_default-docker.ci.php` -> `require 'config_default-docker.dev.php'`; `...docker.ci.cypress.php` -> `...docker.ci.php`; `config_default-ci.mysql.php`/`-ci.pgsql.php` -> `config_default-ci.php`.
- `config/Shared/common/config_*.php` (`config_oms-development.php`, `config_oauth.php`, `config_logs-files.php`, `config_services-devvm.php`) are not auto-includes but building blocks that environment files include via `require 'common/...';`.
- The file name pattern in the Docker SDK docs (`config-default_{env}_{store}.php`) is imprecise; in the code, `config_default-<env>_<bucket>.php` applies.

### Code bucket files (steps 3, 4, 7)
`APPLICATION_CODE_BUCKET` comes from `Spryker\Shared\Kernel\CodeBucket\Config\CodeBucketConfig`, which uses `SprykerConfig\CodeBucketConfig` **if the class exists** (in the release: `src/SprykerConfig/CodeBucketConfig.php`, mapped in `composer.json`). Then: bucket = env `SPRYKER_CODE_BUCKET` (nginx sets `fastcgi_param SPRYKER_CODE_BUCKET` per endpoint from `code-bucket`), otherwise `getDefaultCodeBucket()` (URL segment, `APPLICATION_REGION` or first bucket `EU`). The bucket is therefore not empty, bucket files can load. Only without the project class does `DefaultCodeBucketConfig` take effect and return `''` in Dynamic Store mode. `deploy.yml`/`deploy.dev.yml` set `SPRYKER_DYNAMIC_STORE_MODE: true`. Whether `SPRYKER_CODE_BUCKET` is set for the console in the container is unchecked `[Assumption]`. Preferably resolve region/store differences via env (`SPRYKER_CURRENT_REGION`) and the Store module (see [19](19-multi-store-i18n.md)); store files are considered legacy `[Assumption]`.

### Where APPLICATION_ENV, STORE, CODE_BUCKET come from
`Spryker\Shared\Config\Application\Environment::initialize()` (`public/*/index.php`):
- `APPLICATION_ENV`: env variable, alternatively `config/Shared/console_env_local.php` (gitignored, template `console_env_local.dist.php`), otherwise abort.
- `APPLICATION_STORE`: env variable, otherwise `config/Shared/default_store.php` (`getenv('SPRYKER_DEFAULT_STORE') ?: 'DE'`). In Dynamic Store mode it may be missing; `defineStore()`/`defineLocale()` are `[Deprecated]`.
- `APPLICATION_REGION`: only if `SPRYKER_CURRENT_REGION` is set.
- In addition `APPLICATION` (YVES/ZED/GLUE...), `APPLICATION_ROOT_DIR`, `APPLICATION_SOURCE_DIR`, `APPLICATION_STATIC_DIR`, `APPLICATION_VENDOR_DIR`, `APPLICATION_DATA` (`Environment::defineApplicationDataDir()`, default `APPLICATION_ROOT_DIR/data`).

### Role of deploy.yml and stores.php
- `environment: docker.dev` (`deploy.dev.yml`) or `docker.production` (`deploy.yml`) becomes `APPLICATION_ENV` and selects the environment file (step 2). The Docker SDK generator renders all other values into `docker/deployment/default/env/<app>_<region>.env` (`SPRYKER_*`); the PHP config reads them via `getenv()`. `image: environment:` adds your own variables; they are embedded in the images (a change requires a rebuild).
- `stores.php` is not a `$config` file but the data source of the legacy Store module (`Spryker\Shared\Kernel\Store`): contexts, locales, countries, currencies, queue pools. With `SPRYKER_ACTIVE_STORES` (comma list), `$stores` is built from templates, otherwise the static arrays `DE`, `AT`, `US` apply. In Dynamic Store mode, stores come from the DB (Back Office), see [19](19-multi-store-i18n.md).

### Reading values: Constants and Config (three levels)
1. **Constant** in `Spryker\Shared\<Module>\<Module>Constants` (interface); header comment of the core files: "Declares global environment configuration keys. Do not use it for other class constants." Newer keys with prefix `'MODULE:NAME'` (e.g. `'CONSOLE:ENABLE_DEVELOPMENT_CONSOLE_COMMANDS'`), older ones without (`'ACTIVE_PROCESSES'`, risk of collision).
2. **Set the value** in `config/Shared/config_*.php`: `$config[OmsConstants::ACTIVE_PROCESSES] = [...]`.
3. **Read the value** only in the module config class (`Spryker\Zed\Kernel\AbstractBundleConfig` or the Yves/Client/Glue/Service counterpart): `$this->get(KEY, $default)` -> `Spryker\Shared\Config\Config::get($key, $default)`.

`Config::get()`: key missing **and** `$default === null` -> `Exception('Could not find config key ...')`. `hasValue()` uses `isset()`, a key set to `null` counts as missing. Accesses end up in the config profiler (web profiler in dev mode; no `config:*` console command found `[Assumption]`).

**Shared vs. layer config:** `Spryker\Shared\<Module>\<Module>Config` (base class `Spryker\Shared\Kernel\AbstractBundleConfig`; e.g. `src/Pyz/Shared/Agent/AgentConfig.php` extends the core `AgentConfig`, which extends `AbstractBundleConfig`) for values that several applications need; access from the layer config via `getSharedConfig()` (`SharedConfigResolverAwareTrait`, annotate with `@method`; evidence `vendor/spryker/agent/.../Zed/Agent/AgentConfig.php`). Pyz config **extends** the core config (`src/Pyz/Zed/Oms/OmsConfig.php` overrides `getFallbackDisplayNamePrefix()`). Rarely changing business defaults go into the config class as `protected const`/method, not into `config_default.php`.

`Config::get()` used directly outside a config class occurs in Pyz (`src/Pyz/Zed/Queue/QueueConfig.php:48`, `QueueDependencyProvider.php:75`), but is a **deviation** from the convention `[Assumption]`. New code: config class.

**New in 202610.0:** `Zed\Kernel\AbstractBundleConfig` has `protected getModuleConfig()` and `getModuleConfigValues()`. They read via `ConfigurationFacade::getConfigurationValue()` (maintainable in the Back Office) **if** `\Spryker\Zed\Configuration\Business\ConfigurationFacadeInterface` exists. In the release only `vendor/spryker/configuration-extension` is installed, not `configuration` -> always default. `[Assumption]`: feature optional, not active in the b2b-demo-shop. Env files contain `SPRYKER_CONFIGURATION_ENCRYPTION_KEY`/`..._INIT_VECTOR` (names confirmed).

### Secrets
Pattern in `config/Shared/config_default.php` (from ~line 262): secrets via `getenv()`, never in the code: `CustomerPageConstants::CUSTOMER_REMEMBER_ME_SECRET` <- `SPRYKER_CUSTOMER_REMEMBER_ME_SECRET`; `OauthConstants::PRIVATE_KEY_PATH`/`PUBLIC_KEY_PATH` <- `SPRYKER_OAUTH_KEY_PRIVATE`/`_PUBLIC` (line breaks as `__LINE__`, `str_replace('__LINE__', PHP_EOL, ...)`); `OauthConstants::ENCRYPTION_KEY` <- `SPRYKER_OAUTH_ENCRYPTION_KEY`; `SecuritySystemUserConstants::AUTH_DEFAULT_CREDENTIALS['yves_system']['token']` <- `SPRYKER_ZED_REQUEST_TOKEN`; `HttpConstants::URI_SIGNER_SECRET_KEY` <- `SPRYKER_URI_SIGNER_SECRET_KEY`; DB `SPRYKER_DB_USERNAME`/`_PASSWORD`.

Weak spots in the release (dev only, replace in production):
- `config_default.php:460`: `SPRYKER_PRODUCT_CONFIGURATOR_ENCRYPTION_KEY` with fallback `'change123'` and a fixed IV; `deploy.yml` also sets `'change123'`.
- `config_default-docker.php`: fixed dev keys (`OauthConstants::ENCRYPTION_KEY`, `OAUTH_CLIENT_SECRET = 'abc123'`, `config/Zed/dev_only_*.key`).
- `deploy.dev.yml`: `password: secret` for the DB.

Rules: in production, secrets only as env variables from the platform's secret store `[Assumption]`, never in `config_default*.php`, never in Git. Locally in `config/Shared/config_local.php` (template `config_local.dist.php`). `.gitignore`: `/config/Shared/config_local*` (except `.dist`), `/config/Shared/console_env_local.php`, `docker`. The generated `docker/deployment/default/env/*.env` contain dev values; do not commit them and do not use them in production ([30](30-docker-sdk-workflow.md)).

### Environment files in the release
| File | Content |
|---|---|
| `config_default.php` (961 lines) | Base: namespaces, router, logging, Propel/Redis/Search/Broker/Mail, URLs, Glue hosts, AOP, Message Broker |
| `config_default-docker.php` (338) | Environment `docker`: hosts/ports/base URLs without SSL, debug from `SPRYKER_DEBUG_ENABLED`, DB fallback PGSQL, dev keys |
| `config_default-docker.dev.php` (209) | `docker.dev`: debug, WebProfiler, error renderer, Redis dev, queue worker = 1, log level |
| `config_default-docker.production.php` (183) | `docker.production` (local prod image): WebProfiler off, Twig cache, `ZED_NAVIGATION_CACHE_ENABLED` |
| `config_default-docker.demo.php` (13) | OMS processes only |
| `config_default-docker.ci.php`/`.ci.cypress.php`, `config_default-ci*.php` | CI |
| `config_propel.php` | last; DSN, connections, paths (`src/Orm/Propel/{Schema,Migration_<engine>,Sql}`) |
| `config_local.dist.php`, `console_env_local.dist.php` | Templates |
| `default_store.php`, `stores.php`, `common/` | see above |

Further configuration outside `config/Shared`: `config/Yves/frontend-build-config.json` ([15](15-yves-frontend-atomic.md)), `config/Zed/navigation.xml` ([18](18-back-office.md)), `config/Zed/oms/*.xml` ([10](10-oms-state-machine.md)), `config/Zed/cronjobs/jenkins.php` ([22](22-console-cron-queue.md)), `config/install/**` ([30](30-docker-sdk-workflow.md)).

## Instructions
Introduce a new configuration value:
1. Key in `Pyz\Shared\<Module>\<Module>Constants` (interface, prefix `'MODULE:NAME'`, `@api`).
2. Getter in `Pyz\Zed\<Module>\<Module>Config` (`$this->get(KEY, $default)`, specify return type and default).
3. Set the value in `config/Shared/config_default.php` via `getenv()` (environment-specific values in `config_default-<env>.php`, shared ones in `config/Shared/common/`).
4. Provide the env variable: `deploy*.yml` -> `image: environment:` (dev dummy values only) and `docker/sdk up --build`; local secrets in `config/Shared/config_local.php`.
5. Access only via `$this->getConfig()` in the Factory; models get the config injected ([02](02-project-module.md)).

Commands (all `[live verified (202610.0)]`):
```bash
docker/sdk cli                               # shell in the application container (Spryker env set)
docker/sdk cli env | grep -E '^(APPLICATION|SPRYKER)_' | sort   # caution: secrets in the output
docker/sdk up --build                        # after changing deploy*.yml: regenerate env files, build images
docker/sdk up                                # without rebuild
```
Outside the container: `APPLICATION_ENV=docker.dev APPLICATION_STORE=DE vendor/bin/console ...` or `config/Shared/console_env_local.php` (`<?php return 'docker.dev';`).

## Examples

`src/Pyz/Shared/Console/ConsoleConstants.php` (real):
```php
declare(strict_types = 1);

namespace Pyz\Shared\Console;

interface ConsoleConstants
{
    /**
     * Specification:
     * - Enables commands from modules included by require-dev composer section.
     * - Must be set to false for environments where composer install --no-dev is performed.
     *
     * @api
     */
    public const ENABLE_DEVELOPMENT_CONSOLE_COMMANDS = 'CONSOLE:ENABLE_DEVELOPMENT_CONSOLE_COMMANDS';
}
```
`src/Pyz/Zed/Console/ConsoleConfig.php` (real):
```php
declare(strict_types = 1);

namespace Pyz\Zed\Console;

use Pyz\Shared\Console\ConsoleConstants;
use Spryker\Zed\Console\ConsoleConfig as SprykerConsoleConfig;

class ConsoleConfig extends SprykerConsoleConfig
{
    public function isDevelopmentConsoleCommandsEnabled(): bool
    {
        return $this->get(ConsoleConstants::ENABLE_DEVELOPMENT_CONSOLE_COMMANDS, false);
    }
}
```
Set the value (`config/Shared/config_default.php:195`):
```php
$config[ConsoleConstants::ENABLE_DEVELOPMENT_CONSOLE_COMMANDS] = (bool)getenv('DEVELOPMENT_CONSOLE_COMMANDS');
```
Override the shared config (`src/Pyz/Shared/Agent/AgentConfig.php`, real; the Zed config fetches the value via `getSharedConfig()`):
```php
declare(strict_types = 1);

namespace Pyz\Shared\Agent;

use Spryker\Shared\Agent\AgentConfig as SprykerAgentConfig;

class AgentConfig extends SprykerAgentConfig
{
    public function isSalesOrderAgentEnabled(): bool
    {
        return true;
    }
}
```
Own config scaffold (template, module `ExampleShop` invented, not from the release). `src/Pyz/Shared/ExampleShop/ExampleShopConstants.php`:
```php
declare(strict_types = 1);

namespace Pyz\Shared\ExampleShop;

interface ExampleShopConstants
{
    /**
     * Specification:
     * - Defines the API token of the external service.
     *
     * @api
     */
    public const API_TOKEN = 'EXAMPLE_SHOP:API_TOKEN';

    /**
     * Specification:
     * - Defines the request timeout in seconds.
     *
     * @api
     */
    public const REQUEST_TIMEOUT = 'EXAMPLE_SHOP:REQUEST_TIMEOUT';
}
```
`src/Pyz/Zed/ExampleShop/ExampleShopConfig.php`:
```php
declare(strict_types = 1);

namespace Pyz\Zed\ExampleShop;

use Pyz\Shared\ExampleShop\ExampleShopConstants;
use Spryker\Zed\Kernel\AbstractBundleConfig;

class ExampleShopConfig extends AbstractBundleConfig
{
    protected const int DEFAULT_REQUEST_TIMEOUT = 5;

    public function getApiToken(): string
    {
        return (string)$this->get(ExampleShopConstants::API_TOKEN, '');
    }

    public function getRequestTimeout(): int
    {
        return (int)$this->get(ExampleShopConstants::REQUEST_TIMEOUT, static::DEFAULT_REQUEST_TIMEOUT);
    }
}
```
(Typed class constants `protected const int` require PHP >= 8.3; the release uses `spryker/php:8.4` according to `deploy.yml`. Core code still writes `@var int` without a type; both are allowed.) Value in `config/Shared/config_default.php`, via env only:
```php
use Pyz\Shared\ExampleShop\ExampleShopConstants;

$config[ExampleShopConstants::API_TOKEN] = getenv('EXAMPLE_SHOP_API_TOKEN') ?: '';
$config[ExampleShopConstants::REQUEST_TIMEOUT] = (int)(getenv('EXAMPLE_SHOP_REQUEST_TIMEOUT') ?: 5);
```
Evaluate env in an environment file (`config_default-docker.dev.php`, real):
```php
$config[ApplicationConstants::ENABLE_APPLICATION_DEBUG]
    = $config[ShopApplicationConstants::ENABLE_APPLICATION_DEBUG]
    = (bool)getenv('SPRYKER_DEBUG_ENABLED');

$config[QueueConstants::QUEUE_ADAPTER_CONFIGURATION][EventConstants::EVENT_QUEUE][QueueConfig::CONFIG_MAX_WORKER_NUMBER] = 1;
```
`SPRYKER_DEBUG_ENABLED=1` is in the generated `*.env` (template `common.env.twig`, depending on `docker: debug: enabled:`).

Extend arrays instead of overwriting them (`config/Shared/common/config_oms-development.php`, real):
```php
$config[OmsConstants::ACTIVE_PROCESSES] = array_merge([
    'DummyPayment01',
    'Nopayment01',
], $config[OmsConstants::ACTIVE_PROCESSES]);

$config[SalesConstants::PAYMENT_METHOD_STATEMACHINE_MAPPING] = array_replace(
    $config[SalesConstants::PAYMENT_METHOD_STATEMACHINE_MAPPING],
    [ DummyPaymentConfig::PAYMENT_METHOD_INVOICE => 'DummyPayment01' ]
);
```

## Pitfalls
1. A setting in `config_default-docker.php` has no effect with `docker.dev` -> put it in `config_default.php` or `config_default-docker.dev.php`.
2. `Config::get('KEY')` without a default throws on a missing/`null` key -> specify a default.
3. Bucket/store files: whether `config_default_DE.php` or similar takes effect depends on the code bucket (project class `SprykerConfig\CodeBucketConfig`, env `SPRYKER_CODE_BUCKET`); do not rely on it, resolve the region via env/Store module.
4. A later file overwrites an array completely -> `array_merge`/`array_replace`/`$config[X][] = ...`.
5. Env change in `deploy.yml` without rebuild (`image: environment:` is embedded).
6. `config_propel.php` reads DB values, it does not set them: DB variables must be set before step 8.
7. `*Constants` only for configuration keys, not for other class constants.
8. Constants without a module prefix collide -> `'MODULE:NAME'`.
9. `(bool)getenv('X')`: `'0'` is `false`, `'false'` is `true`. Set boolean env values as `0`/`1`.
10. Secrets in the repo (`'change123'`, `'abc123'`, `password: secret`) are for dev only; override them in production.
11. `getenv()` only in `config/Shared/*.php`; do not read configuration in Facade/business models via `Config::get()`.
12. Anti-patterns for Upgrader/Evaluator `[Assumption]` (see [28](28-upgradability.md)): changes to `vendor/`, copying the entire core config to Pyz instead of `extends`, deleting core keys from `config_default.php` instead of overriding them in an environment file, hardcoding hosts.

13. Lists such as `OmsConstants::ACTIVE_PROCESSES` are set in `config_default.php:755` and `config_default-docker.php:123` via the assignment `=`; `config_default-docker.dev.php` includes `common/config_oms-development.php`, which extends it with `array_merge`. Your own process must be appended in the **effective** environment file (`$config[X][] = '...'` or `array_merge`), not via an assignment in a file loaded earlier (it gets overwritten, see step 2 of the load order).

## Checklist
- [ ] Value resolved first via the config class/`$config[...]` before classes are overridden
- [ ] Constant in `*Constants` (prefix `'MODULE:NAME'`), getter in `*Config` with default and type
- [ ] Value in the right file: shared in `config_default.php`, environment-specific in `config_default-<env>.php`
- [ ] Secrets only via `getenv()`/secret store, nothing in Git, dev defaults replaced in production
- [ ] Arrays extended with `array_merge`/`array_replace`
- [ ] Boolean env values as `0`/`1`
- [ ] Pyz config `extends` the core config, only the necessary method overridden
- [ ] Rebuilt after changing `deploy*.yml` (`docker/sdk up --build`)
- [ ] Shared config only for cross-application values

## Deprecation notes
| Element | Status |
|---|---|
| `Spryker\Shared\Config` (`vendor/spryker/config/src/Spryker/Shared/Config.php`) | `[Deprecated]`, use `Spryker\Shared\Config\Config` |
| `Spryker\Shared\Config\Environment` | `[Deprecated]` "Will be removed without replacement" |
| `Config::defineCodeBucket()` | `[Deprecated]` "Exists for BC reasons" |
| `Application\Environment::defineStore()`/`defineLocale()` | `[Deprecated]` (dynamic stores are based on the channel/store context) |
| `DefaultCodeBucketConfig::resolveDynamicStoreMode()` | `[Deprecated]` "Will be removed without replacement" |
| `SprykerConfig\CodeBucketConfig::getDefaultCodeBucket()` | `[Deprecated]` "will be removed when environment configs are cleaned up" |
| `stores.php`/`default_store.php`/`storesWithSharedPersistence` | `[Assumption]` legacy, not authoritative in Dynamic Store mode |
| Docs "configuration-management" | As of 16.06.2021, probably outdated; code takes precedence |

## Sources
Docs (base `https://docs.spryker.com/docs/`): `dg/dev/backend-development/data-manipulation/configuration-management.html` (content not extractable, not checked); `dg/dev/sdks/the-docker-sdk/docker-sdk-configuration-reference.html` (cross-checked locally via `docker/docs/10-configuration-reference.md`); `docker/docs/07-deploy-file/02-deploy.file.reference.v1.md`.

Code: `vendor/spryker/config/src/Spryker/Shared/Config/{Config.php,Application/Environment.php}`, `vendor/spryker/kernel/src/Spryker/Shared/Kernel/{AbstractBundleConfig,AbstractSharedConfig,SharedConfigResolverAwareTrait,KernelConstants,Store}.php`, `.../CodeBucket/Config/{CodeBucketConfig,DefaultCodeBucketConfig,AbstractCodeBucketConfig}.php`, `vendor/spryker/kernel/src/Spryker/Zed/Kernel/AbstractBundleConfig.php`, `vendor/spryker/oms/.../OmsConfig.php`, `.../Shared/Oms/OmsConstants.php`, `vendor/spryker/agent/.../Zed/Agent/AgentConfig.php`, `config/Shared/**`, `src/SprykerConfig/CodeBucketConfig.php`, `src/Pyz/Shared/{Console,Agent,Scheduler}/`, `src/Pyz/Zed/{Console,Oms,Queue}/`, `public/Yves/index.php`, `deploy.yml`, `deploy.dev.yml`, `docker/generator/src/templates/{env/common.env.twig,nginx/http/region.params.conf.twig}`, `.gitignore`.

Neighbors: [01 Architecture](01-architecture.md), [02 Project module](02-project-module.md), [03 Extending the core](03-extending-the-core.md), [19 Multi-store](19-multi-store-i18n.md), [30 Docker SDK](30-docker-sdk-workflow.md).
