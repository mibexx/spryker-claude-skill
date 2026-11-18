# Template: glue-resource-backend (Glue Backend API Application)

## Purpose
Backend resource `product-badges` on the **GlueBackendApiApplication** (`glue-backend.*`, Back Office users/system-to-system, OAuth token via `POST /token`):
- `GET /product-badges[?badgeKey=<key>]` returns the badge collection.
- `POST /product-badges` creates a badge (`fkProductAbstract`, `badgeKey`, `label`; body as flat JSON, see below).

Unlike the Storefront, the backend works directly with the **Zed Facade**, not with Clients. The Facade `Pyz\Zed\ProductBadge\Business\ProductBadgeFacadeInterface` comes from the template `zed-module`; it is fetched without a bridge directly via the locator in the DependencyProvider, the Factory types the getter to `ProductBadgeFacadeInterface`. See [13-glue-backend](../../references/13-glue-backend.md).

## File list (target path below the project root)
```
src/Pyz/Shared/ProductBadgesBackendApi/Transfer/product_badges_backend_api.transfer.xml
src/Pyz/Glue/ProductBadgesBackendApi/ProductBadgesBackendApiConfig.php
src/Pyz/Glue/ProductBadgesBackendApi/ProductBadgesBackendApiDependencyProvider.php   (Backend\AbstractBundleDependencyProvider, provideBackendDependencies)
src/Pyz/Glue/ProductBadgesBackendApi/ProductBadgesBackendApiFactory.php               (Backend\AbstractBackendApiFactory)
src/Pyz/Glue/ProductBadgesBackendApi/Controller/ProductBadgesResourceController.php   (Backend\Controller\AbstractBackendApiController)
src/Pyz/Glue/ProductBadgesBackendApi/Plugin/GlueBackendApiApplication/ProductBadgesBackendResource.php   (Backend\AbstractResourcePlugin)
src/Pyz/Glue/ProductBadgesBackendApi/Processor/Reader/ProductBadgeReader(.php|Interface.php)
src/Pyz/Glue/ProductBadgesBackendApi/Processor/Creator/ProductBadgeCreator(.php|Interface.php)
src/Pyz/Glue/ProductBadgesBackendApi/Processor/Mapper/ProductBadgeMapper(.php|Interface.php)
```

## Placeholders / customization
- Replace `ProductBadge(s)` with your own name. The resource type is the URL segment (`/product-badges`).
- Error codes `4501` (400, missing attributes) and `4502` (422, `InvalidProductBadgeException` from the Facade: invalid key or unknown abstract product) are freely chosen (assumption: unused). Domain exceptions of the Facade are caught in `ProductBadgeCreator`, otherwise HTTP 500 results.
- Transfers: `ProductBadge`, `ProductBadgeCollection`, `ProductBadgeCriteria` are `strict="true"` as in transfer-and-propel (otherwise `TransferDefinitionMismatchException` when combining the templates).
- PUT is not provided for in `ResourceRouteBuilder`; for PUT/special routes build a `RouteProviderPluginInterface` (model `DynamicEntityRouteProviderPlugin`).
- Parent-child (`/parent/{id}/child`) via `ResourceWithParentPluginInterface` is not proven in the backend router (see reference); check with `router:debug backend` before use.

## Registration (3 places)
1. Resource plugin: `src/Pyz/Glue/GlueBackendApiApplication/GlueBackendApiApplicationDependencyProvider.php`, `getResourcePlugins()`:
   ```php
   use Pyz\Glue\ProductBadgesBackendApi\Plugin\GlueBackendApiApplication\ProductBadgesBackendResource;
   ...
   $plugins = [
       new OauthBackendApiTokenResource(),
       // ...
       new ProductBadgesBackendResource(),
   ];
   ```
2. Protection: extend `src/Pyz/Shared/GlueBackendApiApplicationAuthorizationConnector/GlueBackendApiApplicationAuthorizationConnectorConfig.php`, `getProtectedPaths()`. Without an entry the resource is reachable anonymously (confirmed live: `GET /product-badges` without a token returns `200` with all badges without the entry, `401` with the entry):
   ```php
   '/product-badges' => [
       'isRegularExpression' => false,
   ],
   ```
   Optionally restrict to methods: `'methods' => ['post']`.
3. No scope declaration needed: `ScopeDefinitionPluginInterface` is deliberately not implemented here. If it is implemented, every method without a scope entry is forbidden (403).

## Commands afterwards
All commands and the curl calls ran in shop 202610.0 `[live verified (202610.0)]`.
```bash
docker/sdk cli vendor/bin/console transfer:generate
docker/sdk cli vendor/bin/console dev:ide-auto-completion:generate   # so that the locator call productBadge() is known to PHPStan/IDE
docker/sdk cli vendor/bin/glue glue-api:controller:cache:warm-up   # WITHOUT argument: with an application name the run overwrites the cache of the other application (reference 13)
docker/sdk cli vendor/bin/glue router:debug backend       # argument: backend | storefront (docs say "route:debug", code "router:debug")
docker/sdk cli vendor/bin/console oauth:scope-collection-file:generate   # only with a scope declaration
# Get a token (form body; JSON returns "unsupported_grant_type"), then call the resource
curl -X POST http://glue-backend.eu.spryker.local/token -H 'Content-Type: application/x-www-form-urlencoded' \
  -d 'grant_type=password&username=<user>&password=<pw>'
curl http://glue-backend.eu.spryker.local/product-badges -H 'Authorization: Bearer <access_token>'
curl -X POST http://glue-backend.eu.spryker.local/product-badges -H 'Authorization: Bearer <access_token>' \
  -H 'Content-Type: application/json' -d '{"fkProductAbstract":7,"badgeKey":"sale","label":"Sale"}'
```
A wrong application name during warm-up writes an empty controller cache.

Live result (demo user `admin@spryker.com` / `change123`, release 202610.0):
- Without token: `401` `[{"code":null,"status":401,"message":"Unauthorized request."}]` (entry in `getProtectedPaths()` takes effect).
- `POST /token` (form body) -> `200` with `access_token` (JWT, 8 h).
- `GET /product-badges` -> `200`, **a flat JSON array** `[{"idProductBadge":1,"fkProductAbstract":4,"badgeKey":"new","label":"New!"}]`, `?badgeKey=new` filters.
- `POST` with a flat JSON body -> `201`, response is the object `{"idProductBadge":4,...}`. Missing attributes -> `400` code `4501`, invalid key (`zzz`) -> `422` code `4502` `Badge key "zzz" is not allowed.`, unknown abstract -> `422` `Product abstract with id "9999" does not exist.`.
- **No JSON:API wrapper in this project:** the GlueBackend application has no JSON API convention registered here. A body `{"data":{"type":...,"attributes":{...}}}` is not unpacked (`GlueRequestTransfer.attributes` contains the whole JSON, attributes stay empty -> `4501`), and responses have no `data`/`attributes`. With a registered `GlueJsonApiConvention` the wrapper would apply (the convention's `AttributesRequestBuilder` reads `data.attributes`).


- **Rollback/removal:** after removing the resource, run `docker/sdk cli vendor/bin/glue glue-api:controller:cache:warm-up` (without argument) and `docker/sdk cli vendor/bin/glue api:generate:documentation` again; the generated files under `src/Generated/GlueBackend/Specification/` are excluded via `.gitignore` and not visible in `git status`.
## Source used (model)
- `vendor/spryker/oauth-backend-api/src/Spryker/Glue/OauthBackendApi/` (resource plugin, `TokenResourceController`, backend DependencyProvider; there with a Facade bridge as a core pattern, in the template deliberately without a bridge)
- `vendor/spryker/multi-factor-auth/src/Spryker/Glue/MultiFactorAuth/Plugin/GlueBackendApiApplication/` (`getCollection` declaration)
- `vendor/spryker/dynamic-entity-backend-api/` (controller signatures `getAction(string $id, ...)`, query fields via `GlueRequestTransfer::getQueryFields()`)
- Registration: `src/Pyz/Glue/GlueBackendApiApplication/GlueBackendApiApplicationDependencyProvider.php`

## Verification status
Walked through in shop 202610.0 (all three registration places as described, `router:debug backend` shows `product-badgesResourceGetCollection` and `product-badgesResourcePost` with protection `Yes`, token and resource calls via curl, `code:sniff:style` exit 0, PHPStan level 6 without findings). The response form is flat (see above), the earlier assumption `data[]`/`attributes` was wrong. `[live verified (202610.0)]`
