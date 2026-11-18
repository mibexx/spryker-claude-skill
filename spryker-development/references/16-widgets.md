# Widgets und Widget-Plugins (Recherche T-16)

> Status: Die Doku-Seiten `implementing-widgets-and-widget-plugins` und `modular-frontend` waren nur als Inhaltsverzeichnis abrufbar (keine Codebeispiele); alle Details sind am Code in 202610.0 belegt `[code-verifiziert, Doku nicht abgeglichen]`. Konsolenbefehle im Container ausgeführt (`twig:cache:warmer`, `cache:empty-all`, `transfer:generate`) `[live verifiziert (202610.0)]`; `npm`-Frontend-Build-Befehle ausgeführt `[live verifiziert (202610.0)]` ([15](15-yves-frontend-atomic.md), [30](30-docker-sdk-workflow.md)); das Replace-Muster „gleicher Name, Pyz-Klasse ersetzt Core-Klasse in `getGlobalWidgets()`“ ist aus dem Code abgeleitet `[Annahme]`.

## Zweck
Wiederverwendbare Yves-Bausteine (Klasse + Twig-View), die Daten über Clients holen und in beliebigen Templates per Tag/Funktion gerendert werden. Controller/Routing → [14](14-yves-controller-routing.md); Atomic-Frontend/Theme/Build → [15](15-yves-frontend-atomic.md); CMS-Content-Widgets → [17](17-cms.md); Clients → [08](08-client-storage-search.md).

## Konzepte
**Widget-Klasse:** `extends Spryker\Yves\Kernel\Widget\AbstractWidget`, erfüllt `Spryker\Yves\Kernel\Dependency\Widget\WidgetInterface` (`vendor/spryker/kernel/src/Spryker/Yves/Kernel/Dependency/Widget/WidgetInterface.php`):
```php
public static function getName(): string;      // eindeutiger Name für Twig
public static function getTemplate(): string;  // "@Modul/views/<name>/<name>.twig"
public function getParameters(): array;
```
- **Parameter** werden im **Konstruktor** berechnet und mit `$this->addParameter($name, $value)` abgelegt (fluent). Konstruktor-Argumente = exakt die Werte aus `args [...]` im Twig-Tag (`new $widgetClassName(...$arguments)`, `ShopApplication\Twig\Widget\WidgetFactory`).
- `AbstractWidget` bringt `getFactory()`, `getClient()`, `getConfig()` (Class-Resolver, `@method`-Annotation, Namenskonvention → [02](02-projektmodul.md)), `getLocale()`, `getGlobalContainer()`.
- Parameter sind **read-only** (`ArrayAccess`, `offsetSet` wirft `ReadOnlyException`); im Template `_widget.parameterName`.
- Name = Klassenname (Konvention), im Scope eindeutig, sonst `UniqueWidgetNameException`.

**Registrierung (Scopes, `WidgetContainerAwareTrait::addWidget`, prüft gegen `WidgetInterface`, sonst `InvalidWidgetException`):**
1. **Global:** `Pyz\Yves\ShopApplication\ShopApplicationDependencyProvider::getGlobalWidgets()` (`array<string>` Klassennamen, kein `new`). Core liefert `array_unique(array_merge(getGlobalWidgets(), getGlobalWidgetPlugins()))` unter `WIDGET_GLOBAL`. In jedem Template über `widget 'Name'` / `widgetGlobal('Name')` erreichbar.
2. **Seitenspezifisch:** Controller übergibt Liste an `$this->view($data, $widgetClassNames, $template)` (`View` ruft `addWidgets()`). Typisch `$this->getFactory()->getCartPageWidgetPlugins()` aus `CartPageDependencyProvider`; in 202610.0 sind diese Page-Listen im Standard **leer** (alles läuft über globale Widgets; der Methodenname enthält noch „WidgetPlugins“, erwartet aber Widget-Klassen).
3. **Verschachtelt:** Widget ruft im Konstruktor `$this->addWidget(Foo::class)` (Beispiel `Pyz\Yves\ProductSetWidget\Widget\ProductSetIdsWidget`); Lookup: zuerst letzter geöffneter Container (View/Widget), dann global.

**Twig-Syntax** (`WidgetTwigPlugin`, `WidgetTagTwigPlugin` in `Pyz\Yves\Twig\TwigDependencyProvider`):

| Syntax | Bedeutung |
|---|---|
| `{% widget 'Name' args [a, b] only %}{% endwidget %}` | rendert Widget mit Konstruktor-Argumenten |
| `... with { k: v }` | zusätzliche Template-Variablen |
| `... only` | Kontext nicht vererben (nur `_widget` + `with`); ohne `only` wird `$context` gemerged |
| `... use view('x', 'Modul')` | anderes Template statt `getTemplate()` |
| `{% elsewidget 'Other' args [...] %}` | Fallback, wenn erstes Widget nicht existiert |
| `{% nowidget %}...` | Inhalt, wenn kein Widget existiert |
| `{% widget expr %}` | Name aus Ausdruck/Instanz |
| `{{ widget('Name', a, b) }}`, `{{ widgetBlock('Name', 'block', a) }}`, `{{ widgetGlobal('Name', a) }}` | Funktionen (String) |
| `widgetExists('N')`, `widgetGlobalExists('N')` | Existenzprüfung |
| `{% set w = findWidget('Name', [args]) %}` | Instanz holen, nur Parameter nutzen |

Nicht registrierter Name → Tag liefert **leer** (kein Fehler). Exceptions im Widget → `WidgetRenderException` („Something went wrong in widget …“).

**Daten:** Factory + DependencyProvider des Widget-Moduls; Konstruktor ruft `$this->getFactory()->getXClient()`; Client per `$container->getLocator()->customer()->client()`. Kein Zed-/DB-Zugriff.

**Core-Widgets ersetzen/erweitern:**
- Ersetzen: Pyz-Klasse `extends` das Core-Widget, behält `getName()` (oder überschreibt `getTemplate()`) und wird in Pyz `getGlobalWidgets()` **anstelle** der Core-Klasse eingetragen (beide → `UniqueWidgetNameException`) `[Annahme]`. Im Release gibt es kein Replace-Beispiel; Pyz `MenuItemCompanyWidget` hat einen **neuen** Namen und läuft parallel zu `CompanyMenuItemWidget`.
- Nur Template: gleichnamige Datei unter `src/Pyz/Yves/<Modul>/Theme/default/views/<name>/<name>.twig` (Pyz hat Vorrang vor `vendor/spryker-shop`, → [15](15-yves-frontend-atomic.md)).
- Factory/Client/Config des Core-Widgets lösen per Class Resolver (Pyz → SprykerShop) auf; Pyz-Widget mit `@method \SprykerShop\Yves\CompanyWidget\CompanyWidgetFactory getFactory()` nutzt die Core-Factory.

**Cache-Key-Strategie:** Widget-Instanzen werden pro Request gecacht (`WidgetFactory::$widgetCache`), Key `md5(Klasse . serialize(args))` mit Sonderbehandlung für `QuoteTransfer`/`ItemTransfer` (`StrategyCacheKeyGenerator`). Eigene Strategie: Plugin mit `generateCacheKey(array): ?string` (`null` = kein Caching) und `getWidgetClassName(): string` (`WidgetCacheKeyGeneratorStrategyPluginInterface`), Registrierung in `ShopApplicationDependencyProvider::getWidgetCacheKeyGeneratorStrategyPlugins()` (Beispiel `CartItemNoteFormWidgetCacheKeyGeneratorStrategyPlugin`). Nötig bei zustandsabhängigen Parametern (Session/Kunde) und mehrfachem Aufruf mit gleichen Args.

## Anleitung
Neues Widget (Template [widget](../templates/widget/README.md)):
1. Modul `src/Pyz/Yves/<Name>Widget/` mit `Widget/<Name>Widget.php`, Factory, DependencyProvider ([02](02-projektmodul.md)).
2. `extends AbstractWidget`, `getName()`, `getTemplate()`, Konstruktor setzt Parameter über `addXParameter()` mit Konstanten.
3. Clients nur über DependencyProvider/Factory, typisiert.
4. Twig-View `Theme/default/views/<name>/<name>.twig`: `{% extends template('widget') %}`, `{% define data = {...} %}`, Inhalt in `{% block body %}`; Sichtbarkeit über `data.isVisible` (Basis `shop-ui/.../templates/widget/widget.twig`).
5. Klasse in `getGlobalWidgets()` eintragen (oder über `view()` seitenspezifisch).
6. Im Template einbinden: `{% widget 'Name' args [...] only %}{% endwidget %}`, ggf. `{% nowidget %}`. Core-Templates per gleichnamiger Pyz-Datei überschreiben, nicht in `vendor/` editieren.
7. Cache leeren, Frontend-Build nur bei eigenem SCSS/TS.

Befehle (Standard-Workflow, alle ausgeführt `[live verifiziert (202610.0)]`; `docker/sdk up --build` braucht ein TTY und löscht `src/Generated`, danach läuft die Sektion `build` neu):
```bash
docker/sdk cli vendor/bin/console transfer:generate   # falls neue Transfers genutzt werden
docker/sdk cli vendor/bin/console cache:empty-all     # Twig-/Class-Resolver-Cache
docker/sdk cli npm run yves                           # nur bei SCSS/TS in components/ (-> 15)
docker/sdk up --build                                      # Rebuild bei neuen Modulen/Theme-Dateien
```

## Beispiele
Vollständiges Modul in [templates/widget](../templates/widget/README.md). Echtes Release-Beispiel `src/Pyz/Yves/CustomerFullNameWidget/`:

`Widget/CustomerFullNameWidget.php`
```php
namespace Pyz\Yves\CustomerFullNameWidget\Widget;

use Spryker\Yves\Kernel\Widget\AbstractWidget;

/**
 * @method \Pyz\Yves\CustomerFullNameWidget\CustomerFullNameWidgetFactory getFactory()
 */
class CustomerFullNameWidget extends AbstractWidget
{
    protected const PARAMETER_CUSTOMER_FULL_NAME = 'customerFullName';

    public static function getName(): string { return 'CustomerFullNameWidget'; }

    public static function getTemplate(): string
    {
        return '@CustomerFullNameWidget/views/customer-full-name-widget/customer-full-name-widget.twig';
    }

    public function __construct()
    {
        $this->addCustomerFullNameParameter();
    }

    protected function addCustomerFullNameParameter(): void
    {
        $customerTransfer = $this->getFactory()->getCustomerClient()->getCustomer();

        $this->addParameter(
            static::PARAMETER_CUSTOMER_FULL_NAME,
            $customerTransfer->getFirstName() . ' ' . $customerTransfer->getLastName(),
        );
    }
}
```

Factory (`CustomerFullNameWidgetFactory extends Spryker\Yves\Kernel\AbstractFactory`): `getCustomerClient(): CustomerClientInterface` über `getProvidedDependency(CustomerFullNameWidgetDependencyProvider::CLIENT_CUSTOMER)`. DependencyProvider (`extends Spryker\Yves\Kernel\AbstractBundleDependencyProvider`, `provideDependencies(Container)`): `$container->set(static::CLIENT_CUSTOMER, function (Container $container) { return $container->getLocator()->customer()->client(); });`.

`Theme/default/views/customer-full-name-widget/customer-full-name-widget.twig`
```twig
{% extends template('widget') %}

{% define data = {
    customerFullName: _widget.customerFullName
} %}

{% block body %}
    {{ data.customerFullName }}
{% endblock %}
```

Registrierung (`src/Pyz/Yves/ShopApplication/ShopApplicationDependencyProvider.php`):
```php
protected function getGlobalWidgets(): array
{
    return [
        // ...
        CustomerFullNameWidget::class,
    ];
}
```

Verwendung (echt, `ShopUi/.../user-navigation.twig`, `page-layout-main.twig`):
```twig
<span class="{{ config.name }}__text">{% widget 'CustomerFullNameWidget' only %}{% endwidget %}</span>

{% set customerFullNameWidget = findWidget('CustomerFullNameWidget') %}
{% set customerFullName = customerFullNameWidget.customerFullName %}
```

Widget mit Argumenten (Core `CartNoteFormWidget`): Konstruktor `__construct(QuoteTransfer $quoteTransfer)` mit `addParameter('cartNoteForm', ...)`; Aufruf `{% widget 'CartNoteFormWidget' args [data.cart] only %}{% endwidget %}`. Fallback/`use`/`nowidget` (Core `page-layout-cart.twig`):
```twig
{% widget 'CartOperationsWidget' args [data.cart] use view('cart-operations-title', 'MultiCartWidget') only %}
{% nowidget %}
    ...
{% endwidget %}
```
Die `elsewidget '...WidgetPlugin'`-Zeilen in Core-Templates sind BC-Fallbacks, in neuem Code weglassen.

## Fallstricke
1. Widget nicht in `getGlobalWidgets()`/View registriert → Tag rendert still nichts. Debug `{{ widgetGlobalExists('Name') ? 'ja' : 'nein' }}`.
2. Doppelter Name (Core-Widget und Pyz-Ersatz beide registriert) → `UniqueWidgetNameException`.
3. Klasse implementiert weder `WidgetInterface` noch das alte Plugin-Interface → `InvalidWidgetException`.
4. `args [...]` wird 1:1 als Konstruktor-Argumente gespreizt; falscher Typ → `TypeError`, verpackt als `WidgetRenderException`.
5. `only` vergessen → gesamter Kontext leckt ins Widget-Template; `only` gesetzt, aber Variable benötigt → über `with {…}` übergeben. Konvention: immer `only`.
6. `getTemplate()` muss `@<Modul>/views/<name>/<name>.twig` sein, Datei in `Theme/default/views/<name>/`.
7. Logik im Template; Zugriff über Schichtgrenzen (nur Clients, nie Zed-Facades/Propel).
8. Viel Arbeit im Konstruktor ohne Cache-Strategie → mehrfach pro Seite (Storage-/Zed-Aufrufe in Listen).
9. Parameter im Template schreiben → `ReadOnlyException`.
10. Anti-Patterns, die den Upgrader brechen: Core-Widgets/-Twigs in `vendor/` editieren, Core- und Pyz-Klasse mit gleichem Namen parallel registrieren, Core-Twig-Blöcke kopieren statt zu erweitern ([15](15-yves-frontend-atomic.md), [28](28-upgradability.md)).

**Deprecations (Code hat Vorrang vor Doku)**

| Alt `[Deprecated]` | Ersatz |
|---|---|
| `Spryker\Yves\Kernel\Widget\AbstractWidgetPlugin` | `AbstractWidget` |
| `Spryker\Yves\Kernel\Dependency\Plugin\WidgetPluginInterface` (`initialize()`, `NAME`) | `WidgetInterface` |
| `Spryker\Yves\Kernel\Widget\WidgetFactory` | `SprykerShop\Yves\ShopApplication\Twig\Widget\WidgetFactory` |
| `ShopApplicationDependencyProvider::getGlobalWidgetPlugins()`, `PLUGIN_GLOBAL_WIDGETS`, `addGlobalWidgetPlugins()` | `getGlobalWidgets()`, `WIDGET_GLOBAL` |
| `ShopApplicationDependencyProvider::PLUGIN_APPLICATION`/`addApplicationPlugin()` | `GLOBAL_CONTAINER` |
| `AbstractWidget::getApplication()` | `getGlobalContainer()` |
| `TokenParser\WidgetTagTokenParser` + `Node\WidgetTagTwigNode` | `WidgetTagTwigTokenParser` + `WidgetTagNode` |
| `WidgetTagServiceProvider`/`WidgetServiceProvider` | `WidgetTagTwigPlugin`/`WidgetTwigPlugin` |
| Core-Plugins wie `CartNoteQuoteWidgetPlugin` | jeweiliges `...Widget` |

`getCartPageWidgetPlugins`/`getProductDetailPageWidgetPlugins` sind nicht deprecated, nur irreführend benannt. Die Doku-Überschrift „Widgets and Widget Plugins“ suggeriert zwei gleichwertige Konzepte; im Code sind Widget-Plugins vollständig deprecated `[Annahme]` zur Doku.

## Checkliste
- [ ] `extends AbstractWidget`, `getName()` (= Klassenname, eindeutig), `getTemplate()`, Konstruktor setzt Parameter
- [ ] Clients nur über DependencyProvider/Factory, typisiert; `@method`-Annotation gepflegt
- [ ] Twig-View mit `extends template('widget')`, Block `body`, Pfad `Theme/default/views/<name>/`
- [ ] In `getGlobalWidgets()` (oder per `view()`) registriert
- [ ] Aufruf mit `args [...] only`, ggf. `nowidget`
- [ ] Zustandsabhängig? Cache-Key-Strategie geprüft
- [ ] Cache geleert, Frontend-Build nur falls nötig
- [ ] Keine deprecated Widget-Plugin-Basisklassen, keine Core-Änderungen

## Quellen
Doku (nur Themenübersicht abrufbar): `https://docs.spryker.com/docs/dg/dev/backend-development/yves/implementing-widgets-and-widget-plugins.html`, `https://docs.spryker.com/docs/dg/dev/backend-development/yves/modular-frontend.html`.

Quellcode: `vendor/spryker/kernel/src/Spryker/Yves/Kernel/Widget/{AbstractWidget,AbstractWidgetPlugin,WidgetContainerAwareTrait,WidgetContainerRegistry}.php`, `.../Dependency/Widget/WidgetInterface.php`, `.../Dependency/Plugin/WidgetPluginInterface.php`, `.../View/View.php`, `.../Controller/AbstractController.php::view`; `vendor/spryker-shop/shop-application/src/SprykerShop/Yves/ShopApplication/{ShopApplicationDependencyProvider,ShopApplicationFactory}.php`, `.../Plugin/Twig/{WidgetTwigPlugin,WidgetTagTwigPlugin}.php`, `.../Twig/Widget/*`; `vendor/spryker-shop/shop-application-extension/.../WidgetCacheKeyGeneratorStrategyPluginInterface.php`; `vendor/spryker-shop/shop-ui/.../Theme/default/templates/widget/widget.twig`; `vendor/spryker-shop/cart-note-widget/*`; `vendor/spryker-shop/cart-page/.../CartPageDependencyProvider.php`; `src/Pyz/Yves/ShopApplication/ShopApplicationDependencyProvider.php`, `src/Pyz/Yves/Twig/TwigDependencyProvider.php`; `src/Pyz/Yves/CustomerFullNameWidget/**`, `src/Pyz/Yves/ProductSetWidget/Widget/ProductSetIdsWidget.php`, `src/Pyz/Yves/CompanyWidget/Widget/MenuItemCompanyWidget.php`.

Nachbarn: [14 Controller/Routing](14-yves-controller-routing.md), [15 Atomic Frontend](15-yves-frontend-atomic.md), [17 CMS](17-cms.md), Template [widget](../templates/widget/README.md).
