# Coding-Guidelines und statische Analyse (Recherche T-27)

> Status: Release 202610.0 (Commit d45396b8). Konfigurationsdateien, Sniffs und Regeln gelesen; Doku `code-architecture-guide` nur als Kurzfassung abgerufen, `code-quality` nur Navigation, Detaildoku der Tools (`dg/dev/sdks/sdk/development-tools/*`) nicht abgerufen: `[code-verifiziert, Doku nicht abgeglichen]`. Konsolenbefehle wurden im Container ausgeführt `[live verifiziert (202610.0)]` (echte Läufe und `-d`-Dry-Runs, die die tatsächlichen Aufrufe zeigen). **Wichtig:** Bei `code:sniff:style` und `code:sniff:architecture` meint `-m|--module` **Core-/Vendor-Module** (`Spryker.Kernel`); `-m Pyz.ProductBadge` läuft mit dem Core-Ruleset auf `vendor/pyz/productbadge` und scheitert (`The file "/data/vendor/pyz/console/" does not exist`, Exit 3 bzw. "No module(s) found", Exit 1). **Projektcode wird über das Pfad-Argument geprüft:** `code:sniff:style src/Pyz/Zed/ProductBadge` und `code:sniff:architecture -p 1 src/Pyz/Zed/ProductBadge`. Bei `code:phpstan` dagegen ist `-m ProductBadge` (ohne Organisation) richtig: es analysiert `src/Pyz/{Yves,Zed,Glue}/ProductBadge`; `-m Pyz.X` scheitert dort mit `DirectoryNotFoundException`. `code:sniff:architecture` ohne `-d` legt im Projektroot eine `architecture-baseline.json` (`[]`) an (nicht committen). **Nicht registriert** (in der Live-Liste nicht vorhanden, obwohl die Console-Klassen im Vendor teils existieren, daher hier entfernt): `code:phpmd`, `dev:dependency:*`, `dev:composer:*`, `dev:module:create`, `dev:module:bridge:create`, `dev:plugin-usage:find`, `dev:dependency-violation:find`, `code:constraint:modules`.

## Zweck

Welche Qualitätswerkzeuge im Projekt laufen (PHPStan, Code Sniffer, Architecture Sniffer), wie sie konfiguriert sind, welche Architektur- und Namensregeln sie durchsetzen und in welcher Reihenfolge man sie vor einem Commit ausführt.

## Konzepte

**Tools** (alle über `spryker/development`, `require-dev`, `composer.json` Z. 278-286):

| Tool | Kommando | Paket | Projektdatei |
|---|---|---|---|
| PHPStan | `code:phpstan` | `phpstan/phpstan 2.1.39`, `spryker-sdk/phpstan-spryker ^0.5.1` | `phpstan.neon`, `phpstan-bootstrap.php` |
| Code Sniffer (PHPCS) | `code:sniff:style` | `spryker/code-sniffer ^0.17.36` | `phpcs.xml` |
| Architecture Sniffer (PHPMD) | `code:sniff:architecture` | `spryker/architecture-sniffer ^0.6.1` | keine, Ruleset im Vendor |

Pipeline-Reihenfolge `config/install/sniffs.yml`: Abschnitt `generate` (Transfers, DataBuilders, IDE-Autocompletion, Scope-Collection-Datei; **mit `excluded: true` markiert**, läuft also nicht automatisch mit), dann `sniff` (phpstan → propel-schema-validate → transfer-validate → code-style-sniff → architecture-sniff).

**PHPStan.** `phpstan.neon`: `vendor/spryker-sdk/phpstan-spryker/extension.neon` + `bleedingEdge.neon`, `level: 6`, `paths: src/`, `excludePaths: src/Generated/*, src/Orm/*`, `bootstrapFiles: phpstan-bootstrap.php`, `reportUnmatchedIgnoredErrors: false`, `dynamicConstantNames` (APPLICATION, APPLICATION_ENV, APPLICATION_STORE, APPLICATION_CODE_BUCKET), wenige `ignoreErrors`. `phpstan-bootstrap.php` definiert die `APPLICATION*`-Konstanten und lädt `src/Generated/{Client,Service,Yves}/Ide/AutoCompletion.php`: Vor PHPStan müssen `transfer:generate` und `dev:ide-auto-completion:generate` gelaufen sein (Composer-Script `phpstan-setup`). `phpstan-spryker` löst die „magischen“ Methoden `getFacade()`, `getFactory()`, `getConfig()`, `getRepository()`, `getEntityManager()`, `getQueryContainer()`, `getClient()` auf; fehlt die `@method`-Annotation im Klassen-Docblock, meldet `DynamicMethodMissingPhpDocAnnotationRule`.

**Welches Level gilt?** Mehrere Quellen: `phpstan.neon` und Composer-Script `phpstan` (`-l 6`), CI (`.github/workflows/ci.yml` Z. 160-161, `-l 6`), `DevelopmentConfig::getPhpstanLevel()` im Core 7 (Docblock empfiehlt 8). `PhpstanRunner::getDefaultLevel()` nimmt zuerst das Level aus der `phpstan.neon` des Pfads, dann `phpstan.json`, dann den Config-Fallback: `code:phpstan` nutzt im Projekt Level 6, außer `-l` wird übergeben. Der Runner ruft intern `php -d memory_limit=... vendor/bin/phpstan analyze --memory-limit=4000M --no-progress -c <cfg> <path> -l <level>`.

**Code Sniffer.** `phpcs.xml`: `php_version 80313`, prüft `src/`, `config/`, `tests/`, schließt Generated, `src/Orm` (Base/Map/Propel) und Test-Output aus, erbt `vendor/spryker/code-sniffer/Spryker/ruleset.xml`. Deaktiviert u. a. `Spryker.Commenting.DocBlock`, `DocBlockConst`, `InlineDocBlock`, `DocBlockApiAnnotation`, `DocBlockReturnVoid`, `DisallowCloakingCheck`, `DisallowNullSafeObjectOperator`; `Spryker.Internal.SprykerDisallowFunctions` wird mit `phpVersion=8.3` wieder aktiv (verhindert PHP-8.4-Funktionen). Projektspezifisch: `Spryker.Namespaces.SprykerNamespace` mit `namespace=Pyz`; zusätzliche Slevomat-Sniffs (`DeclareStrictTypes`, `ReturnTypeHint`, `PropertyTypeHint`, `RequireTrailingCommaInDeclaration/ClosureUse`, `UnusedVariable`, `UnusedParameter`, `EarlyExit`, `DisallowEqualOperators` u. a.). Katalog: `vendor/spryker/code-sniffer/docs/sniffs.md`. Das Projekt überschreibt in `src/Pyz/Zed/Development/DevelopmentConfig.php` nur `getCodingStandard()`.

**Architecture Sniffer.** PHPMD mit eigenen Regeln: `DevelopmentConfig::getArchitectureSnifferCommand()` baut `php -d error_reporting=E_ALL&~E_DEPRECATED vendor/bin/phpmd <path> xml <ruleset>`. Core-Ruleset `vendor/spryker/architecture-sniffer/src/ruleset.xml` (Yves/Client/Common/Shared/Zed/Service), Projekt-Ruleset `vendor/spryker/architecture-sniffer/src/Project/ruleset.xml` (enthält u. a. `ProjectNoBridgeRule`, schließt `*DataImport*`, `*/Orm/*`, `*/Generated/*` und Example-Module aus). Priorität Standard 2, Option `-p 1|2|3`. **Welches Ruleset läuft:** `code:sniff:architecture` nutzt standardmäßig das **Core-Ruleset** (`DevelopmentConfig::getArchitectureSnifferRuleset()` liefert `…/architecture-sniffer/src/ruleset.xml`; das Projekt überschreibt die Methode in `src/Pyz/Zed/Development/DevelopmentConfig.php` nicht). Live per Dry-Run belegt: `php -d error_reporting=E_ALL&~E_DEPRECATED vendor/bin/phpmd /data/src/ xml /data/vendor/spryker/architecture-sniffer/src/ruleset.xml --minimumpriority 2`. Die CI ruft ebenfalls das Core-Ruleset. Das **Project-Ruleset** (und damit `ProjectNoBridgeRule`) wird dadurch **nicht** geprüft; man ruft es explizit auf: `vendor/bin/phpmd src/Pyz text vendor/spryker/architecture-sniffer/src/Project/ruleset.xml`.

Regeln (Klassen unter `vendor/spryker/architecture-sniffer/src/`):
- `Project/Common/ProjectNoBridgeRule`: „Project should not use and depend on Bridge pattern“ (Klassen `*Bridge` und Abhängigkeiten darauf werden gemeldet; im Release gibt es in `src/Pyz` keine Bridge-Klasse). Greift nur im Project-Ruleset (siehe oben), nicht bei `code:sniff:architecture` mit Standard-Konfiguration. Das widerspricht der Doku-Praxis; für Projektcode gilt die Konvention des Releases: Facade/Client direkt über den Locator im DependencyProvider.
- `Project/Common/InstanceResolvingRule` (Facade/Client/Factory nie mit `new`), `LocatorInDependencyProviderOnlyRule` (`getLocator()` nur in `*DependencyProvider`), `SingletonInstanceInDependencyProviderOnlyRule`, `LayerAccessRule`, `DisallowedModuleNameRule` (Modulname ohne `test`, `dummy`, `example`, `antelope`), `TooManyPublicMethodsRule`.
- `Project/Zed/{OrmAccessRule,OrmNewEntityNotInCommunicationRule,RepositoryReadOnlyRule,RestrictedOrmQueryAccessInZedPersistenceRule}`.
- `Common/Factory/*`: Factory-Methoden `create*`/`get*`, ein `new` pro create-Methode, keine Schleifen/Logik. `Common/Plugin/{PluginSuffixRule,PluginInterfaceSuffixRule}`.
- `Zed/Business/Facade/*` (`AllMethodsPublicInFacadeRule`, `FacadeNoLogicRule`, `FacadeInterfaceRule`, `FacadeArgumentsNotAllowedUseEntityTransferRule`), `Zed/Dependency/Bridge/*`, `Zed/DependencyProvider/*`, `Zed/Persistence/*` (`SpyEntityUsageRule`, Repository-Regeln).

**Architekturregeln** (Doku `code-architecture-guide`, Kurzfassung; am Code geprüft; Hintergrund → [Architektur](01-architektur.md)):
1. Zed-Schichten Communication → Business → Persistence; kein Direktzugriff Communication→Persistence/Propel.
2. Modulübergreifend nur über Facade (Zed) bzw. Client (Yves/Glue), nie über interne Models, Repositories oder Propel-Queries fremder Module.
3. Objekte nur in Factories erzeugen; Fremdabhängigkeiten holt die Factory per `getProvidedDependency()` aus dem DependencyProvider (→ [Projektmodul](02-projektmodul.md)).
4. Facade ohne Logik, nur Delegation; Facade-Methoden bekommen/liefern Transfers.
5. Core-Erweiterung über Plugins/Extension Points/Config, Vererbung als Replacement (→ [Core erweitern](03-core-erweitern.md)).
6. Projekt-Ebene ohne Bridges (nur vom Project-Ruleset geprüft, siehe oben).

**Namens- und Dateikonventionen** (aus Sniffs/Regeln): Datei = Klassenname (`Spryker.Classes.ClassFileName`), Namespace-Wurzel `Pyz`, keine Pyz-Referenzen im Core; Interfaces `…Interface`, Plugins `…Plugin`, Plugin-Interfaces `…PluginInterface`, Factory-Methoden `create…`/`get…`, DependencyProvider-Methoden `add…`/`provide…`, Konstanten SCREAMING_SNAKE. Jede Datei beginnt mit Lizenz-Docblock und `declare(strict_types = 1);` (mit Leerzeichen um `=`), `array<…>` statt `Type[]`, Rückgabe-/Property-Typen Pflicht, `@method`-Annotationen für magische Zugriffe (`MethodAnnotation/{Facade,Factory,Config,Repository,EntityManager,QueryContainer}MethodAnnotationSniff`). Suppression: `// phpcs:ignore <Sniff>`, `@SuppressWarnings(PHPMD.<Regel>)`, `@phpstan-ignore <id>`; `code:sniff:architecture -s` meldet auch unterdrückte Stellen.

## Anleitung

1. Generieren: `docker/sdk cli console transfer:generate` und `docker/sdk cli console dev:ide-auto-completion:generate`.
2. Style: `docker/sdk cli console code:sniff:style -f <pfad>` (Fix), danach ohne `-f` erneut prüfen.
3. PHPStan: `docker/sdk cli php -d memory_limit=1024M vendor/bin/console code:phpstan` bzw. `docker/sdk cli composer phpstan` (wie CI); für neuen Code strenger mit `-l 7`/`-l 8` und `-m <Modul>`.
4. Architektur: `docker/sdk cli console code:sniff:architecture -p 1 -v` (Core-Ruleset); CI-Parität: `vendor/bin/phpmd src/ text vendor/spryker/architecture-sniffer/src/ruleset.xml --minimumpriority 2`. Zusätzlich Project-Ruleset (Bridges, Projektregeln): `docker/sdk cli vendor/bin/phpmd src/Pyz text vendor/spryker/architecture-sniffer/src/Project/ruleset.xml`.
5. Weitere Prüfungen der Pipeline: `propel:schema:validate`, `transfer:validate`.
6. Suppressions begründen und minimal halten; nie Generated/Orm-Pfade von Hand sniffen.
7. Evaluator zusätzlich (→ [Upgradability](28-upgradability.md)).

Optionen laut Console-Klassen:
```
code:sniff:style         [path] -m|--module  -s|--sniffs  -l|--level  -e|--explain  -d|--dry-run  -f|--fix
code:phpstan             -m|--module  -f|--format [text,xml,json,md]  -d|--dry-run  -l|--level  -c|--is-mergable-config  -o|--offset
code:sniff:architecture  [path] -m|--module  -p|--priority [1|2|3, default 2]  -s|--strict  -d|--dry-run  -u|--update-baseline  (-v zeigt Verstöße)
```
Befehle (alle ausgeführt `[live verifiziert (202610.0)]`; `src/Pyz/Zed/Console` lief mit `phpcs`, `phpstan` Level 6 und `phpmd` Project-Ruleset ohne Befund):
```
docker/sdk cli console transfer:generate
docker/sdk cli console dev:ide-auto-completion:generate
docker/sdk cli console code:sniff:style
docker/sdk cli console code:sniff:style src/Pyz/Zed/ProductBadge       # Pfad-Argument für Projektcode (kein -m Pyz.X)
docker/sdk cli console code:sniff:style -f src/Pyz/Zed/ProductBadge    # -f = --fix
docker/sdk cli console code:sniff:style -e
docker/sdk cli console code:phpstan -l 7 -m ProductBadge
docker/sdk cli console code:sniff:architecture -p 1 -v src/Pyz/Zed/ProductBadge
docker/sdk cli vendor/bin/phpstan analyze -c phpstan.neon src/ -l 6
docker/sdk cli console propel:schema:validate
docker/sdk cli console transfer:validate
docker/sdk cli console dev:ide-auto-completion:remove
docker/sdk cli vendor/bin/phpmd src/Pyz text vendor/spryker/architecture-sniffer/src/Project/ruleset.xml
```
Weiteres registriertes Development-Kommando: `dev:plugin-usage:dump` (Plugin-Nutzung je DependencyProvider). Die Befehle `code:phpmd`, `dev:dependency:*`, `dev:composer:*` und `dev:module:create` sind im Release **nicht registriert** (nicht aufrufen).

## Beispiele

**Projekt-Konfiguration per Vererbung** (`src/Pyz/Zed/Development/DevelopmentConfig.php`, vollständig, Release):
```php
<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\Development;

use Spryker\Zed\Development\DevelopmentConfig as SprykerDevelopmentConfig;

class DevelopmentConfig extends SprykerDevelopmentConfig
{
    public function getCodingStandard(): string
    {
        return APPLICATION_ROOT_DIR . DIRECTORY_SEPARATOR . 'phpcs.xml';
    }
}
```

**Konforme Business-Factory mit `@method`-Annotationen** (`src/Pyz/Zed/ExampleProductSalePage/Business/ExampleProductSalePageBusinessFactory.php`, Release):
```php
/**
 * @method \Pyz\Zed\ExampleProductSalePage\Persistence\ExampleProductSalePageQueryContainerInterface getQueryContainer()
 * @method \Pyz\Zed\ExampleProductSalePage\ExampleProductSalePageConfig getConfig()
 */
class ExampleProductSalePageBusinessFactory extends AbstractBusinessFactory
{
    public function createProductAbstractRelationReader(): ProductAbstractRelationReaderInterface
    {
        return new ProductAbstractRelationReader($this->getQueryContainer(), $this->getConfig());
    }
}
```
Prüfen: genau ein `new` in einer `create*`-Methode, Rückgabe als Interface, keine Logik; fehlte die `@method`-Zeile, melden `phpstan-spryker` bzw. das MethodAnnotation-Sniff einen Fehler. Das Beispielmodul nutzt noch QueryContainer (Legacy zugunsten Repository/EntityManager, → [Propel](05-propel-persistenz.md)).

**Locator nur im DependencyProvider** (`src/Pyz/Zed/Customer/CustomerDependencyProvider.php`, Auszug): `$container->getLocator()->sales()->facade()` in `addFacadeSales()` ist der einzige erlaubte Ort (`LocatorInDependencyProviderOnlyRule`).

**Erweiterung per Factory-Vererbung** (`src/Pyz/Yves/CartPage/CartPageFactory.php`): `class CartPageFactory extends SprykerShop\Yves\CartPage\CartPageFactory` mit zusätzlicher `create…`-Methode und Interface-Rückgabe.

**Suppression im Release:** `// phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter` (`src/Pyz/Zed/Oms/OmsDependencyProvider.php`), `@SuppressWarnings(PHPMD.<Regel>)` (`src/Pyz/Zed/DataImport/DataImportConfig.php`).

## Fallstricke

- PHPStan „Call to an undefined method getFacade()/getConfig()“: `@method` fehlt oder Autocompletion nicht generiert.
- PHPStan findet Transfers nicht: `transfer:generate` nicht gelaufen (`src/Generated` ist ausgeschlossen, aber zum Auflösen nötig).
- Speicherfehler: `php -d memory_limit=1024M` bzw. Runner-Default 4000M.
- Level-Verwirrung: neon 6 ≠ Core-Fallback 7; neuer Code sollte mindestens Level 6, besser 7/8 bestehen (Empfehlung; Docblock der Core-Config empfiehlt 8).
- `ignoreErrors` mit `reportUnmatchedIgnoredErrors: false` verstecken tote Ignores; nicht für neue Verstöße benutzen.
- `new` auf Facade/Client/Factory (`InstanceResolvingRule`); `getLocator()` außerhalb DependencyProvider; Propel-Query im Business-/Communication-Layer; `new SpyEntity` in Communication.
- Modulname mit „Example“/„Test“ (`DisallowedModuleNameRule`); die Demo-Module `Example*` sind nur im Project-Ruleset ausgeschlossen.
- Bridges im Projekt vermeiden (direkt Facade/Client des Core-Moduls im DependencyProvider holen, Factory-Getter auf das Interface des Fremdmoduls typisieren). `code:sniff:architecture` (Core-Ruleset) meldet Bridges **nicht**; dafür das Project-Ruleset per `phpmd` aufrufen (siehe Architecture Sniffer). Die Projekt-Templates dieses Skills enthalten keine Bridges.
- CI-Parität: Style, PHPMD (minimumpriority 2), PHPStan Level 6, bei Release-PRs nach `master` zusätzlich der Evaluator; lokal dieselbe Folge fahren.
- Upgrader-Antipatterns (Core-Dateien in vendor ändern, Core-Methoden kopieren statt `parent::`, Core-Models überschreiben statt Plugin) → [Core erweitern](03-core-erweitern.md), [Upgradability](28-upgradability.md).

## Checkliste

- [ ] Lizenzblock + `declare(strict_types = 1);`, Namespace `Pyz\…`, Dateiname = Klassenname
- [ ] Alle Parameter/Rückgaben/Properties typisiert, `array<…>`-Docblocks
- [ ] `@method`-Annotationen für Facade/Factory/Config/Client vorhanden
- [ ] Objekte nur in Factory (`create*`), Fremdabhängigkeiten über DependencyProvider, Locator nur dort
- [ ] Keine Bridges im Projekt, Schichtgrenzen und Facade/Client-Zugriff eingehalten
- [ ] `transfer:generate` + `dev:ide-auto-completion:generate`, dann `code:sniff:style -f`, `code:phpstan`, `code:sniff:architecture` und `phpmd` mit Project-Ruleset
- [ ] Suppressions begründet und minimal

## Quellen

Doku (Basis https://docs.spryker.com/docs/): `dg/dev/guidelines/coding-guidelines/code-architecture-guide.html` (abgerufen, gekürzt), `…/code-quality.html` (nur Navigation); laut Rechercheplan nicht abgerufen: `…/coding-guidelines.html`, `…/code-style-guide.html`, `dg/dev/architecture/architectural-convention-reference.html`, `architecture-as-code.html`, `dg/dev/sdks/sdk/development-tools/{phpstan,code-sniffer,architecture-sniffer}`.

Code: `phpstan.neon`, `phpstan-bootstrap.php`, `phpcs.xml`, `composer.json` (Z. 278-286, 365-381), `config/install/sniffs.yml`, `.github/workflows/ci.yml` (Z. 154-164), `src/Pyz/Zed/Development/DevelopmentConfig.php`, `vendor/spryker/development/src/Spryker/Zed/Development/{DevelopmentConfig.php,Communication/Console/Code*Console.php,Business/Phpstan/PhpstanRunner.php}`, `vendor/spryker-sdk/phpstan-spryker/`, `vendor/spryker/architecture-sniffer/src/{ruleset.xml,Project/**,Zed/**,Common/**}`, `vendor/spryker/code-sniffer/{Spryker/ruleset.xml,docs/sniffs.md}`, `src/Pyz/Zed/ExampleProductSalePage/Business/…BusinessFactory.php`, `src/Pyz/Yves/CartPage/CartPageFactory.php`, `src/Pyz/Zed/Customer/CustomerDependencyProvider.php`.

Verweise: [Architektur](01-architektur.md), [Projektmodul](02-projektmodul.md), [Core erweitern](03-core-erweitern.md), [Tests](26-tests.md), [Upgradability](28-upgradability.md).

Deprecations/Abweichungen: Level-Abweichung (neon 6 / CI 6 / Core 7 / Docblock 8); CI-Ruleset und Konsole nutzen beide das Core-Ruleset (live belegt); `QueryContainerMethodAnnotationSniff` existiert, QueryContainer gilt als Legacy; `Feature-Policy`-Header-Hinweise → [Security](21-security-session.md).
