# Features integrieren und Projekt-Abhängigkeiten verwalten (Recherche T-29)

> Status: Release 202610.0 (Commit d45396b8; spryker/kernel 3.87.1, spryker/oms 11.55.0; Symfony 6.4.48). Dateibefunde direkt am Dateisystem geprüft; Doku-Seiten (Cart-Guide „Last updated June 16, 2021“, Composer-Guide mit PHP 7.4 im Beispiel) sind veraltet: `[code-verifiziert, Doku nicht abgeglichen]`, Code gilt. Konsolenbefehle im Container ausgeführt `[live verifiziert (202610.0)]`; `composer`-Aufrufe ausgeführt `[live verifiziert (202610.0)]` (`require` mit `--dry-run`, damit `composer.json` unverändert bleibt). **Nicht registriert** (entfernt): `dev:dependency:*`, `dev:composer:*`. Beim Code-Abgleich bestätigt: `CartPageDependencyProvider::getMiniCartViewExpanderPlugins()` existiert in `vendor/spryker-shop/cart-page` (in der Recherche noch als ungeprüft markiert).

## Zweck

Spryker-Features (`spryker-feature/*`) per Integration Guide installieren, prüfen, dass die Integration vollständig ist, Abhängigkeiten und Autoloading verstehen, Standalone-Module veröffentlichen. Updates bestehender Module → [Upgradability](28-upgradability.md); Docker/`deploy.yml` → [Docker SDK Workflow](30-docker-sdk-workflow.md).

## Konzepte

**Feature = Composer-Metapaket.** `"type": "metapackage"` ohne Code, bündelt `require`-Einträge auf Module (`spryker/*`, `spryker-shop/*`) mit Mindestversionen. `composer.lock` enthält 67 `spryker-feature/*` (alle `metapackage`, von 1232 Paketen); das Projekt-`composer.json` hat 67 im `require`, `spryker-feature/development-tools` im `require-dev`. Beispiel `spryker-feature/cart` 202610.0 → `spryker/cart ^7.18.0`, `spryker/cart-note`, `spryker/cart-variant`, `spryker/persistent-cart`, `spryker/calculation`, `spryker-shop/cart-page ^3.62.0`, `spryker-shop/cart-note-widget`. Die Feature-Versionierung folgt dem Release-Tag (`^202610.0`), Module haben eigene SemVer-Versionen. **`vendor/spryker-feature/` existiert nicht** (Metapakete erzeugen kein Verzeichnis; `vendor/composer/installed.json` listet sie). Die Workspace-Einträge `vendor/spryker-feature/*` in `package.json` laufen daher ins Leere (Zweck nicht belegt `[Annahme]`).

**Integration Guide (Muster).** Doku `pbc/all/<feature>/latest/base-shop/install-and-upgrade/install-features/` (z. B. `install-the-cart-feature.html`), je „Install feature core“ (Backend) und „Install feature frontend“ (Yves), mit Prerequisites und Verifikationsblöcken:
1. `composer require spryker-feature/<name> 202610.0 --update-with-dependencies`, danach Modul-Verifikationstabelle.
2. Glossar/Übersetzungen (`data/import/common/common/glossary.csv`), `data:import glossary` (→ [Data Import](09-data-import.md), [Multi-Store/i18n](19-multi-store-i18n.md)).
3. Konfiguration (Constants, `*Config` in Pyz, → [Konfiguration](06-konfiguration.md)).
4. `transfer:generate`, `propel:install` (→ [Transfer](04-transfer.md), [Propel](05-propel-persistenz.md)).
5. Plugins in Pyz-DependencyProvidern aktivieren (Router, Widgets, Stacks, → [Core erweitern](03-core-erweitern.md), [Yves-Routing](14-yves-controller-routing.md), [Widgets](16-widgets.md)).
6. Daten importieren, `publish:trigger-events`, `sync:data` (→ [Publish & Sync](07-publish-sync.md)).
7. Frontend bauen (→ [Atomic Frontend](15-yves-frontend-atomic.md)).

**Integrator deaktiviert.** `composer.json` → `config.allow-plugins`: `"spryker-sdk/integrator": false`; `vendor/spryker-sdk/` enthält nur benchmark, composer-constrainer, evaluator, phpstan-spryker, spryk, utils. Plugin-Registrierung geschieht **manuell** nach Integration Guide.

**Autoloading.** `composer.json`: `autoload.psr-4` `Generated\` → `src/Generated/`, `Orm\` → `src/Orm/`, `Pyz\` → `src/Pyz/`, `SprykerConfig\` → `src/SprykerConfig/`; `autoload-dev` `PyzTest\` → `tests/PyzTest/` plus `test-autoload.php`. `extra.splitting.namespaces = ["Spryker\\", "SprykerShop\\"]`, verarbeitet vom Plugin `spryker/composer-autoload-plugin` (`Spryker\Composer\Plugin\AutoloadPlugin`, `PRE_AUTOLOAD_DUMP`): zerlegt den PSR-4-Eintrag je Layer/Modul (`Spryker\Zed\Cart\`, `Spryker\Client\Cart\` …) für kleinere Autoload-Maps. Deploy: `composer.mode: --no-dev`, `autoload: --classmap-authoritative`. Kernel-Namespaces `config/Shared/config_default.php`: `PROJECT_NAMESPACES = ['Pyz']`, `CORE_NAMESPACES = ['SprykerShop','SprykerEco','Spryker','SprykerSdk']` (`SprykerFeature` fehlt, unproblematisch: Features haben keinen Code).

**npm-Workspaces** (`package.json`, `private: true`): `vendor/spryker/*`, `vendor/spryker/*/assets/Zed`, `vendor/spryker-feature/*`, `vendor/spryker-feature/*/assets/Zed`, `vendor/spryker-shop/*`. Real vorhanden: `vendor/spryker-shop/shop-ui/package.json` (`shop-ui`), `traceable-event-widget`, Zed-Assets in `vendor/spryker/{chart,discount,gui,product-relation-gui,state-machine}/assets/Zed/package.json`. Skripte: `yves` → `npm run build -w shop-ui --`, `zed` → `npm run build -w spryker-zed-gui --`, `postinstall` → `update:config`. Node `>=24.15.0`, npm `>=10`. Ein neues Modul mit eigenen Assets wird nur gebaut, wenn sein Verzeichnis unter ein Workspace-Glob fällt (Core-Module); Projekt-Assets liegen unter `src/Pyz/Yves/*/Theme/…` bzw. `frontend/`.

**Projekt-Abhängigkeiten.** `composer.lock` ist die Wahrheit; `composer outdated`, `composer update "spryker/*"` innerhalb der Constraints; `config.preferred-install: dist`; `config.platform.php` 8.3.13, `require.php >=8.3`; `minimum-stability: dev`, `prefer-stable: true`. Core-Modul ersetzen per Dummy-Paket `replace_<modul>` mit `"replace": {"spryker/<modul>": "*"}` (Doku); im Release nicht verwendet, upgrader-feindlich (letzter Ausweg). Für angepasste Module `~` statt `^` (→ [Upgradability](28-upgradability.md)); im Release ist der Constrainer nicht registriert und es gibt keinen `~`-Constraint.

**Standalone-Module veröffentlichen** (Doku `publish-standalone-modules-on-packagist`): `composer init` im Modul-Repo (`name`, `description`, `type: library`, `license`, `require` mit PHP >=8.3 und **allen** Modulabhängigkeiten, `autoload.psr-4`), `.gitignore` für `vendor/` und `composer.lock`, Git-Tag (GitHub-Release oder `git tag v1.0.0` + `git push origin v1.0.0`), auf packagist.org einreichen, neue Version = neuer Tag, Installation `composer require vendor/package-name`. Das Doku-Beispiel nutzt `"Vendor\\Package\\": "src/"`; echte Spryker-Module nutzen `"Spryker\\": "src/Spryker/"` (`vendor/spryker/cart/composer.json`). Für ein Spryker-Layout-Modul muss der Namespace zu `CORE_NAMESPACES`/`PROJECT_NAMESPACES` passen, sonst löst der ClassResolver Facade/Factory nicht auf (→ [Architektur](01-architektur.md), [Projektmodul](02-projektmodul.md)). Private Pakete über `repositories` (Beispiel im Release: VCS-Repos `spryker/robotframework-suite-tests`, `spryker/cypress-tests`).

## Anleitung

1. **Voraussetzungen** des Feature-Guides (andere Features in Mindestversion) prüfen.
2. **Installieren:** `docker/sdk cli composer require spryker-feature/<name> 202610.0 --update-with-dependencies`; Verifikationstabelle: `ls vendor/spryker/<modul>` bzw. `docker/sdk cli composer show spryker/<modul>`.
3. **Guide-Schritte** nacheinander abarbeiten (Glossar, Config, Transfers/Propel, Plugins, Daten, Frontend) und jede „Verification“ ausführen.
4. **Plugins nur in Pyz-DependencyProvidern** registrieren (Subklasse des Core-Providers) und mit Feature-Kommentar markieren (`#CartNoteFeature`, `#MultiCartFeature`, `#SharedCartFeature` im Release).
5. **Vollständigkeit prüfen:** `docker/sdk cli console dev:plugin-usage:dump [Modul]` gegen die Plugin-Tabelle des Guides abgleichen; `docker/sdk cli composer why <paket>` bzw. `composer show <paket>` für Abhängigkeits-Konsistenz (die Console-Befehle `dev:dependency:find` und `dev:composer:validate-json-files` sind im Release nicht registriert); Konstanten/Config je Guide-Tabelle; Tests (→ [Tests](26-tests.md)), `code:phpstan` (→ [Coding-Guidelines](27-coding-guidelines.md)).
6. **Evaluator** laufen lassen (→ [Upgradability](28-upgradability.md)).

Befehle (alle ausgeführt `[live verifiziert (202610.0)]`; `composer require` hier nur mit `--dry-run`):
```
docker/sdk cli composer require spryker-feature/<name> 202610.0 --update-with-dependencies   # --dry-run zum Testen (live: Exit 0, listet Upgrades)
docker/sdk cli composer why spryker-shop/cart-page   # Paketname mit Vendor spryker-shop; `spryker/cart-page` gibt es nicht (Exit 1)
docker/sdk cli composer show spryker-feature/cart
docker/sdk cli composer outdated "spryker*/*" --direct   # Composer 2.11 akzeptiert nur EIN Paketargument
docker/sdk cli console transfer:generate
docker/sdk cli console propel:install
docker/sdk cli console data:import glossary
docker/sdk cli console publish:trigger-events
docker/sdk cli console frontend:yves:build          # oder npm run yves / yves:production
docker/sdk cli console frontend:zed:build           # oder npm run zed / zed:production
docker/sdk cli console dev:plugin-usage:dump [Modul]
docker/sdk cli console dev:ide-auto-completion:generate
```
Die Reihenfolge nach Codeänderungen steht in [Docker SDK Workflow](30-docker-sdk-workflow.md).

## Beispiele

**Quote-Strategie** (`src/Pyz/Shared/Quote/QuoteConfig.php`, Release, Cart-Guide Schritt 3):
```php
<?php

declare(strict_types = 1);

namespace Pyz\Shared\Quote;

use Spryker\Shared\Quote\QuoteConfig as SprykerQuoteConfig;

class QuoteConfig extends SprykerQuoteConfig
{
    public function getStorageStrategy(): string
    {
        return static::STORAGE_STRATEGY_DATABASE;
    }
}
```

**CartPage-Konfiguration** (`src/Pyz/Yves/CartPage/CartPageConfig.php`, Auszug, Release; alle drei Flags `true`):
```php
class CartPageConfig extends SprykerCartPageConfig
{
    protected const IS_CART_ACTIONS_ASYNC_MODE_ENABLED = true;
    protected const IS_CART_CART_ITEMS_VIA_AJAX_LOAD_ENABLED = true;
    protected const IS_LOADING_UPSELLING_PRODUCTS_VIA_AJAX_ENABLED = true;
```
Die Doku zeigt `protected function`-Überschreibungen/ältere Konstanten; im Release werden Konstanten in Pyz gesetzt, die die Core-Konstante überschreiben.

**Plugin-Registrierung im DependencyProvider** (`src/Pyz/Yves/CartPage/CartPageDependencyProvider.php`, Release, Auszug; `getMiniCartViewExpanderPlugins()` in `vendor/spryker-shop/cart-page` geprüft):
```php
use SprykerShop\Yves\CartPage\CartPageDependencyProvider as SprykerCartPageDependencyProvider;
use SprykerShop\Yves\MultiCartWidget\Plugin\CartPage\MultiCartMiniCartViewExpanderPlugin;

class CartPageDependencyProvider extends SprykerCartPageDependencyProvider
{
    /**
     * @return array<\SprykerShop\Yves\CartPageExtension\Dependency\Plugin\MiniCartViewExpanderPluginInterface>
     */
    protected function getMiniCartViewExpanderPlugins(): array
    {
        return [
            new MultiCartMiniCartViewExpanderPlugin(),
        ];
    }
}
```

**Router- und Widget-Aktivierung** (Release): `src/Pyz/Yves/Router/RouterDependencyProvider.php` aktiviert `CartPageRouteProviderPlugin`, `CartNoteWidgetRouteProviderPlugin` (Kommentar `#CartNoteFeature`), `CartPageAsyncRouteProviderPlugin`, `CartNoteWidgetAsyncRouteProviderPlugin`; `src/Pyz/Yves/ShopApplication/ShopApplicationDependencyProvider.php` führt in `getGlobalWidgets()` `SprykerShop\Yves\CartPage\Widget\ProductAbstractAddToCartButtonWidget::class` (→ [Widgets](16-widgets.md)).

## Fallstricke

- Dateien in `vendor/` ändern (verloren beim nächsten `composer install`, bricht den Upgrader).
- Core-Modul per `replace_*`-Dummy ersetzen statt über Plugin/Config/Vererbung.
- Plugins im Core-DependencyProvider statt in Pyz eintragen.
- Feature installieren, aber Plugin-/Config-Schritte überspringen: Routen fehlen, Widgets rendern nicht; Transfers/Schema nicht neu generiert → „class not found“ in `Generated\`.
- Nur `composer require spryker/<modul>` statt Feature: Abhängigkeiten und Frontend-Module (`spryker-shop/*`) fehlen.
- Frontend-Build nach Integration vergessen.
- Erwarten, dass `vendor/spryker-feature/` existiert oder der Integrator Plugins einträgt (beides nicht der Fall).
- `^`-Constraints bei überschriebenen Modulen.
- Doku-Beispiel `Vendor\Package\` für Spryker-Layout-Module unverändert übernehmen (ClassResolver findet nichts).
- Die Doku-Integrationsseiten unter `dg/dev/integrate-and-configure/` behandeln Werkzeuge/Zusatzfunktionen (Health Checks, Multi-Queue Publish, Dynamic Propel, Authorization Enabler), nicht Features; Features liegen unter `pbc/all/<feature>/latest/base-shop/install-and-upgrade/install-features/`.
- Einzelne Feature-Schritte „Transfers/Schema“ wurden nur für das Cart-Muster geprüft, nicht für alle 67 Features.

## Checkliste

- [ ] Feature-Metapaket statt Einzelmodulen installiert, `composer.lock` committet
- [ ] Alle Guide-Schritte inkl. „Verification“ abgearbeitet
- [ ] Glossar importiert, Config gesetzt, Transfers/Propel generiert
- [ ] Plugins in Pyz-Providern registriert (Feature-Kommentar)
- [ ] Daten importiert, P&S ausgelöst, Frontend gebaut
- [ ] `dev:plugin-usage:dump` gegen Plugin-Tabelle abgeglichen
- [ ] Tests, `code:phpstan` und Evaluator grün
- [ ] Keine Änderungen in `vendor/`, keine `replace_*`-Pakete

## Quellen

Doku (Basis https://docs.spryker.com/docs/): `pbc/all/cart-and-checkout/latest/base-shop/install-and-upgrade/install-features/install-the-cart-feature.html`, `dg/dev/set-up-spryker-locally/manage-dependencies-with-composer.html`, `dg/dev/architecture/module-api/use-composer-constraint-for-customized-modules.html`, `dg/dev/developing-standalone-modules/publish-standalone-modules-on-packagist.html`, `dg/dev/integrate-and-configure/integrate-and-configure.html`, `dg/dev/backend-development/plugins/get-an-overview-of-the-used-plugins.html`.

Code: `composer.json`, `composer.lock`, `vendor/composer/installed.json`, `package.json`, `vendor/spryker/composer-autoload-plugin/src/Spryker/Composer/Plugin/AutoloadPlugin.php`, `vendor/spryker/development/src/Spryker/Zed/Development/Communication/Console/{PluginUsageFinderConsole,DependencyViolationFinderConsole,ComposerJsonValidatorConsole,ComposerJsonUpdaterConsole}.php`, `src/Pyz/Zed/Console/ConsoleDependencyProvider.php` (Z. 407ff, 438), `src/Pyz/Shared/Quote/QuoteConfig.php`, `src/Pyz/Yves/CartPage/{CartPageConfig,CartPageDependencyProvider}.php`, `src/Pyz/Yves/Router/RouterDependencyProvider.php`, `src/Pyz/Yves/ShopApplication/ShopApplicationDependencyProvider.php`, `config/Shared/config_default.php` (Z. 160-173), `vendor/spryker/cart/composer.json`, `deploy.yml`, `tooling.yml`.

Verweise: [Core erweitern](03-core-erweitern.md), [Upgradability](28-upgradability.md), [Docker SDK Workflow](30-docker-sdk-workflow.md), [Konfiguration](06-konfiguration.md).

Abweichungen: Integrator deaktiviert; `vendor/spryker-feature/` fehlt; Constrainer nicht registriert; Doku-Stände alt (Cart-Guide 2021, Composer-Guide PHP 7.4, `string[]`-Typen ohne `strict_types`); die Doku nennt „Symfony 7.4“ im Bereich `integrate-and-configure`, installiert ist Symfony 6.4.48.
