# Client-Schicht: Storage-, Search- und Zed-Zugriff, Leseseite (Recherche T-08)

> Status: Release b2b-demo-shop 202610.0 (spryker/kernel 3.87.1). Die Doku-Seiten `implement-a-client.html` und `use-and-configure-redis-or-valkey-as-a-key-value-store.html` lieferten per Abruf nur Navigationstext. Alle technischen Aussagen stammen aus dem installierten Code `[code-verifiziert, Doku nicht abgeglichen]`, `[Annahme]` die Doku deckt sich mit dem Code. Befehle im Container ausgeführt `[live verifiziert (202610.0)]` (Search-Setup idempotent, Index-/Snapshot-Befehle an Wegwerf-Indizes, danach aufgeräumt). Konkrete Redis-Keys nicht gegen ein laufendes Redis geprüft. Das Schreiben nach Redis/OpenSearch steht in [Publish & Sync](07-publish-sync.md).

## Zweck

Wie Yves und Glue Daten lesen: Client-Aufbau, Lesen aus Redis/Valkey (Storage), Suche in OpenSearch/Elasticsearch, Zed-Aufrufe über `ZedRequest` (und warum man sie für Lesezugriffe meidet) und Installation der Search-Index-Mappings. Controller, die Clients aufrufen: [Yves](14-yves-controller-routing.md). Plugin-Stacks: [Core erweitern](03-core-erweitern.md).

## Konzepte

- **Client** = `Spryker\Client\<Modul>` (Pyz: `src/Pyz/Client/<Modul>`). Pflichtklassen (Muster `vendor/spryker/sales-invoice/src/Spryker/Client/SalesInvoice/`):
  - `…Client extends AbstractClient implements …ClientInterface`: öffentliche API, jede Methode `@api`, delegiert nur an `$this->getFactory()`.
  - `…Factory extends AbstractFactory`: erzeugt Reader/Stubs, holt Abhängigkeiten über `getProvidedDependency()`.
  - `…DependencyProvider extends AbstractDependencyProvider`: `provideServiceLayerDependencies(Container $container)` registriert Clients, Services, Plugins per `$container->set(KEY, fn)`.
  - optional `…Config extends AbstractBundleConfig`, `Zed/…Stub`, `Storage/…Reader`. Transfers im Shared-Teil ([Transfer](04-transfer.md)).
- **Class-Resolver:** Klassen werden nach Namespace aufgelöst, eine gleichnamige Klasse in `Pyz\Client\<Modul>` überschreibt den Core. Im Projekt existieren in Storage/Search/Catalog nur DependencyProvider/Config, die vom Core erben.
- **Schichtregel:** Yves/Glue rufen nur den Client, nie Zed-Facade oder Persistence. Im Projekt werden fremde Clients/Services ohne Bridge direkt aus dem Locator geholt (`$container->getLocator()->storage()->client()` im DependencyProvider) und im Factory-Getter auf das Interface des Fremdmoduls typisiert (`Spryker\Client\Storage\StorageClientInterface`). Bridges (`Dependency/Client/…ToXClientInterface`) sind ein reines Core-Muster ([02](02-projektmodul.md)). Die Client-API (`*ClientInterface`) ist die öffentliche Modul-API, Reader und Factory sind intern.
- **Storage-Clients:** `Spryker\Client\Storage\StorageClient` (`get($key)`, `getMulti(array $keys)`, `getKeys`, `scanKeys`, plus `set`/`delete`, die im Frontend nicht verwendet werden sollen). Adapter über `src/Pyz/Client/Storage/StorageDependencyProvider::getStoragePlugin()` = `StorageRedisPlugin`. Neu daneben `Spryker\Client\StorageRedis\StorageRedisClient` (typisiert, `get(string $key)`, `getMulti(array $keys): array`). `[Annahme]` der generische `StorageClient` bleibt für Kompatibilität und Request-Cache (`getCachedKeys`, Access-Stats), er ist nicht als `@deprecated` markiert. Redis-Prefix `kv:` setzt `StorageRedisWrapper::KV_PREFIX` intern, Code übergibt Keys ohne `kv:`. Verbindung: `config/Shared/config_default.php` (`StorageConstants::STORAGE_KV_SOURCE`, `StorageRedisConstants::*` aus `SPRYKER_KEY_VALUE_STORE_*`, [Konfiguration](06-konfiguration.md)).
- **Storage-Key nie von Hand bauen:** `SynchronizationService::getStorageKeyBuilder($resourceName)->generateKey(SynchronizationDataTransfer)`. Default (`DefaultKeyGeneratorPlugin`): `<resource>:<store>:<locale>:<reference>`, Store/Locale klein, Reference bereinigt, leere Teile entfallen. Beispiel `[Annahme]` `product_abstract:de:de_de:123`. Laufbeispiel: `product_badge:<id_product_abstract>` (kein Store, keine Locale).
- **Search-Bausteine:** `Spryker\Client\Search\SearchClient` ist Delegator über Adapter-Plugins (`src/Pyz/Client/Search/SearchDependencyProvider::getClientAdapterPlugins()`: `SearchHttpSearchAdapterPlugin` zuerst, dann `ElasticsearchSearchAdapterPlugin`, "very important to put this plugin at the top"). Die Engine liegt in `Spryker\Client\SearchElasticsearch\SearchElasticsearchClient`.
  - **Query** (`QueryInterface::getSearchQuery()` liefert Elastica-`Query`), z. B. `ProductCatalogSearchQueryPlugin`.
  - **QueryExpander** (`QueryExpanderPluginInterface::expandQuery(QueryInterface, array $requestParameters)`): `StoreQueryExpanderPlugin`, `LocalizedQueryExpanderPlugin`, `PaginatedQueryExpanderPlugin`, `SortedQueryExpanderPlugin`, `FacetQueryExpanderPlugin` (nach filternden Expandern).
  - **ResultFormatter** (`ResultFormatterPluginInterface::formatResult(...)`, `getName()` = Schlüssel im Ergebnis), Basis `Spryker\Client\SearchElasticsearch\Plugin\ResultFormatter\AbstractElasticsearchResultFormatterPlugin` (implementiert `formatSearchResult(ResultSet, array)`).
  - **Facetten/Sortierung/Pagination:** `FacetConfigTransferBuilderPluginInterface`, `SortConfigTransferBuilderPluginInterface` in `Pyz\Client\Catalog\CatalogDependencyProvider`, Pagination in `Pyz\Client\Catalog\CatalogConfig`.
  - **Catalog-Zusammenspiel:** `CatalogClient::catalogSearch($searchString, $requestParameters)` baut Query, `SearchClient::expandQuery` mit den Expandern, `SearchClient::search` mit den Formattern. Yves ruft nur den `CatalogClient`. Für Search-HTTP existieren parallel `…Variants()`-Methoden (`SearchHttpConfig::TYPE_SEARCH_HTTP`), nicht vertieft.
- **ZedRequest:** `ZedRequestClient::call(string $url, TransferInterface $object, $requestOptions = null): TransferInterface` sendet einen HTTP-Request an das Zed-Gateway (`/<modul>/gateway/<action>`, Beispiel `CustomerStub`: `/customer/gateway/has-customer-with-email-and-password`). Aufbau: Client -> `Factory::createZedStub()` -> `Zed/<Modul>Stub` -> Zed `GatewayController`. Meta-Daten und Header: `src/Pyz/Client/ZedRequest/ZedRequestDependencyProvider` (`getMetaDataProviderPlugins()`, `getHeaderExpanderPlugins()`).
  - **Nutzen:** schreibende/zustandsbehaftete Vorgänge mit Zed-Business-Logik (Login, Registrierung, Warenkorb-Persistenz, Checkout) oder nicht publizierte Daten.
  - **Meiden bei Lesezugriffen im Storefront-Hot-Path:** jeder Aufruf ist ein synchroner HTTP-Roundtrip (Latenz, Skalierung, Zed als Single Point of Failure, kein Cache). Faustregel (Bewertung, nicht wörtlich im Code, konsistent mit der Architektur): neue Leselogik zuerst als Storage-/Search-Tabelle mit Reader, ZedStub nur als Ausnahme.

## Anleitung

1. **Lesezugriff entwerfen:** Daten per Publish & Sync nach Redis/Search bringen ([Publish & Sync](07-publish-sync.md)), dann Client-Reader schreiben.
2. **Client-Modul anlegen:** `Client`, `ClientInterface`, `Factory`, `DependencyProvider`, `Reader/…`, `StorageClient` und `SynchronizationService` direkt aus dem Locator, ohne Bridges. Vollständig: [`publisher-and-synchronization`](../templates/publisher-and-synchronization/README.md) (`Pyz\Client\ProductBadge`).
3. **Key über den Key-Builder erzeugen:** Reference/Store/Locale in `SynchronizationDataTransfer` setzen, `getStorageKeyBuilder(<resource>)->generateKey()`. Resource-Name aus der Shared-Config des Storage-Moduls, identisch zum `resource` im Schema.
4. **Lesen und mappen:** `storageClient->get($key)` liefert ein dekodiertes Array (oder `null`). Mapper/`fromArray($data, true)` zu Transfer (`true`, weil der Behavior `_timestamp` ergänzt). Mehrere Keys: `getMulti`.
5. **Suche erweitern:** Query-Expander und ResultFormatter in `Pyz\Client\Catalog\CatalogDependencyProvider` einhängen (explizite Listen, Eintrag ergänzen, `FacetQueryExpanderPlugin` zuletzt). Neue Facetten/Sortierung über `getFacetConfigTransferBuilderPlugins()`/`getSortConfigTransferBuilderPlugins()`.
6. **Neuen Search-Index** (`search:setup:sources`):
   - `search:setup:sources` (`SearchSetupSourcesConsole`) ruft `SearchFacade::installSources()` mit den Plugins aus `Pyz\Zed\Search\SearchDependencyProvider::getSearchSourceInstallerPlugins()` (`ElasticsearchIndexInstallerPlugin`). Indizes entstehen aus JSON-Schemas.
   - JSON-Schema-Verzeichnisse: `Pyz\Zed\SearchElasticsearch\SearchElasticsearchConfig::getJsonSchemaDefinitionDirectories()` ergänzt `src/Pyz/Shared/*/Schema/` (Projektdatei `src/Pyz/Shared/Search/Schema/page.json`).
   - Gültige Source-Identifier: `protected const SUPPORTED_SOURCE_IDENTIFIERS` in `Pyz\Shared\SearchElasticsearch\SearchElasticsearchConfig` = `page`, `product-review`, `return_reason`, `merchant`. Neuer Index: Identifier ergänzen und JSON-Schema anlegen.
   - `search:setup:source-map` (`GenerateSourceMapConsole`, Beschreibung live: "generate the IndexMap classes by found schema definition files") erzeugt die IndexMap-Klassen aus den JSON-Schemas (u. a. `Generated\Shared\Search\PageIndexMap` mit `PageIndexMap::SEARCH_RESULT_DATA`, `PageIndexMap::TYPE`) über `getSearchMapInstallerPlugins()` -> `ElasticsearchIndexMapInstallerPlugin`. Der Befehl wird in `config/install/docker.yml` aufgerufen (`generate-page-source-map`). `getSearchPageMapPlugins()` ist `@deprecated` ("Will be removed without replacement") und wird dafür nicht verwendet; die Pyz-Liste bleibt nur aus Kompatibilitätsgründen.
   - Nach Änderung der JSON-Schemas: `search:setup:source-map`, danach neu publizieren.
7. **Befehle** (alle hier genannten ausgeführt `[live verifiziert (202610.0)]`; Indexnamen sind **vollständig** anzugeben, z. B. `spryker_b2b_dev_de_return_reason`; `elasticsearch:snapshot:restore` scheitert bei offenen gleichnamigen Indizes):
   ```bash
   docker/sdk cli console search:setup:sources        # StoreAwareConsole, ggf. `--store[=STORE]` (kein `-s`)
   docker/sdk cli console search:setup:source-map
   docker/sdk cli console setup:search                # Alias von search:setup
   docker/sdk cli console elasticsearch:index:delete  # auch close|open|copy
   docker/sdk cli console elasticsearch:snapshot:create   # auch restore|delete|register-repository
   docker/sdk cli console sync:data <resource>        # Redis/Search neu befüllen -> 07-publish-sync.md
   ```
   Die Index-Verwaltung liegt im Modul `search-elasticsearch` (`elasticsearch:*`). Die alten Namen `search:index:close|copy|delete|open` und `search:snapshot:create|restore` sind nur noch **Aliase** der `elasticsearch:*`-Befehle (in der Befehlsliste in eckigen Klammern; `snapshot:delete` und `register-repository` haben keinen Alias, ein Befehl `search:setup:index-map` ist **nicht registriert**). Neuer Code und neue Doku verwenden `elasticsearch:*`. Redis-Keys einsehen: Redis Commander (`redis-commander.spryker.local`, Endpoint aus `deploy.dev.yml`) oder die Storage-GUI im Back Office; im Redis liegt der Key mit Prefix `kv:` (z. B. `kv:product_badge:123`).

## Beispiele

Vollständig: [`publisher-and-synchronization`](../templates/publisher-and-synchronization/README.md). Reader für das Laufbeispiel (`src/Pyz/Client/ProductBadge/Reader/ProductBadgeStorageReader.php`, Kern):

```php
public function findProductBadgesByIdProductAbstract(int $idProductAbstract): array
{
    $productBadgeStorageData = $this->storageClient->get($this->generateStorageKey($idProductAbstract));

    if (!is_array($productBadgeStorageData) || $productBadgeStorageData === []) {
        return [];
    }

    $productBadgeStorageTransfer = (new ProductBadgeStorageTransfer())->fromArray($productBadgeStorageData, true);

    return $productBadgeStorageTransfer->getProductBadges()->getArrayCopy();
}

protected function generateStorageKey(int $idProductAbstract): string
{
    $synchronizationDataTransfer = (new SynchronizationDataTransfer())
        ->setReference((string)$idProductAbstract);

    return $this->synchronizationService
        ->getStorageKeyBuilder(ProductBadgeStorageConfig::PRODUCT_BADGE_RESOURCE_NAME)
        ->generateKey($synchronizationDataTransfer);   // product_badge:123
}
```

Aufruf aus Yves/Glue: `$client->findProductBadgesByIdProductAbstract(123)` (`Pyz\Client\ProductBadge\ProductBadgeClientInterface`).

Core-Muster, Storage-Key bauen und lesen (`vendor/spryker/product-storage/.../ProductAbstractStorageReader.php`):

```php
$synchronizationDataTransfer = (new SynchronizationDataTransfer())
    ->setReference($reference)
    ->setLocale($locale)
    ->setStore($storeName);

$key = $this->synchronizationService
    ->getStorageKeyBuilder(ProductStorageConstants::PRODUCT_ABSTRACT_RESOURCE_NAME)
    ->generateKey($synchronizationDataTransfer);
$productStorageData = $this->storageClient->get($key);
```

Core-Muster Client mit Zed-Stub (`vendor/spryker/sales-invoice/src/Spryker/Client/SalesInvoice/`, gekürzt):

```php
class SalesInvoiceClient extends AbstractClient implements SalesInvoiceClientInterface
{
    public function getOrderInvoices(OrderInvoiceCriteriaTransfer $orderInvoiceCriteriaTransfer): OrderInvoiceCollectionTransfer
    {
        return $this->getFactory()->createZedStub()->getOrderInvoices($orderInvoiceCriteriaTransfer);
    }
}

// Factory
public function createZedStub(): SalesInvoiceStubInterface
{
    return new SalesInvoiceStub($this->getZedRequestClient());
}
```

Projekt-Storage-Adapter, echt (`src/Pyz/Client/Storage/StorageDependencyProvider.php`):

```php
protected function getStoragePlugin(): ?StoragePluginInterface
{
    return new StorageRedisPlugin();
}
```

Such-Plugin-Stacks, echt, gekürzt (`src/Pyz/Client/Catalog/CatalogDependencyProvider.php`):

```php
protected function createCatalogSearchQueryExpanderPlugins(): array
{
    return [
        new StoreQueryExpanderPlugin(),
        new LocalizedQueryExpanderPlugin(),
        new ProductPriceQueryExpanderPlugin(),
        new SortedQueryExpanderPlugin(),
        new PaginatedQueryExpanderPlugin(),
        new IsActiveQueryExpanderPlugin(),
        // FacetQueryExpanderPlugin needs to be after other query expanders which filters down the results.
        new FacetQueryExpanderPlugin(),
    ];
}
```

Eigener ResultFormatter, Vorlage (nicht im Release; `src/Pyz/Client/Catalog/Plugin/Elasticsearch/ResultFormatter/ProductNameListResultFormatterPlugin.php`; `abstract_name` und `PageIndexMap::SEARCH_RESULT_DATA` im Release belegt):

```php
use Elastica\ResultSet;
use Generated\Shared\Search\PageIndexMap;
use Spryker\Client\SearchElasticsearch\Plugin\ResultFormatter\AbstractElasticsearchResultFormatterPlugin;

class ProductNameListResultFormatterPlugin extends AbstractElasticsearchResultFormatterPlugin
{
    public const string NAME = 'productNames';

    public function getName(): string
    {
        return static::NAME;
    }

    /**
     * @param \Elastica\ResultSet $searchResult
     * @param array<string, mixed> $requestParameters
     *
     * @return array<string>
     */
    protected function formatSearchResult(ResultSet $searchResult, array $requestParameters): array
    {
        $names = [];
        foreach ($searchResult->getResults() as $document) {
            $names[] = (string)($document->getSource()[PageIndexMap::SEARCH_RESULT_DATA]['abstract_name'] ?? '');
        }

        return $names;
    }
}
```

Eintrag in `createCatalogSearchResultFormatterPlugins()` ergänzen, Abruf `$result = $catalogClient->catalogSearch($q, $params); $result['productNames']`. `getName()` ist im Interface untypisiert, der Rückgabetyp `string` ist kompatibel `[Annahme]`.

## Fallstricke

- Redis-Keys nie konkatenieren, immer den Key-Builder nutzen (Format, Normalisierung, Store-Handling).
- Reihenfolge der Expander beachten: `FacetQueryExpanderPlugin` zuletzt. `SearchHttpSearchAdapterPlugin` und `SearchHttpSearchContextExpanderPlugin` stehen vorn in `Pyz\Client\Search\SearchDependencyProvider`, nicht umsortieren.
- Neuer Index ohne Identifier in `SUPPORTED_SOURCE_IDENTIFIERS`, ohne JSON-Schema oder ohne `search:setup:sources`: Index fehlt.
- Kein `ZedRequest` für Lesezugriffe im Request-Pfad. Stub-Pfade `/<modul>/gateway/<action>` müssen zum Zed-`GatewayController` passen.
- Fremd-Clients im Projekt direkt aus dem Locator holen (keine Bridge) und im Factory-Getter auf das Client-Interface typisieren, nicht auf konkrete Klassen.
- Reader wie `ProductAbstractStorageReader` cachen in statischen Properties: in Tests zurücksetzen.
- Projekt erweitert über Plugins/DependencyProvider (Pyz erbt vom Core-Provider und ruft bei Stacks `parent::`), nicht durch Änderung am Core ([Core erweitern](03-core-erweitern.md)).
- `fromArray($data, true)` vergessen: `_timestamp` im Redis-Wert ist keine Transfer-Property.
- Neue Pyz-Dateien mit `declare(strict_types = 1);`.
- **Deprecations (Code 202610.0):**
  - `[Deprecated]` `Spryker\Client\Search\Dependency\Plugin\{QueryInterface,QueryExpanderPluginInterface,ResultFormatterPluginInterface,SearchConfigBuilderInterface,SearchConfigExpanderPluginInterface,SearchStringGetterInterface,FacetSearchResultValueTransformerPluginInterface}` -> `Spryker\Client\SearchExtension\Dependency\Plugin\*`.
  - `[Deprecated]` `Search\Dependency\Plugin\{FacetConfigBuilderInterface,PaginationConfigBuilderInterface}` -> `SearchElasticsearch\Config\{FacetConfigInterface,PaginationConfigInterface}`.
  - `[Deprecated]` `Spryker\Client\Search\Plugin\Elasticsearch\ResultFormatter\AbstractElasticsearchResultFormatterPlugin` -> `Spryker\Client\SearchElasticsearch\Plugin\ResultFormatter\AbstractElasticsearchResultFormatterPlugin`. Befund: der Core-`RawCatalogSearchResultFormatterPlugin` importiert noch die alte Klasse, neue Projektplugins nehmen die neue Basis.
  - `[Deprecated]` `SearchClientInterface::getSearchConfig()` und `searchQueryString()` (ohne Ersatz), `read`/`write`/`writeBulk`/`delete`/`deleteBulk` -> `readDocument`/`writeDocument(s)`/`deleteDocument(s)`. Rückgabetyp von `search()`: künftig nur `mixed`.
  - Die Doku weist teils noch das alte Search-Modul aus, der Code hat Vorrang: Engine-Logik in `search-elasticsearch`, `search` ist Delegator (Search-Migration-Konzept, nicht inhaltlich geprüft).

## Checkliste

- [ ] Daten liegen publiziert in Redis/Search ([Publish & Sync](07-publish-sync.md)), Resource-Name identisch.
- [ ] Client-Modul mit Client/Interface/Factory/DependencyProvider, Storage-Client und Synchronization-Service direkt aus dem Locator (keine Bridges).
- [ ] Key per `getStorageKeyBuilder()->generateKey()`, nicht per String.
- [ ] Reader liefert Transfers, `fromArray(..., true)`, leeres Ergebnis bei fehlendem Key.
- [ ] Kein Zed-Roundtrip für Leselogik.
- [ ] Search: Expander-Reihenfolge, ResultFormatter mit neuer Basisklasse, Index-Identifier und JSON-Schema, `search:setup:sources`/`source-map` ausgeführt.
- [ ] Keine veralteten `Spryker\Client\Search\Dependency\Plugin\*`-Interfaces im neuen Code.

## Quellen

Doku (Basis https://docs.spryker.com/docs/, Inhalt nicht abgeglichen): `dg/dev/backend-development/client/client.html`, `dg/dev/backend-development/client/implement-a-client.html`, `dg/dev/backend-development/client/use-and-configure-redis-or-valkey-as-a-key-value-store.html`, `dg/dev/architecture/key-value-storage-redis-and-valkey.html`, `pbc/all/search/latest/base-shop/tutorials-and-howtos/configure-elasticsearch.html`, `pbc/all/search/latest/base-shop/install-and-upgrade/search-migration-concept.html`.

Code:
- `src/Pyz/Client/{Storage,Search,SearchElasticsearch,Catalog,ProductStorage,ZedRequest}/*`, `src/Pyz/Shared/Search/Schema/page.json`, `src/Pyz/Shared/SearchElasticsearch/SearchElasticsearchConfig.php`, `src/Pyz/Zed/Search/SearchDependencyProvider.php`, `src/Pyz/Zed/SearchElasticsearch/SearchElasticsearchConfig.php`
- `vendor/spryker/storage/src/Spryker/Client/Storage/{StorageClient,StorageClientInterface,StorageDependencyProvider}.php`, `vendor/spryker/storage-redis/src/Spryker/Client/StorageRedis/{StorageRedisClientInterface.php,Redis/StorageRedisWrapper.php}`
- `vendor/spryker/search/src/Spryker/Client/Search/*`, `vendor/spryker/search/src/Spryker/Zed/Search/Communication/Console/*`, `vendor/spryker/search-elasticsearch/src/Spryker/Client/SearchElasticsearch/**`, `vendor/spryker/search-extension/src/Spryker/Client/SearchExtension/Dependency/Plugin/*`
- `vendor/spryker/catalog/src/Spryker/Client/Catalog/{CatalogClient.php,Plugin/Elasticsearch/**}`
- `vendor/spryker/product-storage/src/Spryker/Client/ProductStorage/*`, `vendor/spryker/asset-storage/src/Spryker/Client/AssetStorage/*`
- `vendor/spryker/synchronization/src/Spryker/Service/Synchronization/Plugin/{BaseKeyGenerator,DefaultKeyGeneratorPlugin}.php`
- `vendor/spryker/zed-request/src/Spryker/Client/ZedRequest/ZedRequestClient.php`, `vendor/spryker/customer/src/Spryker/Client/Customer/Zed/CustomerStub.php`, `vendor/spryker/sales-invoice/src/Spryker/Client/SalesInvoice/*`
- `config/Shared/config_default.php`, `config/install/docker.yml`

Offene Lücken: Doku-Inhalte nicht verifiziert, `src/Pyz/Client/SearchHttp` nicht vertieft, `StorageClient` vs. `StorageRedisClient` Zielzustand `[Annahme]`.

Nachbar-Referenzen: [Architektur](01-architektur.md), [Core erweitern](03-core-erweitern.md), [Transfer](04-transfer.md), [Konfiguration](06-konfiguration.md), [Publish & Sync](07-publish-sync.md), [Yves](14-yves-controller-routing.md), [Console/Queue](22-console-cron-queue.md). Template: [publisher-and-synchronization](../templates/publisher-and-synchronization/README.md).
