# Multi-Store, Regionen, Locales und Storefront-Übersetzungen (Recherche T-19)

> Status: Release 202610.0 (kernel 3.87.1, Symfony 6.4.48). Verifiziert am Quellcode und in der Recherche live in den Containern (DB `eu-docker`, curl gegen `yves.eu.spryker.local`). `[code-verifiziert, Doku nicht abgeglichen]`: Die Doku-Seiten lieferten nur Gerüstinhalt; Doku-Aussagen sind `[Annahme]`. Konsolenbefehle ausgeführt `[live verifiziert (202610.0)]` (Store-Importer, `queue:setup`, `search:setup:sources`; es wurde kein neuer Store angelegt, die vorhandenen Store-CSV wurden idempotent neu importiert). Offen: Glue-`/stores` lieferte 404 (Ursache ungeklärt); kein Test eines US-Seeds; ob Glossary-Storage-Keys store-abhängig sind `[Annahme: nur locale-abhängig]`. Beim Autorenlauf bestätigt: Signatur `StorePostCreatePluginInterface::execute(StoreTransfer): StoreResponseTransfer`.

## Zweck

Dynamic-Store-Modus (DMS), Stores/Währungen/Locales/Länder anlegen, Store-Kontext in Yves/Glue/Zed, Yves-Übersetzungen über Glossar. Zed-Übersetzungen (CSV) → [18](18-back-office.md); P&S-Mechanik → [07](07-publish-sync.md); Storage lesen → [08](08-client-storage-search.md); Importer → [09](09-data-import.md); Docker/`deploy.yml` → [30](30-docker-sdk-workflow.md).

## Konzepte

**Dynamic Store Mode.** Schalter `SPRYKER_DYNAMIC_STORE_MODE: true` in `deploy.dev.yml` (Container: `SPRYKER_DYNAMIC_STORE_MODE=1`, `SPRYKER_CURRENT_REGION=EU`, `APPLICATION_STORE` leer). Auswertung `Spryker\Shared\Kernel\Store::isDynamicStoreMode()`: Env truthy -> DMS an; fehlt die Variable, gilt DMS an, wenn `config/Shared/stores.php` **nicht** existiert. **`Store::getInstance()` wirft im DMS** `Exception('Dynamic store mode enabled. Store object cannot be used anymore.')` -> stattdessen `StoreFacade` (Zed), `StoreClient`/`StoreStorageClient` (Yves/Glue).

**Region statt Store als Infrastruktur-Einheit.** Pro Region (`regions:` in `deploy.dev.yml`, z. B. `EU`, `US`) eigene DB (`eu-docker`/`us-docker`), RabbitMQ-vhost, Redis-DB, Search-Namespace und Applikationen (`yves_eu`, `glue_eu`, `boffice_eu`, … `*.eu.spryker.local`). Stores (DE, AT) liegen **innerhalb** der Region in derselben DB/Redis/Queue/Search; Trennung über Daten (`fk_store`, `*_store`-Relationen, Storage-Keys). Konfig: `$namespaceKey = SPRYKER_DYNAMIC_STORE_MODE ? SPRYKER_CURRENT_REGION : APPLICATION_STORE` (`config_default.php:402`), RabbitMQ-Default-Connection = Region (`config_default-docker.php`). Konstante `APPLICATION_REGION` aus `SPRYKER_CURRENT_REGION`; `APPLICATION_STORE` ist im DMS nicht definiert.

**Legacy bleibt liegen (Falle):** `config/Shared/stores.php`, `default_store.php`, `config/install/{EU,US}/*.dynamic-store-off.yml` sind im DMS wirkungslos (nur DMS aus: feste Stores, `queuePools`, `storesWithSharedPersistence`). Für reines DMS-Projekt zum Entfernen vorgesehen `[Annahme]`. Install-Recipe DMS: `config/install/dynamic-store.yml`.

**Store-Daten in der DB:** `spy_store`, `spy_locale_store`, `spy_currency_store`, `spy_country_store`, `spy_store_context` (nicht mehr in `stores.php`). Zwei Wege:
- Back Office (`store-gui`, `country-gui`, `currency-gui`, `locale-gui`, `store-context-gui`): `StoreFacade::createStore()`/`updateStore()` führen Validierungs- und Post-Plugins aus `Pyz\Zed\Store\StoreDependencyProvider` aus (u. a. `CountryStorePostCreatePlugin`, `CurrencyStorePostCreatePlugin`, `DefaultLocaleStorePostCreatePlugin`, `LocaleStorePostCreatePlugin`, `SearchSetupSourcesStorePostCreatePlugin`, `ContextStorePostCreatePlugin`). Erweiterung der GUI: `StoreGuiDependencyProvider::get…ExpanderPlugins`. Nach Anlage re-synchronisiert `StoreStorageConfig::getStoreCreationResourcesToReSync()` Ressourcen (Glossar, Navigation, CompanyUser, CustomerAccess).
- Data Import (reproduzierbar):

| Importtyp (`data:import:<typ>`) | CSV | Spalten |
|---|---|---|
| `store` | `data/import/common/EU/store.csv` | `name` |
| `currency` | `…/common/common/currency.csv` | `iso_code,currency_symbol,name` |
| `currency-store` | `…/common/DE/currency_store.csv` | `currency_code,store_name,is_default` |
| `country-store` | `…/common/DE/country_store.csv` | `store_name,country` |
| `locale-store` | `…/common/DE/locale_store.csv` | `locale_name,store_name` |
| `default-locale-store` | `…/common/DE/default_locale_store.csv` | `locale_name,store_name` |
| `context-store` | `…/common/EU/store_context.csv` | `store_name,appication_context_collection` (**Tippfehler "appication" ist im Release so**; JSON `application_contexts[{application,timezone}]`) |
| `glossary` | `…/common/common/glossary.csv` | `key,translation,locale` |

Reihenfolge in `data/import/local/full_EU.yml`: `store` -> `currency` -> `currency-store` -> `country-store` -> `locale-store` -> `default-locale-store` -> `context-store` -> `customer` -> `glossary`. Store-spezifische Relationen je Store in `data/import/common/{DE,AT,US}/*.csv`.

**Yves-Store-Kontext:** `IS_STORE_ROUTING_ENABLED` (Router, StoreWidget, StorageRouter, ShopUi, CustomerPage, …) = `(bool)getenv('SPRYKER_DYNAMIC_STORE_MODE')` (`config_default.php`). URL-Schema `/<STORE>/<lang>/<pfad>` (`/DE/en`, `/AT/de`); live: `/DE/en` 200, `/en` 200 (Fallback), `/US/en` 307 auf Fehlerseite (US gehört zur Region US). Plugin-Kette: `Pyz\Yves\Router\RouterDependencyProvider` (`StorePrefixRouterEnhancerPlugin`, dann `LanguagePrefixRouterEnhancerPlugin`; `StoreDefaultPostAddRouteManipulatorPlugin`), `ShopApplicationDependencyProvider` (`StoreApplicationPlugin` **vor** `LocaleApplicationPlugin` **vor** `TranslatorApplicationPlugin`), `LocaleEventDispatcherPlugin`. Store-Ermittlung (`StoreApplicationPlugin::loadStoreName()`): URL-Segment/`?_store=` -> Session `current_store` -> `APPLICATION_STORE` (nur DMS aus) -> erster Store aus `StoreStorageClient::getStoreNames()`; sonst `Exception('Cannot resolve store')`. `StoreClient::getCurrentStore()` liefert `StoreTransfer` aus Redis (Locales, Währungen, Länder, Timezone) via `Pyz\Client\Store\StoreDependencyProvider::getStoreExpanderPlugins()`. Widgets: `StoreSwitcherWidget`, `LanguageSwitcherWidget`, `CurrencyWidget`.

**Glue:** `StoresApi\Plugin\GlueStorefrontApiApplication\StoreApplicationPlugin`, `StoreValidatorPlugin` (Fehler 601 `store.validation.store_not_found`), `LocaleRequestBuilderPlugin`. Store per Query `_store` -> Header **`Store`** -> `APPLICATION_STORE` -> erster Store; Locale per `Accept-Language` gegen Locales des Stores, Fallback Default-Locale ([12](12-glue-storefront.md)).

**Zed:** `Pyz\Zed\Store\StoreConfig::isMultiStorePerZedEnabled()` = `true` (Core-Default `false`; im DMS Pflicht). `BackofficeStoreApplicationPlugin` liest `?_store=`. Back-Office-Locale aus User-Einstellung (`UserLocaleLocalePlugin`).

**Glossar-Datenfluss (Yves-Texte):**
1. Quelle `glossary.csv` (je Key je Locale eine Zeile) per `data:import:glossary` oder Back-Office-Glossar (`GlossaryFacade::createTranslation()`/`saveGlossaryKeyTranslations()`); Tabellen `spy_glossary_key`, `spy_glossary_translation`.
2. Publish: `GlossaryStoragePlugins` im `PublisherDependencyProvider`, Trigger für Resource `translation`; Queues `publish.translation`, `sync.storage.translation`.
3. Lesen: `Spryker\Client\GlossaryStorage\GlossaryStorageClient::translate($id, $localeName, array $parameters = [])`/`translateBulk()`; **Key nicht gefunden => der Key selbst wird zurückgegeben**, kein Fehler, kein Fallback auf andere Locale (Yves). Parameter `%name%`.
4. Yves-Translator `Spryker\Yves\Translator\Translator\Translator::trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null)` leitet an das Storage; `$domain` wird ignoriert. Twig: `{{ 'global.subscribe.title' | trans }}`, mit Parametern `trans({'%name%': x})`.

Key-Konvention (Beobachtung in `glossary.csv`): `<bereich>.<kontext>.<aussage>`, Kleinbuchstaben, Punkte (z. B. `cart.add.items.success`); offizielle Regel `[Annahme]`.

## Anleitung

**Neuen Store per Import anlegen** (Region EU; Befehle ausgeführt `[live verifiziert (202610.0)]`, Importer `store`, `currency-store`, `country-store`, `locale-store`, `default-locale-store`, `context-store` je Exit 0):
1. Zeile in `data/import/common/EU/store.csv`; Relationen in `data/import/common/<STORE>/{currency_store,country_store,locale_store,default_locale_store}.csv`, Kontext in `store_context.csv`; Importer-Register `data/import/common/<STORE>/<STORE>.yml`.
2. `docker/sdk cli console data:import --config=data/import/local/full_EU.yml` oder einzelne `data:import:store`, `…:currency-store`, `…:country-store`, `…:locale-store`, `…:default-locale-store`, `…:context-store` (**`store` immer vor den `*-store`-Relationen**).
3. `docker/sdk cli console queue:setup` und `docker/sdk cli console search:setup:sources` (neue Queues/Search-Quellen); Publish-Queues abarbeiten.

**Neue Locale:** Zuweisung im Store (`locale-store`), Glossar-Zeilen der Locale importieren, URLs/Suchindex neu publizieren (`publish:trigger-events`), sonst fehlen Sprach-URLs im `LanguageSwitcherWidget` `[Annahme: genaue Resource-Liste; verlässlich ist getStoreCreationResourcesToReSync()]`.

**Neuen Yves-Text:** Zeile in `glossary.csv` je Locale (`key,translation,locale`), `docker/sdk cli console data:import:glossary`, `docker/sdk cli console publish:trigger-events -r translation`, `docker/sdk cli console queue:worker:start --stop-when-empty` ([22](22-console-cron-queue.md)); im Twig `{{ 'key' | trans }}`.

**Store im Code lesen (DMS-konform):** Zed `$this->getFactory()->getStoreFacade()->getStoreByName('DE')` bzw. `getStoreCollection(new StoreCriteriaTransfer())`; Yves/Client `$this->getFactory()->getStoreClient()->getCurrentStore()`, `->getAvailableLocaleIsoCodes()`. Schichtübergreifend nur Facade/Client; `getStoreFacade()` im DependencyProvider des eigenen Moduls bereitstellen ([02](02-projektmodul.md)). URLs nur über `url()`/`path()`, nie `/DE/en/` von Hand.

**Zed-Texte** sind ein anderer Mechanismus: [18](18-back-office.md).

Weitere Befehle (`[live verifiziert (202610.0)]`, registriert): `docker/sdk cli console data:import:currency`, `translator:generate-cache` (Zed-CSV). Region wählen: `SPRYKER_CURRENT_REGION=US vendor/bin/console …` (CLI zeigt `Region: EU | Code bucket: EU`). Es gibt keine eigenen `store:*`-Konsolenbefehle (nur Import/Price). Datenprüfung: `docker/sdk cli "mysql --skip-ssl -h database -uroot -psecret eu-docker -e 'select id_store,name from spy_store'"` (`--skip-ssl` nötig).

## Beispiele

**Echt:** `src/Pyz/Zed/Store/StoreConfig.php`

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

**Echt:** `src/Pyz/Client/Store/StoreDependencyProvider.php`

```php
protected function getStoreExpanderPlugins(): array
{
    return [
        new StoreStorageStoreExpanderPlugin(),
        new TimezoneStoreStorageStoreExpanderPlugin(),
    ];
}
```

**Eigenes Post-Create-Plugin (nicht im Release, Interface-Signatur gegen `vendor/` geprüft):**

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
        // Store-bezogene Initialdaten anlegen (Beispiel: Standard-Badges)
        return (new StoreResponseTransfer())
            ->setIsSuccessful(true)
            ->setStore($storeTransfer);
    }
}
```

Registrierung: Eintrag in `Pyz\Zed\Store\StoreDependencyProvider::getStorePostCreatePlugins()` (Array erweitern).

**Glossar-Nutzung (echt, Muster):** `src/Pyz/Yves/NewsletterWidget/Theme/default/components/molecules/subscription/subscription.twig:21`: `title: 'global.subscribe.title' | trans`.

## Fallstricke

1. `Store::getInstance()` im DMS -> Exception; `APPLICATION_STORE` ist im DMS nicht definiert, nie ohne `defined()` prüfen.
2. `stores.php` ändern bringt im DMS nichts; Stores/Locales/Währungen/Länder per Back Office oder Import pflegen.
3. Import-Spalte `appication_context_collection` nicht "korrigieren" (bricht den Import).
4. Glossar-Key fehlt -> Yves zeigt den Key im Klartext (kein Fallback-Locale). Nach Import/Änderung Publish- (`publish.translation`) und Sync-Queue (`sync.storage.translation`) abarbeiten.
5. Reihenfolge der Application-Plugins: `StoreApplicationPlugin` vor `LocaleApplicationPlugin` vor `TranslatorApplicationPlugin`.
6. Store-/Locale-Strings nicht hartcodieren; `StoreTransfer` aus dem Client holen. Keine Router-URLs von Hand bauen.
7. Glue: Store per Header `Store`/`?_store=`, Locale per `Accept-Language`; unbekannter Store -> Validatorfehler 601.
8. `Translator::trans()` ignoriert `$domain`; Platzhalter `%name%` mit Parametern statt String-Konkatenation.
9. Erweiterungen nur über Plugins in `Pyz\*DependencyProvider` (StoreExpander, StorePostCreate, StoreGui-Expander); `vendor/spryker/store*` nie ändern ([28](28-upgradability.md)).

**Deprecated (aus dem Code):** `Spryker\Shared\Config\Application\Environment::defineStore()` ("Dynamic stores are based on the Channel context") und `defineLocale()` ("…Store context"); `Spryker\Zed\Store\StoreConfig::getDefaultTimezone()` ("Exists for BC reasons only", Zeitzone jetzt in `spy_store_context`); `StoreConfig::getStoreNameReferenceMap()` und `StoreConstants::STORE_NAME_REFERENCE_MAP` ("Will be removed without replacement"); `Yves\Translator\Translator::transChoice()` und `setLocale()` ("Will be removed without replacement"); `StoreFacade::expandAccessTokenRequest()`/`expandMessageAttributes()` nur BC für DMS aus. Optional `StoreConfig::isVisibleToCustomerEnabled()` (Spalte `is_visible_to_customer`, Default `false`, im Projekt nicht aktiviert). Doku `set-up-multiple-stores.html`/`handling-internationalization.html` beschreiben nach Titel das `stores.php`-Modell `[Annahme]`; in 202610.0 gilt es nur bei DMS aus.

## Checkliste

- [ ] DMS-Schalter und Region gesetzt; kein `Store::getInstance()` im Code
- [ ] Store-Daten per Import/Back Office, `store` vor den `*-store`-Relationen
- [ ] Application-Plugin-Reihenfolge Store -> Locale -> Translator
- [ ] Glossar-Keys für alle Locales importiert, `translation` publiziert, Queues leer
- [ ] Neue Store-Logik als Plugin (`StorePostCreate`/`StoreExpander`), nicht im Core
- [ ] Yves-URLs über `url()`/`path()`; Glue-Tests mit Header `Store` und `Accept-Language`
- [ ] Neue Queues/Search-Quellen nach Store-Anlage (`queue:setup`, `search:setup:sources`)

## Quellen

Doku (Basis `https://docs.spryker.com/docs/`, Inhalt nicht auswertbar): `pbc/all/dynamic-multistore/latest/dynamic-multistore.html`, `…/base-shop/dynamic-multistore-feature-overview.html`, `…/base-shop/import-stores.html`, `dg/dev/internationalization-and-multi-store/{set-up-multiple-stores,handling-internationalization,setting-up-a-multi-language-shop,managing-glossary-keys,managing-translations-with-twig-translator}.html`, `dg/dev/backend-development/data-manipulation/data-publishing/publish-and-synchronize-and-multi-store-shop-systems.html`.

Code: `deploy.dev.yml`, `config/Shared/{stores.php,default_store.php,config_default.php,config_default-docker.php}`, `config/install/dynamic-store.yml`, `vendor/spryker/kernel/src/Spryker/Shared/Kernel/Store.php`, `vendor/spryker/store/src/Spryker/{Zed,Client}/Store/**`, `vendor/spryker/store-extension/…/StorePostCreatePluginInterface.php`, `vendor/spryker/router/…/StorePrefixRouterEnhancerPlugin.php`, `vendor/spryker-shop/store-widget/…/StoreApplicationPlugin.php`, `vendor/spryker/stores-api/…/StoreResolver.php`, `vendor/spryker/glossary-storage/src/Spryker/Client/GlossaryStorage/**`, `vendor/spryker/translator/src/Spryker/Yves/Translator/**`, `src/Pyz/Zed/{Store,StoreGui,StoreStorage,Locale,Application,Publisher}/…`, `src/Pyz/Client/Store/StoreDependencyProvider.php`, `src/Pyz/Yves/{Router,ShopApplication,EventDispatcher}/…`, `data/import/common/**`, `data/import/local/full_EU.yml`.

Nachbarn: [07 Publish & Sync](07-publish-sync.md), [08 Storage](08-client-storage-search.md), [09 Data Import](09-data-import.md), [12 Glue Storefront](12-glue-storefront.md), [15 Frontend](15-yves-frontend-atomic.md), [18 Back Office](18-back-office.md), [30 Docker-SDK](30-docker-sdk-workflow.md).
