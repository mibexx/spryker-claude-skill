# Glue Storefront API (REST/JSON:API resources)

> Status: code in `vendor/` and `src/Pyz` (release 202610.0, kernel 3.87.1) read directly and classes/signatures checked. Docs pages could only be checked for URL existence (HTTP 200); their text was not cross-checked `[code-verified, docs not cross-checked]`. All commands were executed in the container `[live verified (202610.0)]`, including the cache warm-ups. Open: cause of the 404 on `glue-storefront.*` (warm-ups do not fix it, see pitfalls), concrete scope format, replacement classes of individual `@deprecated` route plugins.

## Purpose
Create, extend and secure a custom Storefront resource (REST, JSON:API) in the project: route plugin, controller, reader/mapper/ResponseBuilder, relationships, validation, auth, OpenAPI. Backend API → [13](13-glue-backend.md); clients → [08](08-client-storage-search.md); transfers → [04](04-transfer.md).

## Concepts
**Two parallel stacks in 202610.0**

| | A) Classic Glue (`glue.*`) | B) Decoupled Storefront API (`glue-storefront.*`) |
|---|---|---|
| Entry point | `public/Glue/index.php` → `Pyz\Glue\GlueApplication\Bootstrap\GlueBootstrap` | `public/GlueStorefront/index.php` → `GlueStorefrontApiBootstrap` |
| Registration | `ResourceRoutePluginInterface` in `Pyz\Glue\GlueApplication\GlueApplicationDependencyProvider::getResourceRoutePlugins()` | `ResourceInterface` in `Pyz\Glue\GlueStorefrontApiApplication\GlueStorefrontApiApplicationDependencyProvider::getResourcePlugins()` |
| Controller | `AbstractController`, `RestRequestInterface` → `RestResponseInterface` | `AbstractStorefrontApiController`, `GlueRequestTransfer` → `GlueResponseTransfer` |
| Scope | approx. 80 route plugins (products, cart, checkout, customers, orders) | only `token`, `stores`, 5x multi-factor auth |

- **For new resources related to cart/customer/checkout, practically only stack A is fully usable.** Stack B is the target architecture (e.g. `stores-rest-api` → `stores-api`), but hardly migrated. Application selection: `GlueApplicationBootstrapPluginInterface::isServing()`.
- **API Platform** (`spryker/api-platform`) is **not installed** (`vendor/spryker/api-platform` is missing, only `suggest`/`require-dev`). Classes such as `Spryker\ApiPlatform\State\Provider\AbstractStorefrontProvider`, `resources/api/storefront/*.resource.yml` and `Generated\Api\*` are dead in the project and not usable as a pattern. The docs page `dg/dev/architecture/api-platform.html` describes the approach that does not apply here.
- **Layer rule:** Glue Storefront never accesses Zed Facades, only clients (Storage/Search/ZedRequest). Project convention: other modules' clients are fetched directly via the locator in the DependencyProvider, the factory types the getter to the other module's client interface, no bridges (bridges only as a core pattern, see [02](02-project-module.md)).
- Building block roles in stack A: transfer `*RestAttributes` (attributes) → ResourceRoutePlugin (type, methods, controller name) → controller (thin) → factory → reader (client) → mapper (transfer → RestAttributes) → ResponseBuilder (`RestResourceBuilderInterface`, errors).
- `ResourceRouteCollection::addGet|addPost|addPatch|addDelete(string $actionName, bool $isProtected = true, array $context = [])`: **the default is protected**, public only with `false`.
- Controller name in the plugin: kebab-case without suffix (`'stores-resource'` → `StoresResourceController`); action `<method>Action`.
- Error codes: general `002` Missing access token (403), `003` auth failed (401), `007` resource not found, `008` method does not exist, `901` request invalid (422); module-specific in `<Module>Config::RESPONSE_CODE_*`. ETag: `005`/`006`.

## Guide
1. **Transfer** `*RestAttributes` in `src/Pyz/Shared/<Module>/Transfer/*.transfer.xml` ([04](04-transfer.md)); `description` on properties flows into OpenAPI.
2. **Config** with resource constant and error codes (`public const`, a unique number range per module).
3. **ResourceRoutePlugin** `Plugin/GlueApplication/<Name>ResourceRoutePlugin extends AbstractPlugin implements ResourceRoutePluginInterface`: `configure()`, `getResourceType()`, `getController()`, `getResourceAttributesClassName()`.
4. **Controller** `extends Spryker\Glue\Kernel\Controller\AbstractController`: `getAction(RestRequestInterface): RestResponseInterface`, only delegation to the reader. The `@Glue({...})` docblock provides Swagger metadata.
5. **Factory** (`Spryker\Glue\Kernel\AbstractFactory`) builds reader/mapper/ResponseBuilder; `getResourceBuilder()` returns `RestResourceBuilderInterface`.
6. **DependencyProvider** (`Spryker\Glue\Kernel\AbstractBundleDependencyProvider`): clients directly via the locator (`$container->getLocator()->xyz()->client()`), without a bridge; type the factory getters to `Pyz\Client\Xyz\XyzClientInterface` or the core client's interface.
7. **Register** in `Pyz\Glue\GlueApplication\GlueApplicationDependencyProvider::getResourceRoutePlugins()`.
8. **Caches/docs** (see commands). Complete template: [Template glue-resource-storefront](../templates/glue-resource-storefront/README.md).

**Building responses:** `createRestResponse(int $totalItems = 0, int $limit = 0)` and `createRestResource(string $type, ?string $id, ?AbstractTransfer $attributeTransfer)`; `RestResponse::addResource()`, `addError(RestErrorMessageTransfer)` with `code`, `status`, `detail`. No exceptions for domain errors (the `ControllerFilter` turns them into 500). Pagination: `createRestResponse($total, $limit)` and `RestRequestInterface::getPage()`.

**Relationships (`?include=...`):** plugin `ResourceRelationshipPluginInterface` with `addResourceRelationships(array $resources, RestRequestInterface $restRequest): void` and `getRelationshipResourceType(): string`; registration only in `GlueApplicationDependencyProvider::getResourceRelationshipPlugins(ResourceRelationshipCollectionInterface $collection)` via `$collection->addRelationship(<parent type>, <plugin>)`. The reader attaches `$resource->addRelationship($restResource)`. In the project `isEagerRelationshipsLoadingEnabled() = false`; loading happens only with `include`. Parent resources (`/customers/{id}/addresses`): `ResourceWithParentPluginInterface::getParentResourceType()`, access `$restRequest->findParentResourceByType('customers')`.

**Validation (POST/PATCH):** module `rest-request-validator`, plugin `ValidateRestRequestAttributesPlugin` is registered in the project. Rule file `src/Pyz/Glue/<Module>/Validation/<name>.validation.yaml` (extension exactly `.validation.yaml`), structure resource → method → attribute → constraints; error `901`/422. **The validation cache must be built.**

**Versioning:** `ResourceVersionableInterface::getVersion(): RestVersionTransfer`; resolution from Content-Type/Accept (`version=1.2`) or URL path (`GlueApplicationConfig::getPathVersionResolving()`). In the release no installed route plugin uses versioning `[Assumption]`: for incompatible changes, prefer a new resource.

**ETag/If-Match:** `entity-tags-rest-api`; resources requiring an ETag in `EntityTagsRestApiConfig::getEntityTagRequiredResources()` (Pyz adds `RESOURCE_CARTS`).

**Authentication stack A:** `POST /access-tokens` (`AccessTokensResourceRoutePlugin`, body `data.type=access-tokens`, `attributes.username/password`), refresh via `refresh-tokens`. Protected routes are checked by `AccessTokenRestRequestValidatorPlugin` (namespace `...\Plugin\GlueApplication\`, not the deprecated variant in `...\Plugin\`) via the request attribute `is-protected`; missing `Authorization` → `002`/403. Anonymous carts: header `X-Anonymous-Customer-Unique-Id`. Brute-force lockout → [21](21-security-session.md), Customer Access → [20](20-b2b-permissions.md).

**Stack B (short version):** resource plugin `extends AbstractResourcePlugin implements JsonApiResourceInterface` with `getType()`, `getController()` (FQCN), `getDeclaredMethods(): GlueResourceMethodCollectionTransfer`; controller `getAction(string $id, GlueRequestTransfer)` or `getCollectionAction(GlueRequestTransfer)`. Token: `POST /token` (`OauthApiTokenResource`, snake_case attributes). Scopes via `ScopeDefinitionPluginInterface::getScopes(): array` (method → scope); plugins without this interface are exempt from the scope check, missing scope → 403 "Forbidden.". The example format `['get' => 'stores:read']` is an `[Assumption]`.

**OpenAPI:** stack A via the Zed console `docker/sdk console rest-api:generate:documentation` (reads `@Glue` docblocks, `*RestAttributes`; not legacy) `[live verified (202610.0)]` (exit 0); stack B: `vendor/bin/glue api:generate:documentation` generates `src/Generated/GlueStorefront/Specification/spryker_storefront_api.schema.yml` `[live verified (202610.0)]` (exit 0).

## Examples
Complete code (route plugin, controller, factory, DependencyProvider, reader, mapper, ResponseBuilder, transfer) is in [templates/glue-resource-storefront](../templates/glue-resource-storefront/README.md). Core pieces:

`src/Pyz/Glue/ProductBadgesRestApi/Plugin/GlueApplication/ProductBadgesResourceRoutePlugin.php`
```php
class ProductBadgesResourceRoutePlugin extends AbstractPlugin implements ResourceRoutePluginInterface
{
    public function configure(ResourceRouteCollectionInterface $resourceRouteCollection): ResourceRouteCollectionInterface
    {
        $resourceRouteCollection->addGet('get', false); // false = not token-protected

        return $resourceRouteCollection;
    }

    public function getResourceType(): string { return ProductBadgesRestApiConfig::RESOURCE_PRODUCT_BADGES; }

    public function getController(): string { return 'product-badges-resource'; } // ProductBadgesResourceController

    public function getResourceAttributesClassName(): string { return RestProductBadgesAttributesTransfer::class; }
}
```

Controller action (`.../Controller/ProductBadgesResourceController.php`, base `Spryker\Glue\Kernel\Controller\AbstractController`):
```php
public function getAction(RestRequestInterface $restRequest): RestResponseInterface
{
    return $this->getFactory()->createProductBadgeReader()->getProductBadges($restRequest);
}
```

Error response in the ResponseBuilder (pattern from `UrlsRestApi`):
```php
return $this->restResourceBuilder->createRestResponse()->addError(
    (new RestErrorMessageTransfer())
        ->setStatus(Response::HTTP_NOT_FOUND)
        ->setCode(ProductBadgesRestApiConfig::RESPONSE_CODE_PRODUCT_BADGE_NOT_FOUND)
        ->setDetail(ProductBadgesRestApiConfig::RESPONSE_DETAIL_PRODUCT_BADGE_NOT_FOUND),
);
```

Registration (`src/Pyz/Glue/GlueApplication/GlueApplicationDependencyProvider.php`, `getResourceRoutePlugins()`): `new ProductBadgesResourceRoutePlugin(),`.

Relationship (real release example `vendor/spryker/customers-rest-api/.../Plugin/CustomersToAddressesRelationshipPlugin.php`, registration in `getResourceRelationshipPlugins()`):
```php
$resourceRelationshipCollection->addRelationship(
    CustomersRestApiConfig::RESOURCE_CUSTOMERS,
    new CustomersToAddressesRelationshipPlugin(),
);
```
Request `GET /customers/{customerReference}?include=addresses` with `Authorization: Bearer <token>`.

Validation (real release example `vendor/spryker/customers-rest-api/.../Validation/customers.validation.yaml`, excerpt):
```yaml
customers:
    post:
        email:
            - NotBlank
            - Email
        password:
            - NotBlank
            - Length:
                  min: 12
                  max: 128
    patch:
        email:
            - Optional:
                  constraints:
                      - NotBlank
                      - Email
```

Extend an existing resource instead of changing the core (`src/Pyz/Glue/ProductsRestApi/ProductsRestApiDependencyProvider.php`): add an expander plugin in `getConcreteProductsResourceExpanderPlugins()` (implements `ConcreteProductsResourceExpanderPluginInterface`); additional attributes via transfer merge ([04](04-transfer.md)). Extension modules: `*-rest-api-extension`.

Commands (all executed) `[live verified (202610.0)]`:
```bash
docker/sdk cli vendor/bin/console transfer:generate
# Controller cache for stack B and Backend (stack A is not included). Call WITHOUT an argument (like config/install/docker.yml):
docker/sdk cli vendor/bin/glue glue-api:controller:cache:warm-up
# With an application name the command writes the same file with only that application's share (Storefront 4675 bytes, Backend 8909 bytes, both together 13578 bytes); a second run overwrites the first, a wrong name results in an empty cache (6 bytes)
docker/sdk cli vendor/bin/glue api:router:cache:warm-up [application_name]
docker/sdk cli vendor/bin/glue router:debug storefront     # stack B; argument: storefront | backend; there is no router:debug for stack A
docker/sdk cli vendor/bin/console rest-api:build-request-validation-cache   # counterpart: rest-api:remove-validation-cache
docker/sdk cli vendor/bin/console rest-api:generate:documentation           # OpenAPI stack A
```
Smoke tests (live against `glue.eu.spryker.local`): `GET /stores` → 200 JSON:API; `GET /customers` without token → `403`, code `002`; `POST /access-tokens` with wrong password → `401`/`003`.

## Pitfalls
- **`glue-storefront.*` returns 404** (live `[{"code":"007","status":404,"message":"Not found"}]`), although `token`, `stores`, MFA are registered (the schema file lists them). Observed: the route cache `src/Generated/Glue/Router/codeBucketEU/Pyz/url_matching_routes.php` contains 0 routes, `config/install/development.yml` only calls `glue-api:controller:cache:warm-up`. The hypothesis "warm-up missing" is **disproved** `[live verified (202610.0)]`: after `glue-api:controller:cache:warm-up` (with and without application name) and `api:router:cache:warm-up [<app>]`, `GET /stores` and `GET /multi-factor-auth-types` on `glue-storefront.*` still return `404/007`, `GET /token` `404/008` (route exists, method does not), `POST /token` `400 unsupported_grant_type`. The cause remains open. `glue.*` serves `/stores` live.
- **Controller cache forgotten (stack B/Backend only)** → `404`/`007` or `008`. After a new/changed resource there, run `glue-api:controller:cache:warm-up`. Call it without an argument: an application name (`GLUE_STOREFRONT_API_APPLICATION`/`GLUE_BACKEND_API_APPLICATION`) overwrites the shared cache with only its share, a wrong name (e.g. `GLUE`, `GlueBackend`) writes an empty cache (exit is still 0). The classic stack A (`glue.*`) does not read a controller cache (`getControllerConfigurationCacheCollectorPlugins()` only names Storefront and Backend); an unknown route there returns live `{"errors":[{"status":404,"detail":"Not Found"}]}`, not code `007`/`008`.
- **Rollback/removal of a resource (stack A):** After removing resource files and the plugin registration, run `docker/sdk cli console rest-api:generate:documentation` again. The generated file `src/Generated/Glue/Specification/spryker_rest_api.schema.yml` is excluded via `.gitignore`, does not show up in `git status` and otherwise still contains the removed paths. Also rebuild `rest-api:build-request-validation-cache`. Check: `grep -ci <resource> src/Generated/Glue/Specification/spryker_rest_api.schema.yml` returns 0 `[live verified (202610.0)]`.
- **Validation cache not rebuilt** or file named `.yml` instead of `.validation.yaml` → rules have no effect. `.validation.yml`/`.resource.yml` in `resources/api/storefront/` belong to API Platform.
- **`isProtected` forgotten:** the default is `true`; public endpoints explicitly `false`, personal data never.
- **Wrong controller name:** kebab-case without `Controller` (`'product-badges-resource'`).
- Business logic in the controller/plugin; direct Facade/Zed access from Glue Storefront (forbidden, client only). Fetch clients via the locator without a bridge.
- Relationship not registered in `getResourceRelationshipPlugins()` → `include` has no effect.
- Changing core files breaks the upgrader → expander/extension plugins or Pyz inheritance ([03](03-extending-the-core.md), [28](28-upgradability.md)).
- Errors only via `addError(RestErrorMessageTransfer)` with `code`+`status`+`detail`; error codes unique per module.
- Read prices/availability from Storage/Search clients, not from Zed ([25](25-performance-caching.md)).
- The docs tutorials (`implement-a-rest-api-resource`) describe stack A and match the code; whether they carry the note about the replacement was not compared `[Assumption]`.

**Deprecations**
- `Spryker\Glue\StoresRestApi\Plugin\StoresResourceRoutePlugin` `[Deprecated]` → `Spryker\Glue\StoresApi\Plugin\GlueStorefrontApiApplication\StoresResource`; still registered in the project nonetheless, `/stores` is live reachable only via `glue.*`.
- `CustomersResourceRoutePlugin`, `AddressesResourceRoutePlugin`, `CustomerOrdersResourceRoutePlugin` directly under `Plugin/`: not the classes, but only one method each is `@deprecated` (`setStrategy()`, "Will be removed without replacement").
- `AuthRestApi\Plugin\AccessTokenRestRequestValidatorPlugin` `[Deprecated]` (without replacement) → `...\Plugin\GlueApplication\AccessTokenRestRequestValidatorPlugin`.
- `RestRequestValidatorConfig::getValidationCacheFilenamePattern()` `[Deprecated]` → `getValidationCodeBucketCacheFilenamePattern()`.
- Duplicate console names: `rest-api:build-request-validation-cache` (`BuildRestApiValidationCacheConsole`, registered in the project, in install recipes) is the current command; `glue:rest:build-request-validation-cache` (`BuildValidationCacheConsole`) is `@deprecated` and not registered in the project.

## Checklist
- [ ] Stack chosen (A for commerce resources; B only after checking, API Platform excluded)
- [ ] `*RestAttributes` transfer + `transfer:generate`
- [ ] Route plugin: `isProtected` set deliberately, `getController()` kebab-case
- [ ] Controller thin, factory/reader/mapper/ResponseBuilder, clients directly via the locator (no bridge)
- [ ] Plugin registered in `GlueApplicationDependencyProvider::getResourceRoutePlugins()`
- [ ] POST/PATCH: `*.validation.yaml` + validation cache built
- [ ] Relationships/ETag/scopes only if needed and registered
- [ ] Stack B/Backend: `glue-api:controller:cache:warm-up` without argument (and router cache), stack A: no controller cache; smoke test via curl
- [ ] `@Glue` docblock, OpenAPI regenerated
- [ ] No deprecated plugins newly used, no core changes

## Sources
Docs (prefix `https://docs.spryker.com/docs/`, only URL reachability checked): `dg/dev/glue-api/latest/glue-api.html`, `.../rest-api/glue-rest-api.html`, `.../rest-api/glue-infrastructure.html`, `.../glue-api-tutorials/implement-a-rest-api-resource.html`, `.../glue-api-tutorials/extend-a-rest-api-resource.html`, `.../create-json-api-relationships.html`, `.../glue-api-tutorials/validate-rest-request-format.html`, `.../rest-api/glue-api-authentication-and-authorization.html`, `.../glue-api-tutorials/document-glue-api-resources.html`, `dg/dev/architecture/api-platform.html` (redirects to `integrations/spryker-api/...` see [Glue Backend](13-glue-backend.md)).

Source code: `src/Pyz/Glue/GlueApplication/{GlueApplicationDependencyProvider,GlueApplicationConfig}.php`, `src/Pyz/Glue/GlueStorefrontApiApplication/*`, `src/Pyz/Glue/{AuthRestApi,OauthApi,EntityTagsRestApi,ProductsRestApi,Console}/*`, `public/{Glue,GlueStorefront}/index.php`, `config/install/development.yml`, `vendor/spryker/glue-application/src/Spryker/Glue/GlueApplication/{GlueApplicationConfig.php,Rest/*,Plugin/Console/*}`, `vendor/spryker/glue-application-extension/.../Dependency/Plugin/*`, `vendor/spryker/{stores-rest-api,stores-api,urls-rest-api,customers-rest-api,auth-rest-api,oauth-api,rest-request-validator,entity-tags-rest-api}`, `vendor/spryker/glue-storefront-api-application/.../RequestValidator/ScopeRequestAfterRoutingValidator.php`.

Neighbors: [13 Glue Backend](13-glue-backend.md), [08 Clients](08-client-storage-search.md), [04 Transfer](04-transfer.md), [21 Security](21-security-session.md), template [glue-resource-storefront](../templates/glue-resource-storefront/README.md).
