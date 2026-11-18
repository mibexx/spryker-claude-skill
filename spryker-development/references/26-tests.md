# Tests with Codeception and Testify

> Status: Release 202610.0, Codeception 5.1.2, `spryker/testify` installed. Configurations and test files read, helper classes and paths checked against `vendor/`. Docs texts (as of 2021, Cypress page 2026-08-20) fetched; commands match `docker/bin/command/cli/testing.sh`. Testing mode and Codeception were executed `[live verified (202610.0)]` (`docker/sdk testing codecept build`, `run -c tests/PyzTest/Zed/<Module>`, `up -t`, `cli -t`, return with `docker/sdk start`); browser tests (`Presentation` suites) did not run locally (WebDriver session missing, exit 255). `codecept fixtures` was executed during the live verification (creates data permanently, 139 product abstracts that go through the workers to Redis/Elasticsearch; rolled back/removed in the shop afterwards) `[live verified (202610.0)]`; the console commands `transfer:databuilder:generate`, `queue:worker:start` were executed. Deviation docs ↔ code: the docs name `tests/cypress-boilerplate`, in the release the folder is called `tests/cypress-tests` (the code applies).

## Purpose

Build project tests under `tests/PyzTest` and run them in the Docker SDK: Facade/business tests, Glue API Cests, P&S tests, DataBuilders, Testify helpers. Runnable skeleton: [Template codeception-test](../templates/codeception-test/README.md).

## Concepts

**Structure.** `tests/PyzTest/<Application>/<Module>/<Suite>/…Test.php|…Cest.php`, application ∈ `Zed`, `Yves`, `Glue`, `Shared`. Each module has `codeception.yml`, `_support/` (actor "Tester", optionally `Helper/`, `PageObject/`). Suites by layer and purpose: `Business`, `Console`, `Presentation` (WebDriver), in the Glue area `RestApi`. Further suite names in the release (per `codeception.yml`): `BackendApi` and `StorefrontApi` (MultiFactorAuth), `AssetCommunication`, `ProductCommunication`, `ProductReviewCommunication`, `SearchHttpCommunication`, `TaxAppCommunication`, `PaymentPresentation`, `PaymentMethodPresentation`. `@group EndToEnd` on API Cests is used for filtering. Present in the release: Glue (22 modules, mostly `RestApi`, `MultiFactorAuth` additionally `BackendApi`/`StorefrontApi`), Yves (Application, Availability, Cart, Checkout, CompanyUser, Customer, Newsletter, Product, Testify), Zed (among others Product/Business, Calculation, Console, OauthClient, ProductOption, PropelOrm, Touch, MessageBroker (`Business`, `MessageHandlers`) as well as Presentation suites in AppCatalogGui, CmsGui, NavigationGui, ProductRelation, Tax), `Shared/Testify` (`_support/Helper/{Environment,YvesBootstrap}.php`). Core modules ship their own tests under `vendor/spryker/<module>/tests/SprykerTest/…` (read only/use as a model).

**Config levels.**
1. Root `codeception.yml`: `namespace: PyzTest`, `actor: Tester`, `include: tests/PyzTest/*/*`, `paths` (`tests/_output`, `tests/_data`, …), extension `FixturesCommand` and `WebDriverHelper` (only suite `Presentation`), `params: tests/default.yml, env`.
2. Category configs (`-c`): `codeception.functional.yml` (include `Zed/*`, `Yves/*`; `SuiteFilterHelper exclusive: Presentation, Glue, Api, HomepageCest`), `codeception.ci.functional.yml`, `codeception.acceptance.yml` (`inclusive: Presentation`), `codeception.api.yml` (`inclusive: Glue, EndToEnd`).
3. Module `codeception.yml`: `namespace: PyzTest\Zed\<Module>`, relative paths, per suite `path`, `actor`, `modules.enabled`.
`tests/default.yml` sets `SPRYKER_TEST_*` defaults, `SPRYKER_TESTING_ENABLED: false`; the testing mode of the Docker SDK sets the variable (the SDK-internal switch is called `SPRYKER_TESTING_ENABLE`, the generated compose files and `env/cli/testing.env` translate it to `SPRYKER_TESTING_ENABLED=1`). Autoload: `composer.json` `autoload-dev` (`PyzTest\` → `tests/PyzTest/`) plus `test-autoload.php`, which loads `PyzTest\<App>\<Module>\X` from `tests/PyzTest/<App>/<Module>/_support/X.php` (this is how actor and helpers are found).

**Testify helpers** (namespaces checked against vendor; paths relative to `vendor/spryker/…`):

| Helper (FQCN) | Purpose |
|---|---|
| `\SprykerTest\Shared\Testify\Helper\LocatorHelper` | Locator access to Facades/Clients, option `projectNamespaces: ['Pyz']` |
| `\SprykerTest\Shared\Testify\Helper\ConfigHelper` | `setConfig`, `mockConfigMethod`, `mockSharedConfigMethod`, `mockEnvironmentConfig`, `getModuleConfig` |
| `\SprykerTest\Shared\Testify\Helper\DependencyHelper` | `setDependency($key, $value, $onlyFor)` overrides DependencyProvider entries |
| `\SprykerTest\Shared\Testify\Helper\DataCleanupHelper` | `addCleanup(Closure)`, runs in `_after`; config `cleanup: false` switches it off |
| `\SprykerTest\Zed\Testify\Helper\Business\BusinessHelper` | `getFacade()`, `getFactory()`, `mockFacadeMethod`, `mockFactoryMethod`, `mockSharedFactoryMethod`. The alias `\SprykerTest\Zed\Testify\Helper\BusinessHelper` is `[Deprecated]` (core tests partly still use it) |
| `\SprykerTest\Zed\Testify\Helper\Business\DependencyProviderHelper` | DependencyProvider mocks per layer |
| `\SprykerTest\Shared\Propel\Helper\TransactionHelper` | Transaction with rollback per test (business suites); annotation `@disableTransaction` switches it off |
| `\SprykerTest\Shared\Propel\Helper\ConnectionHelper` | DB connection without transaction (Glue suites) |
| `\SprykerTest\Service\Container\Helper\ContainerHelper`, `\SprykerTest\Shared\Store\Helper\StoreDependencyHelper`, `StoreDataHelper` | Container/store in the test |
| `\SprykerTest\Glue\Testify\Helper\GlueRest` (+ `OpenApi3`, `JsonPath`) | REST calls (`depends: PhpBrowser`), `seeResponseMatchesOpenApiSchema()`, JSON:API assertions |
| `\SprykerTest\Shared\Testify\Helper\Environment` | Standard environment; the project uses `\PyzTest\Shared\Testify\Helper\Environment` (`APPLICATION_ENV` = container value, otherwise `devtest`; default store `DE`) |
| `\SprykerTest\Shared\Testify\Helper\WebDriverHelper`, `SuiteFilterHelper`; `\SprykerTest\Shared\Testify\Fixtures\FixturesCommand` | Extensions (Chromedriver, suite filter, command `codecept fixtures`) |
| `PublishAndSynchronizeHelper`, `EventBehaviorHelper`, `QueueHelper`, `StorageHelper`, `SearchHelper` | P&S tests (modules `publisher`, `event-behavior`, `queue`, `storage`, `search`) |
| `CustomerDataHelper`, `ProductDataHelper`, `PriceProductDataHelper`, `StockDataHelper`, `PersistentQuoteHelper`, … | `have*` methods |

`have*` methods (code): `ProductDataHelper::haveProduct`, `haveProductConcrete`, `haveProductConcreteWithLocalizedAttributes`, `haveProductAbstract(array $override = [], bool $localized = false)`, `haveFullProduct`; `CustomerDataHelper::haveCustomer`, `haveCustomerTransfer`, `haveConfirmedCustomer`, `haveConfirmedCustomerWithAddress`, `haveCustomerAddress`; also `haveProductInStockForStore`, `havePriceProduct`, `haveAuthorizationToGlue`. Override arrays with transfer constants: `haveCustomer([CustomerTransfer::USERNAME => 'x'])`. The helpers live under `tests/…/_support`, not in `src/`, and are not a stable public API `[Assumption]`.

**DataBuilder.** `*.databuilder.xml` (namespace `spryker:databuilder-01`) in `tests/_data/` (project) and `vendor/spryker/*/tests/_data/` (core, 105 in the release). One Faker rule per property (`dataBuilderRule="unique()->email()"`, `"=DE"` for constants). Search paths: `Spryker\Zed\Transfer\TransferConfig` (`APPLICATION_ROOT_DIR/tests/_data`, `vendor/*/*/tests/_data/`). Generate: `transfer:databuilder:generate` → `Generated\Shared\DataBuilder\<Name>Builder`. In the release the project has no `tests/_data` with its own files (location derived from `TransferConfig`). New test properties belong in the databuilder XML, not in the productive `transfer.xml` (→ [Transfer](04-transfer.md)).

**Testing Facades.** `Codeception\Test\Unit`, `$this->tester` is the actor; arrange via `have*`, act via the Facade, assert via the returned transfer/DB. Mocks only at boundaries (Facade, Client, dependency): `BusinessHelper::mockFactoryMethod('createX', $mock)` or `DependencyHelper::setDependency(Provider::FACADE_X, $mock)`. Prerequisite: `KernelConstants::ENABLE_CONTAINER_OVERRIDING` = `(bool)getenv('SPRYKER_TESTING_ENABLED')` (set in `config/Shared/config_default-docker.dev.php:69` (authoritative for `docker.dev`), also `config_default-docker.php:321` and `config_default-docker.production.php:62`; → [Configuration](06-configuration.md)). Pure unit tests without DB: instantiate the class directly, `$this->createMock(...)` (example `vendor/spryker/product-storage/tests/…/ProductAbstractStorageWriterTest.php`).

**Testing P&S.** Persistence suite in the `*Storage`/`*Search` module with `PublishAndSynchronizeHelper`, `EventBehaviorHelper`, `QueueHelper`, `StorageHelper` or `SearchHelper`; they replace the queue and Redis/search with in-memory adapters and make the flow synchronous. Assertions (evidenced in `publisher/tests/…/PublishAndSynchronizeHelper.php`): `assertEntityIsPublished($event, $publishQueue)`, `assertEntityCanBeManuallyPublished`, `assertEntityIsSynchronizedToStorage|IsUpdatedInStorage|IsRemovedFromStorage($syncQueue)`, the same as `…ToSearch|…InSearch|…FromSearch`; plus `StorageHelper::assertStorageHasKey|assertStorageNotHasKey|mockStorageData`. `-vvv` shows the flow. The project has **no** P&S example, only core tests (→ [Publish & Sync](07-publish-sync.md)).

**Glue API.** Suite `RestApi`, the actor extends `SprykerTest\Glue\Testify\Tester\ApiEndToEndTester` (base URL `TestifyConstants::GLUE_APPLICATION_DOMAIN`, `formatFullUrl()`), test as a Cest with `@depends loadFixtures`, fixtures class with `FixturesBuilderInterface` + `FixturesContainerInterface`, loaded via `$I->loadFixtures(Class::class)`. Auth: `$I->haveAuthorizationToGlue($customerTransfer)` + `$I->amBearerAuthenticated($token)`; validation `seeResponseMatchesOpenApiSchema()` (→ [Glue Storefront](12-glue-storefront.md), [Glue Backend](13-glue-backend.md)).

**Docker SDK.** `docker/sdk testing` (script `docker/bin/command/cli/testing.sh`): testing mode (scheduler off, webdriver on), waits for database, broker, search, key_value_store, session, webdriver, then runs the command. Permanently: `docker/sdk up -t`, `docker/sdk cli -t`; back with `docker/sdk start`. `deploy.dev.yml`: `docker.testing.region: EU`, `services.webdriver.engine: chromedriver`. CI (`.github/workflows/_codeception.yml`): `docker/sdk boot deploy.ci.….yml` → `docker/sdk up -t` → `docker/sdk testing codecept fixtures` → `docker/sdk console queue:worker:start --stop-when-empty` → `docker/sdk testing codecept run -c codeception.{acceptance|api|functional}.yml`.

**Cypress/Robot.** Cypress: `tests/cypress-tests/` (`cypress/e2e/{api,backoffice,mp,smoke,yves}`, scripts `cy:open`, `cy:run`, `cy:ci:yves|backoffice|api|mp`, `cy:smoke`), runs outside Codeception against a running instance (`.github/workflows/_cypress.yml`, `deploy.ci.acceptance.mariadb.cypress.yml`); new UI tests are better written in Cypress (docs "E2E Testing with Cypress"). Robot Framework: `vendor/spryker/robotframework-suite-tests`, only in CI deploy files, no own Robot tests in the project `[Assumption]`: core QA. Codeception `Presentation` suites exist only for a few modules (AppCatalogGui, CmsGui, NavigationGui, ProductRelation, Tax, plus several modules in `Yves`).

## Instructions

1. Create the test module: `tests/PyzTest/Zed/<Module>/{codeception.yml,_support/<Module>BusinessTester.php,Business/…Test.php}` (skeleton: [Template](../templates/codeception-test/README.md)).
2. List the suite helpers in `codeception.yml`; Pyz projects use `\PyzTest\Shared\Testify\Helper\Environment` and `LocatorHelper` with `projectNamespaces: ['Pyz']`.
3. If custom DataBuilders are needed: `tests/_data/<module>.databuilder.xml`, then `docker/sdk cli console transfer:generate` and `transfer:databuilder:generate`.
4. Build the actor classes: `docker/sdk testing codecept build` (do not commit the `_generated/` folder).
5. Run: **`docker/sdk testing codecept run -c tests/PyzTest/Zed/<Module>`** (module configuration; `codecept run Zed/<Module>` and `run PyzTest/Zed/<Module>` fail with "Suite ... could not be found", `run tests/PyzTest/Zed/<Module>` with "Suite PyzTest was not loaded" `[live verified (202610.0)]`), `-g <group>` (can be repeated, also without `-c` across all modules), `-x <group>`, `-c codeception.functional.yml`. Business/Facade tests run locally (Calculation 15 tests, Product 6, Console 2, Touch 1: all green); `Presentation` suites (browser, e.g. `Zed/Tax`) need a WebDriver session and end locally with exit 255 (`switchTo() on null`).
6. API/acceptance runs: first `docker/sdk testing codecept fixtures`, `docker/sdk console queue:worker:start --stop-when-empty`.
7. Test style: method name `testWhatShouldHappen`, blocks `// Arrange`, `// Act`, `// Assert`, `@group` annotations ("Auto-generated group annotations", own groups below), `declare(strict_types = 1);`.

Commands (all `[live verified (202610.0)]`; `docker/sdk testing` and `up` need a TTY):
```
docker/sdk up -t                       # testing mode (restarts)
docker/sdk cli -t | docker/sdk testing # shell in testing mode
docker/sdk start                       # back to development mode
docker/sdk testing codecept build
docker/sdk testing codecept fixtures                         # executed live; writes test data permanently into the DB
docker/sdk testing codecept run
docker/sdk testing codecept run -c codeception.functional.yml -g Calculation   # functional | acceptance | api | ci.functional (without -g everything runs)
docker/sdk testing codecept run -c tests/PyzTest/Zed/Product
docker/sdk testing codecept run -g Calculation -g Console
docker/sdk testing codecept run -c tests/PyzTest/Zed/Product -vvv   # debug (P&S)
docker/sdk console transfer:databuilder:generate
docker/sdk console queue:worker:start --stop-when-empty
# Cypress (in tests/cypress-tests): npm install; .env from .env.example; npm run cy:open | cy:run | cy:ci:api | cy:smoke
```

## Examples

Complete files intended to be runnable are in the [Template](../templates/codeception-test/README.md). Here are the patterns from the release.

**Suite config Business (project)** (`tests/PyzTest/Zed/Product/codeception.yml`, excerpt):
```yaml
namespace: PyzTest\Zed\Product

suites:
    Business:
        path: Business
        actor: ProductBusinessTester
        modules:
            enabled:
                - Asserts
                - \PyzTest\Shared\Testify\Helper\Environment
                - \SprykerTest\Shared\Testify\Helper\LocatorHelper:
                    projectNamespaces: ['Pyz']
                - \SprykerTest\Shared\Testify\Helper\DataCleanupHelper
                - \SprykerTest\Shared\Propel\Helper\TransactionHelper
                - \SprykerTest\Shared\Testify\Helper\DependencyHelper
                - \SprykerTest\Service\Container\Helper\ContainerHelper
                - \SprykerTest\Shared\Store\Helper\StoreDependencyHelper
```

**Facade test (core pattern)** (`vendor/spryker/customer/tests/SprykerTest/Zed/Customer/Business/Facade/HasEmailTest.php`, core part):
```php
public function testHasEmailReturnsTrueWithCustomer(): void
{
    // Arrange
    $this->tester->createTestCustomer();

    // Act
    $hasEmail = $this->tester->getCustomerFacade()->hasEmail(CustomerBusinessTester::TESTER_EMAIL);

    // Assert
    $this->assertTrue($hasEmail);
}
```

**ProductBadge Facade test (excerpt from the template):**
```php
public function testCreateProductBadgePersistsBadgeAndReturnsItWithId(): void
{
    // Arrange
    $productAbstractTransfer = $this->tester->haveProductAbstract();
    $productBadgeTransfer = (new ProductBadgeBuilder([
        ProductBadgeTransfer::FK_PRODUCT_ABSTRACT => $productAbstractTransfer->getIdProductAbstractOrFail(),
        ProductBadgeTransfer::BADGE_KEY => 'new',
    ]))->build();

    // Act
    $createdProductBadgeTransfer = $this->tester->getProductBadgeFacade()->createProductBadge($productBadgeTransfer);

    // Assert
    $this->assertGreaterThan(0, $createdProductBadgeTransfer->getIdProductBadgeOrFail());
}
```

**Glue Cest (release)** (`tests/PyzTest/Glue/Carts/RestApi/CartsRestApiCest.php`, `requestCarts`):
```php
/**
 * @depends loadFixtures
 */
public function requestCarts(CartsApiTester $I): void
{
    // Arrange
    $quoteTransfer = $I->createPersistentQuote($I, $this->fixtures->getCustomerTransfer(), []);
    $oauthResponseTransfer = $I->haveAuthorizationToGlue($quoteTransfer->getCustomer());
    $I->amBearerAuthenticated($oauthResponseTransfer->getAccessToken());

    // Act
    $I->sendGET($I->buildCartsUrl());

    // Assert
    $I->seeResponseCodeIs(HttpCode::OK);
    $I->seeResponseIsJson();
    $I->seeResponseMatchesOpenApiSchema();
    $I->amSure('Response data contains resource collection')
        ->whenI()
        ->seeResponseDataContainsResourceCollectionOfType(CartsRestApiConfig::RESOURCE_CARTS);
}
```
Matching suite config: `tests/PyzTest/Glue/Carts/codeception.yml` (helpers include `GlueRest` with `depends: PhpBrowser`, `OpenApi3`, `JsonPath`, `AuthRestApiHelper`, `CustomerDataHelper`, `ProductDataHelper`, `DataCleanupHelper` with `cleanup: false`).

**P&S assertions (pattern, not release code; event/queue names are placeholders):**
```php
public function testSaveEntityIsPublishedAndSynchronized(): void
{
    // Arrange: save the entity (Facade/haveX)
    // Act / Assert
    $this->tester->assertEntityIsPublished('Entity.my_table.create', 'publish.my_entity');
    $this->tester->assertEntityIsSynchronizedToStorage('sync.storage.my_entity');
    $this->tester->assertStorageHasKey('my_entity:de:1');
}
```
For ProductBadge, the event `Entity.spy_product_badge.create` and the key `product_badge:<id_product_abstract>` would be used (names from [Template transfer-and-propel](../templates/transfer-and-propel/README.md); check the queue names in the publisher-and-synchronization template).

## Pitfalls

- Forgot `codecept build` after a new suite/actor/helper → "Class …Actions not found".
- Transfers/DataBuilders not generated (`transfer:generate`, `transfer:databuilder:generate`).
- Without testing mode (`-t`/`docker/sdk testing`) `SPRYKER_TESTING_ENABLED` is false → `ENABLE_CONTAINER_OVERRIDING` off → `DependencyHelper`/`mockFactoryMethod` have no effect; `Environment` uses `devtest` only without the variable.
- `APPLICATION_STORE` (default `DE` in `Environment`) wrong → store/locale errors; enable the store helpers.
- Test data: `TransactionHelper` (business) + `DataCleanupHelper`; Glue suites use real persistence, `cleanup: false` (Carts suite) leaves data behind; name fixtures uniquely.
- Cest without `@depends loadFixtures`: `$this->fixtures` not initialized. `HomepageCest` is excluded in `codeception.functional.yml` (fails after `Zed/*`).
- `code:sniff:style tests/PyzTest/...` before `codecept build` aborts with `Trait ..._generated\...Actions not found`; build first, then sniff `[live verified (202610.0)]`.
- `*.databuilder.xml` with a different `strict` value than the main transfer definition: `transfer:databuilder:generate` aborts with `TransferDefinitionMismatchException`; without successful generation all core builders are missing too (`Class ...ProductAbstractBuilder not found`) `[live verified (202610.0)]`.
- Glue Cest with `seeResponseMatchesOpenApiSchema()` for a new resource: run `rest-api:generate:documentation` first, otherwise `No valid path is found in the schema` `[live verified (202610.0)]`.
- `haveProductAbstract()` creates products without a tax set: Facade calls that use `ProductFacade::findProductAbstractById()` throw `TaxSetNotFoundException` (read observer); in your own code use `getProductAbstractCollection()` instead `[live verified (202610.0)]`.
- Zed business suites also run in normal mode via `docker/sdk cli vendor/bin/codecept run -c tests/PyzTest/Zed/<Module>`; testing mode is needed for `DependencyHelper` mocks, webdriver and the Glue suites.
- Do not change core tests; project tests go in `tests/PyzTest/…`.
- Mocks on internal models break on core updates; mock only at Facade/Client/dependency (→ [Upgradability](28-upgradability.md)).
- `Presentation` tests are slow and only work with `WebDriverHelper`/Chrome; write new UI tests in Cypress.
- A Cest for data from Redis needs the queue workers (P&S) to have run before the test run.

## Checklist

- [ ] Test in the path `tests/PyzTest/<App>/<Module>/<Suite>/`, module `codeception.yml` present
- [ ] Helper list uses the new namespace `…\Business\BusinessHelper` (not the deprecated alias)
- [ ] `codecept build` has run, `_generated/` not committed
- [ ] Arrange/Act/Assert, `@group` annotations, `strict_types`
- [ ] Mocks only at Facade/Client/dependency
- [ ] Test data via `have*`/DataBuilder, cleanup or transaction active
- [ ] Glue Cests with `@depends loadFixtures`, group `EndToEnd`
- [ ] Tests run in testing mode, followed by `docker/sdk start`

## Sources

Docs (base https://docs.spryker.com/docs/, fetched 2026-11-18): `dg/dev/guidelines/testing-guidelines/{testing-guidelines,test-framework,setting-up-tests,testify,data-builders,cypress-testing}.html`, `…/executing-tests/testing-the-publish-and-synchronization-process.html`, `dg/dev/sdks/the-docker-sdk/running-tests-with-the-docker-sdk.html`.

Code: `codeception*.yml`, `tests/default.yml`, `test-autoload.php`, `composer.json`, `tests/PyzTest/Zed/Product/`, `tests/PyzTest/Glue/{Carts,ProductLabels}/`, `tests/PyzTest/Shared/Testify/_support/Helper/Environment.php`, `vendor/spryker/testify/tests/SprykerTest/{Shared,Zed,Glue}/Testify/_support/`, `vendor/spryker/{customer,product,product-storage,publisher,event-behavior,queue,storage,search,propel}/tests/`, `vendor/spryker/transfer/src/Spryker/Zed/Transfer/TransferConfig.php`, `config/Shared/config_default-docker.dev.php` (line 69), `config/Shared/config_default-docker.php` (line 321), `deploy.dev.yml`, `docker/bin/command/cli/testing.sh`, `.github/workflows/{_codeception,_cypress}.yml`, `tests/cypress-tests/`.

References: [Transfer](04-transfer.md), [Publish & Sync](07-publish-sync.md), [Configuration](06-configuration.md), [Glue Storefront](12-glue-storefront.md), [Coding guidelines](27-coding-guidelines.md), [Docker SDK workflow](30-docker-sdk-workflow.md). Template: [codeception-test](../templates/codeception-test/README.md).

Deprecations/version status: `\SprykerTest\Zed\Testify\Helper\BusinessHelper` `@deprecated` → `…\Business\BusinessHelper`; `codeception.*.yml` contain `settings.suite_class: \PHPUnit_Framework_TestSuite` (legacy name, apparently still runs with Codeception 5.1.2, do not change `[Assumption]`); `TransferConfig` has `@deprecated` glob methods (→ [Transfer](04-transfer.md)); `_envs/isolated.yml` in core modules only for isolated module tests outside the project.
