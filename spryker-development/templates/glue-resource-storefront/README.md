# Template: glue-resource-storefront (classic GlueApplication stack)

## Purpose
Public Storefront resource `product-badges` in the **classic Glue stack** (`glue.*`, `ResourceRoutePluginInterface`, JSON:API). Reads the badges of an abstract product via the Client `Pyz\Client\ProductBadge\ProductBadgeClientInterface` (Storage, no Zed access). The Client is fetched without a bridge directly via the locator in the DependencyProvider; the Factory types the getter to `ProductBadgeClientInterface`.

- `GET /product-badges?idProductAbstract=5` returns the badges (collection, `id` = `badgeKey`).
- `GET /product-badges/{badgeKey}?idProductAbstract=5` returns one badge.

Why this stack: in 202610.0 practically all commerce resources live in the classic stack (`glue.*`, approx. 80 route plugins). The decoupled stack (`glue-storefront.*`, `GlueStorefrontApiApplication`) has only `token`, `stores` and multi-factor auth resources registered; `spryker/api-platform` is **not installed**. See [12-glue-storefront](../../references/12-glue-storefront.md).

## File list (target path below the project root)
```
src/Pyz/Shared/ProductBadgesRestApi/Transfer/product_badges_rest_api.transfer.xml
src/Pyz/Glue/ProductBadgesRestApi/ProductBadgesRestApiConfig.php
src/Pyz/Glue/ProductBadgesRestApi/ProductBadgesRestApiDependencyProvider.php
src/Pyz/Glue/ProductBadgesRestApi/ProductBadgesRestApiFactory.php
src/Pyz/Glue/ProductBadgesRestApi/Controller/ProductBadgesResourceController.php
src/Pyz/Glue/ProductBadgesRestApi/Plugin/GlueApplication/ProductBadgesResourceRoutePlugin.php
src/Pyz/Glue/ProductBadgesRestApi/Processor/Reader/ProductBadgeReader(.php|Interface.php)
src/Pyz/Glue/ProductBadgesRestApi/Processor/Mapper/ProductBadgeMapper(.php|Interface.php)
src/Pyz/Glue/ProductBadgesRestApi/Processor/RestResponseBuilder/ProductBadgeRestResponseBuilder(.php|Interface.php)
```

## Placeholders / customization
- Replace `ProductBadge` / `ProductBadges` / `product-badges` with your own name (module name, resource type, controller kebab name `product-badges-resource` = `ProductBadgesResourceController`).
- Error codes `4401`/`4402` in `ProductBadgesRestApiConfig` are freely chosen (assumption: unused in the project); keep them unique per module.
- Transfers: `ProductBadge` is `strict="true"` here as in transfer-and-propel (otherwise `TransferDefinitionMismatchException` when combining the templates); the properties are merged during generation.
- The Client return value `array` is mapped defensively (`ProductBadgeTransfer` or storage array, `fromArray(..., true)` accepts snake_case and camelCase). If the Client template adopts a different type, only change `ProductBadgeMapper`.
- If the resource should be protected: in `ProductBadgesResourceRoutePlugin::configure()` use `addGet('get')` without `false` (default is `isProtected = true`). For POST/PATCH additionally create `*.validation.yaml` (see reference).

## Registration
File `src/Pyz/Glue/GlueApplication/GlueApplicationDependencyProvider.php`, method `getResourceRoutePlugins()`:
```php
use Pyz\Glue\ProductBadgesRestApi\Plugin\GlueApplication\ProductBadgesResourceRoutePlugin;
...
protected function getResourceRoutePlugins(): array
{
    return [
        // ...
        new ProductBadgesResourceRoutePlugin(),
    ];
}
```
No composer entry needed (namespace `Pyz\`). The module needs the locator call `productBadge()->client()`; for this the Client template (`Pyz\Client\ProductBadge`) must be present.

## Commands afterwards
`transfer:generate`, `dev:ide-auto-completion:generate`, `rest-api:generate:documentation` and the curl calls against the resource ran in shop 202610.0 `[live verified (202610.0)]`.
```bash
docker/sdk cli vendor/bin/console transfer:generate
docker/sdk cli vendor/bin/console dev:ide-auto-completion:generate   # needed so that the locator call productBadge() is known to PHPStan/IDE
curl -H 'Accept: application/vnd.api+json' 'http://glue.eu.spryker.local/product-badges?idProductAbstract=1'
# Optional OpenAPI (stack A): Zed console, not vendor/bin/glue
docker/sdk console rest-api:generate:documentation
```
Live result (badge 1 on abstract 4, published):
- `GET /product-badges?idProductAbstract=4` -> `200`, `{"data":[{"type":"product-badges","id":"new","attributes":{"badgeKey":"new","label":"New!"},"links":{"self":"..."}}],"links":{...}}`.
- `GET /product-badges/new?idProductAbstract=4` -> `200`, `data` as an object.
- `GET /product-badges?idProductAbstract=9999` (no badges) -> `200` with `"data":[]`.
- `GET /product-badges` without parameters -> `400`, code `4401`, `Query parameter "idProductAbstract" is missing or invalid.`
- The resource is reachable anonymously (`addGet('get', false)`).
- The Codeception Cest (`seeResponseMatchesOpenApiSchema`) needs `rest-api:generate:documentation` beforehand, otherwise `No valid path is found in the schema` (see template `codeception-test`).
- **Rollback/removal:** if the resource is removed again, afterwards run `docker/sdk cli console rest-api:generate:documentation` again and rebuild `rest-api:build-request-validation-cache`. The generated `src/Generated/Glue/Specification/spryker_rest_api.schema.yml` is excluded via `.gitignore` (not visible in `git status`) and otherwise still contains `product-badges`; check via `grep -ci badge …` = 0 `[live verified (202610.0)]`.
- `cache:empty-all` deletes the Glue controller cache; afterwards `docker/sdk cli vendor/bin/glue glue-api:controller:cache:warm-up` (without argument, see reference 12) for the decoupled stacks; the classic stack does not need it.
The classic stack (`glue.*`) does not read a controller cache (`getControllerConfigurationCacheCollectorPlugins()` only knows Storefront and Backend); a controller cache warm-up is not needed here and `router:debug` does not exist for this stack (`router:debug` only accepts `storefront`/`backend`). An unknown route returns `{"errors":[{"status":404,"detail":"Not Found"}]}` live, not the codes `007`/`008` (those belong to the decoupled stack).

## Source used (model)
- `vendor/spryker/urls-rest-api/src/Spryker/Glue/UrlsRestApi/` (route plugin, controller, ResponseBuilder, public GET resource with query parameter)
- `vendor/spryker/stores-rest-api/src/Spryker/Glue/StoresRestApi/` (Factory, DependencyProvider, Mapper; there with a bridge as a core pattern, in the template deliberately without a bridge)
- Registration: `src/Pyz/Glue/GlueApplication/GlueApplicationDependencyProvider.php`

## Verification status
Walked through in shop 202610.0 (registration in `GlueApplicationDependencyProvider` as described, curl against `glue.eu.spryker.local`, `code:sniff:style` exit 0, PHPStan level 6 without findings, Cest `ProductBadgesRestApiCest` green). The response form (`data[]` with `type=product-badges`, `attributes`, `links.self`) is thereby proven. `[live verified (202610.0)]`
