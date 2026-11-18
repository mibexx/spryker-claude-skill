# Template zed-module (running example ProductBadge)

## Purpose
Complete Zed project module `Pyz\Zed\ProductBadge` with Business, Communication and Persistence layers: Facade, BusinessFactory, DependencyProvider, Config, Repository, EntityManager, PersistenceFactory, Mapper. The foreign `ProductFacade` is obtained without a bridge directly via the locator. Background: [Reference 02](../../references/02-project-module.md).

## File list (target paths below the project root)
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
Not included (separate templates): transfer XML and Propel schema (`transfer-and-propel`), Publisher/Synchronization, Client, Back Office UI (`back-office-page`), Console, Yves.

## Prerequisites (from other templates)
- Transfers `ProductBadge`, `ProductBadgeCollection`, `ProductBadgeCriteria` (template `transfer-and-propel`, `transfer:generate` executed).
- Propel classes `Orm\Zed\ProductBadge\Persistence\SpyProductBadge` and `SpyProductBadgeQuery` (schema `spy_product_badge` with namespace `Orm\Zed\ProductBadge\Persistence`, `propel:install` executed).

## Placeholders and customization
- `ProductBadge` -> your own module name (PascalCase); replace in folders, classes, namespaces, constants (`FACADE_PRODUCT`) and transfer names. Check the module name against the core beforehand (`ls vendor/spryker | grep -i <slug>`), otherwise the module inherits/overrides the core (see [Reference 03](../../references/03-extending-the-core.md)).
- Adjust `ProductBadgeConfig::ALLOWED_BADGE_KEYS` to the business requirements.
- **No bridges in the project:** the DependencyProvider fetches foreign Facades directly via the locator (`$container->getLocator()->product()->facade()`), the Factory getter is typed to the foreign module's interface (`Spryker\Zed\Product\Business\ProductFacadeInterface`). This matches `src/Pyz` and the template `back-office-page`. Bridges are a core pattern (see [Reference 02](../../references/02-project-module.md)); the Architecture Sniffer's project ruleset contains `ProjectNoBridgeRule` for this.
- `GatewayController` is only needed if a Client (Yves/Glue) should address Zed via `ZedRequest`. Otherwise omit the file and the `Communication/` folder.

## Registration
None. The module is resolved by naming convention (`Pyz` is in `KernelConstants::PROJECT_NAMESPACES`, `config/Shared/config_default.php`). After creating it, rebuild the resolver cache.
Call from another Pyz module: in that module's DependencyProvider `$container->getLocator()->productBadge()->facade()`; regenerate IDE auto-completion for this.

## Commands afterwards
All commands ran in shop 202610.0 with exit 0 `[live verified (202610.0)]`. Project modules are specified for `code:sniff:*` by **path**, not by `-m Pyz.<Module>` (`-m` means core modules and fails with "does not exist"/"No module(s) found", see reference 27).
```bash
docker/sdk cli console cache:class-resolver:build
docker/sdk cli console dev:ide-auto-completion:generate
docker/sdk cli console code:sniff:style src/Pyz/Zed/ProductBadge
docker/sdk cli console code:sniff:architecture -p 1 -v src/Pyz/Zed/ProductBadge   # creates architecture-baseline.json in the project root (delete after the run), does not check bridges
docker/sdk cli php -d memory_limit=2048M vendor/bin/phpstan analyze -c phpstan.neon src/Pyz/Zed/ProductBadge -l 6   # code:phpstan -m ProductBadge reported "No files found to analyse" in the run
docker/sdk cli vendor/bin/phpmd src/Pyz/Zed/ProductBadge text vendor/spryker/architecture-sniffer/src/Project/ruleset.xml   # project ruleset with ProjectNoBridgeRule
```

## Models (source)
- Persistence pattern (Repository, EntityManager, PersistenceFactory, Mapper): `vendor/spryker/api-key/src/Spryker/Zed/ApiKey/Persistence/`.
- Core bridge (for orientation only, do not recreate in the project): `vendor/spryker/cart-note/src/Spryker/Zed/CartNote/Dependency/Facade/CartNoteToQuoteFacadeBridge.php`.
- Gateway: `vendor/spryker/country/src/Spryker/Zed/Country/Communication/Controller/GatewayController.php`.
- File header: `src/Pyz/Zed/ExampleChart/Plugin/ExampleChart.php`.
- `ProductFacadeInterface::getProductAbstractCollection()`: `vendor/spryker/product/src/Spryker/Zed/Product/Business/ProductFacadeInterface.php`. The validator deliberately does not use `findProductAbstractById()`: its read observers (`TaxSetProductAbstractReadPlugin`) throw `TaxSetNotFoundException` for products without a tax set (occurred in the Codeception run with `haveProductAbstract()`), and creating a badge would then end with HTTP 500.

## Verification status
Walked through in shop 202610.0 (module installed, runtime checked via Back Office, Glue and Codeception): `code:sniff:style` exit 0, `code:sniff:architecture` 0 violations, PHPMD (project ruleset) exit 0, PHPStan level 6 "No errors", Facade test `codecept run -c tests/PyzTest/Zed/ProductBadge` 3 tests green. Corrections from the run: `@method getRepository()` annotation in `GatewayController` (otherwise `Spryker.MethodAnnotation.RepositoryMethodAnnotation.Missing`), validator without read observers. `[live verified (202610.0)]`
