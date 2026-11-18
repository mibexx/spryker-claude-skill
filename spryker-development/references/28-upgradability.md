# Upgradability: Evaluator, Spryker Code Upgrader, module versions

> Status: release 202610.0 (commit d45396b8; spryker/kernel 3.87.1, spryker/oms 11.55.0; Symfony 6.4.48; PHP runtime in the container **8.4.27** (live: `docker/sdk cli php -v`), `composer.json` only requires `>=8.3` (lower bound, `config.platform` 8.3.13); Node `>=24.15.0`). Verified as of 2026-11-18. **The Evaluator was actually executed in the container** (`docker/sdk cli vendor/bin/evaluator ...`, result see examples); console commands executed `[live verified (202610.0)]`; `composer show`, `outdated`, `why`, `require --dry-run` and `install --dry-run` were executed `[live verified (202610.0)]`. The docs pages were only read via web fetch with automatic summarization (`ignore-evaluation-errors`, `use-composer-constraint-for-customized-modules`, `container-set-function`, `run-the-evaluator-tool` without usable full text; the bad/good examples of the rule pages are not reliable): `[code-verified, docs not cross-checked]`, **code takes precedence**.

## Purpose

Build and maintain the project so that minor/patch updates (also via the Spryker Code Upgrader) flow in without manual work: rules, Evaluator, justified ignoring, Composer constraints, approach for major updates.

## Concepts

**Rules as checkers.** In 202610.0 the upgradability rules are encoded as 13 checkers in the Evaluator (`vendor/spryker-sdk/evaluator/src/Checker/*`; docs URLs in `vendor/spryker-sdk/evaluator/config/checkers.yaml`, base `…/keeping-a-project-upgradable/upgradability-guidelines/<rule>.html`).

| Checker (`--checkers`) | Checked (from the code) |
|---|---|
| `DEAD_CODE_CHECKER` | Project classes under `src` that extend core classes but are no longer used anywhere. Skip `@evaluator-skip-dead-code` |
| `DEPENDENCY_PROVIDER_ADDITIONAL_LOGIC_CHECKER` | `if` conditions in `*DependencyProvider.php` (excluding `vendor`); only the method call `is…Development…()` and `class_exists()` are allowed |
| `CONTAINER_SET_FUNCTION_CHECKER` | The callback in `$container->set(...)` must not return an array directly but must call a method |
| `MULTIDIMENSIONAL_ARRAY_CHECKER` | maximum nesting depth of plugin registration in DependencyProviders `MAX_LEVEL = 2`. Skip `@evaluator-skip-multidimensional-array` |
| `SINGLE_PLUGIN_ARGUMENT` | constructor arguments for `new Plugin(...)` in DependencyProviders only int, float, string, constant, bool, `new` expression without further method calls. Skip `@evaluator-skip-single-plugin-argument` |
| `PLUGINS_REGISTRATION_WITH_RESTRICTIONS_CHECKER` | docblock block `Restrictions:` on plugin methods: lines `* - (before\|after) {@link <Class>}` (+ optional text), the class must be used in the DependencyProvider |
| `PHP_VERSION_CHECKER` | allowed PHP major versions from `checkers.yaml` (`['8']`); checks `composer.json`, running PHP, `deploy*.yml` |
| `MINIMUM_ALLOWED_SHOP_VERSION` | module versions against `data/minimum-allowed-package-versions.json`, outdated features against `data/deprecated-features.json`; minimum shop version `202204.0` |
| `NPM_CHECKER` | `npm audit`; severity levels via `ALLOWED_SEVERITY_LEVELS` |
| `OPEN_SOURCE_VULNERABILITIES_CHECKER` | Composer audit (`--abandoned=ignore`) |
| `SPRYKER_SECURITY_CHECKER` | asks the ReleaseApp (HTTP) whether security updates exist for installed modules. **Requires network access** |
| `SPRYKER_DEV_PACKAGES_CHECKER` | Spryker dev packages only in `require-dev` |
| `DISCOURAGED_PACKAGES_CHECKER` | discouraged packages |

The general architecture rules behind them (do not override private core API, do not copy core constants/methods, plugin/config extension instead of inheritance) are in the docs `keeping-a-project-upgradable.html` (scenarios: event subscriber registration, module configuration, plugin registration) and are **not** covered by a checker (→ [Extending the core](03-extending-the-core.md)).

Discrepancy docs ↔ code: the docs summary describes "Plugin registration with restrictions" as registration via feature flag; the code (`RestrictionDocBlockValidator`) checks exclusively the docblock pattern. The docs URL in the release contains the typo `restrintions`.

**Evaluator.** `spryker-sdk/evaluator ^0.3.9` (`require-dev`), binary `vendor/bin/evaluator`, command `evaluate`. Options (`EvaluatorCommand::configure()`): `--path=<folder|file>` (default project root), `--format=output|json|compact`, `-f/--file` or `--no-file` (output to `./report`), `--checkers=A,B`, `--exclude-checkers=A,B`. The README mentions `--checks=`; in the code only `--checkers`/`--exclude-checkers` exist. Exit code in the run on the release `1` when there are findings (only observed, not checked against the class `[Assumption]`). CI: `.github/workflows/ci.yml` (Evaluator only for release PRs to `master` plus a run with environment/network checkers excluded) and `.github/workflows/scheduled.yml` (job `evaluator`, scheduled or PR label `run-evaluator`).

**Ignoring findings with justification.** (1) Annotations in the code for three checkers: `@evaluator-skip-dead-code`, `@evaluator-skip-multidimensional-array`, `@evaluator-skip-single-plugin-argument`; placement in the docblock of the class/method with justification `[Assumption]` (not checked individually for each checker). (2) `tooling.yml` in the project root (default `tooling.yml`, `EVALUATOR_TOOLING_FILE`): key `ignoreErrors` with regex (`preg_match`) on message **or** target, optionally `checker:` to restrict it (`ReportFilter::isViolationShouldBeIgnored`); an invalid regex throws `InvalidArgumentException`. `ignoreErrors` only hides findings in the report; the cause remains visible in the code.

**Spryker Code Upgrader** (SaaS/CI, only from the docs `ca/devscu/developing-with-spryker-code-upgrader.html`): connects to a base branch (GitHub, GitLab, Azure or self-hosted), analyzes the code on a schedule and creates a pull request with the required updates; does not change existing PRs, creates a new one only after the previous one has been merged/closed. Prerequisites: project built according to the upgradability guidelines, customizations and Upgrader PRs covered by automated tests. **The Upgrader does not do major upgrades.** Not verifiable locally: no Upgrader configuration in the repo; `vendor/spryker-sdk/` only contains `benchmark`, `composer-constrainer`, `evaluator`, `phpstan-spryker`, `spryk`, `utils`; the integrator is disabled via `config.allow-plugins` (`"spryker-sdk/integrator": false`). Internals of the Upgrader are `[Assumption]`/not evidenced. The Evaluator is the local pre-check of whether the Upgrader can update the project cleanly.

**Semantic versioning and constraints.** Major = BC break of the **public** module API, minor = backward-compatible feature, patch = bugfix; the private API may change even in minor/patch (docs `semantic-versioning-major-vs.-minor-vs.-patch-release`). Anyone who changes core module behavior at project level (extends non-API classes) should use `~` instead of `^` so that `composer update` only pulls patch versions (README composer-constrainer). In the release there is **no** `~` constraint for Spryker modules in `composer.json`: either no non-API core classes are extended or the constrainer never ran (not clarified).

**composer-constrainer.** `spryker-sdk/composer-constrainer ^0.2.8` (`require-dev`), command `code:constraint:modules` (`ComposerConstraintConsole::COMMAND_NAME` verified). Without an option it **changes** `composer.json` (`^` → `~`); `-d/--dry-run` only validates (exit 0/1, recommended CI hook); `-s/--strict`; `-o csv/--output-format=csv` (strict mode); `-w/--with-foreign`; `-v` version details. It finds extended API and non-API classes in `src/` (`ExtendedModuleFinder`); extended `*DependencyProvider` and `*Config` are excluded. **Finding in the release:** the console command is **not registered** (no hit in `src/Pyz/Zed/Console/ConsoleDependencyProvider.php`); `code:constraint:modules` is missing in `console list code`.

**Major updates.** Docs entry point `dg/dev/upgrade-and-migrate/upgrade-and-migrate.html` with guides for PHP 8.3/8.4/8.5, Symfony 6/7, Node.js/npm, Webpack 5, Twig v1→v3, API Platform and others. The release runs with PHP 8.4.27 (image `spryker/php:8.4`, → [Docker SDK workflow](30-docker-sdk-workflow.md); 8.3 is only the Composer lower bound `>=8.3`) and Symfony 6.4.48; whether 202610.0 supports Symfony 7 has not been checked.

## Guide

1. **Before every commit** run the Evaluator in offline-capable mode and fix findings in the project code instead of ignoring them:
   `docker/sdk cli vendor/bin/evaluator evaluate --format=compact --exclude-checkers=SPRYKER_SECURITY_CHECKER,OPEN_SOURCE_VULNERABILITIES_CHECKER`.
2. **Keep DependencyProviders clean:** no `if` logic except `is…Development…()`/`class_exists()`; `container->set()` callbacks call a method; plugin constructors only with simple arguments; plugin arrays no deeper than level 2.
3. **Activate the constrainer** (once, `[Assumption]` regarding execution): in `src/Pyz/Zed/Console/ConsoleDependencyProvider.php`, in the `isDevelopmentConsoleCommandsEnabled()` block, add `$commands[] = new \SprykerSdk\Zed\ComposerConstrainer\Communication\Console\ComposerConstraintConsole();` (pattern according to the README). Whether additional namespace/module resolution is needed (package in the namespace `SprykerSdk\`) has not been checked.
4. **CI:** `code:constraint:modules -d` (exit 1 = failure).
5. **Adjust constraints:** a run without `-d` changes `^`→`~` for modules with extended non-API classes, then `composer update` and commit `composer.lock`.
6. **Updates:** minor/patch regularly; run `evaluate` before every update; major per module: read the upgrade guide, look for BC breaks of the public API, adapt `src/Pyz`, regenerate transfers/schema (→ [Transfer](04-transfer.md), [Propel](05-propel-persistence.md)), tests (→ [Tests](26-tests.md)). The order is an `[Assumption]`; the docs do not prescribe a fixed sequence.
7. **Stay Upgrader-compatible:** tests for all customizations, Evaluator clean (→ [Checklist](../checklists/upgradability.md)).

Commands (Evaluator commands were executed in the container; output `[]` observed with `--format=json` without findings; `composer show` executed; `code:constraint:modules` is **not registered** in the release (not in `console list`) and can only be called after adding it to the `ConsoleDependencyProvider`; `composer outdated` `[live verified (202610.0)]`). The Evaluator without restriction ends with exit 1 because `OPEN_SOURCE_VULNERABILITIES_CHECKER` (14) and `SPRYKER_SECURITY_CHECKER` (27) report advisories (day-dependent, network required); the CI run below excludes both (exit 0). Add the Docker CLI to the PATH if needed: `export PATH=$PATH:/Applications/Docker.app/Contents/Resources/bin`.
```
docker/sdk cli vendor/bin/evaluator evaluate
docker/sdk cli vendor/bin/evaluator evaluate --format=compact          # like CI
docker/sdk cli vendor/bin/evaluator evaluate --format=json
docker/sdk cli vendor/bin/evaluator evaluate --checkers=PHP_VERSION_CHECKER,DEAD_CODE_CHECKER
docker/sdk cli vendor/bin/evaluator evaluate --exclude-checkers=SPRYKER_SECURITY_CHECKER,OPEN_SOURCE_VULNERABILITIES_CHECKER
docker/sdk cli vendor/bin/evaluator evaluate --path=src/Pyz/Zed/Console
docker/sdk cli vendor/bin/evaluator evaluate --file                    # creates ./report
docker/sdk cli composer outdated "spryker*/*" --direct                # [live verified (202610.0)] only ONE package argument allowed (Composer 2.11: several -> "Too many arguments")
docker/sdk cli composer show spryker/kernel                            # [live verified (202610.0)]
docker/sdk cli console code:constraint:modules -d                      # only after registering the console command, not in `console list` in the release
docker/sdk cli console code:constraint:modules -d -w
docker/sdk cli console code:constraint:modules                         # CHANGES composer.json
```
Project code run as in `ci.yml`:
```
vendor/bin/evaluator evaluate --format=compact --exclude-checkers=DISCOURAGED_PACKAGES_CHECKER,MINIMUM_ALLOWED_SHOP_VERSION,NPM_CHECKER,OPEN_SOURCE_VULNERABILITIES_CHECKER,PHP_VERSION_CHECKER,SPRYKER_DEV_PACKAGES_CHECKER,SPRYKER_SECURITY_CHECKER
```

## Examples

**Allowed condition in the DependencyProvider** (`src/Pyz/Zed/Console/ConsoleDependencyProvider.php`, excerpt from ~line 405, release):
```php
$commands = array_merge($commands, $propelCommands);

if ($this->getConfig()->isDevelopmentConsoleCommandsEnabled()) {
    $commands[] = new EventListenerDumpConsole();
    $commands[] = new CodeTestConsole();
    $commands[] = new CodeStyleSnifferConsole();
    $commands[] = new CodeArchitectureSnifferConsole();
    // ... further development commands
```
Accepted because `DependencyProviderAdditionalLogicChecker::isAcceptedCondition()` allows method calls whose name matches `/^is.*Development.*/`. Any other `if` condition would be reported (→ [Extending the core](03-extending-the-core.md)).

**Real Evaluator result on the unmodified release** (`vendor/bin/evaluator evaluate`, 2026-11-18): `Success!` for CONTAINER_SET_FUNCTION, DEAD_CODE, DEPENDENCY_PROVIDER_ADDITIONAL_LOGIC, SPRYKER_DEV_PACKAGES, DISCOURAGED_PACKAGES, MINIMUM_ALLOWED_SHOP_VERSION, MULTIDIMENSIONAL_ARRAY, NPM, PHP_VERSION, PLUGINS_REGISTRATION_WITH_RESTRICTIONS, SINGLE_PLUGIN_ARGUMENT. Findings: `OPEN_SOURCE_VULNERABILITIES_CHECKER` 2 (gRPC PHP GHSA-gvrc-7wf9-p7hg, phpseclib GHSA-w3p9-g4p2-h3rr), `SPRYKER_SECURITY_CHECKER` 27 lines "Security update available for the module …" (e.g. `spryker/security` 1.12.0 → 1.13.0, `spryker/glue-application` 1.81.1 → 1.82.1, `spryker-shop/customer-page` 2.88.1 → 2.90.0). Exit code 1. Project code architecture checkers clean; the findings only concern dependency freshness (snapshot, dependent on network access to the Release App).

**`tooling.yml`** (release, complete) and extended pattern (syntax from the Evaluator README, checker name from the release):
```yaml
evaluator:
    checkerConfiguration:
        - checker: NPM_CHECKER
          var:
              ALLOWED_SEVERITY_LEVELS: [critical]
    ignoreErrors:
        # generic: regex on message OR target (justification + ticket as a comment)
        - '#Pyz\\Zed\\LegacyThing\\LegacyThingDependencyProvider#'
        # only for one checker
        - messages:
              - '#deploy\.dev\.yml#'
          checker: PHP_VERSION_CHECKER
```
In the release, `tooling.yml` only contains the `checkerConfiguration` part.

**Restrictions docblock** (pattern from `RestrictionDocBlockValidator::RULE_PATTERN`, **not** from the release; found neither in `src/Pyz` nor in Spryker DependencyProviders, not run against the checker):
```php
/**
 * Restrictions:
 * - before {@link \Spryker\Zed\Foo\Communication\Plugin\FooPlugin}
 * - after {@link \Spryker\Zed\Bar\Communication\Plugin\BarPlugin} Reason for the order.
 *
 * @return array<\Spryker\Zed\Foo\Dependency\Plugin\FooPluginInterface>
 */
protected function getFooPlugins(): array
{
    return [
        new BarPlugin(),
        new FooPlugin(),
    ];
}
```
Both classes must be used in the same DependencyProvider, otherwise `Class "%s" is not used in current dependency provider`.

## Pitfalls

| Anti-pattern | Why it is problematic |
|---|---|
| Logic (`if`, `foreach`) in the DependencyProvider | Merging with core changes fails; only `is…Development…`/`class_exists` allowed |
| `$container->set(KEY, function () { return [ ... ]; })` | Array literal in the callback; call a method |
| Plugin arrays deeper than 2 levels | `MULTIDIMENSIONAL_ARRAY_CHECKER` |
| `new Plugin($a->get(), $b)` | `SINGLE_PLUGIN_ARGUMENT`; only scalars, constants, `new` without calls |
| Project class extends a core class and is no longer used | `DEAD_CODE_CHECKER` |
| Overriding core non-API classes in `src/Pyz`, constraint stays `^` | Minor updates can break behavior |
| Spryker dev packages in `require` | `SPRYKER_DEV_PACKAGES_CHECKER` |
| `ignoreErrors` as a permanent solution without justification | hides findings |
| `evaluate --checks=…` (README) | does not exist in the code, correct is `--checkers` |
| Copying core constants/methods, overriding private API | general docs rule, **not detected automatically** |
| Network checkers offline | `SPRYKER_SECURITY_CHECKER` needs the Release App; behavior without network `[Assumption]` (not tested) |
| Expecting the Upgrader to do a major update | manual according to the docs |

Further: constrainer console command not registered (see above). `checkers.yaml` points to `docs/scos/dev/…` URLs, the current docs use `docs/dg/dev/…` (redirect `[Assumption]`). The docs name PHP 8.4/8.5 and Symfony 7 as upgrade targets; the release already runs with PHP 8.4.27 (Composer lower bound 8.3) and Symfony 6.4. API Platform as a migration target is not installed in the release (→ [Glue Backend](13-glue-backend.md)).

Best practices: Evaluator in CI (pattern `ci.yml`, `scheduled.yml`); network/environment checkers separate from project code checkers; skip annotations/`ignoreErrors` only with justification and ticket; extensions via plugins/configuration instead of class inheritance.

## Checklist

- [ ] `evaluate` runs locally and in CI; project code checkers `Success!`
- [ ] No `if` logic except `is…Development…`/`class_exists` in DependencyProviders
- [ ] `container->set()` callbacks call methods
- [ ] Plugin constructors with simple arguments, nesting ≤ 2
- [ ] No unused project classes that extend core classes
- [ ] `ignoreErrors`/skip annotations justified
- [ ] `ComposerConstraintConsole` registered, `code:constraint:modules -d` in CI
- [ ] Extended non-API modules with `~` in `composer.json`
- [ ] Security findings prioritized and planned
- [ ] Tests exist for all customizations
- [ ] Major updates planned with the upgrade guide (module, PHP, Symfony, Node)

## Sources

Docs (relative to `https://docs.spryker.com/docs/`; partly only partial content): `dg/dev/guidelines/keeping-a-project-upgradable/{keeping-a-project-upgradable,run-the-evaluator-tool,ignore-evaluation-errors}.html`, `…/upgradability-guidelines/{multidimensional-array,plugin-registration-with-restrintions,additional-logic-in-dependency-provider,container-set-function,single-plugin-argument}.html`, `ca/devscu/developing-with-spryker-code-upgrader.html`, `dg/dev/architecture/module-api/{semantic-versioning-major-vs.-minor-vs.-patch-release,use-composer-constraint-for-customized-modules}.html`, `dg/dev/upgrade-and-migrate/upgrade-and-migrate.html`.

Code: `vendor/spryker-sdk/evaluator/{README.md,config/checkers.yaml,config/services.yaml,data/*.json,src/Console/Command/EvaluatorCommand.php,src/Filter/ReportFilter.php,src/Checker/*}`, `vendor/spryker-sdk/composer-constrainer/{README.md,src/SprykerSdk/Zed/ComposerConstrainer/…}`, `tooling.yml`, `composer.json` (lines 6, 281-284, 334, 348), `package.json` (`engines`), `.github/workflows/{ci,scheduled}.yml`, `src/Pyz/Zed/Console/ConsoleDependencyProvider.php`.

References: [Extending the core](03-extending-the-core.md), [Coding guidelines](27-coding-guidelines.md), [Features/dependencies](29-feature-integration.md), [Tests](26-tests.md), [Docker SDK workflow](30-docker-sdk-workflow.md); checklist [upgradability](../checklists/upgradability.md).

Deprecations/discrepancies: README option `--checks` does not exist; typo `restrintions` in the release config; `composer-constrainer/composer.json` requires `phpstan/phpstan ^1.2.0` (dev), the Evaluator `^2.1.0` (only for their own development).
