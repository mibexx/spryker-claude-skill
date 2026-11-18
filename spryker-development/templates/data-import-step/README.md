# Template: data-import-step (DataImporter for ProductBadge)

## Purpose
Custom CSV importer as project module `Pyz\Zed\ProductBadgeDataImport` via the plugin approach (import type `product-badge`): config constant, DataSet interface, lookup step (`abstract_sku` -> `id_product_abstract`), writer step (idempotent upsert into `spy_product_badge`), `DataImportPluginInterface` plugin. Background: [09-data-import](../../references/09-data-import.md).

## File list (target path below the project root)
| File | Role |
|---|---|
| `src/Pyz/Zed/ProductBadgeDataImport/ProductBadgeDataImportConfig.php` | `IMPORT_TYPE_PRODUCT_BADGE`, fallback importer configuration |
| `src/Pyz/Zed/ProductBadgeDataImport/ProductBadgeDataImportDependencyProvider.php` | empty, inherits from the core (needed for the base factory) |
| `src/Pyz/Zed/ProductBadgeDataImport/Business/ProductBadgeDataImportBusinessFactory.php` | reader + step broker + steps |
| `src/Pyz/Zed/ProductBadgeDataImport/Business/ProductBadgeDataImportFacade(Interface).php` | `importProductBadge()` |
| `src/Pyz/Zed/ProductBadgeDataImport/Business/Writer/ProductBadge/DataSet/ProductBadgeDataSetInterface.php` | CSV columns and step keys |
| `src/Pyz/Zed/ProductBadgeDataImport/Business/Writer/ProductBadge/AbstractSkuToIdProductAbstractStep.php` | lookup step with cache |
| `src/Pyz/Zed/ProductBadgeDataImport/Business/Writer/ProductBadge/ProductBadgeWriterStep.php` | writer step (extends `PublishAwareStep`, collects the publish event per product abstract) |
| `src/Pyz/Zed/ProductBadgeDataImport/Communication/Plugin/DataImport/ProductBadgeDataImportPlugin.php` | plugin (`import()`, `getImportType()`) |
| `data/import/common/common/product_badge.csv` | sample data (SKUs `cable-hdmi-1`, `cable-vga-1` exist in `product_abstract.csv`) |

## Placeholders / customization
- `ProductBadge` -> your own name (module, classes, `product-badge`, `product_badge.csv`).
- The entity `Orm\Zed\ProductBadge\Persistence\SpyProductBadgeQuery` only comes into existence through the template `transfer-and-propel` (`spy_product_badge`) plus `propel:install`.
- CSV columns: `abstract_sku,badge_key,label`. Further columns: constant in the DataSet interface, access in the step via `$dataSet[...]`.
- No own `*.transfer.xml` needed: `DataImporterConfiguration` and `DataImporterReport` come from the core module `spryker/data-import`.

## Registration (excerpts in existing files)

1. Register the plugin, `src/Pyz/Zed/DataImport/DataImportDependencyProvider.php`, method `getDataImporterPlugins()`:
```php
use Pyz\Zed\ProductBadgeDataImport\Communication\Plugin\DataImport\ProductBadgeDataImportPlugin;
// ...
new ProductBadgeDataImportPlugin(),
```
2. Console `data:import:product-badge` (optional, `data:import product-badge` also works without it), `src/Pyz/Zed/Console/ConsoleDependencyProvider.php`, `getConsoleCommands()` (next to the other `DataImportConsole` entries, approx. lines 239-300):
```php
use Pyz\Zed\ProductBadgeDataImport\ProductBadgeDataImportConfig;
// ...
new DataImportConsole(DataImportConsole::DEFAULT_NAME . static::COMMAND_SEPARATOR . ProductBadgeDataImportConfig::IMPORT_TYPE_PRODUCT_BADGE),
```
3. YAML entry **after** `product-abstract` (order = execution order), `data/import/local/full_EU.yml` (possibly `full_US.yml`, `data/import/production/full_*.yml`, `common/*_import_config_*.yml`):
```yaml
  - data_entity: product-badge
    source: data/import/common/common/product_badge.csv
```
4. Only if the legacy full import without YAML is used: extend `Pyz\Zed\DataImport\DataImportConfig::getFullImportTypes()` with `ProductBadgeDataImportConfig::IMPORT_TYPE_PRODUCT_BADGE` (not needed in the release with YAML; model `StockAddressDataImportConfig::IMPORT_TYPE_STOCK_ADDRESS` there).

## Commands afterwards
```bash
docker/sdk cli console transfer:generate                # [live verified (202610.0)]
docker/sdk cli console cache:empty-all                  # [live verified (202610.0)]
docker/sdk cli console data:import:dump                 # [live verified (202610.0)] product-badge appears in the table (plugin `ProductBadgeDataImportPlugin`)
docker/sdk cli console data:import:product-badge -t     # [live verified (202610.0)] exit 0, "Importable DataSets: 2, Imported DataSets: 2, Import status: Successful"; only this type, aborts with a stack trace on the first error
docker/sdk cli console data:import --config=data/import/local/full_EU.yml   # [live verified (202610.0)] exit 0, product-badge runs after product-abstract, "Overall Import status: Successful"
```
Confirmed live: a second run is an idempotent upsert (2 DataSets, no duplicates, unique key `fk_product_abstract`+`badge_key`); after the import there are `spy_product_badge_storage` rows and Redis keys `kv:product_badge:4` and `:5` (the publish runs via `PublishAwareStep` and the `DataImportPublisherPlugin` after-hook; the scheduler's workers process the queues).

**Publish/Storage:** the project `DataImportDependencyProvider` registers `DataImportEventBehaviorPlugin` as a before-hook (disables entity events during import, `src/Pyz/Zed/DataImport/DataImportDependencyProvider.php`, `getDataImportBeforeImportHookPlugins()`). The entity event behavior of `spy_product_badge` therefore triggers nothing during import, and without further measures the badges would never reach Storage. The writer step therefore extends `PublishAwareStep` and calls `addPublishEvents(ProductBadgeStorageConfig::PRODUCT_BADGE_PUBLISH, $idProductAbstract)` after saving; `DataImportPublisherPlugin` (after-hook, also in the Pyz provider) triggers the collected events in bulk after the import. The publish event `ProductBadge.product_badge.publish` is processed by the writer from the template `publisher-and-synchronization` (event ID = product abstract ID).

**Dependency:** the step uses `Pyz\Shared\ProductBadgeStorage\ProductBadgeStorageConfig` (constant `PRODUCT_BADGE_PUBLISH`) from the template `publisher-and-synchronization`. Without this template, remove the line `addPublishEvents(...)` together with the `use` (then no storage publish), or define the constant in your own module. Background on events/sync: [07-publish-sync](../../references/07-publish-sync.md).

## Source used (model)
- `vendor/spryker/stock-address-data-import/src/Spryker/Zed/StockAddressDataImport/` (Config, Factory, Facade, plugin, writer step, DataSet interface)
- `vendor/spryker/price-product-data-import/.../Business/Model/Step/AbstractSkuToIdProductAbstractStep.php` (lookup step with cache)
- Basis: `Spryker\Zed\DataImport\DataImportConfig::buildImporterConfiguration()`, `DataImportBusinessFactory::getCsvDataImporterFromConfig()` / `createTransactionAwareDataSetStepBroker()`

## Verification status
Walked through in shop 202610.0 (module installed, registration 1 to 3 as above, import and publish executed through to Redis; `code:sniff:style` exit 0, PHPStan level 6 without findings). Depends on the templates `transfer-and-propel` (`SpyProductBadgeQuery`) and `publisher-and-synchronization` (`ProductBadgeStorageConfig`). `[live verified (202610.0)]`
