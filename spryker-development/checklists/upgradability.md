# Upgradability Checklist

Goal: updates (including those by the Spryker Code Upgrader) go in without manual work. Derived from [Upgradability](../references/28-upgradability.md), [Features/Dependencies](../references/29-feature-integration.md), [Coding Guidelines](../references/27-coding-guidelines.md) and [Extending the Core](../references/03-extending-the-core.md). Evaluator, console and `composer` commands were executed in the container `[live verified (202610.0)]`.

## DependencyProvider rules (Evaluator checkers)
- [ ] No `if` logic in `*DependencyProvider`, except the method call `is…Development…()` and `class_exists()` (`DEPENDENCY_PROVIDER_ADDITIONAL_LOGIC_CHECKER`)
- [ ] `$container->set(KEY, function () {…})` calls a method, does not return an array literal (`CONTAINER_SET_FUNCTION_CHECKER`)
- [ ] Plugin arrays at most 2 levels deep (`MULTIDIMENSIONAL_ARRAY_CHECKER`)
- [ ] `new Plugin(...)` only with int, float, string, constant, bool or `new` without further calls (`SINGLE_PLUGIN_ARGUMENT`)
- [ ] `Restrictions:` docblocks only in the pattern `* - before|after {@link <Class>}`, class used in the same provider (`PLUGINS_REGISTRATION_WITH_RESTRICTIONS_CHECKER`)
- [ ] No unused project classes that extend core classes (`DEAD_CODE_CHECKER`)

## Extension strategy ([Extending the Core](../references/03-extending-the-core.md))
- [ ] Order respected: configuration before plugin before inheritance/replacement
- [ ] No files changed in `vendor/`, no `replace_*` packages
- [ ] No private core API overridden, no core methods/constants copied (call `parent::`); not detected by the Evaluator, check in review
- [ ] Plugins only in Pyz DependencyProviders (subclass of the core provider), feature comment set
- [ ] Core config overridden via inheritance (`extends SprykerXxxConfig`), no copy
- [ ] No Pyz references in the core, no bridges in the project (fetch foreign Facade/Client directly via the locator). Check: `code:sniff:architecture` does not report bridges (core ruleset), therefore `docker/sdk cli vendor/bin/phpmd src/Pyz text vendor/spryker/architecture-sniffer/src/Project/ruleset.xml` (contains `ProjectNoBridgeRule`)

## Evaluator
- [ ] `docker/sdk cli vendor/bin/evaluator evaluate --format=compact --exclude-checkers=SPRYKER_SECURITY_CHECKER,OPEN_SOURCE_VULNERABILITIES_CHECKER` yields `Success!` for project code
- [ ] Full run (`evaluate`) regularly and before updates; the option is called `--checkers`/`--exclude-checkers` (not `--checks`)
- [ ] Evaluator in CI (pattern `.github/workflows/ci.yml`, `scheduled.yml`), network checkers separate from project code checkers
- [ ] `ignoreErrors` in `tooling.yml` and `@evaluator-skip-*` annotations only with justification and ticket
- [ ] Spryker dev packages only in `require-dev` (`SPRYKER_DEV_PACKAGES_CHECKER`)

## Composer and versions ([Features/Dependencies](../references/29-feature-integration.md))
- [ ] `ComposerConstraintConsole` registered in `ConsoleDependencyProvider` (not the case in the release; `code:constraint:modules` is missing from `console list`)
- [ ] After registration, `docker/sdk cli console code:constraint:modules -d` in CI
- [ ] Modules with extended non-API classes with `~` instead of `^` in `composer.json`; then `composer update`, `composer.lock` committed
- [ ] Features installed as a metapackage (`spryker-feature/*`), not as individual modules
- [ ] `composer.lock` checked in, updates in the container (`docker/sdk cli composer …`)

## Security and platform
- [ ] Findings from `SPRYKER_SECURITY_CHECKER` and `OPEN_SOURCE_VULNERABILITIES_CHECKER` prioritized and planned (release snapshot: 27 security updates, 2 vulnerabilities)
- [ ] `NPM_CHECKER` severity in `tooling.yml` set deliberately
- [ ] PHP/Symfony/Node versions planned against upgrade guides (release: PHP `>=8.3`, Symfony 6.4, Node `>=24.15.0`)

## Safeguarding
- [ ] Tests for all project customizations ([Tests](../references/26-tests.md)); mocks only on Facade/Client/Dependency
- [ ] Major updates planned manually with the module's upgrade guide (the Upgrader does not do majors)
- [ ] Transfers/Propel regenerated after updates ([Docker SDK Workflow](../references/30-docker-sdk-workflow.md))
