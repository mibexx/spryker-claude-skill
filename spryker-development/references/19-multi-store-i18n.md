# Multi-store, regions, locales and Storefront translations

> Status: release 202610.0 (kernel 3.87.1, Symfony 6.4.48). Verified against the source code and live in the containers (DB `eu-docker`, curl against `yves.eu.spryker.local`). `[code-verified, docs not cross-checked]`: the docs pages only returned skeleton content; docs statements are `[Assumption]`. Console commands executed `[live verified (202610.0)]` (store importer, `queue:setup`, `search:setup:sources`; no new store was created, the existing store CSVs were re-imported idempotently). Open: Glue `/stores` returned 404 (cause unclear); no test of a US seed; whether glossary storage keys are store-dependent `[Assumption: locale-dependent only]`. Confirmed during the authoring run: signature `StorePostCreatePluginInterface::execute(StoreTransfer): StoreResponseTransfer`.

## Purpose

Dynamic Store Mode (DMS), creating stores/currencies/locales/countries, store context in Yves/Glue/Zed, Yves translations via glossary. Zed translations (CSV) → [18](18-back-office.md); P&S mechanics → [07](07-publish-sync.md); reading storage → [08](08-client-storage-search.md); importers → [09](09-data-import.md); Docker/`deploy.yml` → [30](30-docker-sdk-workflow.md).

## Concepts

**Dynamic Store Mode.** Switch `SPRYKER_DYNAMIC_STORE_MODE: true` in `deploy.dev.yml` (container: `SPRYKER_DYNAMIC_STORE_MODE=1`, `SPRYKER_CURRENT_REGION=EU`, `APPLICATION_STORE` empty). Evaluation `Spryker\Shared\Kernel\Store::isDynamicStoreMode()`: env truthy -> DMS on; if the variable is missing, DMS is on if `config/Shared/stores.php` does **not** exist. **`Store::getInstance()` throws in DMS** `Exception('Dynamic store mode enabled. Store object cannot be used anymore.')` -> use `StoreFacade` (Zed), `StoreClient`/`StoreStorageClient` (Yves/Glue) instead.

**Region instead of store as the infrastructure unit.** Per region (`regions:` in `deploy.dev.yml`, e.g. `EU`, `US`) a separate DB (`eu-docker`/`us-docker`), RabbitMQ vhost, Redis DB, search namespace and applications (`yves_eu`, `glue_eu`, `boffice_eu`, … `*.eu.spryker.local`). Stores (DE, AT) live **within** the region in the same DB/Redis/queue/search; separation via data (`fk_store`, `*_store` relations, storage keys). Config: `$namespaceKey = SPRYKER_DYNAMIC_STORE_MODE ? SPRYKER_CURRENT_REGION : APPLICATION_STORE` (`config_default.php:402`), RabbitMQ default connection = region (`config_default-docker.php`). Constant `APPLICATION_REGION` from `SPRYKER_CURRENT_REGION`; `APPLICATION_STORE` is not defined in DMS.

**Legacy stays around (trap):** `config/Shared/stores.php`, `default_store.php`, `config/install/{EU,US}/*.dynamic-store-off.yml` have no effect in DMS (only with DMS off: fixed stores, `queuePools`, `storesWithSharedPersistence`). Intended for removal in a pure DMS project `[Assumption]`. Install recipe DMS: `config/install/dynamic-store.yml`.

**Store data in the DB:** `spy_store`, `spy_locale_store`, `spy_currency_store`, `spy_country_store`, `spy_store_context` (no longer in `stores.php`). Two ways:
- Back Office (`store-gui`, `country-gui`, `currency-gui`, `locale-gui`, `store-context-gui`): `StoreFacade::createStore()`/`updateStore()` run validation and post plugins from `Pyz\Zed\Store\StoreDependencyProvider` (among others `CountryStorePostCreatePlugin`, `CurrencyStorePostCreatePlugin`, `DefaultLocaleStorePostCreatePlugin`, `LocaleStorePostCreatePlugin`, `SearchSetupSourcesStorePostCreatePlugin`, `ContextStorePostCreatePlugin`). Extending the GUI: `StoreGuiDependencyProvider::get…ExpanderPlugins`. After creation, `StoreStorageConfig::getStoreCreationResourcesToReSync()` re-synchronizes resources (glossary, navigation, CompanyUser, CustomerAccess).
- Data Import (reproducible):

| Import type (`data:import:<type>`) | CSV | Columns |
|---|---|---|
| `store` | `data/import/common/EU/store.csv` | `name` |
| `currency` | `…/common/common/currency.csv` | `iso_code,currency_symbol,name` |
| `currency-store` | `…/common/DE/currency_store.csv` | `currency_code,store_name,is_default` |
| `country-store` | `…/common/DE/country_store.csv` | `store_name,country` |
| `locale-store` | `…/common/DE/locale_store.csv` | `locale_name,store_name` |
| `default-locale-store` | `…/common/DE/default_locale_store.csv` | `locale_name,store_name` |
| `context-store` | `…/common/EU/store_context.csv` | `store_name,appication_context_collection` (**the typo "appication" is like this in the release**; JSON `application_contexts[{application,timezone}]`) |
| `glossary` | `…/common/common/glossary.csv` | `key,translation,locale` |

Order in `data/import/local/full_EU.yml`: `store` -> `currency` -> `currency-store` -> `country-store` -> `locale-store` -> `default-locale-store` -> `context-store` -> `customer` -> `glossary`. Store-specific relations per store in `data/import/common/{DE,AT,US}/*.csv`.

**Yves store context:** `IS_STORE_ROUTING_ENABLED` (Router, StoreWidget, StorageRouter, ShopUi, CustomerPage, …) = `(bool)getenv('SPRYKER_DYNAMIC_STORE_MODE')` (`config_default.php`). URL scheme `/<STORE>/<lang>/<path>` (`/DE/en`, `/AT/de`); live: `/DE/en` 200, `/en` 200 (fallback), `/US/en` 307 to the error page (US belongs to region US). Plugin chain: `Pyz\Yves\Router\RouterDependencyProvider` (`StorePrefixRouterEnhancerPlugin`, then `LanguagePrefixRouterEnhancerPlugin`; `StoreDefaultPostAddRouteManipulatorPlugin`), `ShopApplicationDependencyProvider` (`StoreApplicationPlugin` **before** `LocaleApplicationPlugin` **before** `TranslatorApplicationPlugin`), `LocaleEventDispatcherPlugin`. Store resolution (`StoreApplicationPlugin::loadStoreName()`): URL segment/`?_store=` -> session `current_store` -> `APPLICATION_STORE` (only DMS off) -> first store from `StoreStorageClient::getStoreNames()`; otherwise `Exception('Cannot resolve store')`. `StoreClient::getCurrentStore()` returns `StoreTransfer` from Redis (locales, currencies, countries, timezone) via `Pyz\Client\Store\StoreDependencyProvider::getStoreExpanderPlugins()`. Widgets: `StoreSwitcherWidget`, `LanguageSwitcherWidget`, `CurrencyWidget`.

**Glue:** `StoresApi\Plugin\GlueStorefrontApiApplication\StoreApplicationPlugin`, `StoreValidatorPlugin` (error 601 `store.validation.store_not_found`), `LocaleRequestBuilderPlugin`. Store via query `_store` -> header **`Store`** -> `APPLICATION_STORE` -> first store; locale via `Accept-Language` against the store's locales, fallback default locale ([12](12-glue-storefront.md)).

**Zed:** `Pyz\Zed\Store\StoreConfig::isMultiStorePerZedEnabled()` = `true` (core default `false`; mandatory in DMS). `BackofficeStoreApplicationPlugin` reads `?_store=`. Back Office locale from the user setting (`UserLocaleLocalePlugin`).

**Glossary data flow (Yves texts):**
1. Source `glossary.csv` (one line per key per locale) via `data:import:glossary` or Back Office glossary (`GlossaryFacade::createTranslation()`/`saveGlossaryKeyTranslations()`); tables `spy_glossary_key`, `spy_glossary_translation`.
2. Publish: `GlossaryStoragePlugins` in `PublisherDependencyProvider`, trigger for resource `translation`; queues `publish.translation`, `sync.storage.translation`.
3. Read: `Spryker\Client\GlossaryStorage\GlossaryStorageClient::translate($id, $localeName, array $parameters = [])`/`translateBulk()`; **key not found => the key itself is returned**, no error, no fallback to another locale (Yves). Parameters `%name%`.
4. Yves translator `Spryker\Yves\Translator\Translator\Translator::trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null)` forwards to storage; `$domain` is ignored. Twig: `{{ 'global.subscribe.title' | trans }}`, with parameters `trans({'%name%': x})`.

Key convention (observed in `glossary.csv`): `<area>.<context>.<statement>`, lowercase, dots (e.g. `cart.add.items.success`); official rule `[Assumption]`.

## Instructions

**Create a new store via import** (region EU; commands executed `[live verified (202610.0)]`, importers `store`, `currency-store`, `country-store`, `locale-store`, `default-locale-store`, `context-store` each exit 0):
1. Line in `data/import/common/EU/store.csv`; relations in `data/import/common/<STORE>/{currency_store,country_store,locale_store,default_locale_store}.csv`, context in `store_context.csv`; importer register `data/import/common/<STORE>/<STORE>.yml`.
2. `docker/sdk cli console data:import --config=data/import/local/full_EU.yml` or individually `data:import:store`, `…:currency-store`, `…:country-store`, `…:locale-store`, `…:default-locale-store`, `…:context-store` (**always `store` before the `*-store` relations**).
3. `docker/sdk cli console queue:setup` and `docker/sdk cli console search:setup:sources` (new queues/search sources); process the publish queues.

**New locale:** assignment in the store (`locale-store`), import the glossary lines for the locale, republish URLs/search index (`publish:trigger-events`), otherwise language URLs are missing in the `LanguageSwitcherWidget` `[Assumption: exact resource list; getStoreCreationResourcesToReSync() is reliable]`.

**New Yves text:** line in `glossary.csv` per locale (`key,translation,locale`), `docker/sdk cli console data:import:glossary`, `docker/sdk cli console publish:trigger-events -r translation`, `docker/sdk cli console queue:worker:start --stop-when-empty` ([22](22-console-cron-queue.md)); in Twig `{{ 'key' | trans }}`.

**Read the store in code (DMS-compliant):** Zed `$this->getFactory()->getStoreFacade()->getStoreByName('DE')` or `getStoreCollection(new StoreCriteriaTransfer())`; Yves/Client `$this->getFactory()->getStoreClient()->getCurrentStore()`, `->getAvailableLocaleIsoCodes()`. Across layers only Facade/Client; provide `getStoreFacade()` in your own module's DependencyProvider ([02](02-project-module.md)). URLs only via `url()`/`path()`, never `/DE/en/` by hand.

**Zed texts** are a different mechanism: [18](18-back-office.md).

Further commands (`[live verified (202610.0)]`, registered): `docker/sdk cli console data:import:currency`, `translator:generate-cache` (Zed CSV). Choosing the region: `SPRYKER_CURRENT_REGION=US vendor/bin/console …` (CLI shows `Region: EU | Code bucket: EU`). There are no dedicated `store:*` console commands (only import/price). Data check: `docker/sdk cli "mysql --skip-ssl -h database -uroot -psecret eu-docker -e 'select id_store,name from spy_store'"` (`--skip-ssl` required).

## Examples

**Real:** `src/Pyz/Zed/Store/StoreConfig.php`

```php
<?php

declare(strict_types = 1);

namespace Pyz\Zed\Store;

use Spryker\Zed\Store\StoreConfig as SprykerStoreConfig;

class StoreConfig extends SprykerStoreConfig
{
    public function isMultiStorePerZedEnabled(): bool
    {
        return true;
    }
}
```

**Real:** `src/Pyz/Client/Store/StoreDependencyProvider.php`

```php
protected function getStoreExpanderPlugins(): array
{
    return [
        new StoreStorageStoreExpanderPlugin(),
        new TimezoneStoreStorageStoreExpanderPlugin(),
    ];
}
```

**Custom post-create plugin (not in the release, interface signature checked against `vendor/`):**

```php
<?php

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadge\Communication\Plugin\Store;

use Generated\Shared\Transfer\StoreResponseTransfer;
use Generated\Shared\Transfer\StoreTransfer;
use Spryker\Zed\Kernel\Communication\AbstractPlugin;
use Spryker\Zed\StoreExtension\Dependency\Plugin\StorePostCreatePluginInterface;

class ProductBadgeStorePostCreatePlugin extends AbstractPlugin implements StorePostCreatePluginInterface
{
    public function execute(StoreTransfer $storeTransfer): StoreResponseTransfer
    {
        // Create store-related initial data (example: default badges)
        return (new StoreResponseTransfer())
            ->setIsSuccessful(true)
            ->setStore($storeTransfer);
    }
}
```

Registration: entry in `Pyz\Zed\Store\StoreDependencyProvider::getStorePostCreatePlugins()` (extend the array).

**Glossary usage (real, pattern):** `src/Pyz/Yves/NewsletterWidget/Theme/default/components/molecules/subscription/subscription.twig:21`: `title: 'global.subscribe.title' | trans`.

## Pitfalls

1. `Store::getInstance()` in DMS -> exception; `APPLICATION_STORE` is not defined in DMS, never use it without a `defined()` check.
2. Changing `stores.php` has no effect in DMS; maintain stores/locales/currencies/countries via Back Office or import.
3. Do not "fix" the import column `appication_context_collection` (breaks the import).
4. Glossary key missing -> Yves shows the key as plain text (no fallback locale). After import/change, process the publish queue (`publish.translation`) and sync queue (`sync.storage.translation`).
5. Order of application plugins: `StoreApplicationPlugin` before `LocaleApplicationPlugin` before `TranslatorApplicationPlugin`.
6. Do not hard-code store/locale strings; get `StoreTransfer` from the Client. Do not build router URLs by hand.
7. Glue: store via header `Store`/`?_store=`, locale via `Accept-Language`; unknown store -> validator error 601.
8. `Translator::trans()` ignores `$domain`; placeholders `%name%` with parameters instead of string concatenation.
9. Extensions only via plugins in `Pyz\*DependencyProvider` (StoreExpander, StorePostCreate, StoreGui expanders); never change `vendor/spryker/store*` ([28](28-upgradability.md)).

**Deprecated (from the code):** `Spryker\Shared\Config\Application\Environment::defineStore()` ("Dynamic stores are based on the Channel context") and `defineLocale()` ("…Store context"); `Spryker\Zed\Store\StoreConfig::getDefaultTimezone()` ("Exists for BC reasons only", timezone now in `spy_store_context`); `StoreConfig::getStoreNameReferenceMap()` and `StoreConstants::STORE_NAME_REFERENCE_MAP` ("Will be removed without replacement"); `Yves\Translator\Translator::transChoice()` and `setLocale()` ("Will be removed without replacement"); `StoreFacade::expandAccessTokenRequest()`/`expandMessageAttributes()` BC only for DMS off. Optional `StoreConfig::isVisibleToCustomerEnabled()` (column `is_visible_to_customer`, default `false`, not enabled in the project). Docs `set-up-multiple-stores.html`/`handling-internationalization.html` describe, judging by title, the `stores.php` model `[Assumption]`; in 202610.0 it only applies with DMS off.

## Checklist

- [ ] DMS switch and region set; no `Store::getInstance()` in the code
- [ ] Store data via import/Back Office, `store` before the `*-store` relations
- [ ] Application plugin order Store -> Locale -> Translator
- [ ] Glossary keys imported for all locales, `translation` published, queues empty
- [ ] New store logic as a plugin (`StorePostCreate`/`StoreExpander`), not in the core
- [ ] Yves URLs via `url()`/`path()`; Glue tests with header `Store` and `Accept-Language`
- [ ] New queues/search sources after store creation (`queue:setup`, `search:setup:sources`)

## Sources

Docs (base `https://docs.spryker.com/docs/`, content not evaluable): `pbc/all/dynamic-multistore/latest/dynamic-multistore.html`, `…/base-shop/dynamic-multistore-feature-overview.html`, `…/base-shop/import-stores.html`, `dg/dev/internationalization-and-multi-store/{set-up-multiple-stores,handling-internationalization,setting-up-a-multi-language-shop,managing-glossary-keys,managing-translations-with-twig-translator}.html`, `dg/dev/backend-development/data-manipulation/data-publishing/publish-and-synchronize-and-multi-store-shop-systems.html`.

Code: `deploy.dev.yml`, `config/Shared/{stores.php,default_store.php,config_default.php,config_default-docker.php}`, `config/install/dynamic-store.yml`, `vendor/spryker/kernel/src/Spryker/Shared/Kernel/Store.php`, `vendor/spryker/store/src/Spryker/{Zed,Client}/Store/**`, `vendor/spryker/store-extension/…/StorePostCreatePluginInterface.php`, `vendor/spryker/router/…/StorePrefixRouterEnhancerPlugin.php`, `vendor/spryker-shop/store-widget/…/StoreApplicationPlugin.php`, `vendor/spryker/stores-api/…/StoreResolver.php`, `vendor/spryker/glossary-storage/src/Spryker/Client/GlossaryStorage/**`, `vendor/spryker/translator/src/Spryker/Yves/Translator/**`, `src/Pyz/Zed/{Store,StoreGui,StoreStorage,Locale,Application,Publisher}/…`, `src/Pyz/Client/Store/StoreDependencyProvider.php`, `src/Pyz/Yves/{Router,ShopApplication,EventDispatcher}/…`, `data/import/common/**`, `data/import/local/full_EU.yml`.

Neighbors: [07 Publish & Sync](07-publish-sync.md), [08 Storage](08-client-storage-search.md), [09 Data Import](09-data-import.md), [12 Glue Storefront](12-glue-storefront.md), [15 Frontend](15-yves-frontend-atomic.md), [18 Back Office](18-back-office.md), [30 Docker SDK](30-docker-sdk-workflow.md).
