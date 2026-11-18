# Publish & Synchronization, Schreibseite (Recherche T-07)

> Status: Release b2b-demo-shop 202610.0 (spryker/event 2.19.0, event-behavior 1.36.0, publisher 1.3.1, publisher-extension 1.0.0, synchronization 1.21.0, synchronization-behavior 1.15.0, synchronization-extension 1.3.0, queue 1.32.0, product-storage 1.58.1). Verhalten am Quellcode geprüft; Doku-Seiten waren abrufbar, vier verwandte Doku-Seiten wurden nicht ausgewertet (siehe Quellen). Die Doku-Seiten `add-publish-events`, `implement-event-trigger-publisher-plugins`, `implement-synchronization-plugins` sind von 2021 und im Stil älter als der Code. `[code-verifiziert, Doku nicht abgeglichen]` bei Details nur am Code. Befehle im Container ausgeführt `[live verifiziert (202610.0)]` (Trigger, Sync, Queue-Worker, Scheduler-Suspend/Resume). Das Lesen (Client) steht in [Client/Storage/Search](08-client-storage-search.md).

## Zweck

Schreibpfad von der Entity-Änderung bis Redis/OpenSearch: Entity-Event -> Publisher -> `*_storage`/`*_search`-Tabelle -> Sync-Queue -> Redis bzw. Search. Dazu Re-Generation, Re-Sync, Direct Sync und Debugging. Workerbetrieb und eigene Queues: [Console, Cron, Queue](22-console-cron-queue.md). Propel-Grundlagen: [Propel](05-propel-persistenz.md). Plugin-Stacks allgemein: [Core erweitern](03-core-erweitern.md).

## Konzepte

**Ablauf**
1. **Entity speichern/löschen.** Eine Tabelle mit `<behavior name="event">` löst bei `save()`/`delete()` die Events `Entity.<tabelle>.create|update|delete` aus. Die Änderung landet zunächst in `spy_event_behavior_entity_change`.
2. **Runtime-Events auslösen.** Am Request-Ende (`EventBehaviorEventDispatcherPlugin`, `KernelEvents::TERMINATE`) bzw. nach einem Konsolenkommando (`EventBehaviorPostHookPlugin::postRun()`) ruft `EventBehaviorFacade::triggerRuntimeEvents()` die Änderungen ab und gibt sie an den `EventFacade`. Beides ist im Projekt registriert (`EventDispatcherDependencyProvider`, `ConsoleDependencyProvider`).
3. **Event -> Queue.** Publisher-Plugins werden vom `PublisherSubscriber` (`src/Pyz/Zed/Event/EventDependencyProvider.php`) als queued Listener registriert (`addListenerQueued`).
4. **Queue-Name** (`PublisherEventCollator`): Array-Key des Plugin-Stacks (eigene Queue), sonst `PublisherConfig::getPublishQueueName()` (Pyz: `publish`), sonst `event`.
5. **Worker -> Publisher.** `QueueDependencyProvider::getProcessorMessagePlugins()` bindet die Queue an `EventQueueMessageProcessorPlugin`. Der `EventQueueConsumer` gruppiert Nachrichten je Listener und Event und ruft `handleBulk()`. Bei Fehlern wird jedes Element einzeln wiederholt, dann Retry-/Error-Queue.
6. **Writer schreibt die Mirror-Tabelle** (`->save()`).
7. **Synchronization-Behavior** (`postSave()`/`postDelete()`): erzeugt `key`, packt `data` samt `_timestamp` in eine Sync-Nachricht und sendet sie in `queue_group` bzw. `queue_pool`. Mit Direct Sync stattdessen in einen In-Memory-Buffer.
8. **Sync-Worker.** `SynchronizationStorageQueueMessageProcessorPlugin` schreibt JSON per StorageClient nach Redis, `SynchronizationSearchQueueMessageProcessorPlugin` nach Elasticsearch/OpenSearch. `OutdatedValidator` verwirft bei mehr als einem Worker veraltete Nachrichten anhand `_timestamp`.

**Queue-Wiring (nicht vergessen):** Jede Publish- und Sync-Queue muss in `src/Pyz/Client/RabbitMq/RabbitMqConfig.php` (`getPublishQueueConfiguration()`, `getSynchronizationQueueConfiguration()`) deklariert und in `QueueDependencyProvider::getProcessorMessagePlugins()` einem Processor zugeordnet sein, danach `queue:setup`.

**Bausteine**
- **Event-Behavior:** Parameter `name` (im Schema eindeutig), `column` (`*` = alle; fehlt `column`, `PropelException`), optional `value`, `operator`, `keep-original`. Event-Namen als Konstanten in der Shared-Config des Moduls. Zusatzwerte im Event (`originalValues`, `additionalValues`) entstehen über die Behavior-Parameter `keep-original="true"` bzw. `keep-additional="true"` am jeweiligen `<parameter>` (`column="*"` = alle Spalten); der Behavior generiert daraus `getOriginalValueColumnNames()`/`getAdditionalValueColumnNames()` in der Entity. Die Methoden werden nicht von Hand überschrieben. Beispiele: `keep-additional` in `src/Pyz/Zed/Availability/Persistence/Propel/Schema/spy_availability.schema.xml`, `keep-original` in `spy_category.schema.xml`.
- **Publisher-Plugin** (`Communication/Plugin/Publisher/`): `AbstractPlugin` + `Spryker\Zed\PublisherExtension\Dependency\Plugin\PublisherPluginInterface` (`getSubscribedEvents(): array`, `handleBulk(array $eventEntityTransfers, $eventName)` aus `EventBulkHandlerInterface`). Registrierung `Pyz\Zed\Publisher\PublisherDependencyProvider::getPublisherPlugins()` (deklarierter Rückgabetyp `: array`; die Union steht nur im PHPDoc): flache Liste = Default-Queue, `[queueName => [plugins]]` = eigene Publish-Queue.
- **Publisher-Trigger-Plugin** (Re-Generation): `PublisherTriggerPluginInterface` (extends `EventResourceBulkRepositoryPluginInterface`): `getResourceName()`, `getEventName()`, `getIdColumnName(): ?string`, `getData(int $offset, int $limit): array`. Registrierung `getPublisherTriggerPlugins()`. `getIdColumnName()` im Format `tabelle.spalte`: der Teil nach dem Punkt wird aus `modifiedToArray()` der gelieferten Transfers gelesen und wird zur Event-ID. Die gelieferten Objekte müssen Entity-Transfers sein (`buildQueryFromCriteria(...)->find()` liefert `Spy…EntityTransfer`); auf Business-Transfers gemappte Daten führen zu Fehlern bzw. fehlenden IDs `[live verifiziert (202610.0)]`.
- **Synchronization-Data-Plugin** (Re-Sync): aktuell `SynchronizationDataBulkRepositoryPluginInterface` (`getData(int $offset, int $limit, array $ids = []): array` mit `SynchronizationDataTransfer[]`, dazu `getResourceName()`, `hasStore()`, `getParams()`, `getQueueName()`, `getSynchronizationQueuePoolName()`). Registrierung `Pyz\Zed\Synchronization\SynchronizationDependencyProvider::getSynchronizationDataPlugins()`. Der Export verwendet `SynchronizationDataTransfer::getKey()` als fertigen Redis-Key.
- **Synchronization-Behavior-Parameter:**

| Parameter | Bedeutung |
|---|---|
| `resource` | Pflicht, Resource-Name für die Key-Generierung (`MissingAttributeException`) |
| `key_suffix_column` | Spalte, deren Wert der Key-Reference wird |
| `store` / `locale` | ergänzt Spalten `store` (VARCHAR 128) bzw. `locale` (VARCHAR 16), fließt in den Key ein |
| `queue_group` | Sync-Queue. Fehlt er, gilt `resource` |
| `queue_pool` | Queue-Pool (`RabbitMqConfig::getQueuePools()`), **exklusiv mit `store`** (`InvalidConfigurationException`). Ohne `store` Pflicht für `sync:data`: Das Sync-Data-Plugin muss denselben Pool aus `getSynchronizationQueuePoolName()` liefern, sonst `SynchronizationQueuePoolNotFoundException` `[live verifiziert (202610.0)]` |
| `mappings` | `"<key>:<spalte>"`, mehrere mit `;`, erzeugt Alias-Keys (Spalte `alias_keys`) |
| `params` | JSON für `params` in der Nachricht, Search-Tabellen `{"type":"page"}` |
| `synchronization_enabled`, `direct_sync_disabled` | pro Tabelle überschreiben Config bzw. schalten Direct Sync ab |

  Der Behavior ergänzt automatisch `data` (Typ `getDataColumnType()`, Default `CLOB`) und `key` (unique), bei Bedarf `store`, `locale`, `alias_keys`. Tabellenname endet auf `_storage` (Ziel Redis) bzw. `_search` (Ziel Search), die Direct-Sync-Zielerkennung beruht darauf.
- **Storage-Key:** erzeugt der `SynchronizationService` (`DefaultKeyGeneratorPlugin`): `<resource>[:<store>][:<locale>][:<reference>]`, Store/Locale klein, Reference bereinigt. Laufbeispiel: `product_badge:<id_product_abstract>`.

## Anleitung

1. **Schema:** Quell-Tabelle mit `event`-Behavior, Mirror-Tabelle `<name>_storage` mit `synchronization`-Behavior ([transfer-and-propel](../templates/transfer-and-propel/README.md)). Danach `propel:install` (Hooks stecken im generierten Entity-Code).
2. **Modul `<Name>Storage`** (Suffix `Storage` bzw. `Search`): Shared-Config mit Konstanten (Events, Resource, Queues), Writer in der Business-Schicht, DB-Zugriff über Repository/EntityManager, Publisher-Plugins in `Communication/Plugin/Publisher/`.
3. **Writer bulk-orientiert:** `handleBulk` bekommt viele Events. IDs über `EventBehaviorFacade::getEventTransferIds()`, `getEventTransferForeignKeys()`, `getEventTransfersByModifiedColumns()` einsammeln, in einer Query laden, nicht je Event eine Query. `data` als Array setzen (`setData($transfer->toArray())`, der Behavior serialisiert nach JSON). Beim Löschen die Mirror-Entity per `delete()` entfernen (löscht den Redis-Key).
4. **Trigger-Plugin und Sync-Data-Plugin immer mitliefern**, sonst sind `publish:trigger-events -r …` bzw. `sync:data …` wirkungslos. `resource` identisch in Schema, Trigger-Plugin, Sync-Plugin und Reader halten.
5. **Registrieren:** `PublisherDependencyProvider`, `SynchronizationDependencyProvider`, `RabbitMqConfig`, `QueueDependencyProvider` (Zeilen im Template-README).
6. **Queues und Hooks aktivieren** (alle drei ausgeführt `[live verifiziert (202610.0)]`, Exit 0):
   ```bash
   docker/sdk cli console propel:install
   docker/sdk cli console transfer:generate
   docker/sdk cli console queue:setup
   ```
7. **Re-Generation / Re-Sync** (Doku `publish-and-synchronize-re-synchronization-and-re-generation`; `publish:trigger-events -r navigation` (8 Nachrichten landen in der Queue `publish`), `-r cms_page -i 1,2` und `sync:data navigation [1,2]` ausgeführt `[live verifiziert (202610.0)]`, Exit 0, still; zum Abarbeiten `queue:worker:start --stop-when-empty`):
   ```bash
   docker/sdk cli console publish:trigger-events -r product_badge -i 1,2   # -r/--resource, -i/--ids
   docker/sdk cli console sync:data product_badge 1,2                      # Argumente, keine Optionen
   docker/sdk cli console event:trigger:timeout                            # hängende Entity-Change-Events
   ```
   `sync:data` liest vorhandene Mirror-Tabellen über die Sync-Data-Plugins und füllt Redis/Search neu (nach Flush). `publish:trigger-events` baut die Mirror-Tabellen über die Trigger-Plugins neu auf. Redis/Search werden danach nur aktualisiert, wenn sich die Mirror-Entity geändert hat; für alles zusätzlich `sync:data`.
8. **Direct Sync** (Doku `configure-direct-synchronize`): `DirectSynchronizationConsolePlugin` in `ConsoleDependencyProvider::getEventSubscriber()` (im Projekt vorhanden), `SynchronizationBehaviorConfig::isDirectSynchronizationEnabled()` auf `true` (Pyz: `false`), `propel:install`, empfohlen `QueueConfig::isReducedSyncQueueScanEnabled()` (spryker/queue >= 1.29.0). Einzelne Tabellen: `<parameter name="direct_sync_disabled"/>`. **Code-Befund:** Direct Sync gilt nur, wenn `isDirectSynchronizationEnabled() && !direct_sync_disabled && isDynamicStoreEnabled()` (`(bool)getenv('SPRYKER_DYNAMIC_STORE_MODE')`). Ohne Dynamic Store Mode bleibt es bei Queue-Sync, auch mit gesetztem Flag. Das ist strenger als die Doku, der Code gilt.
9. **Debuggen** (Doku `publish-and-synchronize-debugging`):
   - Event-Logger: `$config[EventConstants::LOGGER_ACTIVE] = true` (im Projekt gesetzt), Logfile `EventConstants::LOG_FILE_PATH` (`…/ZED/application_events.log`). Mindest-Loglevel `EventConfig::DEFAULT_EVENT_LOGGER_MIN_LEVEL` in Pyz `Logger::WARNING`, zum Debuggen auf `INFO` senken.
   - RabbitMQ-UI: `.error`-Queues zeigen Fehlschläge. Beim manuellen Debugging Jenkins-Jobs abschalten.
   - Eingrenzen: Mirror-Tabelle falsch/leer = Publisher/Writer. Mirror korrekt, Redis nicht = Sync-Schritt. Redis korrekt = Lesepfad ([Client](08-client-storage-search.md)).
   - Dev-Kommandos (`isDevelopmentConsoleCommandsEnabled()`): `event:dump:listener [--event=…] [--queue=…] [--event-names-only] [--queue-names-only]`, `event:trigger:listener <listenerName> <data> [-e event] [-f format]`.
   - Listener-Fehlermeldungen erscheinen nur bei aktivem Event-Logger (`EventQueueConsumer::handleFailedEventItem`).

Nützliche Konfig: `EventConfig::getEventQueueMessageChunkSize()` (500), `EventBehaviorConfig::getTriggerChunkSize()` (1000), `getTriggerChunkSleepSeconds()` (5), `getMaxEventMessageDataSize()` (256), `SynchronizationConstants::DEFAULT_SYNC_SEARCH_QUEUE_MESSAGE_CHUNK_SIZE` (Projekt 1000), `SynchronizationConfig::isRepositorySyncExportPropelInstancePoolingDisabled()` (Pyz `true`), die Konstante `DEFAULT_SYNCHRONIZATION_POOL_NAME = 'synchronizationPool'` gibt es nur im Projekt (`Pyz\Zed\Synchronization\SynchronizationConfig`), nicht im Core-`SynchronizationConfig`.

## Beispiele

Vollständiges, zusammenpassendes Laufbeispiel: [`publisher-and-synchronization`](../templates/publisher-and-synchronization/README.md). Festlegungen dort: Resource `product_badge`, Key `product_badge:<id_product_abstract>`, Events `Entity.spy_product_badge.create|update|delete` und `ProductBadge.product_badge.publish`, Queues `publish.product_badge` und `sync.storage.product_badge`.

Storage-Tabelle (aus [transfer-and-propel](../templates/transfer-and-propel/README.md)):

```xml
<table name="spy_product_badge_storage" identifierQuoting="true">
    <column name="id_product_badge_storage" required="true" type="INTEGER" autoIncrement="true" primaryKey="true"/>
    <column name="fk_product_abstract" required="true" type="INTEGER"/>
    <unique name="spy_product_badge_storage-unique-fk_product_abstract">
        <unique-column name="fk_product_abstract"/>
    </unique>
    <id-method-parameter value="spy_product_badge_storage_pk_seq"/>
    <behavior name="synchronization">
        <parameter name="resource" value="product_badge"/>
        <parameter name="key_suffix_column" value="fk_product_abstract"/>
        <parameter name="queue_group" value="sync.storage.product_badge"/>
    </behavior>
    <behavior name="timestampable"/>
</table>
```

Publisher-Plugin (`src/Pyz/Zed/ProductBadgeStorage/Communication/Plugin/Publisher/ProductBadgeWritePublisherPlugin.php`):

```php
class ProductBadgeWritePublisherPlugin extends AbstractPlugin implements PublisherPluginInterface
{
    public function handleBulk(array $eventEntityTransfers, $eventName): void
    {
        $this->getFacade()->writeProductBadgeStorageCollectionByEvents($eventEntityTransfers, (string)$eventName);
    }

    public function getSubscribedEvents(): array
    {
        return [
            ProductBadgeStorageConfig::ENTITY_SPY_PRODUCT_BADGE_CREATE,
            ProductBadgeStorageConfig::ENTITY_SPY_PRODUCT_BADGE_UPDATE,
            ProductBadgeStorageConfig::ENTITY_SPY_PRODUCT_BADGE_DELETE,
            ProductBadgeStorageConfig::PRODUCT_BADGE_PUBLISH,
        ];
    }
}
```

Der Parametertyp von `$eventName` bleibt untypisiert (Interface-Signatur), `: void` ist kompatibel. Das Core-Beispiel nutzt untypisiert ohne Rückgabetyp.

Writer-Kern (Entity-Events: Foreign Key, Publish-Event: Event-ID; ein Storage-Datensatz je Product Abstract, ohne Badges Löschen):

```php
$idsProductAbstract = $eventName === ProductBadgeStorageConfig::PRODUCT_BADGE_PUBLISH
    ? $this->eventBehaviorFacade->getEventTransferIds($eventEntityTransfers)
    : $this->eventBehaviorFacade->getEventTransferForeignKeys($eventEntityTransfers, 'spy_product_badge.fk_product_abstract');
// ... Badges je ID laden, dann
$productBadgeStorageEntity->setData($productBadgeStorageTransfer->toArray());
$productBadgeStorageEntity->save();   // postSave() des Behaviors sendet die Sync-Nachricht
```

Registrierung (Auszug `PublisherDependencyProvider`):

```php
protected function getProductBadgeStoragePlugins(): array
{
    return [
        ProductBadgeStorageConfig::PUBLISH_PRODUCT_BADGE => [   // Array-Key = eigene Publish-Queue
            new ProductBadgeWritePublisherPlugin(),
        ],
    ];
}
```

Real im Release (`PublisherDependencyProvider::getPublishAndSynchronizeHealthCheckPlugins()`): Queue-Key plus Storage- und Search-Plugin. Das Modul `vendor/spryker/publish-and-synchronize-health-check-storage` ist das kleinste vollständige Referenzmodul (Schema, Writer, Publisher-/Trigger-Plugin). Beobachtung: für den Health-Check ist im Pyz-`SynchronizationDependencyProvider` kein Sync-Data-Plugin eingetragen, `sync:data` kann die Resource also nicht re-exportieren.

## Fallstricke

1. Queue nur in `RabbitMqConfig` oder nur im `QueueDependencyProvider` eingetragen: Nachrichten stauen sich oder werden nie verarbeitet. Beides plus `queue:setup`.
2. `propel:install` nach Schema-/Behavior-Änderung vergessen: keine Events, keine Sync-Nachrichten.
3. `queue_pool` zusammen mit `store`-Parameter: `InvalidConfigurationException`. Die Abstract-Tabelle von ProductStorage hat `store` und kann keinen `queue_pool` haben, `spy_product_concrete_storage` nur `locale` und trägt ihn (`src/Pyz/Zed/ProductStorage/Persistence/Propel/Schema/spy_product_storage.schema.xml`).
4. `resource` fehlt: `MissingAttributeException`. Tabellenname ohne `_storage`/`_search`-Suffix: Direct-Sync-Zielerkennung leer.
5. `sync:data product_abstract --ids=1`: Die Option `--ids` gibt es bei `sync:data` nicht (Symfony meldet eine unbekannte Option). Die IDs sind das zweite positionale Argument: `sync:data product_abstract 1,2`. Nur `publish:trigger-events` kennt `-i|--ids`.
6. Event-Logger aus: Listener-Fehler erscheinen nur generisch, Nachrichten landen in `*.error`.
7. Direct Sync aktiviert, aber `SPRYKER_DYNAMIC_STORE_MODE` nicht gesetzt: es bleibt bei Queue-Sync.
8. Große Publisher-Läufe belegen Speicher (Propel Instance Pooling), `EventConfig::isInstancePoolingAllowed()` bzw. `isRepositorySyncExportPropelInstancePoolingDisabled()` beachten.
9. Mehrere Sync-Worker: nur der `OutdatedValidator` fängt Reihenfolgeprobleme ab (bei `getQueueWorkerNumber() > 1`).
10. Gleiche Daten erneut gespeichert: `setData()` mit identischem Wert ändert nichts, `postSave()` läuft nur bei `$affectedRows`, es entsteht keine Sync-Nachricht. Praktische Folge: `publish:trigger-events -r <resource>` füllt einen manuell gelöschten Redis-Key nicht wieder auf, dafür `sync:data <resource>` `[live verifiziert (202610.0)]`.
11. Upgrader-Anti-Patterns: Änderungen in `vendor/spryker/*-storage|*-search`, fremde Publisher-Plugins nach Pyz kopieren statt registrieren, `src/Orm`/`src/Generated` von Hand ändern ([Upgradability](28-upgradability.md)).
12. **Deprecations:**
    - `[Deprecated]` `EventTriggerConsole` (`event:trigger`): "Use PublisherTriggerEventsConsole instead" (`publish:trigger-events`), im Projekt nicht registriert.
    - Subscriber/Listener-Modell (`*EventSubscriber`, `Communication/Plugin/Event/Listener/*`): im Release weiter aktiv (ca. 50 Einträge in `src/Pyz/Zed/Event/EventDependencyProvider.php`), Doku nennt Listener Legacy. Mischbetrieb (ProductStorage hat Subscriber und Publisher-Plugin). Neu: Publisher-Plugin, Bestand nicht ohne Not migrieren. `[Annahme]` formale `@deprecated`-Markierung nicht klassenweise geprüft.
    - `EventBehaviorDependencyProvider::getEventTriggerResourcePlugins()` (`*EventResource*Plugin`): Legacy-Pendant zu `getPublisherTriggerPlugins()`, beide sind im Release befüllt.
    - `[Deprecated]` `SynchronizationDataRepositoryPluginInterface` ("Use SynchronizationDataBulkRepositoryPluginInterface instead", im Code geprüft). Die QueryContainer-Variante `SynchronizationDataQueryContainerPluginInterface` trägt dieses Tag nicht. Neue Plugins: `…BulkRepositoryPluginInterface`.
    - `[Deprecated]` Synchronization-Behavior `mapping_resource`: "Will be removed without replacement", stattdessen `mappings`. Behavior-Methoden `isSendingToQueue()`/`setIsSendingToQueue()`: "Use SynchronizationBehaviorConfig::isSynchronizationEnabled()".
    - Die Debug-Doku spricht noch von `{Entity}PublishListener` (veraltet gegenüber dem Plugin-Modell).

## Checkliste

- [ ] Quell-Tabelle mit `event`-Behavior, Mirror-Tabelle `*_storage`/`*_search` mit `synchronization`-Behavior (`resource`, `key_suffix_column`, `queue_group`).
- [ ] Event-/Queue-/Resource-Konstanten in der Shared-Config.
- [ ] Publisher-Plugin, Trigger-Plugin **und** Sync-Data-Plugin vorhanden.
- [ ] `resource` identisch in Schema, Trigger-, Sync-Plugin und Reader.
- [ ] Registriert: `PublisherDependencyProvider` (Plugins und Trigger), `SynchronizationDependencyProvider`, `RabbitMqConfig` (Publish + Sync), `QueueDependencyProvider`.
- [ ] `propel:install`, `transfer:generate`, `queue:setup` ausgeführt.
- [ ] Writer bulk-orientiert, löscht die Mirror-Entity per `delete()`.
- [ ] End-to-End geprüft: Mirror-Tabelle, Redis-Key, Reader.

## Quellen

Doku (Basis https://docs.spryker.com/docs/dg/dev/backend-development/data-manipulation/data-publishing/):
- publish-and-synchronization.html, implement-publish-and-synchronization.html (Stand 20.08.2026)
- add-publish-events.html, implement-event-trigger-publisher-plugins.html, implement-synchronization-plugins.html (Stand 16.06.2021)
- publish-and-synchronize-re-synchronization-and-re-generation.html (Stand 18.09.2025), publish-and-synchronize-debugging.html (Stand 18.09.2025)
- configurartion/configure-direct-synchronize.html (Tippfehler im Pfad ist original, Stand 05.08.2026)
- nicht ausgewertet: Handle data with Publish and Synchronization, Debug listeners, Synchronization behavior enabling multiple mappings, Publish and synchronize and multi-store ([Multi-Store](19-multi-store-i18n.md)).

Code:
- `vendor/spryker/event/src/Spryker/Zed/Event/{Business/Queue/Consumer/EventQueueConsumer.php,Communication/Plugin/Console/EventListenerDumpConsole.php,EventConfig.php,Dependency/Plugin/*}`
- `vendor/spryker/event-behavior/src/Spryker/Zed/EventBehavior/{Persistence/Propel/Behavior/EventBehavior.php,Business/Model/EventResourceRepositoryManager.php,Business/Model/EventEntityTransferFilter.php,Communication/Plugin/*,Dependency/Plugin/*}`
- `vendor/spryker/publisher/src/Spryker/Zed/Publisher/{PublisherDependencyProvider.php,Business/Collator/PublisherEventCollator.php,Communication/Plugin/Event/PublisherSubscriber.php,Communication/Console/PublisherTriggerEventsConsole.php}`
- `vendor/spryker/publisher-extension/…/Dependency/Plugin/{PublisherPluginInterface,PublisherTriggerPluginInterface}.php`
- `vendor/spryker/synchronization/src/Spryker/Zed/Synchronization/{Business/Export/RepositoryExporter.php,Communication/Console/ExportSynchronizedDataConsole.php,Persistence/Propel/Formatter/SynchronizationDataTransferObjectFormatter.php}`, `…/Service/Synchronization/Plugin/{BaseKeyGenerator,DefaultKeyGeneratorPlugin}.php`
- `vendor/spryker/synchronization-extension/…/Dependency/Plugin/*`, `vendor/spryker/synchronization-behavior/…/Behavior/SynchronizationBehavior.php`
- `vendor/spryker/publish-and-synchronize-health-check-storage/src/Spryker/**`, `vendor/spryker/asset-storage/**`, `vendor/spryker/product-label-storage/…/ProductAbstractLabelPublisherTriggerPlugin.php`
- Projekt: `src/Pyz/Zed/{Publisher,Event,EventBehavior,Synchronization,SynchronizationBehavior,Queue,Console,ProductStorage}/*`, `src/Pyz/Client/RabbitMq/RabbitMqConfig.php`, `config/Shared/config_default.php`, `config_default-docker*.php`

Offene Lücken: Lebenszyklus von `spy_event_behavior_entity_change` (Bereinigung, Timeout-Logik) nur oberflächlich geprüft, formale Deprecation der Subscriber nicht klassenweise geprüft.

Nachbar-Referenzen: [Propel](05-propel-persistenz.md), [Client/Storage/Search](08-client-storage-search.md), [Console/Cron/Queue](22-console-cron-queue.md), [Upgradability](28-upgradability.md). Templates: [publisher-and-synchronization](../templates/publisher-and-synchronization/README.md), [transfer-and-propel](../templates/transfer-and-propel/README.md).
