# Glue Storefront API (REST/JSON:API-Ressourcen) (Recherche T-12)

> Status: Code in `vendor/` und `src/Pyz` (Release 202610.0, kernel 3.87.1) direkt gelesen und Klassen/Signaturen geprüft. Doku-Seiten waren nur per URL-Existenz (HTTP 200) prüfbar, ihr Text wurde nicht abgeglichen `[code-verifiziert, Doku nicht abgeglichen]`. Alle Befehle wurden im Container ausgeführt `[live verifiziert (202610.0)]`, auch die Cache-Warm-Ups. Offen: Ursache des 404 auf `glue-storefront.*` (Warm-Ups beheben ihn nicht, siehe Fallstricke), konkretes Scope-Format, Ersatzklassen einzelner `@deprecated` Route-Plugins.

## Zweck
Eine kundenspezifische Storefront-Ressource (REST, JSON:API) im Projekt anlegen, erweitern und absichern: Route-Plugin, Controller, Reader/Mapper/ResponseBuilder, Relationships, Validierung, Auth, OpenAPI. Backend-API → [13](13-glue-backend.md); Clients → [08](08-client-storage-search.md); Transfers → [04](04-transfer.md).

## Konzepte
**Zwei parallele Stacks in 202610.0**

| | A) Klassisches Glue (`glue.*`) | B) Decoupled Storefront API (`glue-storefront.*`) |
|---|---|---|
| Einstieg | `public/Glue/index.php` → `Pyz\Glue\GlueApplication\Bootstrap\GlueBootstrap` | `public/GlueStorefront/index.php` → `GlueStorefrontApiBootstrap` |
| Registrierung | `ResourceRoutePluginInterface` in `Pyz\Glue\GlueApplication\GlueApplicationDependencyProvider::getResourceRoutePlugins()` | `ResourceInterface` in `Pyz\Glue\GlueStorefrontApiApplication\GlueStorefrontApiApplicationDependencyProvider::getResourcePlugins()` |
| Controller | `AbstractController`, `RestRequestInterface` → `RestResponseInterface` | `AbstractStorefrontApiController`, `GlueRequestTransfer` → `GlueResponseTransfer` |
| Umfang | ca. 80 Route-Plugins (Produkte, Warenkorb, Checkout, Kunden, Bestellungen) | nur `token`, `stores`, 5x Multi-Factor-Auth |

- **Für neue Ressourcen mit Warenkorb-/Kunden-/Checkout-Bezug ist praktisch nur Stack A vollständig nutzbar.** Stack B ist die Zielarchitektur (z. B. `stores-rest-api` → `stores-api`), aber kaum migriert. Auswahl der Applikation: `GlueApplicationBootstrapPluginInterface::isServing()`.
- **API Platform** (`spryker/api-platform`) ist **nicht installiert** (`vendor/spryker/api-platform` fehlt, nur `suggest`/`require-dev`). Klassen wie `Spryker\ApiPlatform\State\Provider\AbstractStorefrontProvider`, `resources/api/storefront/*.resource.yml` und `Generated\Api\*` sind im Projekt tot und nicht als Muster verwendbar. Die Doku-Seite `dg/dev/architecture/api-platform.html` beschreibt den Weg, der hier nicht greift.
- **Schichtregel:** Glue Storefront greift nie auf Zed-Facades zu, nur auf Clients (Storage/Search/ZedRequest). Projekt-Konvention: Fremde Clients werden im DependencyProvider direkt über den Locator geholt, die Factory typisiert den Getter auf das Client-Interface des Fremdmoduls, keine Bridges (Bridges nur als Core-Muster, siehe [02](02-projektmodul.md)).
- Baustein-Rollen Stack A: Transfer `*RestAttributes` (Attribute) → ResourceRoutePlugin (Typ, Methoden, Controller-Name) → Controller (dünn) → Factory → Reader (Client) → Mapper (Transfer → RestAttributes) → ResponseBuilder (`RestResourceBuilderInterface`, Fehler).
- `ResourceRouteCollection::addGet|addPost|addPatch|addDelete(string $actionName, bool $isProtected = true, array $context = [])`: **Default ist geschützt**, öffentlich nur mit `false`.
- Controller-Name im Plugin: kebab-case ohne Suffix (`'stores-resource'` → `StoresResourceController`); Action `<methode>Action`.
- Fehlercodes: allgemein `002` Missing access token (403), `003` Auth fehlgeschlagen (401), `007` Resource nicht gefunden, `008` Methode existiert nicht, `901` Request invalid (422); modulspezifisch in `<Modul>Config::RESPONSE_CODE_*`. ETag: `005`/`006`.

## Anleitung
1. **Transfer** `*RestAttributes` in `src/Pyz/Shared/<Modul>/Transfer/*.transfer.xml` ([04](04-transfer.md)); `description` an Properties fließt in OpenAPI.
2. **Config** mit Ressourcen-Konstante und Fehlercodes (`public const`, pro Modul eindeutiger Nummernkreis).
3. **ResourceRoutePlugin** `Plugin/GlueApplication/<Name>ResourceRoutePlugin extends AbstractPlugin implements ResourceRoutePluginInterface`: `configure()`, `getResourceType()`, `getController()`, `getResourceAttributesClassName()`.
4. **Controller** `extends Spryker\Glue\Kernel\Controller\AbstractController`: `getAction(RestRequestInterface): RestResponseInterface`, nur Delegation an Reader. `@Glue({...})`-Docblock liefert Swagger-Metadaten.
5. **Factory** (`Spryker\Glue\Kernel\AbstractFactory`) baut Reader/Mapper/ResponseBuilder; `getResourceBuilder()` liefert `RestResourceBuilderInterface`.
6. **DependencyProvider** (`Spryker\Glue\Kernel\AbstractBundleDependencyProvider`): Clients direkt über den Locator (`$container->getLocator()->xyz()->client()`), ohne Bridge; Factory-Getter auf `Pyz\Client\Xyz\XyzClientInterface` bzw. das Interface des Core-Clients typisieren.
7. **Registrieren** in `Pyz\Glue\GlueApplication\GlueApplicationDependencyProvider::getResourceRoutePlugins()`.
8. **Caches/Doku** (siehe Befehle). Vollständige Vorlage: [Template glue-resource-storefront](../templates/glue-resource-storefront/README.md).

**Antworten bauen:** `createRestResponse(int $totalItems = 0, int $limit = 0)` und `createRestResource(string $type, ?string $id, ?AbstractTransfer $attributeTransfer)`; `RestResponse::addResource()`, `addError(RestErrorMessageTransfer)` mit `code`, `status`, `detail`. Keine Exceptions für Fachfehler (der `ControllerFilter` macht daraus 500). Pagination: `createRestResponse($total, $limit)` und `RestRequestInterface::getPage()`.

**Relationships (`?include=...`):** Plugin `ResourceRelationshipPluginInterface` mit `addResourceRelationships(array $resources, RestRequestInterface $restRequest): void` und `getRelationshipResourceType(): string`; Registrierung nur in `GlueApplicationDependencyProvider::getResourceRelationshipPlugins(ResourceRelationshipCollectionInterface $collection)` via `$collection->addRelationship(<Eltern-Typ>, <Plugin>)`. Reader hängt `$resource->addRelationship($restResource)` an. Im Projekt `isEagerRelationshipsLoadingEnabled() = false`, geladen wird nur bei `include`. Eltern-Ressourcen (`/customers/{id}/addresses`): `ResourceWithParentPluginInterface::getParentResourceType()`, Zugriff `$restRequest->findParentResourceByType('customers')`.

**Validierung (POST/PATCH):** Modul `rest-request-validator`, Plugin `ValidateRestRequestAttributesPlugin` ist im Projekt registriert. Regeldatei `src/Pyz/Glue/<Modul>/Validation/<name>.validation.yaml` (Endung exakt `.validation.yaml`), Struktur Ressource → Methode → Attribut → Constraints; Fehler `901`/422. **Validierungs-Cache muss gebaut werden.**

**Versionierung:** `ResourceVersionableInterface::getVersion(): RestVersionTransfer`; Auflösung aus Content-Type/Accept (`version=1.2`) oder URL-Pfad (`GlueApplicationConfig::getPathVersionResolving()`). Im Release nutzt kein installiertes Route-Plugin Versionierung `[Annahme]`: für inkompatible Änderungen lieber neue Ressource.

**ETag/If-Match:** `entity-tags-rest-api`; ETag-pflichtige Ressourcen in `EntityTagsRestApiConfig::getEntityTagRequiredResources()` (Pyz ergänzt `RESOURCE_CARTS`).

**Authentifizierung Stack A:** `POST /access-tokens` (`AccessTokensResourceRoutePlugin`, Body `data.type=access-tokens`, `attributes.username/password`), Refresh über `refresh-tokens`. Geschützte Routen prüft `AccessTokenRestRequestValidatorPlugin` (Namespace `...\Plugin\GlueApplication\`, nicht die deprecated Variante in `...\Plugin\`) über Request-Attribut `is-protected`; fehlt `Authorization` → `002`/403. Anonyme Warenkörbe: Header `X-Anonymous-Customer-Unique-Id`. Brute-Force-Sperre → [21](21-security-session.md), Customer Access → [20](20-b2b-permissions.md).

**Stack B (Kurzfassung):** Ressourcen-Plugin `extends AbstractResourcePlugin implements JsonApiResourceInterface` mit `getType()`, `getController()` (FQCN), `getDeclaredMethods(): GlueResourceMethodCollectionTransfer`; Controller `getAction(string $id, GlueRequestTransfer)` bzw. `getCollectionAction(GlueRequestTransfer)`. Token: `POST /token` (`OauthApiTokenResource`, snake_case Attribute). Scopes über `ScopeDefinitionPluginInterface::getScopes(): array` (Methode → Scope); Plugins ohne dieses Interface sind vom Scope-Check ausgenommen, fehlender Scope → 403 „Forbidden.“. Beispiel-Format `['get' => 'stores:read']` ist `[Annahme]`.

**OpenAPI:** Stack A über die Zed-Konsole `docker/sdk console rest-api:generate:documentation` (liest `@Glue`-Docblocks, `*RestAttributes`; kein Legacy) `[live verifiziert (202610.0)]` (Exit 0); Stack B: `vendor/bin/glue api:generate:documentation` erzeugt `src/Generated/GlueStorefront/Specification/spryker_storefront_api.schema.yml` `[live verifiziert (202610.0)]` (Exit 0).

## Beispiele
Vollständiger Code (Route-Plugin, Controller, Factory, DependencyProvider, Reader, Mapper, ResponseBuilder, Transfer) liegt in [templates/glue-resource-storefront](../templates/glue-resource-storefront/README.md). Kernstücke:

`src/Pyz/Glue/ProductBadgesRestApi/Plugin/GlueApplication/ProductBadgesResourceRoutePlugin.php`
```php
class ProductBadgesResourceRoutePlugin extends AbstractPlugin implements ResourceRoutePluginInterface
{
    public function configure(ResourceRouteCollectionInterface $resourceRouteCollection): ResourceRouteCollectionInterface
    {
        $resourceRouteCollection->addGet('get', false); // false = nicht token-geschützt

        return $resourceRouteCollection;
    }

    public function getResourceType(): string { return ProductBadgesRestApiConfig::RESOURCE_PRODUCT_BADGES; }

    public function getController(): string { return 'product-badges-resource'; } // ProductBadgesResourceController

    public function getResourceAttributesClassName(): string { return RestProductBadgesAttributesTransfer::class; }
}
```

Controller-Action (`.../Controller/ProductBadgesResourceController.php`, Basis `Spryker\Glue\Kernel\Controller\AbstractController`):
```php
public function getAction(RestRequestInterface $restRequest): RestResponseInterface
{
    return $this->getFactory()->createProductBadgeReader()->getProductBadges($restRequest);
}
```

Fehlerantwort im ResponseBuilder (Muster aus `UrlsRestApi`):
```php
return $this->restResourceBuilder->createRestResponse()->addError(
    (new RestErrorMessageTransfer())
        ->setStatus(Response::HTTP_NOT_FOUND)
        ->setCode(ProductBadgesRestApiConfig::RESPONSE_CODE_PRODUCT_BADGE_NOT_FOUND)
        ->setDetail(ProductBadgesRestApiConfig::RESPONSE_DETAIL_PRODUCT_BADGE_NOT_FOUND),
);
```

Registrierung (`src/Pyz/Glue/GlueApplication/GlueApplicationDependencyProvider.php`, `getResourceRoutePlugins()`): `new ProductBadgesResourceRoutePlugin(),`.

Relationship (echtes Release-Beispiel `vendor/spryker/customers-rest-api/.../Plugin/CustomersToAddressesRelationshipPlugin.php`, Registrierung in `getResourceRelationshipPlugins()`):
```php
$resourceRelationshipCollection->addRelationship(
    CustomersRestApiConfig::RESOURCE_CUSTOMERS,
    new CustomersToAddressesRelationshipPlugin(),
);
```
Abruf `GET /customers/{customerReference}?include=addresses` mit `Authorization: Bearer <token>`.

Validierung (echtes Release-Beispiel `vendor/spryker/customers-rest-api/.../Validation/customers.validation.yaml`, Auszug):
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

Bestehende Ressource erweitern statt Core ändern (`src/Pyz/Glue/ProductsRestApi/ProductsRestApiDependencyProvider.php`): Expander-Plugin in `getConcreteProductsResourceExpanderPlugins()` ergänzen (implementiert `ConcreteProductsResourceExpanderPluginInterface`); weitere Attribute per Transfer-Merge ([04](04-transfer.md)). Extension-Module: `*-rest-api-extension`.

Befehle (alle ausgeführt) `[live verifiziert (202610.0)]`:
```bash
docker/sdk cli vendor/bin/console transfer:generate
# Controller-Cache für Stack B und Backend (Stack A ist nicht enthalten). OHNE Argument aufrufen (wie config/install/docker.yml):
docker/sdk cli vendor/bin/glue glue-api:controller:cache:warm-up
# Mit Applikationsname schreibt der Befehl dieselbe Datei nur mit dem Anteil dieser Applikation (Storefront 4675 Byte, Backend 8909 Byte, beide zusammen 13578 Byte); ein zweiter Lauf überschreibt den ersten, ein falscher Name ergibt einen leeren Cache (6 Byte)
docker/sdk cli vendor/bin/glue api:router:cache:warm-up [application_name]
docker/sdk cli vendor/bin/glue router:debug storefront     # Stack B; Argument: storefront | backend; für Stack A gibt es kein router:debug
docker/sdk cli vendor/bin/console rest-api:build-request-validation-cache   # Gegenstück: rest-api:remove-validation-cache
docker/sdk cli vendor/bin/console rest-api:generate:documentation           # OpenAPI Stack A
```
Smoke-Tests (live in der Recherche gegen `glue.eu.spryker.local`): `GET /stores` → 200 JSON:API; `GET /customers` ohne Token → `403`, Code `002`; `POST /access-tokens` mit falschem Passwort → `401`/`003`.

## Fallstricke
- **`glue-storefront.*` liefert 404** (live `[{"code":"007","status":404,"message":"Not found"}]`), obwohl `token`, `stores`, MFA registriert sind (Schema-Datei listet sie). Beobachtet: Route-Cache `src/Generated/Glue/Router/codeBucketEU/Pyz/url_matching_routes.php` enthält 0 Routen, `config/install/development.yml` ruft nur `glue-api:controller:cache:warm-up` auf. Die Hypothese „Warm-Up fehlt“ ist `[live verifiziert (202610.0)]` **widerlegt**: nach `glue-api:controller:cache:warm-up` (mit und ohne Applikationsname) und `api:router:cache:warm-up [<app>]` liefert `GET /stores` und `GET /multi-factor-auth-types` auf `glue-storefront.*` weiter `404/007`, `GET /token` `404/008` (Route existiert, Methode nicht), `POST /token` `400 unsupported_grant_type`. Die Ursache bleibt offen. `glue.*` liefert `/stores` live.
- **Controller-Cache vergessen (nur Stack B/Backend)** → `404`/`007` oder `008`. Nach neuer/geänderter Ressource dort `glue-api:controller:cache:warm-up`. Ohne Argument aufrufen: ein Applikationsname (`GLUE_STOREFRONT_API_APPLICATION`/`GLUE_BACKEND_API_APPLICATION`) überschreibt den gemeinsamen Cache nur mit seinem Anteil, ein falscher Name (z. B. `GLUE`, `GlueBackend`) schreibt einen leeren Cache (Exit trotzdem 0). Der klassische Stack A (`glue.*`) liest keinen Controller-Cache (`getControllerConfigurationCacheCollectorPlugins()` nennt nur Storefront und Backend); eine unbekannte Route liefert dort live `{"errors":[{"status":404,"detail":"Not Found"}]}`, nicht Code `007`/`008`.
- **Rückbau einer Ressource (Stack A):** Nach dem Entfernen von Ressource-Dateien und Plugin-Registrierung `docker/sdk cli console rest-api:generate:documentation` erneut ausführen. Die generierte Datei `src/Generated/Glue/Specification/spryker_rest_api.schema.yml` ist per `.gitignore` ausgeschlossen, taucht in `git status` nicht auf und enthält sonst weiter die entfernten Pfade. Zusätzlich `rest-api:build-request-validation-cache` neu bauen. Kontrolle: `grep -ci <ressource> src/Generated/Glue/Specification/spryker_rest_api.schema.yml` liefert 0 `[live verifiziert (202610.0)]`.
- **Validierungs-Cache nicht neu gebaut** oder Datei auf `.yml` statt `.validation.yaml` → Regeln wirken nicht. `.validation.yml`/`.resource.yml` in `resources/api/storefront/` gehören zu API Platform.
- **`isProtected` vergessen:** Default ist `true`; öffentliche Endpunkte explizit `false`, personenbezogene Daten nie.
- **Controller-Name falsch:** kebab-case ohne `Controller` (`'product-badges-resource'`).
- Fachlogik im Controller/Plugin; direkter Facade-/Zed-Zugriff aus Glue Storefront (verboten, nur Client). Clients ohne Bridge über den Locator holen.
- Relationship nicht in `getResourceRelationshipPlugins()` registriert → `include` wirkungslos.
- Core-Dateien ändern bricht den Upgrader → Expander-/Extension-Plugins oder Pyz-Vererbung ([03](03-core-erweitern.md), [28](28-upgradability.md)).
- Fehler nur über `addError(RestErrorMessageTransfer)` mit `code`+`status`+`detail`; Fehlercodes pro Modul eindeutig.
- Preise/Verfügbarkeit aus Storage/Search-Clients lesen, nicht aus Zed ([25](25-performance-caching.md)).
- Doku-Tutorials (`implement-a-rest-api-resource`) beschreiben Stack A und passen zum Code; ob sie den Hinweis auf die Ablösung tragen, wurde nicht verglichen `[Annahme]`.

**Deprecations**
- `Spryker\Glue\StoresRestApi\Plugin\StoresResourceRoutePlugin` `[Deprecated]` → `Spryker\Glue\StoresApi\Plugin\GlueStorefrontApiApplication\StoresResource`; im Projekt trotzdem noch registriert, `/stores` ist live nur über `glue.*` erreichbar.
- `CustomersResourceRoutePlugin`, `AddressesResourceRoutePlugin`, `CustomerOrdersResourceRoutePlugin` direkt unter `Plugin/`: nicht die Klassen, sondern je nur eine Methode ist `@deprecated` (`setStrategy()`, „Will be removed without replacement“).
- `AuthRestApi\Plugin\AccessTokenRestRequestValidatorPlugin` `[Deprecated]` (without replacement) → `...\Plugin\GlueApplication\AccessTokenRestRequestValidatorPlugin`.
- `RestRequestValidatorConfig::getValidationCacheFilenamePattern()` `[Deprecated]` → `getValidationCodeBucketCacheFilenamePattern()`.
- Doppelte Console-Namen: `rest-api:build-request-validation-cache` (`BuildRestApiValidationCacheConsole`, im Projekt registriert, in Install-Recipes) ist der aktuelle Befehl; `glue:rest:build-request-validation-cache` (`BuildValidationCacheConsole`) ist `@deprecated` und im Projekt nicht registriert.

## Checkliste
- [ ] Stack gewählt (A für Commerce-Ressourcen; B nur mit Prüfung, API Platform ausgeschlossen)
- [ ] `*RestAttributes`-Transfer + `transfer:generate`
- [ ] Route-Plugin: `isProtected` bewusst gesetzt, `getController()` kebab-case
- [ ] Controller dünn, Factory/Reader/Mapper/ResponseBuilder, Clients direkt über den Locator (keine Bridge)
- [ ] Plugin in `GlueApplicationDependencyProvider::getResourceRoutePlugins()` registriert
- [ ] POST/PATCH: `*.validation.yaml` + Validierungs-Cache gebaut
- [ ] Relationships/ETag/Scopes nur falls nötig und registriert
- [ ] Stack B/Backend: `glue-api:controller:cache:warm-up` ohne Argument (und Router-Cache), Stack A: kein Controller-Cache; Smoke-Test per curl
- [ ] `@Glue`-Docblock, OpenAPI neu generiert
- [ ] Keine deprecated Plugins neu verwendet, keine Core-Änderungen

## Quellen
Doku (Präfix `https://docs.spryker.com/docs/`, nur URL-Erreichbarkeit geprüft): `dg/dev/glue-api/latest/glue-api.html`, `.../rest-api/glue-rest-api.html`, `.../rest-api/glue-infrastructure.html`, `.../glue-api-tutorials/implement-a-rest-api-resource.html`, `.../glue-api-tutorials/extend-a-rest-api-resource.html`, `.../create-json-api-relationships.html`, `.../glue-api-tutorials/validate-rest-request-format.html`, `.../rest-api/glue-api-authentication-and-authorization.html`, `.../glue-api-tutorials/document-glue-api-resources.html`, `dg/dev/architecture/api-platform.html` (leitet laut T-13 auf `integrations/spryker-api/...` um).

Quellcode: `src/Pyz/Glue/GlueApplication/{GlueApplicationDependencyProvider,GlueApplicationConfig}.php`, `src/Pyz/Glue/GlueStorefrontApiApplication/*`, `src/Pyz/Glue/{AuthRestApi,OauthApi,EntityTagsRestApi,ProductsRestApi,Console}/*`, `public/{Glue,GlueStorefront}/index.php`, `config/install/development.yml`, `vendor/spryker/glue-application/src/Spryker/Glue/GlueApplication/{GlueApplicationConfig.php,Rest/*,Plugin/Console/*}`, `vendor/spryker/glue-application-extension/.../Dependency/Plugin/*`, `vendor/spryker/{stores-rest-api,stores-api,urls-rest-api,customers-rest-api,auth-rest-api,oauth-api,rest-request-validator,entity-tags-rest-api}`, `vendor/spryker/glue-storefront-api-application/.../RequestValidator/ScopeRequestAfterRoutingValidator.php`.

Nachbarn: [13 Glue Backend](13-glue-backend.md), [08 Clients](08-client-storage-search.md), [04 Transfer](04-transfer.md), [21 Security](21-security-session.md), Template [glue-resource-storefront](../templates/glue-resource-storefront/README.md).
