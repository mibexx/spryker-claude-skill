# Architecture fundamentals: applications, layers, core vs. Pyz

> Status: release b2b-demo-shop 202610.0 (spryker/kernel 3.87.1). `[code-verified, docs not cross-checked]`: the docs pages (modules-and-application-layers, code-buckets, module-api) were only retrievable as a short version or not at all. Statements come from `vendor/` and `src/Pyz`. Gaps: role of `public/Zed` and `public/Glue` (legacy entry points) not checked, `PriceUS` as a code bucket example not checked. The commands were executed in the container `[live verified (202610.0)]`.

## Purpose
Orientation before writing code: which application/layer is responsible, which call paths are allowed, how Spryker finds Pyz classes before core classes. Follow-up topics: new module [02](02-project-module.md), extending the core [03](03-extending-the-core.md), configuration [06](06-configuration.md), Transfer [04](04-transfer.md), persistence [05](05-propel-persistence.md).

## Concepts

### Applications (entry points)
Each application has `public/<App>/index.php` with its own bootstrap and its own endpoint in `deploy.yml` (`groups: … applications:`). Flow of the application entry points (Yves, Backoffice, Glue, …): `Environment::initialize()` -> `ErrorHandlerEnvironment::initialize()` -> `new XBootstrap()` -> `->boot()->run()`. Exception `public/Configurator/index.php`: no `Environment::initialize()` and no bootstrap, it loads `vendor/autoload.php`, calls `new ConfiguratorPage()` and sends `->render()` directly.

| deploy.yml | Entry point | Bootstrap | `APPLICATION` |
|---|---|---|---|
| `yves` (Storefront) | `public/Yves/index.php` | `Pyz\Yves\ShopApplication\YvesBootstrap` (extends `SprykerShop\Yves\ShopApplication\Bootstrap\YvesBootstrap`) | `YVES` |
| `backoffice` (Zed UI) | `public/Backoffice/index.php` | `Spryker\Zed\Application\Communication\Bootstrap\BackofficeBootstrap` | `ZED` |
| `backend-gateway` (Zed gateway, internal) | `public/BackendGateway/index.php` | `Spryker\Zed\Application\Communication\Bootstrap\BackendGatewayBootstrap` | `ZED` |
| `zed` (legacy) | `public/Zed/index.php` | `[Assumption]` combined legacy entry point, not checked | `ZED` |
| `glue` (legacy) | `public/Glue/index.php` | not checked, see [12](12-glue-storefront.md)/[13](13-glue-backend.md) | |
| `glue-storefront` | `public/GlueStorefront/index.php` | `Pyz\Glue\GlueApplication\Bootstrap\GlueStorefrontApiBootstrap` | `GLUE_STOREFRONT` |
| `glue-backend` | `public/GlueBackend/index.php` | see [13](13-glue-backend.md) | |

Further folders: `public/BackendApi/`, `public/Configurator/`.

### Six namespace layers per module
`src/Spryker/<Layer>/<Module>` with layer = **Yves, Zed, Glue, Client, Service, Shared** (evidence: `vendor/spryker/kernel/src/Spryker/{Yves,Zed,Glue,Client,Service,Shared}/Kernel/`). `src/Pyz/` mirrors the same six folders.

- **Yves**: Storefront (controllers, Twig, plugins). No own DB, no business logic.
- **Zed**: Back Office, business logic, database (Propel), gateway for Yves/Glue.
- **Glue**: REST/JSON API.
- **Client**: access layer for Yves/Glue to storage (Redis), search and Zed (HTTP RPC via `ZedRequest`), see [08](08-client-storage-search.md).
- **Service**: stateless helpers without persistence, usable from all layers (e.g. `src/Pyz/Service/Synchronization`).
- **Shared**: constants, config, Transfer definitions; only what several applications need in the same way.

### Allowed communication paths
- **Yves/Glue -> Client -> (Storage | Search | ZedRequest -> Zed gateway)**. Yves never calls Zed classes or Propel directly. Evidence: `CustomerClient::registerCustomer()` -> `CustomerFactory::createZedCustomerStub()` -> `CustomerStub` -> `ZedRequestClient::call('/customer/gateway/...', $transfer)` -> `Spryker\Zed\Customer\Communication\Controller\GatewayController::registerAction()` -> `getFacade()`.
- **Zed module -> Zed module**: only via the **Facade**, provided in the DependencyProvider. The core modules encapsulate the access with a bridge (core pattern, explained in [02](02-project-module.md)); in the project the DependencyProvider fetches the Facade directly via the locator. Never access business models, repositories, entities or QueryContainers of another module.
- **Extension instead of calling**: the called module defines a plugin interface (often in `*-extension` modules), the project hooks plugins into the DependencyProvider, see [03](03-extending-the-core.md).
- **Transfer objects** (`Generated\Shared\Transfer\*Transfer`) are the only data format across module and gateway boundaries, see [04](04-transfer.md).
- `[Assumption]` (docs-based): "lower layers must not depend on upper ones"; enforced by `spryker/architecture-sniffer` (`composer.json`), see [27](27-coding-guidelines.md).

### Layers of a Zed module (four, not three)
**Deviation from the docs:** the docs name three layers; the release has **four**: Business, Communication, Persistence, **Presentation** (Twig templates, e.g. `vendor/spryker/country/src/Spryker/Zed/Country/Presentation/Index/`, `src/Pyz/Zed/Customer/Presentation/Mail/`).

```
Country/
├── CountryConfig.php, CountryDependencyProvider.php
├── Business/        CountryFacade(+Interface), CountryBusinessFactory, models (private)
├── Communication/   Controller/, Plugin/, Table/, CountryCommunicationFactory
├── Persistence/     CountryRepository, CountryEntityManager (each +Interface), CountryPersistenceFactory, Propel/ (schema)
└── Presentation/    Twig templates of the controllers
```
- **Communication**: controllers, forms, tables, plugins; only calls `getFacade()`/`getFactory()`.
- **Business**: all domain logic; entry exclusively via the Facade, models are instantiated in the `BusinessFactory`.
- **Persistence**: reading via `*Repository`, writing via `*EntityManager` (standard). `*QueryContainer` is the older pattern (resolvers still exist), `[Assumption]` not recommended for new development.
- Base classes: `vendor/spryker/kernel/src/Spryker/Zed/Kernel/{Business,Communication,Persistence}/`, config `AbstractBundleConfig`, `AbstractBundleDependencyProvider`, container `Spryker\Zed\Kernel\Container`.
- Yves: `Spryker\Yves\Kernel\AbstractFactory`, `AbstractPlugin`, `AbstractBundleConfig`, `AbstractBundleDependencyProvider`. Client: `Spryker\Client\Kernel\AbstractClient`, `AbstractFactory`, `AbstractDependencyProvider`. Service: `Spryker\Service\Kernel\AbstractService`, `AbstractServiceFactory`.

**Deviation from the docs:** the short version of the docs mentions Symfony Dependency Injection. Symfony DI exists as an optional integration (`vendor/spryker/application/src/Spryker/Shared/Application/Kernel.php`, package `spryker/container`); it is inactive without `config/{APPLICATION}/bundles.php` and `services.php`. The Spryker container of the modules is `Spryker\Zed\Kernel\Container` (`set()`/`get()`).

### Namespace resolution (ProjectNamespaces before CoreNamespaces)
`config/Shared/config_default.php`:
```php
$config[KernelConstants::PROJECT_NAMESPACE] = 'Pyz';
$config[KernelConstants::PROJECT_NAMESPACES] = ['Pyz'];
$config[KernelConstants::CORE_NAMESPACES] = ['SprykerShop', 'SprykerEco', 'Spryker', 'SprykerSdk'];
```
- Core code calls `getFactory()`, `getConfig()`, `getFacade()`, `getClient()` etc. The class is **not** created with `new`, but looked up via a resolver. Pattern: `Spryker\Shared\Kernel\KernelConfig::getResolvableTypeClassNamePatternMap()`, e.g. `ZedFacade => \%s\Zed\%s\Business\%sFacade`, `ZedFactoryBusiness => ...\Business\%sBusinessFactory`, `ZedConfig => \%s\Zed\%s\%sConfig`.
- Candidate order (`ClassNameCandidatesBuilder::buildClassNames()`): **first all project namespaces**, then core namespaces in the order `SprykerShop, SprykerEco, Spryker, SprykerSdk`; only for **project namespaces** (`addProjectClassNames()`) first `<Module><CodeBucket>`, then `<Module>`; the core namespaces (`addCoreClassNames()`) do not produce code bucket variants. The first existing class wins (`AbstractClassResolver::canResolve()`).
- Consequence: `src/Pyz/Zed/Customer/CustomerConfig.php` replaces `Spryker\Zed\Customer\CustomerConfig` automatically, without registration. Evidence: `src/Pyz/Zed/Customer/CustomerConfig.php`, `src/Pyz/Yves/CartPage/CartPageFactory.php`.
- The layout `src/Pyz/<Layer>/<Module>/` must exactly follow the core's layer and class names, otherwise the resolver does not pick it up (and silently falls back to the core).
- Classes not found via the resolver (plugins, business models) are swapped via DependencyProvider/Factory, see [03](03-extending-the-core.md).
- Cache: `KernelConstants::RESOLVABLE_CLASS_NAMES_CACHE_ENABLED` (release: `true`), file `src/Generated/Shared/Kernel/Pyz/resolvableClassCache*.php`. With the cache active, new Pyz classes only become visible after a rebuild (`cache:class-resolver:build`, command name verified in `ResolvableClassCacheConsole`).
- Namespace roles: `Pyz` project, `Spryker` core (`vendor/spryker/*`), `SprykerShop` Storefront Yves (`vendor/spryker-shop/*`), `SprykerEco`/`SprykerSdk` Eco/SDK. **Deviation:** a namespace `SprykerFeature` is not configured, `vendor/spryker-feature/` does not exist.

### Code buckets
- Suffix with which the resolver prefers a store/region-specific module variant: module name candidates `[<Module><Bucket>, <Module>]` (`ModuleNameCandidatesBuilder`). Example in the release: `src/Pyz/Shared/PriceUS/`.
- Configuration: `src/SprykerConfig/CodeBucketConfig.php` (namespace `SprykerConfig`, mapped in `composer.json`, `extends AbstractCodeBucketConfig`): `getCodeBuckets()` = `['EU','US','DE','AT']` (with `APPLICATION_ENV === 'docker.acp.dev'` the allowed stores). Current bucket: env `SPRYKER_CODE_BUCKET`, otherwise `getDefaultCodeBucket()` (`@deprecated`, path segment of the URL, `APPLICATION_REGION` or first bucket). Invalid bucket -> `InvalidCodeBucketException`.
- Mapping in `deploy.yml`: `endpoints: <domain>: region:` and `code-bucket` per endpoint.
- The docs page `code-buckets.html` was not retrievable, statements purely from code.

### Public vs. private module API
- **Public** = methods annotated with `@api` in Facade, Client, Service, plugin interfaces, config getters, controller actions; plus Transfer definitions, `*Constants`, `*Extension` modules. Facade methods carry `@api` + `{@inheritDoc}`, the description is in the interface.
- **Private** = everything else (business models, factories, readers/writers, repositories, core bridges). Do not call. Exception: Factory and config methods may be **overridden** in the project (extension path, not a call path).
- Deprecation: `@deprecated Use {@link ...} instead.` on the element, removal only in the next major.

## Instructions
1. Clarify responsibility: Storefront rendering -> Yves; domain logic/DB -> Zed; reading from storage/search -> Client; REST -> Glue.
2. Choose the access path: Yves/Glue -> Client; Zed -> other Zed module -> Facade (directly via locator in the DependencyProvider); adapting other modules' behavior -> plugin/config.
3. Place the Pyz file exactly following the resolver pattern (`src/Pyz/Zed/<Module>/Business/<Module>Facade.php` etc.).
4. After new/renamed Pyz classes, rebuild the resolver cache (see commands).
5. Give your own public methods `@api` and an interface.

Commands `[live verified (202610.0)]` (executed):
```bash
docker/sdk cli                                    # shell in the container
docker/sdk cli console transfer:generate          # transfers (see 04)
docker/sdk cli console cache:class-resolver:build # resolver cache
```

## Examples

The project extends a core Factory via inheritance; `SprykerShop\Yves\CartPage` uses it automatically (`src/Pyz/Yves/CartPage/CartPageFactory.php`):
```php
declare(strict_types = 1);

namespace Pyz\Yves\CartPage;

use Pyz\Yves\CartPage\Plugin\Provider\CartItemsProductProvider;
use Pyz\Yves\CartPage\Plugin\Provider\CartItemsProductProviderInterface;
use SprykerShop\Yves\CartPage\CartPageFactory as SprykerCartPageFactory;

class CartPageFactory extends SprykerCartPageFactory
{
    public function createCartItemsProductsProvider(): CartItemsProductProviderInterface
    {
        return new CartItemsProductProvider(
            $this->getProductStorageClient(),
        );
    }
}
```
Yves -> Client -> Zed (`vendor/spryker/customer/src/Spryker/Client/Customer/Zed/CustomerStub.php`, excerpt):
```php
$customerResponseTransfer = $this->zedStub->call('/customer/gateway/has-customer-with-email-and-password', $customerTransfer);
```
The Facade delegates and contains no logic (`vendor/spryker/country/src/Spryker/Zed/Country/Business/CountryFacade.php`, excerpt):
```php
/**
 * @method \Spryker\Zed\Country\Business\CountryBusinessFactory getFactory()
 */
class CountryFacade extends AbstractFacade implements CountryFacadeInterface
{
    /** {@inheritDoc} @api */
    public function hasCountry(string $iso2Code): bool
    {
        return $this->getFactory()->createCountryReader()->countryExists($iso2Code);
    }
}
```
Complete Zed module with all layers: [../templates/zed-module/](../templates/zed-module/README.md).

## Pitfalls
- Pyz class with wrong namespace/folder (e.g. `Business/` missing): the resolver does not find it, the core is silently used instead.
- Calling a business class/QueryContainer of another module or accessing Zed/Propel from Yves: layer boundary violated, breaks upgrades.
- Changing `vendor/`: never. Changing files in `src/Generated/`: they get overwritten.
- Active resolver cache: new Pyz classes only take effect after `cache:class-resolver:build`.
- Pyz subclasses may only extend return types compatibly (core signatures are partly untyped), `[Assumption]` regarding individual cases.
- Module name equal to a core module: new classes override the core (see [02](02-project-module.md)).

## Checklist
- [ ] Right application/layer chosen, access only via Client (Yves/Glue) or Facade (Zed)
- [ ] Path `src/Pyz/<Layer>/<Module>/...` and class names exactly following the resolver pattern
- [ ] No access to other modules' models/repositories/QueryContainers
- [ ] Transfers as the data format across module boundaries
- [ ] `@api` and interface on your own public methods
- [ ] Nothing changed in `vendor/` or `src/Generated/`
- [ ] Resolver cache rebuilt after new classes

## Deprecation notes
- `KernelConfig::getResolvableCacheFilePath()` replaced by `getResolvableCacheFilePathPattern()`.
- `KernelConfig::getCurrentStoreName()` `[Deprecated]`; `resolveDynamicStoreMode()` is not in `KernelConfig`, but in `Spryker\Shared\Kernel\CodeBucket\Config\DefaultCodeBucketConfig` (`vendor/spryker/kernel/src/Spryker/Shared/Kernel/CodeBucket/Config/DefaultCodeBucketConfig.php:52`) and is `[Deprecated]` there ("Will be removed without replacement").
- `AbstractClassResolver::getClassPattern()` `[Deprecated]` (pattern now in `KernelConfig::getResolvableTypeClassNamePatternMap()`).
- `SprykerConfig\CodeBucketConfig::getDefaultCodeBucket()` `[Deprecated]` ("will be removed when environment configs are cleaned up").
- QueryContainer persistence: older pattern `[Assumption]`. Examples: `CustomerClient::confirmRegistration()`, `Customer\GatewayController::confirmRegistrationAction()`.

## Sources
Docs (base `https://docs.spryker.com/docs/`): `dg/dev/architecture/modules-and-application-layers.html` (short version, deviates from the code regarding layers/DI), `dg/dev/architecture/code-buckets.html` (not retrievable), `dg/dev/architecture/module-api/declaration-of-module-apis-public-and-private.html` (short version). Only listed, not read: `conceptual-overview`, `programming-concepts`, `architectural-convention`, `technology-stack`.

Code: `vendor/spryker/kernel/src/Spryker/Shared/Kernel/{KernelConfig.php,ClassResolver/*,CodeBucket/Config/*}`, `vendor/spryker/kernel/src/Spryker/{Zed,Yves,Glue,Client,Service,Shared}/Kernel/`, `config/Shared/config_default.php`, `src/SprykerConfig/CodeBucketConfig.php`, `public/*/index.php`, `deploy.yml`, `vendor/spryker/country/src/Spryker/Zed/Country/`, `vendor/spryker/customer/src/Spryker/{Client,Zed}/Customer/`, `src/Pyz/Zed/Customer/`, `src/Pyz/Yves/CartPage/CartPageFactory.php`, `src/Pyz/Yves/ShopApplication/YvesBootstrap.php`.

Neighbors: [02 Project module](02-project-module.md), [03 Extending the core](03-extending-the-core.md), [06 Configuration](06-configuration.md), [19 Multi-store](19-multi-store-i18n.md), [25 Performance/caching](25-performance-caching.md).
