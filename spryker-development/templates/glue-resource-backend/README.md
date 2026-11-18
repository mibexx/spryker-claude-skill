# Template: glue-resource-backend (Glue Backend API Application)

## Zweck
Backend-Ressource `product-badges` auf der **GlueBackendApiApplication** (`glue-backend.*`, Back-Office-User/System-zu-System, OAuth-Token über `POST /token`):
- `GET /product-badges[?badgeKey=<key>]` liefert die Badge-Collection.
- `POST /product-badges` legt ein Badge an (`fkProductAbstract`, `badgeKey`, `label`; Body als flaches JSON, siehe unten).

Im Unterschied zur Storefront arbeitet das Backend direkt mit der **Zed-Facade**, nicht mit Clients. Die Facade `Pyz\Zed\ProductBadge\Business\ProductBadgeFacadeInterface` stammt aus dem Template `zed-module`; sie wird ohne Bridge direkt über den Locator im DependencyProvider geholt, die Factory typisiert den Getter auf `ProductBadgeFacadeInterface`. Siehe [13-glue-backend](../../references/13-glue-backend.md).

## Dateiliste (Zielpfad unter Projektroot)
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

## Platzhalter / Anpassung
- `ProductBadge(s)` durch den eigenen Namen ersetzen. Der Ressourcen-Typ ist das URL-Segment (`/product-badges`).
- Fehlercodes `4501` (400, fehlende Attribute) und `4502` (422, `InvalidProductBadgeException` der Facade: ungültiger Key oder unbekanntes Abstract-Produkt) sind frei gewählt (Annahme: ungenutzt). Fachliche Exceptions der Facade werden im `ProductBadgeCreator` abgefangen, sonst entsteht HTTP 500.
- Transfers: `ProductBadge`, `ProductBadgeCollection`, `ProductBadgeCriteria` sind wie in transfer-and-propel `strict="true"` (sonst `TransferDefinitionMismatchException` beim Kombinieren der Templates).
- PUT ist im `ResourceRouteBuilder` nicht vorgesehen; für PUT/Sonderrouten einen `RouteProviderPluginInterface` bauen (Vorbild `DynamicEntityRouteProviderPlugin`).
- Parent-Child (`/parent/{id}/child`) per `ResourceWithParentPluginInterface` ist im Backend-Router nicht belegt (siehe Referenz), vor Einsatz mit `router:debug backend` prüfen.

## Registrierung (3 Stellen)
1. Resource-Plugin: `src/Pyz/Glue/GlueBackendApiApplication/GlueBackendApiApplicationDependencyProvider.php`, `getResourcePlugins()`:
   ```php
   use Pyz\Glue\ProductBadgesBackendApi\Plugin\GlueBackendApiApplication\ProductBadgesBackendResource;
   ...
   $plugins = [
       new OauthBackendApiTokenResource(),
       // ...
       new ProductBadgesBackendResource(),
   ];
   ```
2. Schutz: `src/Pyz/Shared/GlueBackendApiApplicationAuthorizationConnector/GlueBackendApiApplicationAuthorizationConnectorConfig.php`, `getProtectedPaths()` ergänzen. Ohne Eintrag ist die Ressource anonym erreichbar (live bestätigt: `GET /product-badges` ohne Token liefert ohne Eintrag `200` mit allen Badges, mit Eintrag `401`):
   ```php
   '/product-badges' => [
       'isRegularExpression' => false,
   ],
   ```
   Optional auf Methoden beschränken: `'methods' => ['post']`.
3. Keine Scope-Deklaration nötig: `ScopeDefinitionPluginInterface` wird hier bewusst nicht implementiert. Wird es implementiert, ist jede Methode ohne Scope-Eintrag verboten (403).

## Befehle danach
Alle Befehle und die curl-Aufrufe liefen im Shop 202610.0 `[live verifiziert (202610.0)]`.
```bash
docker/sdk cli vendor/bin/console transfer:generate
docker/sdk cli vendor/bin/console dev:ide-auto-completion:generate   # damit der Locator-Aufruf productBadge() für PHPStan/IDE bekannt ist
docker/sdk cli vendor/bin/glue glue-api:controller:cache:warm-up   # OHNE Argument: mit Applikationsname überschreibt der Lauf den Cache der anderen Applikation (Referenz 13)
docker/sdk cli vendor/bin/glue router:debug backend       # Argument: backend | storefront (Doku nennt "route:debug", Code "router:debug")
docker/sdk cli vendor/bin/console oauth:scope-collection-file:generate   # nur bei Scope-Deklaration
# Token holen (Form-Body; JSON liefert "unsupported_grant_type"), dann Ressource aufrufen
curl -X POST http://glue-backend.eu.spryker.local/token -H 'Content-Type: application/x-www-form-urlencoded' \
  -d 'grant_type=password&username=<user>&password=<pw>'
curl http://glue-backend.eu.spryker.local/product-badges -H 'Authorization: Bearer <access_token>'
curl -X POST http://glue-backend.eu.spryker.local/product-badges -H 'Authorization: Bearer <access_token>' \
  -H 'Content-Type: application/json' -d '{"fkProductAbstract":7,"badgeKey":"sale","label":"Sale"}'
```
Ein falscher Application-Name beim Warm-up schreibt einen leeren Controller-Cache.

Live-Ergebnis (Demo-User `admin@spryker.com` / `change123`, Release 202610.0):
- Ohne Token: `401` `[{"code":null,"status":401,"message":"Unauthorized request."}]` (Eintrag in `getProtectedPaths()` wirkt).
- `POST /token` (Form-Body) -> `200` mit `access_token` (JWT, 8 h).
- `GET /product-badges` -> `200`, **ein flaches JSON-Array** `[{"idProductBadge":1,"fkProductAbstract":4,"badgeKey":"new","label":"New!"}]`, `?badgeKey=new` filtert.
- `POST` mit flachem JSON-Body -> `201`, Antwort das Objekt `{"idProductBadge":4,...}`. Fehlende Attribute -> `400` Code `4501`, ungültiger Key (`zzz`) -> `422` Code `4502` `Badge key "zzz" is not allowed.`, unbekanntes Abstract -> `422` `Product abstract with id "9999" does not exist.`.
- **Kein JSON:API-Wrapper in diesem Projekt:** Die GlueBackend-Applikation hat hier keine JSON-API-Convention registriert. Ein Body `{"data":{"type":...,"attributes":{...}}}` wird nicht entpackt (`GlueRequestTransfer.attributes` enthält das ganze JSON, Attribute bleiben leer -> `4501`), und Antworten haben kein `data`/`attributes`. Mit registrierter `GlueJsonApiConvention` gälte der Wrapper (`AttributesRequestBuilder` der Convention liest `data.attributes`).


- **Rückbau:** Nach dem Entfernen der Ressource `docker/sdk cli vendor/bin/glue glue-api:controller:cache:warm-up` (ohne Argument) und `docker/sdk cli vendor/bin/glue api:generate:documentation` erneut ausführen; die generierten Dateien unter `src/Generated/GlueBackend/Specification/` sind per `.gitignore` ausgeschlossen und nicht in `git status` sichtbar.
## Verwendete Quelle (Vorbild)
- `vendor/spryker/oauth-backend-api/src/Spryker/Glue/OauthBackendApi/` (Resource-Plugin, `TokenResourceController`, Backend-DependencyProvider; dort mit Facade-Bridge als Core-Muster, im Template bewusst ohne Bridge)
- `vendor/spryker/multi-factor-auth/src/Spryker/Glue/MultiFactorAuth/Plugin/GlueBackendApiApplication/` (`getCollection`-Deklaration)
- `vendor/spryker/dynamic-entity-backend-api/` (Controller-Signaturen `getAction(string $id, ...)`, Query-Felder über `GlueRequestTransfer::getQueryFields()`)
- Registrierung: `src/Pyz/Glue/GlueBackendApiApplication/GlueBackendApiApplicationDependencyProvider.php`

## Verifikationsstatus
Durchgespielt im Shop 202610.0 (alle drei Registrierungsstellen wie beschrieben, `router:debug backend` zeigt `product-badgesResourceGetCollection` und `product-badgesResourcePost` mit Schutz `Yes`, Token und Ressourcenaufrufe per curl, `code:sniff:style` Exit 0, PHPStan Level 6 ohne Befund). Die Antwortform ist flach (siehe oben), die frühere Annahme `data[]`/`attributes` war falsch. `[live verifiziert (202610.0)]`
