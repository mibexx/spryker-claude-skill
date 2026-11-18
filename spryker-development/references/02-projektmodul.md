# Neues Projektmodul anlegen: Facade, Factory, DependencyProvider, Config (Bridges nur als Core-Muster) (Recherche T-02)

> Status: Release 202610.0 (spryker/kernel 3.87.1). `[code-verifiziert, Doku nicht abgeglichen]`: Doku-Seiten (create-modules, facade, dbc, factory, business-models) nur grob bzw. gar nicht abrufbar. Spryk-Definitionen aus `vendor/spryker-sdk/spryk/spryk.phar` gelesen, `spryk-dump`, `spryk-run --dry-run` und ein echter `spryk-run AddModuleDefault` in einem Wegwerfmodul `[live verifiziert (202610.0)]` (Modul danach gelöscht, siehe Fallstrick unten). Lücken: genaue Spryk-Argumentwerte (`--mode`) und interaktive Rückfragen, Cache-Leerbefehle siehe [25](25-performance-caching.md). Das Laufbeispiel ist das Modul **ProductBadge** (Code vollständig in [../templates/zed-module/](../templates/zed-module/README.md)).

## Zweck
Ein eigenes Zed-Modul unter `src/Pyz/Zed/<Modul>/` anlegen: Pflicht- und Optionalklassen, Namensauflösung, Abhängigkeiten, Config, Umgang mit fremden Modulen (direkter Locator-Zugriff, Bridges nur als Core-Muster), Spryk-Gerüst. Anpassung bestehender Core-Module: [03](03-core-erweitern.md). Transfer: [04](04-transfer.md), Propel: [05](05-propel-persistenz.md), Config-Dateien: [06](06-konfiguration.md).

## Konzepte

### Aufbau und Registrierung
Es gibt **keine** Registrierung (kein `composer.json` pro Modul, keine Service-Konfiguration). `Pyz\` ist per PSR-4 auf `src/Pyz/` gemappt, `Pyz` steht in `KernelConstants::PROJECT_NAMESPACES`. Klassen werden per Namensmuster aufgelöst. Harte Pflichtklassen gibt es nicht, nur was die Layer brauchen:

| Datei | Basisklasse | Wann |
|---|---|---|
| `Business/<M>Facade.php` + `Interface` | `Spryker\Zed\Kernel\Business\AbstractFacade` | öffentliche API, `@api` |
| `Business/<M>BusinessFactory.php` | `Spryker\Zed\Kernel\Business\AbstractBusinessFactory` | sobald die Facade Modelle nutzt |
| `Business/<Ordner>/<Model>.php` + `Interface` | - | die Logik |
| `<M>DependencyProvider.php` | `Spryker\Zed\Kernel\AbstractBundleDependencyProvider` | nur bei fremden Abhängigkeiten |
| `<M>Config.php` | `Spryker\Zed\Kernel\AbstractBundleConfig` | nur bei Konfiguration |
| `Dependency/Facade/<M>To<X>FacadeInterface` + `...Bridge` | - | **nur im Core**, im Projekt nicht anlegen (siehe unten) |
| `Persistence/<M>Repository` / `<M>EntityManager` (+Interface) | `AbstractRepository` / `AbstractEntityManager` | Lesen / Schreiben |
| `Persistence/<M>PersistenceFactory.php` | `AbstractPersistenceFactory` | Queries, Mapper |
| `Persistence/Propel/Schema/*.schema.xml` | - | siehe [05](05-propel-persistenz.md) |
| `Communication/Controller\|Plugin\|Console/...` | `AbstractController`, `AbstractPlugin`, `Console` | Zed-Aufrufe, Plugins |
| `Communication/<M>CommunicationFactory.php` | `AbstractCommunicationFactory` | wenn Communication Abhängigkeiten braucht |
| `src/Pyz/Shared/<M>/Transfer/<m>.transfer.xml` | - | siehe [04](04-transfer.md) |

Referenzmodul im Core: `vendor/spryker/cart-note/src/Spryker/Zed/CartNote/` (Facade, Factory, Bridge, EntityManager; die Bridge ist dort Core-Muster, im Projekt nicht nachbauen). Projekt-Pendant `src/Pyz/Zed/ExampleProductSalePage/` ist **kein Vorbild** (Facade erbt von `ProductFacade`, QueryContainer-Schiene).

### Auflösung per Konvention
Muster aus `KernelConfig::getResolvableTypeClassNamePatternMap()`: `ZedFacade -> ...\Business\%sFacade`, `ZedFactoryBusiness -> ...\Business\%sBusinessFactory`, `ZedFactoryCommunication -> ...\Communication\%sCommunicationFactory`, `ZedFactoryPersistence -> ...\Persistence\%sPersistenceFactory`, `ZedRepository`, `ZedEntityManager`, `ZedQueryContainer` (Legacy), `ZedConfig -> \%s\Zed\%s\%sConfig`, `ZedDependencyProvider -> \%s\Zed\%s\%sDependencyProvider`.
- Der Modulname kommt aus dem Namespace der **aufrufenden** Klasse (`ClassInfo`): `Pyz\Zed\Foo\Business\FooFacade` bekommt `Pyz\Zed\Foo\Business\FooBusinessFactory`. Kandidatenreihenfolge Projekt vor Core: siehe [01](01-architektur.md).
- Verdrahtung: `AbstractFacade::getFactory()` über `BusinessFactoryResolver`; `getRepository()`/`getEntityManager()` über `RepositoryResolverAwareTrait`/`EntityManagerResolverAwareTrait`; `AbstractRepository`/`AbstractEntityManager::getFactory()` lösen die `PersistenceFactory` auf; `AbstractFactory` liefert `getConfig()`, `getProvidedDependency()`, `getRepository()`, `getEntityManager()`, `getQueryContainer()`.
- Der **Typ** kommt nur aus der `@method`-PHPDoc (`@method \Pyz\Zed\Foo\Business\FooBusinessFactory getFactory()`), die Basismethoden sind untypisiert. Annotationen gehören in jede Klasse.
- `[Deprecated]` `Spryker\Zed\Kernel\ClassResolver\Factory\FactoryResolver` (ersetzt durch `BusinessFactoryResolver`/`CommunicationFactoryResolver`/`PersistenceFactoryResolver`): pro Layer eine eigene Factory.

### DependencyProvider
Pro Layer eine überschreibbare Methode (`AbstractBundleDependencyProvider`): `provideBusinessLayerDependencies(Container)`, `provideCommunicationLayerDependencies(Container)`, `providePersistenceLayerDependencies(Container)`. Jeder Layer hat **seinen eigenen Container**; was nur im Business-Layer registriert ist, liefert `PersistenceFactory::getProvidedDependency()` nicht (`ContainerKeyNotFoundException`).
- Werte sind Closures (beim ersten `get()` aufgerufen und gecacht). Für "jedes Mal neu" (Propel-Query, mutable Objekte): `$container->factory(function () {...})`.
- Fremde Facades: `$container->getLocator()->product()->facade()`; Clients `->client()`, Services `->service()`. Im Projekt ohne Bridge, der Factory-Getter wird auf das Interface des Fremdmoduls typisiert.
- Konstantennamen sind Konvention: `FACADE_<MODUL>`, `CLIENT_<MODUL>`, `SERVICE_<MODUL>`, `PLUGINS_<X>`, `PROPEL_QUERY_<X>`.
- Plugin-Stacks siehe [03](03-core-erweitern.md). Der alte `DependencyInjector`-Mechanismus ist Legacy (`[Annahme]`: in neuen Modulen nicht verwenden).

### Config
`Spryker\Zed\Kernel\AbstractBundleConfig`: `get($key, $default)` liest `config/Shared/config_*.php`. Die Config wird als Konstruktorparameter der **Modelle** über die Factory übergeben (`new Model($this->getConfig())`), Modelle rufen sie nicht selbst. Werte und Konstanten: [06](06-konfiguration.md).

### Fremde Module im Projekt: direkter Locator-Zugriff, Bridges nur im Core
- **Projektregel:** Im Projekt (`src/Pyz`) gibt es keine Bridges. Der DependencyProvider holt die fremde Facade, den Client oder Service direkt über den Locator (`$container->getLocator()->product()->facade()`), der Factory-Getter ist auf das Interface des Fremdmoduls typisiert (`Spryker\Zed\Product\Business\ProductFacadeInterface`, `Spryker\Client\Storage\StorageClientInterface`). So arbeiten die `src/Pyz`-DependencyProvider im Release (`src/Pyz/Zed/Customer/CustomerDependencyProvider.php`, `src/Pyz/Zed/DataImport/DataImportDependencyProvider.php`) und die Templates (`zed-module`, `publisher-and-synchronization`, `back-office-page`).
- **Core-Muster (nur zur Erklärung):** Core-Module kapseln jede fremde Facade/Client/Service in `Dependency/...Interface` + `...Bridge` (rund 868 Facade-Bridges im Release). Grund: Das Modul deklariert nur benötigte Methoden und fängt Major-Wechsel des Fremdmoduls ab. Der Bridge-Konstruktor ist **untypisiert** (`$quoteFacade`), um keine harte Abhängigkeit zu erzeugen; getypt wird per PHPDoc. Beispiel: `vendor/spryker/cart-note/src/Spryker/Zed/CartNote/Dependency/Facade/CartNoteToQuoteFacadeBridge.php`. Spryk bietet Bridges nur im Core-Modus an (`AddDependencyBridgeMethod`, `mode: core`).
- Der Architecture Sniffer kennt dafür im **Project-Ruleset** die `ProjectNoBridgeRule`. Standardmäßig läuft `code:sniff:architecture` mit dem Core-Ruleset (`DevelopmentConfig::getArchitectureSnifferRuleset()`, in Pyz nicht überschrieben) und meldet Bridges daher nicht. Das Project-Ruleset ruft man so auf: `vendor/bin/phpmd src/Pyz text vendor/spryker/architecture-sniffer/src/Project/ruleset.xml` (siehe [27](27-coding-guidelines.md)).
- Niemals: fremde Modelle/Repositories/Entities/QueryContainer instanziieren, `new FooFacade()` oder `Locator::getInstance()` in Business-Modellen.

## Anleitung
1. Namen prüfen: `ls vendor/spryker | grep -i <slug>` und `ls src/Pyz/Zed`. Bei Namensgleichheit mit einem Core-Modul entsteht eine Erweiterung (siehe [03](03-core-erweitern.md)).
2. Gerüst anlegen: manuell oder per Spryk (unten).
3. Facade-Interface mit `@api` und Specification, Facade delegiert an BusinessFactory-Modelle.
4. BusinessFactory: `create*`-Methoden für Modelle, getypte `get*`-Getter für Dependencies, `@method`-Annotationen.
5. DependencyProvider: Konstante + `add...`-Methode je Dependency im **richtigen Layer**, zuerst `parent::provide...` aufrufen.
6. Config nur für Werte, Zugriff über Factory.
7. Persistenz: Repository (lesen), EntityManager (schreiben), PersistenceFactory (Query, Entity, Mapper) mit Propel-Schema aus [05](05-propel-persistenz.md).
8. Transfers in `Shared/<Modul>/Transfer/*.transfer.xml`, `transfer:generate` ([04](04-transfer.md)).
9. Resolver-Cache neu bauen, IDE-Auto-Completion regenerieren, Sniffer laufen lassen.

### Spryk-Gerüst
Werkzeug `vendor/spryker-sdk/spryk/` (`vendor/bin/spryk-run`, `spryk-dump`, `spryk-build`). Argumente: Spryk-Name, `targetModule` (Format `[Organisation.]Modul[.Layer]`), `dependentModule`; Optionen `--include-optional/-i`, `--dry-run/-d`, `--mode=project`, `--organization=Pyz`.
- `AddModuleDefault` (legt Modul, Client, Shared-Transfer-Schema, Configs, DependencyProvider, Facade, CommunicationFactory, EntityManager, Repository an), `AddZedBusinessFacade`, `AddZedBusinessFacadeMethod` (`facadeMethod`, `input`, `output`, `comment`), `AddZedBusinessFactory`, `AddZedDependencyProvider`, `AddZedPersistenceRepository`, `AddZedPersistenceEntityManager`, `AddZedPersistenceFactory`.
- **Falle:** Das Argument `extends` nutzt `ResolveExtends` und nimmt die **erste existierende Klasse** der Liste (zuerst die Core-Klasse gleichen Modulnamens). Ein Pyz-Modul mit Core-Modulnamen erbt dadurch von der Core-Facade.
- `AddDependencyBridgeMethod` nur `mode: core`; die älteren `AddZedDependency*Bridge`-Spryks sind `[Deprecated]` ("Use AddDependencyBridgeMethod instead", 46 deprecated Spryks insgesamt).
- `dev:module:create` und `dev:bridge:create` sind in der Zed-Befehlsliste von 202610.0 **nicht registriert** `[live verifiziert (202610.0)]` (die Klasse `ModuleCreateConsole` ist deprecated). Module daher manuell oder per Spryk anlegen, Bridges gar nicht (Projektregel).
- Eigene Spryks: `config/spryk/spryks/`, Templates `config/spryk/templates/` (im Projekt nicht vorhanden).

Befehle (alle ausgeführt `[live verifiziert (202610.0)]`):
```bash
docker/sdk cli vendor/bin/spryk-dump --level=all
docker/sdk cli vendor/bin/spryk-dump AddZedBusinessFacade                # Optionen eines Spryks
docker/sdk cli vendor/bin/spryk-run AddModuleDefault Pyz.ProductBadge --mode=project --organization=Pyz --dry-run
# Facade-Methode: Aufruf als EIN String (sonst verliert `docker/sdk cli` die Anführungszeichen), input = Typ + Variable, Typen voll qualifiziert, sonst landet der Namespace des Moduls davor:
docker/sdk cli "vendor/bin/spryk-run AddZedBusinessFacadeMethod --module=ProductBadge --organization=Pyz --mode=project --facadeMethod=createProductBadge --comment='Creates a product badge.' --input='\\Generated\\Shared\\Transfer\\ProductBadgeTransfer \$productBadgeTransfer' --output='\\Generated\\Shared\\Transfer\\ProductBadgeTransfer' --no-interaction"
docker/sdk cli console cache:class-resolver:build
docker/sdk cli console dev:ide-auto-completion:generate
docker/sdk cli console code:sniff:style src/Pyz/Zed/ProductBadge        # Projektcode per Pfad; `-m Pyz.X` ist falsch (meint vendor/pyz/x), siehe 27
docker/sdk cli console code:sniff:architecture -p 1 src/Pyz/Zed/ProductBadge   # Core-Ruleset, keine Bridge-Prüfung
docker/sdk cli vendor/bin/phpmd src/Pyz/Zed/ProductBadge text vendor/spryker/architecture-sniffer/src/Project/ruleset.xml   # Project-Ruleset inkl. ProjectNoBridgeRule
docker/sdk cli vendor/bin/phpstan                                       # phpstan.neon, Level 6
```
Argumentliste vor dem ersten Lauf mit `spryk-dump <Name>` und `--dry-run` prüfen `[live verifiziert (202610.0)]`. **Fallstricke (live):** `AddModuleDefault` legt Client, Zed, Shared und Test-Konfiguration an (ohne `--dry-run` auch Codeception-Build, Style-Fix, `propel:install`, `transfer:generate`) und schreibt eine **leere** `src/Pyz/Shared/<Modul>/Transfer/<modul>.transfer.xml` (`<transfers>` ohne `<transfer>`); danach scheitert `transfer:generate` mit `ErrorException: Undefined array key "transfer"`, bis man dort ein Transfer einträgt oder die Datei löscht. `AddZedBusinessFacadeMethod` ergänzt Facade, Interface und einen Test (`assertTrue(false)` als Platzhalter). Wegwerfmodul wieder löschen und `transfer:generate`, `transfer:entity:generate`, `transfer:databuilder:generate`, `cache:class-resolver:build` neu ausführen.

## Beispiele
Alle Dateien vollständig im Template [../templates/zed-module/](../templates/zed-module/README.md) (Pfad `src/Pyz/Zed/ProductBadge/...`, Dateikopf wie `src/Pyz/Zed/ExampleChart/Plugin/ExampleChart.php`, im Folgenden ohne Kopf). Kernstücke:

`Business/ProductBadgeFacadeInterface.php` (Auszug)
```php
interface ProductBadgeFacadeInterface
{
    /**
     * Specification:
     * - Requires `ProductBadgeTransfer.fkProductAbstract`, `badgeKey` and `label`.
     * - Throws `InvalidProductBadgeException` if the validation fails.
     * - Returns the persisted badge with `idProductBadge` set.
     *
     * @api
     */
    public function createProductBadge(ProductBadgeTransfer $productBadgeTransfer): ProductBadgeTransfer;

    /**
     * @api
     */
    public function getProductBadgeCollection(ProductBadgeCriteriaTransfer $productBadgeCriteriaTransfer): ProductBadgeCollectionTransfer;
}
```
`Business/ProductBadgeFacade.php` (Auszug): keine Logik, nur Delegation.
```php
/**
 * @method \Pyz\Zed\ProductBadge\Business\ProductBadgeBusinessFactory getFactory()
 */
class ProductBadgeFacade extends AbstractFacade implements ProductBadgeFacadeInterface
{
    public function createProductBadge(ProductBadgeTransfer $productBadgeTransfer): ProductBadgeTransfer
    {
        return $this->getFactory()->createProductBadgeCreator()->createProductBadge($productBadgeTransfer);
    }
}
```
`Business/ProductBadgeBusinessFactory.php` (Auszug): `create*` für Modelle, `get*` für Dependencies.
```php
public function createProductBadgeCreator(): ProductBadgeCreatorInterface
{
    return new ProductBadgeCreator($this->createProductBadgeValidator(), $this->getEntityManager());
}

public function getProductFacade(): ProductFacadeInterface
{
    return $this->getProvidedDependency(ProductBadgeDependencyProvider::FACADE_PRODUCT);
}
```
`ProductBadgeDependencyProvider.php` (Auszug), Facade direkt über den Locator:
```php
public function provideBusinessLayerDependencies(Container $container): Container
{
    $container = parent::provideBusinessLayerDependencies($container);
    $container = $this->addProductFacade($container);

    return $container;
}

protected function addProductFacade(Container $container): Container
{
    $container->set(static::FACADE_PRODUCT, function (Container $container) {
        return $container->getLocator()->product()->facade();
    });

    return $container;
}
```
`ProductFacadeInterface` ist `Spryker\Zed\Product\Business\ProductFacadeInterface`. Eine Bridge gibt es im Projekt nicht (nur Core-Muster, siehe oben).

Persistence-Muster (aus `vendor/spryker/api-key/.../Persistence/`): `ProductBadgeEntityManager::createProductBadge()` mappt Transfer -> Entity (`SpyProductBadge`), `save()`, mappt zurück; `ProductBadgeRepository::getProductBadgeCollection()` baut die Query über `getFactory()->createProductBadgeQuery()`, filtert `filterByBadgeKey_In(...)` und mappt über `ProductBadgeMapper`. Query, Entity und Mapper entstehen in `ProductBadgePersistenceFactory`.

Core-Vorbild für Fresh-Query im DependencyProvider (`vendor/spryker/cart-note/.../CartNoteDependencyProvider.php`):
```php
$container->set(static::SALES_ORDER_QUERY, $container->factory(function () {
    return SpySalesOrderQuery::create();
}));
```

## Fallstricke
| Fehler | Ursache | Behebung |
|---|---|---|
| `FacadeNotFoundException`, `BusinessFactoryNotFoundException`, `DependencyProviderNotFoundException` | Name/Ordner/Namespace weicht vom Muster ab; Resolver-Cache zeigt auf altes Ziel | Muster prüfen, `cache:class-resolver:build` |
| `ContainerKeyNotFoundException` | Dependency im falschen Layer registriert | in passender `provide...LayerDependencies` registrieren |
| Autocomplete fehlt, PHPStan "undefined method" | `@method`-Annotation fehlt/falsch | Annotation ergänzen |
| Neues Modul "erbt" Core-Verhalten | Modulname = Core-Modul, `ResolveExtends` setzt Core als Parent | anderen Namen wählen oder bewusst als Erweiterung ([03](03-core-erweitern.md)) |
| Propel-Query/mutables Objekt wiederverwendet | `container->set()` ohne `factory()` | `$container->factory(...)` |
| Facade ruft fremde Models/Repositories | Layer-Verletzung | über die fremde Facade (Locator im DependencyProvider) |
| `new MyFacade()` im Produktivcode | umgeht Locator/Test-Overrides | Facade im aufrufenden DependencyProvider registrieren |
| Spryk im Core-Modus genutzt | generiert nach `vendor` | `--mode=project --organization=Pyz`, vorher `--dry-run` |
| Bridge im Projektmodul angelegt | Core-Muster übernommen | Bridge entfernen, Facade direkt über den Locator holen und im Factory-Getter auf das Fremdmodul-Interface typisieren (`ProjectNoBridgeRule` im Project-Ruleset) |

Upgrade-Anti-Pattern: Änderungen unter `vendor/`; Pyz-Facade erbt von Core-Facade nur um Methoden zu ergänzen; Zugriff auf nicht-`@api`-Klassen fremder Module; DependencyProvider-Logik kopieren statt `parent::provide...` (siehe [28](28-upgradability.md), Gewichtung `[Annahme]`).

## Checkliste
- [ ] Name geprüft (`ls vendor/spryker | grep -i`, `ls src/Pyz/Zed`), PascalCase, passt zu Ordner und Namespace `Pyz\Zed\<Modul>`
- [ ] Facade + Interface (`@api`, Specification, Transfers als Parameter), delegiert an Factory-Modelle
- [ ] BusinessFactory mit `create*` und getypten Dependency-Gettern, `@method`-Annotationen
- [ ] DependencyProvider: Konstanten + `add...`, Closure, `factory()` bei Queries, richtiger Layer, `parent::` aufgerufen
- [ ] Config nur für Werte, Zugriff über Factory
- [ ] Fremde Module nur über Facade/Client/Service direkt aus dem Locator, keine Bridge, Getter auf Fremdmodul-Interface typisiert
- [ ] Repository (lesen) + EntityManager (schreiben) + PersistenceFactory
- [ ] Transfers definiert, `transfer:generate` gelaufen
- [ ] `declare(strict_types = 1);`, Typen, PHPStan Level 6 grün
- [ ] `code:sniff:style` und `code:sniff:architecture` ohne Befunde, zusätzlich Project-Ruleset (`phpmd ... Project/ruleset.xml`) ([27](27-coding-guidelines.md))
- [ ] Tests unter `tests/PyzTest/...` ([26](26-tests.md))

## Quellen
Doku (Basis `https://docs.spryker.com/docs/`, nur grob geprüft): `dg/dev/backend-development/extend-spryker/create-modules.html`, `.../zed/business-layer/facade/facade.html`, `.../facade/design-by-contract-dbc-facade.html`, `.../factory/factory.html` (nicht abrufbar), `.../factory/inject-dependencies-within-factories-container-globals.html` (nicht abrufbar), `dg/dev/sdks/sdk/spryks/adding-spryks.html` (ersetzt durch `vendor/spryker-sdk/spryk/README.md`).

Code: `vendor/spryker/kernel/src/Spryker/Zed/Kernel/{AbstractBundleConfig,AbstractBundleDependencyProvider,AbstractFactory,Container,Locator}.php`, `.../Business/{AbstractFacade,AbstractBusinessFactory}.php`, `.../Persistence/{AbstractRepository,AbstractEntityManager,AbstractPersistenceFactory}.php`, `.../ClassResolver/**`, `vendor/spryker/kernel/src/Spryker/Shared/Kernel/KernelConfig.php`, `vendor/spryker/cart-note/src/Spryker/Zed/CartNote/**`, `vendor/spryker/api-key/src/Spryker/Zed/ApiKey/Persistence/**`, `vendor/spryker/product/src/Spryker/Zed/Product/Business/ProductFacadeInterface.php`, `src/Pyz/Zed/ExampleProductSalePage/**`, `src/Pyz/Zed/Customer/CustomerDependencyProvider.php`, `vendor/spryker-sdk/spryk/{README.md,spryk.phar}`, `vendor/spryker/architecture-sniffer/src/Project/ruleset.xml`.

Nachbarn: [01 Architektur](01-architektur.md), [03 Core erweitern](03-core-erweitern.md), [04 Transfer](04-transfer.md), [05 Propel](05-propel-persistenz.md), [06 Konfiguration](06-konfiguration.md), Template [zed-module](../templates/zed-module/README.md).
