# Client layer: Storage, Search and Zed access, read side

> Status: release b2b-demo-shop 202610.0 (spryker/kernel 3.87.1). The docs pages `implement-a-client.html` and `use-and-configure-redis-or-valkey-as-a-key-value-store.html` only returned navigation text when fetched. All technical statements come from the installed code `[code-verified, docs not cross-checked]`, `[Assumption]` the docs match the code. Commands executed in the container `[live verified (202610.0)]` (search setup idempotent, index/snapshot commands on throwaway indices, cleaned up afterwards). Concrete Redis keys not checked against a running Redis. Writing to Redis/OpenSearch is covered in [Publish & Sync](07-publish-sync.md).

## Purpose

How Yves and Glue read data: client structure, reading from Redis/Valkey (Storage), search in OpenSearch/Elasticsearch, Zed calls via `ZedRequest` (and why to avoid them for read access) and installation of the search index mappings. Controllers that call clients: [Yves](14-yves-controller-routing.md). Plugin stacks: [Extending the core](03-extending-the-core.md).

## Concepts

- **Client** = `Spryker\Client\<Module>` (Pyz: `src/Pyz/Client/<Module>`). Required classes (pattern `vendor/spryker/sales-invoice/src/Spryker/Client/SalesInvoice/`):
  - `…Client extends AbstractClient implements …ClientInterface`: public API, every method `@api`, only delegates to `$this->getFactory()`.
  - `…Factory extends AbstractFactory`: creates readers/stubs, gets dependencies via `getProvidedDependency()`.
  - `…DependencyProvider extends AbstractDependencyProvider`: `provideServiceLayerDependencies(Container $container)` registers clients, services, plugins via `$container->set(KEY, fn)`.
  - optional `…Config extends AbstractBundleConfig`, `Zed/…Stub`, `Storage/…Reader`. Transfers in the Shared part ([Transfer](04-transfer.md)).
- **Class resolver:** classes are resolved by namespace; a class with the same name in `Pyz\Client\<Module>` overrides the core. In the project, Storage/Search/Catalog only contain DependencyProvider/Config, which inherit from the core.
- **Layer rule:** Yves/Glue only call the client, never a Zed Facade or Persistence. In the project, other modules' clients/services are fetched directly from the locator without a bridge (`$container->getLocator()->storage()->client()` in the DependencyProvider) and typed in the Factory getter to the interface of the other module (`Spryker\Client\Storage\StorageClientInterface`). Bridges (`Dependency/Client/…ToXClientInterface`) are a pure core pattern ([02](02-project-module.md)). The client API (`*ClientInterface`) is the public module API; reader and Factory are internal.
- **Storage clients:** `Spryker\Client\Storage\StorageClient` (`get($key)`, `getMulti(array $keys)`, `getKeys`, `scanKeys`, plus `set`/`delete`, which should not be used in the frontend). Adapter via `src/Pyz/Client/Storage/StorageDependencyProvider::getStoragePlugin()` = `StorageRedisPlugin`. New alongside it: `Spryker\Client\StorageRedis\StorageRedisClient` (typed, `get(string $key)`, `getMulti(array $keys): array`). `[Assumption]` the generic `StorageClient` remains for compatibility and the request cache (`getCachedKeys`, access stats); it is not marked `@deprecated`. The Redis prefix `kv:` is set internally by `StorageRedisWrapper::KV_PREFIX`; code passes keys without `kv:`. Connection: `config/Shared/config_default.php` (`StorageConstants::STORAGE_KV_SOURCE`, `StorageRedisConstants::*` from `SPRYKER_KEY_VALUE_STORE_*`, [Configuration](06-configuration.md)).
- **Never build a storage key by hand:** `SynchronizationService::getStorageKeyBuilder($resourceName)->generateKey(SynchronizationDataTransfer)`. Default (`DefaultKeyGeneratorPlugin`): `<resource>:<store>:<locale>:<reference>`, store/locale lowercase, reference sanitized, empty parts omitted. Example `[Assumption]` `product_abstract:de:de_de:123`. Running example: `product_badge:<id_product_abstract>` (no store, no locale).
- **Search building blocks:** `Spryker\Client\Search\SearchClient` is a delegator over adapter plugins (`src/Pyz/Client/Search/SearchDependencyProvider::getClientAdapterPlugins()`: `SearchHttpSearchAdapterPlugin` first, then `ElasticsearchSearchAdapterPlugin`, "very important to put this plugin at the top"). The engine lives in `Spryker\Client\SearchElasticsearch\SearchElasticsearchClient`.
  - **Query** (`QueryInterface::getSearchQuery()` returns an Elastica `Query`), e.g. `ProductCatalogSearchQueryPlugin`.
  - **QueryExpander** (`QueryExpanderPluginInterface::expandQuery(QueryInterface, array $requestParameters)`): `StoreQueryExpanderPlugin`, `LocalizedQueryExpanderPlugin`, `PaginatedQueryExpanderPlugin`, `SortedQueryExpanderPlugin`, `FacetQueryExpanderPlugin` (after filtering expanders).
  - **ResultFormatter** (`ResultFormatterPluginInterface::formatResult(...)`, `getName()` = key in the result), base `Spryker\Client\SearchElasticsearch\Plugin\ResultFormatter\AbstractElasticsearchResultFormatterPlugin` (implements `formatSearchResult(ResultSet, array)`).
  - **Facets/sorting/pagination:** `FacetConfigTransferBuilderPluginInterface`, `SortConfigTransferBuilderPluginInterface` in `Pyz\Client\Catalog\CatalogDependencyProvider`, pagination in `Pyz\Client\Catalog\CatalogConfig`.
  - **Catalog interplay:** `CatalogClient::catalogSearch($searchString, $requestParameters)` builds the query, `SearchClient::expandQuery` with the expanders, `SearchClient::search` with the formatters. Yves only calls the `CatalogClient`. For Search HTTP, parallel `…Variants()` methods exist (`SearchHttpConfig::TYPE_SEARCH_HTTP`), not explored in depth.
- **ZedRequest:** `ZedRequestClient::call(string $url, TransferInterface $object, $requestOptions = null): TransferInterface` sends an HTTP request to the Zed gateway (`/<module>/gateway/<action>`, example `CustomerStub`: `/customer/gateway/has-customer-with-email-and-password`). Structure: client -> `Factory::createZedStub()` -> `Zed/<Module>Stub` -> Zed `GatewayController`. Metadata and headers: `src/Pyz/Client/ZedRequest/ZedRequestDependencyProvider` (`getMetaDataProviderPlugins()`, `getHeaderExpanderPlugins()`).
  - **Use:** writing/stateful operations with Zed business logic (login, registration, cart persistence, checkout) or unpublished data.
  - **Avoid for read access in the Storefront hot path:** every call is a synchronous HTTP round trip (latency, scaling, Zed as a single point of failure, no cache). Rule of thumb (an assessment, not literally in the code, consistent with the architecture): implement new read logic first as a storage/search table with a reader, ZedStub only as an exception.

## Instructions

1. **Design read access:** bring the data to Redis/Search via Publish & Synchronization ([Publish & Sync](07-publish-sync.md)), then write the client reader.
2. **Create the client module:** `Client`, `ClientInterface`, `Factory`, `DependencyProvider`, `Reader/…`, `StorageClient` and `SynchronizationService` directly from the locator, without bridges. Complete: [`publisher-and-synchronization`](../templates/publisher-and-synchronization/README.md) (`Pyz\Client\ProductBadge`).
3. **Generate the key via the key builder:** set reference/store/locale in `SynchronizationDataTransfer`, `getStorageKeyBuilder(<resource>)->generateKey()`. Resource name from the shared config of the storage module, identical to `resource` in the schema.
4. **Read and map:** `storageClient->get($key)` returns a decoded array (or `null`). Mapper/`fromArray($data, true)` to the Transfer (`true`, because the behavior adds `_timestamp`). Several keys: `getMulti`.
5. **Extend the search:** hook query expanders and ResultFormatters into `Pyz\Client\Catalog\CatalogDependencyProvider` (explicit lists, add an entry, `FacetQueryExpanderPlugin` last). New facets/sorting via `getFacetConfigTransferBuilderPlugins()`/`getSortConfigTransferBuilderPlugins()`.
6. **New search index** (`search:setup:sources`):
   - `search:setup:sources` (`SearchSetupSourcesConsole`) calls `SearchFacade::installSources()` with the plugins from `Pyz\Zed\Search\SearchDependencyProvider::getSearchSourceInstallerPlugins()` (`ElasticsearchIndexInstallerPlugin`). Indices are created from JSON schemas.
   - JSON schema directories: `Pyz\Zed\SearchElasticsearch\SearchElasticsearchConfig::getJsonSchemaDefinitionDirectories()` adds `src/Pyz/Shared/*/Schema/` (project file `src/Pyz/Shared/Search/Schema/page.json`).
   - Valid source identifiers: `protected const SUPPORTED_SOURCE_IDENTIFIERS` in `Pyz\Shared\SearchElasticsearch\SearchElasticsearchConfig` = `page`, `product-review`, `return_reason`, `merchant`. New index: add the identifier and create a JSON schema.
   - `search:setup:source-map` (`GenerateSourceMapConsole`, description live: "generate the IndexMap classes by found schema definition files") generates the IndexMap classes from the JSON schemas (among others `Generated\Shared\Search\PageIndexMap` with `PageIndexMap::SEARCH_RESULT_DATA`, `PageIndexMap::TYPE`) via `getSearchMapInstallerPlugins()` -> `ElasticsearchIndexMapInstallerPlugin`. The command is called in `config/install/docker.yml` (`generate-page-source-map`). `getSearchPageMapPlugins()` is `@deprecated` ("Will be removed without replacement") and is not used for this; the Pyz list remains only for compatibility reasons.
   - After changing the JSON schemas: `search:setup:source-map`, then republish.
7. **Commands** (all listed here executed `[live verified (202610.0)]`; index names must be given **in full**, e.g. `spryker_b2b_dev_de_return_reason`; `elasticsearch:snapshot:restore` fails if open indices with the same name exist):
   ```bash
   docker/sdk cli console search:setup:sources        # StoreAwareConsole, optionally `--store[=STORE]` (no `-s`)
   docker/sdk cli console search:setup:source-map
   docker/sdk cli console setup:search                # alias of search:setup
   docker/sdk cli console elasticsearch:index:delete  # also close|open|copy
   docker/sdk cli console elasticsearch:snapshot:create   # also restore|delete|register-repository
   docker/sdk cli console sync:data <resource>        # refill Redis/Search -> 07-publish-sync.md
   ```
   Index management lives in the module `search-elasticsearch` (`elasticsearch:*`). The old names `search:index:close|copy|delete|open` and `search:snapshot:create|restore` are now only **aliases** of the `elasticsearch:*` commands (shown in square brackets in the command list; `snapshot:delete` and `register-repository` have no alias, a command `search:setup:index-map` is **not registered**). New code and new docs use `elasticsearch:*`. Inspecting Redis keys: Redis Commander (`redis-commander.spryker.local`, endpoint from `deploy.dev.yml`) or the storage GUI in the Back Office; in Redis the key has the prefix `kv:` (e.g. `kv:product_badge:123`).

## Examples

Complete: [`publisher-and-synchronization`](../templates/publisher-and-synchronization/README.md). Reader for the running example (`src/Pyz/Client/ProductBadge/Reader/ProductBadgeStorageReader.php`, core part):

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

Call from Yves/Glue: `$client->findProductBadgesByIdProductAbstract(123)` (`Pyz\Client\ProductBadge\ProductBadgeClientInterface`).

Core pattern, build and read a storage key (`vendor/spryker/product-storage/.../ProductAbstractStorageReader.php`):

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

Core pattern client with Zed stub (`vendor/spryker/sales-invoice/src/Spryker/Client/SalesInvoice/`, shortened):

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

Project storage adapter, real (`src/Pyz/Client/Storage/StorageDependencyProvider.php`):

```php
protected function getStoragePlugin(): ?StoragePluginInterface
{
    return new StorageRedisPlugin();
}
```

Search plugin stacks, real, shortened (`src/Pyz/Client/Catalog/CatalogDependencyProvider.php`):

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

Own ResultFormatter, template (not in the release; `src/Pyz/Client/Catalog/Plugin/Elasticsearch/ResultFormatter/ProductNameListResultFormatterPlugin.php`; `abstract_name` and `PageIndexMap::SEARCH_RESULT_DATA` confirmed in the release):

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

Add an entry to `createCatalogSearchResultFormatterPlugins()`, retrieval `$result = $catalogClient->catalogSearch($q, $params); $result['productNames']`. `getName()` is untyped in the interface; the return type `string` is compatible `[Assumption]`.

## Pitfalls

- Never concatenate Redis keys, always use the key builder (format, normalization, store handling).
- Mind the order of the expanders: `FacetQueryExpanderPlugin` last. `SearchHttpSearchAdapterPlugin` and `SearchHttpSearchContextExpanderPlugin` are at the front in `Pyz\Client\Search\SearchDependencyProvider`; do not reorder them.
- New index without an identifier in `SUPPORTED_SOURCE_IDENTIFIERS`, without a JSON schema or without `search:setup:sources`: the index is missing.
- No `ZedRequest` for read access in the request path. Stub paths `/<module>/gateway/<action>` must match the Zed `GatewayController`.
- In the project, fetch other modules' clients directly from the locator (no bridge) and type them in the Factory getter to the client interface, not to concrete classes.
- Readers like `ProductAbstractStorageReader` cache in static properties: reset them in tests.
- The project extends via plugins/DependencyProvider (Pyz inherits from the core provider and calls `parent::` for stacks), not by changing the core ([Extending the core](03-extending-the-core.md)).
- Forgetting `fromArray($data, true)`: `_timestamp` in the Redis value is not a Transfer property.
- New Pyz files with `declare(strict_types = 1);`.
- **Deprecations (code 202610.0):**
  - `[Deprecated]` `Spryker\Client\Search\Dependency\Plugin\{QueryInterface,QueryExpanderPluginInterface,ResultFormatterPluginInterface,SearchConfigBuilderInterface,SearchConfigExpanderPluginInterface,SearchStringGetterInterface,FacetSearchResultValueTransformerPluginInterface}` -> `Spryker\Client\SearchExtension\Dependency\Plugin\*`.
  - `[Deprecated]` `Search\Dependency\Plugin\{FacetConfigBuilderInterface,PaginationConfigBuilderInterface}` -> `SearchElasticsearch\Config\{FacetConfigInterface,PaginationConfigInterface}`.
  - `[Deprecated]` `Spryker\Client\Search\Plugin\Elasticsearch\ResultFormatter\AbstractElasticsearchResultFormatterPlugin` -> `Spryker\Client\SearchElasticsearch\Plugin\ResultFormatter\AbstractElasticsearchResultFormatterPlugin`. Finding: the core `RawCatalogSearchResultFormatterPlugin` still imports the old class; new project plugins use the new base.
  - `[Deprecated]` `SearchClientInterface::getSearchConfig()` and `searchQueryString()` (without replacement), `read`/`write`/`writeBulk`/`delete`/`deleteBulk` -> `readDocument`/`writeDocument(s)`/`deleteDocument(s)`. Return type of `search()`: in future only `mixed`.
  - The docs partly still describe the old Search module; the code takes precedence: engine logic in `search-elasticsearch`, `search` is a delegator (search migration concept, content not checked).

## Checklist

- [ ] Data is published in Redis/Search ([Publish & Sync](07-publish-sync.md)), resource name identical.
- [ ] Client module with Client/Interface/Factory/DependencyProvider, storage client and synchronization service directly from the locator (no bridges).
- [ ] Key via `getStorageKeyBuilder()->generateKey()`, not via string.
- [ ] Reader returns Transfers, `fromArray(..., true)`, empty result if the key is missing.
- [ ] No Zed round trip for read logic.
- [ ] Search: expander order, ResultFormatter with the new base class, index identifier and JSON schema, `search:setup:sources`/`source-map` executed.
- [ ] No outdated `Spryker\Client\Search\Dependency\Plugin\*` interfaces in new code.

## Sources

Docs (base https://docs.spryker.com/docs/, content not cross-checked): `dg/dev/backend-development/client/client.html`, `dg/dev/backend-development/client/implement-a-client.html`, `dg/dev/backend-development/client/use-and-configure-redis-or-valkey-as-a-key-value-store.html`, `dg/dev/architecture/key-value-storage-redis-and-valkey.html`, `pbc/all/search/latest/base-shop/tutorials-and-howtos/configure-elasticsearch.html`, `pbc/all/search/latest/base-shop/install-and-upgrade/search-migration-concept.html`.

Code:
- `src/Pyz/Client/{Storage,Search,SearchElasticsearch,Catalog,ProductStorage,ZedRequest}/*`, `src/Pyz/Shared/Search/Schema/page.json`, `src/Pyz/Shared/SearchElasticsearch/SearchElasticsearchConfig.php`, `src/Pyz/Zed/Search/SearchDependencyProvider.php`, `src/Pyz/Zed/SearchElasticsearch/SearchElasticsearchConfig.php`
- `vendor/spryker/storage/src/Spryker/Client/Storage/{StorageClient,StorageClientInterface,StorageDependencyProvider}.php`, `vendor/spryker/storage-redis/src/Spryker/Client/StorageRedis/{StorageRedisClientInterface.php,Redis/StorageRedisWrapper.php}`
- `vendor/spryker/search/src/Spryker/Client/Search/*`, `vendor/spryker/search/src/Spryker/Zed/Search/Communication/Console/*`, `vendor/spryker/search-elasticsearch/src/Spryker/Client/SearchElasticsearch/**`, `vendor/spryker/search-extension/src/Spryker/Client/SearchExtension/Dependency/Plugin/*`
- `vendor/spryker/catalog/src/Spryker/Client/Catalog/{CatalogClient.php,Plugin/Elasticsearch/**}`
- `vendor/spryker/product-storage/src/Spryker/Client/ProductStorage/*`, `vendor/spryker/asset-storage/src/Spryker/Client/AssetStorage/*`
- `vendor/spryker/synchronization/src/Spryker/Service/Synchronization/Plugin/{BaseKeyGenerator,DefaultKeyGeneratorPlugin}.php`
- `vendor/spryker/zed-request/src/Spryker/Client/ZedRequest/ZedRequestClient.php`, `vendor/spryker/customer/src/Spryker/Client/Customer/Zed/CustomerStub.php`, `vendor/spryker/sales-invoice/src/Spryker/Client/SalesInvoice/*`
- `config/Shared/config_default.php`, `config/install/docker.yml`

Open gaps: docs contents not verified, `src/Pyz/Client/SearchHttp` not explored in depth, `StorageClient` vs. `StorageRedisClient` target state `[Assumption]`.

Neighboring references: [Architecture](01-architecture.md), [Extending the core](03-extending-the-core.md), [Transfer](04-transfer.md), [Configuration](06-configuration.md), [Publish & Sync](07-publish-sync.md), [Yves](14-yves-controller-routing.md), [Console/Queue](22-console-cron-queue.md). Template: [publisher-and-synchronization](../templates/publisher-and-synchronization/README.md).
