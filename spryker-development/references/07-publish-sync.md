# Publish & Synchronization, write side

> Status: Release b2b-demo-shop 202610.0 (spryker/event 2.19.0, event-behavior 1.36.0, publisher 1.3.1, publisher-extension 1.0.0, synchronization 1.21.0, synchronization-behavior 1.15.0, synchronization-extension 1.3.0, queue 1.32.0, product-storage 1.58.1). Behavior checked against the source code; docs pages were retrievable, four related docs pages were not evaluated (see Sources). The docs pages `add-publish-events`, `implement-event-trigger-publisher-plugins`, `implement-synchronization-plugins` are from 2021 and older in style than the code. `[code-verified, docs not cross-checked]` for details checked only against the code. Commands executed in the container `[live verified (202610.0)]` (trigger, sync, queue worker, scheduler suspend/resume). Reading (Client) is covered in [Client/Storage/Search](08-client-storage-search.md).

## Purpose

Write path from the entity change to Redis/OpenSearch: entity event -> publisher -> `*_storage`/`*_search` table -> sync queue -> Redis or search. Plus re-generation, re-sync, direct sync and debugging. Worker operation and custom queues: [Console, Cron, Queue](22-console-cron-queue.md). Propel basics: [Propel](05-propel-persistence.md). Plugin stacks in general: [Extending the core](03-extending-the-core.md).

## Concepts

**Flow**
1. **Save/delete the entity.** A table with `<behavior name="event">` fires the events `Entity.<table>.create|update|delete` on `save()`/`delete()`. The change first lands in `spy_event_behavior_entity_change`.
2. **Trigger runtime events.** At the end of the request (`EventBehaviorEventDispatcherPlugin`, `KernelEvents::TERMINATE`) or after a console command (`EventBehaviorPostHookPlugin::postRun()`), `EventBehaviorFacade::triggerRuntimeEvents()` fetches the changes and passes them to the `EventFacade`. Both are registered in the project (`EventDispatcherDependencyProvider`, `ConsoleDependencyProvider`).
3. **Event -> queue.** Publisher plugins are registered by the `PublisherSubscriber` (`src/Pyz/Zed/Event/EventDependencyProvider.php`) as queued listeners (`addListenerQueued`).
4. **Queue name** (`PublisherEventCollator`): array key of the plugin stack (custom queue), otherwise `PublisherConfig::getPublishQueueName()` (Pyz: `publish`), otherwise `event`.
5. **Worker -> publisher.** `QueueDependencyProvider::getProcessorMessagePlugins()` binds the queue to `EventQueueMessageProcessorPlugin`. The `EventQueueConsumer` groups messages per listener and event and calls `handleBulk()`. On errors each element is retried individually, then retry/error queue.
6. **The writer writes the mirror table** (`->save()`).
7. **Synchronization behavior** (`postSave()`/`postDelete()`): generates `key`, packs `data` together with `_timestamp` into a sync message and sends it to `queue_group` or `queue_pool`. With direct sync, into an in-memory buffer instead.
8. **Sync worker.** `SynchronizationStorageQueueMessageProcessorPlugin` writes JSON via the StorageClient to Redis, `SynchronizationSearchQueueMessageProcessorPlugin` to Elasticsearch/OpenSearch. With more than one worker, `OutdatedValidator` discards outdated messages based on `_timestamp`.

**Queue wiring (do not forget):** Every publish and sync queue must be declared in `src/Pyz/Client/RabbitMq/RabbitMqConfig.php` (`getPublishQueueConfiguration()`, `getSynchronizationQueueConfiguration()`) and assigned to a processor in `QueueDependencyProvider::getProcessorMessagePlugins()`, then `queue:setup`.

**Building blocks**
- **Event behavior:** parameters `name` (unique within the schema), `column` (`*` = all; if `column` is missing, `PropelException`), optionally `value`, `operator`, `keep-original`. Event names as constants in the module's shared config. Additional values in the event (`originalValues`, `additionalValues`) come from the behavior parameters `keep-original="true"` or `keep-additional="true"` on the respective `<parameter>` (`column="*"` = all columns); from these the behavior generates `getOriginalValueColumnNames()`/`getAdditionalValueColumnNames()` in the entity. The methods are not overridden by hand. Examples: `keep-additional` in `src/Pyz/Zed/Availability/Persistence/Propel/Schema/spy_availability.schema.xml`, `keep-original` in `spy_category.schema.xml`.
- **Publisher plugin** (`Communication/Plugin/Publisher/`): `AbstractPlugin` + `Spryker\Zed\PublisherExtension\Dependency\Plugin\PublisherPluginInterface` (`getSubscribedEvents(): array`, `handleBulk(array $eventEntityTransfers, $eventName)` from `EventBulkHandlerInterface`). Registration `Pyz\Zed\Publisher\PublisherDependencyProvider::getPublisherPlugins()` (declared return type `: array`; the union is only in the PHPDoc): flat list = default queue, `[queueName => [plugins]]` = custom publish queue.
- **Publisher trigger plugin** (re-generation): `PublisherTriggerPluginInterface` (extends `EventResourceBulkRepositoryPluginInterface`): `getResourceName()`, `getEventName()`, `getIdColumnName(): ?string`, `getData(int $offset, int $limit): array`. Registration `getPublisherTriggerPlugins()`. `getIdColumnName()` in the format `table.column`: the part after the dot is read from `modifiedToArray()` of the returned transfers and becomes the event ID. The returned objects must be entity transfers (`buildQueryFromCriteria(...)->find()` returns `Spy…EntityTransfer`); data mapped to business transfers leads to errors or missing IDs `[live verified (202610.0)]`.
- **Synchronization data plugin** (re-sync): currently `SynchronizationDataBulkRepositoryPluginInterface` (`getData(int $offset, int $limit, array $ids = []): array` with `SynchronizationDataTransfer[]`, plus `getResourceName()`, `hasStore()`, `getParams()`, `getQueueName()`, `getSynchronizationQueuePoolName()`). Registration `Pyz\Zed\Synchronization\SynchronizationDependencyProvider::getSynchronizationDataPlugins()`. The export uses `SynchronizationDataTransfer::getKey()` as the finished Redis key.
- **Synchronization behavior parameters:**

| Parameter | Meaning |
|---|---|
| `resource` | Mandatory, resource name for key generation (`MissingAttributeException`) |
| `key_suffix_column` | Column whose value becomes the key reference |
| `store` / `locale` | adds the columns `store` (VARCHAR 128) or `locale` (VARCHAR 16), goes into the key |
| `queue_group` | Sync queue. If missing, `resource` applies |
| `queue_pool` | Queue pool (`RabbitMqConfig::getQueuePools()`), **mutually exclusive with `store`** (`InvalidConfigurationException`). Without `store` mandatory for `sync:data`: the sync data plugin must return the same pool from `getSynchronizationQueuePoolName()`, otherwise `SynchronizationQueuePoolNotFoundException` `[live verified (202610.0)]` |
| `mappings` | `"<key>:<column>"`, several separated by `;`, creates alias keys (column `alias_keys`) |
| `params` | JSON for `params` in the message, search tables `{"type":"page"}` |
| `synchronization_enabled`, `direct_sync_disabled` | per table, override the config or switch off direct sync |

  The behavior automatically adds `data` (type `getDataColumnType()`, default `CLOB`) and `key` (unique), and if needed `store`, `locale`, `alias_keys`. The table name ends in `_storage` (target Redis) or `_search` (target search); direct sync target detection relies on this.
- **Storage key:** generated by the `SynchronizationService` (`DefaultKeyGeneratorPlugin`): `<resource>[:<store>][:<locale>][:<reference>]`, store/locale lowercase, reference sanitized. Running example: `product_badge:<id_product_abstract>`.

## Instructions

1. **Schema:** source table with `event` behavior, mirror table `<name>_storage` with `synchronization` behavior ([transfer-and-propel](../templates/transfer-and-propel/README.md)). Then `propel:install` (the hooks live in the generated entity code).
2. **Module `<Name>Storage`** (suffix `Storage` or `Search`): shared config with constants (events, resource, queues), writer in the business layer, DB access via Repository/EntityManager, publisher plugins in `Communication/Plugin/Publisher/`.
3. **Bulk-oriented writer:** `handleBulk` receives many events. Collect IDs via `EventBehaviorFacade::getEventTransferIds()`, `getEventTransferForeignKeys()`, `getEventTransfersByModifiedColumns()`, load them in one query, not one query per event. Set `data` as an array (`setData($transfer->toArray())`, the behavior serializes to JSON). On deletion remove the mirror entity via `delete()` (deletes the Redis key).
4. **Always ship the trigger plugin and the sync data plugin**, otherwise `publish:trigger-events -r …` or `sync:data …` have no effect. Keep `resource` identical in schema, trigger plugin, sync plugin and reader.
5. **Register:** `PublisherDependencyProvider`, `SynchronizationDependencyProvider`, `RabbitMqConfig`, `QueueDependencyProvider` (lines in the template README).
6. **Activate queues and hooks** (all three executed `[live verified (202610.0)]`, exit 0):
   ```bash
   docker/sdk cli console propel:install
   docker/sdk cli console transfer:generate
   docker/sdk cli console queue:setup
   ```
7. **Re-generation / re-sync** (docs `publish-and-synchronize-re-synchronization-and-re-generation`; `publish:trigger-events -r navigation` (8 messages land in the `publish` queue), `-r cms_page -i 1,2` and `sync:data navigation [1,2]` executed `[live verified (202610.0)]`, exit 0, silent; to process them `queue:worker:start --stop-when-empty`):
   ```bash
   docker/sdk cli console publish:trigger-events -r product_badge -i 1,2   # -r/--resource, -i/--ids
   docker/sdk cli console sync:data product_badge 1,2                      # arguments, not options
   docker/sdk cli console event:trigger:timeout                            # stuck entity change events
   ```
   `sync:data` reads existing mirror tables via the sync data plugins and refills Redis/search (after a flush). `publish:trigger-events` rebuilds the mirror tables via the trigger plugins. Redis/search are then only updated if the mirror entity changed; for everything, additionally run `sync:data`.
8. **Direct sync** (docs `configure-direct-synchronize`): `DirectSynchronizationConsolePlugin` in `ConsoleDependencyProvider::getEventSubscriber()` (present in the project), `SynchronizationBehaviorConfig::isDirectSynchronizationEnabled()` set to `true` (Pyz: `false`), `propel:install`, recommended `QueueConfig::isReducedSyncQueueScanEnabled()` (spryker/queue >= 1.29.0). Individual tables: `<parameter name="direct_sync_disabled"/>`. **Code finding:** direct sync only applies if `isDirectSynchronizationEnabled() && !direct_sync_disabled && isDynamicStoreEnabled()` (`(bool)getenv('SPRYKER_DYNAMIC_STORE_MODE')`). Without Dynamic Store mode it stays with queue sync, even with the flag set. This is stricter than the docs; the code applies.
9. **Debugging** (docs `publish-and-synchronize-debugging`):
   - Event logger: `$config[EventConstants::LOGGER_ACTIVE] = true` (set in the project), log file `EventConstants::LOG_FILE_PATH` (`…/ZED/application_events.log`). Minimum log level `EventConfig::DEFAULT_EVENT_LOGGER_MIN_LEVEL` in Pyz `Logger::WARNING`, lower it to `INFO` for debugging.
   - RabbitMQ UI: `.error` queues show failures. Switch off Jenkins jobs during manual debugging.
   - Narrowing down: mirror table wrong/empty = publisher/writer. Mirror correct, Redis not = sync step. Redis correct = read path ([Client](08-client-storage-search.md)).
   - Dev commands (`isDevelopmentConsoleCommandsEnabled()`): `event:dump:listener [--event=…] [--queue=…] [--event-names-only] [--queue-names-only]`, `event:trigger:listener <listenerName> <data> [-e event] [-f format]`.
   - Listener error messages only appear with the event logger active (`EventQueueConsumer::handleFailedEventItem`).

Useful config: `EventConfig::getEventQueueMessageChunkSize()` (500), `EventBehaviorConfig::getTriggerChunkSize()` (1000), `getTriggerChunkSleepSeconds()` (5), `getMaxEventMessageDataSize()` (256), `SynchronizationConstants::DEFAULT_SYNC_SEARCH_QUEUE_MESSAGE_CHUNK_SIZE` (project 1000), `SynchronizationConfig::isRepositorySyncExportPropelInstancePoolingDisabled()` (Pyz `true`); the constant `DEFAULT_SYNCHRONIZATION_POOL_NAME = 'synchronizationPool'` exists only in the project (`Pyz\Zed\Synchronization\SynchronizationConfig`), not in the core `SynchronizationConfig`.

## Examples

Complete, consistent running example: [`publisher-and-synchronization`](../templates/publisher-and-synchronization/README.md). Definitions there: resource `product_badge`, key `product_badge:<id_product_abstract>`, events `Entity.spy_product_badge.create|update|delete` and `ProductBadge.product_badge.publish`, queues `publish.product_badge` and `sync.storage.product_badge`.

Storage table (from [transfer-and-propel](../templates/transfer-and-propel/README.md)):

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

Publisher plugin (`src/Pyz/Zed/ProductBadgeStorage/Communication/Plugin/Publisher/ProductBadgeWritePublisherPlugin.php`):

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

The parameter type of `$eventName` stays untyped (interface signature), `: void` is compatible. The core example uses it untyped without a return type.

Writer core (entity events: foreign key, publish event: event ID; one storage record per product abstract, delete if there are no badges):

```php
$idsProductAbstract = $eventName === ProductBadgeStorageConfig::PRODUCT_BADGE_PUBLISH
    ? $this->eventBehaviorFacade->getEventTransferIds($eventEntityTransfers)
    : $this->eventBehaviorFacade->getEventTransferForeignKeys($eventEntityTransfers, 'spy_product_badge.fk_product_abstract');
// ... load badges per ID, then
$productBadgeStorageEntity->setData($productBadgeStorageTransfer->toArray());
$productBadgeStorageEntity->save();   // the behavior's postSave() sends the sync message
```

Registration (excerpt `PublisherDependencyProvider`):

```php
protected function getProductBadgeStoragePlugins(): array
{
    return [
        ProductBadgeStorageConfig::PUBLISH_PRODUCT_BADGE => [   // array key = custom publish queue
            new ProductBadgeWritePublisherPlugin(),
        ],
    ];
}
```

Real in the release (`PublisherDependencyProvider::getPublishAndSynchronizeHealthCheckPlugins()`): queue key plus storage and search plugin. The module `vendor/spryker/publish-and-synchronize-health-check-storage` is the smallest complete reference module (schema, writer, publisher/trigger plugin). Observation: no sync data plugin is registered for the health check in the Pyz `SynchronizationDependencyProvider`, so `sync:data` cannot re-export the resource.

## Pitfalls

1. Queue entered only in `RabbitMqConfig` or only in `QueueDependencyProvider`: messages pile up or are never processed. Both plus `queue:setup`.
2. Forgot `propel:install` after a schema/behavior change: no events, no sync messages.
3. `queue_pool` together with the `store` parameter: `InvalidConfigurationException`. The abstract table of ProductStorage has `store` and cannot have a `queue_pool`; `spy_product_concrete_storage` has only `locale` and carries it (`src/Pyz/Zed/ProductStorage/Persistence/Propel/Schema/spy_product_storage.schema.xml`).
4. `resource` missing: `MissingAttributeException`. Table name without `_storage`/`_search` suffix: direct sync target detection empty.
5. `sync:data product_abstract --ids=1`: the option `--ids` does not exist for `sync:data` (Symfony reports an unknown option). The IDs are the second positional argument: `sync:data product_abstract 1,2`. Only `publish:trigger-events` knows `-i|--ids`.
6. Event logger off: listener errors only appear generically, messages land in `*.error`.
7. Direct sync enabled, but `SPRYKER_DYNAMIC_STORE_MODE` not set: it stays with queue sync.
8. Large publisher runs consume memory (Propel instance pooling); mind `EventConfig::isInstancePoolingAllowed()` or `isRepositorySyncExportPropelInstancePoolingDisabled()`.
9. Several sync workers: only the `OutdatedValidator` catches ordering problems (with `getQueueWorkerNumber() > 1`).
10. Same data saved again: `setData()` with an identical value changes nothing, `postSave()` only runs with `$affectedRows`, no sync message is created. Practical consequence: `publish:trigger-events -r <resource>` does not refill a manually deleted Redis key; use `sync:data <resource>` for that `[live verified (202610.0)]`.
11. Upgrader anti-patterns: changes in `vendor/spryker/*-storage|*-search`, copying foreign publisher plugins into Pyz instead of registering them, changing `src/Orm`/`src/Generated` by hand ([Upgradability](28-upgradability.md)).
12. **Deprecations:**
    - `[Deprecated]` `EventTriggerConsole` (`event:trigger`): "Use PublisherTriggerEventsConsole instead" (`publish:trigger-events`), not registered in the project.
    - Subscriber/listener model (`*EventSubscriber`, `Communication/Plugin/Event/Listener/*`): still active in the release (approx. 50 entries in `src/Pyz/Zed/Event/EventDependencyProvider.php`), the docs call listeners legacy. Mixed operation (ProductStorage has a subscriber and a publisher plugin). New: publisher plugin; do not migrate existing ones without need. `[Assumption]` formal `@deprecated` marking not checked class by class.
    - `EventBehaviorDependencyProvider::getEventTriggerResourcePlugins()` (`*EventResource*Plugin`): legacy counterpart of `getPublisherTriggerPlugins()`, both are populated in the release.
    - `[Deprecated]` `SynchronizationDataRepositoryPluginInterface` ("Use SynchronizationDataBulkRepositoryPluginInterface instead", checked in the code). The QueryContainer variant `SynchronizationDataQueryContainerPluginInterface` does not carry this tag. New plugins: `…BulkRepositoryPluginInterface`.
    - `[Deprecated]` synchronization behavior `mapping_resource`: "Will be removed without replacement", use `mappings` instead. Behavior methods `isSendingToQueue()`/`setIsSendingToQueue()`: "Use SynchronizationBehaviorConfig::isSynchronizationEnabled()".
    - The debug docs still talk about `{Entity}PublishListener` (outdated compared to the plugin model).

## Checklist

- [ ] Source table with `event` behavior, mirror table `*_storage`/`*_search` with `synchronization` behavior (`resource`, `key_suffix_column`, `queue_group`).
- [ ] Event/queue/resource constants in the shared config.
- [ ] Publisher plugin, trigger plugin **and** sync data plugin present.
- [ ] `resource` identical in schema, trigger plugin, sync plugin and reader.
- [ ] Registered: `PublisherDependencyProvider` (plugins and triggers), `SynchronizationDependencyProvider`, `RabbitMqConfig` (publish + sync), `QueueDependencyProvider`.
- [ ] `propel:install`, `transfer:generate`, `queue:setup` executed.
- [ ] Writer bulk-oriented, deletes the mirror entity via `delete()`.
- [ ] Checked end to end: mirror table, Redis key, reader.

## Sources

Docs (base https://docs.spryker.com/docs/dg/dev/backend-development/data-manipulation/data-publishing/):
- publish-and-synchronization.html, implement-publish-and-synchronization.html (as of 20.08.2026)
- add-publish-events.html, implement-event-trigger-publisher-plugins.html, implement-synchronization-plugins.html (as of 16.06.2021)
- publish-and-synchronize-re-synchronization-and-re-generation.html (as of 18.09.2025), publish-and-synchronize-debugging.html (as of 18.09.2025)
- configurartion/configure-direct-synchronize.html (the typo in the path is original, as of 05.08.2026)
- not evaluated: Handle data with Publish and Synchronization, Debug listeners, Synchronization behavior enabling multiple mappings, Publish and synchronize and multi-store ([Multi-Store](19-multi-store-i18n.md)).

Code:
- `vendor/spryker/event/src/Spryker/Zed/Event/{Business/Queue/Consumer/EventQueueConsumer.php,Communication/Plugin/Console/EventListenerDumpConsole.php,EventConfig.php,Dependency/Plugin/*}`
- `vendor/spryker/event-behavior/src/Spryker/Zed/EventBehavior/{Persistence/Propel/Behavior/EventBehavior.php,Business/Model/EventResourceRepositoryManager.php,Business/Model/EventEntityTransferFilter.php,Communication/Plugin/*,Dependency/Plugin/*}`
- `vendor/spryker/publisher/src/Spryker/Zed/Publisher/{PublisherDependencyProvider.php,Business/Collator/PublisherEventCollator.php,Communication/Plugin/Event/PublisherSubscriber.php,Communication/Console/PublisherTriggerEventsConsole.php}`
- `vendor/spryker/publisher-extension/…/Dependency/Plugin/{PublisherPluginInterface,PublisherTriggerPluginInterface}.php`
- `vendor/spryker/synchronization/src/Spryker/Zed/Synchronization/{Business/Export/RepositoryExporter.php,Communication/Console/ExportSynchronizedDataConsole.php,Persistence/Propel/Formatter/SynchronizationDataTransferObjectFormatter.php}`, `…/Service/Synchronization/Plugin/{BaseKeyGenerator,DefaultKeyGeneratorPlugin}.php`
- `vendor/spryker/synchronization-extension/…/Dependency/Plugin/*`, `vendor/spryker/synchronization-behavior/…/Behavior/SynchronizationBehavior.php`
- `vendor/spryker/publish-and-synchronize-health-check-storage/src/Spryker/**`, `vendor/spryker/asset-storage/**`, `vendor/spryker/product-label-storage/…/ProductAbstractLabelPublisherTriggerPlugin.php`
- Project: `src/Pyz/Zed/{Publisher,Event,EventBehavior,Synchronization,SynchronizationBehavior,Queue,Console,ProductStorage}/*`, `src/Pyz/Client/RabbitMq/RabbitMqConfig.php`, `config/Shared/config_default.php`, `config_default-docker*.php`

Open gaps: lifecycle of `spy_event_behavior_entity_change` (cleanup, timeout logic) only checked superficially, formal deprecation of the subscribers not checked class by class.

Neighboring references: [Propel](05-propel-persistence.md), [Client/Storage/Search](08-client-storage-search.md), [Console/Cron/Queue](22-console-cron-queue.md), [Upgradability](28-upgradability.md). Templates: [publisher-and-synchronization](../templates/publisher-and-synchronization/README.md), [transfer-and-propel](../templates/transfer-and-propel/README.md).
