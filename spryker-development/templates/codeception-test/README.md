# Template codeception-test

Facade-Business-Test (DB-Fixtures, Rollback je Test) und Glue-RestApi-Cest für das Laufbeispiel **ProductBadge**. Hintergrund: [Tests](../../references/26-tests.md), Gegenstück zu den Templates [zed-module](../zed-module/README.md), [transfer-and-propel](../transfer-and-propel/README.md) und [glue-resource-storefront](../glue-resource-storefront/README.md).

## Zweck

- `tests/PyzTest/Zed/ProductBadge/`: Codeception-Modul-Konfiguration, Actor ("Tester"), Daten-Helper und ein Facade-Test gegen `Pyz\Zed\ProductBadge\Business\ProductBadgeFacadeInterface` (`createProductBadge`, `getProductBadgeCollection`) inklusive Negativfall (nicht erlaubter `badgeKey` → `InvalidProductBadgeException`).
- `tests/PyzTest/Glue/ProductBadges/`: End-to-End-Cest gegen die Ressource `product-badges` aus dem Template glue-resource-storefront (Fixtures-Klasse, Actor, Suite-Config).
- `tests/_data/product_badge.databuilder.xml`: DataBuilder-Regeln für `ProductBadgeBuilder`.

## Dateiliste (Zielpfad unter Projektroot)

| Datei | Zweck |
|---|---|
| `tests/_data/product_badge.databuilder.xml` | DataBuilder (`badgeKey` aus der erlaubten Liste, `label`) |
| `tests/PyzTest/Zed/ProductBadge/codeception.yml` | Modul-Config, Suite `Business` mit Helper-Liste |
| `tests/PyzTest/Zed/ProductBadge/_support/ProductBadgeBusinessTester.php` | Actor (`use _generated\ProductBadgeBusinessTesterActions`, entsteht durch `codecept build`) |
| `tests/PyzTest/Zed/ProductBadge/_support/Helper/ProductBadgeDataHelper.php` | `haveProductBadge()`, `getProductBadgeFacade()`, Cleanup |
| `tests/PyzTest/Zed/ProductBadge/Business/ProductBadgeFacadeTest.php` | Facade-Test (Arrange/Act/Assert) |
| `tests/PyzTest/Glue/ProductBadges/codeception.yml` | Suite `RestApi` |
| `tests/PyzTest/Glue/ProductBadges/_support/ProductBadgesApiTester.php` | Actor mit `buildProductBadgesUrl()` |
| `tests/PyzTest/Glue/ProductBadges/RestApi/ProductBadgesRestApiFixtures.php` | Fixtures (`FixturesBuilderInterface`, `FixturesContainerInterface`) |
| `tests/PyzTest/Glue/ProductBadges/RestApi/ProductBadgesRestApiCest.php` | Cest (`@depends loadFixtures`, Gruppe `EndToEnd`) |

## Platzhalter / Anpassung

- `ProductBadge`/`ProductBadges` durch den eigenen Modulnamen ersetzen: Ordner, Namespaces `PyzTest\Zed\<Modul>`, Actor-Name `<Modul>BusinessTester`, Glue-Ressource.
- Der Modulname im Namespace (`PyzTest\Zed\ProductBadge`, dritter Teil) ist maßgeblich: Der `BusinessHelper` leitet daraus das Modul ab und löst `Pyz\Zed\ProductBadge\Business\ProductBadgeFacade` auf (`PyzTest` → Organisation `Pyz`).
- Erlaubte Badge-Keys (`new`, `sale`, `bestseller`) stammen aus `ProductBadgeConfig::getAllowedBadgeKeys()` des Templates zed-module; bei Änderung dort die DataBuilder-Regel und die Konstanten im Test anpassen.
- Der Glue-Cest setzt veröffentlichte Daten voraus (Storage-Key `product_badge:<id_product_abstract>`, siehe transfer-and-propel). Ohne Publish & Sync und Queue-Worker schlägt der positive Fall fehl (Ablauf aus `.github/workflows/_codeception.yml`, live bestätigt; zusätzlich muss `rest-api:generate:documentation` gelaufen sein, sonst scheitert `seeResponseMatchesOpenApiSchema` mit `No valid path is found in the schema`).

## Registrierung

Keine Registrierung im Code. Codeception findet das Modul über `include: tests/PyzTest/*/*` in der Root-`codeception.yml` (und `tests/PyzTest/Zed/*` in `codeception.functional.yml`, `tests/PyzTest/*/*` in `codeception.api.yml` mit Filter `Glue`/`EndToEnd`). Der Actor wird über `test-autoload.php` aus `_support/` geladen (`PyzTest\<App>\<Modul>\X` → `tests/PyzTest/<App>/<Modul>/_support/X.php`); der Helper `PyzTest\Zed\ProductBadge\Helper\ProductBadgeDataHelper` liegt deshalb in `_support/Helper/`.

## Befehle danach

Alle Befehle liefen im Shop 202610.0 (Testing-Modus per `script -q /dev/null docker/sdk up -t`, danach `docker/sdk up` zurück in den Normalmodus) `[live verifiziert (202610.0)]`; Testing-Modus siehe [Tests](../../references/26-tests.md) und [Docker SDK Workflow](../../references/30-docker-sdk-workflow.md).
```
docker/sdk cli console transfer:generate                  # ProductBadge-Transfers
docker/sdk cli console transfer:databuilder:generate      # ProductBadgeBuilder aus tests/_data (dauert, ~1880 Builder; VOR dem ersten Testlauf)
docker/sdk testing codecept build                         # Actor-Klassen (_generated), danach erst code:sniff auf tests/
docker/sdk testing codecept run -c tests/PyzTest/Zed/ProductBadge   # 3 Tests; "codecept run Zed/ProductBadge" ist falsch (Suite not found)
docker/sdk testing codecept run -g ProductBadgeFacadeTest
docker/sdk testing codecept fixtures                      # vor Glue-API-Läufen, legt Fixture-Zeilen in der DB an
docker/sdk console queue:worker:start --stop-when-empty   # P&S abarbeiten (wie CI)
docker/sdk console rest-api:generate:documentation        # OpenAPI-Schema für seeResponseMatchesOpenApiSchema
docker/sdk testing codecept run -c codeception.api.yml -g ProductBadgesRestApiCest
docker/sdk console code:sniff:style tests/PyzTest/Zed/ProductBadge   # nach codecept build
```
Voraussetzung: Tabelle `spy_product_badge` existiert (`propel:install`), für den Glue-Cest Container im Testing-Modus (`docker/sdk up -t` bzw. `docker/sdk testing`). Die Zed-Business-Suite läuft auch im Normalmodus über `docker/sdk cli vendor/bin/codecept run -c tests/PyzTest/Zed/ProductBadge` (live bestätigt für das Modul `OrderPaymentCheck`).

Live-Ergebnis (202610.0): Facade-Test 3 Tests, 8 Assertions grün; Glue-Cest 3 Tests, 8 Assertions grün (nach Fixtures, Worker und Schema-Generierung); `codecept fixtures` 37 Tests (alle Suites) in 13 s. Korrekturen aus dem Lauf: DataBuilder-XML mit `strict="true"` (sonst `TransferDefinitionMismatchException` in `transfer:databuilder:generate`), Facade-Validator ohne `findProductAbstractById()` (Tax-Set-Observer, siehe `zed-module`).

## Verwendete Quelle (Vorbilder im Release)

- Suite-Config und Actor: `tests/PyzTest/Zed/Product/{codeception.yml,_support/ProductBusinessTester.php}`, `tests/PyzTest/Glue/ProductLabels/codeception.yml`, `tests/PyzTest/Glue/Carts/RestApi/CartsRestApiCest.php`.
- Helper-Muster: `vendor/spryker/product/tests/SprykerTest/Shared/Product/_support/Helper/ProductDataHelper.php` (`haveProductAbstract`, `haveFullProduct`), `DataCleanupHelperTrait`, `LocatorHelperTrait`.
- Business-Helper: `vendor/spryker/testify/tests/SprykerTest/Zed/Testify/_support/Helper/Business/BusinessHelper.php` (der Alias `\SprykerTest\Zed\Testify\Helper\BusinessHelper` ist `@deprecated`, im Template wird der neue Namespace genutzt).
- Environment-Helper (Projekt): `tests/PyzTest/Shared/Testify/_support/Helper/Environment.php`.

## Verifikationsstatus

Durchgespielt im Shop 202610.0: Facade-Test (3 Tests) und Glue-Cest (3 Tests) grün, `code:sniff:style` auf beide Testverzeichnisse Exit 0 (nach `codecept build`; vorher bricht der Sniffer mit `Trait ..._generated\...Actions not found` ab). Die offenen Punkte der Vorfassung sind geklärt:
- Die Helper-Liste im Business-Suite reicht für `haveProductAbstract()` aus (Test grün).
- `BusinessHelper` vor `LocatorHelper` funktioniert; der Test nutzt `ProductBadgeDataHelper::getProductBadgeFacade()`.
- Faker-Formatter `randomElement`, `word` in `product_badge.databuilder.xml` werden von `transfer:databuilder:generate` akzeptiert (nach Korrektur `strict="true"`).
- P&S-Test (Persistence-Suite mit `PublishAndSynchronizeHelper`) ist bewusst nicht enthalten: Im Release belegen nur Core-Tests (`vendor/spryker/publisher/tests/...`) das Muster, im Projekt gibt es kein Beispiel. Der Publish-Pfad wurde stattdessen live über Import, Back Office und Glue-Backend geprüft (siehe `publisher-and-synchronization`). Muster und Assertion-Methoden → [Tests](../../references/26-tests.md).
`[live verifiziert (202610.0)]`
