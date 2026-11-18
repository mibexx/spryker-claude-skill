# Architektur-Grundlagen: Applikationen, Schichten, Core vs. Pyz (Recherche T-01)

> Status: Release b2b-demo-shop 202610.0 (spryker/kernel 3.87.1). `[code-verifiziert, Doku nicht abgeglichen]`: Die Doku-Seiten (modules-and-application-layers, code-buckets, module-api) waren nur als Kurzfassung bzw. gar nicht abrufbar. Aussagen stammen aus `vendor/` und `src/Pyz`. Lücken: Rolle von `public/Zed` und `public/Glue` (Legacy-Einstiege) nicht geprüft, `PriceUS` als Code-Bucket-Beispiel nicht geprüft. Die Befehle wurden im Container ausgeführt `[live verifiziert (202610.0)]`.

## Zweck
Orientierung, bevor Code geschrieben wird: welche Applikation/Schicht ist zuständig, welche Aufrufwege sind erlaubt, wie findet Spryker Pyz- vor Core-Klassen. Folgethemen: neues Modul [02](02-projektmodul.md), Core erweitern [03](03-core-erweitern.md), Konfiguration [06](06-konfiguration.md), Transfer [04](04-transfer.md), Persistenz [05](05-propel-persistenz.md).

## Konzepte

### Applikationen (Einstiegspunkte)
Jede Applikation hat `public/<App>/index.php` mit eigenem Bootstrap und eigenem Endpoint in `deploy.yml` (`groups: … applications:`). Ablauf der Applikations-Einstiege (Yves, Backoffice, Glue, …): `Environment::initialize()` -> `ErrorHandlerEnvironment::initialize()` -> `new XBootstrap()` -> `->boot()->run()`. Ausnahme `public/Configurator/index.php`: kein `Environment::initialize()` und kein Bootstrap, es lädt `vendor/autoload.php`, ruft `new ConfiguratorPage()` und sendet `->render()` direkt.

| deploy.yml | Einstieg | Bootstrap | `APPLICATION` |
|---|---|---|---|
| `yves` (Storefront) | `public/Yves/index.php` | `Pyz\Yves\ShopApplication\YvesBootstrap` (erbt `SprykerShop\Yves\ShopApplication\Bootstrap\YvesBootstrap`) | `YVES` |
| `backoffice` (Zed-UI) | `public/Backoffice/index.php` | `Spryker\Zed\Application\Communication\Bootstrap\BackofficeBootstrap` | `ZED` |
| `backend-gateway` (Zed-Gateway, intern) | `public/BackendGateway/index.php` | `Spryker\Zed\Application\Communication\Bootstrap\BackendGatewayBootstrap` | `ZED` |
| `zed` (Legacy) | `public/Zed/index.php` | `[Annahme]` kombinierter Legacy-Einstieg, nicht geprüft | `ZED` |
| `glue` (Legacy) | `public/Glue/index.php` | nicht geprüft, siehe [12](12-glue-storefront.md)/[13](13-glue-backend.md) | |
| `glue-storefront` | `public/GlueStorefront/index.php` | `Pyz\Glue\GlueApplication\Bootstrap\GlueStorefrontApiBootstrap` | `GLUE_STOREFRONT` |
| `glue-backend` | `public/GlueBackend/index.php` | siehe [13](13-glue-backend.md) | |

Weitere Ordner: `public/BackendApi/`, `public/Configurator/`.

### Sechs Namespace-Schichten pro Modul
`src/Spryker/<Layer>/<Modul>` mit Layer = **Yves, Zed, Glue, Client, Service, Shared** (Beleg: `vendor/spryker/kernel/src/Spryker/{Yves,Zed,Glue,Client,Service,Shared}/Kernel/`). `src/Pyz/` spiegelt dieselben sechs Ordner.

- **Yves**: Storefront (Controller, Twig, Plugins). Keine eigene DB, keine Business-Logik.
- **Zed**: Back Office, Business-Logik, Datenbank (Propel), Gateway für Yves/Glue.
- **Glue**: REST/JSON-API.
- **Client**: Zugriffsschicht für Yves/Glue auf Storage (Redis), Search und Zed (HTTP-RPC via `ZedRequest`), siehe [08](08-client-storage-search.md).
- **Service**: zustandslose Helfer ohne Persistenz, von allen Schichten nutzbar (z. B. `src/Pyz/Service/Synchronization`).
- **Shared**: Konstanten, Config, Transfer-Definitionen; nur was mehrere Applikationen gleich brauchen.

### Erlaubte Kommunikationswege
- **Yves/Glue -> Client -> (Storage | Search | ZedRequest -> Zed-Gateway)**. Yves ruft nie Zed-Klassen oder Propel direkt. Beleg: `CustomerClient::registerCustomer()` -> `CustomerFactory::createZedCustomerStub()` -> `CustomerStub` -> `ZedRequestClient::call('/customer/gateway/...', $transfer)` -> `Spryker\Zed\Customer\Communication\Controller\GatewayController::registerAction()` -> `getFacade()`.
- **Zed-Modul -> Zed-Modul**: nur über die **Facade**, bereitgestellt im DependencyProvider. Die Core-Module kapseln den Zugriff mit einer Bridge (Core-Muster, erklärt in [02](02-projektmodul.md)); im Projekt holt der DependencyProvider die Facade direkt über den Locator. Nie auf Business-Modelle, Repositories, Entities oder QueryContainer eines fremden Moduls zugreifen.
- **Erweiterung statt Aufruf**: Das aufgerufene Modul definiert ein Plugin-Interface (oft in `*-extension`-Modulen), das Projekt hängt Plugins in den DependencyProvider ein, siehe [03](03-core-erweitern.md).
- **Transfer-Objekte** (`Generated\Shared\Transfer\*Transfer`) sind das einzige Datenformat über Modul- und Gateway-Grenzen, siehe [04](04-transfer.md).
- `[Annahme]` (Doku-gestützt): "untere Layer dürfen nicht von oberen abhängen"; durchgesetzt durch `spryker/architecture-sniffer` (`composer.json`), siehe [27](27-coding-guidelines.md).

### Layer eines Zed-Moduls (vier, nicht drei)
**Abweichung zur Doku:** Die Doku nennt drei Layer; im Release gibt es **vier**: Business, Communication, Persistence, **Presentation** (Twig-Templates, z. B. `vendor/spryker/country/src/Spryker/Zed/Country/Presentation/Index/`, `src/Pyz/Zed/Customer/Presentation/Mail/`).

```
Country/
├── CountryConfig.php, CountryDependencyProvider.php
├── Business/        CountryFacade(+Interface), CountryBusinessFactory, Modelle (privat)
├── Communication/   Controller/, Plugin/, Table/, CountryCommunicationFactory
├── Persistence/     CountryRepository, CountryEntityManager (je +Interface), CountryPersistenceFactory, Propel/ (Schema)
└── Presentation/    Twig-Templates der Controller
```
- **Communication**: Controller, Forms, Tables, Plugins; ruft nur `getFacade()`/`getFactory()`.
- **Business**: gesamte Fachlogik; Einstieg ausschließlich über die Facade, Modelle werden in der `BusinessFactory` instanziiert.
- **Persistence**: Lesen über `*Repository`, Schreiben über `*EntityManager` (Standard). `*QueryContainer` ist das ältere Muster (Resolver existieren weiter), `[Annahme]` für Neuentwicklung nicht empfohlen.
- Basisklassen: `vendor/spryker/kernel/src/Spryker/Zed/Kernel/{Business,Communication,Persistence}/`, Config `AbstractBundleConfig`, `AbstractBundleDependencyProvider`, Container `Spryker\Zed\Kernel\Container`.
- Yves: `Spryker\Yves\Kernel\AbstractFactory`, `AbstractPlugin`, `AbstractBundleConfig`, `AbstractBundleDependencyProvider`. Client: `Spryker\Client\Kernel\AbstractClient`, `AbstractFactory`, `AbstractDependencyProvider`. Service: `Spryker\Service\Kernel\AbstractService`, `AbstractServiceFactory`.

**Abweichung zur Doku:** Die Doku-Kurzfassung erwähnt Symfony Dependency Injection. Symfony DI ist als optionale Integration vorhanden (`vendor/spryker/application/src/Spryker/Shared/Application/Kernel.php`, Paket `spryker/container`); sie ist ohne `config/{APPLICATION}/bundles.php` und `services.php` inaktiv. Der Spryker-Container der Module ist `Spryker\Zed\Kernel\Container` (`set()`/`get()`).

### Namespace-Auflösung (ProjectNamespaces vor CoreNamespaces)
`config/Shared/config_default.php`:
```php
$config[KernelConstants::PROJECT_NAMESPACE] = 'Pyz';
$config[KernelConstants::PROJECT_NAMESPACES] = ['Pyz'];
$config[KernelConstants::CORE_NAMESPACES] = ['SprykerShop', 'SprykerEco', 'Spryker', 'SprykerSdk'];
```
- Core-Code ruft `getFactory()`, `getConfig()`, `getFacade()`, `getClient()` usw. Die Klasse wird **nicht** per `new` erzeugt, sondern über einen Resolver gesucht. Muster: `Spryker\Shared\Kernel\KernelConfig::getResolvableTypeClassNamePatternMap()`, z. B. `ZedFacade => \%s\Zed\%s\Business\%sFacade`, `ZedFactoryBusiness => ...\Business\%sBusinessFactory`, `ZedConfig => \%s\Zed\%s\%sConfig`.
- Kandidatenreihenfolge (`ClassNameCandidatesBuilder::buildClassNames()`): **zuerst alle Projekt-Namespaces**, dann Core-Namespaces in der Reihenfolge `SprykerShop, SprykerEco, Spryker, SprykerSdk`; nur bei **Projekt-Namespaces** (`addProjectClassNames()`) erst `<Modul><CodeBucket>`, dann `<Modul>`; die Core-Namespaces (`addCoreClassNames()`) erzeugen keine Code-Bucket-Varianten. Die erste existierende Klasse gewinnt (`AbstractClassResolver::canResolve()`).
- Folge: `src/Pyz/Zed/Customer/CustomerConfig.php` ersetzt `Spryker\Zed\Customer\CustomerConfig` automatisch, ohne Registrierung. Beleg: `src/Pyz/Zed/Customer/CustomerConfig.php`, `src/Pyz/Yves/CartPage/CartPageFactory.php`.
- Das Layout `src/Pyz/<Layer>/<Modul>/` muss exakt Layer- und Klassennamen des Core folgen, sonst greift der Resolver nicht (und fällt still auf den Core zurück).
- Nicht per Resolver gefundene Klassen (Plugins, Business-Modelle) werden über DependencyProvider/Factory ausgetauscht, siehe [03](03-core-erweitern.md).
- Cache: `KernelConstants::RESOLVABLE_CLASS_NAMES_CACHE_ENABLED` (Release: `true`), Datei `src/Generated/Shared/Kernel/Pyz/resolvableClassCache*.php`. Neue Pyz-Klassen sind bei aktivem Cache erst nach Neuaufbau sichtbar (`cache:class-resolver:build`, Befehlsname in `ResolvableClassCacheConsole` belegt).
- Namespace-Rollen: `Pyz` Projekt, `Spryker` Core (`vendor/spryker/*`), `SprykerShop` Storefront-Yves (`vendor/spryker-shop/*`), `SprykerEco`/`SprykerSdk` Eco/SDK. **Abweichung:** Ein Namespace `SprykerFeature` ist nicht konfiguriert, `vendor/spryker-feature/` existiert nicht.

### Code-Buckets
- Suffix, mit dem der Resolver eine store-/regionsspezifische Modulvariante bevorzugt: Modulnamen-Kandidaten `[<Modul><Bucket>, <Modul>]` (`ModuleNameCandidatesBuilder`). Beispiel im Release: `src/Pyz/Shared/PriceUS/`.
- Konfiguration: `src/SprykerConfig/CodeBucketConfig.php` (Namespace `SprykerConfig`, in `composer.json` gemappt, `extends AbstractCodeBucketConfig`): `getCodeBuckets()` = `['EU','US','DE','AT']` (bei `APPLICATION_ENV === 'docker.acp.dev'` die erlaubten Stores). Aktueller Bucket: Env `SPRYKER_CODE_BUCKET`, sonst `getDefaultCodeBucket()` (`@deprecated`, Pfadsegment der URL, `APPLICATION_REGION` oder erster Bucket). Ungültiger Bucket -> `InvalidCodeBucketException`.
- Zuordnung in `deploy.yml`: `endpoints: <domain>: region:` und `code-bucket` je Endpoint.
- Die Doku-Seite `code-buckets.html` war nicht abrufbar, Aussagen rein aus Code.

### Öffentliche vs. private Modul-API
- **Öffentlich** = mit `@api` annotierte Methoden in Facade, Client, Service, Plugin-Interfaces, Config-Gettern, Controller-Actions; dazu Transfer-Definitionen, `*Constants`, `*Extension`-Module. Facade-Methoden tragen `@api` + `{@inheritDoc}`, die Beschreibung steht im Interface.
- **Privat** = alles andere (Business-Modelle, Factories, Reader/Writer, Repositories, Core-Bridges). Nicht aufrufen. Ausnahme: Factory- und Config-Methoden dürfen im Projekt **überschrieben** werden (Erweiterungsweg, kein Aufrufweg).
- Deprecation: `@deprecated Use {@link ...} instead.` am Element, Entfernung erst im nächsten Major.

## Anleitung
1. Zuständigkeit klären: Storefront-Darstellung -> Yves; Fachlogik/DB -> Zed; Lesen aus Storage/Search -> Client; REST -> Glue.
2. Zugriffsweg wählen: Yves/Glue -> Client; Zed -> fremdes Zed-Modul -> Facade (direkt per Locator im DependencyProvider); Anpassung fremden Verhaltens -> Plugin/Config.
3. Pyz-Datei exakt nach Resolver-Muster ablegen (`src/Pyz/Zed/<Modul>/Business/<Modul>Facade.php` usw.).
4. Nach neuen/umbenannten Pyz-Klassen Resolver-Cache neu bauen (siehe Befehle).
5. Eigene öffentliche Methoden mit `@api` und Interface versehen.

Befehle `[live verifiziert (202610.0)]` (ausgeführt):
```bash
docker/sdk cli                                    # Shell im Container
docker/sdk cli console transfer:generate          # Transfers (siehe 04)
docker/sdk cli console cache:class-resolver:build # Resolver-Cache
```

## Beispiele

Projekt erweitert Core-Factory per Vererbung; `SprykerShop\Yves\CartPage` nutzt sie automatisch (`src/Pyz/Yves/CartPage/CartPageFactory.php`):
```php
declare(strict_types = 1);

namespace Pyz\Yves\CartPage;

use Pyz\Yves\CartPage\Plugin\Provider\CartItemsProductProvider;
use Pyz\Yves\CartPage\Plugin\Provider\CartItemsProductProviderInterface;
use SprykerShop\Yves\CartPage\CartPageFactory as SprykerCartPageFactory;

class CartPageFactory extends SprykerCartPageFactory
{
    public function createCartItemsProductsProvider(): CartItemsProductProviderInterface
    {
        return new CartItemsProductProvider(
            $this->getProductStorageClient(),
        );
    }
}
```
Yves -> Client -> Zed (`vendor/spryker/customer/src/Spryker/Client/Customer/Zed/CustomerStub.php`, Auszug):
```php
$customerResponseTransfer = $this->zedStub->call('/customer/gateway/has-customer-with-email-and-password', $customerTransfer);
```
Facade delegiert, enthält keine Logik (`vendor/spryker/country/src/Spryker/Zed/Country/Business/CountryFacade.php`, Auszug):
```php
/**
 * @method \Spryker\Zed\Country\Business\CountryBusinessFactory getFactory()
 */
class CountryFacade extends AbstractFacade implements CountryFacadeInterface
{
    /** {@inheritDoc} @api */
    public function hasCountry(string $iso2Code): bool
    {
        return $this->getFactory()->createCountryReader()->countryExists($iso2Code);
    }
}
```
Vollständiges Zed-Modul mit allen Layern: [../templates/zed-module/](../templates/zed-module/README.md).

## Fallstricke
- Pyz-Klasse mit falschem Namespace/Ordner (z. B. `Business/` fehlt): Resolver findet sie nicht, der Core wird still weiterverwendet.
- Aufruf von Business-Klasse/QueryContainer eines fremden Moduls oder Zugriff von Yves auf Zed/Propel: Schichtgrenze verletzt, bricht Upgrades.
- `vendor/` ändern: nie. Dateien in `src/Generated/` ändern: werden überschrieben.
- Aktiver Resolver-Cache: neue Pyz-Klassen greifen erst nach `cache:class-resolver:build`.
- Pyz-Subklassen dürfen Rückgabetypen nur kompatibel ergänzen (Core-Signaturen sind teils untypisiert), `[Annahme]` zu Einzelfällen.
- Modulname gleich einem Core-Modul: neue Klassen überschreiben den Core (siehe [02](02-projektmodul.md)).

## Checkliste
- [ ] Richtige Applikation/Schicht gewählt, Zugriff nur über Client (Yves/Glue) bzw. Facade (Zed)
- [ ] Pfad `src/Pyz/<Layer>/<Modul>/...` und Klassennamen exakt nach Resolver-Muster
- [ ] Kein Zugriff auf fremde Models/Repositories/QueryContainer
- [ ] Transfers als Datenformat über Modulgrenzen
- [ ] `@api` und Interface an eigenen öffentlichen Methoden
- [ ] Nichts in `vendor/` oder `src/Generated/` geändert
- [ ] Resolver-Cache nach neuen Klassen neu gebaut

## Deprecation-Hinweise
- `KernelConfig::getResolvableCacheFilePath()` ersetzt durch `getResolvableCacheFilePathPattern()`.
- `KernelConfig::getCurrentStoreName()` `[Deprecated]`; `resolveDynamicStoreMode()` liegt nicht in `KernelConfig`, sondern in `Spryker\Shared\Kernel\CodeBucket\Config\DefaultCodeBucketConfig` (`vendor/spryker/kernel/src/Spryker/Shared/Kernel/CodeBucket/Config/DefaultCodeBucketConfig.php:52`) und ist dort `[Deprecated]` ("Will be removed without replacement").
- `AbstractClassResolver::getClassPattern()` `[Deprecated]` (Pattern jetzt in `KernelConfig::getResolvableTypeClassNamePatternMap()`).
- `SprykerConfig\CodeBucketConfig::getDefaultCodeBucket()` `[Deprecated]` ("will be removed when environment configs are cleaned up").
- QueryContainer-Persistence: älteres Muster `[Annahme]`. Beispiele: `CustomerClient::confirmRegistration()`, `Customer\GatewayController::confirmRegistrationAction()`.

## Quellen
Doku (Basis `https://docs.spryker.com/docs/`): `dg/dev/architecture/modules-and-application-layers.html` (Kurzfassung, weicht bei Layern/DI vom Code ab), `dg/dev/architecture/code-buckets.html` (nicht abrufbar), `dg/dev/architecture/module-api/declaration-of-module-apis-public-and-private.html` (Kurzfassung). Nur gelistet, nicht gelesen: `conceptual-overview`, `programming-concepts`, `architectural-convention`, `technology-stack`.

Code: `vendor/spryker/kernel/src/Spryker/Shared/Kernel/{KernelConfig.php,ClassResolver/*,CodeBucket/Config/*}`, `vendor/spryker/kernel/src/Spryker/{Zed,Yves,Glue,Client,Service,Shared}/Kernel/`, `config/Shared/config_default.php`, `src/SprykerConfig/CodeBucketConfig.php`, `public/*/index.php`, `deploy.yml`, `vendor/spryker/country/src/Spryker/Zed/Country/`, `vendor/spryker/customer/src/Spryker/{Client,Zed}/Customer/`, `src/Pyz/Zed/Customer/`, `src/Pyz/Yves/CartPage/CartPageFactory.php`, `src/Pyz/Yves/ShopApplication/YvesBootstrap.php`.

Nachbarn: [02 Projektmodul](02-projektmodul.md), [03 Core erweitern](03-core-erweitern.md), [06 Konfiguration](06-konfiguration.md), [19 Multi-Store](19-multi-store-i18n.md), [25 Performance/Caching](25-performance-caching.md).
