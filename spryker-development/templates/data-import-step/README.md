# Template: data-import-step (DataImporter für ProductBadge)

## Zweck
Eigener CSV-Importer als Projektmodul `Pyz\Zed\ProductBadgeDataImport` im Plugin-Weg (Import-Typ `product-badge`): Config-Konstante, DataSet-Interface, Lookup-Step (`abstract_sku` -> `id_product_abstract`), Writer-Step (idempotenter Upsert in `spy_product_badge`), `DataImportPluginInterface`-Plugin. Hintergrund: [09-data-import](../../references/09-data-import.md).

## Dateiliste (Zielpfad unter Projektroot)
| Datei | Rolle |
|---|---|
| `src/Pyz/Zed/ProductBadgeDataImport/ProductBadgeDataImportConfig.php` | `IMPORT_TYPE_PRODUCT_BADGE`, Fallback-Importerkonfiguration |
| `src/Pyz/Zed/ProductBadgeDataImport/ProductBadgeDataImportDependencyProvider.php` | leer, erbt vom Core (nötig für die Basis-Factory) |
| `src/Pyz/Zed/ProductBadgeDataImport/Business/ProductBadgeDataImportBusinessFactory.php` | Reader + Step-Broker + Steps |
| `src/Pyz/Zed/ProductBadgeDataImport/Business/ProductBadgeDataImportFacade(Interface).php` | `importProductBadge()` |
| `src/Pyz/Zed/ProductBadgeDataImport/Business/Writer/ProductBadge/DataSet/ProductBadgeDataSetInterface.php` | CSV-Spalten und Step-Schlüssel |
| `src/Pyz/Zed/ProductBadgeDataImport/Business/Writer/ProductBadge/AbstractSkuToIdProductAbstractStep.php` | Lookup-Step mit Cache |
| `src/Pyz/Zed/ProductBadgeDataImport/Business/Writer/ProductBadge/ProductBadgeWriterStep.php` | Writer-Step (erbt `PublishAwareStep`, sammelt das Publish-Event je Product Abstract) |
| `src/Pyz/Zed/ProductBadgeDataImport/Communication/Plugin/DataImport/ProductBadgeDataImportPlugin.php` | Plugin (`import()`, `getImportType()`) |
| `data/import/common/common/product_badge.csv` | Beispieldaten (SKUs `cable-hdmi-1`, `cable-vga-1` existieren in `product_abstract.csv`) |

## Platzhalter / Anpassung
- `ProductBadge` -> eigener Name (Modul, Klassen, `product-badge`, `product_badge.csv`).
- Die Entity `Orm\Zed\ProductBadge\Persistence\SpyProductBadgeQuery` entsteht erst durch das Template `transfer-and-propel` (`spy_product_badge`) plus `propel:install`.
- CSV-Spalten: `abstract_sku,badge_key,label`. Weitere Spalten: Konstante im DataSet-Interface, Zugriff im Step über `$dataSet[...]`.
- Keine eigene `*.transfer.xml` nötig: `DataImporterConfiguration` und `DataImporterReport` kommen aus dem Core-Modul `spryker/data-import`.

## Registrierung (Ausschnitte in bestehenden Dateien)

1. Plugin eintragen, `src/Pyz/Zed/DataImport/DataImportDependencyProvider.php`, Methode `getDataImporterPlugins()`:
```php
use Pyz\Zed\ProductBadgeDataImport\Communication\Plugin\DataImport\ProductBadgeDataImportPlugin;
// ...
new ProductBadgeDataImportPlugin(),
```
2. Konsole `data:import:product-badge` (optional, `data:import product-badge` funktioniert auch ohne), `src/Pyz/Zed/Console/ConsoleDependencyProvider.php`, `getConsoleCommands()` (neben den anderen `DataImportConsole`-Einträgen, ca. Zeile 239-300):
```php
use Pyz\Zed\ProductBadgeDataImport\ProductBadgeDataImportConfig;
// ...
new DataImportConsole(DataImportConsole::DEFAULT_NAME . static::COMMAND_SEPARATOR . ProductBadgeDataImportConfig::IMPORT_TYPE_PRODUCT_BADGE),
```
3. YAML-Eintrag **nach** `product-abstract` (Reihenfolge = Ausführungsreihenfolge), `data/import/local/full_EU.yml` (ggf. `full_US.yml`, `data/import/production/full_*.yml`, `common/*_import_config_*.yml`):
```yaml
  - data_entity: product-badge
    source: data/import/common/common/product_badge.csv
```
4. Nur falls der Legacy-Full-Import ohne YAML genutzt wird: `Pyz\Zed\DataImport\DataImportConfig::getFullImportTypes()` um `ProductBadgeDataImportConfig::IMPORT_TYPE_PRODUCT_BADGE` ergänzen (im Release mit YAML nicht nötig; Vorbild `StockAddressDataImportConfig::IMPORT_TYPE_STOCK_ADDRESS` dort).

## Befehle danach
```bash
docker/sdk cli console transfer:generate                # [live verifiziert (202610.0)]
docker/sdk cli console cache:empty-all                  # [live verifiziert (202610.0)]
docker/sdk cli console data:import:dump                 # [live verifiziert (202610.0)] product-badge erscheint in der Tabelle (Plugin `ProductBadgeDataImportPlugin`)
docker/sdk cli console data:import:product-badge -t     # [live verifiziert (202610.0)] Exit 0, "Importable DataSets: 2, Imported DataSets: 2, Import status: Successful"; nur dieser Typ, Abbruch mit Stacktrace beim ersten Fehler
docker/sdk cli console data:import --config=data/import/local/full_EU.yml   # [live verifiziert (202610.0)] Exit 0, product-badge läuft nach product-abstract, "Overall Import status: Successful"
```
Live bestätigt: zweiter Lauf ist ein idempotenter Upsert (2 DataSets, keine Duplikate, Unique-Key `fk_product_abstract`+`badge_key`); nach dem Import stehen `spy_product_badge_storage`-Zeilen und Redis-Keys `kv:product_badge:4` und `:5` (der Publish läuft über `PublishAwareStep` und den `DataImportPublisherPlugin`-After-Hook, Worker des Schedulers verarbeiten die Queues).

**Publish/Storage:** Der Projekt-`DataImportDependencyProvider` registriert `DataImportEventBehaviorPlugin` als Before-Hook (schaltet Entity-Events beim Import ab, `src/Pyz/Zed/DataImport/DataImportDependencyProvider.php`, `getDataImportBeforeImportHookPlugins()`). Das Entity-Event-Behavior von `spy_product_badge` löst beim Import deshalb nichts aus, und ohne weitere Maßnahme kämen die Badges nie nach Storage. Der Writer-Step erbt darum von `PublishAwareStep` und ruft nach dem Speichern `addPublishEvents(ProductBadgeStorageConfig::PRODUCT_BADGE_PUBLISH, $idProductAbstract)` auf; `DataImportPublisherPlugin` (After-Hook, ebenfalls im Pyz-Provider) löst die gesammelten Events nach dem Import gebündelt aus. Das Publish-Event `ProductBadge.product_badge.publish` verarbeitet der Writer aus Template `publisher-and-synchronization` (Event-ID = Product-Abstract-ID).

**Abhängigkeit:** Der Step nutzt `Pyz\Shared\ProductBadgeStorage\ProductBadgeStorageConfig` (Konstante `PRODUCT_BADGE_PUBLISH`) aus dem Template `publisher-and-synchronization`. Ohne dieses Template die Zeile `addPublishEvents(...)` samt `use` entfernen (dann kein Storage-Publish), oder die Konstante im eigenen Modul definieren. Hintergrund Events/Sync: [07-publish-sync](../../references/07-publish-sync.md).

## Verwendete Quelle (Vorbild)
- `vendor/spryker/stock-address-data-import/src/Spryker/Zed/StockAddressDataImport/` (Config, Factory, Facade, Plugin, Writer-Step, DataSet-Interface)
- `vendor/spryker/price-product-data-import/.../Business/Model/Step/AbstractSkuToIdProductAbstractStep.php` (Lookup-Step mit Cache)
- Basis: `Spryker\Zed\DataImport\DataImportConfig::buildImporterConfiguration()`, `DataImportBusinessFactory::getCsvDataImporterFromConfig()` / `createTransactionAwareDataSetStepBroker()`

## Verifikationsstatus
Durchgespielt im Shop 202610.0 (Modul installiert, Registrierung 1 bis 3 wie oben, Import und Publish bis Redis ausgeführt; `code:sniff:style` Exit 0, PHPStan Level 6 ohne Befund). Abhängig von den Templates `transfer-and-propel` (`SpyProductBadgeQuery`) und `publisher-and-synchronization` (`ProductBadgeStorageConfig`). `[live verifiziert (202610.0)]`
