# Template publisher-and-synchronization

Schreibseite (Publish & Synchronization) und Leseseite (Client) für das Laufbeispiel **ProductBadge**: Änderung an `spy_product_badge` -> Entity-Event -> Publisher-Plugin -> Writer -> `spy_product_badge_storage` -> Synchronization-Behavior -> Sync-Queue -> Redis -> `ProductBadgeClient`. Hintergrund: [Publish & Sync](../../references/07-publish-sync.md), [Client/Storage](../../references/08-client-storage-search.md). Voraussetzung: Schemas und Transfers aus [transfer-and-propel](../transfer-and-propel/README.md), Quell-Modul aus [zed-module](../zed-module/README.md).

## Festgelegte Namen

| Gegenstand | Wert |
|---|---|
| Resource-Name | `product_badge` (identisch in Schema, Trigger-Plugin, Sync-Plugin, Key-Builder) |
| **Storage-Key** (Redis, Code ohne internen Prefix `kv:`) | `product_badge:<id_product_abstract>`, z. B. `product_badge:123`. Erzeugt vom `SynchronizationService` (`DefaultKeyGeneratorPlugin`), Reference = `fk_product_abstract`, kein Store/Locale. Direkt in Redis: `kv:product_badge:123` |
| Redis-Wert | JSON von `ProductBadgeStorage`: `id_product_abstract`, `product_badges[]` (+ `_timestamp` vom Behavior) |
| **Entity-Events** (event-Behavior auf `spy_product_badge`) | `Entity.spy_product_badge.create`, `.update`, `.delete` |
| **Publish-Event** (Re-Generation) | `ProductBadge.product_badge.publish`, Event-ID = Product-Abstract-ID |
| Publish-Queue | `publish.product_badge` (Array-Key im Plugin-Stack) |
| Sync-Queue | `sync.storage.product_badge` |
| Queue-Pool | `synchronizationPool` (Schema-Parameter `queue_pool`, Konstante `PRODUCT_BADGE_SYNCHRONIZATION_POOL_NAME`) |
| Konstanten | `Pyz\Shared\ProductBadgeStorage\ProductBadgeStorageConfig` |

Ablauf Writer: Entity-Events liefern die Product-Abstract-ID als Foreign Key (`spy_product_badge.fk_product_abstract`, vom event-Behavior in `foreignKeys` abgelegt). Der Writer lädt alle Badges je Product Abstract und schreibt genau **eine** Storage-Zeile je Product Abstract. Ohne Badges wird die Zeile gelöscht (`delete()` entfernt den Redis-Key).

## Dateiliste (Zielpfad unter dem Projektroot)

```
src/Pyz/Shared/ProductBadgeStorage/ProductBadgeStorageConfig.php
src/Pyz/Zed/ProductBadgeStorage/
├── ProductBadgeStorageDependencyProvider.php
├── Business/{ProductBadgeStorageFacade,ProductBadgeStorageFacadeInterface,ProductBadgeStorageBusinessFactory}.php
├── Business/Writer/{ProductBadgeStorageWriter,ProductBadgeStorageWriterInterface}.php
├── Persistence/{ProductBadgeStoragePersistenceFactory,ProductBadgeStorageRepository(+Interface),ProductBadgeStorageEntityManager(+Interface)}.php
├── Persistence/Propel/Mapper/ProductBadgeStorageMapper.php
├── Communication/Plugin/Publisher/{ProductBadgeWritePublisherPlugin,ProductBadgePublisherTriggerPlugin}.php
└── Communication/Plugin/Synchronization/ProductBadgeSynchronizationDataBulkRepositoryPlugin.php
src/Pyz/Client/ProductBadge/
├── ProductBadgeClient.php, ProductBadgeClientInterface.php, ProductBadgeFactory.php, ProductBadgeDependencyProvider.php
└── Reader/{ProductBadgeStorageReader,ProductBadgeStorageReaderInterface}.php
```

Das Client-Modul `Pyz\Client\ProductBadge` ist der Lesezugriff, den die Glue- und Yves-Templates nutzen (`findProductBadgesByIdProductAbstract(int): array<ProductBadgeTransfer>`).

## Platzhalter / Anpassung

- `ProductBadge` / `product_badge` durch den eigenen Modulnamen ersetzen (Modul `<Name>Storage` im Zed, Client-Modul `<Name>`).
- Der Writer liest die Quell-Tabelle über `SpyProductBadgeQuery`, die per `providePersistenceLayerDependencies()` injiziert wird (Muster aus `ProductAlternativeDependencyProvider::PROPEL_QUERY_PRODUCT`). Alternative: Facade des Quell-Moduls direkt über den Locator beziehen (keine Bridge im Projekt, siehe [Referenz 02](../../references/02-projektmodul.md)).
- **Keine Bridges:** Die Facade `EventBehavior` (Zed) sowie `StorageClient` und `SynchronizationService` (Client) kommen direkt aus dem Locator (`$container->getLocator()->storage()->client()` usw.). Die Factory-Getter sind auf die Interfaces der Fremdmodule typisiert (`EventBehaviorFacadeInterface`, `StorageClientInterface`, `SynchronizationServiceInterface`).
- Mit Store- oder Locale-abhängigem Key: im Schema `store`/`locale`-Parameter ergänzen, im Reader `setStore()`/`setLocale()` am `SynchronizationDataTransfer` setzen, `hasStore()` im Sync-Plugin auf `true`.
- Queue-Pool (`queue_pool`) nur ohne `store`-Parameter. Er ist hier **nötig**: `Behavior`-Sync läuft auch ohne, aber `sync:data` wirft ohne `getSynchronizationQueuePoolName()` die `SynchronizationQueuePoolNotFoundException` ("You must specify either store column or `SynchronizationQueuePoolName`"). Schema-Parameter, Plugin-Rückgabe und `RabbitMqConfig::getQueuePools()` müssen denselben Poolnamen verwenden (`synchronizationPool`).
- Für Search statt Storage: Tabelle `*_search`, `SynchronizationSearchQueueMessageProcessorPlugin`, `params` `{"type":"page"}` (siehe [Client/Storage/Search](../../references/08-client-storage-search.md)).

## Registrierung

Auszüge, nur Zeilen ergänzen.

`src/Pyz/Zed/Publisher/PublisherDependencyProvider.php`

```php
use Pyz\Shared\ProductBadgeStorage\ProductBadgeStorageConfig;
use Pyz\Zed\ProductBadgeStorage\Communication\Plugin\Publisher\ProductBadgePublisherTriggerPlugin;
use Pyz\Zed\ProductBadgeStorage\Communication\Plugin\Publisher\ProductBadgeWritePublisherPlugin;

// in getPublisherPlugins(): im array_merge(...) ergänzen
$this->getProductBadgeStoragePlugins(),

/**
 * @return array<string, list<\Spryker\Zed\PublisherExtension\Dependency\Plugin\PublisherPluginInterface>>
 */
protected function getProductBadgeStoragePlugins(): array
{
    return [
        ProductBadgeStorageConfig::PUBLISH_PRODUCT_BADGE => [
            new ProductBadgeWritePublisherPlugin(),
        ],
    ];
}

// in getPublisherTriggerPlugins():
new ProductBadgePublisherTriggerPlugin(),
```

`src/Pyz/Zed/Synchronization/SynchronizationDependencyProvider.php`, `getSynchronizationDataPlugins()`:

```php
use Pyz\Zed\ProductBadgeStorage\Communication\Plugin\Synchronization\ProductBadgeSynchronizationDataBulkRepositoryPlugin;

new ProductBadgeSynchronizationDataBulkRepositoryPlugin(),
```

`src/Pyz/Client/RabbitMq/RabbitMqConfig.php`

```php
// getPublishQueueConfiguration()
ProductBadgeStorageConfig::PUBLISH_PRODUCT_BADGE,

// getSynchronizationQueueConfiguration()
ProductBadgeStorageConfig::PRODUCT_BADGE_SYNC_STORAGE_QUEUE,
```

`src/Pyz/Zed/Queue/QueueDependencyProvider.php`, `getProcessorMessagePlugins()`:

```php
ProductBadgeStorageConfig::PUBLISH_PRODUCT_BADGE => new EventQueueMessageProcessorPlugin(),
ProductBadgeStorageConfig::PRODUCT_BADGE_SYNC_STORAGE_QUEUE => new SynchronizationStorageQueueMessageProcessorPlugin(),
```

Bereits im Projekt vorhanden und nicht anzufassen: `PublisherSubscriber` in `EventDependencyProvider`, `EventBehaviorPostHookPlugin` und `PublisherTriggerEventsConsole`/`ExportSynchronizedDataConsole` im `ConsoleDependencyProvider`, `EventBehaviorEventDispatcherPlugin` im `EventDispatcherDependencyProvider`.

Der Client benötigt keine Registrierung (Class-Resolver findet `Pyz\Client\ProductBadge\*`).

## Befehle danach

Alle Befehle liefen im Shop 202610.0 mit Exit 0 `[live verifiziert (202610.0)]` (Registrierung wie unten beschrieben, `queue:setup` legte `publish.product_badge`, `sync.storage.product_badge` und die `.error`-Queues an, `event:dump:listener` zeigt `ProductBadgeStorage::ProductBadgeWritePublisherPlugin` mit "Handled in Queue: Yes").

```bash
docker/sdk cli console transfer:generate
docker/sdk cli console propel:install          # generiert Entities inkl. event-/synchronization-Hooks
docker/sdk cli console queue:setup             # legt publish.product_badge und sync.storage.product_badge an
docker/sdk cli console event:dump:listener --event=Entity.spy_product_badge.create   # nur Dev-Konsolenkommandos
docker/sdk cli console publish:trigger-events -r product_badge       # Re-Generation (Mirror-Tabelle neu)
docker/sdk cli console sync:data product_badge                       # Re-Sync nach Redis (Argumente, keine Optionen)
docker/sdk cli console queue:worker:start                            # Details: ../../references/22-console-cron-queue.md
```

Prüfen: Badge im Back Office/über Facade speichern, Worker laufen lassen (im Shop erledigt das der Scheduler innerhalb einer Minute; sonst `queue:task:start event`, `queue:task:start publish.product_badge`, `queue:task:start sync.storage.product_badge`), danach `spy_product_badge_storage` (Zeile mit `data`, `key = product_badge:<id>`) und Redis (`valkey-cli -n 1 get kv:product_badge:<id>`, Datenbank 1 = Storage) kontrollieren.

Live beobachtet (202610.0): (1) Datenimport (`data:import:product-badge`, Publish-Event über `PublishAwareStep`) -> Storage-Zeilen und Redis-Keys `kv:product_badge:4/5`. (2) Anlage im Back Office und per Glue-Backend (Entity-Event `Entity.spy_product_badge.create`) -> Publisher -> Storage-Zeile -> Redis. (3) Löschen per Propel (`SpyProductBadge::delete()` plus `EventBehaviorFacade::triggerRuntimeEvents()` in einem Skript) -> Storage-Zeile und Redis-Key verschwinden. (4) `sync:data product_badge` schreibt fehlende Keys neu (der Wert enthält dann kein `_timestamp`). **Wichtig:** `publish:trigger-events -r product_badge` ändert bei unverändertem Quelldatensatz nichts (Propel speichert die unveränderte Storage-Zeile nicht, es entsteht keine Sync-Nachricht). Ein manuell gelöschter Redis-Key kommt daher nur über `sync:data product_badge [<id_product_abstract>,...]` zurück. Löscht man Quellzeilen per SQL, entsteht kein Entity-Event, der Trigger liest nur vorhandene Quellzeilen: Storage-Zeile und Key bleiben stehen.

## Vorbild-Quellen

- `vendor/spryker/publish-and-synchronize-health-check-storage/src/Spryker/**` (Schema, Writer, Publisher- und Trigger-Plugin; Config mit Event-/Queue-Konstanten)
- `vendor/spryker/asset-storage/src/Spryker/Zed/AssetStorage/**` (Bulk-Repository-Sync-Plugin), `.../Spryker/Client/AssetStorage/**` (Client mit Reader, Key-Builder; die dortigen Bridges sind Core-Muster und werden im Projekt nicht nachgebaut)
- `vendor/spryker/category-storage/.../CategoryStorageRepository.php` (`SynchronizationDataTransferObjectFormatter`)
- `vendor/spryker/product-label-storage/.../ProductAbstractLabelPublisherTriggerPlugin.php` (`getIdColumnName()` = `fk_product_abstract`)
- Projekt: `src/Pyz/Zed/Publisher/PublisherDependencyProvider.php`, `src/Pyz/Zed/Queue/QueueDependencyProvider.php`, `src/Pyz/Client/RabbitMq/RabbitMqConfig.php`

## Hinweise und Abweichungen

- `handleBulk(array $eventEntityTransfers, $eventName): void`: Parametertyp von `$eventName` bleibt untypisiert (Interface `EventBulkHandlerInterface`, Typisierung wäre eine Signaturverletzung), `: void` ist kompatibel.
- Direct Sync ist im Release aus (`isDirectSynchronizationEnabled() = false`, zusätzlich greift es nur mit `SPRYKER_DYNAMIC_STORE_MODE`). Das Template arbeitet mit Queue-Sync.
- Die Sync-ID-Argumente von `sync:data product_badge <ids>` sind Product-Abstract-IDs (Filter auf `fk_product_abstract` der Storage-Tabelle).
- Keine Transaktionsklammer um die Schleife im Writer. Für Atomarität `$this->getTransactionHandler()` in einer Business-Klasse ergänzen [Annahme, nicht geprüft].
- `ProductBadgeStorageRepository::getProductBadgeEntityTransfers()` liefert bewusst `SpyProductBadgeEntityTransfer` (über `buildQueryFromCriteria()`): `EventResourceRepositoryManager` liest die ID-Spalte per `modifiedToArray()['fk_product_abstract']` aus dem Rückgabewert des Trigger-Plugins. Mappt man auf `ProductBadgeTransfer`, scheitert `publish:trigger-events` mit `TypeError` im Mapper (`SpyProductBadge` erwartet, `SpyProductBadgeEntityTransfer` übergeben). `buildQueryFromCriteria()` liefert immer Entity-Transfers, nie Propel-Objekte.
- Das event-Behavior auf der Quell-Tabelle verwendet `column="*"`. Ein DB-seitiges `ON DELETE CASCADE` würde keine Entity-Events auslösen (daher im Schema nicht gesetzt).

## Verifikationsstatus

Interfaces und Signaturen gegen `vendor/` geprüft: `PublisherPluginInterface`, `PublisherTriggerPluginInterface` (-> `EventResourceBulkRepositoryPluginInterface::getData(int,int)`), `SynchronizationDataBulkRepositoryPluginInterface::getData(int,int,array)`, `EventBehaviorFacadeInterface::getEventTransferIds/getEventTransferForeignKeys`, `StorageClientInterface::get`, `SynchronizationServiceInterface::getStorageKeyBuilder`, `AbstractRepository::buildQueryFromCriteria`, `SynchronizationDataTransferObjectFormatter`. `[geprüft in Sandbox: php -l, phpcs (phpcs.xml), phpstan Level 6 mit Projektkonfig (202610.0)]` Transfers und Propel-Klassen dafür in einer Sandbox generiert, nicht im Projekt. Das Project-Ruleset des Architecture Sniffers meldet keine Verstöße, `getProductBadgePropelQuery()` entspricht der `FactoryPropelQueryMethodNameRule`. Durchgespielt im Shop 202610.0 (siehe Protokoll Phase 2): Publish -> Storage -> Sync -> Redis für Import, Back Office, Glue-Backend und Löschen. Korrekturen aus dem Lauf: `queue_pool` (Schema, Plugin, Config-Konstante) und Trigger-Plugin mit Entity-Transfers. `[live verifiziert (202610.0)]`
