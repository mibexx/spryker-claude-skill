# Tests mit Codeception und Testify (Recherche T-26)

> Status: Release 202610.0, Codeception 5.1.2, `spryker/testify` installiert. Konfigurationen und Testdateien gelesen, Helper-Klassen und -Pfade gegen `vendor/` geprüft. Doku-Texte (Stand 2021, Cypress-Seite 2026-08-20) laut Recherche abgerufen; Befehle stimmen mit `docker/bin/command/cli/testing.sh` überein. Testing-Modus und Codeception wurden ausgeführt `[live verifiziert (202610.0)]` (`docker/sdk testing codecept build`, `run -c tests/PyzTest/Zed/<Modul>`, `up -t`, `cli -t`, Rückkehr mit `docker/sdk start`); Browser-Tests (`Presentation`-Suiten) liefen lokal nicht (WebDriver-Session fehlt, Exit 255). `codecept fixtures` wurde in Phase 2 der Live-Verifikation ausgeführt (legt dauerhaft Daten an, 139 Product Abstracts, die per Worker bis Redis/Elasticsearch laufen; im Shop danach zurückgebaut) `[live verifiziert (202610.0)]`; die Konsolenbefehle `transfer:databuilder:generate`, `queue:worker:start` wurden ausgeführt. Abweichung Doku ↔ Code: Doku nennt `tests/cypress-boilerplate`, im Release heißt der Ordner `tests/cypress-tests` (Code gilt).

## Zweck

Projekt-Tests unter `tests/PyzTest` aufbauen und im Docker SDK ausführen: Facade-/Business-Tests, Glue-API-Cests, P&S-Tests, DataBuilder, Testify-Helper. Lauffähiges Gerüst: [Template codeception-test](../templates/codeception-test/README.md).

## Konzepte

**Gliederung.** `tests/PyzTest/<Applikation>/<Modul>/<Suite>/…Test.php|…Cest.php`, Applikation ∈ `Zed`, `Yves`, `Glue`, `Shared`. Jedes Modul hat `codeception.yml`, `_support/` (Actor "Tester", optional `Helper/`, `PageObject/`). Suites nach Schicht und Zweck: `Business`, `Console`, `Presentation` (WebDriver), im Glue-Bereich `RestApi`. Weitere Suite-Namen im Release (je `codeception.yml`): `BackendApi` und `StorefrontApi` (MultiFactorAuth), `AssetCommunication`, `ProductCommunication`, `ProductReviewCommunication`, `SearchHttpCommunication`, `TaxAppCommunication`, `PaymentPresentation`, `PaymentMethodPresentation`. `@group EndToEnd` auf API-Cests dient der Filterung. Im Release vorhanden: Glue (22 Module, überwiegend `RestApi`, `MultiFactorAuth` zusätzlich `BackendApi`/`StorefrontApi`), Yves (Application, Availability, Cart, Checkout, CompanyUser, Customer, Newsletter, Product, Testify), Zed (u. a. Product/Business, Calculation, Console, OauthClient, ProductOption, PropelOrm, Touch, MessageBroker (`Business`, `MessageHandlers`) sowie Presentation-Suites in AppCatalogGui, CmsGui, NavigationGui, ProductRelation, Tax), `Shared/Testify` (`_support/Helper/{Environment,YvesBootstrap}.php`). Core-Module bringen eigene Tests unter `vendor/spryker/<modul>/tests/SprykerTest/…` mit (nur lesen/als Vorlage).

**Config-Ebenen.**
1. Root `codeception.yml`: `namespace: PyzTest`, `actor: Tester`, `include: tests/PyzTest/*/*`, `paths` (`tests/_output`, `tests/_data`, …), Extension `FixturesCommand` und `WebDriverHelper` (nur Suite `Presentation`), `params: tests/default.yml, env`.
2. Kategorie-Configs (`-c`): `codeception.functional.yml` (include `Zed/*`, `Yves/*`; `SuiteFilterHelper exclusive: Presentation, Glue, Api, HomepageCest`), `codeception.ci.functional.yml`, `codeception.acceptance.yml` (`inclusive: Presentation`), `codeception.api.yml` (`inclusive: Glue, EndToEnd`).
3. Modul-`codeception.yml`: `namespace: PyzTest\Zed\<Modul>`, Pfade relativ, je Suite `path`, `actor`, `modules.enabled`.
`tests/default.yml` setzt `SPRYKER_TEST_*`-Defaults, `SPRYKER_TESTING_ENABLED: false`; der Testing-Modus des Docker SDK setzt die Variable (der SDK-interne Schalter heißt `SPRYKER_TESTING_ENABLE`, die generierten Compose-Dateien und `env/cli/testing.env` übersetzen ihn nach `SPRYKER_TESTING_ENABLED=1`). Autoload: `composer.json` `autoload-dev` (`PyzTest\` → `tests/PyzTest/`) plus `test-autoload.php`, das `PyzTest\<App>\<Modul>\X` aus `tests/PyzTest/<App>/<Modul>/_support/X.php` lädt (so finden Actor und Helper ihren Weg).

**Testify-Helper** (Namespaces gegen vendor geprüft; Pfade relativ zu `vendor/spryker/…`):

| Helper (FQCN) | Zweck |
|---|---|
| `\SprykerTest\Shared\Testify\Helper\LocatorHelper` | Locator-Zugriff auf Facades/Clients, Option `projectNamespaces: ['Pyz']` |
| `\SprykerTest\Shared\Testify\Helper\ConfigHelper` | `setConfig`, `mockConfigMethod`, `mockSharedConfigMethod`, `mockEnvironmentConfig`, `getModuleConfig` |
| `\SprykerTest\Shared\Testify\Helper\DependencyHelper` | `setDependency($key, $value, $onlyFor)` überschreibt DependencyProvider-Einträge |
| `\SprykerTest\Shared\Testify\Helper\DataCleanupHelper` | `addCleanup(Closure)`, läuft in `_after`; Config `cleanup: false` schaltet ab |
| `\SprykerTest\Zed\Testify\Helper\Business\BusinessHelper` | `getFacade()`, `getFactory()`, `mockFacadeMethod`, `mockFactoryMethod`, `mockSharedFactoryMethod`. Der Alias `\SprykerTest\Zed\Testify\Helper\BusinessHelper` ist `[Deprecated]` (Core-Tests nutzen ihn teils noch) |
| `\SprykerTest\Zed\Testify\Helper\Business\DependencyProviderHelper` | DependencyProvider-Mocks je Layer |
| `\SprykerTest\Shared\Propel\Helper\TransactionHelper` | Transaktion mit Rollback je Test (Business-Suites); Annotation `@disableTransaction` schaltet ab |
| `\SprykerTest\Shared\Propel\Helper\ConnectionHelper` | DB-Verbindung ohne Transaktion (Glue-Suites) |
| `\SprykerTest\Service\Container\Helper\ContainerHelper`, `\SprykerTest\Shared\Store\Helper\StoreDependencyHelper`, `StoreDataHelper` | Container/Store im Test |
| `\SprykerTest\Glue\Testify\Helper\GlueRest` (+ `OpenApi3`, `JsonPath`) | REST-Aufrufe (`depends: PhpBrowser`), `seeResponseMatchesOpenApiSchema()`, JSON:API-Assertions |
| `\SprykerTest\Shared\Testify\Helper\Environment` | Standard-Umgebung; das Projekt nutzt `\PyzTest\Shared\Testify\Helper\Environment` (`APPLICATION_ENV` = Container-Wert, sonst `devtest`; Default-Store `DE`) |
| `\SprykerTest\Shared\Testify\Helper\WebDriverHelper`, `SuiteFilterHelper`; `\SprykerTest\Shared\Testify\Fixtures\FixturesCommand` | Extensions (Chromedriver, Suite-Filter, Befehl `codecept fixtures`) |
| `PublishAndSynchronizeHelper`, `EventBehaviorHelper`, `QueueHelper`, `StorageHelper`, `SearchHelper` | P&S-Tests (Module `publisher`, `event-behavior`, `queue`, `storage`, `search`) |
| `CustomerDataHelper`, `ProductDataHelper`, `PriceProductDataHelper`, `StockDataHelper`, `PersistentQuoteHelper`, … | `have*`-Methoden |

`have*`-Methoden (Code): `ProductDataHelper::haveProduct`, `haveProductConcrete`, `haveProductConcreteWithLocalizedAttributes`, `haveProductAbstract(array $override = [], bool $localized = false)`, `haveFullProduct`; `CustomerDataHelper::haveCustomer`, `haveCustomerTransfer`, `haveConfirmedCustomer`, `haveConfirmedCustomerWithAddress`, `haveCustomerAddress`; außerdem `haveProductInStockForStore`, `havePriceProduct`, `haveAuthorizationToGlue`. Override-Arrays mit Transfer-Konstanten: `haveCustomer([CustomerTransfer::USERNAME => 'x'])`. Die Helper stehen unter `tests/…/_support`, nicht in `src/` und sind keine stabile Public API `[Annahme]`.

**DataBuilder.** `*.databuilder.xml` (Namespace `spryker:databuilder-01`) in `tests/_data/` (Projekt) und `vendor/spryker/*/tests/_data/` (Core, 105 im Release). Pro Property eine Faker-Regel (`dataBuilderRule="unique()->email()"`, `"=DE"` für Konstanten). Suchpfade: `Spryker\Zed\Transfer\TransferConfig` (`APPLICATION_ROOT_DIR/tests/_data`, `vendor/*/*/tests/_data/`). Generieren: `transfer:databuilder:generate` → `Generated\Shared\DataBuilder\<Name>Builder`. Im Release hat das Projekt kein `tests/_data` mit eigenen Dateien (Ablageort aus `TransferConfig` abgeleitet). Neue Test-Properties gehören in die databuilder-XML, nicht in die produktive `transfer.xml` (→ [Transfer](04-transfer.md)).

**Facades testen.** `Codeception\Test\Unit`, `$this->tester` ist der Actor; Arrange per `have*`, Act über Facade, Assert über Rückgabe-Transfer/DB. Mocks nur an Grenzen (Facade, Client, Dependency): `BusinessHelper::mockFactoryMethod('createX', $mock)` oder `DependencyHelper::setDependency(Provider::FACADE_X, $mock)`. Voraussetzung: `KernelConstants::ENABLE_CONTAINER_OVERRIDING` = `(bool)getenv('SPRYKER_TESTING_ENABLED')` (gesetzt in `config/Shared/config_default-docker.dev.php:69` (maßgeblich für `docker.dev`), außerdem `config_default-docker.php:321` und `config_default-docker.production.php:62`; → [Konfiguration](06-konfiguration.md)). Reine Unit-Tests ohne DB: Klasse direkt instanziieren, `$this->createMock(...)` (Beispiel `vendor/spryker/product-storage/tests/…/ProductAbstractStorageWriterTest.php`).

**P&S testen.** Persistence-Suite im `*Storage`/`*Search`-Modul mit `PublishAndSynchronizeHelper`, `EventBehaviorHelper`, `QueueHelper`, `StorageHelper` bzw. `SearchHelper`; sie ersetzen Queue und Redis/Search durch In-Memory-Adapter und machen den Fluss synchron. Assertions (in `publisher/tests/…/PublishAndSynchronizeHelper.php` belegt): `assertEntityIsPublished($event, $publishQueue)`, `assertEntityCanBeManuallyPublished`, `assertEntityIsSynchronizedToStorage|IsUpdatedInStorage|IsRemovedFromStorage($syncQueue)`, dieselben `…ToSearch|…InSearch|…FromSearch`; dazu `StorageHelper::assertStorageHasKey|assertStorageNotHasKey|mockStorageData`. `-vvv` zeigt den Ablauf. Im Projekt gibt es **kein** P&S-Beispiel, nur Core-Tests (→ [Publish & Sync](07-publish-sync.md)).

**Glue-API.** Suite `RestApi`, Actor erbt `SprykerTest\Glue\Testify\Tester\ApiEndToEndTester` (Basis-URL `TestifyConstants::GLUE_APPLICATION_DOMAIN`, `formatFullUrl()`), Test als Cest mit `@depends loadFixtures`, Fixtures-Klasse mit `FixturesBuilderInterface` + `FixturesContainerInterface`, geladen per `$I->loadFixtures(Class::class)`. Auth: `$I->haveAuthorizationToGlue($customerTransfer)` + `$I->amBearerAuthenticated($token)`; Validierung `seeResponseMatchesOpenApiSchema()` (→ [Glue Storefront](12-glue-storefront.md), [Glue Backend](13-glue-backend.md)).

**Docker SDK.** `docker/sdk testing` (Skript `docker/bin/command/cli/testing.sh`): Testing-Modus (Scheduler aus, Webdriver an), wartet auf database, broker, search, key_value_store, session, webdriver, führt dann den Befehl aus. Dauerhaft: `docker/sdk up -t`, `docker/sdk cli -t`; zurück `docker/sdk start`. `deploy.dev.yml`: `docker.testing.region: EU`, `services.webdriver.engine: chromedriver`. CI (`.github/workflows/_codeception.yml`): `docker/sdk boot deploy.ci.….yml` → `docker/sdk up -t` → `docker/sdk testing codecept fixtures` → `docker/sdk console queue:worker:start --stop-when-empty` → `docker/sdk testing codecept run -c codeception.{acceptance|api|functional}.yml`.

**Cypress/Robot.** Cypress: `tests/cypress-tests/` (`cypress/e2e/{api,backoffice,mp,smoke,yves}`, Skripte `cy:open`, `cy:run`, `cy:ci:yves|backoffice|api|mp`, `cy:smoke`), läuft außerhalb von Codeception gegen eine laufende Instanz (`.github/workflows/_cypress.yml`, `deploy.ci.acceptance.mariadb.cypress.yml`); neue UI-Tests besser als Cypress (Doku „E2E Testing with Cypress“). Robot Framework: `vendor/spryker/robotframework-suite-tests`, nur in CI-Deploy-Dateien, keine eigenen Robot-Tests im Projekt `[Annahme]`: Core-QA. Codeception-`Presentation`-Suites existieren nur für wenige Module (AppCatalogGui, CmsGui, NavigationGui, ProductRelation, Tax sowie in `Yves` mehrere Module).

## Anleitung

1. Testmodul anlegen: `tests/PyzTest/Zed/<Modul>/{codeception.yml,_support/<Modul>BusinessTester.php,Business/…Test.php}` (Gerüst: [Template](../templates/codeception-test/README.md)).
2. Suite-Helper in `codeception.yml` listen; Pyz-Projekte nutzen `\PyzTest\Shared\Testify\Helper\Environment` und `LocatorHelper` mit `projectNamespaces: ['Pyz']`.
3. Falls eigene DataBuilder nötig: `tests/_data/<modul>.databuilder.xml`, dann `docker/sdk cli console transfer:generate` und `transfer:databuilder:generate`.
4. Actor-Klassen bauen: `docker/sdk testing codecept build` (Ordner `_generated/` nicht committen).
5. Ausführen: **`docker/sdk testing codecept run -c tests/PyzTest/Zed/<Modul>`** (Modul-Konfiguration; `codecept run Zed/<Modul>` und `run PyzTest/Zed/<Modul>` scheitern mit "Suite ... could not be found", `run tests/PyzTest/Zed/<Modul>` mit "Suite PyzTest was not loaded" `[live verifiziert (202610.0)]`), `-g <Gruppe>` (mehrfach möglich, auch ohne `-c` über alle Module), `-x <Gruppe>`, `-c codeception.functional.yml`. Business-/Facade-Tests laufen lokal (Calculation 15 Tests, Product 6, Console 2, Touch 1: alle grün); `Presentation`-Suiten (Browser, z. B. `Zed/Tax`) brauchen eine WebDriver-Session und enden lokal mit Exit 255 (`switchTo() on null`).
6. API-/Acceptance-Läufe: vorher `docker/sdk testing codecept fixtures`, `docker/sdk console queue:worker:start --stop-when-empty`.
7. Teststil: Methodenname `testWhatShouldHappen`, Blöcke `// Arrange`, `// Act`, `// Assert`, `@group`-Annotationen („Auto-generated group annotations“, eigene Gruppen darunter), `declare(strict_types = 1);`.

Befehle (alle `[live verifiziert (202610.0)]`; `docker/sdk testing` und `up` brauchen ein TTY):
```
docker/sdk up -t                       # Testing-Modus (startet neu)
docker/sdk cli -t | docker/sdk testing # Shell im Testing-Modus
docker/sdk start                       # zurück in den Entwicklungsmodus
docker/sdk testing codecept build
docker/sdk testing codecept fixtures                         # nur `--help` ausgeführt; der Lauf schreibt Testdaten in die DB
docker/sdk testing codecept run
docker/sdk testing codecept run -c codeception.functional.yml -g Calculation   # functional | acceptance | api | ci.functional (ohne -g läuft alles)
docker/sdk testing codecept run -c tests/PyzTest/Zed/Product
docker/sdk testing codecept run -g Calculation -g Console
docker/sdk testing codecept run -c tests/PyzTest/Zed/Product -vvv   # Debug (P&S)
docker/sdk console transfer:databuilder:generate
docker/sdk console queue:worker:start --stop-when-empty
# Cypress (in tests/cypress-tests): npm install; .env aus .env.example; npm run cy:open | cy:run | cy:ci:api | cy:smoke
```

## Beispiele

Vollständige, lauffähig gedachte Dateien liegen im [Template](../templates/codeception-test/README.md). Hier die Muster aus dem Release.

**Suite-Config Business (Projekt)** (`tests/PyzTest/Zed/Product/codeception.yml`, Auszug):
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

**Facade-Test (Core-Muster)** (`vendor/spryker/customer/tests/SprykerTest/Zed/Customer/Business/Facade/HasEmailTest.php`, Kern):
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

**ProductBadge-Facade-Test (Auszug aus dem Template):**
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

**Glue-Cest (Release)** (`tests/PyzTest/Glue/Carts/RestApi/CartsRestApiCest.php`, `requestCarts`):
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
Suite-Config dazu: `tests/PyzTest/Glue/Carts/codeception.yml` (Helper u. a. `GlueRest` mit `depends: PhpBrowser`, `OpenApi3`, `JsonPath`, `AuthRestApiHelper`, `CustomerDataHelper`, `ProductDataHelper`, `DataCleanupHelper` mit `cleanup: false`).

**P&S-Assertions (Muster, kein Release-Code; Event-/Queue-Namen sind Platzhalter):**
```php
public function testSaveEntityIsPublishedAndSynchronized(): void
{
    // Arrange: Entity speichern (Facade/haveX)
    // Act / Assert
    $this->tester->assertEntityIsPublished('Entity.my_table.create', 'publish.my_entity');
    $this->tester->assertEntityIsSynchronizedToStorage('sync.storage.my_entity');
    $this->tester->assertStorageHasKey('my_entity:de:1');
}
```
Für ProductBadge wären Event `Entity.spy_product_badge.create` und Key `product_badge:<id_product_abstract>` einzusetzen (Namen aus [Template transfer-and-propel](../templates/transfer-and-propel/README.md); Queue-Namen im Template publisher-and-synchronization prüfen).

## Fallstricke

- `codecept build` vergessen nach neuer Suite/Actor/Helper → „Class …Actions not found“.
- Transfers/DataBuilder nicht generiert (`transfer:generate`, `transfer:databuilder:generate`).
- Ohne Testing-Modus (`-t`/`docker/sdk testing`) ist `SPRYKER_TESTING_ENABLED` falsch → `ENABLE_CONTAINER_OVERRIDING` aus → `DependencyHelper`/`mockFactoryMethod` greifen nicht; `Environment` nutzt `devtest` nur ohne die Variable.
- `APPLICATION_STORE` (Default `DE` in `Environment`) falsch → Store-/Locale-Fehler; Store-Helper aktivieren.
- Testdaten: `TransactionHelper` (Business) + `DataCleanupHelper`; Glue-Suites nutzen reale Persistenz, `cleanup: false` (Carts-Suite) hinterlässt Daten; Fixtures eindeutig benennen.
- Cest ohne `@depends loadFixtures`: `$this->fixtures` nicht initialisiert. `HomepageCest` ist in `codeception.functional.yml` ausgeschlossen (schlägt nach `Zed/*` fehl).
- `code:sniff:style tests/PyzTest/...` vor `codecept build` bricht mit `Trait ..._generated\...Actions not found` ab; erst bauen, dann sniffen `[live verifiziert (202610.0)]`.
- `*.databuilder.xml` mit anderem `strict`-Wert als die Haupt-Transfer-Definition: `transfer:databuilder:generate` bricht mit `TransferDefinitionMismatchException` ab; ohne erfolgreiche Generierung fehlen auch alle Core-Builder (`Class ...ProductAbstractBuilder not found`) `[live verifiziert (202610.0)]`.
- Glue-Cest mit `seeResponseMatchesOpenApiSchema()` für eine neue Ressource: erst `rest-api:generate:documentation`, sonst `No valid path is found in the schema` `[live verifiziert (202610.0)]`.
- `haveProductAbstract()` legt Produkte ohne Tax Set an: Facade-Aufrufe, die `ProductFacade::findProductAbstractById()` nutzen, werfen `TaxSetNotFoundException` (Read-Observer); in eigenem Code stattdessen `getProductAbstractCollection()` verwenden `[live verifiziert (202610.0)]`.
- Zed-Business-Suiten laufen auch im Normalmodus über `docker/sdk cli vendor/bin/codecept run -c tests/PyzTest/Zed/<Modul>`; der Testing-Modus ist für `DependencyHelper`-Mocks, Webdriver und die Glue-Suiten nötig.
- Core-Tests nicht ändern; Projekt-Tests in `tests/PyzTest/…`.
- Mocks an internen Models brechen bei Core-Updates; nur an Facade/Client/Dependency mocken (→ [Upgradability](28-upgradability.md)).
- `Presentation`-Tests sind langsam, nur mit `WebDriverHelper`/Chrome; neue UI-Tests als Cypress.
- Der Cest für Daten aus Redis braucht ausgeführte Queue-Worker (P&S) vor dem Lauf.

## Checkliste

- [ ] Test im Pfad `tests/PyzTest/<App>/<Modul>/<Suite>/`, Modul-`codeception.yml` vorhanden
- [ ] Helper-Liste nutzt neuen Namespace `…\Business\BusinessHelper` (nicht den deprecated Alias)
- [ ] `codecept build` gelaufen, `_generated/` nicht committet
- [ ] Arrange/Act/Assert, `@group`-Annotationen, `strict_types`
- [ ] Mocks nur an Facade/Client/Dependency
- [ ] Testdaten per `have*`/DataBuilder, Cleanup bzw. Transaktion aktiv
- [ ] Glue-Cests mit `@depends loadFixtures`, Gruppe `EndToEnd`
- [ ] Tests laufen im Testing-Modus, anschließend `docker/sdk start`

## Quellen

Doku (Basis https://docs.spryker.com/docs/, abgerufen 2026-11-18): `dg/dev/guidelines/testing-guidelines/{testing-guidelines,test-framework,setting-up-tests,testify,data-builders,cypress-testing}.html`, `…/executing-tests/testing-the-publish-and-synchronization-process.html`, `dg/dev/sdks/the-docker-sdk/running-tests-with-the-docker-sdk.html`.

Code: `codeception*.yml`, `tests/default.yml`, `test-autoload.php`, `composer.json`, `tests/PyzTest/Zed/Product/`, `tests/PyzTest/Glue/{Carts,ProductLabels}/`, `tests/PyzTest/Shared/Testify/_support/Helper/Environment.php`, `vendor/spryker/testify/tests/SprykerTest/{Shared,Zed,Glue}/Testify/_support/`, `vendor/spryker/{customer,product,product-storage,publisher,event-behavior,queue,storage,search,propel}/tests/`, `vendor/spryker/transfer/src/Spryker/Zed/Transfer/TransferConfig.php`, `config/Shared/config_default-docker.dev.php` (Z. 69), `config/Shared/config_default-docker.php` (Z. 321), `deploy.dev.yml`, `docker/bin/command/cli/testing.sh`, `.github/workflows/{_codeception,_cypress}.yml`, `tests/cypress-tests/`.

Verweise: [Transfer](04-transfer.md), [Publish & Sync](07-publish-sync.md), [Konfiguration](06-konfiguration.md), [Glue Storefront](12-glue-storefront.md), [Coding-Guidelines](27-coding-guidelines.md), [Docker SDK Workflow](30-docker-sdk-workflow.md). Template: [codeception-test](../templates/codeception-test/README.md).

Deprecations/Versionsstand: `\SprykerTest\Zed\Testify\Helper\BusinessHelper` `@deprecated` → `…\Business\BusinessHelper`; `codeception.*.yml` enthalten `settings.suite_class: \PHPUnit_Framework_TestSuite` (Legacy-Name, läuft mit Codeception 5.1.2 offenbar trotzdem, nicht ändern `[Annahme]`); `TransferConfig` hat `@deprecated`-Glob-Methoden (→ [Transfer](04-transfer.md)); `_envs/isolated.yml` in Core-Modulen nur für isolierte Modultests außerhalb des Projekts.
