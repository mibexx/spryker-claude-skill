# Template zed-module (Laufbeispiel ProductBadge)

## Zweck
Vollständiges Zed-Projektmodul `Pyz\Zed\ProductBadge` mit Business-, Communication- und Persistence-Layer: Facade, BusinessFactory, DependencyProvider, Config, Repository, EntityManager, PersistenceFactory, Mapper. Die fremde `ProductFacade` wird ohne Bridge direkt über den Locator bezogen. Hintergrund: [Referenz 02](../../references/02-projektmodul.md).

## Dateiliste (Zielpfade unter dem Projektroot)
```
src/Pyz/Zed/ProductBadge/
├── ProductBadgeConfig.php
├── ProductBadgeDependencyProvider.php
├── Business/
│   ├── ProductBadgeFacade.php
│   ├── ProductBadgeFacadeInterface.php
│   ├── ProductBadgeBusinessFactory.php
│   ├── Creator/ProductBadgeCreator.php + ProductBadgeCreatorInterface.php
│   ├── Reader/ProductBadgeReader.php + ProductBadgeReaderInterface.php
│   ├── Validator/ProductBadgeValidator.php + ProductBadgeValidatorInterface.php
│   └── Exception/InvalidProductBadgeException.php
├── Communication/Controller/GatewayController.php
└── Persistence/
    ├── ProductBadgePersistenceFactory.php
    ├── ProductBadgeRepository.php + ProductBadgeRepositoryInterface.php
    ├── ProductBadgeEntityManager.php + ProductBadgeEntityManagerInterface.php
    └── Propel/Mapper/ProductBadgeMapper.php
```
Nicht enthalten (eigene Templates): Transfer-XML und Propel-Schema (`transfer-and-propel`), Publisher/Synchronization, Client, Back-Office-UI (`back-office-page`), Console, Yves.

## Voraussetzungen (aus anderen Templates)
- Transfers `ProductBadge`, `ProductBadgeCollection`, `ProductBadgeCriteria` (Template `transfer-and-propel`, `transfer:generate` ausgeführt).
- Propel-Klassen `Orm\Zed\ProductBadge\Persistence\SpyProductBadge` und `SpyProductBadgeQuery` (Schema `spy_product_badge` mit Namespace `Orm\Zed\ProductBadge\Persistence`, `propel:install` ausgeführt).

## Platzhalter und Anpassung
- `ProductBadge` -> eigener Modulname (PascalCase), in Ordnern, Klassen, Namespaces, Konstanten (`FACADE_PRODUCT`) und Transfer-Namen ersetzen. Modulname vorher gegen Core prüfen (`ls vendor/spryker | grep -i <slug>`), sonst erbt/überschreibt das Modul den Core (siehe [Referenz 03](../../references/03-core-erweitern.md)).
- `ProductBadgeConfig::ALLOWED_BADGE_KEYS` fachlich anpassen.
- **Keine Bridges im Projekt:** Fremde Facades holt der DependencyProvider direkt über den Locator (`$container->getLocator()->product()->facade()`), der Factory-Getter ist auf das Interface des Fremdmoduls typisiert (`Spryker\Zed\Product\Business\ProductFacadeInterface`). Das entspricht `src/Pyz` und dem Template `back-office-page`. Bridges sind ein Core-Muster (siehe [Referenz 02](../../references/02-projektmodul.md)); das Project-Ruleset des Architecture Sniffers enthält dafür `ProjectNoBridgeRule`.
- `GatewayController` ist nur nötig, wenn ein Client (Yves/Glue) Zed per `ZedRequest` ansprechen soll. Sonst Datei und Ordner `Communication/` weglassen.

## Registrierung
Keine. Das Modul wird per Namenskonvention aufgelöst (`Pyz` steht in `KernelConstants::PROJECT_NAMESPACES`, `config/Shared/config_default.php`). Nach dem Anlegen den Resolver-Cache neu bauen.
Aufruf aus einem anderen Pyz-Modul: im dortigen DependencyProvider `$container->getLocator()->productBadge()->facade()`, dafür IDE-Auto-Completion regenerieren.

## Befehle danach
Alle Befehle liefen im Shop 202610.0 mit Exit 0 `[live verifiziert (202610.0)]`. Projektmodule werden bei `code:sniff:*` per **Pfad** angegeben, nicht per `-m Pyz.<Modul>` (`-m` meint Core-Module und scheitert mit "does not exist"/"No module(s) found", siehe Referenz 27).
```bash
docker/sdk cli console cache:class-resolver:build
docker/sdk cli console dev:ide-auto-completion:generate
docker/sdk cli console code:sniff:style src/Pyz/Zed/ProductBadge
docker/sdk cli console code:sniff:architecture -p 1 -v src/Pyz/Zed/ProductBadge   # legt architecture-baseline.json im Projektroot an (nach dem Lauf löschen), prüft keine Bridges
docker/sdk cli php -d memory_limit=2048M vendor/bin/phpstan analyze -c phpstan.neon src/Pyz/Zed/ProductBadge -l 6   # code:phpstan -m ProductBadge meldete im Lauf "No files found to analyse"
docker/sdk cli vendor/bin/phpmd src/Pyz/Zed/ProductBadge text vendor/spryker/architecture-sniffer/src/Project/ruleset.xml   # Project-Ruleset mit ProjectNoBridgeRule
```

## Vorbilder (Quelle)
- Persistence-Muster (Repository, EntityManager, PersistenceFactory, Mapper): `vendor/spryker/api-key/src/Spryker/Zed/ApiKey/Persistence/`.
- Core-Bridge (nur zur Orientierung, im Projekt nicht nachbauen): `vendor/spryker/cart-note/src/Spryker/Zed/CartNote/Dependency/Facade/CartNoteToQuoteFacadeBridge.php`.
- Gateway: `vendor/spryker/country/src/Spryker/Zed/Country/Communication/Controller/GatewayController.php`.
- Dateikopf: `src/Pyz/Zed/ExampleChart/Plugin/ExampleChart.php`.
- `ProductFacadeInterface::getProductAbstractCollection()`: `vendor/spryker/product/src/Spryker/Zed/Product/Business/ProductFacadeInterface.php`. Der Validator nutzt bewusst nicht `findProductAbstractById()`: dessen Read-Observer (`TaxSetProductAbstractReadPlugin`) werfen `TaxSetNotFoundException` bei Produkten ohne Tax Set (im Codeception-Lauf mit `haveProductAbstract()` aufgetreten), eine Badge-Anlage würde dann mit HTTP 500 enden.

## Verifikationsstatus
Durchgespielt im Shop 202610.0 (Modul installiert, Laufzeit über Back Office, Glue und Codeception geprüft): `code:sniff:style` Exit 0, `code:sniff:architecture` 0 Verstöße, PHPMD (Project-Ruleset) Exit 0, PHPStan Level 6 "No errors", Facade-Test `codecept run -c tests/PyzTest/Zed/ProductBadge` 3 Tests grün. Korrekturen aus dem Lauf: `@method getRepository()`-Annotation im `GatewayController` (sonst `Spryker.MethodAnnotation.RepositoryMethodAnnotation.Missing`), Validator ohne Read-Observer. `[live verifiziert (202610.0)]`
