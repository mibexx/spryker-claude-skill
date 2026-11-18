# Glue Backend API, Decoupled Glue und API-Autorisierung (Recherche T-13)

> Status: Code in `vendor/` und `src/Pyz` (202610.0) direkt gelesen, Basisklassen und Signaturen des Beispiels gegen `vendor/` geprüft. Doku-Texte wurden nicht abgeglichen `[code-verifiziert, Doku nicht abgeglichen]`. Alle Befehle wurden im Container ausgeführt `[live verifiziert (202610.0)]`. Das Template wurde in einer Sandbox mit `php -l`, phpcs, phpstan (L6) und `transfer:generate` (kombiniert mit transfer-and-propel) geprüft, nicht gegen eine laufende Instanz ausgeführt. Offene Lücken: UND/ODER-Verknüpfung mehrerer Authorization-Strategien, Console-Befehl des Dynamic-Entity-Installers, Parent-Child im Backend-Router, eigene dritte Glue-Applikation (nur Doku).

## Zweck
Backend-API-Ressourcen (Back-Office-User, System-zu-System) auf der `GlueBackendApiApplication` bauen und absichern (OAuth-Token, Protected Paths, Scopes, API-Key, Dynamic Entity). Storefront-API → [12](12-glue-storefront.md); Security allgemein → [21](21-security-session.md); ACL im Back Office → [18](18-back-office.md).

## Konzepte
**Einstiegspunkte in 202610.0** (`public/*/index.php`, `deploy.dev.yml`)

| `public/…` | Bootstrap | Zweck |
|---|---|---|
| `Glue/` | `Pyz\Glue\GlueApplication\Bootstrap\GlueBootstrap` | Legacy-Glue (`*RestApi`) → [12](12-glue-storefront.md) |
| `GlueStorefront/` | `GlueStorefrontApiBootstrap` | Storefront-API → [12](12-glue-storefront.md) |
| `GlueBackend/` | `GlueBackendApiBootstrap` (`APPLICATION=GLUE_BACKEND`) | **Glue Backend API** (dieses Thema), Host `glue-backend.eu.spryker.local` |
| `BackendApi/` | Zed-`BackendApiBootstrap` (`backend-api.*`) | Älterer Zed-Einstieg, nicht die Glue Backend API; in `deploy.dev.yml` (`backapi_eu`/`backapi_us`, `application: zed`, `entry-point: BackendApi`, Host `backend-api.*`) konfiguriert, Nutzung durch Projektcode nicht vertieft |

„Decoupled Glue“ = Trennung in `GlueStorefrontApiApplication` und `GlueBackendApiApplication` auf Basis von `GlueApplication`. Konfiguration `config/Shared/config_default.php` (Abschnitt „Glue Backend API“): `GLUE_BACKEND_API_HOST`, `PROJECT_NAMESPACES = ['Pyz']`, CORS.

**Backend vs. Storefront**

| Aspekt | Storefront | Backend |
|---|---|---|
| Datenzugriff | Clients | **Facades** direkt (Locator im DependencyProvider, ohne Bridge) |
| Identität | Kunde (`CustomerRequestBuilderPlugin`) | Back-Office-User (`UserRequestBuilderPlugin`) |
| OAuth-Modul | `oauth-api` (`OauthApiTokenResource`) | `oauth-backend-api` (`OauthBackendApiTokenResource`) |
| Kernel-Basisklassen | `Spryker\Glue\Kernel\...` | `Spryker\Glue\Kernel\Backend\...` (Factory, Plugin, Controller, DependencyProvider, Container) |
| Modulnamen | `*Api`, `*RestApi` | `*BackendApi` |
| Routen | Resource-Plugins (+ Legacy Route-Plugins) | `ResourceInterface`-Plugins **und** `RouteProviderPluginInterface` |

**Routen:** `ResourceRouteBuilder` erzeugt `/{type}` und `/{type}/{id}` (`get`, `patch`, `delete`); `get|getCollection→GET`, `post→POST`. **PUT ist nicht in `ACTION_METHOD_MAP`**: PUT/Sonderrouten nur per `RouteProviderPluginInterface` (Vorbild `DynamicEntity`). Default-Format CamelCase, `setIsSnakeCased(true)` schaltet auf snake_case. JSON:API ist **nicht** Default: `JsonApiConventionPlugin::isApplicable()` greift nur bei `Content-Type`/`Accept: application/vnd.api+json` und nur für Ressourcen mit `JsonApiResourceInterface`.

**Authentifizierung:** `POST /token` (`OauthBackendApiTokenResource`, Controller `TokenResourceController`, Attribute `ApiTokenAttributesTransfer`, Application `GLUE_BACKEND_API_APPLICATION`, Audit-Log). Pipeline im Projekt (`GlueBackendApiApplicationDependencyProvider`): `ApplicationIdentifier-`/`Locale-`/`UserRequestBuilderPlugin`; Validatoren `BackendApiAccessTokenValidatorPlugin`, `UserRequestValidatorPlugin`; After-Routing `RequestCors-`, `ScopeRequestAfterRouting-`, `AuthorizationRequestAfterRoutingValidatorPlugin`, `MultiFactorAuthBackendApiRequestValidatorPlugin`. Scopes: `UserOauthScopeProviderPlugin` (Grant `password`, Scope `user`; zusätzlich `back-office-user` möglich).

**Autorisierung** (`AuthorizationValidator::validate()`): `OPTIONS` immer erlaubt; Strategien aus Route-Default `_authorization_strategies`; steht der Pfad in `getProtectedPaths()`, kommt `ProtectedPath` (401 „Unauthorized request.“) plus Configs der `ProtectedRouteAuthorizationConfigProviderPlugins` (Pyz: `OauthUserScopeProtectedRouteAuthorizationConfigProviderPlugin` → `UserOauthScope`, 403) dazu; `AuthorizationRequestExpanderPlugins` (Pyz: `ApiKeyAuthorizationRequestExpanderPlugin`) ergänzen die Identity.

| Strategie | Wirkung |
|---|---|
| `ProtectedPath` | Pfad in `getProtectedPaths()` → authentifizierter User nötig, sonst 401 |
| `UserOauthScope` | User-Identity braucht Scope `back-office-user` oder exakt `['user']`, sonst 403 |
| `ApiKey` | gültiger Key in Header `x-api-key` oder Query `api_key` |

**Scope pro Resource:** implementiert ein Resource-Plugin `Spryker\Glue\OauthExtension\Dependency\Plugin\ScopeDefinitionPluginInterface::getScopes(): array` (Methode klein → Scope), prüft `ScopeRequestAfterRoutingValidatorPlugin` `RequestUser->getScopes()`; **Methode ohne Eintrag = verboten (403)**. Scopes landen per `oauth:scope-collection-file:generate` in `src/Generated/Zed/Oauth/GlueScopesCache/glue_scopes_cache.yml`. Im Release nutzt keine installierte Backend-Ressource dieses Interface.

**API-Key:** Module `api-key`, `api-key-gui` (Back Office: Administration → API Keys), `api-key-authorization-connector`. Keys werden nur gehasht (`sha256`) gespeichert, bei Verlust neu erzeugen. Route-Schutz per `->setDefault('_authorization_strategies', ['ApiKey'])`.

**Dynamic Entity (Data Exchange API):** `spryker-feature/data-exchange-api` → `dynamic-entity`, `dynamic-entity-backend-api`, `dynamic-entity-gui`. `DynamicEntityRouteProviderPlugin` (in `getRouteProviderPlugins()`) baut `GET/POST/PATCH/PUT/DELETE` auf `/dynamic-entity/{resourceName}[/{id}]`, Route-Default `_authorization_strategies = ['ApiKey']`. Offene Tabellen/Felder: `src/Pyz/Zed/DynamicEntity/data/installer/configuration.json` (75 Einträge) bzw. Back Office. Die Regex `/^\/dynamic-entity\/.+/` steht in `Pyz\Glue\GlueApplication\GlueApplicationConfig::getRoutePatternsAllowedForCacheWarmUp()` (Cache-Warm-up-Muster), nicht in `getProtectedPaths()`. Log: `data/dynamic-entity/logs/<name>.log`.

**API Platform:** `spryker/api-platform` ist **nicht installiert**. Der Code ist vorbereitet (`ApiApplicationProxy` fällt bei 404 ohne ausführbare Resource auf den Symfony-Kernel/API Platform zurück; `stores-api` enthält toten `Api/Storefront/Provider/StoresStorefrontProvider.php`). Für neue Endpunkte den `AbstractResourcePlugin`-Weg aus diesem Dokument nutzen; API Platform nur nach bewusster Einführung (nicht verifiziert).

## Anleitung
Neue Backend-Ressource (Template [glue-resource-backend](../templates/glue-resource-backend/README.md)):
1. Transfer `*BackendApiAttributes` (+ Stubs `GlueRequest`, `GlueResponse`, `GlueResource`, `GlueError`, `GlueResourceMethodCollection`, `GlueResourceMethodConfiguration`) ([04](04-transfer.md)).
2. Config mit `RESOURCE_*`-Konstante (= URL-Segment).
3. **Factory** `extends Spryker\Glue\Kernel\Backend\AbstractBackendApiFactory` (Pflicht: `AbstractBackendApiController::getFactory()` wirft sonst `InvalidAbstractFactoryException`); **DependencyProvider** `extends Spryker\Glue\Kernel\Backend\AbstractBundleDependencyProvider`, Dependencies in `provideBackendDependencies(Spryker\Glue\Kernel\Backend\Container)` (Facade direkt per `$container->getLocator()->x()->facade()`, ohne Bridge; Factory-Getter auf `Pyz\Zed\X\Business\XFacadeInterface` bzw. das Facade-Interface des Core-Moduls typisieren; Bridges nur als Core-Muster, siehe [02](02-projektmodul.md)).
4. **Controller** `extends Spryker\Glue\Kernel\Backend\Controller\AbstractBackendApiController`: `getCollectionAction(GlueRequestTransfer)`, `getAction(string $id, GlueRequestTransfer)`, `postAction(<Attributes>Transfer, GlueRequestTransfer)`, `patchAction`, `deleteAction`; Rückgabe `GlueResponseTransfer` (Fehler via `GlueErrorTransfer`, Daten via `GlueResourceTransfer`). Controller bleibt dünn.
5. **Resource-Plugin** `extends Spryker\Glue\GlueApplication\Plugin\GlueApplication\Backend\AbstractResourcePlugin implements ResourceInterface` (die Variante ohne `Backend` gehört zur Storefront-/Legacy-Welt): `getType()`, `getController()` (FQCN), `getDeclaredMethods()`.
6. **Registrieren** in `Pyz\Glue\GlueBackendApiApplication\GlueBackendApiApplicationDependencyProvider::getResourcePlugins()`.
7. **Schützen:** Pfad in `Pyz\Shared\GlueBackendApiApplicationAuthorizationConnector\GlueBackendApiApplicationAuthorizationConnectorConfig::getProtectedPaths()` (`'/product-badges' => ['isRegularExpression' => false]`, optional `'methods' => [...]`). Ohne Eintrag und ohne `_authorization_strategies` ist die Ressource vermutlich anonym erreichbar `[Annahme]`, aus `AuthorizationValidator` abgeleitet.
8. Befehle: `transfer:generate`, Controller-Cache (`GLUE_BACKEND_API_APPLICATION`), `router:debug backend`, ggf. Scope-Datei (siehe Beispiele).

**Fehlerbehandlung in Ressourcen:** Fachliche Exceptions der Facade (z. B. `InvalidProductBadgeException`) im Creator/Reader abfangen und als `GlueErrorTransfer` mit 400/422 zurückgeben; ungefangen werden sie zu HTTP 500 (Template: 400 bei fehlenden Attributen, 422 bei ungültigem Key/unbekanntem Produkt).

**Eigene Authorization-Strategie:** Plugin `implements Spryker\Shared\AuthorizationExtension\Dependency\Plugin\AuthorizationStrategyPluginInterface` (`authorize(AuthorizationRequestTransfer): bool`, `getStrategyName(): string`; im Code liegt es unter `Shared`, die Doku nennt noch `Spryker\Client\AuthorizationExtension\...`), Registrierung in `Pyz\Zed\Authorization\AuthorizationDependencyProvider::getAuthorizationStrategyPlugins()`, Zuordnung per Route-Default `_authorization_strategies` oder `ProtectedRouteAuthorizationConfigProviderPluginInterface::provide(): RouteAuthorizationConfigTransfer`. Ob `AuthorizationStrategyAwareResourceRoutePluginInterface` (Doku-Beispiel) im Backend greift, ist nicht belegt (Extractor existiert, kein vendor-Beispiel).

**Parent-Child (`/parent/{id}/child`):** `ResourceWithParentPluginInterface::getParentResourceType()`; ob `ResourceRouteBuilder` es berücksichtigt, ist nicht belegt `[Annahme]` → vor Einsatz mit `router:debug backend` prüfen oder `RouteProviderPluginInterface` nutzen.

**Eigene Applikation/Konvention (nur Doku, nicht gegen Code belegt):** eigener `entry-point` + `public/<Name>/index.php` mit `define('APPLICATION', 'GLUE_CUSTOM')`, Bootstrap/Plugin/Application-Klassen unter `Pyz\Glue\CustomApiApplication`; Vorlage `src/Pyz/Glue/GlueApplication/Bootstrap/GlueBackendApiBootstrap.php`. Konvention: `ConventionPluginInterface` in `GlueApplicationDependencyProvider::getConventionPlugins()` (Pyz: nur `JsonApiConventionPlugin`).

## Beispiele
Vollständiger Code: [templates/glue-resource-backend](../templates/glue-resource-backend/README.md). Kernstücke:

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

Release-Muster der Registrierung (echt, gekürzt, `src/Pyz/Glue/GlueBackendApiApplication/GlueBackendApiApplicationDependencyProvider.php`):
```php
protected function getResourcePlugins(): array
{
    $plugins = [
        new OauthBackendApiTokenResource(),
        new MultiFactorAuthBackendResourcePlugin(),
        // ... weitere MultiFactorAuth*-Resources
        // hier: new ProductBadgesBackendResource(),
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

Schutz (`src/Pyz/Shared/GlueBackendApiApplicationAuthorizationConnector/GlueBackendApiApplicationAuthorizationConnectorConfig.php`, in `getProtectedPaths()` ergänzen):
```php
'/product-badges' => [
    'isRegularExpression' => false,
],
```

Befehle (alle ausgeführt, `curl /token` mit falschen Zugangsdaten: Form-Body `400 invalid_client`, JSON-Body `400 unsupported_grant_type`) `[live verifiziert (202610.0)]`:
```bash
docker/sdk cli vendor/bin/console transfer:generate
docker/sdk cli vendor/bin/glue glue-api:controller:cache:warm-up   # ohne Argument: gemeinsamer Cache aller Applikationen; mit GLUE_BACKEND_API_APPLICATION nur der Backend-Anteil, der den Storefront-Anteil überschreibt
docker/sdk cli vendor/bin/glue api:router:cache:warm-up <application_name>
docker/sdk cli vendor/bin/glue router:debug backend        # Argument: backend | storefront ("GlueBackend" ist falsch); Doku nennt "route:debug", Code "router:debug"
docker/sdk cli vendor/bin/console oauth:scope-collection-file:generate
docker/sdk cli vendor/bin/console oauth:refresh-token:remove-expired
docker/sdk cli vendor/bin/glue api:generate:documentation
# Token: Form-Body, nicht JSON (JSON liefert live "unsupported_grant_type")
curl -X POST https://glue-backend.eu.spryker.local/token -H 'Content-Type: application/x-www-form-urlencoded' \
  -d 'grant_type=password&username=<user>&password=<pw>'
curl https://glue-backend.eu.spryker.local/product-badges -H 'Authorization: Bearer <access_token>'
```
Die Pyz-Glue-Console registriert genau `ApiGenerateDocumentationConsole`, `ControllerCacheCollectorConsole`, `RouterDebugGlueApplicationConsole`, `RouterCacheWarmUpConsole`; `glue api:generate` (API Platform) existiert nicht.

## Fallstricke
- **Falsche Basisklasse:** `...\Plugin\GlueApplication\AbstractResourcePlugin` ohne `Backend` im Backend → falsche Resolver/Factory. Immer `Backend\AbstractResourcePlugin`, `Kernel\Backend\...`.
- **Controller-Cache:** Nach neuer Resource `glue-api:controller:cache:warm-up` (ohne Argument), sonst 404. Ein falscher Application-Name (`GlueBackend`, `GLUE_BACKEND`, `GLUE`) schreibt einen leeren Controller-Cache, ein richtiger Name überschreibt den Anteil der anderen Applikation `[live verifiziert (202610.0)]`.
- **Rückbau einer Ressource:** Nach dem Entfernen die Dokumentation neu erzeugen (`docker/sdk cli vendor/bin/glue api:generate:documentation`) und `glue-api:controller:cache:warm-up` (ohne Argument) neu bauen. Die generierten Specification-Dateien unter `src/Generated/GlueBackend/Specification/` sind per `.gitignore` ausgeschlossen und zeigen die Entfernung nicht in `git status`.
- **Scopes:** Neue Scopes erst nach `oauth:scope-collection-file:generate`; Methoden ohne Scope-Eintrag einer `ScopeDefinitionPluginInterface`-Resource sind verboten (403).
- **Pfad nicht geschützt:** neue Backend-Ressourcen immer in `getProtectedPaths()` eintragen.
- **Core ändern statt Pyz erweitern:** `getProtectedPaths()` per Pyz-Config-Vererbung, Plugins per Pyz-DependencyProvider; keine Kopien von Core-Controllern/Factories ([03](03-core-erweitern.md), [28](28-upgradability.md)).
- **Storefront-Muster im Backend:** kein ZedRequest-Client; Facades direkt über den Locator holen (keine Bridge), Factory-Getter auf das Facade-Interface typisieren.
- **Fachliche Exceptions ungefangen** (ungültiger Key, unbekanntes Produkt) → HTTP 500 statt 400/422; im Creator abfangen.
- **`/token` mit JSON-Body** → `unsupported_grant_type`; `application/x-www-form-urlencoded` mit `grant_type=password` verwenden.
- **JSON:API-Annahme:** Backend ist nativ-JSON (live bestätigt mit dem Template `glue-resource-backend`: Request-Body flach `{"fkProductAbstract":7,...}`, Antwort flaches JSON bzw. Array; ein `{"data":{"attributes":...}}`-Wrapper wird ohne registrierte JSON-API-Convention nicht entpackt); `application/vnd.api+json` wirkt nur bei `JsonApiResourceInterface`.
- **PUT** fehlt im `ResourceRouteBuilder` → `RouteProviderPluginInterface`.
- **API-Key im Query (`?api_key=`)** landet in Zugriffslogs; Header `X-Api-Key` bevorzugen.
- **Dynamic Entity + ApiKey + ProtectedPath:** Verknüpfung mehrerer Strategien im `AuthorizationChecker` (UND/ODER) wurde nicht geprüft; erst mit Bearer-Token, API-Key separat testen. Felder mit `isCreatable/isEditable=false` sind schreibgeschützt; eigene Tabellen brauchen vorher Propel-Schema ([05](05-propel-persistenz.md)).
- **CORS:** Backend-Default `*` (`SPRYKER_GLUE_APPLICATION_CORS_ALLOW_ORIGIN`), in Produktion einschränken. Security-Header ergänzt `GlueBackendApiApplicationConfig::getSecurityHeaders()` (`Cache-Control: no-cache, private`).
- **Tests:** `testify-backend-api` (`DynamicFixturesBackendResourcePlugin` hinter `class_exists`) → [26](26-tests.md).

**Deprecations:** `OauthBackendApi\Plugin\GlueApplication\OauthBackendTokenResource` `[Deprecated]` → `OauthBackendApiTokenResource` (im Pyz-Provider schon umgestellt); `OauthBackendApiConfig::REQUEST_ATTRIBUTE_IS_PROTECTED` deprecated (remove in next major); Legacy-Glue (`*ResourceRoutePlugin`, `*RestApi`, `glue.*`) ist der Altweg. Doku-Abweichungen: Plan-URLs leiten auf `integrations/spryker-api/...` um; Doku-Autorisierungsbeispiele nennen `Spryker\Client\AuthorizationExtension`, Code `Spryker\Shared\AuthorizationExtension`; `route:debug` ↔ `router:debug`.

## Checkliste
- [ ] `Kernel\Backend`-Basisklassen (Factory, DependencyProvider, Controller, ResourcePlugin)
- [ ] Facade direkt über den Locator in `provideBackendDependencies()` (keine Bridge), Getter auf Facade-Interface typisiert
- [ ] Controller dünn, Rückgabe `GlueResponseTransfer`, Fehler als `GlueErrorTransfer`
- [ ] Plugin in `getResourcePlugins()` registriert
- [ ] Pfad in `getProtectedPaths()` (oder `_authorization_strategies`) geschützt
- [ ] Scopes nur bewusst; Scope-Datei generiert, falls `ScopeDefinitionPluginInterface` genutzt
- [ ] Fachliche Exceptions abgefangen (400/422)
- [ ] `transfer:generate`, Controller-Cache (`GLUE_BACKEND_API_APPLICATION`), `router:debug backend`, Token- und Ressourcen-Aufruf getestet
- [ ] Kein API-Platform-/Legacy-Muster, keine Core-Änderungen, CORS für Produktion geprüft

## Quellen
Doku (Basis `https://docs.spryker.com/docs/integrations/spryker-api/`): `getting-started-with-apis/comparison-of-storefront-and-backend-apis`, `backend-api/developing-apis/{create-backend-resources,create-api-applications,create-backend-api-resources-with-parent-child-relationships,create-and-change-backend-api-conventions}`, `authenticating-and-authorization/backend-api/{create-backend-api-authorization-strategies,use-api-key-authorization}`, `api-platform/api-platform`.

Quellcode: `src/Pyz/Glue/GlueBackendApiApplication/*`, `src/Pyz/Glue/GlueBackendApiApplicationAuthorizationConnector/*`, `src/Pyz/Shared/GlueBackendApiApplicationAuthorizationConnector/GlueBackendApiApplicationAuthorizationConnectorConfig.php`, `src/Pyz/Zed/{Authorization,Oauth,DynamicEntity,GlueBackendApiApplicationAuthorizationConnector}/*`, `src/Pyz/Glue/GlueApplication/Bootstrap/GlueBackendApiBootstrap.php`, `public/GlueBackend/index.php`, `vendor/spryker/glue-backend-api-application/.../{ResourceRouteBuilder,RequestValidator/ScopeRequestAfterRoutingValidator}`, `vendor/spryker/kernel/src/Spryker/Glue/Kernel/Backend/*`, `vendor/spryker/glue-application/.../Plugin/GlueApplication/Backend/AbstractResourcePlugin.php`, `vendor/spryker/oauth-backend-api/*`, `vendor/spryker/glue-backend-api-application-authorization-connector/*`, `vendor/spryker/api-key*/*`, `vendor/spryker/dynamic-entity-backend-api/*`, `vendor/spryker/multi-factor-auth/.../Glue/MultiFactorAuth/*`.

Nachbarn: [12 Glue Storefront](12-glue-storefront.md), [21 Security](21-security-session.md), [18 Back Office](18-back-office.md), Template [glue-resource-backend](../templates/glue-resource-backend/README.md).
