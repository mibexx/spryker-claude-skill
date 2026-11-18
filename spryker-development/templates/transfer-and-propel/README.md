# Template transfer-and-propel

Transfer-Definitionen und Propel-Schemas für das Laufbeispiel **ProductBadge**: Quell-Tabelle mit `event`-Behavior, Storage-Tabelle mit `synchronization`-Behavior, zugehörige Transfers. Hintergrund: [Transfer](../../references/04-transfer.md), [Propel](../../references/05-propel-persistenz.md), [Publish & Sync](../../references/07-publish-sync.md).

## Dateiliste (Zielpfad unter dem Projektroot)

| Datei | Inhalt |
|---|---|
| `src/Pyz/Shared/ProductBadge/Transfer/product_badge.transfer.xml` | `ProductBadge`, `ProductBadgeCollection`, `ProductBadgeCriteria` (alle `strict="true"`) |
| `src/Pyz/Shared/ProductBadgeStorage/Transfer/product_badge_storage.transfer.xml` | `ProductBadgeStorage` (Payload der Storage-Tabelle / des Redis-Werts) |
| `src/Pyz/Zed/ProductBadge/Persistence/Propel/Schema/spy_product_badge.schema.xml` | Tabelle `spy_product_badge`, FK auf `spy_product_abstract`, Behaviors `event` und `timestampable` |
| `src/Pyz/Zed/ProductBadgeStorage/Persistence/Propel/Schema/spy_product_badge_storage.schema.xml` | Tabelle `spy_product_badge_storage`, Behaviors `synchronization` und `timestampable` |

## Festgelegte Namen (gelten für alle ProductBadge-Templates)

| Gegenstand | Wert |
|---|---|
| Quell-Tabelle / Entity | `spy_product_badge` / `Orm\Zed\ProductBadge\Persistence\SpyProductBadge(Query)` |
| Storage-Tabelle / Entity | `spy_product_badge_storage` / `Orm\Zed\ProductBadgeStorage\Persistence\SpyProductBadgeStorage(Query)` |
| Resource-Name (`resource` im Behavior) | `product_badge` |
| Storage-Key (Redis, ohne internen Prefix `kv:`) | `product_badge:<id_product_abstract>`, z. B. `product_badge:123` (kein Store, keine Locale; Key-Suffix-Spalte `fk_product_abstract`) |
| Sync-Queue (`queue_group`) | `sync.storage.product_badge` |
| Publish-Queue | `publish.product_badge` |
| Entity-Events (event-Behavior, `column="*"`) | `Entity.spy_product_badge.create`, `Entity.spy_product_badge.update`, `Entity.spy_product_badge.delete` |
| Publish-Event (Trigger-Plugin, Re-Generation) | `ProductBadge.product_badge.publish` |

Redis-Wert (JSON, vom Behavior um `_timestamp` ergänzt): `{"id_product_abstract":123,"product_badges":[{"id_product_badge":1,"fk_product_abstract":123,"badge_key":"new","label":"Neu"}]}`.

## Platzhalter / Anpassung

- `ProductBadge` / `product_badge` durch den eigenen Modulnamen ersetzen (Transfer-, Schema-Dateiname, Namespace `Orm\Zed\<Modul>\Persistence`, Tabellenname, Behavior-Parameternamen, Resource, Queue-Namen).
- Tabellenpräfix: Die Laufbeispiel-Verträge verwenden `spy_`. Für echte Projekttabellen empfiehlt die Recherche `pyz_` mit explizitem `phpName` (siehe [Propel](../../references/05-propel-persistenz.md)). Dann ändern sich Tabellenname, Entity-Klassen und Event-Namen (`Entity.pyz_product_badge.*`).
- Fremdschlüsselname und Indexnamen höchstens 63 Zeichen (Postgres).
- Storage-Tabelle: Tabellenname muss auf `_storage` (Ziel Redis) bzw. `_search` (Ziel Search) enden.
- Spalten `data` und `key` (unique) nicht deklarieren, der `synchronization`-Behavior ergänzt sie (`modifyTable()`).
- Kein `store`-/`locale`-Parameter, weil der Key store- und locale-unabhängig ist. Mit `store` wäre `queue_pool` nicht erlaubt (`InvalidConfigurationException`).
- **`queue_pool` ist hier Pflicht** (`synchronizationPool`, wie bei den Pyz-Storage-Tabellen und in `RabbitMqConfig::getQueuePools()`). Ohne Pool schreibt der Behavior zwar in die Queue `sync.storage.product_badge`, aber `sync:data product_badge` bricht mit `SynchronizationQueuePoolNotFoundException` ab (live gefunden, siehe [publisher-and-synchronization](../publisher-and-synchronization/README.md)).

## Registrierung

Keine eigene Registrierung. Schema-Dateien werden über den Glob `src/*/Zed/*/Persistence/Propel/Schema/` und Transfer-Dateien über `src/*/Shared/*/Transfer/` gefunden. Die Queue-/Plugin-Registrierung steht im Template [publisher-and-synchronization](../publisher-and-synchronization/README.md).

## Befehle danach `[live verifiziert (202610.0)]`

```bash
docker/sdk cli console transfer:generate
docker/sdk cli console transfer:validate -vv
docker/sdk cli console propel:schema:copy
docker/sdk cli console propel:diff
docker/sdk cli console propel:migrate
docker/sdk cli console propel:model:build
docker/sdk cli console transfer:entity:generate
```

Alternativ `docker/sdk cli console propel:install` (Komplettlauf inkl. Entity-Transfers; ohne Änderung ein No-Op mit Exit 0).

Live-Ergebnis im Shop 202610.0 (alle Exit 0): `propel:diff` meldet `2 added tables` und legt `src/Orm/Propel/Migration_mysql/PropelMigration_<ts>.php` an (Engine MySQL/MariaDB; das Verzeichnis ist git-ignoriert), `propel:migrate` führt `4 of 4 SQL statements` aus und legt `spy_product_badge` und `spy_product_badge_storage` an (die Storage-Tabelle bekommt von der `synchronization`-Behavior `data`, `key`, `alias_keys` mit Unique-Index auf `key`), danach meldet `propel:diff` `Same XML and database structures`. `propel:model:build` und `transfer:entity:generate` erzeugen `src/Orm/Zed/ProductBadge*` und `SpyProductBadge(Storage)EntityTransfer`. Beide Entity-Namespaces liegen unter `src/Orm/Zed/<Modul>/Persistence`. Nach Änderung der Behaviors ist `propel:install` bzw. `propel:model:build` zwingend, sonst fehlen die Event- und Sync-Hooks in den Entities.

## Vorbild-Quellen

- Transfer: `src/Pyz/Shared/ProductStorage/Transfer/product_storage.transfer.xml` (Kopfzeile), `vendor/spryker/asset-storage/src/Spryker/Shared/AssetStorage/Transfer/asset_storage.transfer.xml` (`strict="true"`).
- Quell-Schema: `vendor/spryker/product-alternative/.../spy_product_alternative.schema.xml` (FK auf `spy_product_abstract`), `vendor/spryker/asset/.../spy_asset.schema.xml` (event-Behavior).
- Storage-Schema: `vendor/spryker/asset-storage/.../spy_asset_slot_storage.schema.xml`, `vendor/spryker/publish-and-synchronize-health-check-storage/.../spy_publish_and_synchronize_health_check_storage.schema.xml`.

## Verifikationsstatus

Schema-Köpfe, Behavior-Parameter und Attribute gegen die genannten Vorbilder und `SynchronizationBehavior`/`EventBehavior` im Release 202610.0 geprüft (`modifyTable()` ergänzt `data`/`key`; Foreign Keys des Quell-Schemas landen als `foreignKeys` im Event). Durchgespielt im Shop 202610.0: `transfer:generate`, `transfer:validate -vv`, `propel:schema:copy`, `propel:diff`, `propel:migrate`, `propel:model:build`, `transfer:entity:generate`, `propel:install` (Details im Protokoll `live-verification.md`, Abschnitt Phase 2). Korrektur aus dem Lauf: `queue_pool` im Storage-Schema ergänzt. `[live verifiziert (202610.0)]`
