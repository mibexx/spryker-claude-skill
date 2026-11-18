# Template: yves-controller-route (Yves page with route, controller, Twig view)

## Purpose
Yves module `ProductBadgePage` with the route `GET /product-badge/{idProductAbstract}` (route name `product-badge`), which outputs the badges of an abstract product from the Client `Pyz\Client\ProductBadge\ProductBadgeClientInterface` (Storage) in a Twig view. Unknown ID or no badges: redirect to the 404 error page (live: `307`, see below). Explanation of the pattern: [14-yves-controller-routing](../../references/14-yves-controller-routing.md).

## File list (target path below the project root)
```
src/Pyz/Yves/ProductBadgePage/Plugin/Router/ProductBadgePageRouteProviderPlugin.php
src/Pyz/Yves/ProductBadgePage/Controller/ProductBadgeController.php
src/Pyz/Yves/ProductBadgePage/Reader/ProductBadgeReader.php
src/Pyz/Yves/ProductBadgePage/Reader/ProductBadgeReaderInterface.php
src/Pyz/Yves/ProductBadgePage/ProductBadgePageFactory.php
src/Pyz/Yves/ProductBadgePage/ProductBadgePageDependencyProvider.php
src/Pyz/Yves/ProductBadgePage/Theme/default/views/product-badge/product-badge.twig
```

## Placeholders / customization
- Replace `ProductBadgePage` (module folder = namespace segment = Twig namespace `@ProductBadgePage`), `ProductBadge` (controller without suffix), `product-badge` (route/view).
- In `buildGetRoute()` the module name and controller name must match folder and class exactly (`'ProductBadgePage'`, `'ProductBadge'`, not `'ProductBadgeController'`).
- The template is passed explicitly to `view()` (`@ProductBadgePage/views/product-badge/product-badge.twig` = file `Theme/default/views/product-badge/product-badge.twig`); do not rely on the `_template` convention.
- The title in the Twig file is hard-coded; for multilingual support use a glossary key and `| trans` (→ [19-multi-store-i18n](../../references/19-multi-store-i18n.md)).
- If the page should only be for logged-in customers: add the URL to `CustomerConstants::CUSTOMER_SECURED_PATTERN` (`config/Shared/config_default.php`) (→ [21-security-session](../../references/21-security-session.md)).

## Registration
File `src/Pyz/Yves/Router/RouterDependencyProvider.php`, method `getRouteProvider()`:
```php
use Pyz\Yves\ProductBadgePage\Plugin\Router\ProductBadgePageRouteProviderPlugin;
...
protected function getRouteProvider(): array
{
    return [
        // ...
        new ProductBadgePageRouteProviderPlugin(),
    ];
}
```
Without an entry: 404. No composer entry needed (namespace `Pyz\`). Prerequisite: Client template `Pyz\Client\ProductBadge` (locator `productBadge()->client()`).

## Commands afterwards
`[live verified (202610.0)]` (template installed in the shop, commands and route executed)
```bash
docker/sdk cli vendor/bin/yves router:cache:warm-up       # Yves router cache (Yves console, not Zed); in dev mode YvesDevelopmentRouterPlugin intercepts matches
docker/sdk console cache:empty-all                        # Zed console
docker/sdk cli vendor/bin/yves router:debug product-badge # check the route (only in the Yves console, Zed has no router:debug)
```
Call: `http://yves.eu.spryker.local/product-badge/<idProductAbstract>` (possibly with language prefix `/en/...`).

Live result (202610.0): `GET /product-badge/4` and `/en/product-badge/4` -> `200`, page with `<h1>Product badges (#4)</h1>` and `<li data-qa="product-badge-new">`. Unknown ID (`/product-badge/9999`) -> **`307` redirect** to `/DE/en/error-page/404?errorMessage=No+product+badges+found+for+abstract+product+%229999%22.` (The error page itself returns HTTP `200` in this shop.) `router:debug product-badge` shows path `/product-badge/{idProductAbstract}`, requirement `\d+`, controller `ProductBadgeController::indexAction`.

## Source used (model)
- `src/Pyz/Yves/ExampleProductSalePage/` (provider, controller, Factory, DependencyProvider, Twig view)
- `src/Pyz/Yves/Router/RouterDependencyProvider.php` (registration)
- `vendor/spryker/router/src/Spryker/Yves/Router/Plugin/RouteProvider/AbstractRouteProviderPlugin.php`, `vendor/spryker/kernel/src/Spryker/Yves/Kernel/Controller/AbstractController.php`

## Verification status
Classes and signatures (`AbstractRouteProviderPlugin::buildGetRoute`, `AbstractController::view`, `AbstractBundleDependencyProvider::provideDependencies(Container)`, `AbstractFactory::getProvidedDependency`, template `page-layout-main` with block `content`) checked against `vendor/` and `src/Pyz`. Walked through in shop 202610.0 (registration in `RouterDependencyProvider`, router cache warm-up, page via curl), `code:sniff:style` exit 0, PHPStan level 6 without findings. `[live verified (202610.0)]`
