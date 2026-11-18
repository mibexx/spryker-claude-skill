# Konfiguration und Umgebungen (Recherche T-06)

> Status: Release 202610.0 (spryker/kernel 3.87.1). `[code-verifiziert, Doku nicht abgeglichen]`: Die Doku-Seite "configuration-management" (Stand 2021) war nicht extrahierbar; die Docker-SDK-Referenz wurde lokal (`docker/docs/`) gegengelesen. Lücken: Ladereihenfolge nur aus Code gelesen, nicht per Debug im Container bestätigt; Secret-Verwaltung in Spryker Cloud (PaaS) nicht lokal prüfbar; Modul `configuration` (Back-Office-Konfiguration) nicht installiert. Befehle: `docker/sdk cli` und `docker/sdk console` wurden live ausgeführt `[live verifiziert (202610.0)]`, `docker/sdk up` und `up --build` ebenfalls `[live verifiziert (202610.0)]` (TTY nötig). **Korrektur zur Recherche:** `src/SprykerConfig/CodeBucketConfig.php` existiert im Release (T-06 nahm das Gegenteil an), siehe Abschnitt Code-Bucket-Dateien.

## Zweck
Wo Konfigurationswerte definiert, geladen und gelesen werden (`config/Shared/*.php`, `*Constants`, `*Config`), wie Umgebungen und Secrets behandelt werden. `deploy.yml`, Docker SDK, Install-Recipes: [30](30-docker-sdk-workflow.md). Config als Erweiterungsstrategie: [03](03-core-erweitern.md). Stores fachlich: [19](19-multi-store-i18n.md).

## Konzepte

### Ladereihenfolge
`vendor/spryker/config/src/Spryker/Shared/Config/Config.php::init()`. Alle Dateien liegen in `config/Shared/`, heißen `config_<typ>.php` und werden per `include` in dasselbe `$config`-ArrayObject geladen; fehlende Dateien werden übersprungen, spätere überschreiben frühere Schlüssel.

| # | Datei | Bedingung |
|---|---|---|
| 1 | `config_default.php` | immer |
| 2 | `config_default-<APPLICATION_ENV>.php` | z. B. `config_default-docker.dev.php` |
| 3 | `config_default_<CODE_BUCKET>.php` | nur wenn `APPLICATION_CODE_BUCKET !== ''` |
| 4 | `config_default-<ENV>_<CODE_BUCKET>.php` | nur mit Code-Bucket |
| 5 | `config_local_test.php` | optional |
| 6 | `config_local.php` | optional, gitignored |
| 7 | `config_local_<CODE_BUCKET>.php` | nur mit Code-Bucket |
| 8 | `config_propel.php` | immer zuletzt, baut den Propel-DSN aus den vorher gesetzten `PropelConstants::ZED_DB_*` |

Konsequenzen:
- `config_default-docker.php` wird **nur** bei `APPLICATION_ENV=docker` geladen. Bei `docker.dev` lädt Schritt 2 `config_default-docker.dev.php`, und diese enthält kein `require` auf die Basisdatei. Einstellungen für alle Docker-Umgebungen gehören in `config_default.php`.
- Verschachtelte Umgebungen nur per `require` in der Datei selbst: `config_default-docker.ci.php` -> `require 'config_default-docker.dev.php'`; `...docker.ci.cypress.php` -> `...docker.ci.php`; `config_default-ci.mysql.php`/`-ci.pgsql.php` -> `config_default-ci.php`.
- `config/Shared/common/config_*.php` (`config_oms-development.php`, `config_oauth.php`, `config_logs-files.php`, `config_services-devvm.php`) sind keine Auto-Includes, sondern Bausteine, die Umgebungsdateien per `require 'common/...';` einbinden.
- Dateinamen-Muster in den Docker-SDK-Docs (`config-default_{env}_{store}.php`) ist ungenau; im Code gilt `config_default-<env>_<bucket>.php`.

### Code-Bucket-Dateien (Schritte 3, 4, 7)
`APPLICATION_CODE_BUCKET` kommt aus `Spryker\Shared\Kernel\CodeBucket\Config\CodeBucketConfig`, die `SprykerConfig\CodeBucketConfig` nutzt, **wenn die Klasse existiert** (im Release: `src/SprykerConfig/CodeBucketConfig.php`, in `composer.json` gemappt). Dann gilt: Bucket = Env `SPRYKER_CODE_BUCKET` (nginx setzt `fastcgi_param SPRYKER_CODE_BUCKET` je Endpoint aus `code-bucket`), sonst `getDefaultCodeBucket()` (URL-Segment, `APPLICATION_REGION` oder erster Bucket `EU`). Der Bucket ist damit nicht leer, Bucket-Dateien können laden. Nur ohne Projektklasse greift `DefaultCodeBucketConfig` und liefert im Dynamic-Store-Modus `''`. `deploy.yml`/`deploy.dev.yml` setzen `SPRYKER_DYNAMIC_STORE_MODE: true`. Ob für die Konsole im Container `SPRYKER_CODE_BUCKET` gesetzt ist, ist ungeprüft `[Annahme]`. Region/Store-Unterschiede bevorzugt über Env (`SPRYKER_CURRENT_REGION`) und Store-Modul lösen (siehe [19](19-multi-store-i18n.md)); Store-Dateien gelten als Legacy `[Annahme]`.

### Woher kommen APPLICATION_ENV, STORE, CODE_BUCKET
`Spryker\Shared\Config\Application\Environment::initialize()` (`public/*/index.php`):
- `APPLICATION_ENV`: Env-Variable, ersatzweise `config/Shared/console_env_local.php` (gitignored, Vorlage `console_env_local.dist.php`), sonst Abbruch.
- `APPLICATION_STORE`: Env-Variable, sonst `config/Shared/default_store.php` (`getenv('SPRYKER_DEFAULT_STORE') ?: 'DE'`). Im Dynamic-Store-Modus darf sie fehlen; `defineStore()`/`defineLocale()` sind `[Deprecated]`.
- `APPLICATION_REGION`: nur wenn `SPRYKER_CURRENT_REGION` gesetzt ist.
- Dazu `APPLICATION` (YVES/ZED/GLUE...), `APPLICATION_ROOT_DIR`, `APPLICATION_SOURCE_DIR`, `APPLICATION_STATIC_DIR`, `APPLICATION_VENDOR_DIR`, `APPLICATION_DATA` (`Environment::defineApplicationDataDir()`, Default `APPLICATION_ROOT_DIR/data`).

### Rolle von deploy.yml und stores.php
- `environment: docker.dev` (`deploy.dev.yml`) bzw. `docker.production` (`deploy.yml`) wird zu `APPLICATION_ENV` und wählt die Umgebungsdatei (Schritt 2). Alle übrigen Werte rendert der Docker-SDK-Generator in `docker/deployment/default/env/<app>_<region>.env` (`SPRYKER_*`); die PHP-Config liest sie per `getenv()`. `image: environment:` fügt eigene Variablen hinzu, sie sind in die Images eingebettet (Änderung braucht Rebuild).
- `stores.php` ist keine `$config`-Datei, sondern Datenquelle des Legacy-Store-Moduls (`Spryker\Shared\Kernel\Store`): Kontexte, Locales, Countries, Währungen, Queue-Pools. Mit `SPRYKER_ACTIVE_STORES` (Komma-Liste) wird `$stores` aus Templates gebaut, sonst gelten die statischen Arrays `DE`, `AT`, `US`. Im Dynamic-Store-Modus kommen Stores aus der DB (Back Office), siehe [19](19-multi-store-i18n.md).

### Werte lesen: Constants und Config (dreistufig)
1. **Konstante** in `Spryker\Shared\<Modul>\<Modul>Constants` (Interface); Kopfkommentar der Core-Dateien: "Declares global environment configuration keys. Do not use it for other class constants." Neuere Schlüssel mit Präfix `'MODUL:NAME'` (z. B. `'CONSOLE:ENABLE_DEVELOPMENT_CONSOLE_COMMANDS'`), ältere ohne (`'ACTIVE_PROCESSES'`, Kollisionsgefahr).
2. **Wert setzen** in `config/Shared/config_*.php`: `$config[OmsConstants::ACTIVE_PROCESSES] = [...]`.
3. **Wert lesen** nur in der Modul-Config-Klasse (`Spryker\Zed\Kernel\AbstractBundleConfig` bzw. Yves/Client/Glue/Service-Pendant): `$this->get(KEY, $default)` -> `Spryker\Shared\Config\Config::get($key, $default)`.

`Config::get()`: Schlüssel fehlt **und** `$default === null` -> `Exception('Could not find config key ...')`. `hasValue()` nutzt `isset()`, ein auf `null` gesetzter Schlüssel zählt als fehlend. Zugriffe landen im Config-Profiler (Web-Profiler im Dev-Modus; kein `config:*`-Konsolenbefehl gefunden `[Annahme]`).

**Shared- vs. Layer-Config:** `Spryker\Shared\<Modul>\<Modul>Config` (Basisklasse `Spryker\Shared\Kernel\AbstractBundleConfig`; z. B. erweitert `src/Pyz/Shared/Agent/AgentConfig.php` die Core-`AgentConfig`, die `AbstractBundleConfig` erweitert) für Werte, die mehrere Applikationen brauchen; Zugriff aus Layer-Config über `getSharedConfig()` (`SharedConfigResolverAwareTrait`, mit `@method` annotieren; Beleg `vendor/spryker/agent/.../Zed/Agent/AgentConfig.php`). Pyz-Config **erweitert** die Core-Config (`src/Pyz/Zed/Oms/OmsConfig.php` überschreibt `getFallbackDisplayNamePrefix()`). Selten ändernde fachliche Defaults als `protected const`/Methode in die Config-Klasse, nicht in `config_default.php`.

`Config::get()` direkt außerhalb einer Config-Klasse kommt in Pyz vor (`src/Pyz/Zed/Queue/QueueConfig.php:48`, `QueueDependencyProvider.php:75`), ist aber eine **Abweichung** von der Konvention `[Annahme]`. Neuer Code: Config-Klasse.

**Neu in 202610.0:** `Zed\Kernel\AbstractBundleConfig` hat `protected getModuleConfig()` und `getModuleConfigValues()`. Sie lesen über `ConfigurationFacade::getConfigurationValue()` (Back Office pflegbar), **wenn** `\Spryker\Zed\Configuration\Business\ConfigurationFacadeInterface` existiert. Im Release ist nur `vendor/spryker/configuration-extension` installiert, nicht `configuration` -> immer Default. `[Annahme]`: Feature optional, im b2b-demo-shop nicht aktiv. Env-Dateien enthalten `SPRYKER_CONFIGURATION_ENCRYPTION_KEY`/`..._INIT_VECTOR` (Namen belegt).

### Secrets
Muster in `config/Shared/config_default.php` (ab ~Z. 262): Secrets per `getenv()`, nie im Code: `CustomerPageConstants::CUSTOMER_REMEMBER_ME_SECRET` <- `SPRYKER_CUSTOMER_REMEMBER_ME_SECRET`; `OauthConstants::PRIVATE_KEY_PATH`/`PUBLIC_KEY_PATH` <- `SPRYKER_OAUTH_KEY_PRIVATE`/`_PUBLIC` (Zeilenumbrüche als `__LINE__`, `str_replace('__LINE__', PHP_EOL, ...)`); `OauthConstants::ENCRYPTION_KEY` <- `SPRYKER_OAUTH_ENCRYPTION_KEY`; `SecuritySystemUserConstants::AUTH_DEFAULT_CREDENTIALS['yves_system']['token']` <- `SPRYKER_ZED_REQUEST_TOKEN`; `HttpConstants::URI_SIGNER_SECRET_KEY` <- `SPRYKER_URI_SIGNER_SECRET_KEY`; DB `SPRYKER_DB_USERNAME`/`_PASSWORD`.

Schwachstellen im Release (nur Dev, in Produktion ersetzen):
- `config_default.php:460`: `SPRYKER_PRODUCT_CONFIGURATOR_ENCRYPTION_KEY` mit Fallback `'change123'` und festem IV; `deploy.yml` setzt ebenfalls `'change123'`.
- `config_default-docker.php`: feste Dev-Schlüssel (`OauthConstants::ENCRYPTION_KEY`, `OAUTH_CLIENT_SECRET = 'abc123'`, `config/Zed/dev_only_*.key`).
- `deploy.dev.yml`: `password: secret` für die DB.

Regeln: Produktiv Secrets nur als Env-Variablen aus dem Secret-Store der Plattform `[Annahme]`, nie in `config_default*.php`, nie in Git. Lokal in `config/Shared/config_local.php` (Vorlage `config_local.dist.php`). `.gitignore`: `/config/Shared/config_local*` (außer `.dist`), `/config/Shared/console_env_local.php`, `docker`. Die generierten `docker/deployment/default/env/*.env` enthalten Dev-Werte, nicht committen und nicht produktiv nutzen ([30](30-docker-sdk-workflow.md)).

### Umgebungsdateien im Release
| Datei | Inhalt |
|---|---|
| `config_default.php` (961 Z.) | Basis: Namespaces, Router, Logging, Propel/Redis/Search/Broker/Mail, URLs, Glue-Hosts, AOP, Message Broker |
| `config_default-docker.php` (338) | Umgebung `docker`: Hosts/Ports/Base-URLs ohne SSL, Debug aus `SPRYKER_DEBUG_ENABLED`, DB-Fallback PGSQL, Dev-Schlüssel |
| `config_default-docker.dev.php` (209) | `docker.dev`: Debug, WebProfiler, Error-Renderer, Redis dev, Queue-Worker = 1, Log-Level |
| `config_default-docker.production.php` (183) | `docker.production` (lokales Prod-Image): WebProfiler aus, Twig-Cache, `ZED_NAVIGATION_CACHE_ENABLED` |
| `config_default-docker.demo.php` (13) | nur OMS-Prozesse |
| `config_default-docker.ci.php`/`.ci.cypress.php`, `config_default-ci*.php` | CI |
| `config_propel.php` | zuletzt; DSN, Connections, Pfade (`src/Orm/Propel/{Schema,Migration_<engine>,Sql}`) |
| `config_local.dist.php`, `console_env_local.dist.php` | Vorlagen |
| `default_store.php`, `stores.php`, `common/` | siehe oben |

Weitere Konfiguration außerhalb `config/Shared`: `config/Yves/frontend-build-config.json` ([15](15-yves-frontend-atomic.md)), `config/Zed/navigation.xml` ([18](18-back-office.md)), `config/Zed/oms/*.xml` ([10](10-oms-state-machine.md)), `config/Zed/cronjobs/jenkins.php` ([22](22-console-cron-queue.md)), `config/install/**` ([30](30-docker-sdk-workflow.md)).

## Anleitung
Neuen Konfigurationswert einführen:
1. Schlüssel in `Pyz\Shared\<Modul>\<Modul>Constants` (Interface, Präfix `'MODUL:NAME'`, `@api`).
2. Getter in `Pyz\Zed\<Modul>\<Modul>Config` (`$this->get(KEY, $default)`, Rückgabetyp, Default angeben).
3. Wert in `config/Shared/config_default.php` per `getenv()` setzen (Env-spezifisches in `config_default-<env>.php`, Gemeinsames in `config/Shared/common/`).
4. Env-Variable bereitstellen: `deploy*.yml` -> `image: environment:` (nur Dev-Dummywerte) und `docker/sdk up --build`; lokale Secrets in `config/Shared/config_local.php`.
5. Zugriff nur über `$this->getConfig()` in Factory; Modelle bekommen die Config injiziert ([02](02-projektmodul.md)).

Befehle (alle `[live verifiziert (202610.0)]`):
```bash
docker/sdk cli                               # Shell im Anwendungs-Container (Spryker-Env gesetzt)
docker/sdk cli env | grep -E '^(APPLICATION|SPRYKER)_' | sort   # Achtung: Secrets in der Ausgabe
docker/sdk up --build                        # nach Änderung von deploy*.yml: Env-Dateien neu generieren, Images bauen
docker/sdk up                                # ohne Rebuild
```
Außerhalb des Containers: `APPLICATION_ENV=docker.dev APPLICATION_STORE=DE vendor/bin/console ...` oder `config/Shared/console_env_local.php` (`<?php return 'docker.dev';`).

## Beispiele

`src/Pyz/Shared/Console/ConsoleConstants.php` (echt):
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
`src/Pyz/Zed/Console/ConsoleConfig.php` (echt):
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
Wert setzen (`config/Shared/config_default.php:195`):
```php
$config[ConsoleConstants::ENABLE_DEVELOPMENT_CONSOLE_COMMANDS] = (bool)getenv('DEVELOPMENT_CONSOLE_COMMANDS');
```
Shared-Config überschreiben (`src/Pyz/Shared/Agent/AgentConfig.php`, echt; die Zed-Config holt den Wert über `getSharedConfig()`):
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
Eigenes Config-Gerüst (Vorlage, Modul `ExampleShop` erfunden, nicht aus dem Release). `src/Pyz/Shared/ExampleShop/ExampleShopConstants.php`:
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
(Typisierte Klassenkonstanten `protected const int` brauchen PHP >= 8.3; das Release nutzt `spryker/php:8.4` laut `deploy.yml`. Core-Code schreibt noch `@var int` ohne Typ; beides zulässig.) Wert in `config/Shared/config_default.php`, nur per Env:
```php
use Pyz\Shared\ExampleShop\ExampleShopConstants;

$config[ExampleShopConstants::API_TOKEN] = getenv('EXAMPLE_SHOP_API_TOKEN') ?: '';
$config[ExampleShopConstants::REQUEST_TIMEOUT] = (int)(getenv('EXAMPLE_SHOP_REQUEST_TIMEOUT') ?: 5);
```
Env in Umgebungsdatei auswerten (`config_default-docker.dev.php`, echt):
```php
$config[ApplicationConstants::ENABLE_APPLICATION_DEBUG]
    = $config[ShopApplicationConstants::ENABLE_APPLICATION_DEBUG]
    = (bool)getenv('SPRYKER_DEBUG_ENABLED');

$config[QueueConstants::QUEUE_ADAPTER_CONFIGURATION][EventConstants::EVENT_QUEUE][QueueConfig::CONFIG_MAX_WORKER_NUMBER] = 1;
```
`SPRYKER_DEBUG_ENABLED=1` steht in den generierten `*.env` (Template `common.env.twig`, abhängig von `docker: debug: enabled:`).

Arrays ergänzen statt überschreiben (`config/Shared/common/config_oms-development.php`, echt):
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

## Fallstricke
1. Einstellung in `config_default-docker.php` wirkt bei `docker.dev` nicht -> in `config_default.php` oder `config_default-docker.dev.php`.
2. `Config::get('KEY')` ohne Default wirft bei fehlendem/`null`-Schlüssel -> Default angeben.
3. Bucket-/Store-Dateien: ob `config_default_DE.php` o. ä. greift, hängt vom Code-Bucket ab (Projektklasse `SprykerConfig\CodeBucketConfig`, Env `SPRYKER_CODE_BUCKET`); nicht darauf verlassen, Region über Env/Store-Modul lösen.
4. Spätere Datei überschreibt Array komplett -> `array_merge`/`array_replace`/`$config[X][] = ...`.
5. Env-Änderung in `deploy.yml` ohne Rebuild (`image: environment:` ist eingebettet).
6. `config_propel.php` liest DB-Werte, setzt sie nicht: DB-Variablen müssen vor Schritt 8 gesetzt sein.
7. `*Constants` nur für Konfigurationsschlüssel, nicht für andere Klassenkonstanten.
8. Konstanten ohne Modul-Präfix kollidieren -> `'MODUL:NAME'`.
9. `(bool)getenv('X')`: `'0'` ist `false`, `'false'` ist `true`. Boolesche Env-Werte als `0`/`1` setzen.
10. Secrets im Repo (`'change123'`, `'abc123'`, `password: secret`) nur für Dev; Produktion überschreiben.
11. `getenv()` nur in `config/Shared/*.php`; Konfiguration nicht in Facade/Business-Modellen per `Config::get()` lesen.
12. Anti-Pattern für Upgrader/Evaluator `[Annahme]` (siehe [28](28-upgradability.md)): Änderungen an `vendor/`, ganze Core-Config nach Pyz kopieren statt `extends`, Core-Schlüssel aus `config_default.php` löschen statt in Umgebungsdatei überschreiben, Hosts hartcodieren.

13. Listen wie `OmsConstants::ACTIVE_PROCESSES` werden in `config_default.php:755` und `config_default-docker.php:123` per Zuweisung `=` gesetzt; `config_default-docker.dev.php` bindet `common/config_oms-development.php` ein, das mit `array_merge` ergänzt. Ein eigener Prozess muss in der **wirksamen** Umgebungsdatei angehängt werden (`$config[X][] = '...'` oder `array_merge`), nicht per Zuweisung in einer früher geladenen Datei (wird überschrieben, siehe Schritt 2 der Ladereihenfolge).

## Checkliste
- [ ] Wert zuerst über Config-Klasse/`$config[...]` gelöst, bevor Klassen überschrieben werden
- [ ] Konstante in `*Constants` (Präfix `'MODUL:NAME'`), Getter in `*Config` mit Default und Typ
- [ ] Wert in richtiger Datei: gemeinsam `config_default.php`, umgebungsspezifisch `config_default-<env>.php`
- [ ] Secrets nur per `getenv()`/Secret-Store, nichts in Git, Dev-Defaults produktiv ersetzt
- [ ] Arrays mit `array_merge`/`array_replace` ergänzt
- [ ] Boolesche Env-Werte als `0`/`1`
- [ ] Pyz-Config `extends` Core-Config, nur nötige Methode überschrieben
- [ ] Nach Änderung von `deploy*.yml` neu gebaut (`docker/sdk up --build`)
- [ ] Shared-Config nur für applikationsübergreifende Werte

## Deprecation-Hinweise
| Element | Status |
|---|---|
| `Spryker\Shared\Config` (`vendor/spryker/config/src/Spryker/Shared/Config.php`) | `[Deprecated]`, `Spryker\Shared\Config\Config` nutzen |
| `Spryker\Shared\Config\Environment` | `[Deprecated]` "Will be removed without replacement" |
| `Config::defineCodeBucket()` | `[Deprecated]` "Exists for BC reasons" |
| `Application\Environment::defineStore()`/`defineLocale()` | `[Deprecated]` (Dynamic Stores basieren auf Channel-/Store-Kontext) |
| `DefaultCodeBucketConfig::resolveDynamicStoreMode()` | `[Deprecated]` "Will be removed without replacement" |
| `SprykerConfig\CodeBucketConfig::getDefaultCodeBucket()` | `[Deprecated]` "will be removed when environment configs are cleaned up" |
| `stores.php`/`default_store.php`/`storesWithSharedPersistence` | `[Annahme]` Legacy, im Dynamic-Store-Modus nicht maßgeblich |
| Doku "configuration-management" | Stand 16.06.2021, vermutlich veraltet; Code hat Vorrang |

## Quellen
Doku (Basis `https://docs.spryker.com/docs/`): `dg/dev/backend-development/data-manipulation/configuration-management.html` (Inhalt nicht extrahierbar, nicht geprüft); `dg/dev/sdks/the-docker-sdk/docker-sdk-configuration-reference.html` (lokal gegengeprüft über `docker/docs/10-configuration-reference.md`); `docker/docs/07-deploy-file/02-deploy.file.reference.v1.md`.

Code: `vendor/spryker/config/src/Spryker/Shared/Config/{Config.php,Application/Environment.php}`, `vendor/spryker/kernel/src/Spryker/Shared/Kernel/{AbstractBundleConfig,AbstractSharedConfig,SharedConfigResolverAwareTrait,KernelConstants,Store}.php`, `.../CodeBucket/Config/{CodeBucketConfig,DefaultCodeBucketConfig,AbstractCodeBucketConfig}.php`, `vendor/spryker/kernel/src/Spryker/Zed/Kernel/AbstractBundleConfig.php`, `vendor/spryker/oms/.../OmsConfig.php`, `.../Shared/Oms/OmsConstants.php`, `vendor/spryker/agent/.../Zed/Agent/AgentConfig.php`, `config/Shared/**`, `src/SprykerConfig/CodeBucketConfig.php`, `src/Pyz/Shared/{Console,Agent,Scheduler}/`, `src/Pyz/Zed/{Console,Oms,Queue}/`, `public/Yves/index.php`, `deploy.yml`, `deploy.dev.yml`, `docker/generator/src/templates/{env/common.env.twig,nginx/http/region.params.conf.twig}`, `.gitignore`.

Nachbarn: [01 Architektur](01-architektur.md), [02 Projektmodul](02-projektmodul.md), [03 Core erweitern](03-core-erweitern.md), [19 Multi-Store](19-multi-store-i18n.md), [30 Docker SDK](30-docker-sdk-workflow.md).
