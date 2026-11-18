# Glue Backend API, decoupled Glue and API authorization

> Status: code in `vendor/` and `src/Pyz` (202610.0) read directly, base classes and signatures of the example checked against `vendor/`. The docs texts were not cross-checked `[code-verified, docs not cross-checked]`. All commands were executed in the container `[live verified (202610.0)]`. The template was checked in a sandbox with `php -l`, phpcs, phpstan (L6) and `transfer:generate` (combined with transfer-and-propel), not run against a running instance. Open gaps: AND/OR combination of several authorization strategies, console command of the Dynamic Entity installer, parent-child in the backend router, own third Glue application (docs only).

## Purpose
Build and secure backend API resources (Back Office users, system-to-system) on the `GlueBackendApiApplication` (OAuth token, protected paths, scopes, API key, Dynamic Entity). Storefront API → [12](12-glue-storefront.md); security in general → [21](21-security-session.md); ACL in the Back Office → [18](18-back-office.md).

## Concepts
**Entry points in 202610.0** (`public/*/index.php`, `deploy.dev.yml`)

| `public/…` | Bootstrap | Purpose |
|---|---|---|
| `Glue/` | `Pyz\Glue\GlueApplication\Bootstrap\GlueBootstrap` | Legacy Glue (`*RestApi`) → [12](12-glue-storefront.md) |
| `GlueStorefront/` | `GlueStorefrontApiBootstrap` | Storefront API → [12](12-glue-storefront.md) |
| `GlueBackend/` | `GlueBackendApiBootstrap` (`APPLICATION=GLUE_BACKEND`) | **Glue Backend API** (this topic), host `glue-backend.eu.spryker.local` |
| `BackendApi/` | Zed `BackendApiBootstrap` (`backend-api.*`) | Older Zed entry point, not the Glue Backend API; configured in `deploy.dev.yml` (`backapi_eu`/`backapi_us`, `application: zed`, `entry-point: BackendApi`, host `backend-api.*`), usage by project code not explored in depth |

"Decoupled Glue" = separation into `GlueStorefrontApiApplication` and `GlueBackendApiApplication` based on `GlueApplication`. Configuration `config/Shared/config_default.php` (section "Glue Backend API"): `GLUE_BACKEND_API_HOST`, `PROJECT_NAMESPACES = ['Pyz']`, CORS.

**Backend vs. Storefront**

| Aspect | Storefront | Backend |
|---|---|---|
| Data access | Clients | **Facades** directly (locator in the DependencyProvider, without bridge) |
| Identity | Customer (`CustomerRequestBuilderPlugin`) | Back Office user (`UserRequestBuilderPlugin`) |
| OAuth module | `oauth-api` (`OauthApiTokenResource`) | `oauth-backend-api` (`OauthBackendApiTokenResource`) |
| Kernel base classes | `Spryker\Glue\Kernel\...` | `Spryker\Glue\Kernel\Backend\...` (Factory, Plugin, Controller, DependencyProvider, Container) |
| Module names | `*Api`, `*RestApi` | `*BackendApi` |
| Routes | Resource plugins (+ legacy route plugins) | `ResourceInterface` plugins **and** `RouteProviderPluginInterface` |

**Routes:** `ResourceRouteBuilder` creates `/{type}` and `/{type}/{id}` (`get`, `patch`, `delete`); `get|getCollection→GET`, `post→POST`. **PUT is not in `ACTION_METHOD_MAP`**: PUT/special routes only via `RouteProviderPluginInterface` (model `DynamicEntity`). Default format CamelCase, `setIsSnakeCased(true)` switches to snake_case. JSON:API is **not** the default: `JsonApiConventionPlugin::isApplicable()` only applies with `Content-Type`/`Accept: application/vnd.api+json` and only for resources with `JsonApiResourceInterface`.

**Authentication:** `POST /token` (`OauthBackendApiTokenResource`, controller `TokenResourceController`, attributes `ApiTokenAttributesTransfer`, application `GLUE_BACKEND_API_APPLICATION`, audit log). Pipeline in the project (`GlueBackendApiApplicationDependencyProvider`): `ApplicationIdentifier-`/`Locale-`/`UserRequestBuilderPlugin`; validators `BackendApiAccessTokenValidatorPlugin`, `UserRequestValidatorPlugin`; after routing `RequestCors-`, `ScopeRequestAfterRouting-`, `AuthorizationRequestAfterRoutingValidatorPlugin`, `MultiFactorAuthBackendApiRequestValidatorPlugin`. Scopes: `UserOauthScopeProviderPlugin` (grant `password`, scope `user`; additionally `back-office-user` possible).

**Authorization** (`AuthorizationValidator::validate()`): `OPTIONS` always allowed; strategies from the route default `_authorization_strategies`; if the path is in `getProtectedPaths()`, `ProtectedPath` (401 "Unauthorized request.") is added plus the configs of the `ProtectedRouteAuthorizationConfigProviderPlugins` (Pyz: `OauthUserScopeProtectedRouteAuthorizationConfigProviderPlugin` → `UserOauthScope`, 403); `AuthorizationRequestExpanderPlugins` (Pyz: `ApiKeyAuthorizationRequestExpanderPlugin`) add to the identity.

| Strategy | Effect |
|---|---|
| `ProtectedPath` | Path in `getProtectedPaths()` → authenticated user required, otherwise 401 |
| `UserOauthScope` | User identity needs scope `back-office-user` or exactly `['user']`, otherwise 403 |
| `ApiKey` | valid key in header `x-api-key` or query `api_key` |

**Scope per resource:** if a resource plugin implements `Spryker\Glue\OauthExtension\Dependency\Plugin\ScopeDefinitionPluginInterface::getScopes(): array` (method lowercase → scope), `ScopeRequestAfterRoutingValidatorPlugin` checks `RequestUser->getScopes()`; **method without entry = forbidden (403)**. Scopes end up in `src/Generated/Zed/Oauth/GlueScopesCache/glue_scopes_cache.yml` via `oauth:scope-collection-file:generate`. In the release, no installed backend resource uses this interface.

**API key:** modules `api-key`, `api-key-gui` (Back Office: Administration → API Keys), `api-key-authorization-connector`. Keys are only stored hashed (`sha256`); if lost, generate a new one. Route protection via `->setDefault('_authorization_strategies', ['ApiKey'])`.

**Dynamic Entity (Data Exchange API):** `spryker-feature/data-exchange-api` → `dynamic-entity`, `dynamic-entity-backend-api`, `dynamic-entity-gui`. `DynamicEntityRouteProviderPlugin` (in `getRouteProviderPlugins()`) builds `GET/POST/PATCH/PUT/DELETE` on `/dynamic-entity/{resourceName}[/{id}]`, route default `_authorization_strategies = ['ApiKey']`. Exposed tables/fields: `src/Pyz/Zed/DynamicEntity/data/installer/configuration.json` (75 entries) or the Back Office. The regex `/^\/dynamic-entity\/.+/` is in `Pyz\Glue\GlueApplication\GlueApplicationConfig::getRoutePatternsAllowedForCacheWarmUp()` (cache warm-up pattern), not in `getProtectedPaths()`. Log: `data/dynamic-entity/logs/<name>.log`.

**API Platform:** `spryker/api-platform` is **not installed**. The code is prepared (`ApiApplicationProxy` falls back to the Symfony kernel/API Platform on a 404 without an executable resource; `stores-api` contains a dead `Api/Storefront/Provider/StoresStorefrontProvider.php`). For new endpoints, use the `AbstractResourcePlugin` approach from this document; API Platform only after deliberate introduction (not verified).

## Instructions
New backend resource (template [glue-resource-backend](../templates/glue-resource-backend/README.md)):
1. Transfer `*BackendApiAttributes` (+ stubs `GlueRequest`, `GlueResponse`, `GlueResource`, `GlueError`, `GlueResourceMethodCollection`, `GlueResourceMethodConfiguration`) ([04](04-transfer.md)).
2. Config with a `RESOURCE_*` constant (= URL segment).
3. **Factory** `extends Spryker\Glue\Kernel\Backend\AbstractBackendApiFactory` (mandatory: otherwise `AbstractBackendApiController::getFactory()` throws `InvalidAbstractFactoryException`); **DependencyProvider** `extends Spryker\Glue\Kernel\Backend\AbstractBundleDependencyProvider`, dependencies in `provideBackendDependencies(Spryker\Glue\Kernel\Backend\Container)` (Facade directly via `$container->getLocator()->x()->facade()`, without bridge; type the Factory getter to `Pyz\Zed\X\Business\XFacadeInterface` or the Facade interface of the core module; bridges only as a core pattern, see [02](02-project-module.md)).
4. **Controller** `extends Spryker\Glue\Kernel\Backend\Controller\AbstractBackendApiController`: `getCollectionAction(GlueRequestTransfer)`, `getAction(string $id, GlueRequestTransfer)`, `postAction(<Attributes>Transfer, GlueRequestTransfer)`, `patchAction`, `deleteAction`; returns `GlueResponseTransfer` (errors via `GlueErrorTransfer`, data via `GlueResourceTransfer`). The controller stays thin.
5. **Resource plugin** `extends Spryker\Glue\GlueApplication\Plugin\GlueApplication\Backend\AbstractResourcePlugin implements ResourceInterface` (the variant without `Backend` belongs to the Storefront/legacy world): `getType()`, `getController()` (FQCN), `getDeclaredMethods()`.
6. **Register** in `Pyz\Glue\GlueBackendApiApplication\GlueBackendApiApplicationDependencyProvider::getResourcePlugins()`.
7. **Protect:** path in `Pyz\Shared\GlueBackendApiApplicationAuthorizationConnector\GlueBackendApiApplicationAuthorizationConnectorConfig::getProtectedPaths()` (`'/product-badges' => ['isRegularExpression' => false]`, optionally `'methods' => [...]`). Without an entry and without `_authorization_strategies`, the resource is probably reachable anonymously `[Assumption]`, derived from `AuthorizationValidator`.
8. Commands: `transfer:generate`, controller cache (`GLUE_BACKEND_API_APPLICATION`), `router:debug backend`, scope file if needed (see Examples).

**Error handling in resources:** catch business exceptions of the Facade (e.g. `InvalidProductBadgeException`) in the creator/reader and return them as `GlueErrorTransfer` with 400/422; uncaught they become HTTP 500 (template: 400 for missing attributes, 422 for an invalid key/unknown product).

**Own authorization strategy:** plugin `implements Spryker\Shared\AuthorizationExtension\Dependency\Plugin\AuthorizationStrategyPluginInterface` (`authorize(AuthorizationRequestTransfer): bool`, `getStrategyName(): string`; in the code it lives under `Shared`, the docs still name `Spryker\Client\AuthorizationExtension\...`), registration in `Pyz\Zed\Authorization\AuthorizationDependencyProvider::getAuthorizationStrategyPlugins()`, assignment via route default `_authorization_strategies` or `ProtectedRouteAuthorizationConfigProviderPluginInterface::provide(): RouteAuthorizationConfigTransfer`. Whether `AuthorizationStrategyAwareResourceRoutePluginInterface` (docs example) takes effect in the backend is not confirmed (extractor exists, no vendor example).

**Parent-child (`/parent/{id}/child`):** `ResourceWithParentPluginInterface::getParentResourceType()`; whether `ResourceRouteBuilder` takes it into account is not confirmed `[Assumption]` → check with `router:debug backend` before use, or use `RouteProviderPluginInterface`.

**Own application/convention (docs only, not confirmed against code):** own `entry-point` + `public/<Name>/index.php` with `define('APPLICATION', 'GLUE_CUSTOM')`, bootstrap/plugin/application classes under `Pyz\Glue\CustomApiApplication`; template `src/Pyz/Glue/GlueApplication/Bootstrap/GlueBackendApiBootstrap.php`. Convention: `ConventionPluginInterface` in `GlueApplicationDependencyProvider::getConventionPlugins()` (Pyz: only `JsonApiConventionPlugin`).

## Examples
Complete code: [templates/glue-resource-backend](../templates/glue-resource-backend/README.md). Key parts:

`src/Pyz/Glue/ProductBadgesBackendApi/Plugin/GlueBackendApiApplication/ProductBadgesBackendResource.php`
```php
class ProductBadgesBackendResource extends AbstractResourcePlugin implements ResourceInterface // ...\Backend\AbstractResourcePlugin
{
    public function getType(): string { return ProductBadgesBackendApiConfig::RESOURCE_PRODUCT_BADGES; }

    public function getController(): string { return ProductBadgesResourceController::class; }

    public function getDeclaredMethods(): GlueResourceMethodCollectionTransfer
    {
        return (new GlueResourceMethodCollectionTransfer())
            ->setGetCollection(
                (new GlueResourceMethodConfigurationTransfer())
                    ->setAction('getCollectionAction')
                    ->setAttributes(ProductBadgesBackendApiAttributesTransfer::class)
                    ->setIsSingularResponse(false),
            )
            ->setPost(
                (new GlueResourceMethodConfigurationTransfer())
                    ->setAction('postAction')
                    ->setAttributes(ProductBadgesBackendApiAttributesTransfer::class)
                    ->setIsSingularResponse(true),
            );
    }
}
```

Controller (`Spryker\Glue\Kernel\Backend\Controller\AbstractBackendApiController`):
```php
public function getCollectionAction(GlueRequestTransfer $glueRequestTransfer): GlueResponseTransfer
{
    return $this->getFactory()->createProductBadgeReader()->getProductBadgeCollection($glueRequestTransfer);
}

public function postAction(ProductBadgesBackendApiAttributesTransfer $attributesTransfer, GlueRequestTransfer $glueRequestTransfer): GlueResponseTransfer
{
    return $this->getFactory()->createProductBadgeCreator()->createProductBadge($attributesTransfer);
}
```

Release pattern of the registration (real, shortened, `src/Pyz/Glue/GlueBackendApiApplication/GlueBackendApiApplicationDependencyProvider.php`):
```php
protected function getResourcePlugins(): array
{
    $plugins = [
        new OauthBackendApiTokenResource(),
        new MultiFactorAuthBackendResourcePlugin(),
        // ... further MultiFactorAuth* resources
        // here: new ProductBadgesBackendResource(),
    ];
    if (class_exists(DynamicFixturesBackendResourcePlugin::class)) {
        $plugins[] = new DynamicFixturesBackendResourcePlugin();
    }

    return $plugins;
}

protected function getRouteProviderPlugins(): array
{
    return [new DynamicEntityRouteProviderPlugin()];
}
```

Protection (`src/Pyz/Shared/GlueBackendApiApplicationAuthorizationConnector/GlueBackendApiApplicationAuthorizationConnectorConfig.php`, add to `getProtectedPaths()`):
```php
'/product-badges' => [
    'isRegularExpression' => false,
],
```

Commands (all executed, `curl /token` with wrong credentials: form body `400 invalid_client`, JSON body `400 unsupported_grant_type`) `[live verified (202610.0)]`:
```bash
docker/sdk cli vendor/bin/console transfer:generate
docker/sdk cli vendor/bin/glue glue-api:controller:cache:warm-up   # without argument: shared cache of all applications; with GLUE_BACKEND_API_APPLICATION only the backend part, which overwrites the storefront part
docker/sdk cli vendor/bin/glue api:router:cache:warm-up <application_name>
docker/sdk cli vendor/bin/glue router:debug backend        # argument: backend | storefront ("GlueBackend" is wrong); docs name "route:debug", code "router:debug"
docker/sdk cli vendor/bin/console oauth:scope-collection-file:generate
docker/sdk cli vendor/bin/console oauth:refresh-token:remove-expired
docker/sdk cli vendor/bin/glue api:generate:documentation
# Token: form body, not JSON (JSON returns "unsupported_grant_type" live)
curl -X POST https://glue-backend.eu.spryker.local/token -H 'Content-Type: application/x-www-form-urlencoded' \
  -d 'grant_type=password&username=<user>&password=<pw>'
curl https://glue-backend.eu.spryker.local/product-badges -H 'Authorization: Bearer <access_token>'
```
The Pyz Glue console registers exactly `ApiGenerateDocumentationConsole`, `ControllerCacheCollectorConsole`, `RouterDebugGlueApplicationConsole`, `RouterCacheWarmUpConsole`; `glue api:generate` (API Platform) does not exist.

## Pitfalls
- **Wrong base class:** `...\Plugin\GlueApplication\AbstractResourcePlugin` without `Backend` in the backend → wrong resolver/Factory. Always `Backend\AbstractResourcePlugin`, `Kernel\Backend\...`.
- **Controller cache:** after a new resource run `glue-api:controller:cache:warm-up` (without argument), otherwise 404. A wrong application name (`GlueBackend`, `GLUE_BACKEND`, `GLUE`) writes an empty controller cache, a correct name overwrites the part of the other application `[live verified (202610.0)]`.
- **Rollback/removal of a resource:** after removal, regenerate the documentation (`docker/sdk cli vendor/bin/glue api:generate:documentation`) and rebuild `glue-api:controller:cache:warm-up` (without argument). The generated specification files under `src/Generated/GlueBackend/Specification/` are excluded via `.gitignore` and do not show the removal in `git status`.
- **Scopes:** new scopes only after `oauth:scope-collection-file:generate`; methods without a scope entry in a `ScopeDefinitionPluginInterface` resource are forbidden (403).
- **Path not protected:** always add new backend resources to `getProtectedPaths()`.
- **Changing the core instead of extending Pyz:** `getProtectedPaths()` via Pyz config inheritance, plugins via the Pyz DependencyProvider; no copies of core controllers/Factories ([03](03-extending-the-core.md), [28](28-upgradability.md)).
- **Storefront pattern in the backend:** no ZedRequest client; get Facades directly via the locator (no bridge), type the Factory getter to the Facade interface.
- **Uncaught business exceptions** (invalid key, unknown product) → HTTP 500 instead of 400/422; catch them in the creator.
- **`/token` with JSON body** → `unsupported_grant_type`; use `application/x-www-form-urlencoded` with `grant_type=password`.
- **JSON:API assumption:** the backend is native JSON (confirmed live with the template `glue-resource-backend`: request body flat `{"fkProductAbstract":7,...}`, response flat JSON or array; a `{"data":{"attributes":...}}` wrapper is not unpacked without a registered JSON API convention); `application/vnd.api+json` only takes effect with `JsonApiResourceInterface`.
- **PUT** is missing in `ResourceRouteBuilder` → `RouteProviderPluginInterface`.
- **API key in the query (`?api_key=`)** ends up in access logs; prefer the header `X-Api-Key`.
- **Dynamic Entity + ApiKey + ProtectedPath:** combination of several strategies in `AuthorizationChecker` (AND/OR) was not checked; test first with a bearer token, API key separately. Fields with `isCreatable/isEditable=false` are read-only; own tables need a Propel schema first ([05](05-propel-persistence.md)).
- **CORS:** backend default `*` (`SPRYKER_GLUE_APPLICATION_CORS_ALLOW_ORIGIN`), restrict in production. Security headers are added by `GlueBackendApiApplicationConfig::getSecurityHeaders()` (`Cache-Control: no-cache, private`).
- **Tests:** `testify-backend-api` (`DynamicFixturesBackendResourcePlugin` behind `class_exists`) → [26](26-tests.md).

**Deprecations:** `OauthBackendApi\Plugin\GlueApplication\OauthBackendTokenResource` `[Deprecated]` → `OauthBackendApiTokenResource` (already switched in the Pyz provider); `OauthBackendApiConfig::REQUEST_ATTRIBUTE_IS_PROTECTED` deprecated (remove in next major); legacy Glue (`*ResourceRoutePlugin`, `*RestApi`, `glue.*`) is the old way. Docs deviations: plan URLs redirect to `integrations/spryker-api/...`; docs authorization examples name `Spryker\Client\AuthorizationExtension`, code `Spryker\Shared\AuthorizationExtension`; `route:debug` ↔ `router:debug`.

## Checklist
- [ ] `Kernel\Backend` base classes (Factory, DependencyProvider, Controller, ResourcePlugin)
- [ ] Facade directly via the locator in `provideBackendDependencies()` (no bridge), getter typed to the Facade interface
- [ ] Controller thin, returns `GlueResponseTransfer`, errors as `GlueErrorTransfer`
- [ ] Plugin registered in `getResourcePlugins()`
- [ ] Path protected in `getProtectedPaths()` (or `_authorization_strategies`)
- [ ] Scopes only deliberately; scope file generated if `ScopeDefinitionPluginInterface` is used
- [ ] Business exceptions caught (400/422)
- [ ] `transfer:generate`, controller cache (`GLUE_BACKEND_API_APPLICATION`), `router:debug backend`, token and resource call tested
- [ ] No API Platform/legacy pattern, no core changes, CORS checked for production

## Sources
Docs (base `https://docs.spryker.com/docs/integrations/spryker-api/`): `getting-started-with-apis/comparison-of-storefront-and-backend-apis`, `backend-api/developing-apis/{create-backend-resources,create-api-applications,create-backend-api-resources-with-parent-child-relationships,create-and-change-backend-api-conventions}`, `authenticating-and-authorization/backend-api/{create-backend-api-authorization-strategies,use-api-key-authorization}`, `api-platform/api-platform`.

Source code: `src/Pyz/Glue/GlueBackendApiApplication/*`, `src/Pyz/Glue/GlueBackendApiApplicationAuthorizationConnector/*`, `src/Pyz/Shared/GlueBackendApiApplicationAuthorizationConnector/GlueBackendApiApplicationAuthorizationConnectorConfig.php`, `src/Pyz/Zed/{Authorization,Oauth,DynamicEntity,GlueBackendApiApplicationAuthorizationConnector}/*`, `src/Pyz/Glue/GlueApplication/Bootstrap/GlueBackendApiBootstrap.php`, `public/GlueBackend/index.php`, `vendor/spryker/glue-backend-api-application/.../{ResourceRouteBuilder,RequestValidator/ScopeRequestAfterRoutingValidator}`, `vendor/spryker/kernel/src/Spryker/Glue/Kernel/Backend/*`, `vendor/spryker/glue-application/.../Plugin/GlueApplication/Backend/AbstractResourcePlugin.php`, `vendor/spryker/oauth-backend-api/*`, `vendor/spryker/glue-backend-api-application-authorization-connector/*`, `vendor/spryker/api-key*/*`, `vendor/spryker/dynamic-entity-backend-api/*`, `vendor/spryker/multi-factor-auth/.../Glue/MultiFactorAuth/*`.

Neighbors: [12 Glue Storefront](12-glue-storefront.md), [21 Security](21-security-session.md), [18 Back Office](18-back-office.md), template [glue-resource-backend](../templates/glue-resource-backend/README.md).
