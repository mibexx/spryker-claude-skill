# Creating a new project module: Facade, Factory, DependencyProvider, Config (bridges only as a core pattern)

> Status: release 202610.0 (spryker/kernel 3.87.1). `[code-verified, docs not cross-checked]`: docs pages (create-modules, facade, dbc, factory, business-models) only roughly retrievable or not at all. Spryk definitions read from `vendor/spryker-sdk/spryk/spryk.phar`, `spryk-dump`, `spryk-run --dry-run` and a real `spryk-run AddModuleDefault` in a throwaway module `[live verified (202610.0)]` (module deleted afterwards, see pitfall below). Gaps: exact Spryk argument values (`--mode`) and interactive prompts; for cache-clearing commands see [25](25-performance-caching.md). The running example is the module **ProductBadge** (full code in [../templates/zed-module/](../templates/zed-module/README.md)).

## Purpose
Create your own Zed module under `src/Pyz/Zed/<Module>/`: mandatory and optional classes, name resolution, dependencies, config, dealing with other modules (direct locator access, bridges only as a core pattern), Spryk scaffolding. Adapting existing core modules: [03](03-extending-the-core.md). Transfer: [04](04-transfer.md), Propel: [05](05-propel-persistence.md), config files: [06](06-configuration.md).

## Concepts

### Structure and registration
There is **no** registration (no `composer.json` per module, no service configuration). `Pyz\` is mapped to `src/Pyz/` via PSR-4, `Pyz` is listed in `KernelConstants::PROJECT_NAMESPACES`. Classes are resolved by name pattern. There are no strictly mandatory classes, only what the layers need:

| File | Base class | When |
|---|---|---|
| `Business/<M>Facade.php` + `Interface` | `Spryker\Zed\Kernel\Business\AbstractFacade` | public API, `@api` |
| `Business/<M>BusinessFactory.php` | `Spryker\Zed\Kernel\Business\AbstractBusinessFactory` | as soon as the Facade uses models |
| `Business/<Folder>/<Model>.php` + `Interface` | - | the logic |
| `<M>DependencyProvider.php` | `Spryker\Zed\Kernel\AbstractBundleDependencyProvider` | only for external dependencies |
| `<M>Config.php` | `Spryker\Zed\Kernel\AbstractBundleConfig` | only for configuration |
| `Dependency/Facade/<M>To<X>FacadeInterface` + `...Bridge` | - | **core only**, do not create in the project (see below) |
| `Persistence/<M>Repository` / `<M>EntityManager` (+Interface) | `AbstractRepository` / `AbstractEntityManager` | reading / writing |
| `Persistence/<M>PersistenceFactory.php` | `AbstractPersistenceFactory` | queries, mappers |
| `Persistence/Propel/Schema/*.schema.xml` | - | see [05](05-propel-persistence.md) |
| `Communication/Controller\|Plugin\|Console/...` | `AbstractController`, `AbstractPlugin`, `Console` | Zed calls, plugins |
| `Communication/<M>CommunicationFactory.php` | `AbstractCommunicationFactory` | when Communication needs dependencies |
| `src/Pyz/Shared/<M>/Transfer/<m>.transfer.xml` | - | see [04](04-transfer.md) |

Reference module in the core: `vendor/spryker/cart-note/src/Spryker/Zed/CartNote/` (Facade, Factory, bridge, EntityManager; the bridge is a core pattern there, do not replicate it in the project). The project counterpart `src/Pyz/Zed/ExampleProductSalePage/` is **not a model to follow** (Facade extends `ProductFacade`, QueryContainer track).

### Resolution by convention
Patterns from `KernelConfig::getResolvableTypeClassNamePatternMap()`: `ZedFacade -> ...\Business\%sFacade`, `ZedFactoryBusiness -> ...\Business\%sBusinessFactory`, `ZedFactoryCommunication -> ...\Communication\%sCommunicationFactory`, `ZedFactoryPersistence -> ...\Persistence\%sPersistenceFactory`, `ZedRepository`, `ZedEntityManager`, `ZedQueryContainer` (legacy), `ZedConfig -> \%s\Zed\%s\%sConfig`, `ZedDependencyProvider -> \%s\Zed\%s\%sDependencyProvider`.
- The module name comes from the namespace of the **calling** class (`ClassInfo`): `Pyz\Zed\Foo\Business\FooFacade` gets `Pyz\Zed\Foo\Business\FooBusinessFactory`. Candidate order project before core: see [01](01-architecture.md).
- Wiring: `AbstractFacade::getFactory()` via `BusinessFactoryResolver`; `getRepository()`/`getEntityManager()` via `RepositoryResolverAwareTrait`/`EntityManagerResolverAwareTrait`; `AbstractRepository`/`AbstractEntityManager::getFactory()` resolve the `PersistenceFactory`; `AbstractFactory` provides `getConfig()`, `getProvidedDependency()`, `getRepository()`, `getEntityManager()`, `getQueryContainer()`.
- The **type** comes only from the `@method` PHPDoc (`@method \Pyz\Zed\Foo\Business\FooBusinessFactory getFactory()`); the base methods are untyped. Annotations belong in every class.
- `[Deprecated]` `Spryker\Zed\Kernel\ClassResolver\Factory\FactoryResolver` (replaced by `BusinessFactoryResolver`/`CommunicationFactoryResolver`/`PersistenceFactoryResolver`): one separate factory per layer.

### DependencyProvider
One overridable method per layer (`AbstractBundleDependencyProvider`): `provideBusinessLayerDependencies(Container)`, `provideCommunicationLayerDependencies(Container)`, `providePersistenceLayerDependencies(Container)`. Each layer has **its own container**; what is only registered in the business layer is not returned by `PersistenceFactory::getProvidedDependency()` (`ContainerKeyNotFoundException`).
- Values are closures (invoked on the first `get()` and cached). For "new every time" (Propel query, mutable objects): `$container->factory(function () {...})`.
- Other modules' Facades: `$container->getLocator()->product()->facade()`; clients `->client()`, services `->service()`. In the project without a bridge, the factory getter is typed to the other module's interface.
- Constant names are convention: `FACADE_<MODULE>`, `CLIENT_<MODULE>`, `SERVICE_<MODULE>`, `PLUGINS_<X>`, `PROPEL_QUERY_<X>`.
- Plugin stacks see [03](03-extending-the-core.md). The old `DependencyInjector` mechanism is legacy (`[Assumption]`: do not use in new modules).

### Config
`Spryker\Zed\Kernel\AbstractBundleConfig`: `get($key, $default)` reads `config/Shared/config_*.php`. The config is passed as a constructor parameter to the **models** via the factory (`new Model($this->getConfig())`); models do not fetch it themselves. Values and constants: [06](06-configuration.md).

### Other modules in the project: direct locator access, bridges only in the core
- **Project rule:** In the project (`src/Pyz`) there are no bridges. The DependencyProvider fetches the other module's Facade, client or service directly via the locator (`$container->getLocator()->product()->facade()`); the factory getter is typed to the other module's interface (`Spryker\Zed\Product\Business\ProductFacadeInterface`, `Spryker\Client\Storage\StorageClientInterface`). This is how the `src/Pyz` DependencyProviders in the release work (`src/Pyz/Zed/Customer/CustomerDependencyProvider.php`, `src/Pyz/Zed/DataImport/DataImportDependencyProvider.php`) and the templates (`zed-module`, `publisher-and-synchronization`, `back-office-page`).
- **Core pattern (for explanation only):** Core modules wrap every other module's Facade/client/service in `Dependency/...Interface` + `...Bridge` (around 868 Facade bridges in the release). Reason: the module declares only the methods it needs and absorbs major changes of the other module. The bridge constructor is **untyped** (`$quoteFacade`) to avoid creating a hard dependency; typing is done via PHPDoc. Example: `vendor/spryker/cart-note/src/Spryker/Zed/CartNote/Dependency/Facade/CartNoteToQuoteFacadeBridge.php`. Spryk offers bridges only in core mode (`AddDependencyBridgeMethod`, `mode: core`).
- The Architecture Sniffer has the `ProjectNoBridgeRule` for this in the **project ruleset**. By default `code:sniff:architecture` runs with the core ruleset (`DevelopmentConfig::getArchitectureSnifferRuleset()`, not overridden in Pyz) and therefore does not report bridges. The project ruleset is invoked like this: `vendor/bin/phpmd src/Pyz text vendor/spryker/architecture-sniffer/src/Project/ruleset.xml` (see [27](27-coding-guidelines.md)).
- Never: instantiate other modules' models/repositories/entities/QueryContainers, `new FooFacade()` or `Locator::getInstance()` in business models.

## Guide
1. Check the name: `ls vendor/spryker | grep -i <slug>` and `ls src/Pyz/Zed`. If the name matches a core module, you get an extension (see [03](03-extending-the-core.md)).
2. Create the scaffold: manually or via Spryk (below).
3. Facade interface with `@api` and Specification; the Facade delegates to BusinessFactory models.
4. BusinessFactory: `create*` methods for models, typed `get*` getters for dependencies, `@method` annotations.
5. DependencyProvider: constant + `add...` method per dependency in the **correct layer**, call `parent::provide...` first.
6. Config only for values, access via the factory.
7. Persistence: Repository (read), EntityManager (write), PersistenceFactory (query, entity, mapper) with the Propel schema from [05](05-propel-persistence.md).
8. Transfers in `Shared/<Module>/Transfer/*.transfer.xml`, `transfer:generate` ([04](04-transfer.md)).
9. Rebuild the resolver cache, regenerate IDE auto-completion, run the sniffers.

### Spryk scaffolding
Tool `vendor/spryker-sdk/spryk/` (`vendor/bin/spryk-run`, `spryk-dump`, `spryk-build`). Arguments: Spryk name, `targetModule` (format `[Organization.]Module[.Layer]`), `dependentModule`; options `--include-optional/-i`, `--dry-run/-d`, `--mode=project`, `--organization=Pyz`.
- `AddModuleDefault` (creates module, client, shared transfer schema, configs, DependencyProvider, Facade, CommunicationFactory, EntityManager, Repository), `AddZedBusinessFacade`, `AddZedBusinessFacadeMethod` (`facadeMethod`, `input`, `output`, `comment`), `AddZedBusinessFactory`, `AddZedDependencyProvider`, `AddZedPersistenceRepository`, `AddZedPersistenceEntityManager`, `AddZedPersistenceFactory`.
- **Trap:** The argument `extends` uses `ResolveExtends` and takes the **first existing class** in the list (the core class with the same module name first). A Pyz module with a core module name therefore extends the core Facade.
- `AddDependencyBridgeMethod` only `mode: core`; the older `AddZedDependency*Bridge` Spryks are `[Deprecated]` ("Use AddDependencyBridgeMethod instead", 46 deprecated Spryks in total).
- `dev:module:create` and `dev:bridge:create` are **not registered** in the Zed command list of 202610.0 `[live verified (202610.0)]` (the class `ModuleCreateConsole` is deprecated). Therefore create modules manually or via Spryk, bridges not at all (project rule).
- Custom Spryks: `config/spryk/spryks/`, templates `config/spryk/templates/` (not present in the project).

Commands (all executed `[live verified (202610.0)]`):
```bash
docker/sdk cli vendor/bin/spryk-dump --level=all
docker/sdk cli vendor/bin/spryk-dump AddZedBusinessFacade                # options of a Spryk
docker/sdk cli vendor/bin/spryk-run AddModuleDefault Pyz.ProductBadge --mode=project --organization=Pyz --dry-run
# Facade method: call as ONE string (otherwise `docker/sdk cli` loses the quotes), input = type + variable, types fully qualified, otherwise the module's namespace is prepended:
docker/sdk cli "vendor/bin/spryk-run AddZedBusinessFacadeMethod --module=ProductBadge --organization=Pyz --mode=project --facadeMethod=createProductBadge --comment='Creates a product badge.' --input='\\Generated\\Shared\\Transfer\\ProductBadgeTransfer \$productBadgeTransfer' --output='\\Generated\\Shared\\Transfer\\ProductBadgeTransfer' --no-interaction"
docker/sdk cli console cache:class-resolver:build
docker/sdk cli console dev:ide-auto-completion:generate
docker/sdk cli console code:sniff:style src/Pyz/Zed/ProductBadge        # project code by path; `-m Pyz.X` is wrong (means vendor/pyz/x), see 27
docker/sdk cli console code:sniff:architecture -p 1 src/Pyz/Zed/ProductBadge   # core ruleset, no bridge check
docker/sdk cli vendor/bin/phpmd src/Pyz/Zed/ProductBadge text vendor/spryker/architecture-sniffer/src/Project/ruleset.xml   # project ruleset incl. ProjectNoBridgeRule
docker/sdk cli vendor/bin/phpstan                                       # phpstan.neon, level 6
```
Check the argument list before the first run with `spryk-dump <Name>` and `--dry-run` `[live verified (202610.0)]`. **Pitfalls (live):** `AddModuleDefault` creates client, Zed, Shared and test configuration (without `--dry-run` also Codeception build, style fix, `propel:install`, `transfer:generate`) and writes an **empty** `src/Pyz/Shared/<Module>/Transfer/<module>.transfer.xml` (`<transfers>` without `<transfer>`); afterwards `transfer:generate` fails with `ErrorException: Undefined array key "transfer"` until you add a transfer there or delete the file. `AddZedBusinessFacadeMethod` adds Facade, interface and a test (`assertTrue(false)` as a placeholder). Delete the throwaway module again and re-run `transfer:generate`, `transfer:entity:generate`, `transfer:databuilder:generate`, `cache:class-resolver:build`.

## Examples
All files in full in the template [../templates/zed-module/](../templates/zed-module/README.md) (path `src/Pyz/Zed/ProductBadge/...`, file header like `src/Pyz/Zed/ExampleChart/Plugin/ExampleChart.php`, shown below without header). Core pieces:

`Business/ProductBadgeFacadeInterface.php` (excerpt)
```php
interface ProductBadgeFacadeInterface
{
    /**
     * Specification:
     * - Requires `ProductBadgeTransfer.fkProductAbstract`, `badgeKey` and `label`.
     * - Throws `InvalidProductBadgeException` if the validation fails.
     * - Returns the persisted badge with `idProductBadge` set.
     *
     * @api
     */
    public function createProductBadge(ProductBadgeTransfer $productBadgeTransfer): ProductBadgeTransfer;

    /**
     * @api
     */
    public function getProductBadgeCollection(ProductBadgeCriteriaTransfer $productBadgeCriteriaTransfer): ProductBadgeCollectionTransfer;
}
```
`Business/ProductBadgeFacade.php` (excerpt): no logic, only delegation.
```php
/**
 * @method \Pyz\Zed\ProductBadge\Business\ProductBadgeBusinessFactory getFactory()
 */
class ProductBadgeFacade extends AbstractFacade implements ProductBadgeFacadeInterface
{
    public function createProductBadge(ProductBadgeTransfer $productBadgeTransfer): ProductBadgeTransfer
    {
        return $this->getFactory()->createProductBadgeCreator()->createProductBadge($productBadgeTransfer);
    }
}
```
`Business/ProductBadgeBusinessFactory.php` (excerpt): `create*` for models, `get*` for dependencies.
```php
public function createProductBadgeCreator(): ProductBadgeCreatorInterface
{
    return new ProductBadgeCreator($this->createProductBadgeValidator(), $this->getEntityManager());
}

public function getProductFacade(): ProductFacadeInterface
{
    return $this->getProvidedDependency(ProductBadgeDependencyProvider::FACADE_PRODUCT);
}
```
`ProductBadgeDependencyProvider.php` (excerpt), Facade directly via the locator:
```php
public function provideBusinessLayerDependencies(Container $container): Container
{
    $container = parent::provideBusinessLayerDependencies($container);
    $container = $this->addProductFacade($container);

    return $container;
}

protected function addProductFacade(Container $container): Container
{
    $container->set(static::FACADE_PRODUCT, function (Container $container) {
        return $container->getLocator()->product()->facade();
    });

    return $container;
}
```
`ProductFacadeInterface` is `Spryker\Zed\Product\Business\ProductFacadeInterface`. There is no bridge in the project (core pattern only, see above).

Persistence pattern (from `vendor/spryker/api-key/.../Persistence/`): `ProductBadgeEntityManager::createProductBadge()` maps transfer -> entity (`SpyProductBadge`), `save()`, maps back; `ProductBadgeRepository::getProductBadgeCollection()` builds the query via `getFactory()->createProductBadgeQuery()`, filters `filterByBadgeKey_In(...)` and maps via `ProductBadgeMapper`. Query, entity and mapper are created in `ProductBadgePersistenceFactory`.

Core model for a fresh query in the DependencyProvider (`vendor/spryker/cart-note/.../CartNoteDependencyProvider.php`):
```php
$container->set(static::SALES_ORDER_QUERY, $container->factory(function () {
    return SpySalesOrderQuery::create();
}));
```

## Pitfalls
| Error | Cause | Fix |
|---|---|---|
| `FacadeNotFoundException`, `BusinessFactoryNotFoundException`, `DependencyProviderNotFoundException` | Name/folder/namespace deviates from the pattern; resolver cache points to an old target | Check the pattern, `cache:class-resolver:build` |
| `ContainerKeyNotFoundException` | Dependency registered in the wrong layer | register in the matching `provide...LayerDependencies` |
| Autocomplete missing, PHPStan "undefined method" | `@method` annotation missing/wrong | add the annotation |
| New module "inherits" core behavior | Module name = core module, `ResolveExtends` sets the core as parent | choose a different name or deliberately as an extension ([03](03-extending-the-core.md)) |
| Propel query/mutable object reused | `container->set()` without `factory()` | `$container->factory(...)` |
| Facade calls other modules' models/repositories | Layer violation | via the other module's Facade (locator in the DependencyProvider) |
| `new MyFacade()` in production code | bypasses locator/test overrides | register the Facade in the calling DependencyProvider |
| Spryk used in core mode | generates into `vendor` | `--mode=project --organization=Pyz`, `--dry-run` first |
| Bridge created in the project module | Core pattern copied | remove the bridge, fetch the Facade directly via the locator and type the factory getter to the other module's interface (`ProjectNoBridgeRule` in the project ruleset) |

Upgrade anti-patterns: changes under `vendor/`; Pyz Facade extends the core Facade only to add methods; access to non-`@api` classes of other modules; copying DependencyProvider logic instead of `parent::provide...` (see [28](28-upgradability.md), weighting `[Assumption]`).

## Checklist
- [ ] Name checked (`ls vendor/spryker | grep -i`, `ls src/Pyz/Zed`), PascalCase, matches folder and namespace `Pyz\Zed\<Module>`
- [ ] Facade + interface (`@api`, Specification, transfers as parameters), delegates to factory models
- [ ] BusinessFactory with `create*` and typed dependency getters, `@method` annotations
- [ ] DependencyProvider: constants + `add...`, closure, `factory()` for queries, correct layer, `parent::` called
- [ ] Config only for values, access via the factory
- [ ] Other modules only via Facade/client/service directly from the locator, no bridge, getter typed to the other module's interface
- [ ] Repository (read) + EntityManager (write) + PersistenceFactory
- [ ] Transfers defined, `transfer:generate` run
- [ ] `declare(strict_types = 1);`, types, PHPStan level 6 green
- [ ] `code:sniff:style` and `code:sniff:architecture` without findings, plus the project ruleset (`phpmd ... Project/ruleset.xml`) ([27](27-coding-guidelines.md))
- [ ] Tests under `tests/PyzTest/...` ([26](26-tests.md))

## Sources
Docs (base `https://docs.spryker.com/docs/`, only roughly checked): `dg/dev/backend-development/extend-spryker/create-modules.html`, `.../zed/business-layer/facade/facade.html`, `.../facade/design-by-contract-dbc-facade.html`, `.../factory/factory.html` (not retrievable), `.../factory/inject-dependencies-within-factories-container-globals.html` (not retrievable), `dg/dev/sdks/sdk/spryks/adding-spryks.html` (replaced by `vendor/spryker-sdk/spryk/README.md`).

Code: `vendor/spryker/kernel/src/Spryker/Zed/Kernel/{AbstractBundleConfig,AbstractBundleDependencyProvider,AbstractFactory,Container,Locator}.php`, `.../Business/{AbstractFacade,AbstractBusinessFactory}.php`, `.../Persistence/{AbstractRepository,AbstractEntityManager,AbstractPersistenceFactory}.php`, `.../ClassResolver/**`, `vendor/spryker/kernel/src/Spryker/Shared/Kernel/KernelConfig.php`, `vendor/spryker/cart-note/src/Spryker/Zed/CartNote/**`, `vendor/spryker/api-key/src/Spryker/Zed/ApiKey/Persistence/**`, `vendor/spryker/product/src/Spryker/Zed/Product/Business/ProductFacadeInterface.php`, `src/Pyz/Zed/ExampleProductSalePage/**`, `src/Pyz/Zed/Customer/CustomerDependencyProvider.php`, `vendor/spryker-sdk/spryk/{README.md,spryk.phar}`, `vendor/spryker/architecture-sniffer/src/Project/ruleset.xml`.

Neighbors: [01 Architecture](01-architecture.md), [03 Extending the core](03-extending-the-core.md), [04 Transfer](04-transfer.md), [05 Propel](05-propel-persistence.md), [06 Configuration](06-configuration.md), template [zed-module](../templates/zed-module/README.md).
