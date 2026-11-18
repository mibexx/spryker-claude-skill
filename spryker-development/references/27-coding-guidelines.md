# Coding guidelines and static analysis

> Status: Release 202610.0 (commit d45396b8). Configuration files, sniffs and rules read; docs `code-architecture-guide` only fetched as a short version, `code-quality` only navigation, detailed docs of the tools (`dg/dev/sdks/sdk/development-tools/*`) not fetched: `[code-verified, docs not cross-checked]`. Console commands were executed in the container `[live verified (202610.0)]` (real runs and `-d` dry runs that show the actual invocations). **Important:** for `code:sniff:style` and `code:sniff:architecture`, `-m|--module` means **core/vendor modules** (`Spryker.Kernel`); `-m Pyz.ProductBadge` runs with the core ruleset on `vendor/pyz/productbadge` and fails (`The file "/data/vendor/pyz/console/" does not exist`, exit 3, or "No module(s) found", exit 1). **Project code is checked via the path argument:** `code:sniff:style src/Pyz/Zed/ProductBadge` and `code:sniff:architecture -p 1 src/Pyz/Zed/ProductBadge`. For `code:phpstan`, by contrast, `-m ProductBadge` (without organization) is correct: it analyzes `src/Pyz/{Yves,Zed,Glue}/ProductBadge`; `-m Pyz.X` fails there with `DirectoryNotFoundException`. `code:sniff:architecture` without `-d` creates an `architecture-baseline.json` (`[]`) in the project root (do not commit). **Not registered** (not present in the live list, although some of the console classes exist in vendor, therefore removed here): `code:phpmd`, `dev:dependency:*`, `dev:composer:*`, `dev:module:create`, `dev:module:bridge:create`, `dev:plugin-usage:find`, `dev:dependency-violation:find`, `code:constraint:modules`.

## Purpose

Which quality tools run in the project (PHPStan, Code Sniffer, Architecture Sniffer), how they are configured, which architecture and naming rules they enforce, and in which order to run them before a commit.

## Concepts

**Tools** (all via `spryker/development`, `require-dev`, `composer.json` lines 278-286):

| Tool | Command | Package | Project file |
|---|---|---|---|
| PHPStan | `code:phpstan` | `phpstan/phpstan 2.1.39`, `spryker-sdk/phpstan-spryker ^0.5.1` | `phpstan.neon`, `phpstan-bootstrap.php` |
| Code Sniffer (PHPCS) | `code:sniff:style` | `spryker/code-sniffer ^0.17.36` | `phpcs.xml` |
| Architecture Sniffer (PHPMD) | `code:sniff:architecture` | `spryker/architecture-sniffer ^0.6.1` | none, ruleset in vendor |

Pipeline order `config/install/sniffs.yml`: section `generate` (Transfers, DataBuilders, IDE autocompletion, scope collection file; **marked with `excluded: true`**, so it does not run automatically), then `sniff` (phpstan → propel-schema-validate → transfer-validate → code-style-sniff → architecture-sniff).

**PHPStan.** `phpstan.neon`: `vendor/spryker-sdk/phpstan-spryker/extension.neon` + `bleedingEdge.neon`, `level: 6`, `paths: src/`, `excludePaths: src/Generated/*, src/Orm/*`, `bootstrapFiles: phpstan-bootstrap.php`, `reportUnmatchedIgnoredErrors: false`, `dynamicConstantNames` (APPLICATION, APPLICATION_ENV, APPLICATION_STORE, APPLICATION_CODE_BUCKET), a few `ignoreErrors`. `phpstan-bootstrap.php` defines the `APPLICATION*` constants and loads `src/Generated/{Client,Service,Yves}/Ide/AutoCompletion.php`: `transfer:generate` and `dev:ide-auto-completion:generate` must have run before PHPStan (Composer script `phpstan-setup`). `phpstan-spryker` resolves the "magic" methods `getFacade()`, `getFactory()`, `getConfig()`, `getRepository()`, `getEntityManager()`, `getQueryContainer()`, `getClient()`; if the `@method` annotation is missing in the class docblock, `DynamicMethodMissingPhpDocAnnotationRule` reports it.

**Which level applies?** Several sources: `phpstan.neon` and Composer script `phpstan` (`-l 6`), CI (`.github/workflows/ci.yml` lines 160-161, `-l 6`), `DevelopmentConfig::getPhpstanLevel()` in the core 7 (docblock recommends 8). `PhpstanRunner::getDefaultLevel()` first takes the level from the `phpstan.neon` of the path, then `phpstan.json`, then the config fallback: `code:phpstan` uses level 6 in the project unless `-l` is passed. Internally the runner calls `php -d memory_limit=... vendor/bin/phpstan analyze --memory-limit=4000M --no-progress -c <cfg> <path> -l <level>`.

**Code Sniffer.** `phpcs.xml`: `php_version 80313`, checks `src/`, `config/`, `tests/`, excludes Generated, `src/Orm` (Base/Map/Propel) and test output, inherits `vendor/spryker/code-sniffer/Spryker/ruleset.xml`. Disables among others `Spryker.Commenting.DocBlock`, `DocBlockConst`, `InlineDocBlock`, `DocBlockApiAnnotation`, `DocBlockReturnVoid`, `DisallowCloakingCheck`, `DisallowNullSafeObjectOperator`; `Spryker.Internal.SprykerDisallowFunctions` is re-enabled with `phpVersion=8.3` (prevents PHP 8.4 functions). Project-specific: `Spryker.Namespaces.SprykerNamespace` with `namespace=Pyz`; additional Slevomat sniffs (`DeclareStrictTypes`, `ReturnTypeHint`, `PropertyTypeHint`, `RequireTrailingCommaInDeclaration/ClosureUse`, `UnusedVariable`, `UnusedParameter`, `EarlyExit`, `DisallowEqualOperators` among others). Catalog: `vendor/spryker/code-sniffer/docs/sniffs.md`. In `src/Pyz/Zed/Development/DevelopmentConfig.php` the project only overrides `getCodingStandard()`.

**Architecture Sniffer.** PHPMD with custom rules: `DevelopmentConfig::getArchitectureSnifferCommand()` builds `php -d error_reporting=E_ALL&~E_DEPRECATED vendor/bin/phpmd <path> xml <ruleset>`. Core ruleset `vendor/spryker/architecture-sniffer/src/ruleset.xml` (Yves/Client/Common/Shared/Zed/Service), project ruleset `vendor/spryker/architecture-sniffer/src/Project/ruleset.xml` (contains among others `ProjectNoBridgeRule`, excludes `*DataImport*`, `*/Orm/*`, `*/Generated/*` and example modules). Default priority 2, option `-p 1|2|3`. **Which ruleset runs:** `code:sniff:architecture` uses the **core ruleset** by default (`DevelopmentConfig::getArchitectureSnifferRuleset()` returns `…/architecture-sniffer/src/ruleset.xml`; the project does not override the method in `src/Pyz/Zed/Development/DevelopmentConfig.php`). Proven live via dry run: `php -d error_reporting=E_ALL&~E_DEPRECATED vendor/bin/phpmd /data/src/ xml /data/vendor/spryker/architecture-sniffer/src/ruleset.xml --minimumpriority 2`. CI also calls the core ruleset. The **project ruleset** (and thus `ProjectNoBridgeRule`) is therefore **not** checked; you call it explicitly: `vendor/bin/phpmd src/Pyz text vendor/spryker/architecture-sniffer/src/Project/ruleset.xml`.

Rules (classes under `vendor/spryker/architecture-sniffer/src/`):
- `Project/Common/ProjectNoBridgeRule`: "Project should not use and depend on Bridge pattern" (classes `*Bridge` and dependencies on them are reported; in the release there is no bridge class in `src/Pyz`). Only applies in the project ruleset (see above), not with `code:sniff:architecture` in the default configuration. This contradicts docs practice; for project code the convention of the release applies: Facade/Client directly via the locator in the DependencyProvider.
- `Project/Common/InstanceResolvingRule` (never create Facade/Client/Factory with `new`), `LocatorInDependencyProviderOnlyRule` (`getLocator()` only in `*DependencyProvider`), `SingletonInstanceInDependencyProviderOnlyRule`, `LayerAccessRule`, `DisallowedModuleNameRule` (module name without `test`, `dummy`, `example`, `antelope`), `TooManyPublicMethodsRule`.
- `Project/Zed/{OrmAccessRule,OrmNewEntityNotInCommunicationRule,RepositoryReadOnlyRule,RestrictedOrmQueryAccessInZedPersistenceRule}`.
- `Common/Factory/*`: factory methods `create*`/`get*`, one `new` per create method, no loops/logic. `Common/Plugin/{PluginSuffixRule,PluginInterfaceSuffixRule}`.
- `Zed/Business/Facade/*` (`AllMethodsPublicInFacadeRule`, `FacadeNoLogicRule`, `FacadeInterfaceRule`, `FacadeArgumentsNotAllowedUseEntityTransferRule`), `Zed/Dependency/Bridge/*`, `Zed/DependencyProvider/*`, `Zed/Persistence/*` (`SpyEntityUsageRule`, Repository rules).

**Architecture rules** (docs `code-architecture-guide`, short version; checked against the code; background → [Architecture](01-architecture.md)):
1. Zed layers Communication → Business → Persistence; no direct access Communication→Persistence/Propel.
2. Across modules only via Facade (Zed) or Client (Yves/Glue), never via internal models, repositories or Propel queries of other modules.
3. Create objects only in factories; the Factory fetches foreign dependencies via `getProvidedDependency()` from the DependencyProvider (→ [Project module](02-project-module.md)).
4. Facade without logic, delegation only; Facade methods receive/return Transfers.
5. Core extension via plugins/extension points/config, inheritance as replacement (→ [Extending the core](03-extending-the-core.md)).
6. Project level without bridges (only checked by the project ruleset, see above).

**Naming and file conventions** (from sniffs/rules): file = class name (`Spryker.Classes.ClassFileName`), namespace root `Pyz`, no Pyz references in the core; interfaces `…Interface`, plugins `…Plugin`, plugin interfaces `…PluginInterface`, factory methods `create…`/`get…`, DependencyProvider methods `add…`/`provide…`, constants SCREAMING_SNAKE. Every file starts with a license docblock and `declare(strict_types = 1);` (with spaces around `=`), `array<…>` instead of `Type[]`, return/property types mandatory, `@method` annotations for magic accesses (`MethodAnnotation/{Facade,Factory,Config,Repository,EntityManager,QueryContainer}MethodAnnotationSniff`). Suppression: `// phpcs:ignore <Sniff>`, `@SuppressWarnings(PHPMD.<Rule>)`, `@phpstan-ignore <id>`; `code:sniff:architecture -s` also reports suppressed locations.

## Instructions

1. Generate: `docker/sdk cli console transfer:generate` and `docker/sdk cli console dev:ide-auto-completion:generate`.
2. Style: `docker/sdk cli console code:sniff:style -f <path>` (fix), then check again without `-f`.
3. PHPStan: `docker/sdk cli php -d memory_limit=1024M vendor/bin/console code:phpstan` or `docker/sdk cli composer phpstan` (like CI); for new code stricter with `-l 7`/`-l 8` and `-m <Module>`.
4. Architecture: `docker/sdk cli console code:sniff:architecture -p 1 -v` (core ruleset); CI parity: `vendor/bin/phpmd src/ text vendor/spryker/architecture-sniffer/src/ruleset.xml --minimumpriority 2`. Additionally the project ruleset (bridges, project rules): `docker/sdk cli vendor/bin/phpmd src/Pyz text vendor/spryker/architecture-sniffer/src/Project/ruleset.xml`.
5. Further checks of the pipeline: `propel:schema:validate`, `transfer:validate`.
6. Justify suppressions and keep them minimal; never sniff Generated/Orm paths by hand.
7. Additionally the Evaluator (→ [Upgradability](28-upgradability.md)).

Options according to the console classes:
```
code:sniff:style         [path] -m|--module  -s|--sniffs  -l|--level  -e|--explain  -d|--dry-run  -f|--fix
code:phpstan             -m|--module  -f|--format [text,xml,json,md]  -d|--dry-run  -l|--level  -c|--is-mergable-config  -o|--offset
code:sniff:architecture  [path] -m|--module  -p|--priority [1|2|3, default 2]  -s|--strict  -d|--dry-run  -u|--update-baseline  (-v shows violations)
```
Commands (all executed `[live verified (202610.0)]`; `src/Pyz/Zed/Console` ran with `phpcs`, `phpstan` level 6 and `phpmd` project ruleset without findings):
```
docker/sdk cli console transfer:generate
docker/sdk cli console dev:ide-auto-completion:generate
docker/sdk cli console code:sniff:style
docker/sdk cli console code:sniff:style src/Pyz/Zed/ProductBadge       # path argument for project code (no -m Pyz.X)
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
Further registered development command: `dev:plugin-usage:dump` (plugin usage per DependencyProvider). The commands `code:phpmd`, `dev:dependency:*`, `dev:composer:*` and `dev:module:create` are **not registered** in the release (do not call them).

## Examples

**Project configuration via inheritance** (`src/Pyz/Zed/Development/DevelopmentConfig.php`, complete, release):
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

**Compliant business factory with `@method` annotations** (`src/Pyz/Zed/ExampleProductSalePage/Business/ExampleProductSalePageBusinessFactory.php`, release):
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
Check: exactly one `new` in a `create*` method, return as interface, no logic; if the `@method` line were missing, `phpstan-spryker` or the MethodAnnotation sniff would report an error. The example module still uses a QueryContainer (legacy in favor of Repository/EntityManager, → [Propel](05-propel-persistence.md)).

**Locator only in the DependencyProvider** (`src/Pyz/Zed/Customer/CustomerDependencyProvider.php`, excerpt): `$container->getLocator()->sales()->facade()` in `addFacadeSales()` is the only allowed place (`LocatorInDependencyProviderOnlyRule`).

**Extension via factory inheritance** (`src/Pyz/Yves/CartPage/CartPageFactory.php`): `class CartPageFactory extends SprykerShop\Yves\CartPage\CartPageFactory` with an additional `create…` method and interface return type.

**Suppression in the release:** `// phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter` (`src/Pyz/Zed/Oms/OmsDependencyProvider.php`), `@SuppressWarnings(PHPMD.<Rule>)` (`src/Pyz/Zed/DataImport/DataImportConfig.php`).

## Pitfalls

- PHPStan "Call to an undefined method getFacade()/getConfig()": `@method` missing or autocompletion not generated.
- PHPStan does not find Transfers: `transfer:generate` has not run (`src/Generated` is excluded, but needed for resolution).
- Memory errors: `php -d memory_limit=1024M` or the runner default 4000M.
- Level confusion: neon 6 ≠ core fallback 7; new code should pass at least level 6, better 7/8 (recommendation; the docblock of the core config recommends 8).
- `ignoreErrors` with `reportUnmatchedIgnoredErrors: false` hide dead ignores; do not use them for new violations.
- `new` on Facade/Client/Factory (`InstanceResolvingRule`); `getLocator()` outside the DependencyProvider; Propel query in the business/communication layer; `new SpyEntity` in Communication.
- Module name with "Example"/"Test" (`DisallowedModuleNameRule`); the demo modules `Example*` are only excluded in the project ruleset.
- Avoid bridges in the project (fetch the core module's Facade/Client directly in the DependencyProvider, type the factory getter to the interface of the foreign module). `code:sniff:architecture` (core ruleset) does **not** report bridges; for that, call the project ruleset via `phpmd` (see Architecture Sniffer). The project templates of this skill contain no bridges.
- CI parity: style, PHPMD (minimumpriority 2), PHPStan level 6, for release PRs to `master` additionally the Evaluator; run the same sequence locally.
- Upgrader anti-patterns (changing core files in vendor, copying core methods instead of `parent::`, overriding core models instead of a plugin) → [Extending the core](03-extending-the-core.md), [Upgradability](28-upgradability.md).

## Checklist

- [ ] License block + `declare(strict_types = 1);`, namespace `Pyz\…`, file name = class name
- [ ] All parameters/returns/properties typed, `array<…>` docblocks
- [ ] `@method` annotations for Facade/Factory/Config/Client present
- [ ] Objects only in the Factory (`create*`), foreign dependencies via DependencyProvider, locator only there
- [ ] No bridges in the project, layer boundaries and Facade/Client access respected
- [ ] `transfer:generate` + `dev:ide-auto-completion:generate`, then `code:sniff:style -f`, `code:phpstan`, `code:sniff:architecture` and `phpmd` with the project ruleset
- [ ] Suppressions justified and minimal

## Sources

Docs (base https://docs.spryker.com/docs/): `dg/dev/guidelines/coding-guidelines/code-architecture-guide.html` (fetched, shortened), `…/code-quality.html` (navigation only); not fetched: `…/coding-guidelines.html`, `…/code-style-guide.html`, `dg/dev/architecture/architectural-convention-reference.html`, `architecture-as-code.html`, `dg/dev/sdks/sdk/development-tools/{phpstan,code-sniffer,architecture-sniffer}`.

Code: `phpstan.neon`, `phpstan-bootstrap.php`, `phpcs.xml`, `composer.json` (lines 278-286, 365-381), `config/install/sniffs.yml`, `.github/workflows/ci.yml` (lines 154-164), `src/Pyz/Zed/Development/DevelopmentConfig.php`, `vendor/spryker/development/src/Spryker/Zed/Development/{DevelopmentConfig.php,Communication/Console/Code*Console.php,Business/Phpstan/PhpstanRunner.php}`, `vendor/spryker-sdk/phpstan-spryker/`, `vendor/spryker/architecture-sniffer/src/{ruleset.xml,Project/**,Zed/**,Common/**}`, `vendor/spryker/code-sniffer/{Spryker/ruleset.xml,docs/sniffs.md}`, `src/Pyz/Zed/ExampleProductSalePage/Business/…BusinessFactory.php`, `src/Pyz/Yves/CartPage/CartPageFactory.php`, `src/Pyz/Zed/Customer/CustomerDependencyProvider.php`.

References: [Architecture](01-architecture.md), [Project module](02-project-module.md), [Extending the core](03-extending-the-core.md), [Tests](26-tests.md), [Upgradability](28-upgradability.md).

Deprecations/deviations: level deviation (neon 6 / CI 6 / core 7 / docblock 8); CI ruleset and console both use the core ruleset (proven live); `QueryContainerMethodAnnotationSniff` exists, QueryContainer is considered legacy; `Feature-Policy` header notes → [Security](21-security-session.md).
