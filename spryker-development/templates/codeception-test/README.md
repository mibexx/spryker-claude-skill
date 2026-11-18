# Template codeception-test

Facade business test (DB fixtures, rollback per test) and Glue RestApi Cest for the running example **ProductBadge**. Background: [Tests](../../references/26-tests.md), counterpart to the templates [zed-module](../zed-module/README.md), [transfer-and-propel](../transfer-and-propel/README.md) and [glue-resource-storefront](../glue-resource-storefront/README.md).

## Purpose

- `tests/PyzTest/Zed/ProductBadge/`: Codeception module configuration, actor ("Tester"), data helper and a Facade test against `Pyz\Zed\ProductBadge\Business\ProductBadgeFacadeInterface` (`createProductBadge`, `getProductBadgeCollection`) including a negative case (not allowed `badgeKey` → `InvalidProductBadgeException`).
- `tests/PyzTest/Glue/ProductBadges/`: end-to-end Cest against the resource `product-badges` from the template glue-resource-storefront (fixtures class, actor, suite config).
- `tests/_data/product_badge.databuilder.xml`: DataBuilder rules for `ProductBadgeBuilder`.

## File list (target path below the project root)

| File | Purpose |
|---|---|
| `tests/_data/product_badge.databuilder.xml` | DataBuilder (`badgeKey` from the allowed list, `label`) |
| `tests/PyzTest/Zed/ProductBadge/codeception.yml` | module config, suite `Business` with helper list |
| `tests/PyzTest/Zed/ProductBadge/_support/ProductBadgeBusinessTester.php` | actor (`use _generated\ProductBadgeBusinessTesterActions`, created by `codecept build`) |
| `tests/PyzTest/Zed/ProductBadge/_support/Helper/ProductBadgeDataHelper.php` | `haveProductBadge()`, `getProductBadgeFacade()`, cleanup |
| `tests/PyzTest/Zed/ProductBadge/Business/ProductBadgeFacadeTest.php` | Facade test (Arrange/Act/Assert) |
| `tests/PyzTest/Glue/ProductBadges/codeception.yml` | suite `RestApi` |
| `tests/PyzTest/Glue/ProductBadges/_support/ProductBadgesApiTester.php` | actor with `buildProductBadgesUrl()` |
| `tests/PyzTest/Glue/ProductBadges/RestApi/ProductBadgesRestApiFixtures.php` | fixtures (`FixturesBuilderInterface`, `FixturesContainerInterface`) |
| `tests/PyzTest/Glue/ProductBadges/RestApi/ProductBadgesRestApiCest.php` | Cest (`@depends loadFixtures`, group `EndToEnd`) |

## Placeholders / customization

- Replace `ProductBadge`/`ProductBadges` with your own module name: folders, namespaces `PyzTest\Zed\<Module>`, actor name `<Module>BusinessTester`, Glue resource.
- The module name in the namespace (`PyzTest\Zed\ProductBadge`, third part) is decisive: the `BusinessHelper` derives the module from it and resolves `Pyz\Zed\ProductBadge\Business\ProductBadgeFacade` (`PyzTest` → organization `Pyz`).
- Allowed badge keys (`new`, `sale`, `bestseller`) come from `ProductBadgeConfig::getAllowedBadgeKeys()` of the template zed-module; if changed there, adjust the DataBuilder rule and the constants in the test.
- The Glue Cest requires published data (storage key `product_badge:<id_product_abstract>`, see transfer-and-propel). Without Publish & Sync and the queue worker the positive case fails (procedure from `.github/workflows/_codeception.yml`, confirmed live; additionally `rest-api:generate:documentation` must have run, otherwise `seeResponseMatchesOpenApiSchema` fails with `No valid path is found in the schema`).

## Registration

No registration in code. Codeception finds the module via `include: tests/PyzTest/*/*` in the root `codeception.yml` (and `tests/PyzTest/Zed/*` in `codeception.functional.yml`, `tests/PyzTest/*/*` in `codeception.api.yml` with filter `Glue`/`EndToEnd`). The actor is loaded from `_support/` via `test-autoload.php` (`PyzTest\<App>\<Module>\X` → `tests/PyzTest/<App>/<Module>/_support/X.php`); the helper `PyzTest\Zed\ProductBadge\Helper\ProductBadgeDataHelper` is therefore located in `_support/Helper/`.

## Commands afterwards

All commands ran in shop 202610.0 (testing mode via `script -q /dev/null docker/sdk up -t`, afterwards `docker/sdk up` back to normal mode) `[live verified (202610.0)]`; for testing mode see [Tests](../../references/26-tests.md) and [Docker SDK Workflow](../../references/30-docker-sdk-workflow.md).
```
docker/sdk cli console transfer:generate                  # ProductBadge transfers
docker/sdk cli console transfer:databuilder:generate      # ProductBadgeBuilder from tests/_data (takes a while, ~1880 builders; BEFORE the first test run)
docker/sdk testing codecept build                         # actor classes (_generated), only then code:sniff on tests/
docker/sdk testing codecept run -c tests/PyzTest/Zed/ProductBadge   # 3 tests; "codecept run Zed/ProductBadge" is wrong (Suite not found)
docker/sdk testing codecept run -g ProductBadgeFacadeTest
docker/sdk testing codecept fixtures                      # before Glue API runs, creates fixture rows in the DB
docker/sdk console queue:worker:start --stop-when-empty   # process P&S (like CI)
docker/sdk console rest-api:generate:documentation        # OpenAPI schema for seeResponseMatchesOpenApiSchema
docker/sdk testing codecept run -c codeception.api.yml -g ProductBadgesRestApiCest
docker/sdk console code:sniff:style tests/PyzTest/Zed/ProductBadge   # after codecept build
```
Prerequisite: table `spy_product_badge` exists (`propel:install`), for the Glue Cest the container in testing mode (`docker/sdk up -t` or `docker/sdk testing`). The Zed business suite also runs in normal mode via `docker/sdk cli vendor/bin/codecept run -c tests/PyzTest/Zed/ProductBadge` (confirmed live for the module `OrderPaymentCheck`).

Live result (202610.0): Facade test 3 tests, 8 assertions green; Glue Cest 3 tests, 8 assertions green (after fixtures, worker and schema generation); `codecept fixtures` 37 tests (all suites) in 13 s. Corrections from the run: DataBuilder XML with `strict="true"` (otherwise `TransferDefinitionMismatchException` in `transfer:databuilder:generate`), Facade validator without `findProductAbstractById()` (tax set observer, see `zed-module`).

## Source used (models in the release)

- Suite config and actor: `tests/PyzTest/Zed/Product/{codeception.yml,_support/ProductBusinessTester.php}`, `tests/PyzTest/Glue/ProductLabels/codeception.yml`, `tests/PyzTest/Glue/Carts/RestApi/CartsRestApiCest.php`.
- Helper pattern: `vendor/spryker/product/tests/SprykerTest/Shared/Product/_support/Helper/ProductDataHelper.php` (`haveProductAbstract`, `haveFullProduct`), `DataCleanupHelperTrait`, `LocatorHelperTrait`.
- Business helper: `vendor/spryker/testify/tests/SprykerTest/Zed/Testify/_support/Helper/Business/BusinessHelper.php` (the alias `\SprykerTest\Zed\Testify\Helper\BusinessHelper` is `@deprecated`, the template uses the new namespace).
- Environment helper (project): `tests/PyzTest/Shared/Testify/_support/Helper/Environment.php`.

## Verification status

Walked through in shop 202610.0: Facade test (3 tests) and Glue Cest (3 tests) green, `code:sniff:style` on both test directories exit 0 (after `codecept build`; before that the sniffer aborts with `Trait ..._generated\...Actions not found`). The open points of the previous version are resolved:
- The helper list in the business suite is sufficient for `haveProductAbstract()` (test green).
- `BusinessHelper` before `LocatorHelper` works; the test uses `ProductBadgeDataHelper::getProductBadgeFacade()`.
- Faker formatters `randomElement`, `word` in `product_badge.databuilder.xml` are accepted by `transfer:databuilder:generate` (after the `strict="true"` correction).
- A P&S test (persistence suite with `PublishAndSynchronizeHelper`) is deliberately not included: in the release only core tests (`vendor/spryker/publisher/tests/...`) prove the pattern, there is no example in the project. The publish path was instead checked live via import, Back Office and Glue Backend (see `publisher-and-synchronization`). Pattern and assertion methods → [Tests](../../references/26-tests.md).
`[live verified (202610.0)]`
