# Core erweitern: Plugins, Extension Points, Vererbung, Konfiguration (Recherche T-03)

> Status: Release 202610.0 (spryker/kernel 3.87.1, spryker/oms 11.55.0). `[code-verifiziert, Doku nicht abgeglichen]`: Die Doku-Seiten (extend-spryker, development-strategies, extend-the-core, plugins) wurden gelesen, tragen aber den Stand 2021/Jan 2022; der Code des Releases hat Vorrang. Befehle `dev:plugin-usage:dump`, `cache:class-resolver:build`, `dev:ide-auto-completion:generate`, `code:sniff:architecture` wurden im Container ausgeführt `[live verifiziert (202610.0)]`. Lücken: keine Prioritäts-API für Plugin-Stacks gefunden `[Annahme]`: reine Array-Reihenfolge; Wirkung des Resolver-Caches aus Code abgeleitet `[Annahme]`; Nutzung des Configuration-Moduls (`getModuleConfig`) im Release ungeprüft.

## Zweck
Verhalten eines Core-Moduls ändern, ohne `vendor/` anzufassen und möglichst upgrade-sicher. Neue Module: [02](02-projektmodul.md). Konfigurationswerte/-dateien: [06](06-konfiguration.md). Upgrader/Evaluator: [28](28-upgradability.md).

## Konzepte

### Fünf Entwicklungsstrategien (Reihenfolge der Präferenz, Doku "Development strategies")
| # | Strategie | Upgrade-Support | Mechanik |
|---|---|---|---|
| 1 | Configuration | hoch | `Pyz\Zed\<Modul>\<Modul>Config extends Spryker\Zed\<Modul>\<Modul>Config`, Konstanten/Methoden überschreiben |
| 2 | Plug and play | hoch | Plugin im **Pyz-DependencyProvider** in einen Plugin-Stack eintragen/entfernen/ersetzen |
| 3 | Project modules | hoch | neues Pyz-Modul ([02](02-projektmodul.md)) |
| 4 | Module customization | reduziert, manuelle Prüfung pro Update | Core-Klasse per Vererbung/Komposition in Pyz ersetzen (Factory/Model/Facade) |
| 5 | Module replacement | kein Support | Composer `replace` eines Core-Moduls |

Doku-Hinweise: Bei Strategie 4 Modulversion mit `~` statt `^` pinnen, Komposition statt Vererbung erwägen. Fehlt ein Extension Point, beim Spryker-Account-Manager anfragen.

### Extension-Module und Plugin-Interfaces
- Ein `*-extension`-Modul enthält **nur Interfaces** (und ggf. Transfer-Definitionen), keine Logik; es vermeidet zirkuläre Abhängigkeiten zum Zielmodul. Im Release: 212 Verzeichnisse `vendor/spryker/*-extension`, dazu `vendor/spryker-shop/*-extension` (z. B. `cart-page-extension`).
- Beispiel: `vendor/spryker/calculation-extension/src/Spryker/Zed/CalculationExtension/Dependency/Plugin/CalculationPluginInterface.php`. Das Zielmodul stellt den Stack bereit (`CalculationDependencyProvider::QUOTE_CALCULATOR_PLUGIN_STACK`), die Business-Factory liest ihn über `getProvidedDependency()`.
- Ablage eines Plugins:

| App | Verzeichnis | Basisklasse |
|---|---|---|
| Zed | `Pyz\Zed\<Modul>\Communication\Plugin\<Zielmodul>\` | `Spryker\Zed\Kernel\Communication\AbstractPlugin` (`getFacade()`, `getFactory()`, `getConfig()`) |
| Yves | `Pyz\Yves\<Modul>\Plugin\<Zielmodul>\` | `Spryker\Yves\Kernel\AbstractPlugin` |
| Client | `Pyz\Client\<Modul>\Plugin\<Zielmodul>\` | `Spryker\Client\Kernel\AbstractPlugin` |

  Echte Beispiele: `src/Pyz/Zed/Oms/Communication/Plugin/Oms/InitiationTimeoutProcessorPlugin.php`, `src/Pyz/Yves/PriceWidget/Plugin/Twig/PriceModeTwigPlugin.php`, `src/Pyz/Client/ExampleProductSalePage/Plugin/Elasticsearch/Query/SaleSearchQueryPlugin.php`.
- Plugins delegieren nur an Facade/Factory des **eigenen** Moduls; keine Logik im Plugin.
- Immer das `*Extension`-Interface wählen; Interfaces im Modul selbst (z. B. `Spryker\Zed\Cart\Dependency\...PluginInterface`) werden zu `*Extension` migriert.

### Plugin-Stacks
Der Core-Provider hat je Stack `add<Stack>(Container)` (setzt Lazy-Closure) und `protected get<Stack>(): array` (Default, meist `[]`). Das Projekt überschreibt **nur** `get...Plugins()`. Beispiel Core (`vendor/spryker/oms/.../OmsDependencyProvider.php`):
```php
protected function addTimeoutProcessorPlugins(Container $container): Container
{
    $container->set(static::PLUGINS_TIMEOUT_PROCESSOR, function () {
        return $this->getTimeoutProcessorPlugins();
    });
    return $container;
}
protected function getTimeoutProcessorPlugins(): array { return []; }
```
- Reihenfolge: Array-Reihenfolge = Ausführungsreihenfolge (`[Annahme]`, kein Prioritätsparameter). Fachlich entscheidend z. B. in `CalculationDependencyProvider` (Doc-Block in `src/Pyz/Zed/Calculation/CalculationDependencyProvider.php`).
- Manche Stacks sind **assoziativ** (z. B. `ProductPageSearchDependencyProvider::getDataExpanderPlugins()`, Schlüssel aus mehreren Config-Klassen, in Pyz `ProductPageSearchConfig`, `ProductLabelSearchConfig`, `ProductReviewSearchConfig`, `ProductListSearchConfig` und `ProductImageConfig`, siehe `src/Pyz/Zed/ProductPageSearch/ProductPageSearchDependencyProvider.php:57-63`): Ersetzen durch denselben Schlüssel.
- Hinzufügen zu Core-Defaults: `array_merge(parent::getXPlugins(), [new MyPlugin()])` (Discount, Oauth, PriceProduct, CmsBlockGui). Komplette Liste in Pyz (Core liefert `[]`): Product, Customer, Calculation. Letzteres ist das **Standardmuster im b2b-demo-shop**.
- Entfernen/Ersetzen: keine Remove-API. (1) Stack komplett in Pyz ohne `parent::` definieren und das Plugin weglassen. (2) Bei `parent::`-Stacks filtern. Das Beispiel entfernt ein Plugin, das der Core-Provider tatsächlich selbst liefert (`getDecisionRulePlugins()` in `vendor/spryker/discount/.../DiscountDependencyProvider.php` enthält u. a. `Spryker\Zed\Discount\Communication\Plugin\DecisionRule\DayOfTheWeekDecisionRulePlugin`); das Filter-Muster selbst ist nicht aus dem Release:
```php
return array_values(array_filter(
    parent::getDecisionRulePlugins(),
    static fn ($plugin): bool => !$plugin instanceof \Spryker\Zed\Discount\Communication\Plugin\DecisionRule\DayOfTheWeekDecisionRulePlugin,
));
```
  Hinweis: Das `CustomerGroupDecisionRulePlugin` (`Spryker\Zed\CustomerGroupDiscountConnector\Communication\Plugin\DecisionRule\…`) liefert `parent::` **nicht**; es kommt im Release erst per `array_merge` in `src/Pyz/Zed/Discount/DiscountDependencyProvider.php` dazu und lässt sich dort einfach aus der Liste streichen. Ersetzen = Original entfernen und eigenes Plugin an **derselben Position** einfügen.
- Zusätzliche Abhängigkeiten im Pyz-Provider: `src/Pyz/Zed/Customer/CustomerDependencyProvider.php` überschreibt `provideCommunicationLayerDependencies()`, ruft `parent::` und setzt `FACADE_SALES`, `FACADE_NEWSLETTER` über `$container->set(KEY, fn(Container $c) => $c->getLocator()->sales()->facade())`. Direkter Facade-Zugriff über den Locator ist auf Projektebene der Standard, Bridges sind ein reines Core-Muster ([02](02-projektmodul.md)).

### Entscheidungsbaum
1. Geht es um einen **Wert** (Limit, Liste, Flag, Event-Liste)? -> Pyz-`<Modul>Config` erweitern (`src/Pyz/Zed/Customer/CustomerConfig.php`: `MIN_LENGTH_CUSTOMER_PASSWORD = 12`; `src/Pyz/Zed/Product/ProductConfig.php`: `array_merge(parent::...Events(), [...])`).
2. Gibt es einen **Extension Point** (Plugin-Stack, `*-extension`-Interface)? -> Plugin im Pyz-DependencyProvider.
3. Fehlt er? -> Spryker anfragen; Notlösung **Customization**: Factory in Pyz überschreiben und eigene Klasse einhängen.
4. Nur wenn das nicht passt: Module replacement (kein Upgrade-Support).

### Klassen in Pyz überschreiben
Auflösung rein per Namenskonvention (Projekt vor Core, Details [01](01-architektur.md)). `Pyz\Zed\Oms\Business\OmsFacade` wird **automatisch** statt der Core-Facade genutzt, ebenso Factory, Config, DependencyProvider.
- Pyz-Klasse **extends** die Core-Klasse gleichen Namens (Alias `use ... as Spryker...`).
- Neues Verhalten per **neue Factory-Methode** (`createXyz()`), nicht durch Kopieren von Core-Methoden. Jede überschriebene Core-Methode ist eine Upgrade-Stelle.
- Config-Zugriff über `$this->getConfig()` mit `@method \Pyz\Zed\<Modul>\<Modul>Config getConfig()`.
- Resolver-Cache nach neuen Pyz-Klassen neu bauen (`RESOLVABLE_CLASS_NAMES_CACHE_ENABLED` und `RESOLVED_INSTANCE_CACHE_ENABLED` sind im Release `true`).

## Anleitung
1. Entscheidungsbaum durchgehen (Config -> Plugin -> Customization -> Replacement).
2. **Config:** Pyz-Config anlegen/erweitern (`extends` Core-Config), nur benötigte Konstante/Methode überschreiben; vorher im Core prüfen, ob sie `private` oder durch `getModuleConfig()` ersetzt ist.
3. **Plugin:** passendes `*-extension`-Interface suchen (`ls vendor/spryker | grep -i extension`), Plugin unter `Communication/Plugin/<Zielmodul>/` anlegen, im Pyz-DependencyProvider in den richtigen Stack und an die richtige Position einhängen (bei Calculation Quote- **und** Order-Stack).
4. **Customization:** Pyz-Factory `extends` Core-Factory, neue `create*`-Methode, Facade/Interface in Pyz nur ergänzen. Modulversion auf `~` pinnen.
5. Cache neu bauen, Plugins prüfen.

Befehle `[live verifiziert (202610.0)]` (in `docker/sdk console list` registriert):
```bash
docker/sdk cli console dev:plugin-usage:dump [Modul]   # Plugin-Nutzung je DependencyProvider (PluginUsageFinderConsole)
docker/sdk cli console cache:class-resolver:build      # Resolver-Cache (ResolvableClassCacheConsole)
docker/sdk cli console dev:ide-auto-completion:generate  # IDE-Auto-Completion (Alias: dev:ide:generate-auto-completion)
docker/sdk cli console code:sniff:architecture         # siehe 27
```
Back Office: Seite `development/dependency-provider-plugin-usage` (Controller `DependencyProviderPluginUsageController`, CSV-Download laut Doku).

## Beispiele
Konfiguration überschreiben (`src/Pyz/Zed/Customer/CustomerConfig.php`, Auszug):
```php
namespace Pyz\Zed\Customer;

use Spryker\Zed\Customer\CustomerConfig as SprykerCustomerConfig;

class CustomerConfig extends SprykerCustomerConfig
{
    protected const PASSWORD_RESET_EXPIRATION_IS_ENABLED = true;
    protected const MIN_LENGTH_CUSTOMER_PASSWORD = 12;
    protected const MAX_LENGTH_CUSTOMER_PASSWORD = 128;

    /** {@inheritDoc} @return array<string> */
    public function getCustomerPasswordDenyList(): array
    {
        return ['qwerty'];
    }
}
```
Plugin schreiben (`src/Pyz/Zed/Oms/Communication/Plugin/Oms/InitiationTimeoutProcessorPlugin.php`, Kopf):
```php
namespace Pyz\Zed\Oms\Communication\Plugin\Oms;

use Spryker\Zed\Kernel\Communication\AbstractPlugin;
use Spryker\Zed\OmsExtension\Dependency\Plugin\TimeoutProcessorPluginInterface;

/**
 * @method \Pyz\Zed\Oms\Business\OmsFacadeInterface getFacade()
 * @method \Pyz\Zed\Oms\Communication\OmsCommunicationFactory getFactory()
 * @method \Pyz\Zed\Oms\OmsConfig getConfig()
 */
class InitiationTimeoutProcessorPlugin extends AbstractPlugin implements TimeoutProcessorPluginInterface
{
    public function getName(): string { return 'OmsTimeout/Initiation'; }
    // getLabel(OmsEventTransfer): string, calculateTimeout(...) delegiert an $this->getFacade()
}
```
Plugin registrieren (`src/Pyz/Zed/Oms/OmsDependencyProvider.php`):
```php
/**
 * @return array<\Spryker\Zed\OmsExtension\Dependency\Plugin\TimeoutProcessorPluginInterface>
 */
protected function getTimeoutProcessorPlugins(): array
{
    return [
        new InitiationTimeoutProcessorPlugin(),
    ];
}
```
Core-Stack erweitern (`src/Pyz/Zed/Discount/DiscountDependencyProvider.php`):
```php
protected function getDecisionRulePlugins(): array
{
    return array_merge(parent::getDecisionRulePlugins(), [
        new ShipmentCarrierDecisionRulePlugin(),
        new CustomerGroupDecisionRulePlugin(),
        // ...
    ]);
}
```
Customization, upgrade-freundliche Form: nur **ergänzen** (`src/Pyz/Zed/Oms/Business/`):
```php
class OmsBusinessFactory extends \Spryker\Zed\Oms\Business\OmsBusinessFactory
{
    public function createInitiationTimeoutCalculator(): TimeoutProcessorTimeoutCalculatorInterface
    {
        return new InitiationTimeoutCalculator();
    }
}

class OmsFacade extends \Spryker\Zed\Oms\Business\OmsFacade implements OmsFacadeInterface
{
    public function calculateInitiationTimeout(TimeoutProcessorTimeoutRequestTransfer $request): TimeoutProcessorTimeoutResponseTransfer
    {
        return $this->getFactory()->createInitiationTimeoutCalculator()->calculateTimeout($request);
    }
}
```
`OmsFacadeInterface` ist ein Pyz-Interface; ohne Interface sehen andere Module neue Facade-Methoden nicht.

Core-Model ersetzen = Strategie 4: `src/Pyz/Zed/CustomerAccess/Business/CustomerAccessBusinessFactory.php` überschreibt `createCustomerAccessFilter()` und `createCustomerAccessUpdater()` mit Pyz-Klassen. Bei Core-Änderung an diesen Signaturen bricht das Pyz-Modul, manuell prüfen.

Eigenes Calculator-Plugin (Vorlage, nicht aus dem Release), `src/Pyz/Zed/MyCalc/Communication/Plugin/Calculation/HandlingFeeCalculatorPlugin.php`:
```php
declare(strict_types = 1);

namespace Pyz\Zed\MyCalc\Communication\Plugin\Calculation;

use Generated\Shared\Transfer\CalculableObjectTransfer;
use Spryker\Zed\CalculationExtension\Dependency\Plugin\CalculationPluginInterface;
use Spryker\Zed\Kernel\Communication\AbstractPlugin;

/**
 * @method \Pyz\Zed\MyCalc\Business\MyCalcFacadeInterface getFacade()
 */
class HandlingFeeCalculatorPlugin extends AbstractPlugin implements CalculationPluginInterface
{
    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function recalculate(CalculableObjectTransfer $calculableObjectTransfer): void
    {
        $this->getFacade()->recalculateHandlingFee($calculableObjectTransfer);
    }
}
```
Registrierung in `src/Pyz/Zed/Calculation/CalculationDependencyProvider.php` in `getQuoteCalculatorPluginStack()` **und** `getOrderCalculatorPluginStack()` vor den Total-Calculatoren. Das Core-Interface deklariert `recalculate(CalculableObjectTransfer $calculableObjectTransfer);` ohne Rückgabetyp; `: void` ist zulässig.

Plugin entfernen (Vorlage): in beiden Stacks `new RefundableAmountCalculatorPlugin(),` löschen (im Release Zeile ~219 Quote und ~263 Order).

## Fallstricke
1. Core-Datei in `vendor/` ändern: geht bei `composer update` verloren, Upgrader kann nicht rebasen.
2. Pyz-Klasse mit abweichendem Namen/Pfad: Auflösung ist rein konventionsbasiert, sie greift nicht.
3. Plugin in falschen Stack: Calculation hat getrennte Quote- und Order-Stacks.
4. Plugin an falscher Position (Totals vor Aggregatoren).
5. Veraltetes Interface: `src/Pyz/Zed/ProductUrlCartConnector/Communication/Plugin/ProductUrlCartPlugin.php` implementiert `Spryker\Zed\Cart\Dependency\ItemExpanderPluginInterface` (`@deprecated Use {@link \Spryker\Zed\CartExtension\Dependency\Plugin\ItemExpanderPluginInterface}`) und ist im Release in **keinem** DependencyProvider registriert (Totcode) - nicht als Vorlage nehmen, `CartExtension`-Interface verwenden.
6. Doku-Rezept "Extend a core module that is used by another module" nutzt `$container[self::X]` und `self::provideBusinessLayerDependencies()` - **veraltet** gegenüber `$container->set(KEY, fn)` + `parent::`. Die Doku warnt selbst vor Drift zwischen Bridge- und Facade-Interface (nur mit Patch-Lock der Modulversion); auf Projektebene entfällt das Problem, weil dort keine Bridges gebaut werden.
7. Config-Konstante überschreiben, die im Core `private` ist oder durch `getModuleConfig()` ersetzt wurde.
8. Cache vergessen: nach neuen Überschreibungen `cache:class-resolver:build`, sonst altes Core-Verhalten `[Annahme]`.
9. Neue Methoden nur in der Pyz-Facade ohne Pyz-Interface: andere Module sehen sie nicht.
10. Direkter Zugriff auf Business-Klassen fremder Module statt Facade/Client/Service ([01](01-architektur.md)).
11. Plugin-Typen im PHPDoc des Stacks angeben (`@return array<\Spryker\Zed\XExtension\Dependency\Plugin\YPluginInterface>`), `@api` an Plugin-Methoden.

## Checkliste
- [ ] Reihenfolge Config -> Plugin -> Customization -> Replacement eingehalten
- [ ] Änderung in Pyz, nichts in `vendor/`
- [ ] `*Extension`-Interface (nicht das veraltete Modul-Interface) verwendet
- [ ] Plugin in richtigem Stack und an fachlich richtiger Position (Quote und Order bei Calculation)
- [ ] `array_merge(parent::..., [...])` bzw. bewusst kompletter Stack
- [ ] Plugin dünn (nur Delegation), `@method` und `@api` gesetzt
- [ ] Customization nur über neue Factory-Methoden; Modulversion auf `~` gepinnt
- [ ] Pyz-Facade-Interface gepflegt
- [ ] `declare(strict_types = 1);` und vollständige Typen
- [ ] Resolver-Cache neu gebaut, `dev:plugin-usage:dump` geprüft

## Deprecation- und Versionshinweise
- `AbstractBundleConfig::get(KEY, default)` -> `Spryker\Shared\Config\Config::getInstance()->get()`; **nicht verwechseln** mit dem neuen `getModuleConfig(string $key, ...)` (`protected`, `vendor/spryker/kernel/src/Spryker/Zed/Kernel/AbstractBundleConfig.php`): liest aus dem Modul `configuration` (nur wenn `ConfigurationFacadeInterface` existiert, sonst `$default`); im Release nicht installiert, siehe [06](06-konfiguration.md).
- `AbstractClassResolver::getClassPattern()` `[Deprecated]` (Patterns in `KernelConfig::getResolvableTypeClassNamePatternMap()`).
- `DependencyInjectorResolver`/DependencyInjectors `[Deprecated]` (Kommentar in `AbstractClassResolver::unsetCurrentCacheEntry()`): keine neuen Injectors, stattdessen Pyz-DependencyProvider.
- `Spryker\Zed\Cart\Dependency\ItemExpanderPluginInterface` `[Deprecated]`, Ersatz `CartExtension`-Interface.
- Doku-Redirect: `...extend-a-core-module-that-is-used-by-another.html` heißt jetzt `...-by-another-module.html`.

## Quellen
Doku (Basis `https://docs.spryker.com/docs/`): `dg/dev/backend-development/extend-spryker/extend-spryker.html`, `.../development-strategies.html`, `.../spryker-os-module-customisation/extend-the-core.html`, `.../extend-a-core-module-that-is-used-by-another-module.html`, `dg/dev/backend-development/plugins/plugins.html`, `.../plugins/get-an-overview-of-the-used-plugins.html`.

Code: `vendor/spryker/kernel/src/Spryker/Shared/Kernel/{KernelConfig.php,ClassResolver/**}`, `vendor/spryker/kernel/src/Spryker/Zed/Kernel/{AbstractBundleDependencyProvider,AbstractBundleConfig}.php`, `.../Communication/Console/ResolvableClassCacheConsole.php`, `vendor/spryker/development/src/Spryker/Zed/Development/Communication/Console/PluginUsageFinderConsole.php`, `.../Controller/DependencyProviderPluginUsageController.php`, `vendor/spryker/calculation-extension/`, `vendor/spryker/calculation/.../CalculationDependencyProvider.php`, `vendor/spryker/oms/.../OmsDependencyProvider.php`, `vendor/spryker/cart/src/Spryker/Zed/Cart/Dependency/ItemExpanderPluginInterface.php`, `config/Shared/config_default.php`, `src/Pyz/Zed/{Product,Customer,Calculation,Oms,Discount,CustomerAccess,CmsBlockGui,ProductPageSearch}/`, `src/Pyz/Zed/ProductUrlCartConnector/`.

Nachbarn: [01 Architektur](01-architektur.md), [02 Projektmodul](02-projektmodul.md), [06 Konfiguration](06-konfiguration.md), [28 Upgradability](28-upgradability.md).
