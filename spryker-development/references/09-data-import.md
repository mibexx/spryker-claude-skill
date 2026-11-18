# Data Import

> Status: Release 202610.0 (spryker/data-import 1.34.0, data-import-extension 1.2.0). Code verified against `vendor/` and `src/Pyz` `[code-verified, docs not cross-checked]`; docs pages partly evaluated, several are outdated (see pitfalls). Read-only commands (`data:import --help`, `data:import:dump`) were executed live, **no import was executed**. The example importer (template) has not been run; it was checked in a sandbox with php -l, phpcs and phpstan (202610.0).

## Purpose
Loading master data (CSV) into the DB via the console: structure of an importer, YAML control per region/store, creating and registering a custom importer, order, queue importer, performance. Publish/Sync of the imported data: [07-publish-sync](07-publish-sync.md). Consoles in general: [22-console-cron-queue](22-console-cron-queue.md).

## Concepts

**Pipeline** Reader -> DataSetStepBroker (steps) -> Writer/persistence, built in a `*DataImportBusinessFactory` (base `Spryker\Zed\DataImport\Business\DataImportBusinessFactory`).

| Building block | Class (under `vendor/spryker/data-import/src/Spryker/Zed/DataImport/`) | Role |
|---|---|---|
| Importer | `Business/Model/DataImporter.php` | iterates over the reader, executes steps per DataSet, collects errors in the `DataImporterReportTransfer` (max. `getDefaultAmountOfErrors()`, default 50) |
| Importer with writer | `Business/Model/DataImporterDataSetWriterAware.php` | additionally `DataSetWriterPluginInterface::write()` per DataSet and `flush()` at the end (batch) |
| Queue importer | `Business/DataImporter/Queue/QueueDataImporter.php` | reads from a queue instead of CSV |
| Reader | `Business/Model/DataReader/CsvReader/CsvReader.php`; `Business/DataReader/CsvReader/CsvAdapterReader.php` (Flysystem, with `DataImportConstants::OTHER_SOURCE_ENABLED`) | one `DataSetInterface` per CSV row, keys = header |
| DataSet | `Business/Model/DataSet/DataSet.php` (ArrayObject) | accessing a missing key throws `DataKeyNotFoundInDataSetException`; importer attaches `CONTEXT` |
| Step | `DataImportStepInterface::execute(DataSetInterface $dataSet)` | one transformation or write stage; optionally `DataImportStepBeforeExecuteInterface`, `DataImportStepAfterExecuteInterface` |
| Step broker | `DataSetStepBroker`, `DataSetStepBrokerTransactionAware` (Propel transaction, bulk, rollback, clears instance pools), `DataSetStepBrokerElasticBatchTransactionAware` | steps in `addStep()` order |
| Standard steps | `AddLocalesStep`, `AddStoresStep`, `LocalizedAttributesExtractorStep`, `RenameDataSetKeysStep`, `PublishAwareStep`, `TouchAwareStep` | via `create*Step()` of the base factory |
| Writer plugins | `vendor/spryker/data-import-extension/.../Dependency/Plugin/`: `DataSetWriterPluginInterface` (`write`, `flush`), `DataSetWriterPluginApplicableAwareInterface`, `DataSetItemWriterPluginInterface` | batch writing (Pyz: `ProductAbstractPropelWriterPlugin` among others) |
| Publish | `PublishAwareStep` + `DataImporterPublisher` | collects events, triggers them in bulk after the import |

**Two ways to hook in an importer** (both exist in the release):
1. **Plugin in the module** (standard, for new importers): `<X>DataImportPlugin implements DataImportPluginInterface` (`import()`, `getImportType()`), registered in `Pyz\Zed\DataImport\DataImportDependencyProvider::getDataImporterPlugins()`. Model: `Spryker\Zed\StockAddressDataImport\...\StockAddressDataImportPlugin`.
2. **Pyz factory** `Pyz\Zed\DataImport\Business\DataImportBusinessFactory::getDataImporterByType()` (large `switch` over `DataImportConfig::IMPORT_TYPE_*`, e.g. `currency`, `glossary`, `product-abstract`): legacy path of the demo shop, do not replicate it for new importers.
`DataImporterCollectionCollector` combines both sources per YAML action.

**Directories `data/import/`**: `common/common/` (store/region-independent CSV), `common/EU|US/` (region-specific: `store.csv`, `store_context.csv`), `common/DE|AT|US/` (store-specific: `currency_store.csv`, `product_price.csv`, ...), `common/*_import_config_{EU,US}.yml` (thematic YAML groups), `local/{full,store}_{EU,US}.yml` (local entry point), `production/full_{EU,US}.yml`. `common/{DE,AT,US}/<STORE>.yml` are not referenced by any install recipe `[Assumption]`: only usable via `--config`.

**YAML format** (`DataImportConfigurationActionTransfer`):
```yaml
version: 0
actions:
  - data_entity: currency            # = getImportType()
    source: data/import/common/common/currency.csv   # relative to the project root
  - data_entity: currency-store      # the same type can appear multiple times with a different source
    source: data/import/common/DE/currency_store.csv
```
Further action fields: `file_system`, `context`. The parser only reads the first YAML document, unknown keys are ignored. `FileResolver` tries `source` relative to the CWD (project root in the container), then `<data/import/>/<source>`; otherwise `FileResolverFileNotFoundException` with the checked paths.

**Default configuration:** `Pyz\Zed\DataImport\DataImportConfig::getDefaultYamlConfigPath()` = `data/import/local/full_<APPLICATION_REGION>.yml` (fallback `EU`); without `--config` it also applies to `data:import:<type>` and `data:import:dump`. Install recipes: `config/install/docker.yml` -> `data:import --config=data/import/local/full_${SPRYKER_REGION}.yml`.

**Order:** with YAML exactly the `actions` from top to bottom. `data:import <type>` filters the actions to `data_entity === <type>` and runs all matches; no match -> message `Requested import type "X" was not found in <config>.` with success status. Dependencies in the demo shop (from `full_EU.yml`): `store`, `currency`, `currency-store`, `country-store`, `locale-store`, `customer`, `glossary`, `tax`, `shipment`, `stock`, `stock-address`, ... -> Catalog (`category`, `product-abstract`, `product-concrete`, `product-price`, ...) -> Merchandising -> Navigation -> Content -> B2B. Concrete requires abstract, `*-store` requires `store`, prices require currency/store.

**Queue importer** (infrastructure present, unused in the project): two-stage CSV -> queue (`QueueWriter`, `AbstractQueueWriterPlugin`, group `QUEUE_WRITERS`) and queue -> DB (`QueueDataImporter`, group `QUEUE_READERS`, parallelizable). Chunk sizes `DATA_IMPORT:QUEUE_READER_CHUNK_SIZE` / `..._WRITER_CHUNK_SIZE` (default 100). `DataImportFacade::writeDataSetItemsToQueue(string $queueName, array $dataSetItems)`. `--group=QUEUE_WRITERS` cannot be combined with an importer argument; `QUEUE_READERS` not recommended (race conditions). Setting it up would be custom work; queue operation: [22-console-cron-queue](22-console-cron-queue.md).

## Instructions

**Creating a custom importer (plugin path)** - ready-made template: [templates/data-import-step](../templates/data-import-step/README.md).
1. Module `Pyz\Zed\<X>DataImport`: Config (extends `Spryker\Zed\DataImport\DataImportConfig`, constant `IMPORT_TYPE_*` in kebab-case, method with `buildImporterConfiguration($file, $type)`), empty DependencyProvider (extends `DataImportDependencyProvider`, required for the base factory), BusinessFactory (extends `DataImportBusinessFactory`), Facade, plugin.
2. Factory: `getCsvDataImporterFromConfig($config)` -> `createTransactionAwareDataSetStepBroker($bulkSize = null)` -> `addStep(...)` per step (lookups first, writer last) -> `addDataSetStepBroker()`.
3. Facade: `import<X>(?DataImporterConfigurationTransfer)` -> `getFactory()->get<X>DataImporter($transfer)->import($transfer)`.
4. Plugin: `import()` delegates to the Facade, `getImportType()` returns the constant.
5. Register: plugin in `DataImportDependencyProvider::getDataImporterPlugins()`; optionally the console `new DataImportConsole(DataImportConsole::DEFAULT_NAME . static::COMMAND_SEPARATOR . <Type>)` in `Pyz\Zed\Console\ConsoleDependencyProvider::getConsoleCommands()` (makes `data:import:<type>` available; without the entry `data:import <type>` works). Note: `StockAddress` is **not** registered as a short command in the release, but runs via YAML.
6. `data_entity` entry in `data/import/local/full_*.yml` (and `production/full_*.yml`, `common/*_import_config_*.yml`) at the right position in the order. Without the entry the importer does not run during the full import.
7. Only for the legacy full import without YAML: extend `Pyz\Zed\DataImport\DataImportConfig::getFullImportTypes()` (core: `[]`; Pyz adds `StockAddress`). With a non-empty list the legacy run only executes these types.
8. `transfer:generate` (if transfers), `data:import:dump`, test run with `-t`.

**Performance**
- `createTransactionAwareDataSetStepBroker($bulkSize)`: commit every `$bulkSize` DataSets, instance pools are cleared (every 1000, `MEMORY_CLEAR_THRESHOLD`). Pyz model: `GlossaryWriterStep::BULK_SIZE`.
- Batch writer (`DataImporterDataSetWriterAware` + `DataSetWriterPluginInterface`) instead of single ORM writes; load/cache lookups (key -> ID) once, no heavy Facade calls per row; for mass data PDO/CTE instead of Propel.
- Events off: hook plugins `DataImportEventBehaviorPlugin` (before, `disableEvent()`) and `DataImportPublisherPlugin` (after, `publish()`), both in the Pyz `DataImportDependencyProvider` (`getDataImportBeforeImportHookPlugins()` / `...AfterImportHookPlugins()`). `DataImporterPublisher` bundles events (`PUBLISHER_TRIGGER_CHUNK_SIZE`, default 20000).
- Bulk mode: `DataImportConstants::IS_BULK_MODE_ENABLED`, `BULK_MODE_GRADUALITY_FACTOR` (5), `BULK_MODE_MEMORY_THRESHOLD_PERCENT` (30); not set in `config/Shared/` (default `false`).
- Read batch Pyz: `getReadCollectionBatchSize()` = 500. `--progress-bar`/`-pb` (from 1.32.0) only for diagnostics (debug overhead).

**Console** `[live verified (202610.0)]`: `data:import`, `data:import:<type>`, `data:import:dump`, `--config` (e.g. `content_management_import_config_EU.yml`, 16 actions), `-o/-l` and `-t` were executed (EU; imports are idempotent upserts, full run of `full_EU.yml` verified live in release 202610.0); prefix `docker/sdk cli console`:

| Command | Effect |
|---|---|
| `data:import` | full import with the default YAML |
| `data:import --config=data/import/local/full_US.yml` (`-c`) | any YAML |
| `data:import <type>` / `data:import:<type>` | only actions of this `data_entity` |
| `data:import:dump` | lists importers **of the default YAML** (those not included, such as `combined-product-*`, are missing) |
| `-o <n> -l <n>` | offset/limit |
| `-t` / `--throw-exception` | abort on the first error with stack trace |
| `-d -e -s -r` | CSV delimiter, enclosure, escape, `--has-header=0` |
| `-g <GROUP>` | import group (only without importer argument); `-g QUEUE_WRITERS` ends in the project with `InvalidImportGroupException` (exit 255), because no importer is in the group |
| `-f <csv>` | **has no effect**, both with `--config` and with importer argument and default YAML: `source` from the YAML wins `[live verified (202610.0)]` ("Import source:" shows the YAML source). For a different CSV, change the YAML or use your own YAML with `--config` |

Exit code `1` if a sub-importer fails (code `DataImportConsole`, install recipes then abort). A missing CSV file, in contrast, throws `FileResolverFileNotFoundException` (exit 255, with and without `-t`, `live verified`).

## Examples

Complete module intended to be runnable (Config, interface, steps, plugin, CSV, YAML): [templates/data-import-step](../templates/data-import-step/README.md). Core excerpts, verified against the core importer `StockAddressDataImport` and the template:

```php
// src/Pyz/Zed/ProductBadgeDataImport/Business/ProductBadgeDataImportBusinessFactory.php
public function getProductBadgeDataImporter(?DataImporterConfigurationTransfer $dataImporterConfigurationTransfer = null): DataImporterInterface
{
    $dataImporter = $this->getCsvDataImporterFromConfig(
        $dataImporterConfigurationTransfer ?: $this->getConfig()->getProductBadgeDataImporterConfiguration(),
    );

    $dataSetStepBroker = $this->createTransactionAwareDataSetStepBroker();
    $dataSetStepBroker->addStep($this->createAbstractSkuToIdProductAbstractStep());   // Lookup
    $dataSetStepBroker->addStep($this->createProductBadgeWriterStep());               // writer last

    $dataImporter->addDataSetStepBroker($dataSetStepBroker);

    return $dataImporter;
}
```

```php
// Writer step: idempotent, validate mandatory columns, descriptive exception
$productBadgeEntity = SpyProductBadgeQuery::create()
    ->filterByFkProductAbstract($dataSet[ProductBadgeDataSetInterface::ID_PRODUCT_ABSTRACT])
    ->filterByBadgeKey($dataSet[ProductBadgeDataSetInterface::COLUMN_BADGE_KEY])
    ->findOneOrCreate();
$productBadgeEntity->setLabel($dataSet[ProductBadgeDataSetInterface::COLUMN_LABEL])->save();

// Writer step extends PublishAwareStep: entity events are off during import (DataImportEventBehaviorPlugin),
// the publish event is collected and triggered after the import by the DataImportPublisherPlugin
$this->addPublishEvents(ProductBadgeStorageConfig::PRODUCT_BADGE_PUBLISH, $dataSet[ProductBadgeDataSetInterface::ID_PRODUCT_ABSTRACT]);
```
The template writer `class ProductBadgeWriterStep extends PublishAwareStep implements DataImportStepInterface` is **not optional**: without `addPublishEvents()`, data of an entity with event/publish behavior never reaches storage during import.

```yaml
# data/import/local/full_EU.yml (after product-abstract)
  - data_entity: product-badge
    source: data/import/common/common/product_badge.csv
```

Publish step (events, model `vendor/spryker/cms-page-data-import/.../CmsPagePublishStep.php`):
```php
class CmsPagePublishStep extends PublishAwareStep implements DataImportStepInterface
{
    public function execute(DataSetInterface $dataSet): void
    {
        $cmsVersionTransfer = $this->cmsFacade->publishWithVersion($dataSet[CmsPageStoreDataSet::ID_CMS_PAGE]);
        $this->addPublishEvents(CmsEvents::CMS_VERSION_PUBLISH, $cmsVersionTransfer->getFkCmsPage());
    }
}
```
`PublishAwareStep::afterExecute()` passes events to `DataImporterPublisher`; they are triggered by `DataImportPublisherPlugin::afterImport()` ([07-publish-sync](07-publish-sync.md)).

## Pitfalls

| Symptom | Cause / solution |
|---|---|
| `Requested import type "x" was not found in <config>.` (status still successful) | `data_entity` missing in the default/`--config` YAML; the plugin alone is not enough |
| Plugin registered, but does not run in the full import | YAML entry missing; (legacy) `getFullImportTypes()` not extended |
| `Data importer not found for "x" importer type.` | only in the legacy path without YAML; type not registered as a plugin |
| `Could not find file "...". Checked path(s): ...` | `source` wrong (relative to the project root, not to the YAML) |
| `The key "x" was not found in data set. Available keys: ...` | CSV header differs (spelling, BOM, delimiter) or a step only sets the key later; check step order |
| `No import group (except "FULL") can be used when an import type is specified` | `--group` with importer argument |
| two plugins with the same `getImportType()` | the later one overrides the earlier one |
| Data imported, Storefront empty | `DataImportEventBehaviorPlugin` disables entity events during import; the writer step must extend `PublishAwareStep` and call `addPublishEvents()` (otherwise never in storage); additionally the publish hook did not run / queue not processed: [07-publish-sync](07-publish-sync.md) |
| Error list ends at 50 | `ALERT: Amount of errors to log has been reached limit`: increase `DataImportConstants::DEFAULT_AMOUNT_OF_ERRORS` or use `-t` |
| Partial rollbacks, fluctuating counters | `DataSetStepBrokerTransactionAware` rolls back the whole bulk group; keep bulk small, fix the faulty row |

- Add new importers as a dedicated project module with a plugin, not in the `switch` of the Pyz factory. Never change core importers and their CSV (`vendor/.../data/import/*.csv`) (Upgrader, [28-upgradability](28-upgradability.md)); project data belongs in `data/import/`.
- In the release, steps access data directly via Propel query (allowed in importers); for business logic of other modules only use a Facade if it needs no DB query per row.
- Importers idempotent (`findOneOrCreate()` on a natural key): the demo install runs multiple times.
- Keep the structure of the demo shop directories (`${SPRYKER_REGION}` in install recipes).
- **Deprecated/outdated:** import without YAML (`DataImportExecutor::executeByImporterType()` `@deprecated`, console warns; irrelevant in the Pyz project, since `getDefaultYamlConfigPath()` is set). The typo variant `BULK_MODE_MEMORY_THESHOLD_PERCENT` / `getBulkWriteMemoryThesoldPercent()` only for BC, use `...THRESHOLD...`.
- **Docs deviations:** `execution-order-of-data-importers` says "store is hardcoded", in the release `store` is a regular importer (`StoreDataImportPlugin`, first action in `full_EU.yml`). The queue importer docs (2021) override `Pyz\...\DataImportBusinessFactory::getImporter()` and name `QueueWriterConfigurationTransfer`; in the release the override does not exist, the transfer is called `DataImporterQueueWriterConfigurationTransfer`. The YAML docs (2021) name `catalog_setup_import_config.yml`, in the release `*_import_config_{EU,US}.yml`.
- `file_system`/Flysystem sources `[Assumption]` (not executed); the behavior of `-f` in YAML mode is clarified (no effect, see table).

## Checklist
- [ ] Module `Pyz\Zed\<X>DataImport` with Config, empty DependencyProvider, Factory, Facade, plugin
- [ ] Steps: lookups first, writer last; mandatory columns validated; idempotent
- [ ] Plugin in `getDataImporterPlugins()`; if needed, console in `ConsoleDependencyProvider`
- [ ] `data_entity` in `full_*.yml` (region EU and US), order according to dependencies
- [ ] CSV under `data/import/common/...`, `source` relative to the project root
- [ ] `data:import:dump` shows the type, test run with `-t`
- [ ] Events/publish clarified: writer extends `PublishAwareStep` and calls `addPublishEvents()` (entity events are off during import), large volumes: bulk/batch
- [ ] Nothing changed in `vendor/`

## Sources
Docs (base `https://docs.spryker.com/docs/dg/dev/data-import/latest/`): `data-import.html`, `creating-data-importers.html`, `data-importers-implementation.html`, `importing-data-with-a-configuration-file.html`, `execution-order-of-data-importers.html`, `importing-data-with-the-queue-data-importer.html`, `data-import-optimization-guidelines.html`.

Code: `vendor/spryker/data-import/src/Spryker/Zed/DataImport/` (`DataImportConfig`, `Business/DataImportBusinessFactory`, `Business/DataImporter/DataImporterCollectionCollector`, `Business/Model/{DataImporter,DataImporterDataSetWriterAware}`, `Business/Model/DataSet/*`, `Business/Model/DataImportStep/*`, `Communication/Console/{DataImportConsole,Executor/DataImportExecutor}`, `Communication/Plugin/*`), `vendor/spryker/data-import-extension/`, `vendor/spryker/stock-address-data-import/`, `vendor/spryker/price-product-data-import/.../AbstractSkuToIdProductAbstractStep.php`, `src/Pyz/Zed/DataImport/{DataImportConfig,DataImportDependencyProvider}.php`, `src/Pyz/Zed/Console/ConsoleDependencyProvider.php`, `data/import/**`, `config/install/*.yml`.

Neighbours: [04-transfer](04-transfer.md), [05-propel-persistence](05-propel-persistence.md), [02-project-module](02-project-module.md), [03-extending-the-core](03-extending-the-core.md), [19-multi-store-i18n](19-multi-store-i18n.md). Template: [data-import-step](../templates/data-import-step/README.md).
