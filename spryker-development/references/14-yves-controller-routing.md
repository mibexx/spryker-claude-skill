# Yves: Controller, Routing, Twig-Erweiterung (Recherche T-14)

> Status: Code in `vendor/` und `src/Pyz` (202610.0, kernel 3.87.1, Symfony 6.4.48) direkt gelesen; Template-Klassen gegen vendor geprüft. Doku-Seiten (Stand 2021) sind teils veraltet und wurden nicht Zeile für Zeile abgeglichen `[code-verifiziert, Doku nicht abgeglichen]`. Konsolenbefehle gegen die Befehlslisten von `vendor/bin/yves` und Zed-Console geprüft (`vendor/bin/yves router:debug` ausgeführt) `[live verifiziert (202610.0)]`. Offen: Verhalten doppelter Routennamen und `_template`-Fallback (nur aus Code/Symfony abgeleitet), eigenes Twig-Tag nur als Skizze.

## Zweck
PHP- und Routing-Seite von Yves: Boot, Route-Provider, Controller/Actions, Core-Controller/-Routen überschreiben, StorageRouter (SEO-URLs), Twig-Plugins, Response-Header, Controller-Events. Templates/Build → [15](15-yves-frontend-atomic.md); Widgets → [16](16-widgets.md); CMS → [17](17-cms.md); Locale/Store → [19](19-multi-store-i18n.md); Rechte/Session → [20](20-b2b-permissions.md), [21](21-security-session.md).

## Konzepte
**Boot:** `public/Yves/index.php` → `Pyz\Yves\ShopApplication\YvesBootstrap` (leer, erbt `SprykerShop\Yves\ShopApplication\Bootstrap\YvesBootstrap`) → `ShopApplicationFactory::createApplication()` baut `Application` mit den Plugins aus `Pyz\Yves\ShopApplication\ShopApplicationDependencyProvider::getApplicationPlugins()` (u. a. `YvesHttpApplicationPlugin`, `TwigApplicationPlugin`, `EventDispatcherApplicationPlugin`, `RouterApplicationPlugin`, `SessionApplicationPlugin`, `YvesSecurityApplicationPlugin`). Jedes Plugin: `ApplicationPluginInterface::provide(ContainerInterface): ContainerInterface`. Derselbe Provider hält `getGlobalWidgets()` ([16](16-widgets.md)), `getFilterControllerEventSubscriberPlugins()`, `getWidgetCacheKeyGeneratorStrategyPlugins()`. EventDispatcher-Plugins: `Pyz\Yves\EventDispatcher\EventDispatcherDependencyProvider::getEventDispatcherPlugins()`.

**Routing-Bausteine** (alle `src/Pyz/Yves/<Modul>/`): Controller → Action → Route-Provider-Plugin → Registrierung in `Pyz\Yves\Router\RouterDependencyProvider::getRouteProvider()`.
- Controller `extends Spryker\Yves\Kernel\Controller\AbstractController` (oder `SprykerShop\Yves\ShopApplication\Controller\AbstractController` mit Widget-Registry, Storage-Cache-Trait).
- Action-Rückgabe: `View` (`$this->view($data, $widgets, $template)`), `array` (`viewResponse($data)`), `RedirectResponse` (`redirectResponseInternal($routeName, $params, $code)`, `redirectResponseExternal($url, $code)`), `JsonResponse` (`jsonResponse($data, $status, $headers)`). Argumente (`Request`, Routen-Platzhalter) werden per Reflection aufgelöst.
- Route-Provider `extends Spryker\Yves\Router\Plugin\RouteProvider\AbstractRouteProviderPlugin` (implementiert `RouteProviderPluginInterface::addRoutes(RouteCollection): RouteCollection`); Helfer `buildRoute|buildGetRoute|buildPostRoute($path, $module, $controller, $action = 'indexAction')`. Controller wird über den Kernel-`ControllerResolver` aufgelöst (Pyz vor SprykerShop vor Spryker). Modulname = Ordner, Controllername ohne `Controller`-Suffix.
- `_template`-Konvention (`<Modul>/<controller-kebab>/<action-kebab>`) existiert, aber **alle** Core-/Pyz-Controller übergeben das Template explizit an `view()` (`'@Modul/views/<name>/<name>.twig'` = Datei `Theme/default/views/<name>/<name>.twig`). Reihenfolge im `ShopApplicationTwigEventSubscriber`: `View::getTemplate()`, dann Request-Attribut `_template`, dann Route-Ableitung. Immer das Template übergeben.

**Router-Kette** (`ChainRouter`, Reihenfolge in `RouterDependencyProvider::getRouterPlugins()`): `YvesRouterPlugin` (statische Routen, gecachter Matcher) → `StorageRouterPlugin` (SEO-URLs aus Storage, `UrlStorageClientInterface::matchUrl()`) → `YvesDevelopmentRouterPlugin` (Fallback ohne Cache). **Statische Routen haben Vorrang vor SEO-URLs.**
- Vom Storage-Treffer zum Controller: `ControllerRouteEnhancer` wählt unter `ResourceCreatorPluginInterface`-Plugins (`Pyz\Yves\StorageRouter\StorageRouterDependencyProvider::getResourceCreatorPlugins()`) das mit passendem `getType()`; bestimmt `getModuleName()/getControllerName()/getActionName()` und `mergeResourceData($data)`. Im Release: CMS-Seite, Katalog-Kategorie, Produktdetail (`product_abstract` → `ProductDetailPage/Product/detail`), Produktset, Redirect. Eigener SEO-Typ = eigenes ResourceCreatorPlugin; die Daten kommen aus dem Publish ([07](07-publish-sync.md)).
- Store-/Sprachpräfix: `getRouterEnhancerPlugins()` mit `StorePrefixRouterEnhancerPlugin`, `LanguagePrefixRouterEnhancerPlugin`; StorageRouter separat `StorePrefixStorageRouterEnhancerPlugin` (Sprachpräfix steckt in den Storage-URLs). Erlaubte Sprachen/Stores: `RouterConfig::getAllowedLanguages()/getAllowedStores()`. Store-Routing nur mit `SPRYKER_DYNAMIC_STORE_MODE` (`RouterConstants::IS_STORE_ROUTING_ENABLED`). `PostAddRouteManipulatorPlugin`s: Language-/StoreDefault, `SslPostAddRouteManipulatorPlugin` (`RouterConstants::YVES_IS_SSL_ENABLED`, `YVES_SSL_EXCLUDED_ROUTE_NAMES`). Beobachtung: `StoreDefaultPostAddRouteManipulatorPlugin` setzt im Core fest `'US'`.

**Core überschreiben:**
- Controller: `Pyz\Yves\<Modul>\Controller\<Name>Controller extends SprykerShop\...\<Name>Controller`; Pyz wird zuerst gefunden, Route bleibt. Bevorzugt die geschützten `execute*Action()`-Methoden überschreiben (Beispiele `Pyz\Yves\CustomerPage\Controller\RegisterController`, `ProductDetailPage\Controller\ProductController`).
- Route: Pyz-Provider `extends` den Core-Provider, `parent::addRoutes()`, ergänzen; in `getRouteProvider()` die **Pyz-Klasse statt der Core-Klasse** eintragen (Beispiel `Pyz\Yves\MultiCartPage\Plugin\Router\MultiCartPageRouteProviderPlugin`). Gleicher Routenname: später hinzugefügter ersetzt früheren (Symfony, `[Annahme]`, nicht getestet). Route entfernen: Provider ersetzen oder `$routeCollection->remove('name')` `[Annahme]`.

**Twig-Erweiterungen:** Plugin-Muster `Spryker\Shared\TwigExtension\Dependency\Plugin\TwigPluginInterface::extend(Environment, ContainerInterface): Environment`, registriert in `Pyz\Yves\Twig\TwigDependencyProvider::getTwigPlugins()` (parameterlos). In `extend()`: `addFunction(TwigFunction)`, `addFilter`, `addGlobal`, `addExtension`, `addTokenParser`. Tags: `AbstractTokenParser::getTag()` + `parse(Token): Node` + eigene Node-Klasse (Vorbild `ShopUiDefineTwigTokenParser`, Tag `define`; `WidgetTagTwigPlugin`, Tag `widget`). Template-Pfade: `getTwigLoaderPlugins()`; `TwigConfig::getTemplatePaths()` sucht erst Pyz-Namespaces (`src/Pyz/Yves/<Modul>/Theme/<theme>`, `.../default`), dann Core, daher überschreibt eine gleichnamige Pyz-Twig-Datei die Core-Datei ([15](15-yves-frontend-atomic.md)).

**Response-Header:** (1) Controller: `JsonResponse` mit `$headers` oder Symfony-Response; bei `View`-Actions entsteht der Response erst im View-Event, daher besser (2). (2) `EventDispatcherPluginInterface::extend(EventDispatcherInterface, ContainerInterface)` mit Listener auf `KernelEvents::RESPONSE` (Muster `LastVisitCookieEventDispatcherPlugin`), Registrierung in `EventDispatcherDependencyProvider`. (3) Security-Header: `ApplicationConfig::getSecurityHeaders()` + `SecurityHeaderExpanderPluginInterface::expand(array): array` in `Pyz\Yves\Application\ApplicationDependencyProvider::getSecurityHeaderExpanderPlugins()` (Pyz: `ConfiguratorSecurityHeaderExpanderPlugin`). Cache-Control: `CacheControlHeaderEventDispatcherPlugin` ([25](25-performance-caching.md)).

**Controller-Events:** `FilterControllerEventHandlerPluginInterface::handle(ControllerEvent): void` in `ShopApplicationDependencyProvider::getFilterControllerEventSubscriberPlugins()` (Release: `CompanyUserRestrictionHandlerPlugin`, `CheckBusinessOnBehalfCompanyUserHandlerPlugin`, `CompanyBusinessUnitControllerRestrictionPlugin`, `LogoutInvalidatedCustomerFilterControllerEventHandlerPlugin`).

## Anleitung
Neue Seite (Template [yves-controller-route](../templates/yves-controller-route/README.md)):
1. Modulordner `src/Pyz/Yves/<Name>Page/` ([02](02-projektmodul.md)).
2. `Plugin/Router/<Name>PageRouteProviderPlugin` mit `public const ROUTE_NAME_*`, `buildGetRoute(...)`, Requirements/Defaults für Platzhalter.
3. `Controller/<Name>Controller` mit `@method ...Factory getFactory()`, Action mit `view($data, $widgets, '@<Modul>/views/...')`.
4. Factory + DependencyProvider (Client/Service über `$container->getLocator()->x()->client()`, nie Locator im Controller).
5. Twig-View `Theme/default/views/<name>/<name>.twig` (`extends template('page-layout-main')`, `define data`, Daten als `_view.<key>`).
6. In `RouterDependencyProvider::getRouteProvider()` eintragen; Router-Cache neu.
7. Optional: Zugriff schützen ([21](21-security-session.md): `CUSTOMER_SECURED_PATTERN`), Twig-Plugin, Header-Plugin.

Befehle `[live verifiziert (202610.0)]`: Yves-CLI (`vendor/bin/yves`) über `src/Pyz/Yves/Console/ConsoleDependencyProvider.php` (`RouterDebugYvesConsole`, `RouterCacheWarmUpConsole`). `router:debug` gibt es **nur** in der Yves-Konsole; die Zed-Konsole kennt es nicht (`Command "router:debug" is not defined`), dort gibt es nur `router:cache:warm-up`, `router:cache:warm-up:backoffice` und `router:cache:warm-up:backend-gateway`.
```bash
docker/sdk cli vendor/bin/yves router:debug [name]     # Alias router:debug:yves
docker/sdk cli vendor/bin/yves router:cache:warm-up    # Yves-Router-Cache
docker/sdk console cache:empty-all                     # Zed-Konsole
```
Das Yves-Router-Warm-up läuft im Docker-SDK-Build/Deploy ([30](30-docker-sdk-workflow.md)). Cache-Pfad `RouterConstants::YVES_CACHE_PATH`, abschaltbar mit `RouterConstants::YVES_IS_CACHE_ENABLED`. Neue Pyz-Klassen sind per PSR-4 `Pyz\` ohne weitere Schritte auffindbar `[Annahme]`.

## Beispiele
Vollständiges Modul in [templates/yves-controller-route](../templates/yves-controller-route/README.md). Echte Release-Beispiele:

Route-Provider (`src/Pyz/Yves/ExampleProductSalePage/Plugin/Router/ExampleProductSaleRouteProviderPlugin.php`):
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

Action (`.../Controller/SaleController.php`): Platzhalter landen als Argument, Template explizit:
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

Core-Route erweitern (`src/Pyz/Yves/MultiCartPage/Plugin/Router/MultiCartPageRouteProviderPlugin.php`):
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

Core-Controller überschreiben (`src/Pyz/Yves/CustomerPage/Controller/RegisterController.php`):
```php
public function indexAction(Request $request): View|RedirectResponse
{
    $response = $this->executeIndexAction($request);
    if (!is_array($response)) {
        return $response;
    }
    // ... projektspezifische Template-Wahl
    return $this->view($response, [], '@CustomerPage/views/login/login.twig');
}
```

Twig-Funktion (`src/Pyz/Yves/PriceWidget/Plugin/Twig/PriceModeTwigPlugin.php`; Imports `Spryker\Service\Container\ContainerInterface`, `Spryker\Shared\TwigExtension\Dependency\Plugin\TwigPluginInterface`, `Spryker\Yves\Kernel\AbstractPlugin`, `Twig\Environment`, `Twig\TwigFunction`):
```php
public function extend(Environment $twig, ContainerInterface $container): Environment
{
    $twig->addFunction(new TwigFunction('getPriceMode', function () {
        return $this->getFactory()->getPriceClient()->getCurrentPriceMode();
    }));

    return $twig;
}
```

Eigener Response-Header (nach dem Muster `LastVisitCookieEventDispatcherPlugin`, abgeleitet, nicht ausgeführt `[Annahme]`):
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

Registrierung (`src/Pyz/Yves/Router/RouterDependencyProvider.php`): `getRouteProvider()` → `new ProductBadgePageRouteProviderPlugin()`; Twig: `TwigDependencyProvider::getTwigPlugins()`; Header: `EventDispatcherDependencyProvider::getEventDispatcherPlugins()`.

## Fallstricke
- Route-Provider nicht in `getRouteProvider()` eingetragen oder Cache nicht geleert → 404.
- Modulname in `buildRoute()` ≠ Ordner, oder `'SaleController'` statt `'Sale'` → Controller nicht gefunden (erwartet `<Namespace>\Yves\<Modul>\Controller\<Name>Controller`).
- Template-Pfad: `@Modul/views/...` entspricht `Theme/<theme>/views/...`; `view()` ohne `$template` und ohne passende Konvention → Template nicht gefunden.
- Platzhalter-Routen (`{categoryPath}`) ohne Requirement/Default fangen Pfade ab, die der StorageRouter bedienen soll.
- Statische Route kollidiert mit SEO-URL: statische gewinnt.
- `redirectResponseExternal()` ohne `RedirectUrlValidationEventDispatcherPlugin` löst `E_USER_DEPRECATED` aus; externe Hosts müssen in `KernelConstants::DOMAIN_WHITELIST` stehen (`STRICT_DOMAIN_REDIRECT = true`).
- Business-Logik/DB im Controller; in Yves nur Client/Service über Factory, keine Zed-Facades, kein Propel ([01](01-architektur.md), [08](08-client-storage-search.md)).
- Core-Controller überschreiben erst, wenn kein `execute*Action()`-Hook oder Plugin-Extension-Point reicht; nie unter `vendor/` ändern ([03](03-core-erweitern.md), [28](28-upgradability.md)).
- Routennamen als `ROUTE_NAME_*`-Konstante nutzen, im Twig `path('name')`/`url()`.
- Veraltete Doku: `redirectResponse()`, `streamedResponse()`, `Application $app`, `Pyz\Yves\Collector\...StorageRouter`, `getTwigPlugins(Application $app)`, `AbstractTwigExtensionPlugin`, `static/public/Yves/index.php` (Release: `public/Yves/index.php`), `HelloRouteProvider($isSsl)` (SSL jetzt per `SslPostAddRouteManipulatorPlugin`), `ApplicationRouteProviderPlugin` (Home-Route liegt in `HomePageRouteProviderPlugin`).

**Deprecations (im Release verifiziert)**

| Alt `[Deprecated]` | Neu |
|---|---|
| `ShopApplication\Plugin\Provider\AbstractYvesControllerProvider` | `Spryker\Yves\Router\Plugin\RouteProvider\AbstractRouteProviderPlugin` |
| `ShopControllerEventServiceProvider` | `ShopApplicationFilterControllerEventDispatcherPlugin` |
| `ShopTwigServiceProvider`, `WidgetTagServiceProvider` (View) | `ShopApplicationEventDispatcherPlugin` |
| `WidgetServiceProvider`, `WidgetTagServiceProvider` (Twig) | `WidgetTwigPlugin`, `WidgetTagTwigPlugin` |
| `YvesExceptionServiceProvider` | `ShopApplicationExceptionEventDispatcherPlugin` |
| `AutoloaderCacheServiceProvider` | `Spryker\Yves\Kernel\Plugin\EventDispatcher\AutoloaderCacheEventDispatcherPlugin` |
| `Spryker\Service\Twig\Plugin\AbstractTwigExtensionPlugin` (Teilmethoden `initRuntime`, `getGlobals`, `getName`) | `TwigPluginInterface` bzw. native Twig-Interfaces |
| `AbstractController::deprecatedRedirectResponseExternal()` | `redirectResponseExternal()` + `RedirectUrlValidationEventDispatcherPlugin` |
| `HomePageRouteProviderPlugin::ROUTE_HOME` | `ROUTE_NAME_HOME` |

## Checkliste
- [ ] Modulname/Controllername in `buildRoute()` passen exakt zu Ordner/Klasse
- [ ] Template explizit an `view()` übergeben, Datei unter `Theme/default/views/...`
- [ ] Route-Provider in `RouterDependencyProvider::getRouteProvider()` (bei Core-Erweiterung Pyz-Klasse statt Core-Klasse)
- [ ] Requirements/Defaults für Platzhalter gesetzt
- [ ] Daten nur über Factory → DependencyProvider → Client/Service
- [ ] Zugriffsschutz geprüft (`CUSTOMER_SECURED_PATTERN`)
- [ ] Router-Cache neu / `vendor/bin/yves router:debug` geprüft
- [ ] Keine deprecated Provider-/Twig-Klassen, keine Änderungen unter `vendor/`
- [ ] `declare(strict_types = 1);`, `@method`-Annotationen

## Quellen
Doku (Basis `https://docs.spryker.com/docs/`, Stand 2021, teils veraltet): `dg/dev/backend-development/yves/{yves,yves-bootstrapping,controllers-and-actions,yves-routes,implement-url-routing-in-yves,url,implementing-twig-extensions}.html`, `dg/dev/frontend-development/latest/yves/custom-twig-functions-for-yves.html`, `dg/dev/frontend-development/latest/define-twig-tag.html`.

Quellcode: `public/Yves/index.php`; `src/Pyz/Yves/{ShopApplication,Router,StorageRouter,Twig,EventDispatcher,Application,Console}/`; `src/Pyz/Yves/ExampleProductSalePage/`; `src/Pyz/Yves/{CustomerPage,ProductDetailPage,MultiCartPage,PriceWidget}/`; `vendor/spryker/kernel/src/Spryker/Yves/Kernel/Controller/AbstractController.php`; `vendor/spryker/router/src/Spryker/Yves/Router/**`; `vendor/spryker/router-extension/**`; `vendor/spryker/twig/src/Spryker/Yves/Twig/TwigConfig.php`; `vendor/spryker/twig-extension/**`; `vendor/spryker/application/src/Spryker/Yves/Application/ApplicationConfig.php`; `vendor/spryker-shop/shop-application/**`; `vendor/spryker-shop/storage-router/**`, `storage-router-extension/**`; `vendor/spryker-shop/shop-ui/.../ShopUi/{Plugin/Twig,Twig}/**`; `config/Shared/config_default.php` (Router, Domain-Whitelist).

Nachbarn: [15 Atomic Frontend](15-yves-frontend-atomic.md), [16 Widgets](16-widgets.md), [21 Security](21-security-session.md), Template [yves-controller-route](../templates/yves-controller-route/README.md).
