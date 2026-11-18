# Template: yves-controller-route (Yves-Seite mit Route, Controller, Twig-View)

## Zweck
Yves-Modul `ProductBadgePage` mit der Route `GET /product-badge/{idProductAbstract}` (Routenname `product-badge`), die die Badges eines Abstract-Produkts aus dem Client `Pyz\Client\ProductBadge\ProductBadgeClientInterface` (Storage) in einer Twig-View ausgibt. Unbekannte ID oder keine Badges: Weiterleitung auf die 404-Fehlerseite (live: `307`, siehe unten). Erklärung des Musters: [14-yves-controller-routing](../../references/14-yves-controller-routing.md).

## Dateiliste (Zielpfad unter Projektroot)
```
src/Pyz/Yves/ProductBadgePage/Plugin/Router/ProductBadgePageRouteProviderPlugin.php
src/Pyz/Yves/ProductBadgePage/Controller/ProductBadgeController.php
src/Pyz/Yves/ProductBadgePage/Reader/ProductBadgeReader.php
src/Pyz/Yves/ProductBadgePage/Reader/ProductBadgeReaderInterface.php
src/Pyz/Yves/ProductBadgePage/ProductBadgePageFactory.php
src/Pyz/Yves/ProductBadgePage/ProductBadgePageDependencyProvider.php
src/Pyz/Yves/ProductBadgePage/Theme/default/views/product-badge/product-badge.twig
```

## Platzhalter / Anpassung
- `ProductBadgePage` (Modulordner = Namespace-Segment = Twig-Namespace `@ProductBadgePage`), `ProductBadge` (Controller ohne Suffix), `product-badge` (Route/View) ersetzen.
- In `buildGetRoute()` müssen Modulname und Controllername exakt zu Ordner und Klasse passen (`'ProductBadgePage'`, `'ProductBadge'`, nicht `'ProductBadgeController'`).
- Das Template wird explizit an `view()` übergeben (`@ProductBadgePage/views/product-badge/product-badge.twig` = Datei `Theme/default/views/product-badge/product-badge.twig`); nicht auf die `_template`-Konvention verlassen.
- Der Titel in der Twig-Datei ist hartkodiert; für Mehrsprachigkeit Glossar-Key und `| trans` verwenden (→ [19-multi-store-i18n](../../references/19-multi-store-i18n.md)).
- Soll die Seite nur für eingeloggte Kunden sein: URL in `CustomerConstants::CUSTOMER_SECURED_PATTERN` (`config/Shared/config_default.php`) aufnehmen (→ [21-security-session](../../references/21-security-session.md)).

## Registrierung
Datei `src/Pyz/Yves/Router/RouterDependencyProvider.php`, Methode `getRouteProvider()`:
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
Ohne Eintrag: 404. Kein composer-Eintrag nötig (Namespace `Pyz\`). Voraussetzung: Client-Template `Pyz\Client\ProductBadge` (Locator `productBadge()->client()`).

## Befehle danach
`[live verifiziert (202610.0)]` (Template im Shop installiert, Befehle und Route ausgeführt)
```bash
docker/sdk cli vendor/bin/yves router:cache:warm-up       # Yves-Router-Cache (Yves-Konsole, nicht Zed); im Dev-Modus fängt YvesDevelopmentRouterPlugin Treffer ab
docker/sdk console cache:empty-all                        # Zed-Konsole
docker/sdk cli vendor/bin/yves router:debug product-badge # Route prüfen (nur in der Yves-Konsole, in Zed gibt es kein router:debug)
```
Aufruf: `http://yves.eu.spryker.local/product-badge/<idProductAbstract>` (ggf. mit Sprachpräfix `/en/...`).

Live-Ergebnis (202610.0): `GET /product-badge/4` und `/en/product-badge/4` -> `200`, Seite mit `<h1>Product badges (#4)</h1>` und `<li data-qa="product-badge-new">`. Unbekannte ID (`/product-badge/9999`) -> **`307` Redirect** auf `/DE/en/error-page/404?errorMessage=No+product+badges+found+for+abstract+product+%229999%22.` (Die Fehlerseite selbst liefert in diesem Shop HTTP `200`.) `router:debug product-badge` zeigt Pfad `/product-badge/{idProductAbstract}`, Requirement `\d+`, Controller `ProductBadgeController::indexAction`.

## Verwendete Quelle (Vorbild)
- `src/Pyz/Yves/ExampleProductSalePage/` (Provider, Controller, Factory, DependencyProvider, Twig-View)
- `src/Pyz/Yves/Router/RouterDependencyProvider.php` (Registrierung)
- `vendor/spryker/router/src/Spryker/Yves/Router/Plugin/RouteProvider/AbstractRouteProviderPlugin.php`, `vendor/spryker/kernel/src/Spryker/Yves/Kernel/Controller/AbstractController.php`

## Verifikationsstatus
Klassen und Signaturen (`AbstractRouteProviderPlugin::buildGetRoute`, `AbstractController::view`, `AbstractBundleDependencyProvider::provideDependencies(Container)`, `AbstractFactory::getProvidedDependency`, Template `page-layout-main` mit Block `content`) gegen `vendor/` und `src/Pyz` geprüft. Durchgespielt im Shop 202610.0 (Registrierung in `RouterDependencyProvider`, Router-Cache-Warmup, Seite per curl), `code:sniff:style` Exit 0, PHPStan Level 6 ohne Befund. `[live verifiziert (202610.0)]`
