# Template: glue-resource-storefront (klassischer GlueApplication-Stack)

## Zweck
Öffentliche Storefront-Ressource `product-badges` im **klassischen Glue-Stack** (`glue.*`, `ResourceRoutePluginInterface`, JSON:API). Liest Badges eines Abstract-Produkts über den Client `Pyz\Client\ProductBadge\ProductBadgeClientInterface` (Storage, kein Zed-Zugriff). Der Client wird ohne Bridge direkt über den Locator im DependencyProvider geholt; die Factory typisiert den Getter auf `ProductBadgeClientInterface`.

- `GET /product-badges?idProductAbstract=5` liefert die Badges (Collection, `id` = `badgeKey`).
- `GET /product-badges/{badgeKey}?idProductAbstract=5` liefert ein Badge.

Warum dieser Stack: In 202610.0 leben praktisch alle Commerce-Ressourcen im klassischen Stack (`glue.*`, ca. 80 Route-Plugins). Der Decoupled-Stack (`glue-storefront.*`, `GlueStorefrontApiApplication`) hat nur `token`, `stores` und Multi-Factor-Auth-Ressourcen registriert; `spryker/api-platform` ist **nicht installiert**. Siehe [12-glue-storefront](../../references/12-glue-storefront.md).

## Dateiliste (Zielpfad unter Projektroot)
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

## Platzhalter / Anpassung
- `ProductBadge` / `ProductBadges` / `product-badges` durch den eigenen Namen ersetzen (Modulname, Ressourcentyp, Controller-Kebab-Name `product-badges-resource` = `ProductBadgesResourceController`).
- Fehlercodes `4401`/`4402` in `ProductBadgesRestApiConfig` sind frei gewählt (Annahme: im Projekt ungenutzt); pro Modul eindeutig halten.
- Transfers: `ProductBadge` ist hier wie in transfer-and-propel `strict="true"` (sonst `TransferDefinitionMismatchException` beim Kombinieren der Templates); die Properties werden beim Generieren zusammengeführt.
- Der Client-Rückgabewert `array` wird defensiv gemappt (`ProductBadgeTransfer` oder Storage-Array, `fromArray(..., true)` akzeptiert snake_case und camelCase). Passt das Client-Template einen anderen Typ an, nur `ProductBadgeMapper` ändern.
- Soll die Ressource geschützt sein: in `ProductBadgesResourceRoutePlugin::configure()` `addGet('get')` ohne `false` (Default ist `isProtected = true`). Für POST/PATCH zusätzlich `*.validation.yaml` anlegen (siehe Referenz).

## Registrierung
Datei `src/Pyz/Glue/GlueApplication/GlueApplicationDependencyProvider.php`, Methode `getResourceRoutePlugins()`:
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
Kein composer-Eintrag nötig (Namespace `Pyz\`). Das Modul braucht den Locator-Aufruf `productBadge()->client()`; dafür muss das Client-Template (`Pyz\Client\ProductBadge`) vorhanden sein.

## Befehle danach
`transfer:generate`, `dev:ide-auto-completion:generate`, `rest-api:generate:documentation` und die curl-Aufrufe gegen die Ressource liefen im Shop 202610.0 `[live verifiziert (202610.0)]`.
```bash
docker/sdk cli vendor/bin/console transfer:generate
docker/sdk cli vendor/bin/console dev:ide-auto-completion:generate   # nötig, damit der Locator-Aufruf productBadge() für PHPStan/IDE bekannt ist
curl -H 'Accept: application/vnd.api+json' 'http://glue.eu.spryker.local/product-badges?idProductAbstract=1'
# Optional OpenAPI (Stack A): Zed-Konsole, nicht vendor/bin/glue
docker/sdk console rest-api:generate:documentation
```
Live-Ergebnis (Badge 1 am Abstract 4, publiziert):
- `GET /product-badges?idProductAbstract=4` -> `200`, `{"data":[{"type":"product-badges","id":"new","attributes":{"badgeKey":"new","label":"New!"},"links":{"self":"..."}}],"links":{...}}`.
- `GET /product-badges/new?idProductAbstract=4` -> `200`, `data` als Objekt.
- `GET /product-badges?idProductAbstract=9999` (keine Badges) -> `200` mit `"data":[]`.
- `GET /product-badges` ohne Parameter -> `400`, Code `4401`, `Query parameter "idProductAbstract" is missing or invalid.`
- Die Ressource ist anonym erreichbar (`addGet('get', false)`).
- Codeception-Cest (`seeResponseMatchesOpenApiSchema`) braucht vorher `rest-api:generate:documentation`, sonst `No valid path is found in the schema` (siehe Template `codeception-test`).
- **Rückbau:** Wird die Ressource wieder entfernt, danach `docker/sdk cli console rest-api:generate:documentation` erneut ausführen und `rest-api:build-request-validation-cache` neu bauen. Die generierte `src/Generated/Glue/Specification/spryker_rest_api.schema.yml` ist per `.gitignore` ausgeschlossen (nicht in `git status` sichtbar) und enthält sonst weiter `product-badges`; Kontrolle per `grep -ci badge …` = 0 `[live verifiziert (202610.0)]`.
- `cache:empty-all` löscht den Glue-Controller-Cache; danach `docker/sdk cli vendor/bin/glue glue-api:controller:cache:warm-up` (ohne Argument, siehe Referenz 12) für die Decoupled-Stacks; der klassische Stack braucht ihn nicht.
Der klassische Stack (`glue.*`) liest keinen Controller-Cache (`getControllerConfigurationCacheCollectorPlugins()` kennt nur Storefront und Backend); ein Controller-Cache-Warm-up ist hier nicht nötig und `router:debug` gibt es für diesen Stack nicht (`router:debug` akzeptiert nur `storefront`/`backend`). Eine unbekannte Route liefert live `{"errors":[{"status":404,"detail":"Not Found"}]}`, nicht die Codes `007`/`008` (die gehören zum Decoupled-Stack).

## Verwendete Quelle (Vorbild)
- `vendor/spryker/urls-rest-api/src/Spryker/Glue/UrlsRestApi/` (Route-Plugin, Controller, ResponseBuilder, öffentliche GET-Ressource mit Query-Parameter)
- `vendor/spryker/stores-rest-api/src/Spryker/Glue/StoresRestApi/` (Factory, DependencyProvider, Mapper; dort mit Bridge als Core-Muster, im Template bewusst ohne Bridge)
- Registrierung: `src/Pyz/Glue/GlueApplication/GlueApplicationDependencyProvider.php`

## Verifikationsstatus
Durchgespielt im Shop 202610.0 (Registrierung in `GlueApplicationDependencyProvider` wie beschrieben, curl gegen `glue.eu.spryker.local`, `code:sniff:style` Exit 0, PHPStan Level 6 ohne Befund, Cest `ProductBadgesRestApiCest` grün). Die Antwortform (`data[]` mit `type=product-badges`, `attributes`, `links.self`) ist damit belegt. `[live verifiziert (202610.0)]`
