# Extending the core: plugins, extension points, inheritance, configuration

> Status: Release 202610.0 (spryker/kernel 3.87.1, spryker/oms 11.55.0). `[code-verified, docs not cross-checked]`: the docs pages (extend-spryker, development-strategies, extend-the-core, plugins) were read, but they reflect the state of 2021/Jan 2022; the release code takes precedence. Commands `dev:plugin-usage:dump`, `cache:class-resolver:build`, `dev:ide-auto-completion:generate`, `code:sniff:architecture` were executed in the container `[live verified (202610.0)]`. Gaps: no priority API for plugin stacks found `[Assumption]`: pure array order; effect of the resolver cache derived from code `[Assumption]`; use of the Configuration module (`getModuleConfig`) in the release unchecked.

## Purpose
Changing the behavior of a core module without touching `vendor/` and as upgrade-safe as possible. New modules: [02](02-project-module.md). Configuration values/files: [06](06-configuration.md). Upgrader/Evaluator: [28](28-upgradability.md).

## Concepts

### Five development strategies (order of preference, docs "Development strategies")
| # | Strategy | Upgrade support | Mechanism |
|---|---|---|---|
| 1 | Configuration | high | `Pyz\Zed\<Module>\<Module>Config extends Spryker\Zed\<Module>\<Module>Config`, override constants/methods |
| 2 | Plug and play | high | add/remove/replace a plugin in a plugin stack in the **Pyz DependencyProvider** |
| 3 | Project modules | high | new Pyz module ([02](02-project-module.md)) |
| 4 | Module customization | reduced, manual check per update | replace a core class in Pyz via inheritance/composition (Factory/model/Facade) |
| 5 | Module replacement | no support | Composer `replace` of a core module |

Docs notes: with strategy 4, pin the module version with `~` instead of `^`, consider composition instead of inheritance. If an extension point is missing, ask the Spryker account manager.

### Extension modules and plugin interfaces
- A `*-extension` module contains **only interfaces** (and possibly transfer definitions), no logic; it avoids circular dependencies to the target module. In the release: 212 directories `vendor/spryker/*-extension`, plus `vendor/spryker-shop/*-extension` (e.g. `cart-page-extension`).
- Example: `vendor/spryker/calculation-extension/src/Spryker/Zed/CalculationExtension/Dependency/Plugin/CalculationPluginInterface.php`. The target module provides the stack (`CalculationDependencyProvider::QUOTE_CALCULATOR_PLUGIN_STACK`), the business factory reads it via `getProvidedDependency()`.
- Location of a plugin:

| App | Directory | Base class |
|---|---|---|
| Zed | `Pyz\Zed\<Module>\Communication\Plugin\<TargetModule>\` | `Spryker\Zed\Kernel\Communication\AbstractPlugin` (`getFacade()`, `getFactory()`, `getConfig()`) |
| Yves | `Pyz\Yves\<Module>\Plugin\<TargetModule>\` | `Spryker\Yves\Kernel\AbstractPlugin` |
| Client | `Pyz\Client\<Module>\Plugin\<TargetModule>\` | `Spryker\Client\Kernel\AbstractPlugin` |

  Real examples: `src/Pyz/Zed/Oms/Communication/Plugin/Oms/InitiationTimeoutProcessorPlugin.php`, `src/Pyz/Yves/PriceWidget/Plugin/Twig/PriceModeTwigPlugin.php`, `src/Pyz/Client/ExampleProductSalePage/Plugin/Elasticsearch/Query/SaleSearchQueryPlugin.php`.
- Plugins only delegate to the Facade/Factory of their **own** module; no logic in the plugin.
- Always choose the `*Extension` interface; interfaces in the module itself (e.g. `Spryker\Zed\Cart\Dependency\...PluginInterface`) are being migrated to `*Extension`.

### Plugin stacks
For each stack the core provider has `add<Stack>(Container)` (sets a lazy closure) and `protected get<Stack>(): array` (default, usually `[]`). The project overrides **only** `get...Plugins()`. Core example (`vendor/spryker/oms/.../OmsDependencyProvider.php`):
```php
protected function addTimeoutProcessorPlugins(Container $container): Container
{
    $container->set(static::PLUGINS_TIMEOUT_PROCESSOR, function () {
        return $this->getTimeoutProcessorPlugins();
    });
    return $container;
}
protected function getTimeoutProcessorPlugins(): array { return []; }
```
- Order: array order = execution order (`[Assumption]`, no priority parameter). Business-critical e.g. in `CalculationDependencyProvider` (docblock in `src/Pyz/Zed/Calculation/CalculationDependencyProvider.php`).
- Some stacks are **associative** (e.g. `ProductPageSearchDependencyProvider::getDataExpanderPlugins()`, keys from several config classes, in Pyz `ProductPageSearchConfig`, `ProductLabelSearchConfig`, `ProductReviewSearchConfig`, `ProductListSearchConfig` and `ProductImageConfig`, see `src/Pyz/Zed/ProductPageSearch/ProductPageSearchDependencyProvider.php:57-63`): replace via the same key.
- Adding to core defaults: `array_merge(parent::getXPlugins(), [new MyPlugin()])` (Discount, Oauth, PriceProduct, CmsBlockGui). Complete list in Pyz (core returns `[]`): Product, Customer, Calculation. The latter is the **standard pattern in the b2b-demo-shop**.
- Removing/replacing: no remove API. (1) Define the stack completely in Pyz without `parent::` and leave out the plugin. (2) For `parent::` stacks, filter. The example removes a plugin that the core provider actually supplies itself (`getDecisionRulePlugins()` in `vendor/spryker/discount/.../DiscountDependencyProvider.php` contains among others `Spryker\Zed\Discount\Communication\Plugin\DecisionRule\DayOfTheWeekDecisionRulePlugin`); the filter pattern itself is not from the release:
```php
return array_values(array_filter(
    parent::getDecisionRulePlugins(),
    static fn ($plugin): bool => !$plugin instanceof \Spryker\Zed\Discount\Communication\Plugin\DecisionRule\DayOfTheWeekDecisionRulePlugin,
));
```
  Note: the `CustomerGroupDecisionRulePlugin` (`Spryker\Zed\CustomerGroupDiscountConnector\Communication\Plugin\DecisionRule\…`) is **not** supplied by `parent::`; in the release it is only added via `array_merge` in `src/Pyz/Zed/Discount/DiscountDependencyProvider.php` and can simply be removed from the list there. Replacing = remove the original and insert your own plugin at the **same position**.
- Additional dependencies in the Pyz provider: `src/Pyz/Zed/Customer/CustomerDependencyProvider.php` overrides `provideCommunicationLayerDependencies()`, calls `parent::` and sets `FACADE_SALES`, `FACADE_NEWSLETTER` via `$container->set(KEY, fn(Container $c) => $c->getLocator()->sales()->facade())`. Direct Facade access via the locator is the standard at project level; bridges are a pure core pattern ([02](02-project-module.md)).

### Decision tree
1. Is it about a **value** (limit, list, flag, event list)? -> extend the Pyz `<Module>Config` (`src/Pyz/Zed/Customer/CustomerConfig.php`: `MIN_LENGTH_CUSTOMER_PASSWORD = 12`; `src/Pyz/Zed/Product/ProductConfig.php`: `array_merge(parent::...Events(), [...])`).
2. Is there an **extension point** (plugin stack, `*-extension` interface)? -> plugin in the Pyz DependencyProvider.
3. Is it missing? -> ask Spryker; fallback **customization**: override the Factory in Pyz and hook in your own class.
4. Only if that does not fit: module replacement (no upgrade support).

### Overriding classes in Pyz
Resolution purely by naming convention (project before core, details [01](01-architecture.md)). `Pyz\Zed\Oms\Business\OmsFacade` is used **automatically** instead of the core Facade, likewise Factory, Config, DependencyProvider.
- The Pyz class **extends** the core class of the same name (alias `use ... as Spryker...`).
- New behavior via a **new factory method** (`createXyz()`), not by copying core methods. Every overridden core method is an upgrade point.
- Config access via `$this->getConfig()` with `@method \Pyz\Zed\<Module>\<Module>Config getConfig()`.
- Rebuild the resolver cache after new Pyz classes (`RESOLVABLE_CLASS_NAMES_CACHE_ENABLED` and `RESOLVED_INSTANCE_CACHE_ENABLED` are `true` in the release).

## Instructions
1. Walk through the decision tree (Config -> plugin -> customization -> replacement).
2. **Config:** create/extend the Pyz config (`extends` the core config), only override the needed constant/method; first check in the core whether it is `private` or replaced by `getModuleConfig()`.
3. **Plugin:** find the matching `*-extension` interface (`ls vendor/spryker | grep -i extension`), create the plugin under `Communication/Plugin/<TargetModule>/`, hook it into the right stack and at the right position in the Pyz DependencyProvider (for Calculation the quote **and** order stack).
4. **Customization:** Pyz Factory `extends` the core Factory, new `create*` method, only add to the Facade/interface in Pyz. Pin the module version with `~`.
5. Rebuild the cache, check the plugins.

Commands `[live verified (202610.0)]` (registered in `docker/sdk console list`):
```bash
docker/sdk cli console dev:plugin-usage:dump [Module]   # plugin usage per DependencyProvider (PluginUsageFinderConsole)
docker/sdk cli console cache:class-resolver:build      # resolver cache (ResolvableClassCacheConsole)
docker/sdk cli console dev:ide-auto-completion:generate  # IDE autocompletion (alias: dev:ide:generate-auto-completion)
docker/sdk cli console code:sniff:architecture         # see 27
```
Back Office: page `development/dependency-provider-plugin-usage` (controller `DependencyProviderPluginUsageController`, CSV download according to the docs).

## Examples
Overriding configuration (`src/Pyz/Zed/Customer/CustomerConfig.php`, excerpt):
```php
namespace Pyz\Zed\Customer;

use Spryker\Zed\Customer\CustomerConfig as SprykerCustomerConfig;

class CustomerConfig extends SprykerCustomerConfig
{
    protected const PASSWORD_RESET_EXPIRATION_IS_ENABLED = true;
    protected const MIN_LENGTH_CUSTOMER_PASSWORD = 12;
    protected const MAX_LENGTH_CUSTOMER_PASSWORD = 128;

    /** {@inheritDoc} @return array<string> */
    public function getCustomerPasswordDenyList(): array
    {
        return ['qwerty'];
    }
}
```
Writing a plugin (`src/Pyz/Zed/Oms/Communication/Plugin/Oms/InitiationTimeoutProcessorPlugin.php`, header):
```php
namespace Pyz\Zed\Oms\Communication\Plugin\Oms;

use Spryker\Zed\Kernel\Communication\AbstractPlugin;
use Spryker\Zed\OmsExtension\Dependency\Plugin\TimeoutProcessorPluginInterface;

/**
 * @method \Pyz\Zed\Oms\Business\OmsFacadeInterface getFacade()
 * @method \Pyz\Zed\Oms\Communication\OmsCommunicationFactory getFactory()
 * @method \Pyz\Zed\Oms\OmsConfig getConfig()
 */
class InitiationTimeoutProcessorPlugin extends AbstractPlugin implements TimeoutProcessorPluginInterface
{
    public function getName(): string { return 'OmsTimeout/Initiation'; }
    // getLabel(OmsEventTransfer): string, calculateTimeout(...) delegates to $this->getFacade()
}
```
Registering a plugin (`src/Pyz/Zed/Oms/OmsDependencyProvider.php`):
```php
/**
 * @return array<\Spryker\Zed\OmsExtension\Dependency\Plugin\TimeoutProcessorPluginInterface>
 */
protected function getTimeoutProcessorPlugins(): array
{
    return [
        new InitiationTimeoutProcessorPlugin(),
    ];
}
```
Extending a core stack (`src/Pyz/Zed/Discount/DiscountDependencyProvider.php`):
```php
protected function getDecisionRulePlugins(): array
{
    return array_merge(parent::getDecisionRulePlugins(), [
        new ShipmentCarrierDecisionRulePlugin(),
        new CustomerGroupDecisionRulePlugin(),
        // ...
    ]);
}
```
Customization, upgrade-friendly form: only **add** (`src/Pyz/Zed/Oms/Business/`):
```php
class OmsBusinessFactory extends \Spryker\Zed\Oms\Business\OmsBusinessFactory
{
    public function createInitiationTimeoutCalculator(): TimeoutProcessorTimeoutCalculatorInterface
    {
        return new InitiationTimeoutCalculator();
    }
}

class OmsFacade extends \Spryker\Zed\Oms\Business\OmsFacade implements OmsFacadeInterface
{
    public function calculateInitiationTimeout(TimeoutProcessorTimeoutRequestTransfer $request): TimeoutProcessorTimeoutResponseTransfer
    {
        return $this->getFactory()->createInitiationTimeoutCalculator()->calculateTimeout($request);
    }
}
```
`OmsFacadeInterface` is a Pyz interface; without the interface, other modules do not see new Facade methods.

Replacing a core model = strategy 4: `src/Pyz/Zed/CustomerAccess/Business/CustomerAccessBusinessFactory.php` overrides `createCustomerAccessFilter()` and `createCustomerAccessUpdater()` with Pyz classes. If the core changes these signatures, the Pyz module breaks; check manually.

Custom calculator plugin (template, not from the release), `src/Pyz/Zed/MyCalc/Communication/Plugin/Calculation/HandlingFeeCalculatorPlugin.php`:
```php
declare(strict_types = 1);

namespace Pyz\Zed\MyCalc\Communication\Plugin\Calculation;

use Generated\Shared\Transfer\CalculableObjectTransfer;
use Spryker\Zed\CalculationExtension\Dependency\Plugin\CalculationPluginInterface;
use Spryker\Zed\Kernel\Communication\AbstractPlugin;

/**
 * @method \Pyz\Zed\MyCalc\Business\MyCalcFacadeInterface getFacade()
 */
class HandlingFeeCalculatorPlugin extends AbstractPlugin implements CalculationPluginInterface
{
    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function recalculate(CalculableObjectTransfer $calculableObjectTransfer): void
    {
        $this->getFacade()->recalculateHandlingFee($calculableObjectTransfer);
    }
}
```
Registration in `src/Pyz/Zed/Calculation/CalculationDependencyProvider.php` in `getQuoteCalculatorPluginStack()` **and** `getOrderCalculatorPluginStack()` before the total calculators. The core interface declares `recalculate(CalculableObjectTransfer $calculableObjectTransfer);` without a return type; `: void` is permitted.

Removing a plugin (template): delete `new RefundableAmountCalculatorPlugin(),` in both stacks (in the release line ~219 quote and ~263 order).

## Pitfalls
1. Changing a core file in `vendor/`: lost on `composer update`, the Upgrader cannot rebase.
2. Pyz class with a deviating name/path: resolution is purely convention-based, it does not take effect.
3. Plugin in the wrong stack: Calculation has separate quote and order stacks.
4. Plugin at the wrong position (totals before aggregators).
5. Outdated interface: `src/Pyz/Zed/ProductUrlCartConnector/Communication/Plugin/ProductUrlCartPlugin.php` implements `Spryker\Zed\Cart\Dependency\ItemExpanderPluginInterface` (`@deprecated Use {@link \Spryker\Zed\CartExtension\Dependency\Plugin\ItemExpanderPluginInterface}`) and is registered in **no** DependencyProvider in the release (dead code) - do not use it as a template, use the `CartExtension` interface.
6. The docs recipe "Extend a core module that is used by another module" uses `$container[self::X]` and `self::provideBusinessLayerDependencies()` - **outdated** compared to `$container->set(KEY, fn)` + `parent::`. The docs themselves warn about drift between bridge and Facade interface (only with a patch lock of the module version); at project level the problem does not arise, because no bridges are built there.
7. Overriding a config constant that is `private` in the core or has been replaced by `getModuleConfig()`.
8. Forgetting the cache: after new overrides run `cache:class-resolver:build`, otherwise old core behavior `[Assumption]`.
9. New methods only in the Pyz Facade without a Pyz interface: other modules do not see them.
10. Direct access to business classes of other modules instead of Facade/Client/Service ([01](01-architecture.md)).
11. Specify plugin types in the PHPDoc of the stack (`@return array<\Spryker\Zed\XExtension\Dependency\Plugin\YPluginInterface>`), `@api` on plugin methods.

## Checklist
- [ ] Order Config -> plugin -> customization -> replacement followed
- [ ] Change in Pyz, nothing in `vendor/`
- [ ] `*Extension` interface (not the outdated module interface) used
- [ ] Plugin in the right stack and at the business-correct position (quote and order for Calculation)
- [ ] `array_merge(parent::..., [...])` or a deliberately complete stack
- [ ] Plugin thin (delegation only), `@method` and `@api` set
- [ ] Customization only via new factory methods; module version pinned with `~`
- [ ] Pyz Facade interface maintained
- [ ] `declare(strict_types = 1);` and complete types
- [ ] Resolver cache rebuilt, `dev:plugin-usage:dump` checked

## Deprecation and version notes
- `AbstractBundleConfig::get(KEY, default)` -> `Spryker\Shared\Config\Config::getInstance()->get()`; **do not confuse** with the new `getModuleConfig(string $key, ...)` (`protected`, `vendor/spryker/kernel/src/Spryker/Zed/Kernel/AbstractBundleConfig.php`): reads from the `configuration` module (only if `ConfigurationFacadeInterface` exists, otherwise `$default`); not installed in the release, see [06](06-configuration.md).
- `AbstractClassResolver::getClassPattern()` `[Deprecated]` (patterns in `KernelConfig::getResolvableTypeClassNamePatternMap()`).
- `DependencyInjectorResolver`/DependencyInjectors `[Deprecated]` (comment in `AbstractClassResolver::unsetCurrentCacheEntry()`): no new injectors, use the Pyz DependencyProvider instead.
- `Spryker\Zed\Cart\Dependency\ItemExpanderPluginInterface` `[Deprecated]`, replacement `CartExtension` interface.
- Docs redirect: `...extend-a-core-module-that-is-used-by-another.html` is now called `...-by-another-module.html`.

## Sources
Docs (base `https://docs.spryker.com/docs/`): `dg/dev/backend-development/extend-spryker/extend-spryker.html`, `.../development-strategies.html`, `.../spryker-os-module-customisation/extend-the-core.html`, `.../extend-a-core-module-that-is-used-by-another-module.html`, `dg/dev/backend-development/plugins/plugins.html`, `.../plugins/get-an-overview-of-the-used-plugins.html`.

Code: `vendor/spryker/kernel/src/Spryker/Shared/Kernel/{KernelConfig.php,ClassResolver/**}`, `vendor/spryker/kernel/src/Spryker/Zed/Kernel/{AbstractBundleDependencyProvider,AbstractBundleConfig}.php`, `.../Communication/Console/ResolvableClassCacheConsole.php`, `vendor/spryker/development/src/Spryker/Zed/Development/Communication/Console/PluginUsageFinderConsole.php`, `.../Controller/DependencyProviderPluginUsageController.php`, `vendor/spryker/calculation-extension/`, `vendor/spryker/calculation/.../CalculationDependencyProvider.php`, `vendor/spryker/oms/.../OmsDependencyProvider.php`, `vendor/spryker/cart/src/Spryker/Zed/Cart/Dependency/ItemExpanderPluginInterface.php`, `config/Shared/config_default.php`, `src/Pyz/Zed/{Product,Customer,Calculation,Oms,Discount,CustomerAccess,CmsBlockGui,ProductPageSearch}/`, `src/Pyz/Zed/ProductUrlCartConnector/`.

Neighbours: [01 Architecture](01-architecture.md), [02 Project module](02-project-module.md), [06 Configuration](06-configuration.md), [28 Upgradability](28-upgradability.md).
