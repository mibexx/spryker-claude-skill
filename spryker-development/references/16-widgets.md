# Widgets and widget plugins

> Status: the docs pages `implementing-widgets-and-widget-plugins` and `modular-frontend` were only retrievable as a table of contents (no code examples); all details are backed by the code in 202610.0 `[code-verified, docs not cross-checked]`. Console commands executed in the container (`twig:cache:warmer`, `cache:empty-all`, `transfer:generate`) `[live verified (202610.0)]`; `npm` frontend build commands executed `[live verified (202610.0)]` ([15](15-yves-frontend-atomic.md), [30](30-docker-sdk-workflow.md)); the replace pattern "same name, Pyz class replaces core class in `getGlobalWidgets()`" is derived from the code `[Assumption]`.

## Purpose
Reusable Yves building blocks (class + Twig view) that fetch data via Clients and are rendered in any template via tag/function. Controllers/routing → [14](14-yves-controller-routing.md); Atomic frontend/theme/build → [15](15-yves-frontend-atomic.md); CMS content widgets → [17](17-cms.md); Clients → [08](08-client-storage-search.md).

## Concepts
**Widget class:** `extends Spryker\Yves\Kernel\Widget\AbstractWidget`, fulfills `Spryker\Yves\Kernel\Dependency\Widget\WidgetInterface` (`vendor/spryker/kernel/src/Spryker/Yves/Kernel/Dependency/Widget/WidgetInterface.php`):
```php
public static function getName(): string;      // unique name for Twig
public static function getTemplate(): string;  // "@Module/views/<name>/<name>.twig"
public function getParameters(): array;
```
- **Parameters** are computed in the **constructor** and stored with `$this->addParameter($name, $value)` (fluent). Constructor arguments = exactly the values from `args [...]` in the Twig tag (`new $widgetClassName(...$arguments)`, `ShopApplication\Twig\Widget\WidgetFactory`).
- `AbstractWidget` provides `getFactory()`, `getClient()`, `getConfig()` (class resolver, `@method` annotation, naming convention → [02](02-project-module.md)), `getLocale()`, `getGlobalContainer()`.
- Parameters are **read-only** (`ArrayAccess`, `offsetSet` throws `ReadOnlyException`); in the template `_widget.parameterName`.
- Name = class name (convention), unique within the scope, otherwise `UniqueWidgetNameException`.

**Registration (scopes, `WidgetContainerAwareTrait::addWidget`, checks against `WidgetInterface`, otherwise `InvalidWidgetException`):**
1. **Global:** `Pyz\Yves\ShopApplication\ShopApplicationDependencyProvider::getGlobalWidgets()` (`array<string>` class names, no `new`). Core provides `array_unique(array_merge(getGlobalWidgets(), getGlobalWidgetPlugins()))` under `WIDGET_GLOBAL`. Reachable in every template via `widget 'Name'` / `widgetGlobal('Name')`.
2. **Page-specific:** the controller passes a list to `$this->view($data, $widgetClassNames, $template)` (`View` calls `addWidgets()`). Typically `$this->getFactory()->getCartPageWidgetPlugins()` from `CartPageDependencyProvider`; in 202610.0 these page lists are **empty** by default (everything runs via global widgets; the method name still contains "WidgetPlugins", but expects widget classes).
3. **Nested:** a widget calls `$this->addWidget(Foo::class)` in its constructor (example `Pyz\Yves\ProductSetWidget\Widget\ProductSetIdsWidget`); lookup: first the last opened container (view/widget), then global.

**Twig syntax** (`WidgetTwigPlugin`, `WidgetTagTwigPlugin` in `Pyz\Yves\Twig\TwigDependencyProvider`):

| Syntax | Meaning |
|---|---|
| `{% widget 'Name' args [a, b] only %}{% endwidget %}` | renders the widget with constructor arguments |
| `... with { k: v }` | additional template variables |
| `... only` | do not inherit the context (only `_widget` + `with`); without `only` `$context` is merged |
| `... use view('x', 'Module')` | different template instead of `getTemplate()` |
| `{% elsewidget 'Other' args [...] %}` | fallback if the first widget does not exist |
| `{% nowidget %}...` | content if no widget exists |
| `{% widget expr %}` | name from expression/instance |
| `{{ widget('Name', a, b) }}`, `{{ widgetBlock('Name', 'block', a) }}`, `{{ widgetGlobal('Name', a) }}` | functions (string) |
| `widgetExists('N')`, `widgetGlobalExists('N')` | existence check |
| `{% set w = findWidget('Name', [args]) %}` | get the instance, use parameters only |

Unregistered name → the tag returns **empty** (no error). Exceptions in the widget → `WidgetRenderException` ("Something went wrong in widget …").

**Data:** Factory + DependencyProvider of the widget module; the constructor calls `$this->getFactory()->getXClient()`; Client via `$container->getLocator()->customer()->client()`. No Zed/DB access.

**Replacing/extending core widgets:**
- Replace: the Pyz class `extends` the core widget, keeps `getName()` (or overrides `getTemplate()`) and is registered in Pyz `getGlobalWidgets()` **instead of** the core class (both → `UniqueWidgetNameException`) `[Assumption]`. The release has no replace example; Pyz `MenuItemCompanyWidget` has a **new** name and runs in parallel to `CompanyMenuItemWidget`.
- Template only: file with the same name under `src/Pyz/Yves/<Module>/Theme/default/views/<name>/<name>.twig` (Pyz takes precedence over `vendor/spryker-shop`, → [15](15-yves-frontend-atomic.md)).
- Factory/Client/Config of the core widget resolve via class resolver (Pyz → SprykerShop); a Pyz widget with `@method \SprykerShop\Yves\CompanyWidget\CompanyWidgetFactory getFactory()` uses the core Factory.

**Cache key strategy:** widget instances are cached per request (`WidgetFactory::$widgetCache`), key `md5(class . serialize(args))` with special handling for `QuoteTransfer`/`ItemTransfer` (`StrategyCacheKeyGenerator`). Custom strategy: plugin with `generateCacheKey(array): ?string` (`null` = no caching) and `getWidgetClassName(): string` (`WidgetCacheKeyGeneratorStrategyPluginInterface`), registered in `ShopApplicationDependencyProvider::getWidgetCacheKeyGeneratorStrategyPlugins()` (example `CartItemNoteFormWidgetCacheKeyGeneratorStrategyPlugin`). Needed for state-dependent parameters (session/customer) and multiple calls with the same args.

## Instructions
New widget (template [widget](../templates/widget/README.md)):
1. Module `src/Pyz/Yves/<Name>Widget/` with `Widget/<Name>Widget.php`, Factory, DependencyProvider ([02](02-project-module.md)).
2. `extends AbstractWidget`, `getName()`, `getTemplate()`, the constructor sets parameters via `addXParameter()` with constants.
3. Clients only via DependencyProvider/Factory, typed.
4. Twig view `Theme/default/views/<name>/<name>.twig`: `{% extends template('widget') %}`, `{% define data = {...} %}`, content in `{% block body %}`; visibility via `data.isVisible` (base `shop-ui/.../templates/widget/widget.twig`).
5. Register the class in `getGlobalWidgets()` (or page-specifically via `view()`).
6. Include in the template: `{% widget 'Name' args [...] only %}{% endwidget %}`, optionally `{% nowidget %}`. Override core templates with a Pyz file of the same name, do not edit in `vendor/`.
7. Clear the cache, frontend build only with your own SCSS/TS.

Commands (standard workflow, all executed `[live verified (202610.0)]`; `docker/sdk up --build` needs a TTY and deletes `src/Generated`, afterwards the `build` section runs again):
```bash
docker/sdk cli vendor/bin/console transfer:generate   # if new transfers are used
docker/sdk cli vendor/bin/console cache:empty-all     # Twig/class resolver cache
docker/sdk cli npm run yves                           # only with SCSS/TS in components/ (-> 15)
docker/sdk up --build                                      # rebuild for new modules/theme files
```

## Examples
Complete module in [templates/widget](../templates/widget/README.md). Real release example `src/Pyz/Yves/CustomerFullNameWidget/`:

`Widget/CustomerFullNameWidget.php`
```php
namespace Pyz\Yves\CustomerFullNameWidget\Widget;

use Spryker\Yves\Kernel\Widget\AbstractWidget;

/**
 * @method \Pyz\Yves\CustomerFullNameWidget\CustomerFullNameWidgetFactory getFactory()
 */
class CustomerFullNameWidget extends AbstractWidget
{
    protected const PARAMETER_CUSTOMER_FULL_NAME = 'customerFullName';

    public static function getName(): string { return 'CustomerFullNameWidget'; }

    public static function getTemplate(): string
    {
        return '@CustomerFullNameWidget/views/customer-full-name-widget/customer-full-name-widget.twig';
    }

    public function __construct()
    {
        $this->addCustomerFullNameParameter();
    }

    protected function addCustomerFullNameParameter(): void
    {
        $customerTransfer = $this->getFactory()->getCustomerClient()->getCustomer();

        $this->addParameter(
            static::PARAMETER_CUSTOMER_FULL_NAME,
            $customerTransfer->getFirstName() . ' ' . $customerTransfer->getLastName(),
        );
    }
}
```

Factory (`CustomerFullNameWidgetFactory extends Spryker\Yves\Kernel\AbstractFactory`): `getCustomerClient(): CustomerClientInterface` via `getProvidedDependency(CustomerFullNameWidgetDependencyProvider::CLIENT_CUSTOMER)`. DependencyProvider (`extends Spryker\Yves\Kernel\AbstractBundleDependencyProvider`, `provideDependencies(Container)`): `$container->set(static::CLIENT_CUSTOMER, function (Container $container) { return $container->getLocator()->customer()->client(); });`.

`Theme/default/views/customer-full-name-widget/customer-full-name-widget.twig`
```twig
{% extends template('widget') %}

{% define data = {
    customerFullName: _widget.customerFullName
} %}

{% block body %}
    {{ data.customerFullName }}
{% endblock %}
```

Registration (`src/Pyz/Yves/ShopApplication/ShopApplicationDependencyProvider.php`):
```php
protected function getGlobalWidgets(): array
{
    return [
        // ...
        CustomerFullNameWidget::class,
    ];
}
```

Usage (real, `ShopUi/.../user-navigation.twig`, `page-layout-main.twig`):
```twig
<span class="{{ config.name }}__text">{% widget 'CustomerFullNameWidget' only %}{% endwidget %}</span>

{% set customerFullNameWidget = findWidget('CustomerFullNameWidget') %}
{% set customerFullName = customerFullNameWidget.customerFullName %}
```

Widget with arguments (core `CartNoteFormWidget`): constructor `__construct(QuoteTransfer $quoteTransfer)` with `addParameter('cartNoteForm', ...)`; call `{% widget 'CartNoteFormWidget' args [data.cart] only %}{% endwidget %}`. Fallback/`use`/`nowidget` (core `page-layout-cart.twig`):
```twig
{% widget 'CartOperationsWidget' args [data.cart] use view('cart-operations-title', 'MultiCartWidget') only %}
{% nowidget %}
    ...
{% endwidget %}
```
The `elsewidget '...WidgetPlugin'` lines in core templates are BC fallbacks, omit them in new code.

## Pitfalls
1. Widget not registered in `getGlobalWidgets()`/view → the tag silently renders nothing. Debug `{{ widgetGlobalExists('Name') ? 'yes' : 'no' }}`.
2. Duplicate name (core widget and Pyz replacement both registered) → `UniqueWidgetNameException`.
3. Class implements neither `WidgetInterface` nor the old plugin interface → `InvalidWidgetException`.
4. `args [...]` is spread 1:1 as constructor arguments; wrong type → `TypeError`, wrapped as `WidgetRenderException`.
5. `only` forgotten → the entire context leaks into the widget template; `only` set but a variable is needed → pass it via `with {…}`. Convention: always `only`.
6. `getTemplate()` must be `@<Module>/views/<name>/<name>.twig`, file in `Theme/default/views/<name>/`.
7. Logic in the template; access across layer boundaries (only Clients, never Zed Facades/Propel).
8. Lots of work in the constructor without a cache strategy → multiple times per page (storage/Zed calls in lists).
9. Writing parameters in the template → `ReadOnlyException`.
10. Anti-patterns that break the upgrader: editing core widgets/Twigs in `vendor/`, registering core and Pyz classes with the same name in parallel, copying core Twig blocks instead of extending them ([15](15-yves-frontend-atomic.md), [28](28-upgradability.md)).

**Deprecations (code takes precedence over docs)**

| Old `[Deprecated]` | Replacement |
|---|---|
| `Spryker\Yves\Kernel\Widget\AbstractWidgetPlugin` | `AbstractWidget` |
| `Spryker\Yves\Kernel\Dependency\Plugin\WidgetPluginInterface` (`initialize()`, `NAME`) | `WidgetInterface` |
| `Spryker\Yves\Kernel\Widget\WidgetFactory` | `SprykerShop\Yves\ShopApplication\Twig\Widget\WidgetFactory` |
| `ShopApplicationDependencyProvider::getGlobalWidgetPlugins()`, `PLUGIN_GLOBAL_WIDGETS`, `addGlobalWidgetPlugins()` | `getGlobalWidgets()`, `WIDGET_GLOBAL` |
| `ShopApplicationDependencyProvider::PLUGIN_APPLICATION`/`addApplicationPlugin()` | `GLOBAL_CONTAINER` |
| `AbstractWidget::getApplication()` | `getGlobalContainer()` |
| `TokenParser\WidgetTagTokenParser` + `Node\WidgetTagTwigNode` | `WidgetTagTwigTokenParser` + `WidgetTagNode` |
| `WidgetTagServiceProvider`/`WidgetServiceProvider` | `WidgetTagTwigPlugin`/`WidgetTwigPlugin` |
| Core plugins like `CartNoteQuoteWidgetPlugin` | the respective `...Widget` |

`getCartPageWidgetPlugins`/`getProductDetailPageWidgetPlugins` are not deprecated, just misleadingly named. The docs heading "Widgets and Widget Plugins" suggests two equivalent concepts; in the code widget plugins are fully deprecated `[Assumption]` regarding the docs.

## Checklist
- [ ] `extends AbstractWidget`, `getName()` (= class name, unique), `getTemplate()`, constructor sets parameters
- [ ] Clients only via DependencyProvider/Factory, typed; `@method` annotation maintained
- [ ] Twig view with `extends template('widget')`, block `body`, path `Theme/default/views/<name>/`
- [ ] Registered in `getGlobalWidgets()` (or via `view()`)
- [ ] Call with `args [...] only`, optionally `nowidget`
- [ ] State-dependent? Cache key strategy checked
- [ ] Cache cleared, frontend build only if needed
- [ ] No deprecated widget plugin base classes, no core changes

## Sources
Docs (only topic overview retrievable): `https://docs.spryker.com/docs/dg/dev/backend-development/yves/implementing-widgets-and-widget-plugins.html`, `https://docs.spryker.com/docs/dg/dev/backend-development/yves/modular-frontend.html`.

Source code: `vendor/spryker/kernel/src/Spryker/Yves/Kernel/Widget/{AbstractWidget,AbstractWidgetPlugin,WidgetContainerAwareTrait,WidgetContainerRegistry}.php`, `.../Dependency/Widget/WidgetInterface.php`, `.../Dependency/Plugin/WidgetPluginInterface.php`, `.../View/View.php`, `.../Controller/AbstractController.php::view`; `vendor/spryker-shop/shop-application/src/SprykerShop/Yves/ShopApplication/{ShopApplicationDependencyProvider,ShopApplicationFactory}.php`, `.../Plugin/Twig/{WidgetTwigPlugin,WidgetTagTwigPlugin}.php`, `.../Twig/Widget/*`; `vendor/spryker-shop/shop-application-extension/.../WidgetCacheKeyGeneratorStrategyPluginInterface.php`; `vendor/spryker-shop/shop-ui/.../Theme/default/templates/widget/widget.twig`; `vendor/spryker-shop/cart-note-widget/*`; `vendor/spryker-shop/cart-page/.../CartPageDependencyProvider.php`; `src/Pyz/Yves/ShopApplication/ShopApplicationDependencyProvider.php`, `src/Pyz/Yves/Twig/TwigDependencyProvider.php`; `src/Pyz/Yves/CustomerFullNameWidget/**`, `src/Pyz/Yves/ProductSetWidget/Widget/ProductSetIdsWidget.php`, `src/Pyz/Yves/CompanyWidget/Widget/MenuItemCompanyWidget.php`.

Neighbors: [14 Controller/routing](14-yves-controller-routing.md), [15 Atomic frontend](15-yves-frontend-atomic.md), [17 CMS](17-cms.md), template [widget](../templates/widget/README.md).
