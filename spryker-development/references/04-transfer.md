# Transfer-Objekte (Recherche T-04)

> Status: Release b2b-demo-shop 202610.0 (spryker/kernel 3.87.1). Generator, XSD, Merge-Regeln und generierte Klassen wurden am Code geprüft; Doku-Seiten waren abrufbar (HTTP 200), eine eigene Doku-Version für 202610.0 gibt es nicht ("latest"). `[code-verifiziert, Doku nicht abgeglichen]` gilt für Detailaussagen, die nur am Code belegt sind. Nicht ausgeführt: eigener Generatorlauf mit neuem XML im Projekt (Projekt blieb unverändert); die Befehle sind registriert `[live verifiziert (202610.0)]`, `transfer:generate` mit Sandbox-Pfad lief im Template-Test des Reviewers. Annahmen sind markiert.

## Zweck

Ein Transfer ist ein generierter, typisierter Daten-Container (DTO), nutzbar in Yves, Zed, Glue, Client und Service. Er ist der Standard-Datentyp für Facade-, Client- und Repository-Signaturen. Diese Referenz beschreibt Definition (XML), Merge von Core- und Projekt-Definitionen, Generierung und Konventionen. Propel-Schema und Entity-Transfers: [Propel](05-propel-persistenz.md). Modulaufbau: [Projektmodul](02-projektmodul.md).

## Konzepte

- **Nur XML.** Definition in `*.transfer.xml`, generiert wird `src/Generated/Shared/Transfer/<Name>Transfer.php` (Namespace `Generated\Shared\Transfer`, Basis `Spryker\Shared\Kernel\Transfer\AbstractTransfer`). Im Release liegen 2226 generierte Dateien, 8 Definitionen in `src/Pyz/**`, 871 in `vendor/spryker` und `vendor/spryker-shop`. `src/Generated/` nie committen oder editieren ("EVERY CHANGE WILL BE LOST", in `.gitignore`).
- **Fundorte** (`vendor/spryker/transfer/src/Spryker/Zed/Transfer/TransferConfig.php`):

| Methode | Wert |
|---|---|
| `getApplicationSourceDirectoryGlobPattern()` | `src/*/Shared/*/Transfer/` (also `src/Pyz/Shared/<Modul>/Transfer/`) |
| `getSprykerCoreSourceDirectoryGlobPattern()` | `vendor/*/*/src/*/Shared/*/Transfer/` (protected, `@deprecated` -> `getCoreSourceDirectoryGlobPatterns()`, intern weiter genutzt) |
| `getTransferFileNamePattern()` | `/(.*?).transfer.xml/` |
| `getClassTargetDirectory()` | `src/Generated/Shared/Transfer/` |
| `getDataBuilderSourceDirectories()` | wie Transfers plus `tests/_data` und `vendor/*/*/tests/_data/` |

  Konvention: Dateiname `<modul_snake>.transfer.xml` im Ordner `Shared/<Modul>/Transfer/`. Mehrere Dateien pro Ordner sind möglich.
- **Property-Typen** (XSD `transfer-01.xsd`, Code): `string`, `int`, `float`, `bool`, `array`, `mixed`, `decimal` (einziges Value Object, `Spryker\DecimalObject\Decimal`), Transfername (`Customer`), Collection (`Customer[]`), typisierte Arrays (`string[]`, `int[]`, ...). Die Doku nennt nur `int, string, bool, array`.
- **Property-Attribute:** `name` (camelCase, Pflicht), `type` (Pflicht), `singular` (Name für `add…`), `associative="true"`, `strict="true"`, `deprecated="Text"`, `description`, `sensitive="true"` (`#[SensitiveParameter]`), `restRequestParameter`/`restResponseParameter` ([Glue Storefront](12-glue-storefront.md)), `example`/`examples`.
- **Namenssuffix:** Der Generator hängt `Transfer` an. Im XML steht `Customer`, nicht `CustomerTransfer`. Pyz aktiviert `isTransferSuffixCheckStrict() = true` (Suffix wird nur ausgelassen, wenn der Name auf `Transfer` endet; Default `false`: sobald `Transfer` irgendwo im Namen steht, entfällt es).
- **Merge (`TransferDefinitionMerger`):** Alle Definitionen mit gleichem Namen (`User` = `UserTransfer`) werden zu **einer** Klasse vereinigt. Core und Pyz sind gleichrangig, es gibt keine Vererbung und keine Reihenfolge. So erweitert Pyz einen Core-Transfer: derselbe `<transfer name>` mit zusätzlichen Properties. Gleichnamige Properties müssen in **allen** Attributen identisch sein (`type`, `singular`, `associative`, `strict`), sonst Abbruch `Value mismatch for "<Transfer>.<property>" transfer property`.
  - `bundles` (Quell-Module, ergibt `@module A|B`) wird vereinigt. `deprecated`: erste gesetzte gilt. `description`: Strategie `getPropertyDescriptionMergeStrategy()`, Default `GET_FIRST`. `dataBuilderRule` darf nur mit `isProjectTransferOverrideActive()` (Default `false`) abweichen.
  - `strict` muss an Transfer und Property in allen Definitionen gleich gesetzt (oder gleich fehlend) sein, sonst `TransferDefinitionMismatchException` ("Strict mode violation detected for transfer …").
  - `TransferConfig::getTypeShims()` korrigiert falsche Core-Typen (z. B. `KeyTranslation.glossaryKey` int -> string). Nicht projektseitig erweitern.
- **`strict`:** An einer Transfer-Property: `fromArray()` wirft `InvalidStrictTypeException`, wenn ein Transfer-Wert (nach der Initialisierung aus Arrays) kein `TransferInterface` ist. Auf Transfer-Ebene (z. B. `UserCollection` in `acl.transfer.xml`) setzt es `is_strict` für die Properties. Leere primitive Arrays mit `strict` gelten bei `require…` als nicht gesetzt. Für neue Transfers `strict="true"` setzen. `[Annahme]` Zusammenspiel von Transfer- und Property-Ebene nicht an jedem Generatorzweig geprüft.
- **Generierte Methoden** (belegt an `UserTransfer`, `ProductConcreteTransfer`):
  - Konstante je Property: `UserTransfer::IS_MERCHANT_AGENT = 'isMerchantAgent'` (`public const string`).
  - Skalar: `setX()`, `getX()` (nullable), `getXOrFail()` (wirft `NullValueException`), `setXOrFail()`, `requireX()` (wirft `RequiredTransferPropertyException`).
  - Collection `Foo[]`: `setFoos(ArrayObject)`, `getFoos(): ArrayObject`, `addFoo(FooTransfer)`, `requireFoos()`. Collections sind nie `null`. `…OrFail` gibt es nicht für Collections und Arrays.
  - Allgemein: `fromArray(array $data, $ignoreMissingProperty = false)` (akzeptiert snake_case, camelCase, PascalCase), `toArray($isRecursive = true, $camelCasedKeys = false)` (snake_case), `modifiedToArray()`, `isPropertyModified()`.
- **Entity-Transfers** (`Spy<Tabelle>EntityTransfer`, Basis `AbstractEntityTransfer`) entstehen aus den Propel-Schemas, nicht aus `*.transfer.xml` ([Propel](05-propel-persistenz.md)).

## Anleitung

1. **Vor dem Anlegen im Core suchen:** `grep -rn 'name="Quote"' vendor/spryker/*/src --include=*.transfer.xml`. Vorhandene Transfers wiederverwenden oder additiv erweitern.
2. **Datei anlegen:** `src/Pyz/Shared/<Modul>/Transfer/<modul_snake>.transfer.xml` mit `xmlns="spryker:transfer-01"` (fehlt der Namespace, wird die Datei nicht gültig geparst).
3. **Pro Use-Case eigene Transfers:** `<Name>`, `<Name>Criteria`, `<Name>Collection` (bei Schreibvorgängen `…CollectionResponse`) statt Skalar-Parameterlisten. Collections mit `singular`.
4. **Core-Transfer erweitern:** denselben Transfernamen mit nur den neuen Properties deklarieren. `type`, `singular`, `associative`, `strict` exakt wie im Core übernehmen, falls die Property dort existiert (`grep -rn 'name="y"' vendor/spryker/*/src src/Pyz --include=*.transfer.xml`).
5. **Generieren und prüfen** `[live verifiziert (202610.0)]` (registriert):
   ```bash
   docker/sdk cli console transfer:generate
   docker/sdk cli console transfer:validate -vv
   ```
6. **Entity-Transfers** nach Schema-Änderungen: erst `propel:schema:copy`, dann `docker/sdk cli console transfer:entity:generate`.

Konsolenkommandos (Kommandos im Container über `docker/sdk cli console <cmd>`) `[live verifiziert (202610.0)]` (alle in `console list` registriert):

| Kommando | Wirkung |
|---|---|
| `transfer:generate` | Daten-Transfers aus `*.transfer.xml` (`TransferGeneratorConsole`) |
| `transfer:entity:generate` | Entity-Transfers aus `src/Orm/Propel/Schema/*.schema.xml` (Klasse im Modul `propel`: `EntityTransferGeneratorConsole`) |
| `transfer:databuilder:generate` | `<Name>Builder` nach `src/Generated/Shared/DataBuilder/` (nur dev/test) |
| `transfer:validate [-b <modul>] [-vv]` | XSD- und Merge-Prüfung, Exit-Code 0 bei Erfolg |
| `transfer:remove`, `transfer:entity:remove`, `transfer:databuilder:remove` | generierten Code löschen |

`list transfer` zeigt in der Installation sieben Kommandos. Registriert in `src/Pyz/Zed/Console/ConsoleDependencyProvider.php`: `TransferGeneratorConsole`, `RemoveTransferConsole`, `EntityTransferGeneratorConsole`; `ValidatorConsole` und DataBuilder-Konsolen nur bei `isDevelopmentConsoleCommandsEnabled()`. Die Install-Recipes (`config/install/*.yml`) löschen zuerst (`remove-transfer` u. a.) und generieren dann ([Docker SDK](30-docker-sdk-workflow.md)).

Projekt-Config `src/Pyz/Zed/Transfer/TransferConfig.php` (aktiv): `isTransferXmlValidationEnabled()`, `isTransferSuffixCheckStrict()`, `isArrayRequireValidationEnabled()` (`requireX` schlägt bei `[]` fehl). Nicht aktiv (Default `false`): `isTransferNameValidated()`, `isCaseValidated()`, `isSingularRequired()`. Für Neuprojekte sinnvoll, für Bestand erst nach Bereinigung.

Data Builder (Tests, Einsatz in [Tests](26-tests.md)): Definition `*.databuilder.xml` unter `tests/_data/` mit je Property `name` und `dataBuilderRule` (Faker). Im Projekt gab es keine eigenen `*.databuilder.xml`; eigene Builder gehören nach `tests/_data/`. Das `strict`-Attribut muss mit der Haupt-Transfer-Definition übereinstimmen (strict dort -> `strict="true"` auch in der DataBuilder-XML), sonst bricht `transfer:databuilder:generate` mit `TransferDefinitionMismatchException` ab `[live verifiziert (202610.0)]`.

## Beispiele

Template (vollständig): [`transfer-and-propel`](../templates/transfer-and-propel/README.md). Laufbeispiel ProductBadge, Pfad `src/Pyz/Shared/ProductBadge/Transfer/product_badge.transfer.xml`:

```xml
<?xml version="1.0"?>
<transfers xmlns="spryker:transfer-01"
           xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
           xsi:schemaLocation="spryker:transfer-01 http://static.spryker.com/transfer-01.xsd">

    <transfer name="ProductBadge" strict="true">
        <property name="idProductBadge" type="int"/>
        <property name="fkProductAbstract" type="int"/>
        <property name="badgeKey" type="string"/>
        <property name="label" type="string"/>
    </transfer>

    <transfer name="ProductBadgeCollection" strict="true">
        <property name="productBadges" type="ProductBadge[]" singular="productBadge"/>
    </transfer>

    <transfer name="ProductBadgeCriteria" strict="true">
        <property name="badgeKeys" type="string[]" singular="badgeKey"/>
    </transfer>

</transfers>
```

Nutzung (Auszug eines Readers, `getXOrFail()` statt `requireX()->getX()`):

```php
$productBadgeCollectionTransfer = new ProductBadgeCollectionTransfer();

foreach ($productBadgeCriteriaTransfer->getBadgeKeys() as $badgeKey) {
    $productBadgeCollectionTransfer->addProductBadge(
        (new ProductBadgeTransfer())->setBadgeKey($badgeKey),
    );
}
```

Core erweitern, echt (`src/Pyz/Shared/ProductStorage/Transfer/product_storage.transfer.xml`):

```xml
<transfer name="ProductView">
    <property name="productSetIds" type="array"/>
    <property name="bundledProductIds" type="array"/>
</transfer>
```

Auffälligkeit im Demo-Shop derselben Datei: `ProductAbstractStorage.roductSetIds` (fehlendes "p") ist vermutlich ein Tippfehler `[Annahme]`, wird aber generiert. Nicht als Muster übernehmen.

Core-Definition mit Collection, `associative`, `strict`, `deprecated` (`vendor/spryker/acl/.../acl.transfer.xml`):

```xml
<property name="groups" type="Group[]" singular="group"/>
<property name="isMerchantAgent" type="bool" strict="true"/>
<property name="reference" type="string" deprecated="Will be removed with next major release."/>
<property name="navigationItems" type="NavigationItem[]" associative="true" singular="navigationItem"/>
<transfer name="UserCollection" strict="true">
```

Fehler früh erkennen:

```php
$email = $customerTransfer->getEmailOrFail();                // NullValueException, wenn nicht gesetzt
$array = $customerTransfer->toArray();                       // rekursiv, snake_case
$copy = (new CustomerTransfer())->fromArray($array, true);   // true: unbekannte Keys ignorieren
```

## Fallstricke

1. Generierten Code editiert oder committet: geht beim nächsten `transfer:generate` verloren.
2. Nach XML-Änderung nicht neu generiert: `Call to undefined method …setFoo()`.
3. `Value mismatch for "X.y" transfer property`: gleiche Property mit anderem `type`, `singular`, `associative` oder `strict` in einem anderen Modul. Gegenstelle per `grep` suchen, Pyz an Core angleichen.
4. `Strict mode violation detected …` (`TransferDefinitionMismatchException`): `strict` nicht in allen Definitionen gleich.
5. `InvalidStrictTypeException` bei `fromArray()`: verschachtelte Daten als Transfer statt als Array übergeben.
6. `RequiredTransferPropertyException` / `NullValueException`: `requireX()` / `getXOrFail()` auf ungesetzter Property.
7. Namen mit "Transfer" mittendrin werden ohne `isTransferSuffixCheckStrict()` nicht suffigiert. `CustomerTransfer` im XML erzeugt bei Default keinen doppelten Suffix, bei `isTransferNameValidated() = true` aber `InvalidNameException`.
8. Collection ohne `singular`: `add…` wird per Inflection geraten, bei `isSingularRequired() = true` bricht der Generator ab (`InvalidSingularPropertyNameException`). Immer setzen.
9. `associative="true"` an falschem Typ oder Wert (`InvalidAssociativeTypeException`, `InvalidAssociativeValueException`).
10. Tippfehler in Property-Namen erzeugen still eine zusätzliche Property (Beispiel `roductSetIds`).
11. `array` als Allzweck-Container: schwer zu validieren, eigene Transfers anlegen. Sensible Felder (Passwörter, Tokens) mit `sensitive="true"` markieren.
12. Business-Logik oder eigene Klassen im Transfer: nicht vorgesehen. Mapping in Mapper-Klassen ([Propel](05-propel-persistenz.md)).
13. Upgrader-Anti-Patterns: Core-`*.transfer.xml` in `vendor/` ändern, `src/Generated` committen, eigene Property mit gleichem Namen wie eine neue Core-Property, aber anderem Typ/`strict`, `isProjectTransferOverrideActive() = true` zum Überstimmen von Typen (das Feature ist nur für `dataBuilderRule` gedacht). Core-Transfers nur additiv erweitern ([Upgradability](28-upgradability.md)).
14. Deprecations (Code 202610.0 hat Vorrang):
    - `[Deprecated]` `TransferConfig::getEntitiesSourceDirectories()` -> `PropelFacadeInterface::getSchemaDirectory()`. In `src/Pyz/Zed/Transfer/TransferConfig.php` noch überschrieben, wirkungslos (kein Aufrufer, Verzeichnis `src/Orm/Propel/*/Schema/` existiert nicht) `[Annahme]` kann entfallen.
    - `[Deprecated]` `getSprykerCoreSourceDirectoryGlobPattern()` -> `getCoreSourceDirectoryGlobPatterns()`.
    - `getAdditionalSourceDirectoryGlobPatterns()`: Doku nennt sie als Erweiterungspunkt für Zusatzverzeichnisse, im Code ist sie `protected` und `@deprecated`. Sie funktioniert weiterhin, ein dokumentierter Nachfolger fehlt `[Annahme]`, Doku-Nachfrage offen.
    - `[Deprecated]` `ClassDefinition::setNameWithoutValidation()` ("Will be removed with the next major to enforce validation then"): Spryker wird `isTransferNameValidated()` künftig erzwingen.
    - `requireX()`: laut Doku deprecated seit `spryker/transfer` 3.25.0, Ersatz `getXOrFail()`. Im Release weiter generiert und im Core verbreitet.

## Checkliste

- [ ] Transfer im Core gesucht, Wiederverwendung oder additive Erweiterung geprüft.
- [ ] Datei `src/Pyz/Shared/<Modul>/Transfer/<modul_snake>.transfer.xml`, Namespace `spryker:transfer-01`.
- [ ] Neue Transfers `strict="true"`, Collections mit `singular`, Typen eng (`int`, `string`, `Foo[]`, `string[]`).
- [ ] Bei Core-Property: `type`/`singular`/`associative`/`strict` identisch zum Core.
- [ ] `transfer:generate` und `transfer:validate -vv` laufen fehlerfrei.
- [ ] `getXOrFail()` statt `requireX()`, keine Logik im Transfer.
- [ ] Sensible Properties mit `sensitive="true"`.
- [ ] Kein Eintrag in `vendor/` oder `src/Generated`.

## Quellen

Doku (https://docs.spryker.com/docs/):
- dg/dev/backend-development/data-manipulation/data-ingestion/structural-preparations/create-use-and-extend-the-transfer-objects.html
- dg/dev/guidelines/testing-guidelines/data-builders.html

Code:
- `vendor/spryker/transfer/src/Spryker/Zed/Transfer/TransferConfig.php`, `…/Business/Model/Generator/{ClassDefinition,TransferDefinitionMerger,TransferDefinitionLoader,EntityDefinitionNormalizer}.php`, `…/Business/TransferFacadeInterface.php`, `…/Communication/Console/*.php`
- `vendor/spryker/transfer/data/definition/{transfer-01,databuilder-01}.xsd`
- `vendor/spryker/kernel/src/Spryker/Shared/Kernel/Transfer/AbstractTransfer.php`
- `vendor/spryker/propel/src/Spryker/Zed/Propel/Communication/Console/EntityTransferGeneratorConsole.php`
- `vendor/spryker/acl/src/Spryker/Shared/Acl/Transfer/acl.transfer.xml`, `vendor/spryker/acl/tests/_data/acl.databuilder.xml`
- `src/Pyz/Zed/Transfer/TransferConfig.php`, `src/Pyz/Zed/Console/ConsoleDependencyProvider.php`, `src/Pyz/Shared/*/Transfer/*.transfer.xml`, `config/install/{development,ci}.yml`

Nachbar-Referenzen: [Architektur](01-architektur.md), [Projektmodul](02-projektmodul.md), [Propel und Persistenz](05-propel-persistenz.md), [Glue Storefront](12-glue-storefront.md), [Console](22-console-cron-queue.md), [Tests](26-tests.md). Template: [transfer-and-propel](../templates/transfer-and-propel/README.md).
