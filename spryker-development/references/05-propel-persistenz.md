# Propel-Schema und Persistenz-Layer (Recherche T-05)

> Status: Release b2b-demo-shop 202610.0 (spryker/kernel 3.87.1, propel/propel 2.0.1 laut `vendor/composer/installed.json`). Quellcode direkt gelesen. Die Doku-Seiten (docs.spryker.com) lieferten per Abruf nur Navigationsgerüst, keine Fachinhalte; Aussagen stammen aus dem Code `[code-verifiziert, Doku nicht abgeglichen]`. Konsolenbefehle wurden aus den Console-Klassen und `config/install/docker.yml` abgeleitet; alle Befehle wurden in der Live-Verifikation im Container ausgeführt `[live verifiziert (202610.0)]` (Protokoll `live-verification.md`, Abschnitt 1.1), auch ein echter Migrationslauf mit Wegwerf-Schema (diff, migrate, Rollback). Nicht ausgeführt, weil zerstörend: `propel:tables:drop`, `propel:database:drop`, `propel:sql:insert`.

## Zweck

Persistenz-Layer eines Zed-Moduls: Propel-Schema (`*.schema.xml`), Repository (lesen), EntityManager (schreiben), Mapper (Entity <-> Transfer), Migrationen und Indizes. Transfers: [Transfer](04-transfer.md). Behaviors `event` und `synchronization`: [Publish & Sync](07-publish-sync.md). Modulaufbau (Facade, Factory): [Projektmodul](02-projektmodul.md).

## Konzepte

- **Schema-Fundorte** (`vendor/spryker/propel/src/Spryker/Zed/Propel/PropelConfig.php`): Projekt `src/*/Zed/*/Persistence/Propel/Schema/`, Core `vendor/*/*/src/*/Zed/*/Persistence/Propel/Schema/`. Beim `propel:schema:copy` werden Core- und Projekt-Dateien mit **gleichem Dateinamen** vom `PropelSchemaMerger` zu einer Datei in `src/Orm/Propel/Schema/` verschmolzen (182 Dateien im Release). Voraussetzung: identisches Tripel `name` | `package` | `namespace`, sonst `SchemaMergeException: Ambiguous use of name, package and namespace`.
- **Core-Tabelle erweitern** = Projekt-Schema mit gleichem Dateinamen und nur dem Delta. Echt: `src/Pyz/Zed/ProductGroup/Persistence/Propel/Schema/spy_product.schema.xml` ergänzt `spy_product_abstract.color_code`.
- **Neue Projekt-Tabellen:** Präfix `pyz_` statt `spy_` mit explizitem `phpName` (Beispiel `pyz_example_state_machine_item` / `PyzExampleStateMachineItem`). Das Laufbeispiel ProductBadge nutzt `spy_product_badge` (Vertrag der Templates). 161 Pyz-Schemas im Release.
- **Generierte Verzeichnisse** (nie editieren, nicht committen): `src/Orm/Propel/Schema` (Merge-Ergebnis), `src/Orm/Zed/<Modul>/Persistence/{Base,Map}` und `Spy*.php`/`Spy*Query.php`, `src/Generated/Shared/Transfer/Spy*EntityTransfer.php`.
- **Behaviors** (Vorkommen in Core-Schemas): `timestampable` (220x, Standard-Propel), `synchronization` (91x), `uuid` (39x), `archivable` (8x), `event` (5x im Core, 50 Pyz-Schema-Dateien mit `behavior name="event"`), `query_cache` (3x).
  - `event`: `…/event-behavior/…/Behavior/EventBehavior.php`. Parameter `name`, `column` (`*` = alle, sonst Spalte), optional `value`/`operator`, `keep-original="true"` (alten Wert mitliefern). Details [Publish & Sync](07-publish-sync.md).
  - `synchronization`: `…/synchronization-behavior/…/SynchronizationBehavior.php` (`resource`, `key_suffix_column`, `queue_group`, `queue_pool`, `store`, `locale`, `mappings` …).
  - `uuid`: `…/uuid-behavior/…/UuidBehavior.php` (`key_prefix`, `key_columns`, legt Spalte `uuid` und Unique-Index `…-unique-uuid` an).
- **Repository / EntityManager / QueryContainer:**
  - `Spryker\Zed\Kernel\Persistence\AbstractRepository`: nur lesen, gibt Transfers zurück, nie Entities.
  - `AbstractEntityManager`: nur schreiben (save/delete). `save(EntityTransferInterface)` mappt über `TransferToEntityMapper` auf die Entity.
  - `AbstractQueryContainer`: Legacy, gibt Propel-Queries zurück. Im Kernel nicht als `@deprecated` markiert, in Pyz noch im Einsatz (`ExampleStateMachine`, `ProductStorage`). Empfehlung `[Annahme]` abgeleitet aus dem Aufbau neuerer Core-Module (ProductAlternative): neue Module nur mit Repository + EntityManager, QueryContainer nur beim Erweitern von Legacy-Modulen.
  - Beide hängen an der `PersistenceFactory` (`AbstractPersistenceFactory`), die die Queries erzeugt (`SpyXxxQuery::create()`). Fremde Queries kommen über den `DependencyProvider` (`providePersistenceLayerDependencies()`, Beispiel `ProductAlternativeDependencyProvider::PROPEL_QUERY_PRODUCT`).
  - Zugriff nur aus der Business-Schicht über `getRepository()` / `getEntityManager()` der Factory/Facade. Nur die Persistence-Schicht kennt `Orm\…`-Klassen.
- **Mapping:** Mapper in `Persistence/Propel/Mapper/` (Standard, Beispiel `ProductAlternativeMapper`), erzeugt in der PersistenceFactory. Alternative: Entity-Transfers (`Spy…EntityTransfer`, `transfer:entity:generate`).
- **Indizes:** Postgres legt für Fremdschlüssel keine Indizes an. `spryker/index-generator` stellt `propel:postgres-indexes:generate` bereit und schreibt `src/Pyz/Zed/IndexGenerator/Persistence/Propel/Schema/*.schema.xml` (nicht von Hand pflegen). Namenslimit `PropelConfig::POSTGRES_INDEX_NAME_MAX_LENGTH = 63`, geprüft von `propel:schema:validate-xml-names`. `Pyz\Zed\Propel\PropelDependencyProvider::getPropelSchemaElementFilterPlugins()` registriert `ForeignKeyIndexPropelSchemaElementFilterPlugin` (entfernt redundante FK-Indizes, wenn ein Unique-Index die FK-Spalte abdeckt). Engine: MySQL/MariaDB oder PostgreSQL (`SPRYKER_DB_ENGINE`, Default MySQL, `config/Shared/config_default.php`).

## Anleitung

1. **Schema anlegen:** `src/Pyz/Zed/<Modul>/Persistence/Propel/Schema/<tabelle>.schema.xml`. Kopf mit `xmlns="spryker:schema-01"` und `https://static.spryker.com/schema-01.xsd`. Namespace `Orm\Zed\<Modul>\Persistence`, Package `src.Orm.Zed.<Modul>.Persistence`.
2. **Konventionen:** PK `id_<tabelle>`, FK `fk_<ziel>`, Sequenz `<tabelle>_pk_seq` (`id-method-parameter`), FK- und Index-Namen höchstens 63 Zeichen. Neue Pflichtspalte (`required="true"`) in einer Core-Tabelle nur mit Default oder nullable.
3. **Migration und Klassen erzeugen** (alle fünf ausgeführt `[live verifiziert (202610.0)]`; ohne Schemaänderung sind `propel:diff` ("no diff to generate") und `propel:migrate` ("nothing to migrate") No-Ops, mit Änderung erzeugt `diff` `src/Orm/Propel/Migration_<engine>/PropelMigration_<ts>.php` und `migrate` führt sie aus):
   ```bash
   docker/sdk cli console propel:schema:copy
   docker/sdk cli console propel:diff
   docker/sdk cli console propel:migrate
   docker/sdk cli console propel:model:build
   docker/sdk cli console transfer:entity:generate
   ```
   Kurzform `docker/sdk cli console propel:install` (Komplettlauf, siehe unten).
4. **Persistence-Klassen anlegen:** `PersistenceFactory` (Query + Mapper), `Repository` (+ Interface), `EntityManager` (+ Interface), Mapper. Referenz: [zed-module](../templates/zed-module/README.md).
5. **Facade/Business** greift nur auf Repository und EntityManager zu.

**Ablauf der Befehle**
- `propel:install` (`PropelInstallConsole::getDependingCommands()`): `propel:database:create`, `propel:pg-sql-compat`, `propel:schema:copy`, `propel:model:build`, `transfer:entity:generate`, `propel:diff` (nicht mit `--no-diff`), `propel:migrate`.
- Docker-SDK-Pipeline (`config/install/docker.yml`): Build-Phase `propel:schema:copy` -> `propel:model:build` -> `transfer:entity:generate`. Init-Phase `propel:schema:copy` -> `propel:pg-sql-compat` -> `propel:migration:delete` -> `propel:tables:drop` -> `propel:diff` -> `propel:migrate` -> `propel:migration:delete` -> `setup:init-db`. Migrationsdateien werden dort also gelöscht und nicht dauerhaft aufbewahrt (Pfad laut `config/Shared/config_propel.php`: `src/Orm/Propel/Migration_<engine>`, in der Installation nicht vorhanden).
- `propel:migrate` kennt nur die Option `--migrate-to-version`.
- **Schema wieder entfernen:** `propel:model:build` löscht verwaiste Klassen nicht; `src/Orm/Zed/<Modul>/` (inkl. `Persistence/Base`, `Map`) bleibt liegen und muss von Hand gelöscht werden, danach `propel:schema:copy`, `propel:model:build`, `transfer:entity:generate`, `transfer:generate` erneut. Die Tabelle selbst bleibt in der DB, bis eine Migration sie entfernt (`migration:down`).
- **Rollback:** Spryker bringt keinen Wrapper für `migration:down`. `vendor/bin/propel migration:down --config-dir=<Ordner>` funktioniert `[live verifiziert (202610.0)]` (Tabelle weg, `propel_migration` bereinigt), braucht aber eine `propel.json` im `--config-dir`. `propel:config:convert` liefert sie in 202610.0 **nicht** (Exit 255, `ConfigMissingPropertyException: Could not find "phpConfDir"`, `config_propel.php` setzt `paths.phpConfDir` nicht). Praktikabel: Datei selbst erzeugen, z. B. per kleinem PHP-Skript im Container `json_encode(['propel' => Config::get(PropelConstants::PROPEL) + paths.phpConfDir])` nach `/tmp/<ordner>/propel.json`, danach `migration:down`. Die Migrationsdatei muss dafür noch in `src/Orm/Propel/Migration_<engine>/` liegen (`propel:migration:delete` löscht sie). Besser: rückwärtskompatible Folge-Migration (Spalte erst nullable anlegen, später entfernen).

Weitere Befehle (ausgeführt `[live verifiziert (202610.0)]`, Ausnahmen in der Tabelle):

| Befehl | Zweck |
|---|---|
| `propel:migration:check` | offene Migrationen prüfen; **Exit-Code 1 heißt: nichts zu migrieren** (auch "No migration file found"), Exit 0 nur bei offenen Migrationen |
| `propel:migration:delete` | Migrationsordner löschen |
| `propel:schema:validate`, `propel:schema:validate-xml-names` | Schema-Validierung (unter `docker.dev` registriert; in anderen Umgebungen nicht geprüft) |
| `propel:postgres-indexes:generate` | FK-Indizes für Postgres (Projekt läuft lokal auf MariaDB; `propel:pg-sql-compat` meldet dort "This command doesn't support chosen DB engine") |
| `propel:tables:drop`, `propel:database:drop`, `propel:database:create` | DB-Verwaltung (`propel:database:create` im Rahmen von `propel:install` ausgeführt; `tables:drop` und `database:drop` **nicht ausgeführt** (zerstörend); `propel:database:export`/`import` sind **nicht registriert**) |
| `propel:sql:build`, `propel:sql:insert`, `propel:config:convert` | SQL/Config (`sql:build` ausgeführt; `sql:insert` nicht ausgeführt (überschreibt Tabellen); `config:convert` ist in 202610.0 defekt, s. Rollback) |

## Beispiele

Vollständige Dateien: [`transfer-and-propel`](../templates/transfer-and-propel/README.md) (Schemas) und [`zed-module`](../templates/zed-module/README.md) (Repository, EntityManager, Mapper).

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

Core-Referenz Repository/EntityManager/Factory (`vendor/spryker/product-alternative/src/Spryker/Zed/ProductAlternative/Persistence/`, gekürzt):

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

Projekt-Erweiterung eines Core-EntityManagers, echt (`src/Pyz/Zed/CustomerAccess/Persistence/CustomerAccessEntityManager.php`): `class CustomerAccessEntityManager extends SprykerCustomerAccessEntityManager implements CustomerAccessEntityManagerInterface`. Die Klassenauflösung bevorzugt Pyz vor Spryker, eine gleichnamige Pyz-Klasse überschreibt die Core-Klasse ([Core erweitern](03-core-erweitern.md)).

Event-Behavior mit Original-Wert, echt (`src/Pyz/Zed/Category/Persistence/Propel/Schema/spy_category.schema.xml`):

```xml
<behavior name="event">
    <parameter name="spy_category_node_all" column="*"/>
    <parameter name="spy_category_node_fk_parent_category_node" column="fk_parent_category_node" keep-original="true"/>
</behavior>
```

Synchronization-Behavior in der Storage-Tabelle (Core `spy_product_quantity_storage`), Projekt setzt nur `queue_pool`:

```xml
<behavior name="synchronization">
    <parameter name="resource" value="product_quantity"/>
    <parameter name="key_suffix_column" value="fk_product"/>
    <parameter name="queue_group" value="sync.storage.product"/>
</behavior>
```

## Fallstricke

- Core-Schema in `vendor/` editieren: stattdessen Projekt-Schema gleichen Dateinamens mit identischem `name`/`package`/`namespace`. Pyz-Schema-Datei nicht kopieren, nur das Delta.
- Nach Schema-Änderung `propel:schema:copy` + `propel:model:build` vergessen: Klassen und `filterByXxx()` fehlen. Nach Behavior-Änderung ist `propel:install`/`propel:model:build` zwingend (Hooks stecken im generierten Code).
- `src/Orm` und `src/Generated` nie manuell ändern.
- Entities über Schichtgrenzen reichen: Repository/EntityManager geben Transfers zurück, die Business-Schicht kennt keine Propel-Klassen.
- Keine Queries in Facade, Business oder Communication, keine Propel-Aufrufe in Plugins außerhalb Persistence. Fremd-Queries per `DependencyProvider` oder über die Facade des anderen Moduls.
- N+1 vermeiden: `joinWith…`/`use…Query()` im Repository statt Schleifen-Queries.
- Name-/Namespace-Abweichung gegenüber dem Core-Schema: `SchemaMergeException`.
- Index-/FK-Namen über 63 Zeichen brechen auf Postgres.
- `findOneOrCreate()` mit `null`-ID filtert auf `IS NULL` und legt neu an: für Updates nur mit gesetzter ID verwenden.
- Event-Behavior mit `column="*"` erzeugt Event-Last (Publish & Sync): nur wo nötig. DB-seitiges `ON DELETE CASCADE` löst keine Entity-Events aus.
- Veralteter Schema-Kopf `xsi:noNamespaceSchemaLocation="http://xsd.propelorm.org/1.6/database.xsd"` (z. B. Pyz-ProductGroup): funktioniert beim Merge, aber nicht nachahmen.
- Upgrader-Anti-Patterns: Core-Schemas/-Klassen in `vendor` ändern, Kopie eines Core-Repositories nach Pyz statt Vererbung, Migrationsdateien manuell im Core ablegen ([Upgradability](28-upgradability.md)).
- Deprecations: `PropelBusinessFactory::create…Console()` ist teilweise `@deprecated` ("Please add the Command directly to your ConsoleDependencyProvider", z. B. `createPropelInstallConsole`), Konsolen direkt in `src/Pyz/Zed/Console/ConsoleDependencyProvider.php` registrieren. Der `AbstractQueryContainer` ist Legacy, aber ohne `@deprecated`-Tag.

## Checkliste

- [ ] Schema in `Persistence/Propel/Schema/`, `spryker:schema-01`-Kopf, korrektes Namespace/Package.
- [ ] Core-Tabelle erweitert: gleicher Dateiname, nur Delta, Spalten nullable oder mit Default.
- [ ] PK/FK/Sequenz-Namen nach Konvention, Index-Namen höchstens 63 Zeichen.
- [ ] `propel:schema:copy`, `propel:diff`, `propel:migrate`, `propel:model:build`, `transfer:entity:generate` (oder `propel:install`) gelaufen.
- [ ] Repository liest, EntityManager schreibt, beide liefern/nehmen Transfers.
- [ ] Mapper statt Entity-Weitergabe, keine Queries außerhalb Persistence.
- [ ] Behavior-Entscheidung geprüft (`event`, `synchronization`, `timestampable`, `uuid`), danach `propel:install`.
- [ ] Nichts in `vendor/`, `src/Orm`, `src/Generated` geändert.

## Quellen

Doku (Navigation geprüft, Fachinhalt nicht abrufbar), Basis https://docs.spryker.com/docs/dg/dev/backend-development/:
- zed/persistence-layer/persistence-layer.html, `…/database-schema-definition.html`, `…/repository.html`, `…/entity-manager.html`
- data-manipulation/run-and-revert-a-database-migration.html (Abruf: nur Weiterleitung)
- zed/communication-layer/add-indexes-to-foreign-key-columns-index-generator.html (nicht separat abgerufen)

Code:
- `vendor/spryker/propel/src/Spryker/Zed/Propel/{PropelConfig,PropelDependencyProvider}.php`, `Business/Model/PropelSchemaMerger.php`, `Communication/Console/*Console.php`, `Communication/Plugin/Propel/ForeignKeyIndexPropelSchemaElementFilterPlugin.php`
- `vendor/spryker/kernel/src/Spryker/Zed/Kernel/Persistence/{AbstractRepository,AbstractEntityManager,AbstractQueryContainer,AbstractPersistenceFactory}.php`, `…/EntityManager/TransferToEntityMapper.php`
- `vendor/spryker/{event-behavior,synchronization-behavior,uuid-behavior}/…/Behavior/*.php`, `vendor/spryker/index-generator/src/Spryker/Zed/IndexGenerator/`
- `vendor/spryker/product-alternative/src/Spryker/Zed/ProductAlternative/Persistence/` (Referenzmodul)
- `config/Shared/config_propel.php`, `config/install/docker.yml`, `src/Pyz/Zed/Propel/*`, `src/Pyz/Zed/ExampleStateMachine/Persistence/*`, `src/Pyz/Zed/CustomerAccess/Persistence/*`

Offene Lücken: Live-Ausführung der Befehle, Doku-Fachinhalt, Rollback (`migration:down`), genaue Registrierungsbedingung von `PostgresIndexGeneratorConsole` und Schema-Validatoren in `ConsoleDependencyProvider`.

Nachbar-Referenzen: [Projektmodul](02-projektmodul.md), [Core erweitern](03-core-erweitern.md), [Transfer](04-transfer.md), [Publish & Sync](07-publish-sync.md), [Docker SDK](30-docker-sdk-workflow.md), [Upgradability](28-upgradability.md). Templates: [transfer-and-propel](../templates/transfer-and-propel/README.md), [zed-module](../templates/zed-module/README.md).
