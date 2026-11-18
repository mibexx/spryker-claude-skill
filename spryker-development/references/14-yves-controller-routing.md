# Yves: controllers, routing, Twig extension

> Status: Code in `vendor/` and `src/Pyz` (202610.0, kernel 3.87.1, Symfony 6.4.48) read directly; template classes checked against vendor. Docs pages (as of 2021) are partly outdated and were not cross-checked line by line `[code-verified, docs not cross-checked]`. Console commands checked against the command lists of `vendor/bin/yves` and the Zed console (`vendor/bin/yves router:debug` executed) `[live verified (202610.0)]`. Open: behavior of duplicate route names and the `_template` fallback (only derived from code/Symfony), custom Twig tag only as a sketch.

## Purpose
PHP and routing side of Yves: boot, route providers, controllers/actions, overriding core controllers/routes, StorageRouter (SEO URLs), Twig plugins, response headers, controller events. Templates/build → [15](15-yves-frontend-atomic.md); widgets → [16](16-widgets.md); CMS → [17](17-cms.md); locale/store → [19](19-multi-store-i18n.md); permissions/session → [20](20-b2b-permissions.md), [21](21-security-session.md).

## Concepts
**Boot:** `public/Yves/index.php` → `Pyz\Yves\ShopApplication\YvesBootstrap` (empty, extends `SprykerShop\Yves\ShopApplication\Bootstrap\YvesBootstrap`) → `ShopApplicationFactory::createApplication()` builds the `Application` with the plugins from `Pyz\Yves\ShopApplication\ShopApplicationDependencyProvider::getApplicationPlugins()` (among others `YvesHttpApplicationPlugin`, `TwigApplicationPlugin`, `EventDispatcherApplicationPlugin`, `RouterApplicationPlugin`, `SessionApplicationPlugin`, `YvesSecurityApplicationPlugin`). Each plugin: `ApplicationPluginInterface::provide(ContainerInterface): ContainerInterface`. The same provider holds `getGlobalWidgets()` ([16](16-widgets.md)), `getFilterControllerEventSubscriberPlugins()`, `getWidgetCacheKeyGeneratorStrategyPlugins()`. EventDispatcher plugins: `Pyz\Yves\EventDispatcher\EventDispatcherDependencyProvider::getEventDispatcherPlugins()`.

**Routing building blocks** (all `src/Pyz/Yves/<Module>/`): controller → action → route provider plugin → registration in `Pyz\Yves\Router\RouterDependencyProvider::getRouteProvider()`.
- Controller `extends Spryker\Yves\Kernel\Controller\AbstractController` (or `SprykerShop\Yves\ShopApplication\Controller\AbstractController` with widget registry, storage cache trait).
- Action return: `View` (`$this->view($data, $widgets, $template)`), `array` (`viewResponse($data)`), `RedirectResponse` (`redirectResponseInternal($routeName, $params, $code)`, `redirectResponseExternal($url, $code)`), `JsonResponse` (`jsonResponse($data, $status, $headers)`). Arguments (`Request`, route placeholders) are resolved via reflection.
- Route provider `extends Spryker\Yves\Router\Plugin\RouteProvider\AbstractRouteProviderPlugin` (implements `RouteProviderPluginInterface::addRoutes(RouteCollection): RouteCollection`); helpers `buildRoute|buildGetRoute|buildPostRoute($path, $module, $controller, $action = 'indexAction')`. The controller is resolved via the kernel `ControllerResolver` (Pyz before SprykerShop before Spryker). Module name = folder, controller name without the `Controller` suffix.
- The `_template` convention (`<Module>/<controller-kebab>/<action-kebab>`) exists, but **all** core/Pyz controllers pass the template explicitly to `view()` (`'@Module/views/<name>/<name>.twig'` = file `Theme/default/views/<name>/<name>.twig`). Order in `ShopApplicationTwigEventSubscriber`: `View::getTemplate()`, then the request attribute `_template`, then derivation from the route. Always pass the template.

**Router chain** (`ChainRouter`, order in `RouterDependencyProvider::getRouterPlugins()`): `YvesRouterPlugin` (static routes, cached matcher) → `StorageRouterPlugin` (SEO URLs from storage, `UrlStorageClientInterface::matchUrl()`) → `YvesDevelopmentRouterPlugin` (fallback without cache). **Static routes take precedence over SEO URLs.**
- From the storage hit to the controller: `ControllerRouteEnhancer` chooses among `ResourceCreatorPluginInterface` plugins (`Pyz\Yves\StorageRouter\StorageRouterDependencyProvider::getResourceCreatorPlugins()`) the one with the matching `getType()`; it determines `getModuleName()/getControllerName()/getActionName()` and `mergeResourceData($data)`. In the release: CMS page, catalog category, product detail (`product_abstract` → `ProductDetailPage/Product/detail`), product set, redirect. Custom SEO type = custom ResourceCreatorPlugin; the data comes from publishing ([07](07-publish-sync.md)).
- Store/language prefix: `getRouterEnhancerPlugins()` with `StorePrefixRouterEnhancerPlugin`, `LanguagePrefixRouterEnhancerPlugin`; StorageRouter separately `StorePrefixStorageRouterEnhancerPlugin` (the language prefix is contained in the storage URLs). Allowed languages/stores: `RouterConfig::getAllowedLanguages()/getAllowedStores()`. Store routing only with `SPRYKER_DYNAMIC_STORE_MODE` (`RouterConstants::IS_STORE_ROUTING_ENABLED`). `PostAddRouteManipulatorPlugin`s: Language/StoreDefault, `SslPostAddRouteManipulatorPlugin` (`RouterConstants::YVES_IS_SSL_ENABLED`, `YVES_SSL_EXCLUDED_ROUTE_NAMES`). Observation: `StoreDefaultPostAddRouteManipulatorPlugin` hardcodes `'US'` in the core.

**Overriding the core:**
- Controller: `Pyz\Yves\<Module>\Controller\<Name>Controller extends SprykerShop\...\<Name>Controller`; Pyz is found first, the route stays. Prefer overriding the protected `execute*Action()` methods (examples `Pyz\Yves\CustomerPage\Controller\RegisterController`, `ProductDetailPage\Controller\ProductController`).
- Route: Pyz provider `extends` the core provider, `parent::addRoutes()`, then add; in `getRouteProvider()` register the **Pyz class instead of the core class** (example `Pyz\Yves\MultiCartPage\Plugin\Router\MultiCartPageRouteProviderPlugin`). Same route name: the one added later replaces the earlier one (Symfony, `[Assumption]`, not tested). Removing a route: replace the provider or `$routeCollection->remove('name')` `[Assumption]`.

**Twig extensions:** plugin pattern `Spryker\Shared\TwigExtension\Dependency\Plugin\TwigPluginInterface::extend(Environment, ContainerInterface): Environment`, registered in `Pyz\Yves\Twig\TwigDependencyProvider::getTwigPlugins()` (without parameters). In `extend()`: `addFunction(TwigFunction)`, `addFilter`, `addGlobal`, `addExtension`, `addTokenParser`. Tags: `AbstractTokenParser::getTag()` + `parse(Token): Node` + custom node class (model `ShopUiDefineTwigTokenParser`, tag `define`; `WidgetTagTwigPlugin`, tag `widget`). Template paths: `getTwigLoaderPlugins()`; `TwigConfig::getTemplatePaths()` searches Pyz namespaces first (`src/Pyz/Yves/<Module>/Theme/<theme>`, `.../default`), then the core, so a Pyz Twig file with the same name overrides the core file ([15](15-yves-frontend-atomic.md)).

**Response headers:** (1) Controller: `JsonResponse` with `$headers` or a Symfony response; for `View` actions the response is only created in the view event, so (2) is better. (2) `EventDispatcherPluginInterface::extend(EventDispatcherInterface, ContainerInterface)` with a listener on `KernelEvents::RESPONSE` (pattern `LastVisitCookieEventDispatcherPlugin`), registration in `EventDispatcherDependencyProvider`. (3) Security headers: `ApplicationConfig::getSecurityHeaders()` + `SecurityHeaderExpanderPluginInterface::expand(array): array` in `Pyz\Yves\Application\ApplicationDependencyProvider::getSecurityHeaderExpanderPlugins()` (Pyz: `ConfiguratorSecurityHeaderExpanderPlugin`). Cache-Control: `CacheControlHeaderEventDispatcherPlugin` ([25](25-performance-caching.md)).

**Controller events:** `FilterControllerEventHandlerPluginInterface::handle(ControllerEvent): void` in `ShopApplicationDependencyProvider::getFilterControllerEventSubscriberPlugins()` (release: `CompanyUserRestrictionHandlerPlugin`, `CheckBusinessOnBehalfCompanyUserHandlerPlugin`, `CompanyBusinessUnitControllerRestrictionPlugin`, `LogoutInvalidatedCustomerFilterControllerEventHandlerPlugin`).

## Instructions
New page (template [yves-controller-route](../templates/yves-controller-route/README.md)):
1. Module folder `src/Pyz/Yves/<Name>Page/` ([02](02-project-module.md)).
2. `Plugin/Router/<Name>PageRouteProviderPlugin` with `public const ROUTE_NAME_*`, `buildGetRoute(...)`, requirements/defaults for placeholders.
3. `Controller/<Name>Controller` with `@method ...Factory getFactory()`, action with `view($data, $widgets, '@<Module>/views/...')`.
4. Factory + DependencyProvider (Client/Service via `$container->getLocator()->x()->client()`, never the locator in the controller).
5. Twig view `Theme/default/views/<name>/<name>.twig` (`extends template('page-layout-main')`, `define data`, data as `_view.<key>`).
6. Register in `RouterDependencyProvider::getRouteProvider()`; rebuild the router cache.
7. Optional: protect access ([21](21-security-session.md): `CUSTOMER_SECURED_PATTERN`), Twig plugin, header plugin.

Commands `[live verified (202610.0)]`: Yves CLI (`vendor/bin/yves`) via `src/Pyz/Yves/Console/ConsoleDependencyProvider.php` (`RouterDebugYvesConsole`, `RouterCacheWarmUpConsole`). `router:debug` exists **only** in the Yves console; the Zed console does not know it (`Command "router:debug" is not defined`), there only `router:cache:warm-up`, `router:cache:warm-up:backoffice` and `router:cache:warm-up:backend-gateway` exist.
```bash
docker/sdk cli vendor/bin/yves router:debug [name]     # alias router:debug:yves
docker/sdk cli vendor/bin/yves router:cache:warm-up    # Yves router cache
docker/sdk console cache:empty-all                     # Zed console
```
The Yves router warm-up runs in the Docker SDK build/deploy ([30](30-docker-sdk-workflow.md)). Cache path `RouterConstants::YVES_CACHE_PATH`, can be disabled with `RouterConstants::YVES_IS_CACHE_ENABLED`. New Pyz classes can be found via PSR-4 `Pyz\` without further steps `[Assumption]`.

## Examples
Complete module in [templates/yves-controller-route](../templates/yves-controller-route/README.md). Real release examples:

Route provider (`src/Pyz/Yves/ExampleProductSalePage/Plugin/Router/ExampleProductSaleRouteProviderPlugin.php`):
```php
class ExampleProductSaleRouteProviderPlugin extends AbstractRouteProviderPlugin
{
    public const ROUTE_NAME_SALE = 'sale';

    public function addRoutes(RouteCollection $routeCollection): RouteCollection
    {
        $route = $this->buildRoute('/outlet{categoryPath}', 'ExampleProductSalePage', 'Sale', 'indexAction');
        $route = $route->setRequirement('categoryPath', '\/.+');
        $route = $route->setDefault('categoryPath', null);

        $routeCollection->add(static::ROUTE_NAME_SALE, $route);

        return $routeCollection;
    }
}
```

Action (`.../Controller/SaleController.php`): placeholders arrive as arguments, template explicit:
```php
public function indexAction(?string $categoryPath, Request $request): View
{
    // ...
    return $this->view(
        array_merge($searchResults, ['numberFormatConfig' => $numberFormatConfigTransfer->toArray()]),
        $this->getFactory()->getExampleProductSalePageWidgetPlugins(),
        '@ExampleProductSalePage/views/sale-example/sale-example.twig',
    );
}
```

Extending a core route (`src/Pyz/Yves/MultiCartPage/Plugin/Router/MultiCartPageRouteProviderPlugin.php`):
```php
public function addRoutes(RouteCollection $routeCollection): RouteCollection
{
    $routeCollection = parent::addRoutes($routeCollection);
    $route = $this->buildRoute('/set-default-back/{idQuote}', 'MultiCartPage', 'MultiCart', 'setDefaultBack');
    $route = $route->setRequirement(static::PARAM_ID_QUOTE, '\d+');
    $routeCollection->add(static::ROUTE_MULTI_CART_SET_DEFAULT_BACK, $route);

    return $routeCollection;
}
```

Overriding a core controller (`src/Pyz/Yves/CustomerPage/Controller/RegisterController.php`):
```php
public function indexAction(Request $request): View|RedirectResponse
{
    $response = $this->executeIndexAction($request);
    if (!is_array($response)) {
        return $response;
    }
    // ... project-specific template choice
    return $this->view($response, [], '@CustomerPage/views/login/login.twig');
}
```

Twig function (`src/Pyz/Yves/PriceWidget/Plugin/Twig/PriceModeTwigPlugin.php`; imports `Spryker\Service\Container\ContainerInterface`, `Spryker\Shared\TwigExtension\Dependency\Plugin\TwigPluginInterface`, `Spryker\Yves\Kernel\AbstractPlugin`, `Twig\Environment`, `Twig\TwigFunction`):
```php
public function extend(Environment $twig, ContainerInterface $container): Environment
{
    $twig->addFunction(new TwigFunction('getPriceMode', function () {
        return $this->getFactory()->getPriceClient()->getCurrentPriceMode();
    }));

    return $twig;
}
```

Custom response header (following the pattern of `LastVisitCookieEventDispatcherPlugin`, derived, not executed `[Assumption]`):
```php
class ExampleHeaderEventDispatcherPlugin extends AbstractPlugin implements EventDispatcherPluginInterface
{
    public function extend(EventDispatcherInterface $eventDispatcher, ContainerInterface $container): EventDispatcherInterface
    {
        $eventDispatcher->addListener(KernelEvents::RESPONSE, function (ResponseEvent $event): void {
            $event->getResponse()->headers->set('X-Example-Header', 'hello');
        });

        return $eventDispatcher;
    }
}
```
Imports: `Spryker\Shared\EventDispatcher\EventDispatcherInterface`, `Spryker\Shared\EventDispatcherExtension\Dependency\Plugin\EventDispatcherPluginInterface`, `Symfony\Component\HttpKernel\Event\ResponseEvent`, `Symfony\Component\HttpKernel\KernelEvents`.

Registration (`src/Pyz/Yves/Router/RouterDependencyProvider.php`): `getRouteProvider()` → `new ProductBadgePageRouteProviderPlugin()`; Twig: `TwigDependencyProvider::getTwigPlugins()`; header: `EventDispatcherDependencyProvider::getEventDispatcherPlugins()`.

## Pitfalls
- Route provider not registered in `getRouteProvider()` or cache not cleared → 404.
- Module name in `buildRoute()` ≠ folder, or `'SaleController'` instead of `'Sale'` → controller not found (expects `<Namespace>\Yves\<Module>\Controller\<Name>Controller`).
- Template path: `@Module/views/...` corresponds to `Theme/<theme>/views/...`; `view()` without `$template` and without a matching convention → template not found.
- Placeholder routes (`{categoryPath}`) without requirement/default catch paths that the StorageRouter is supposed to serve.
- Static route collides with an SEO URL: the static one wins.
- `redirectResponseExternal()` without `RedirectUrlValidationEventDispatcherPlugin` triggers `E_USER_DEPRECATED`; external hosts must be listed in `KernelConstants::DOMAIN_WHITELIST` (`STRICT_DOMAIN_REDIRECT = true`).
- Business logic/DB in the controller; in Yves only Client/Service via the Factory, no Zed Facades, no Propel ([01](01-architecture.md), [08](08-client-storage-search.md)).
- Override core controllers only when no `execute*Action()` hook or plugin extension point suffices; never change anything under `vendor/` ([03](03-extending-the-core.md), [28](28-upgradability.md)).
- Use route names as `ROUTE_NAME_*` constants, in Twig `path('name')`/`url()`.
- Outdated docs: `redirectResponse()`, `streamedResponse()`, `Application $app`, `Pyz\Yves\Collector\...StorageRouter`, `getTwigPlugins(Application $app)`, `AbstractTwigExtensionPlugin`, `static/public/Yves/index.php` (release: `public/Yves/index.php`), `HelloRouteProvider($isSsl)` (SSL now via `SslPostAddRouteManipulatorPlugin`), `ApplicationRouteProviderPlugin` (the home route lives in `HomePageRouteProviderPlugin`).

**Deprecations (verified in the release)**

| Old `[Deprecated]` | New |
|---|---|
| `ShopApplication\Plugin\Provider\AbstractYvesControllerProvider` | `Spryker\Yves\Router\Plugin\RouteProvider\AbstractRouteProviderPlugin` |
| `ShopControllerEventServiceProvider` | `ShopApplicationFilterControllerEventDispatcherPlugin` |
| `ShopTwigServiceProvider`, `WidgetTagServiceProvider` (View) | `ShopApplicationEventDispatcherPlugin` |
| `WidgetServiceProvider`, `WidgetTagServiceProvider` (Twig) | `WidgetTwigPlugin`, `WidgetTagTwigPlugin` |
| `YvesExceptionServiceProvider` | `ShopApplicationExceptionEventDispatcherPlugin` |
| `AutoloaderCacheServiceProvider` | `Spryker\Yves\Kernel\Plugin\EventDispatcher\AutoloaderCacheEventDispatcherPlugin` |
| `Spryker\Service\Twig\Plugin\AbstractTwigExtensionPlugin` (partial methods `initRuntime`, `getGlobals`, `getName`) | `TwigPluginInterface` or native Twig interfaces |
| `AbstractController::deprecatedRedirectResponseExternal()` | `redirectResponseExternal()` + `RedirectUrlValidationEventDispatcherPlugin` |
| `HomePageRouteProviderPlugin::ROUTE_HOME` | `ROUTE_NAME_HOME` |

## Checklist
- [ ] Module name/controller name in `buildRoute()` match folder/class exactly
- [ ] Template passed explicitly to `view()`, file under `Theme/default/views/...`
- [ ] Route provider in `RouterDependencyProvider::getRouteProvider()` (for a core extension, the Pyz class instead of the core class)
- [ ] Requirements/defaults set for placeholders
- [ ] Data only via Factory → DependencyProvider → Client/Service
- [ ] Access protection checked (`CUSTOMER_SECURED_PATTERN`)
- [ ] Router cache rebuilt / checked with `vendor/bin/yves router:debug`
- [ ] No deprecated provider/Twig classes, no changes under `vendor/`
- [ ] `declare(strict_types = 1);`, `@method` annotations

## Sources
Docs (base `https://docs.spryker.com/docs/`, as of 2021, partly outdated): `dg/dev/backend-development/yves/{yves,yves-bootstrapping,controllers-and-actions,yves-routes,implement-url-routing-in-yves,url,implementing-twig-extensions}.html`, `dg/dev/frontend-development/latest/yves/custom-twig-functions-for-yves.html`, `dg/dev/frontend-development/latest/define-twig-tag.html`.

Source code: `public/Yves/index.php`; `src/Pyz/Yves/{ShopApplication,Router,StorageRouter,Twig,EventDispatcher,Application,Console}/`; `src/Pyz/Yves/ExampleProductSalePage/`; `src/Pyz/Yves/{CustomerPage,ProductDetailPage,MultiCartPage,PriceWidget}/`; `vendor/spryker/kernel/src/Spryker/Yves/Kernel/Controller/AbstractController.php`; `vendor/spryker/router/src/Spryker/Yves/Router/**`; `vendor/spryker/router-extension/**`; `vendor/spryker/twig/src/Spryker/Yves/Twig/TwigConfig.php`; `vendor/spryker/twig-extension/**`; `vendor/spryker/application/src/Spryker/Yves/Application/ApplicationConfig.php`; `vendor/spryker-shop/shop-application/**`; `vendor/spryker-shop/storage-router/**`, `storage-router-extension/**`; `vendor/spryker-shop/shop-ui/.../ShopUi/{Plugin/Twig,Twig}/**`; `config/Shared/config_default.php` (router, domain whitelist).

Neighbours: [15 Atomic Frontend](15-yves-frontend-atomic.md), [16 Widgets](16-widgets.md), [21 Security](21-security-session.md), template [yves-controller-route](../templates/yves-controller-route/README.md).
