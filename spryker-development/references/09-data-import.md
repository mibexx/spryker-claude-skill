# Data Import (Recherche T-09)

> Status: Release 202610.0 (spryker/data-import 1.34.0, data-import-extension 1.2.0). Code gegen `vendor/` und `src/Pyz` verifiziert `[code-verifiziert, Doku nicht abgeglichen]`; Doku-Seiten teils ausgewertet, mehrere sind veraltet (siehe Fallstricke). Lesende Befehle (`data:import --help`, `data:import:dump`) wurden live ausgeführt, **kein Import wurde ausgeführt**. Der Beispiel-Importer (Template) ist nicht gelaufen; er wurde in einer Sandbox mit php -l, phpcs und phpstan geprüft (202610.0).

## Zweck
Stammdaten (CSV) per Konsole in die DB laden: Aufbau eines Importers, YAML-Steuerung pro Region/Store, eigenen Importer anlegen und registrieren, Reihenfolge, Queue-Importer, Performance. Publish/Sync der importierten Daten: [07-publish-sync](07-publish-sync.md). Konsolen allgemein: [22-console-cron-queue](22-console-cron-queue.md).

## Konzepte

**Pipeline** Reader -> DataSetStepBroker (Steps) -> Writer/Persistenz, gebaut in einer `*DataImportBusinessFactory` (Basis `Spryker\Zed\DataImport\Business\DataImportBusinessFactory`).

| Baustein | Klasse (unter `vendor/spryker/data-import/src/Spryker/Zed/DataImport/`) | Rolle |
|---|---|---|
| Importer | `Business/Model/DataImporter.php` | iteriert den Reader, führt Steps je DataSet aus, sammelt Fehler im `DataImporterReportTransfer` (max. `getDefaultAmountOfErrors()`, Default 50) |
| Importer mit Writer | `Business/Model/DataImporterDataSetWriterAware.php` | zusätzlich `DataSetWriterPluginInterface::write()` je DataSet und `flush()` am Ende (Batch) |
| Queue-Importer | `Business/DataImporter/Queue/QueueDataImporter.php` | liest aus Queue statt CSV |
| Reader | `Business/Model/DataReader/CsvReader/CsvReader.php`; `Business/DataReader/CsvReader/CsvAdapterReader.php` (Flysystem, bei `DataImportConstants::OTHER_SOURCE_ENABLED`) | je CSV-Zeile ein `DataSetInterface`, Schlüssel = Header |
| DataSet | `Business/Model/DataSet/DataSet.php` (ArrayObject) | Zugriff auf fehlenden Schlüssel wirft `DataKeyNotFoundInDataSetException`; Importer hängt `CONTEXT` an |
| Step | `DataImportStepInterface::execute(DataSetInterface $dataSet)` | eine Transformations- oder Schreibstufe; optional `DataImportStepBeforeExecuteInterface`, `DataImportStepAfterExecuteInterface` |
| Step Broker | `DataSetStepBroker`, `DataSetStepBrokerTransactionAware` (Propel-Transaktion, Bulk, Rollback, leert Instance-Pools), `DataSetStepBrokerElasticBatchTransactionAware` | Steps in `addStep()`-Reihenfolge |
| Standard-Steps | `AddLocalesStep`, `AddStoresStep`, `LocalizedAttributesExtractorStep`, `RenameDataSetKeysStep`, `PublishAwareStep`, `TouchAwareStep` | über `create*Step()` der Basis-Factory |
| Writer-Plugins | `vendor/spryker/data-import-extension/.../Dependency/Plugin/`: `DataSetWriterPluginInterface` (`write`, `flush`), `DataSetWriterPluginApplicableAwareInterface`, `DataSetItemWriterPluginInterface` | Batch-Schreiben (Pyz: `ProductAbstractPropelWriterPlugin` u. a.) |
| Publish | `PublishAwareStep` + `DataImporterPublisher` | sammelt Events, löst sie nach dem Import gebündelt aus |

**Zwei Wege, einen Importer einzuhängen** (beide existieren im Release):
1. **Plugin im Modul** (Standard, für neue Importer): `<X>DataImportPlugin implements DataImportPluginInterface` (`import()`, `getImportType()`), registriert in `Pyz\Zed\DataImport\DataImportDependencyProvider::getDataImporterPlugins()`. Vorbild `Spryker\Zed\StockAddressDataImport\...\StockAddressDataImportPlugin`.
2. **Pyz-Factory** `Pyz\Zed\DataImport\Business\DataImportBusinessFactory::getDataImporterByType()` (großer `switch` über `DataImportConfig::IMPORT_TYPE_*`, z. B. `currency`, `glossary`, `product-abstract`): Bestandsweg des Demo-Shops, für neue Importer nicht nachbauen.
`DataImporterCollectionCollector` kombiniert pro YAML-Action beide Quellen.

**Verzeichnisse `data/import/`**: `common/common/` (store-/regionsunabhängige CSV), `common/EU|US/` (regionsspezifisch: `store.csv`, `store_context.csv`), `common/DE|AT|US/` (storespezifisch: `currency_store.csv`, `product_price.csv`, ...), `common/*_import_config_{EU,US}.yml` (thematische YAML-Gruppen), `local/{full,store}_{EU,US}.yml` (Einstieg lokal), `production/full_{EU,US}.yml`. `common/{DE,AT,US}/<STORE>.yml` werden von keinem Install-Recipe referenziert `[Annahme]`: nur per `--config` nutzbar.

**YAML-Format** (`DataImportConfigurationActionTransfer`):
```yaml
version: 0
actions:
  - data_entity: currency            # = getImportType()
    source: data/import/common/common/currency.csv   # relativ zum Projektroot
  - data_entity: currency-store      # derselbe Typ mehrfach mit anderer Quelle möglich
    source: data/import/common/DE/currency_store.csv
```
Weitere Action-Felder: `file_system`, `context`. Parser liest nur das erste YAML-Dokument, unbekannte Schlüssel werden ignoriert. `FileResolver` probiert `source` relativ zum CWD (Projektroot im Container), dann `<data/import/>/<source>`; sonst `FileResolverFileNotFoundException` mit geprüften Pfaden.

**Default-Konfiguration:** `Pyz\Zed\DataImport\DataImportConfig::getDefaultYamlConfigPath()` = `data/import/local/full_<APPLICATION_REGION>.yml` (Fallback `EU`); gilt ohne `--config` auch für `data:import:<typ>` und `data:import:dump`. Install-Recipes: `config/install/docker.yml` -> `data:import --config=data/import/local/full_${SPRYKER_REGION}.yml`.

**Reihenfolge:** mit YAML exakt die `actions` von oben nach unten. `data:import <typ>` filtert die Actions auf `data_entity === <typ>` und führt alle Treffer aus; kein Treffer -> Meldung `Requested import type "X" was not found in <config>.` bei Erfolgsstatus. Abhängigkeiten im Demo-Shop (aus `full_EU.yml`): `store`, `currency`, `currency-store`, `country-store`, `locale-store`, `customer`, `glossary`, `tax`, `shipment`, `stock`, `stock-address`, ... -> Catalog (`category`, `product-abstract`, `product-concrete`, `product-price`, ...) -> Merchandising -> Navigation -> Content -> B2B. Concrete setzt Abstract voraus, `*-store` setzt `store` voraus, Preise setzen Währung/Store voraus.

**Queue-Importer** (Infrastruktur vorhanden, im Projekt ungenutzt): zweistufig CSV -> Queue (`QueueWriter`, `AbstractQueueWriterPlugin`, Gruppe `QUEUE_WRITERS`) und Queue -> DB (`QueueDataImporter`, Gruppe `QUEUE_READERS`, parallelisierbar). Chunk-Größen `DATA_IMPORT:QUEUE_READER_CHUNK_SIZE` / `..._WRITER_CHUNK_SIZE` (Default 100). `DataImportFacade::writeDataSetItemsToQueue(string $queueName, array $dataSetItems)`. `--group=QUEUE_WRITERS` ist nicht mit einem Importer-Argument kombinierbar; `QUEUE_READERS` nicht empfohlen (Race Conditions). Aufbau wäre Eigenarbeit, Queue-Betrieb: [22-console-cron-queue](22-console-cron-queue.md).

## Anleitung

**Eigenen Importer anlegen (Plugin-Weg)** - fertiges Template: [templates/data-import-step](../templates/data-import-step/README.md).
1. Modul `Pyz\Zed\<X>DataImport`: Config (extends `Spryker\Zed\DataImport\DataImportConfig`, Konstante `IMPORT_TYPE_*` kebab-case, Methode mit `buildImporterConfiguration($file, $type)`), leerer DependencyProvider (extends `DataImportDependencyProvider`, nötig für die Basis-Factory), BusinessFactory (extends `DataImportBusinessFactory`), Facade, Plugin.
2. Factory: `getCsvDataImporterFromConfig($config)` -> `createTransactionAwareDataSetStepBroker($bulkSize = null)` -> `addStep(...)` je Step (Lookups zuerst, Writer zuletzt) -> `addDataSetStepBroker()`.
3. Facade: `import<X>(?DataImporterConfigurationTransfer)` -> `getFactory()->get<X>DataImporter($transfer)->import($transfer)`.
4. Plugin: `import()` delegiert an die Facade, `getImportType()` liefert die Konstante.
5. Registrieren: Plugin in `DataImportDependencyProvider::getDataImporterPlugins()`; optional Konsole `new DataImportConsole(DataImportConsole::DEFAULT_NAME . static::COMMAND_SEPARATOR . <Typ>)` in `Pyz\Zed\Console\ConsoleDependencyProvider::getConsoleCommands()` (macht `data:import:<typ>` verfügbar; ohne Eintrag geht `data:import <typ>`). Hinweis: `StockAddress` ist im Release **nicht** als Kurzbefehl registriert, läuft aber per YAML.
6. `data_entity`-Eintrag in `data/import/local/full_*.yml` (und `production/full_*.yml`, `common/*_import_config_*.yml`) an der richtigen Stelle der Reihenfolge. Ohne Eintrag läuft der Importer beim Full-Import nicht.
7. Nur für den Legacy-Full-Import ohne YAML: `Pyz\Zed\DataImport\DataImportConfig::getFullImportTypes()` ergänzen (Core: `[]`; Pyz ergänzt `StockAddress`). Bei nicht leerer Liste führt der Legacy-Lauf nur diese Typen aus.
8. `transfer:generate` (falls Transfers), `data:import:dump`, Testlauf mit `-t`.

**Performance**
- `createTransactionAwareDataSetStepBroker($bulkSize)`: Commit alle `$bulkSize` DataSets, Instance-Pools werden geleert (alle 1000, `MEMORY_CLEAR_THRESHOLD`). Pyz-Vorbild `GlossaryWriterStep::BULK_SIZE`.
- Batch-Writer (`DataImporterDataSetWriterAware` + `DataSetWriterPluginInterface`) statt Einzel-ORM; Lookups (Key -> ID) einmal laden/cachen, keine schweren Facade-Aufrufe pro Zeile; bei Massendaten PDO/CTE statt Propel.
- Events aus: Hook-Plugins `DataImportEventBehaviorPlugin` (before, `disableEvent()`) und `DataImportPublisherPlugin` (after, `publish()`), beide im Pyz-`DataImportDependencyProvider` (`getDataImportBeforeImportHookPlugins()` / `...AfterImportHookPlugins()`). `DataImporterPublisher` bündelt Events (`PUBLISHER_TRIGGER_CHUNK_SIZE`, Default 20000).
- Bulk-Modus: `DataImportConstants::IS_BULK_MODE_ENABLED`, `BULK_MODE_GRADUALITY_FACTOR` (5), `BULK_MODE_MEMORY_THRESHOLD_PERCENT` (30); in `config/Shared/` nicht gesetzt (Default `false`).
- Lesebatch Pyz: `getReadCollectionBatchSize()` = 500. `--progress-bar`/`-pb` (ab 1.32.0) nur zur Diagnose (Debug-Overhead).

**Konsole** `[live verifiziert (202610.0)]`: `data:import`, `data:import:<typ>`, `data:import:dump`, `--config` (z. B. `content_management_import_config_EU.yml`, 16 Actions), `-o/-l` und `-t` wurden ausgeführt (EU; Imports sind idempotente Upserts, Volllauf `full_EU.yml` siehe Protokoll `live-verification.md`); Prefix `docker/sdk cli console`:

| Befehl | Wirkung |
|---|---|
| `data:import` | Full-Import mit Default-YAML |
| `data:import --config=data/import/local/full_US.yml` (`-c`) | beliebige YAML |
| `data:import <typ>` / `data:import:<typ>` | nur Actions dieses `data_entity` |
| `data:import:dump` | listet Importer **der Default-YAML** (nicht enthaltene wie `combined-product-*` fehlen) |
| `-o <n> -l <n>` | Offset/Limit |
| `-t` / `--throw-exception` | Abbruch beim ersten Fehler mit Stacktrace |
| `-d -e -s -r` | CSV-Delimiter, Enclosure, Escape, `--has-header=0` |
| `-g <GRUPPE>` | Import-Gruppe (nur ohne Importer-Argument); `-g QUEUE_WRITERS` endet im Projekt mit `InvalidImportGroupException` (Exit 255), weil kein Importer in der Gruppe liegt |
| `-f <csv>` | **wirkungslos**, sowohl mit `--config` als auch mit Importer-Argument und Default-YAML: `source` aus der YAML gewinnt `[live verifiziert (202610.0)]` ("Import source:" zeigt die YAML-Quelle). Für eine andere CSV die YAML ändern oder eigene YAML mit `--config` |

Exit-Code `1`, wenn ein Teilimporter fehlschlägt (Code `DataImportConsole`, Install-Recipes brechen dann ab). Eine fehlende CSV-Datei dagegen wirft `FileResolverFileNotFoundException` (Exit 255, mit und ohne `-t`, `live verifiziert`).

## Beispiele

Vollständiges lauffähig gedachtes Modul (Config, Interface, Steps, Plugin, CSV, YAML): [templates/data-import-step](../templates/data-import-step/README.md). Kern-Auszüge, am Core-Importer `StockAddressDataImport` und am Template verifiziert:

```php
// src/Pyz/Zed/ProductBadgeDataImport/Business/ProductBadgeDataImportBusinessFactory.php
public function getProductBadgeDataImporter(?DataImporterConfigurationTransfer $dataImporterConfigurationTransfer = null): DataImporterInterface
{
    $dataImporter = $this->getCsvDataImporterFromConfig(
        $dataImporterConfigurationTransfer ?: $this->getConfig()->getProductBadgeDataImporterConfiguration(),
    );

    $dataSetStepBroker = $this->createTransactionAwareDataSetStepBroker();
    $dataSetStepBroker->addStep($this->createAbstractSkuToIdProductAbstractStep());   // Lookup
    $dataSetStepBroker->addStep($this->createProductBadgeWriterStep());               // Writer zuletzt

    $dataImporter->addDataSetStepBroker($dataSetStepBroker);

    return $dataImporter;
}
```

```php
// Writer-Step: idempotent, Pflichtspalten validieren, sprechende Exception
$productBadgeEntity = SpyProductBadgeQuery::create()
    ->filterByFkProductAbstract($dataSet[ProductBadgeDataSetInterface::ID_PRODUCT_ABSTRACT])
    ->filterByBadgeKey($dataSet[ProductBadgeDataSetInterface::COLUMN_BADGE_KEY])
    ->findOneOrCreate();
$productBadgeEntity->setLabel($dataSet[ProductBadgeDataSetInterface::COLUMN_LABEL])->save();

// Writer-Step erbt PublishAwareStep: Entity-Events sind beim Import aus (DataImportEventBehaviorPlugin),
// das Publish-Event wird gesammelt und nach dem Import vom DataImportPublisherPlugin ausgelöst
$this->addPublishEvents(ProductBadgeStorageConfig::PRODUCT_BADGE_PUBLISH, $dataSet[ProductBadgeDataSetInterface::ID_PRODUCT_ABSTRACT]);
```
Der Template-Writer `class ProductBadgeWriterStep extends PublishAwareStep implements DataImportStepInterface` ist **nicht optional**: ohne `addPublishEvents()` kommen Daten einer Entity mit Event-/Publish-Behavior beim Import nie in Storage.

```yaml
# data/import/local/full_EU.yml (nach product-abstract)
  - data_entity: product-badge
    source: data/import/common/common/product_badge.csv
```

Publish-Step (Events, Vorbild `vendor/spryker/cms-page-data-import/.../CmsPagePublishStep.php`):
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
`PublishAwareStep::afterExecute()` übergibt Events an `DataImporterPublisher`; ausgelöst werden sie durch `DataImportPublisherPlugin::afterImport()` ([07-publish-sync](07-publish-sync.md)).

## Fallstricke

| Symptom | Ursache / Lösung |
|---|---|
| `Requested import type "x" was not found in <config>.` (Status trotzdem erfolgreich) | `data_entity` fehlt in Default-/`--config`-YAML; Plugin allein genügt nicht |
| Plugin registriert, läuft aber nicht im Full-Import | YAML-Eintrag fehlt; (Legacy) `getFullImportTypes()` nicht ergänzt |
| `Data importer not found for "x" importer type.` | nur im Legacy-Pfad ohne YAML; Typ nicht als Plugin registriert |
| `Could not find file "...". Checked path(s): ...` | `source` falsch (relativ zum Projektroot, nicht zur YAML) |
| `The key "x" was not found in data set. Available keys: ...` | CSV-Header weicht ab (Schreibweise, BOM, Delimiter) oder Step setzt den Schlüssel erst später; Step-Reihenfolge prüfen |
| `No import group (except "FULL") can be used when an import type is specified` | `--group` mit Importer-Argument |
| zwei Plugins mit gleichem `getImportType()` | das spätere überschreibt das frühere |
| Daten importiert, Storefront leer | `DataImportEventBehaviorPlugin` schaltet Entity-Events beim Import ab; der Writer-Step muss `PublishAwareStep` erben und `addPublishEvents()` rufen (sonst nie Storage); außerdem Publish-Hook lief nicht / Queue nicht abgearbeitet: [07-publish-sync](07-publish-sync.md) |
| Fehlerliste endet bei 50 | `ALERT: Amount of errors to log has been reached limit`: `DataImportConstants::DEFAULT_AMOUNT_OF_ERRORS` erhöhen oder `-t` |
| Teil-Rollbacks, schwankende Zähler | `DataSetStepBrokerTransactionAware` rollt die ganze Bulk-Gruppe zurück; Bulk klein halten, Fehlerzeile beheben |

- Neue Importer als eigenes Projektmodul mit Plugin, nicht im `switch` der Pyz-Factory ergänzen. Core-Importer und deren CSV (`vendor/.../data/import/*.csv`) nie ändern (Upgrader, [28-upgradability](28-upgradability.md)); Projektdaten gehören nach `data/import/`.
- Steps greifen im Release direkt per Propel-Query zu (erlaubt in Importern); für Fachlogik anderer Module Facade nur nutzen, wenn ohne DB-Abfrage pro Zeile.
- Importer idempotent (`findOneOrCreate()` auf natürlichem Schlüssel): Demo-Install läuft mehrfach.
- Struktur der Demo-Shop-Verzeichnisse beibehalten (`${SPRYKER_REGION}` in Install-Recipes).
- **Deprecated/veraltet:** Import ohne YAML (`DataImportExecutor::executeByImporterType()` `@deprecated`, Console warnt; im Pyz-Projekt irrelevant, da `getDefaultYamlConfigPath()` gesetzt). Tippfehler-Variante `BULK_MODE_MEMORY_THESHOLD_PERCENT` / `getBulkWriteMemoryThesoldPercent()` nur BC, `...THRESHOLD...` nutzen.
- **Doku-Abweichungen:** `execution-order-of-data-importers` sagt "store is hardcoded", im Release ist `store` ein regulärer Importer (`StoreDataImportPlugin`, erste Action in `full_EU.yml`). Queue-Importer-Doku (2021) überschreibt `Pyz\...\DataImportBusinessFactory::getImporter()` und nennt `QueueWriterConfigurationTransfer`; im Release existiert der Override nicht, der Transfer heißt `DataImporterQueueWriterConfigurationTransfer`. YAML-Doku (2021) nennt `catalog_setup_import_config.yml`, im Release `*_import_config_{EU,US}.yml`.
- `file_system`/Flysystem-Quellen `[Annahme]` (nicht ausgeführt); das Verhalten von `-f` im YAML-Modus ist geklärt (wirkungslos, s. Tabelle).

## Checkliste
- [ ] Modul `Pyz\Zed\<X>DataImport` mit Config, leerem DependencyProvider, Factory, Facade, Plugin
- [ ] Steps: Lookups zuerst, Writer zuletzt; Pflichtspalten validiert; idempotent
- [ ] Plugin in `getDataImporterPlugins()`; ggf. Konsole in `ConsoleDependencyProvider`
- [ ] `data_entity` in `full_*.yml` (Region EU und US), Reihenfolge nach Abhängigkeiten
- [ ] CSV unter `data/import/common/...`, `source` relativ zum Projektroot
- [ ] `data:import:dump` zeigt den Typ, Testlauf mit `-t`
- [ ] Events/Publish geklärt: Writer erbt `PublishAwareStep` und ruft `addPublishEvents()` (Entity-Events sind beim Import aus), große Mengen: Bulk/Batch
- [ ] Nichts in `vendor/` geändert

## Quellen
Doku (Basis `https://docs.spryker.com/docs/dg/dev/data-import/latest/`): `data-import.html`, `creating-data-importers.html`, `data-importers-implementation.html`, `importing-data-with-a-configuration-file.html`, `execution-order-of-data-importers.html`, `importing-data-with-the-queue-data-importer.html`, `data-import-optimization-guidelines.html`.

Code: `vendor/spryker/data-import/src/Spryker/Zed/DataImport/` (`DataImportConfig`, `Business/DataImportBusinessFactory`, `Business/DataImporter/DataImporterCollectionCollector`, `Business/Model/{DataImporter,DataImporterDataSetWriterAware}`, `Business/Model/DataSet/*`, `Business/Model/DataImportStep/*`, `Communication/Console/{DataImportConsole,Executor/DataImportExecutor}`, `Communication/Plugin/*`), `vendor/spryker/data-import-extension/`, `vendor/spryker/stock-address-data-import/`, `vendor/spryker/price-product-data-import/.../AbstractSkuToIdProductAbstractStep.php`, `src/Pyz/Zed/DataImport/{DataImportConfig,DataImportDependencyProvider}.php`, `src/Pyz/Zed/Console/ConsoleDependencyProvider.php`, `data/import/**`, `config/install/*.yml`.

Nachbarn: [04-transfer](04-transfer.md), [05-propel-persistenz](05-propel-persistenz.md), [02-projektmodul](02-projektmodul.md), [03-core-erweitern](03-core-erweitern.md), [19-multi-store-i18n](19-multi-store-i18n.md). Template: [data-import-step](../templates/data-import-step/README.md).
