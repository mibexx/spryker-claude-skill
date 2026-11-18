# Review Checklist

Tick off before the merge/PR. Derived from the references on performance, tests, coding guidelines, upgradability, Docker SDK workflow, security and configuration. Commands in the container: `docker/sdk cli <command>` or `docker/sdk cli console <cmd>`; all commands were executed in the container `[live verified (202610.0)]`.

## Architecture and code style ([Coding Guidelines](../references/27-coding-guidelines.md))
- [ ] License docblock and `declare(strict_types = 1);`, namespace `Pyz\…`, file name = class name
- [ ] Parameters, return values and properties typed, `array<…>` docblocks
- [ ] `@method` annotations for Facade/Factory/Config/Client present
- [ ] Objects only in Factory `create*` methods, foreign dependencies via DependencyProvider, `getLocator()` only there
- [ ] Across modules only Facade (Zed) or Client (Yves/Glue); no Propel queries/repositories of foreign modules
- [ ] No bridges in project code (foreign Facade/Client directly via the locator in the DependencyProvider, Factory getter typed to the foreign module's interface); `ProjectNoBridgeRule` only applies in the project ruleset (see Static analysis). Facade without logic, Facade methods with Transfers
- [ ] No Propel/`new SpyEntity` in Business/Communication
- [ ] Module name without `Example`, `Test`, `Dummy`, `Antelope`
- [ ] Suppressions (`phpcs:ignore`, `@SuppressWarnings`, `@phpstan-ignore`) justified and minimal

## Static analysis ([Coding Guidelines](../references/27-coding-guidelines.md))
- [ ] `transfer:generate` and `dev:ide-auto-completion:generate` executed beforehand
- [ ] `code:sniff:style` without findings (first with `-f`, then without)
- [ ] `code:phpstan` (at least level 6, like CI) without new findings
- [ ] `code:sniff:architecture` (priority 2, **core ruleset**) without findings; CI parity `phpmd src/ … --minimumpriority 2`
- [ ] Project ruleset without findings (checks `ProjectNoBridgeRule` among others): `docker/sdk cli vendor/bin/phpmd src/Pyz text vendor/spryker/architecture-sniffer/src/Project/ruleset.xml`
- [ ] `propel:schema:validate` and `transfer:validate` green

## Tests ([Tests](../references/26-tests.md))
- [ ] Test in the path `tests/PyzTest/<App>/<Module>/<Suite>/`, module `codeception.yml` present
- [ ] Arrange/Act/Assert, `@group` annotations, `strict_types`, method name `testWhatShouldHappen`
- [ ] Mocks only on Facade/Client/Dependency, not on internal models
- [ ] New helper namespace `…\Business\BusinessHelper` (not the deprecated alias)
- [ ] Test data via `have*`/DataBuilder; transaction or cleanup active
- [ ] Glue Cests with `@depends loadFixtures`, group `EndToEnd`, OpenAPI schema checked
- [ ] `codecept build` run, `_generated/` not committed

## Performance ([Performance/Caching](../references/25-performance-caching.md))
- [ ] Yves read path without a Zed call (only Client → Storage/Search)
- [ ] Multiple reads via `getMulti`, no single `get` in loops
- [ ] No N+1 queries (join, `filterByIdIn`, bulk repository)
- [ ] New caches with warm-up in the install recipe
- [ ] Chunk sizes changed only after measurement

## Security and session ([Security/Session](../references/21-security-session.md))
- [ ] New Yves route in `CustomerConstants::CUSTOMER_SECURED_PATTERN` if only for logged-in users (otherwise reachable anonymously); mind the locale/store prefix in the regex
- [ ] New Zed route: access via ACL; public only via `IGNORABLE_ROUTE_PATTERN`
- [ ] Glue resources: `isProtected`/authorization strategy set deliberately
- [ ] Forms via `FormFactory` (CSRF active), no `|raw` for user input
- [ ] No secrets in code; `getenv()` only in `config/Shared/*.php`

## Configuration ([Configuration](../references/06-configuration.md))
- [ ] Values read via `*Constants` + `*Config`, not via `Config::get()` in Business/DependencyProvider
- [ ] Setting in the file that is actually loaded (not `config_default-docker.php` with `docker.dev`)
- [ ] `Config::get()` always with a default, array extensions via `array_merge`
- [ ] New env variables added to `deploy*.yml`, demo secrets not in production environments

## Errors, logging, mail ([Errors/Logging](../references/23-errors-logging-debugging.md), [Mail](../references/24-mail-message-broker.md))
- [ ] Exceptions logged with context (`['exception' => $e]`), own exceptions in `Business/Exception/`
- [ ] Sensitive fields in `LOG_SANITIZE_FIELDS`
- [ ] Mail type plugin registered, glossary keys imported for all locales

## Upgradability and workflow
- [ ] [Upgradability checklist](upgradability.md) worked through, Evaluator clean
- [ ] After changes, the matching generation/cache commands from [Docker SDK Workflow](../references/30-docker-sdk-workflow.md) ("Order after code changes") executed
- [ ] Generated folders (`src/Generated`, `src/Orm`, `data/`) and `docker/deployment/` not committed
