# Integrating features and managing project dependencies

> Status: release 202610.0 (commit d45396b8; spryker/kernel 3.87.1, spryker/oms 11.55.0; Symfony 6.4.48). File findings checked directly on the file system; docs pages (cart guide "Last updated June 16, 2021", Composer guide with PHP 7.4 in the example) are outdated: `[code-verified, docs not cross-checked]`, code applies. Console commands executed in the container `[live verified (202610.0)]`; `composer` calls executed `[live verified (202610.0)]` (`require` with `--dry-run` so that `composer.json` remains unchanged). **Not registered** (removed): `dev:dependency:*`, `dev:composer:*`. Confirmed during the code cross-check: `CartPageDependencyProvider::getMiniCartViewExpanderPlugins()` exists in `vendor/spryker-shop/cart-page`.

## Purpose

Install Spryker features (`spryker-feature/*`) via integration guide, check that the integration is complete, understand dependencies and autoloading, publish standalone modules. Updates of existing modules → [Upgradability](28-upgradability.md); Docker/`deploy.yml` → [Docker SDK workflow](30-docker-sdk-workflow.md).

## Concepts

**Feature = Composer metapackage.** `"type": "metapackage"` without code, bundles `require` entries on modules (`spryker/*`, `spryker-shop/*`) with minimum versions. `composer.lock` contains 67 `spryker-feature/*` (all `metapackage`, out of 1232 packages); the project `composer.json` has 67 in `require`, `spryker-feature/development-tools` in `require-dev`. Example `spryker-feature/cart` 202610.0 → `spryker/cart ^7.18.0`, `spryker/cart-note`, `spryker/cart-variant`, `spryker/persistent-cart`, `spryker/calculation`, `spryker-shop/cart-page ^3.62.0`, `spryker-shop/cart-note-widget`. Feature versioning follows the release tag (`^202610.0`), modules have their own SemVer versions. **`vendor/spryker-feature/` does not exist** (metapackages create no directory; `vendor/composer/installed.json` lists them). The workspace entries `vendor/spryker-feature/*` in `package.json` therefore point nowhere (purpose not established `[Assumption]`).

**Integration guide (pattern).** Docs `pbc/all/<feature>/latest/base-shop/install-and-upgrade/install-features/` (e.g. `install-the-cart-feature.html`), each with "Install feature core" (backend) and "Install feature frontend" (Yves), with prerequisites and verification blocks:
1. `composer require spryker-feature/<name> 202610.0 --update-with-dependencies`, then the module verification table.
2. Glossary/translations (`data/import/common/common/glossary.csv`), `data:import glossary` (→ [Data Import](09-data-import.md), [Multi-store/i18n](19-multi-store-i18n.md)).
3. Configuration (constants, `*Config` in Pyz, → [Configuration](06-configuration.md)).
4. `transfer:generate`, `propel:install` (→ [Transfer](04-transfer.md), [Propel](05-propel-persistence.md)).
5. Activate plugins in Pyz DependencyProviders (router, widgets, stacks, → [Extending the core](03-extending-the-core.md), [Yves routing](14-yves-controller-routing.md), [Widgets](16-widgets.md)).
6. Import data, `publish:trigger-events`, `sync:data` (→ [Publish & Sync](07-publish-sync.md)).
7. Build the frontend (→ [Atomic frontend](15-yves-frontend-atomic.md)).

**Integrator disabled.** `composer.json` → `config.allow-plugins`: `"spryker-sdk/integrator": false`; `vendor/spryker-sdk/` only contains benchmark, composer-constrainer, evaluator, phpstan-spryker, spryk, utils. Plugin registration happens **manually** following the integration guide.

**Autoloading.** `composer.json`: `autoload.psr-4` `Generated\` → `src/Generated/`, `Orm\` → `src/Orm/`, `Pyz\` → `src/Pyz/`, `SprykerConfig\` → `src/SprykerConfig/`; `autoload-dev` `PyzTest\` → `tests/PyzTest/` plus `test-autoload.php`. `extra.splitting.namespaces = ["Spryker\\", "SprykerShop\\"]`, processed by the plugin `spryker/composer-autoload-plugin` (`Spryker\Composer\Plugin\AutoloadPlugin`, `PRE_AUTOLOAD_DUMP`): splits the PSR-4 entry per layer/module (`Spryker\Zed\Cart\`, `Spryker\Client\Cart\` …) for smaller autoload maps. Deploy: `composer.mode: --no-dev`, `autoload: --classmap-authoritative`. Kernel namespaces `config/Shared/config_default.php`: `PROJECT_NAMESPACES = ['Pyz']`, `CORE_NAMESPACES = ['SprykerShop','SprykerEco','Spryker','SprykerSdk']` (`SprykerFeature` is missing, unproblematic: features have no code).

**npm workspaces** (`package.json`, `private: true`): `vendor/spryker/*`, `vendor/spryker/*/assets/Zed`, `vendor/spryker-feature/*`, `vendor/spryker-feature/*/assets/Zed`, `vendor/spryker-shop/*`. Actually present: `vendor/spryker-shop/shop-ui/package.json` (`shop-ui`), `traceable-event-widget`, Zed assets in `vendor/spryker/{chart,discount,gui,product-relation-gui,state-machine}/assets/Zed/package.json`. Scripts: `yves` → `npm run build -w shop-ui --`, `zed` → `npm run build -w spryker-zed-gui --`, `postinstall` → `update:config`. Node `>=24.15.0`, npm `>=10`. A new module with its own assets is only built if its directory falls under a workspace glob (core modules); project assets live under `src/Pyz/Yves/*/Theme/…` or `frontend/`.

**Project dependencies.** `composer.lock` is the source of truth; `composer outdated`, `composer update "spryker/*"` within the constraints; `config.preferred-install: dist`; `config.platform.php` 8.3.13, `require.php >=8.3`; `minimum-stability: dev`, `prefer-stable: true`. Replacing a core module via dummy package `replace_<module>` with `"replace": {"spryker/<module>": "*"}` (docs); not used in the release, upgrader-hostile (last resort). For customized modules `~` instead of `^` (→ [Upgradability](28-upgradability.md)); in the release the constrainer is not registered and there is no `~` constraint.

**Publishing standalone modules** (docs `publish-standalone-modules-on-packagist`): `composer init` in the module repo (`name`, `description`, `type: library`, `license`, `require` with PHP >=8.3 and **all** module dependencies, `autoload.psr-4`), `.gitignore` for `vendor/` and `composer.lock`, Git tag (GitHub release or `git tag v1.0.0` + `git push origin v1.0.0`), submit to packagist.org, new version = new tag, installation `composer require vendor/package-name`. The docs example uses `"Vendor\\Package\\": "src/"`; real Spryker modules use `"Spryker\\": "src/Spryker/"` (`vendor/spryker/cart/composer.json`). For a module with Spryker layout the namespace must match `CORE_NAMESPACES`/`PROJECT_NAMESPACES`, otherwise the ClassResolver does not resolve Facade/Factory (→ [Architecture](01-architecture.md), [Project module](02-project-module.md)). Private packages via `repositories` (example in the release: VCS repos `spryker/robotframework-suite-tests`, `spryker/cypress-tests`).

## Instructions

1. **Check the prerequisites** of the feature guide (other features in a minimum version).
2. **Install:** `docker/sdk cli composer require spryker-feature/<name> 202610.0 --update-with-dependencies`; verification table: `ls vendor/spryker/<module>` or `docker/sdk cli composer show spryker/<module>`.
3. **Work through the guide steps** one after the other (glossary, config, transfers/Propel, plugins, data, frontend) and run every "Verification".
4. **Register plugins only in Pyz DependencyProviders** (subclass of the core provider) and mark them with a feature comment (`#CartNoteFeature`, `#MultiCartFeature`, `#SharedCartFeature` in the release).
5. **Check completeness:** compare `docker/sdk cli console dev:plugin-usage:dump [Module]` against the guide's plugin table; `docker/sdk cli composer why <package>` or `composer show <package>` for dependency consistency (the console commands `dev:dependency:find` and `dev:composer:validate-json-files` are not registered in the release); constants/config per guide table; tests (→ [Tests](26-tests.md)), `code:phpstan` (→ [Coding guidelines](27-coding-guidelines.md)).
6. **Run the evaluator** (→ [Upgradability](28-upgradability.md)).

Commands (all executed `[live verified (202610.0)]`; `composer require` here only with `--dry-run`):
```
docker/sdk cli composer require spryker-feature/<name> 202610.0 --update-with-dependencies   # --dry-run for testing (live: exit 0, lists upgrades)
docker/sdk cli composer why spryker-shop/cart-page   # package name with vendor spryker-shop; `spryker/cart-page` does not exist (exit 1)
docker/sdk cli composer show spryker-feature/cart
docker/sdk cli composer outdated "spryker*/*" --direct   # Composer 2.11 accepts only ONE package argument
docker/sdk cli console transfer:generate
docker/sdk cli console propel:install
docker/sdk cli console data:import glossary
docker/sdk cli console publish:trigger-events
docker/sdk cli console frontend:yves:build          # or npm run yves / yves:production
docker/sdk cli console frontend:zed:build           # or npm run zed / zed:production
docker/sdk cli console dev:plugin-usage:dump [Module]
docker/sdk cli console dev:ide-auto-completion:generate
```
The order after code changes is described in [Docker SDK workflow](30-docker-sdk-workflow.md).

## Examples

**Quote strategy** (`src/Pyz/Shared/Quote/QuoteConfig.php`, release, cart guide step 3):
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

**CartPage configuration** (`src/Pyz/Yves/CartPage/CartPageConfig.php`, excerpt, release; all three flags `true`):
```php
class CartPageConfig extends SprykerCartPageConfig
{
    protected const IS_CART_ACTIONS_ASYNC_MODE_ENABLED = true;
    protected const IS_CART_CART_ITEMS_VIA_AJAX_LOAD_ENABLED = true;
    protected const IS_LOADING_UPSELLING_PRODUCTS_VIA_AJAX_ENABLED = true;
```
The docs show `protected function` overrides/older constants; in the release constants are set in Pyz that override the core constant.

**Plugin registration in the DependencyProvider** (`src/Pyz/Yves/CartPage/CartPageDependencyProvider.php`, release, excerpt; `getMiniCartViewExpanderPlugins()` checked in `vendor/spryker-shop/cart-page`):
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

**Router and widget activation** (release): `src/Pyz/Yves/Router/RouterDependencyProvider.php` activates `CartPageRouteProviderPlugin`, `CartNoteWidgetRouteProviderPlugin` (comment `#CartNoteFeature`), `CartPageAsyncRouteProviderPlugin`, `CartNoteWidgetAsyncRouteProviderPlugin`; `src/Pyz/Yves/ShopApplication/ShopApplicationDependencyProvider.php` lists `SprykerShop\Yves\CartPage\Widget\ProductAbstractAddToCartButtonWidget::class` in `getGlobalWidgets()` (→ [Widgets](16-widgets.md)).

## Pitfalls

- Changing files in `vendor/` (lost on the next `composer install`, breaks the upgrader).
- Replacing a core module via a `replace_*` dummy instead of via plugin/config/inheritance.
- Registering plugins in the core DependencyProvider instead of in Pyz.
- Installing a feature but skipping the plugin/config steps: routes missing, widgets do not render; transfers/schema not regenerated → "class not found" in `Generated\`.
- Only `composer require spryker/<module>` instead of the feature: dependencies and frontend modules (`spryker-shop/*`) are missing.
- Forgetting the frontend build after integration.
- Expecting that `vendor/spryker-feature/` exists or that the integrator registers plugins (neither is the case).
- `^` constraints on overridden modules.
- Adopting the docs example `Vendor\Package\` unchanged for modules with Spryker layout (ClassResolver finds nothing).
- The docs integration pages under `dg/dev/integrate-and-configure/` cover tools/additional functions (health checks, multi-queue publish, Dynamic Propel, authorization enabler), not features; features are under `pbc/all/<feature>/latest/base-shop/install-and-upgrade/install-features/`.
- Individual feature steps "transfers/schema" were only checked for the cart pattern, not for all 67 features.

## Checklist

- [ ] Feature metapackage installed instead of individual modules, `composer.lock` committed
- [ ] All guide steps including "Verification" worked through
- [ ] Glossary imported, config set, transfers/Propel generated
- [ ] Plugins registered in Pyz providers (feature comment)
- [ ] Data imported, P&S triggered, frontend built
- [ ] `dev:plugin-usage:dump` compared against the plugin table
- [ ] Tests, `code:phpstan` and evaluator green
- [ ] No changes in `vendor/`, no `replace_*` packages

## Sources

Docs (base https://docs.spryker.com/docs/): `pbc/all/cart-and-checkout/latest/base-shop/install-and-upgrade/install-features/install-the-cart-feature.html`, `dg/dev/set-up-spryker-locally/manage-dependencies-with-composer.html`, `dg/dev/architecture/module-api/use-composer-constraint-for-customized-modules.html`, `dg/dev/developing-standalone-modules/publish-standalone-modules-on-packagist.html`, `dg/dev/integrate-and-configure/integrate-and-configure.html`, `dg/dev/backend-development/plugins/get-an-overview-of-the-used-plugins.html`.

Code: `composer.json`, `composer.lock`, `vendor/composer/installed.json`, `package.json`, `vendor/spryker/composer-autoload-plugin/src/Spryker/Composer/Plugin/AutoloadPlugin.php`, `vendor/spryker/development/src/Spryker/Zed/Development/Communication/Console/{PluginUsageFinderConsole,DependencyViolationFinderConsole,ComposerJsonValidatorConsole,ComposerJsonUpdaterConsole}.php`, `src/Pyz/Zed/Console/ConsoleDependencyProvider.php` (line 407ff, 438), `src/Pyz/Shared/Quote/QuoteConfig.php`, `src/Pyz/Yves/CartPage/{CartPageConfig,CartPageDependencyProvider}.php`, `src/Pyz/Yves/Router/RouterDependencyProvider.php`, `src/Pyz/Yves/ShopApplication/ShopApplicationDependencyProvider.php`, `config/Shared/config_default.php` (lines 160-173), `vendor/spryker/cart/composer.json`, `deploy.yml`, `tooling.yml`.

References: [Extending the core](03-extending-the-core.md), [Upgradability](28-upgradability.md), [Docker SDK workflow](30-docker-sdk-workflow.md), [Configuration](06-configuration.md).

Deviations: integrator disabled; `vendor/spryker-feature/` missing; constrainer not registered; docs versions old (cart guide 2021, Composer guide PHP 7.4, `string[]` types without `strict_types`); the docs mention "Symfony 7.4" in the `integrate-and-configure` area, installed is Symfony 6.4.48.
