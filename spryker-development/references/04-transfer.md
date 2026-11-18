# Transfer objects

> Status: release b2b-demo-shop 202610.0 (spryker/kernel 3.87.1). Generator, XSD, merge rules and generated classes were checked against the code; docs pages were reachable (HTTP 200), there is no separate docs version for 202610.0 ("latest"). `[code-verified, docs not cross-checked]` applies to detailed statements backed only by code. Not executed: an own generator run with new XML in the project (project remained unchanged); the commands are registered `[live verified (202610.0)]`, `transfer:generate` with a sandbox path ran in the reviewer's template test. Assumptions are marked.

## Purpose

A Transfer is a generated, typed data container (DTO), usable in Yves, Zed, Glue, Client and Service. It is the standard data type for Facade, Client and Repository signatures. This reference describes definition (XML), merging of core and project definitions, generation and conventions. Propel schema and entity transfers: [Propel](05-propel-persistence.md). Module structure: [Project module](02-project-module.md).

## Concepts

- **XML only.** Definition in `*.transfer.xml`, generated into `src/Generated/Shared/Transfer/<Name>Transfer.php` (namespace `Generated\Shared\Transfer`, base `Spryker\Shared\Kernel\Transfer\AbstractTransfer`). The release contains 2226 generated files, 8 definitions in `src/Pyz/**`, 871 in `vendor/spryker` and `vendor/spryker-shop`. Never commit or edit `src/Generated/` ("EVERY CHANGE WILL BE LOST", in `.gitignore`).
- **Locations** (`vendor/spryker/transfer/src/Spryker/Zed/Transfer/TransferConfig.php`):

| Method | Value |
|---|---|
| `getApplicationSourceDirectoryGlobPattern()` | `src/*/Shared/*/Transfer/` (i.e. `src/Pyz/Shared/<Module>/Transfer/`) |
| `getSprykerCoreSourceDirectoryGlobPattern()` | `vendor/*/*/src/*/Shared/*/Transfer/` (protected, `@deprecated` -> `getCoreSourceDirectoryGlobPatterns()`, still used internally) |
| `getTransferFileNamePattern()` | `/(.*?).transfer.xml/` |
| `getClassTargetDirectory()` | `src/Generated/Shared/Transfer/` |
| `getDataBuilderSourceDirectories()` | like transfers plus `tests/_data` and `vendor/*/*/tests/_data/` |

  Convention: file name `<module_snake>.transfer.xml` in the folder `Shared/<Module>/Transfer/`. Multiple files per folder are possible.
- **Property types** (XSD `transfer-01.xsd`, code): `string`, `int`, `float`, `bool`, `array`, `mixed`, `decimal` (the only value object, `Spryker\DecimalObject\Decimal`), transfer name (`Customer`), collection (`Customer[]`), typed arrays (`string[]`, `int[]`, ...). The docs only mention `int, string, bool, array`.
- **Property attributes:** `name` (camelCase, required), `type` (required), `singular` (name for `add…`), `associative="true"`, `strict="true"`, `deprecated="Text"`, `description`, `sensitive="true"` (`#[SensitiveParameter]`), `restRequestParameter`/`restResponseParameter` ([Glue Storefront](12-glue-storefront.md)), `example`/`examples`.
- **Name suffix:** the generator appends `Transfer`. The XML says `Customer`, not `CustomerTransfer`. Pyz enables `isTransferSuffixCheckStrict() = true` (the suffix is only omitted if the name ends with `Transfer`; default `false`: as soon as `Transfer` appears anywhere in the name, it is omitted).
- **Merge (`TransferDefinitionMerger`):** all definitions with the same name (`User` = `UserTransfer`) are combined into **one** class. Core and Pyz have equal rank, there is no inheritance and no ordering. This is how Pyz extends a core Transfer: the same `<transfer name>` with additional properties. Properties with the same name must be identical in **all** attributes (`type`, `singular`, `associative`, `strict`), otherwise abort with `Value mismatch for "<Transfer>.<property>" transfer property`.
  - `bundles` (source modules, results in `@module A|B`) is merged. `deprecated`: the first one set wins. `description`: strategy `getPropertyDescriptionMergeStrategy()`, default `GET_FIRST`. `dataBuilderRule` may only differ with `isProjectTransferOverrideActive()` (default `false`).
  - `strict` must be set identically (or be identically absent) on the Transfer and the property in all definitions, otherwise `TransferDefinitionMismatchException` ("Strict mode violation detected for transfer …").
  - `TransferConfig::getTypeShims()` corrects wrong core types (e.g. `KeyTranslation.glossaryKey` int -> string). Do not extend on the project side.
- **`strict`:** on a Transfer property: `fromArray()` throws `InvalidStrictTypeException` if a Transfer value (after initialization from arrays) is not a `TransferInterface`. At Transfer level (e.g. `UserCollection` in `acl.transfer.xml`) it sets `is_strict` for the properties. Empty primitive arrays with `strict` count as not set for `require…`. Set `strict="true"` for new Transfers. `[Assumption]` Interplay of Transfer and property level not checked on every generator branch.
- **Generated methods** (verified on `UserTransfer`, `ProductConcreteTransfer`):
  - Constant per property: `UserTransfer::IS_MERCHANT_AGENT = 'isMerchantAgent'` (`public const string`).
  - Scalar: `setX()`, `getX()` (nullable), `getXOrFail()` (throws `NullValueException`), `setXOrFail()`, `requireX()` (throws `RequiredTransferPropertyException`).
  - Collection `Foo[]`: `setFoos(ArrayObject)`, `getFoos(): ArrayObject`, `addFoo(FooTransfer)`, `requireFoos()`. Collections are never `null`. `…OrFail` does not exist for collections and arrays.
  - General: `fromArray(array $data, $ignoreMissingProperty = false)` (accepts snake_case, camelCase, PascalCase), `toArray($isRecursive = true, $camelCasedKeys = false)` (snake_case), `modifiedToArray()`, `isPropertyModified()`.
- **Entity transfers** (`Spy<Table>EntityTransfer`, base `AbstractEntityTransfer`) are created from the Propel schemas, not from `*.transfer.xml` ([Propel](05-propel-persistence.md)).

## Instructions

1. **Search the core before creating:** `grep -rn 'name="Quote"' vendor/spryker/*/src --include=*.transfer.xml`. Reuse existing Transfers or extend them additively.
2. **Create the file:** `src/Pyz/Shared/<Module>/Transfer/<module_snake>.transfer.xml` with `xmlns="spryker:transfer-01"` (if the namespace is missing, the file is not parsed validly).
3. **Dedicated Transfers per use case:** `<Name>`, `<Name>Criteria`, `<Name>Collection` (for write operations `…CollectionResponse`) instead of scalar parameter lists. Collections with `singular`.
4. **Extend a core Transfer:** declare the same Transfer name with only the new properties. Copy `type`, `singular`, `associative`, `strict` exactly from the core if the property exists there (`grep -rn 'name="y"' vendor/spryker/*/src src/Pyz --include=*.transfer.xml`).
5. **Generate and validate** `[live verified (202610.0)]` (registered):
   ```bash
   docker/sdk cli console transfer:generate
   docker/sdk cli console transfer:validate -vv
   ```
6. **Entity transfers** after schema changes: first `propel:schema:copy`, then `docker/sdk cli console transfer:entity:generate`.

Console commands (commands in the container via `docker/sdk cli console <cmd>`) `[live verified (202610.0)]` (all registered in `console list`):

| Command | Effect |
|---|---|
| `transfer:generate` | data transfers from `*.transfer.xml` (`TransferGeneratorConsole`) |
| `transfer:entity:generate` | entity transfers from `src/Orm/Propel/Schema/*.schema.xml` (class in module `propel`: `EntityTransferGeneratorConsole`) |
| `transfer:databuilder:generate` | `<Name>Builder` into `src/Generated/Shared/DataBuilder/` (dev/test only) |
| `transfer:validate [-b <module>] [-vv]` | XSD and merge check, exit code 0 on success |
| `transfer:remove`, `transfer:entity:remove`, `transfer:databuilder:remove` | delete generated code |

`list transfer` shows seven commands in the installation. Registered in `src/Pyz/Zed/Console/ConsoleDependencyProvider.php`: `TransferGeneratorConsole`, `RemoveTransferConsole`, `EntityTransferGeneratorConsole`; `ValidatorConsole` and DataBuilder consoles only with `isDevelopmentConsoleCommandsEnabled()`. The install recipes (`config/install/*.yml`) first delete (`remove-transfer` among others) and then generate ([Docker SDK](30-docker-sdk-workflow.md)).

Project config `src/Pyz/Zed/Transfer/TransferConfig.php` (active): `isTransferXmlValidationEnabled()`, `isTransferSuffixCheckStrict()`, `isArrayRequireValidationEnabled()` (`requireX` fails on `[]`). Not active (default `false`): `isTransferNameValidated()`, `isCaseValidated()`, `isSingularRequired()`. Useful for new projects, for existing ones only after cleanup.

Data Builder (tests, usage in [Tests](26-tests.md)): definition `*.databuilder.xml` under `tests/_data/` with `name` and `dataBuilderRule` (Faker) per property. The project had no custom `*.databuilder.xml`; custom builders belong in `tests/_data/`. The `strict` attribute must match the main Transfer definition (strict there -> `strict="true"` in the DataBuilder XML as well), otherwise `transfer:databuilder:generate` aborts with `TransferDefinitionMismatchException` `[live verified (202610.0)]`.

## Examples

Template (complete): [`transfer-and-propel`](../templates/transfer-and-propel/README.md). Running example ProductBadge, path `src/Pyz/Shared/ProductBadge/Transfer/product_badge.transfer.xml`:

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

Usage (excerpt from a reader, `getXOrFail()` instead of `requireX()->getX()`):

```php
$productBadgeCollectionTransfer = new ProductBadgeCollectionTransfer();

foreach ($productBadgeCriteriaTransfer->getBadgeKeys() as $badgeKey) {
    $productBadgeCollectionTransfer->addProductBadge(
        (new ProductBadgeTransfer())->setBadgeKey($badgeKey),
    );
}
```

Extending the core, real (`src/Pyz/Shared/ProductStorage/Transfer/product_storage.transfer.xml`):

```xml
<transfer name="ProductView">
    <property name="productSetIds" type="array"/>
    <property name="bundledProductIds" type="array"/>
</transfer>
```

Oddity in the demo shop in the same file: `ProductAbstractStorage.roductSetIds` (missing "p") is probably a typo `[Assumption]`, but it is generated. Do not adopt as a pattern.

Core definition with collection, `associative`, `strict`, `deprecated` (`vendor/spryker/acl/.../acl.transfer.xml`):

```xml
<property name="groups" type="Group[]" singular="group"/>
<property name="isMerchantAgent" type="bool" strict="true"/>
<property name="reference" type="string" deprecated="Will be removed with next major release."/>
<property name="navigationItems" type="NavigationItem[]" associative="true" singular="navigationItem"/>
<transfer name="UserCollection" strict="true">
```

Detect errors early:

```php
$email = $customerTransfer->getEmailOrFail();                // NullValueException if not set
$array = $customerTransfer->toArray();                       // recursive, snake_case
$copy = (new CustomerTransfer())->fromArray($array, true);   // true: ignore unknown keys
```

## Pitfalls

1. Generated code edited or committed: lost on the next `transfer:generate`.
2. Not regenerated after an XML change: `Call to undefined method …setFoo()`.
3. `Value mismatch for "X.y" transfer property`: same property with a different `type`, `singular`, `associative` or `strict` in another module. Find the counterpart via `grep`, align Pyz with core.
4. `Strict mode violation detected …` (`TransferDefinitionMismatchException`): `strict` not identical in all definitions.
5. `InvalidStrictTypeException` on `fromArray()`: nested data passed as a Transfer instead of as an array.
6. `RequiredTransferPropertyException` / `NullValueException`: `requireX()` / `getXOrFail()` on an unset property.
7. Names with "Transfer" in the middle are not suffixed without `isTransferSuffixCheckStrict()`. `CustomerTransfer` in the XML does not create a double suffix by default, but with `isTransferNameValidated() = true` it raises `InvalidNameException`.
8. Collection without `singular`: `add…` is guessed via inflection; with `isSingularRequired() = true` the generator aborts (`InvalidSingularPropertyNameException`). Always set it.
9. `associative="true"` on a wrong type or value (`InvalidAssociativeTypeException`, `InvalidAssociativeValueException`).
10. Typos in property names silently create an additional property (example `roductSetIds`).
11. `array` as a general-purpose container: hard to validate, create dedicated Transfers. Mark sensitive fields (passwords, tokens) with `sensitive="true"`.
12. Business logic or custom classes in the Transfer: not intended. Mapping in mapper classes ([Propel](05-propel-persistence.md)).
13. Upgrader anti-patterns: changing core `*.transfer.xml` in `vendor/`, committing `src/Generated`, own property with the same name as a new core property but a different type/`strict`, `isProjectTransferOverrideActive() = true` to override types (the feature is only intended for `dataBuilderRule`). Extend core Transfers only additively ([Upgradability](28-upgradability.md)).
14. Deprecations (code 202610.0 takes precedence):
    - `[Deprecated]` `TransferConfig::getEntitiesSourceDirectories()` -> `PropelFacadeInterface::getSchemaDirectory()`. Still overridden in `src/Pyz/Zed/Transfer/TransferConfig.php`, without effect (no caller, directory `src/Orm/Propel/*/Schema/` does not exist) `[Assumption]` can be removed.
    - `[Deprecated]` `getSprykerCoreSourceDirectoryGlobPattern()` -> `getCoreSourceDirectoryGlobPatterns()`.
    - `getAdditionalSourceDirectoryGlobPatterns()`: the docs name it as the extension point for additional directories, in the code it is `protected` and `@deprecated`. It still works, a documented successor is missing `[Assumption]`, docs inquiry open.
    - `[Deprecated]` `ClassDefinition::setNameWithoutValidation()` ("Will be removed with the next major to enforce validation then"): Spryker will enforce `isTransferNameValidated()` in the future.
    - `requireX()`: according to the docs deprecated since `spryker/transfer` 3.25.0, replacement `getXOrFail()`. Still generated in the release and widespread in the core.

## Checklist

- [ ] Searched for the Transfer in the core, checked reuse or additive extension.
- [ ] File `src/Pyz/Shared/<Module>/Transfer/<module_snake>.transfer.xml`, namespace `spryker:transfer-01`.
- [ ] New Transfers `strict="true"`, collections with `singular`, narrow types (`int`, `string`, `Foo[]`, `string[]`).
- [ ] For a core property: `type`/`singular`/`associative`/`strict` identical to core.
- [ ] `transfer:generate` and `transfer:validate -vv` run without errors.
- [ ] `getXOrFail()` instead of `requireX()`, no logic in the Transfer.
- [ ] Sensitive properties with `sensitive="true"`.
- [ ] No entry in `vendor/` or `src/Generated`.

## Sources

Docs (https://docs.spryker.com/docs/):
- dg/dev/backend-development/data-manipulation/data-ingestion/structural-preparations/create-use-and-extend-the-transfer-objects.html
- dg/dev/guidelines/testing-guidelines/data-builders.html

Code:
- `vendor/spryker/transfer/src/Spryker/Zed/Transfer/TransferConfig.php`, `…/Business/Model/Generator/{ClassDefinition,TransferDefinitionMerger,TransferDefinitionLoader,EntityDefinitionNormalizer}.php`, `…/Business/TransferFacadeInterface.php`, `…/Communication/Console/*.php`
- `vendor/spryker/transfer/data/definition/{transfer-01,databuilder-01}.xsd`
- `vendor/spryker/kernel/src/Spryker/Shared/Kernel/Transfer/AbstractTransfer.php`
- `vendor/spryker/propel/src/Spryker/Zed/Propel/Communication/Console/EntityTransferGeneratorConsole.php`
- `vendor/spryker/acl/src/Spryker/Shared/Acl/Transfer/acl.transfer.xml`, `vendor/spryker/acl/tests/_data/acl.databuilder.xml`
- `src/Pyz/Zed/Transfer/TransferConfig.php`, `src/Pyz/Zed/Console/ConsoleDependencyProvider.php`, `src/Pyz/Shared/*/Transfer/*.transfer.xml`, `config/install/{development,ci}.yml`

Neighbor references: [Architecture](01-architecture.md), [Project module](02-project-module.md), [Propel and persistence](05-propel-persistence.md), [Glue Storefront](12-glue-storefront.md), [Console](22-console-cron-queue.md), [Tests](26-tests.md). Template: [transfer-and-propel](../templates/transfer-and-propel/README.md).
