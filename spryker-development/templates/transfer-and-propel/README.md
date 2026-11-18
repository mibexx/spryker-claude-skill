# Template transfer-and-propel

Transfer definitions and Propel schemas for the running example **ProductBadge**: source table with `event` behavior, storage table with `synchronization` behavior, associated transfers. Background: [Transfer](../../references/04-transfer.md), [Propel](../../references/05-propel-persistence.md), [Publish & Sync](../../references/07-publish-sync.md).

## File list (target path below the project root)

| File | Content |
|---|---|
| `src/Pyz/Shared/ProductBadge/Transfer/product_badge.transfer.xml` | `ProductBadge`, `ProductBadgeCollection`, `ProductBadgeCriteria` (all `strict="true"`) |
| `src/Pyz/Shared/ProductBadgeStorage/Transfer/product_badge_storage.transfer.xml` | `ProductBadgeStorage` (payload of the storage table / the Redis value) |
| `src/Pyz/Zed/ProductBadge/Persistence/Propel/Schema/spy_product_badge.schema.xml` | table `spy_product_badge`, FK to `spy_product_abstract`, behaviors `event` and `timestampable` |
| `src/Pyz/Zed/ProductBadgeStorage/Persistence/Propel/Schema/spy_product_badge_storage.schema.xml` | table `spy_product_badge_storage`, behaviors `synchronization` and `timestampable` |

## Fixed names (apply to all ProductBadge templates)

| Item | Value |
|---|---|
| Source table / entity | `spy_product_badge` / `Orm\Zed\ProductBadge\Persistence\SpyProductBadge(Query)` |
| Storage table / entity | `spy_product_badge_storage` / `Orm\Zed\ProductBadgeStorage\Persistence\SpyProductBadgeStorage(Query)` |
| Resource name (`resource` in the behavior) | `product_badge` |
| Storage key (Redis, without internal prefix `kv:`) | `product_badge:<id_product_abstract>`, e.g. `product_badge:123` (no store, no locale; key suffix column `fk_product_abstract`) |
| Sync queue (`queue_group`) | `sync.storage.product_badge` |
| Publish queue | `publish.product_badge` |
| Entity events (event behavior, `column="*"`) | `Entity.spy_product_badge.create`, `Entity.spy_product_badge.update`, `Entity.spy_product_badge.delete` |
| Publish event (trigger plugin, regeneration) | `ProductBadge.product_badge.publish` |

Redis value (JSON, extended with `_timestamp` by the behavior): `{"id_product_abstract":123,"product_badges":[{"id_product_badge":1,"fk_product_abstract":123,"badge_key":"new","label":"New"}]}`.

## Placeholders / customization

- Replace `ProductBadge` / `product_badge` with your own module name (transfer and schema file names, namespace `Orm\Zed\<Module>\Persistence`, table name, behavior parameter names, resource, queue names).
- Table prefix: the running example contracts use `spy_`. For real project tables the skill recommends `pyz_` with an explicit `phpName` (see [Propel](../../references/05-propel-persistence.md)). Then the table name, entity classes and event names change (`Entity.pyz_product_badge.*`).
- Foreign key name and index names at most 63 characters (Postgres).
- Storage table: the table name must end in `_storage` (target Redis) or `_search` (target Search).
- Do not declare the columns `data` and `key` (unique); the `synchronization` behavior adds them (`modifyTable()`).
- No `store`/`locale` parameter, because the key is store- and locale-independent. With `store`, `queue_pool` would not be allowed (`InvalidConfigurationException`).
- **`queue_pool` is mandatory here** (`synchronizationPool`, as with the Pyz storage tables and in `RabbitMqConfig::getQueuePools()`). Without a pool the behavior does write to the queue `sync.storage.product_badge`, but `sync:data product_badge` aborts with `SynchronizationQueuePoolNotFoundException` (found live, see [publisher-and-synchronization](../publisher-and-synchronization/README.md)).

## Registration

No separate registration. Schema files are found via the glob `src/*/Zed/*/Persistence/Propel/Schema/` and transfer files via `src/*/Shared/*/Transfer/`. The queue/plugin registration is in the template [publisher-and-synchronization](../publisher-and-synchronization/README.md).

## Commands afterwards `[live verified (202610.0)]`

```bash
docker/sdk cli console transfer:generate
docker/sdk cli console transfer:validate -vv
docker/sdk cli console propel:schema:copy
docker/sdk cli console propel:diff
docker/sdk cli console propel:migrate
docker/sdk cli console propel:model:build
docker/sdk cli console transfer:entity:generate
```

Alternatively `docker/sdk cli console propel:install` (complete run incl. entity transfers; without changes a no-op with exit 0).

Live result in shop 202610.0 (all exit 0): `propel:diff` reports `2 added tables` and creates `src/Orm/Propel/Migration_mysql/PropelMigration_<ts>.php` (engine MySQL/MariaDB; the directory is git-ignored), `propel:migrate` executes `4 of 4 SQL statements` and creates `spy_product_badge` and `spy_product_badge_storage` (the storage table gets `data`, `key`, `alias_keys` with a unique index on `key` from the `synchronization` behavior), afterwards `propel:diff` reports `Same XML and database structures`. `propel:model:build` and `transfer:entity:generate` generate `src/Orm/Zed/ProductBadge*` and `SpyProductBadge(Storage)EntityTransfer`. Both entity namespaces are located under `src/Orm/Zed/<Module>/Persistence`. After changing the behaviors, `propel:install` or `propel:model:build` is mandatory, otherwise the event and sync hooks are missing in the entities.

## Model sources

- Transfer: `src/Pyz/Shared/ProductStorage/Transfer/product_storage.transfer.xml` (header line), `vendor/spryker/asset-storage/src/Spryker/Shared/AssetStorage/Transfer/asset_storage.transfer.xml` (`strict="true"`).
- Source schema: `vendor/spryker/product-alternative/.../spy_product_alternative.schema.xml` (FK to `spy_product_abstract`), `vendor/spryker/asset/.../spy_asset.schema.xml` (event behavior).
- Storage schema: `vendor/spryker/asset-storage/.../spy_asset_slot_storage.schema.xml`, `vendor/spryker/publish-and-synchronize-health-check-storage/.../spy_publish_and_synchronize_health_check_storage.schema.xml`.

## Verification status

Schema headers, behavior parameters and attributes checked against the named models and `SynchronizationBehavior`/`EventBehavior` in release 202610.0 (`modifyTable()` adds `data`/`key`; foreign keys of the source schema end up as `foreignKeys` in the event). Walked through in shop 202610.0: `transfer:generate`, `transfer:validate -vv`, `propel:schema:copy`, `propel:diff`, `propel:migrate`, `propel:model:build`, `transfer:entity:generate`, `propel:install` (verified live in release 202610.0). Correction from the run: `queue_pool` added to the storage schema. `[live verified (202610.0)]`
