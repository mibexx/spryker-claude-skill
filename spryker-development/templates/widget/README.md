# Template: widget (Yves-Widget)

## Zweck
Yves-Widget `ProductBadgeWidget`: rendert die Badges eines Abstract-Produkts (Daten aus `Pyz\Client\ProductBadge\ProductBadgeClientInterface`, Storage) als Liste. Wird in beliebigen Twig-Templates per `{% widget 'ProductBadgeWidget' args [idProductAbstract] only %}` eingebunden. Erklärung: [16-widgets](../../references/16-widgets.md).

## Dateiliste (Zielpfad unter Projektroot)
```
src/Pyz/Yves/ProductBadgeWidget/Widget/ProductBadgeWidget.php
src/Pyz/Yves/ProductBadgeWidget/ProductBadgeWidgetFactory.php
src/Pyz/Yves/ProductBadgeWidget/ProductBadgeWidgetDependencyProvider.php
src/Pyz/Yves/ProductBadgeWidget/Theme/default/views/product-badge-widget/product-badge-widget.twig
```

## Platzhalter / Anpassung
- `ProductBadgeWidget` (Modul, Klasse, `getName()`), `product-badge-widget` (View-Ordner und Dateiname) ersetzen. `getName()` = Klassenname, im Scope eindeutig (sonst `UniqueWidgetNameException`).
- Konstruktor-Argumente = exakt die Werte aus `args [...]` im Twig-Tag (hier `int $idProductAbstract`; falscher Typ führt zu `WidgetRenderException`).
- Das Template erweitert `template('widget')` und füllt den Block `body`; `isVisible` blendet bei leerer Liste alles aus. CSS-Klassen (`list`, `label`) sind Beispiele aus dem Atomic-Frontend, bei Bedarf eigene Komponente anlegen (→ [15-yves-frontend-atomic](../../references/15-yves-frontend-atomic.md)).
- Die Klasse nutzt `Spryker\Yves\Kernel\Widget\AbstractWidget` (nicht die deprecated `AbstractWidgetPlugin`).

## Registrierung
1. Global: `src/Pyz/Yves/ShopApplication/ShopApplicationDependencyProvider.php`, Methode `getGlobalWidgets()` (Liste von Klassennamen, kein `new`):
   ```php
   use Pyz\Yves\ProductBadgeWidget\Widget\ProductBadgeWidget;
   ...
   protected function getGlobalWidgets(): array
   {
       return [
           // ...
           ProductBadgeWidget::class,
       ];
   }
   ```
   Alternativ seitenspezifisch: Klasse in der Widget-Liste des Page-Moduls (z. B. `ProductDetailPageDependencyProvider::getProductDetailPageWidgetPlugins()`, im Release standardmäßig leer) und diese Liste im Controller an `$this->view($data, $widgets, $template)` übergeben. Der Provider des Page-Moduls muss dafür im Pyz-Modul überschrieben werden.
2. Einbinden in Twig (Beispiel Produktdetailseite): **Im Release 202610.0 überschreibt Pyz die PDP bereits** (`src/Pyz/Yves/ProductDetailPage/Theme/default/views/pdp/pdp.twig`, erbt vom Core-`pdp` und nutzt `image-gallery`, nicht `product-carousel`). Das Widget live im Block `title` hinter `</h1>` ergänzen (verifiziert):
   ```twig
   {% widget 'ProductBadgeWidget' args [data.product.idProductAbstract] only %}{% endwidget %}
   ```
   Eine Kopie von `product-carousel.twig` im Pyz-Modul bleibt wirkungslos, solange die Pyz-PDP den Core-`product-carousel` nicht einbindet (live ausprobiert: keine Ausgabe). Hat das Projekt keine eigene PDP, Core-Template nicht in `vendor/` ändern, sondern gleichnamige Datei im Pyz-Modul anlegen, z. B. `src/Pyz/Yves/ProductDetailPage/Theme/default/components/molecules/product-carousel/product-carousel.twig` (Kopie des Core-Templates; Core nutzt dort `{% widget 'ProductAbstractLabelWidget' args [data.product.idProductAbstract] only %}`) und ergänzen:
   ```twig
   {% widget 'ProductBadgeWidget' args [data.product.idProductAbstract] only %}{% endwidget %}
   ```
   Ist das Widget nicht registriert, rendert der Tag stillschweigend nichts. Debug: `{{ widgetGlobalExists('ProductBadgeWidget') ? 'ja' : 'nein' }}`.

## Befehle danach
`cache:empty-all` und `npm run yves` `[live verifiziert (202610.0)]` (Widget im Shop installiert und gerendert)
```bash
docker/sdk console cache:empty-all                         # Zed-Konsole
docker/sdk cli npm run yves                                # nur falls eigenes SCSS/TS ergänzt wurde (Annahme, siehe Referenz 15)
```
Neue Twig-Dateien in Pyz-Modulen werden im Dev-Betrieb meist direkt gefunden; bei Problemen Twig-Cache leeren bzw. Rebuild (`docker/sdk up --build`).

## Verwendete Quelle (Vorbild)
- `src/Pyz/Yves/CustomerFullNameWidget/` (Widget, Factory, DependencyProvider, Twig-View, Registrierung in `getGlobalWidgets()`)
- `vendor/spryker/kernel/src/Spryker/Yves/Kernel/Widget/AbstractWidget.php`, `Dependency/Widget/WidgetInterface.php`
- `vendor/spryker-shop/shop-ui/src/SprykerShop/Yves/ShopUi/Theme/default/templates/widget/widget.twig`

## Verifikationsstatus
Basisklasse, Interface (`getName()`, `getTemplate()`, `addParameter()`), Twig-Basis-Template und Registrierungsmethode `getGlobalWidgets()` gegen `vendor/` und `src/Pyz` geprüft. Durchgespielt im Shop 202610.0: Registrierung in `getGlobalWidgets()`, Einbindung in `pdp.twig`; die Produktseite `/en/hdmi-cable-1` (Abstract 4) rendert `<li class="list__item" data-qa="product-badge-new">`, Abstracts ohne Badge (`/en/computer-6`) rendern nichts. `code:sniff:style` Exit 0, PHPStan Level 6 ohne Befund. `[live verifiziert (202610.0)]`
