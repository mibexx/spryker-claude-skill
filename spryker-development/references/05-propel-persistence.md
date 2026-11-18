# Propel schema and persistence layer

> Status: Release b2b-demo-shop 202610.0 (spryker/kernel 3.87.1, propel/propel 2.0.1 according to `vendor/composer/installed.json`). Source code read directly. The docs pages (docs.spryker.com) only returned the navigation skeleton when fetched, no technical content; statements come from the code `[code-verified, docs not cross-checked]`. Console commands were derived from the console classes and `config/install/docker.yml`; all commands were executed in the container during live verification `[live verified (202610.0)]`, including a real migration run with a throwaway schema (diff, migrate, rollback). Not executed because destructive: `propel:tables:drop`, `propel:database:drop`, `propel:sql:insert`.

## Purpose

Persistence layer of a Zed module: Propel schema (`*.schema.xml`), Repository (read), EntityManager (write), Mapper (Entity <-> Transfer), migrations and indexes. Transfers: [Transfer](04-transfer.md). Behaviors `event` and `synchronization`: [Publish & Sync](07-publish-sync.md). Module structure (Facade, Factory): [Project module](02-project-module.md).

## Concepts

- **Schema locations** (`vendor/spryker/propel/src/Spryker/Zed/Propel/PropelConfig.php`): project `src/*/Zed/*/Persistence/Propel/Schema/`, core `vendor/*/*/src/*/Zed/*/Persistence/Propel/Schema/`. During `propel:schema:copy`, core and project files with the **same file name** are merged by the `PropelSchemaMerger` into one file in `src/Orm/Propel/Schema/` (182 files in the release). Prerequisite: identical triple `name` | `package` | `namespace`, otherwise `SchemaMergeException: Ambiguous use of name, package and namespace`.
- **Extending a core table** = project schema with the same file name and only the delta. Real: `src/Pyz/Zed/ProductGroup/Persistence/Propel/Schema/spy_product.schema.xml` adds `spy_product_abstract.color_code`.
- **New project tables:** prefix `pyz_` instead of `spy_` with an explicit `phpName` (example `pyz_example_state_machine_item` / `PyzExampleStateMachineItem`). The running example ProductBadge uses `spy_product_badge` (contract of the templates). 161 Pyz schemas in the release.
- **Generated directories** (never edit, do not commit): `src/Orm/Propel/Schema` (merge result), `src/Orm/Zed/<Module>/Persistence/{Base,Map}` and `Spy*.php`/`Spy*Query.php`, `src/Generated/Shared/Transfer/Spy*EntityTransfer.php`.
- **Behaviors** (occurrences in core schemas): `timestampable` (220x, standard Propel), `synchronization` (91x), `uuid` (39x), `archivable` (8x), `event` (5x in the core, 50 Pyz schema files with `behavior name="event"`), `query_cache` (3x).
  - `event`: `…/event-behavior/…/Behavior/EventBehavior.php`. Parameters `name`, `column` (`*` = all, otherwise a column), optionally `value`/`operator`, `keep-original="true"` (also deliver the old value). Details [Publish & Sync](07-publish-sync.md).
  - `synchronization`: `…/synchronization-behavior/…/SynchronizationBehavior.php` (`resource`, `key_suffix_column`, `queue_group`, `queue_pool`, `store`, `locale`, `mappings` …).
  - `uuid`: `…/uuid-behavior/…/UuidBehavior.php` (`key_prefix`, `key_columns`, creates the column `uuid` and the unique index `…-unique-uuid`).
- **Repository / EntityManager / QueryContainer:**
  - `Spryker\Zed\Kernel\Persistence\AbstractRepository`: read only, returns Transfers, never entities.
  - `AbstractEntityManager`: write only (save/delete). `save(EntityTransferInterface)` maps onto the entity via `TransferToEntityMapper`.
  - `AbstractQueryContainer`: legacy, returns Propel queries. Not marked `@deprecated` in the kernel, still in use in Pyz (`ExampleStateMachine`, `ProductStorage`). Recommendation `[Assumption]` derived from the structure of newer core modules (ProductAlternative): new modules only with Repository + EntityManager, QueryContainer only when extending legacy modules.
  - Both hang off the `PersistenceFactory` (`AbstractPersistenceFactory`), which creates the queries (`SpyXxxQuery::create()`). Foreign queries come via the `DependencyProvider` (`providePersistenceLayerDependencies()`, example `ProductAlternativeDependencyProvider::PROPEL_QUERY_PRODUCT`).
  - Access only from the business layer via `getRepository()` / `getEntityManager()` of the Factory/Facade. Only the persistence layer knows `Orm\…` classes.
- **Mapping:** Mapper in `Persistence/Propel/Mapper/` (standard, example `ProductAlternativeMapper`), created in the PersistenceFactory. Alternative: entity transfers (`Spy…EntityTransfer`, `transfer:entity:generate`).
- **Indexes:** Postgres does not create indexes for foreign keys. `spryker/index-generator` provides `propel:postgres-indexes:generate` and writes `src/Pyz/Zed/IndexGenerator/Persistence/Propel/Schema/*.schema.xml` (do not maintain by hand). Name limit `PropelConfig::POSTGRES_INDEX_NAME_MAX_LENGTH = 63`, checked by `propel:schema:validate-xml-names`. `Pyz\Zed\Propel\PropelDependencyProvider::getPropelSchemaElementFilterPlugins()` registers `ForeignKeyIndexPropelSchemaElementFilterPlugin` (removes redundant FK indexes when a unique index covers the FK column). Engine: MySQL/MariaDB or PostgreSQL (`SPRYKER_DB_ENGINE`, default MySQL, `config/Shared/config_default.php`).

## Instructions

1. **Create the schema:** `src/Pyz/Zed/<Module>/Persistence/Propel/Schema/<table>.schema.xml`. Header with `xmlns="spryker:schema-01"` and `https://static.spryker.com/schema-01.xsd`. Namespace `Orm\Zed\<Module>\Persistence`, package `src.Orm.Zed.<Module>.Persistence`.
2. **Conventions:** PK `id_<table>`, FK `fk_<target>`, sequence `<table>_pk_seq` (`id-method-parameter`), FK and index names at most 63 characters. A new mandatory column (`required="true"`) in a core table only with a default or nullable.
3. **Generate migration and classes** (all five executed `[live verified (202610.0)]`; without a schema change `propel:diff` ("no diff to generate") and `propel:migrate` ("nothing to migrate") are no-ops; with a change, `diff` creates `src/Orm/Propel/Migration_<engine>/PropelMigration_<ts>.php` and `migrate` executes it):
   ```bash
   docker/sdk cli console propel:schema:copy
   docker/sdk cli console propel:diff
   docker/sdk cli console propel:migrate
   docker/sdk cli console propel:model:build
   docker/sdk cli console transfer:entity:generate
   ```
   Short form `docker/sdk cli console propel:install` (complete run, see below).
4. **Create persistence classes:** `PersistenceFactory` (query + mapper), `Repository` (+ interface), `EntityManager` (+ interface), Mapper. Reference: [zed-module](../templates/zed-module/README.md).
5. **Facade/Business** only accesses the Repository and EntityManager.

**Command sequence**
- `propel:install` (`PropelInstallConsole::getDependingCommands()`): `propel:database:create`, `propel:pg-sql-compat`, `propel:schema:copy`, `propel:model:build`, `transfer:entity:generate`, `propel:diff` (not with `--no-diff`), `propel:migrate`.
- Docker SDK pipeline (`config/install/docker.yml`): build phase `propel:schema:copy` -> `propel:model:build` -> `transfer:entity:generate`. Init phase `propel:schema:copy` -> `propel:pg-sql-compat` -> `propel:migration:delete` -> `propel:tables:drop` -> `propel:diff` -> `propel:migrate` -> `propel:migration:delete` -> `setup:init-db`. So migration files are deleted there and not kept permanently (path according to `config/Shared/config_propel.php`: `src/Orm/Propel/Migration_<engine>`, not present in the installation).
- `propel:migrate` only knows the option `--migrate-to-version`.
- **Removing a schema again:** `propel:model:build` does not delete orphaned classes; `src/Orm/Zed/<Module>/` (incl. `Persistence/Base`, `Map`) remains and must be deleted by hand, then run `propel:schema:copy`, `propel:model:build`, `transfer:entity:generate`, `transfer:generate` again. The table itself remains in the DB until a migration removes it (`migration:down`).
- **Rollback:** Spryker ships no wrapper for `migration:down`. `vendor/bin/propel migration:down --config-dir=<folder>` works `[live verified (202610.0)]` (table gone, `propel_migration` cleaned up), but needs a `propel.json` in the `--config-dir`. `propel:config:convert` does **not** produce it in 202610.0 (exit 255, `ConfigMissingPropertyException: Could not find "phpConfDir"`, `config_propel.php` does not set `paths.phpConfDir`). Practical: create the file yourself, e.g. via a small PHP script in the container `json_encode(['propel' => Config::get(PropelConstants::PROPEL) + paths.phpConfDir])` to `/tmp/<folder>/propel.json`, then `migration:down`. For this, the migration file must still be in `src/Orm/Propel/Migration_<engine>/` (`propel:migration:delete` deletes it). Better: a backward-compatible follow-up migration (create the column as nullable first, remove it later).

Further commands (executed `[live verified (202610.0)]`, exceptions in the table):

| Command | Purpose |
|---|---|
| `propel:migration:check` | check pending migrations; **exit code 1 means: nothing to migrate** (also "No migration file found"), exit 0 only with pending migrations |
| `propel:migration:delete` | delete the migration folder |
| `propel:schema:validate`, `propel:schema:validate-xml-names` | schema validation (registered under `docker.dev`; not checked in other environments) |
| `propel:postgres-indexes:generate` | FK indexes for Postgres (the project runs locally on MariaDB; `propel:pg-sql-compat` reports "This command doesn't support chosen DB engine" there) |
| `propel:tables:drop`, `propel:database:drop`, `propel:database:create` | DB management (`propel:database:create` executed as part of `propel:install`; `tables:drop` and `database:drop` **not executed** (destructive); `propel:database:export`/`import` are **not registered**) |
| `propel:sql:build`, `propel:sql:insert`, `propel:config:convert` | SQL/config (`sql:build` executed; `sql:insert` not executed (overwrites tables); `config:convert` is broken in 202610.0, see rollback) |

## Examples

Complete files: [`transfer-and-propel`](../templates/transfer-and-propel/README.md) (schemas) and [`zed-module`](../templates/zed-module/README.md) (Repository, EntityManager, Mapper).

`src/Pyz/Zed/ProductBadge/Persistence/Propel/Schema/spy_product_badge.schema.xml`:

```xml
<?xml version="1.0"?>
<database xmlns="spryker:schema-01" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" name="zed" xsi:schemaLocation="spryker:schema-01 https://static.spryker.com/schema-01.xsd" namespace="Orm\Zed\ProductBadge\Persistence" package="src.Orm.Zed.ProductBadge.Persistence">

    <table name="spy_product_badge" identifierQuoting="true">
        <column name="id_product_badge" required="true" type="INTEGER" autoIncrement="true" primaryKey="true"/>
        <column name="fk_product_abstract" required="true" type="INTEGER"/>
        <column name="badge_key" required="true" type="VARCHAR" size="255"/>
        <column name="label" required="true" type="VARCHAR" size="255"/>

        <foreign-key name="spy_product_badge-fk_product_abstract" foreignTable="spy_product_abstract" phpName="ProductAbstract" refPhpName="ProductBadge">
            <reference local="fk_product_abstract" foreign="id_product_abstract"/>
        </foreign-key>

        <unique name="spy_product_badge-unique-fk_product_abstract-badge_key">
            <unique-column name="fk_product_abstract"/>
            <unique-column name="badge_key"/>
        </unique>

        <id-method-parameter value="spy_product_badge_pk_seq"/>

        <behavior name="event">
            <parameter name="spy_product_badge_all" column="*"/>
        </behavior>

        <behavior name="timestampable"/>
    </table>

</database>
```

Core reference Repository/EntityManager/Factory (`vendor/spryker/product-alternative/src/Spryker/Zed/ProductAlternative/Persistence/`, shortened):

```php
class ProductAlternativePersistenceFactory extends AbstractPersistenceFactory
{
    public function createProductAlternativePropelQuery(): SpyProductAlternativeQuery
    {
        return SpyProductAlternativeQuery::create();
    }

    public function createProductPropelQuery(): SpyProductQuery
    {
        return $this->getProvidedDependency(ProductAlternativeDependencyProvider::PROPEL_QUERY_PRODUCT);
    }
}

class ProductAlternativeRepository extends AbstractRepository implements ProductAlternativeRepositoryInterface
{
    public function findProductAlternativeByIdProductAlternative(int $idProductAlternative): ?ProductAlternativeTransfer
    {
        $entity = $this->getFactory()->createProductAlternativePropelQuery()
            ->filterByIdProductAlternative($idProductAlternative)
            ->findOne();

        if (!$entity) {
            return null;
        }

        return $this->getFactory()->createProductAlternativeMapper()->mapProductAlternativeTransfer($entity);
    }
}
```

Project extension of a core EntityManager, real (`src/Pyz/Zed/CustomerAccess/Persistence/CustomerAccessEntityManager.php`): `class CustomerAccessEntityManager extends SprykerCustomerAccessEntityManager implements CustomerAccessEntityManagerInterface`. Class resolution prefers Pyz over Spryker; a Pyz class with the same name overrides the core class ([Extending the core](03-extending-the-core.md)).

Event behavior with original value, real (`src/Pyz/Zed/Category/Persistence/Propel/Schema/spy_category.schema.xml`):

```xml
<behavior name="event">
    <parameter name="spy_category_node_all" column="*"/>
    <parameter name="spy_category_node_fk_parent_category_node" column="fk_parent_category_node" keep-original="true"/>
</behavior>
```

Synchronization behavior in the storage table (core `spy_product_quantity_storage`), the project only sets `queue_pool`:

```xml
<behavior name="synchronization">
    <parameter name="resource" value="product_quantity"/>
    <parameter name="key_suffix_column" value="fk_product"/>
    <parameter name="queue_group" value="sync.storage.product"/>
</behavior>
```

## Pitfalls

- Editing a core schema in `vendor/`: instead use a project schema with the same file name and identical `name`/`package`/`namespace`. Do not copy the schema file into Pyz, only the delta.
- Forgetting `propel:schema:copy` + `propel:model:build` after a schema change: classes and `filterByXxx()` are missing. After a behavior change, `propel:install`/`propel:model:build` is mandatory (the hooks live in the generated code).
- Never change `src/Orm` and `src/Generated` manually.
- Passing entities across layer boundaries: Repository/EntityManager return Transfers, the business layer knows no Propel classes.
- No queries in Facade, Business or Communication, no Propel calls in plugins outside Persistence. Foreign queries via the `DependencyProvider` or via the other module's Facade.
- Avoid N+1: `joinWith…`/`use…Query()` in the Repository instead of queries in loops.
- Name/namespace deviation from the core schema: `SchemaMergeException`.
- Index/FK names over 63 characters break on Postgres.
- `findOneOrCreate()` with a `null` ID filters on `IS NULL` and creates a new record: for updates only use it with a set ID.
- Event behavior with `column="*"` generates event load (Publish & Sync): only where needed. A DB-side `ON DELETE CASCADE` does not trigger entity events.
- Outdated schema header `xsi:noNamespaceSchemaLocation="http://xsd.propelorm.org/1.6/database.xsd"` (e.g. Pyz ProductGroup): works during the merge, but do not imitate it.
- Upgrader anti-patterns: changing core schemas/classes in `vendor`, copying a core Repository into Pyz instead of inheriting, placing migration files manually in the core ([Upgradability](28-upgradability.md)).
- Deprecations: `PropelBusinessFactory::create…Console()` is partly `@deprecated` ("Please add the Command directly to your ConsoleDependencyProvider", e.g. `createPropelInstallConsole`); register consoles directly in `src/Pyz/Zed/Console/ConsoleDependencyProvider.php`. The `AbstractQueryContainer` is legacy, but without a `@deprecated` tag.

## Checklist

- [ ] Schema in `Persistence/Propel/Schema/`, `spryker:schema-01` header, correct namespace/package.
- [ ] Core table extended: same file name, only the delta, columns nullable or with default.
- [ ] PK/FK/sequence names according to convention, index names at most 63 characters.
- [ ] `propel:schema:copy`, `propel:diff`, `propel:migrate`, `propel:model:build`, `transfer:entity:generate` (or `propel:install`) have run.
- [ ] Repository reads, EntityManager writes, both return/accept Transfers.
- [ ] Mapper instead of passing entities, no queries outside Persistence.
- [ ] Behavior decision checked (`event`, `synchronization`, `timestampable`, `uuid`), then `propel:install`.
- [ ] Nothing changed in `vendor/`, `src/Orm`, `src/Generated`.

## Sources

Docs (navigation checked, technical content not retrievable), base https://docs.spryker.com/docs/dg/dev/backend-development/:
- zed/persistence-layer/persistence-layer.html, `…/database-schema-definition.html`, `…/repository.html`, `…/entity-manager.html`
- data-manipulation/run-and-revert-a-database-migration.html (fetch: redirect only)
- zed/communication-layer/add-indexes-to-foreign-key-columns-index-generator.html (not fetched separately)

Code:
- `vendor/spryker/propel/src/Spryker/Zed/Propel/{PropelConfig,PropelDependencyProvider}.php`, `Business/Model/PropelSchemaMerger.php`, `Communication/Console/*Console.php`, `Communication/Plugin/Propel/ForeignKeyIndexPropelSchemaElementFilterPlugin.php`
- `vendor/spryker/kernel/src/Spryker/Zed/Kernel/Persistence/{AbstractRepository,AbstractEntityManager,AbstractQueryContainer,AbstractPersistenceFactory}.php`, `…/EntityManager/TransferToEntityMapper.php`
- `vendor/spryker/{event-behavior,synchronization-behavior,uuid-behavior}/…/Behavior/*.php`, `vendor/spryker/index-generator/src/Spryker/Zed/IndexGenerator/`
- `vendor/spryker/product-alternative/src/Spryker/Zed/ProductAlternative/Persistence/` (reference module)
- `config/Shared/config_propel.php`, `config/install/docker.yml`, `src/Pyz/Zed/Propel/*`, `src/Pyz/Zed/ExampleStateMachine/Persistence/*`, `src/Pyz/Zed/CustomerAccess/Persistence/*`

Open gaps: docs technical content, exact registration condition of `PostgresIndexGeneratorConsole` and the schema validators in `ConsoleDependencyProvider`.

Neighbour references: [Project module](02-project-module.md), [Extending the core](03-extending-the-core.md), [Transfer](04-transfer.md), [Publish & Sync](07-publish-sync.md), [Docker SDK](30-docker-sdk-workflow.md), [Upgradability](28-upgradability.md). Templates: [transfer-and-propel](../templates/transfer-and-propel/README.md), [zed-module](../templates/zed-module/README.md).
