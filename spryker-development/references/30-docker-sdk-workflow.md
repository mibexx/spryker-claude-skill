# Docker SDK, deploy.yml, install recipes and local workflow

> Status: Release 202610.0 (commit d45396b8). Code and local SDK docs (`docker/docs/…`) read; the online docs URLs were not fetched, the contents were cross-checked against the files of the same name in the local SDK docs and the code (`[code-verified, docs not cross-checked]`, assumption: the online version matches the local one). Verified live in release 202610.0 (executed): `help`, `bootstrap deploy.dev.yml`, `up`, `up --build`, `up --assets`, `up --jobs`, `up -t`, `start`, `run`, `stop`, `restart`, `down`, `ps`, `cli`, `cli -x`, `cli -t`, `console`, `exec`, `wait`, `logs`, `jobs start|stop`, `testing codecept …`, `pull`, `build codebase|assets`, `config`, `vendor/bin/install -r docker [-d] -s …` and all console commands in the lists. **Not executed locally** (destroy data): `reset`, `clean-data`, `clean`, `prune`, `up --data`; `demo [<section>]` was executed and in doing so **dropped all tables of the region** (restored from a dump), see pitfalls. Two behaviors the code does not reveal: `docker/sdk up`/`testing` need a TTY (without one: `ERROR: failed to get console: provided file is not a console`), and `up` recreates containers, so `/data/node_modules` in the `cli` container is empty (→ `up --assets`). On macOS, Docker lives under `/Applications/Docker.app/Contents/Resources/bin` (add it to the PATH if needed).

This file contains the **command order after code changes** (section "Order after code changes"), which SKILL.md refers to.

## Purpose

Understand the local environment and deployment: structure of `deploy.yml`, Docker SDK commands, mount modes, install recipes (`config/install/*.yml`, `vendor/bin/install`), Xdebug and the correct order of generation/cache commands after changes. PHP configuration files → [Configuration](06-configuration.md); workers/scheduler → [Console/Cron/Queue](22-console-cron-queue.md); testing mode → [Tests](26-tests.md).

## Concepts

**`deploy.yml` keys** (`deploy.yml`, `deploy.dev.yml`):

| Key | Meaning |
|---|---|
| `version: '0.1'` | Format version |
| `namespace`, `tag` | Prefix/tag for the Docker project and images; dev: `spryker_b2b_dev`/`dev`, container names `<namespace>_<applicationname>_1` (e.g. `spryker_b2b_dev_yves_eu_1`); `deploy.yml`: `spryker_b2b`/`1.0` |
| `environment` | becomes `APPLICATION_ENV` (`docker.dev`, `docker.production`) and selects `config/Shared/config_default-<env>.php` (→ [Configuration](06-configuration.md)) |
| `pipeline` | optional, name of the install recipe; default `docker` (`SPRYKER_PIPELINE=${SPRYKER_PIPELINE:-docker}`); CI files set e.g. `docker.ci.acceptance-no-mp` |
| `image` | `tag: spryker/php:8.4`, `php.enabled-extensions` (here `otel`), `php.ini`, `environment` (custom variables in all PHP containers, e.g. `SPRYKER_DYNAMIC_STORE_MODE: true`), `node.version: 24`, `node.npm: 10` |
| `composer` | `mode` (flags for `composer install`; dev `''`, prod `--no-dev`), `autoload` (prod `--classmap-authoritative`) |
| `assets` | `mode: development`/`production` (prod with `spryker/nginx-brotli`) |
| `regions` | per region (`EU`, `US`): `mail.sender`, `database`, `broker.namespace`, `key_value_store.namespace`, `search.namespace`; **no** `stores:` anymore (Dynamic Store, → [Multi-Store/i18n](19-multi-store-i18n.md)) |
| `groups.<g>.applications.<a>` | Instance; key is free, `application:` = type (`yves`, `glue`, `glue-storefront`, `glue-backend`, `backoffice`, `backend-gateway`, `zed`) |
| `…endpoints` | Host names (`yves.eu.spryker.local`) with `region`, optionally `services.session.namespace`, `primal: true`, `entry-point` (`Configurator`, `BackendApi`) |
| `services` | `database` (mysql, `mariadb-10.4`), `broker` (rabbitmq 4.1), `session`/`key_value_store` (valkey), `search` (elastic 7.10), `scheduler` (jenkins 2.492.3), `mail_catcher` (mailpit), `swagger`, `dashboard`, `webdriver` (chromedriver), dev additionally `redis-gui`; `endpoints` with `protocol: tcp` publish ports (`localhost:3306`, `5672`, `16379`, `9200`) |
| `docker` | `ssl`, `debug.enabled`, `debug.xdebug.enabled`, `testing.region` (dev) or `testing.store` (prod file, probably a leftover `[Assumption]`), `mount` |

Endpoints use `*.spryker.local`; the host needs `/etc/hosts` entries (`./docker/sdk install` lists them; set on this machine `[Assumption]`).

**Inheritance.** In the release there is **no** inheritance between `deploy.yml`, `deploy.dev.yml` and the `deploy.ci.*.yml`: all are full copies (no `imports` per grep). Changes must be maintained in all relevant files. The SDK supports inheritance via `imports:` (code `PrepareDeployFileTransferExecutor`, `DeployFileGeneratorConstants::YAML_IMPORTS_KEY`; templates first at project level, then SDK base); the local SDK docs write `import:` in one place, the code only shows `imports` (docs typo `[Assumption]`). File selection: `docker/sdk bootstrap <file>`; without an argument `deploy.local.yml`, otherwise `deploy.yml` (`deploy.local.yml` does not exist).

**Commands** (dispatch in `docker/sdk`; `boot|bootstrap` and `config` run in the SDK, all others via `docker/deployment/<project>/deploy`, which only exists after bootstrap; list verified via `./docker/sdk help`):

| Command | Effect |
|---|---|
| `bootstrap [-v] [file]` | generates `docker/deployment/default/` from the deploy file |
| `config` | prints the resolved deploy file (`project.yml`) |
| `up [--build] [--assets] [--data] [--jobs]` | builds and starts everything; steps that already ran are skipped unless forced by option |
| `run`/`start`, `stop`, `restart`, `down`, `ps` | Lifecycle |
| `cli` / `cli <cmd>` | interactive shell or command in the `cli` container (e.g. `./docker/sdk cli composer install`) |
| `console <cmd>` | Short form for `vendor/bin/console <cmd>` |
| `testing [<cmd>]` | like `cli`, but in testing mode: sets `SPRYKER_TESTING_ENABLE=1` SDK-internally (`Compose::ensureTestingMode` starts the webdriver container if needed), inside the container `SPRYKER_TESTING_ENABLED=1` is set via `env/cli/testing.env` (plus webdriver/host variables); waits for database, broker, search, key_value_store, session, webdriver. `APPLICATION_ENV` stays unchanged (`testing.sh`, `testing.env.twig`) |
| `exec <service> <cmd>` | Command in a service container |
| `demo` / `demo <section>` | **Destructive:** `Data::load --force` for each region: `queue` setup, then `init-storages-per-region` with `propel:tables:drop` (drops all tables of the region), then `demodata` (or the section). Do not run against data you want to keep `[live verified (202610.0)]` (run aborted at `propel:diff`, DB had to be restored from the dump) |
| `clean-data` | deletes all volumes (database, Redis, search) |
| `jobs start/stop/clean` | Scheduler jobs (→ [Console/Cron/Queue](22-console-cron-queue.md)) |
| `logs` | shows "All logs are now available at http://spryker.local/logs", asks `(y/n)` and on `y` continuously tails `/var/log/spryker/exception.log` (no options, abort with Ctrl-C). Exceptions additionally go to the containers' stderr: `docker logs <container>` |
| `wait <service>...` | waits for services |
| `pull`, `build [images\|codebase\|assets]`, `export` | `export` baked mode only |
| `reset` | `down` + delete volumes + `up --build --assets --data --jobs` (demo data anew, **data is lost**) |
| `clean`, `prune [--f] [--a]` | remove containers/images/volumes or all Docker data and project artifacts |
| `sync logs` | starts `mutagen sync monitor` (runs until aborted, hanging in scripts; marked as deprecated in the code) |

Options for `cli`, `console`, `testing`: `-v`, `-x` (Xdebug), `-t` (testing mode); variables `VERBOSE=1`, `SPRYKER_XDEBUG_ENABLE=1`, `SPRYKER_TESTING_ENABLE=1`. Multiple projects: `./docker/sdk -p <name> …` or `SPRYKER_PROJECT_NAME=<name>` (default `default`).

**Mount modes** (`docker/docs/02-development/01-choosing-a-mount-mode.md`, `docker/bin/sdk/mount/*.sh`): `baked` (code in the image, not changeable; demo/production), `native` (bind mount, Linux/WSL2), `mutagen` (macOS, fast), `docker-sync` (macOS/Windows WSL1), `nfs` (script present, not configured, outdated `[Assumption]`). `deploy.dev.yml` selects per platform `native` (linux), `docker-sync` (windows), `mutagen` (macos); on this machine `mutagen`. Files created by the container (`src/Generated`, `src/Orm`, `data/`) are synced back to the host; a stopped session makes generated files missing in the IDE `[Assumption]`.

**Install recipes** (module `spryker/install`; `vendor/bin/install` is a Composer proxy). Invocation (`InstallConsole`):
```
vendor/bin/install [-r <recipe>] [-s <section>]... [-g <group>]... [-x <exclude>]... [-e <include-excluded>]... [-d] [-i] [-b] [-a] [-l] [<store>]
```
`-r` recipe (default `APPLICATION_ENV`, otherwise `config/Shared/console_env_local.php`, otherwise `production`), `-s` sections, `-g` groups, `-x` exclude, `-e` include those marked `excluded: true` anyway, `-d` dry run, `-i/-b/-a` prompts/stop, `-l` log to `data/install/logs/<date>.log`. Resolution `config/install/<recipe>.yml`, `config/install/<recipe>`, `<root>/<recipe>`, `<recipe>`; otherwise `ConfigurationFileNotFoundException`.

Recipe files: `{docker,development,production,destructive,pre-deploy,dynamic-store,testing,sniffs,ci,ci.restore-db.stage2,docker.ci.acceptance,docker.ci.acceptance-no-mp,docker.ci.api,docker.ci.api.full}.yml` as well as `config/install/{EU,US}/*.dynamic-store-off.yml`. Deviation from the local SDK docs: there `EU/{destructive,pre-deploy,production}.yml` and `docker.ci.functional.yml`; in the release the code applies. Structure: `env`, optionally `command-timeout`/`stores`, `sections.<name>.<command>` with `command`, `stores`, `groups`, `pre`, `condition`, `breakOnFailure`, `timeout`, `excluded`.

Sections of the `docker` recipe:

| Section | Invocation | Content |
|---|---|---|
| `build` | `Codebase::build`: `vendor/bin/install -r $SPRYKER_PIPELINE -s build -s build-development` | `transfer:generate`, router cache (Yves, Backoffice, Backend-Gateway), `oms:process-cache:warm-up`, `twig:cache:warmer`, `navigation:build-cache`, `rest-api:build-request-validation-cache`, `propel:schema:copy`, `propel:model:build`, `transfer:entity:generate`, `search:setup:source-map`, `translator:generate-cache`, `oauth:scope-collection-file:generate`, `open-telemetry:generate` |
| `build-development` | see above | `transfer:generate`, `transfer:databuilder:generate`, `dev:ide-auto-completion:generate`, `rest-api:generate:documentation` |
| `build-production` | baked/prod only (`excluded: true`) | `cache:class-resolver:build`, `twig:template:warmer` (Zed and Yves) |
| `build-static`, `build-static-<mode>` | `assets/mount.sh` | `frontend:project:install-dependencies`, `frontend:yves:build`, `frontend:zed:build`, `frontend:date-time-product-configurator:build` (`-e production` in prod mode) |
| `scheduler-setup`/`-suspend`/`-clean` | `service/scheduler/jenkins.sh` | `scheduler:setup` etc. |
| `init-storages-per-store` | `Data::load` per store | `queue:setup`, `search:setup:sources` |
| `init-storages-per-region` | `Data::load` per region | `propel:schema:copy`, `propel:pg-sql-compat`, `propel:migration:delete`, `propel:tables:drop`, `propel:diff`, `propel:migrate`, `propel:migration:delete`, `setup:init-db` |
| `demodata` | `Data::load` / `demo <section>` | `queue:setup`, `data:import --config=data/import/local/full_${SPRYKER_REGION}.yml`, `search:setup:sources`, `product-label:relations:update`, `vendor/bin/glue glue-api:controller:cache:warm-up`, `vendor/bin/glue api:generate:documentation` (both via the Glue binary, not via `console`) |

`Data::load` first calls `-s clean-storage -s init-storage`; these sections are missing in `docker.yml`, the runner silently skips them ("Install docker finished in 0s") `[live verified (202610.0)]`. Further recipes: `development.yml` (legacy with stores `DE`/`AT`/`US`, non-Docker), `production.yml`/`pre-deploy.yml`/`destructive.yml`/`dynamic-store.yml` (cloud pipeline; `destructive` deletes storage and DB, never on production data), `testing.yml` (`devtest`, `codecept build`, `code:fixtures`), `docker.ci.*.yml`/`ci.yml` (CI via `pipeline:`).

**Xdebug.** Prerequisite `docker.debug.enabled: true` and `docker.debug.xdebug.enabled: true` (dev yes, `deploy.yml` both `false`); the deploy template sets `SPRYKER_XDEBUG_MODE_ENABLE=1`, otherwise `Compose::verboseMode` reports "Debugging is disabled in deploy.yml". Enable per invocation: `./docker/sdk up -x` (web), `./docker/sdk cli -x`, `console -x <cmd>`, `testing -x` (or `SPRYKER_XDEBUG_ENABLE=1`); in the CLI `PHP_INI_SCAN_DIR=:/usr/local/etc/php/debug.conf.d` (loads `69-xdebug.ini`). Without `-x` the extension is not loaded (`php -m | grep -i xdebug` in the container: empty, verified). `69-xdebug.ini`: `xdebug.mode=debug,coverage`, `start_with_request=yes`, `client_host=${SPRYKER_XDEBUG_HOST_IP}`, `client_port=9003`, `max_nesting_level=1000`; Xdebug 2 directives (port 9000) are legacy. PhpStorm docs: ports `9000,9003`, server `spryker`, host `spryker`, port `80`, path mapping to `/data`. Host IP can be overridden with `docker.debug.xdebug.custom_host_ip`. After changing `docker.debug`: `./docker/sdk bootstrap deploy.dev.yml && ./docker/sdk up`. Observation: `./docker/sdk help` shows "-x [DISABLED]" here despite the enabled deploy file, probably display only `[Assumption]`.

**After `deploy.yml` changes:** always `./docker/sdk bootstrap <file>` and `./docker/sdk up` (if needed `--build`). `docker/deployment/` is generated; do not edit.

## Order after code changes

All commands in the `cli` container (`docker/sdk cli …` or `docker/sdk cli console …`; short form `docker/sdk console …`). The order follows the `docker` recipe, section `build` or `init-storages-per-region`. All commands in this list were executed `[live verified (202610.0)]`, deviations are noted alongside.

Overall sequence when several kinds of changes come together:
1. `composer install` or `composer dump-autoload` (new packages/classes; prod additionally `cache:class-resolver:build`)
2. **Transfer:** `transfer:generate` (optionally `transfer:databuilder:generate`, `dev:ide-auto-completion:generate`)
3. **Propel:** `propel:schema:copy` → `propel:model:build` → `propel:diff` → `propel:migrate` → `transfer:entity:generate` → then `transfer:generate` if entity transfers are used (short form `propel:install`, → [Propel](05-propel-persistence.md))
4. `search:setup:sources` (+ `search:setup:source-map`), `queue:setup`
5. **Router cache:** `vendor/bin/yves router:cache:warm-up`, `router:cache:warm-up:backoffice`, `router:cache:warm-up:backend-gateway`
6. **Navigation:** `navigation:build-cache`
7. Further caches: `oms:process-cache:warm-up`, `twig:cache:warmer`, `translator:generate-cache`, `rest-api:build-request-validation-cache`, `vendor/bin/glue glue-api:controller:cache:warm-up`
8. **Frontend:** `frontend:yves:build`, `frontend:zed:build`
9. Data: `data:import …`, `publish:trigger-events`
10. Unclear cache state: `cache:empty-all`, then run the warm-ups again

Per change:

| Change | Commands in this order |
|---|---|
| `*.transfer.xml` new/changed | `transfer:generate`, optionally `transfer:databuilder:generate` (tests), `dev:ide-auto-completion:generate` (IDE, → [Transfer](04-transfer.md)) |
| `*.schema.xml` new/changed | `propel:schema:copy` → `propel:model:build` → `propel:diff` → `propel:migrate` → `transfer:entity:generate`; `transfer:generate` if entity transfers are used (→ [Propel](05-propel-persistence.md)) |
| New Pyz controller, route | Yves: `vendor/bin/yves router:cache:warm-up`; Backoffice: `router:cache:warm-up:backoffice`; Backend-Gateway: `router:cache:warm-up:backend-gateway` (→ [Yves routing](14-yves-controller-routing.md)) |
| Twig template | not needed in dev, `twig:cache:warmer` for warming up; prod `twig:template:warmer` |
| Back Office navigation (`navigation.xml`) | `navigation:build-cache` (removal: `navigation:cache:remove`, → [Back Office](18-back-office.md)) |
| OMS process XML | `oms:process-cache:warm-up` (→ [OMS](10-oms-state-machine.md)) |
| Glue resource | `rest-api:build-request-validation-cache`, `vendor/bin/glue glue-api:controller:cache:warm-up` (→ [Glue Storefront](12-glue-storefront.md)) |
| Zed translations | `translator:generate-cache` |
| Search mapping | `search:setup:sources` (+ `search:setup:source-map`, → [Client/Storage/Search](08-client-storage-search.md)) |
| New queue | `queue:setup` (→ [Console/Cron/Queue](22-console-cron-queue.md)) |
| New classes/modules (class resolution) | `composer dump-autoload`, prod `cache:class-resolver:build` |
| SCSS/TS (Yves/Zed) | `frontend:yves:build`, `frontend:zed:build` (→ [Atomic Frontend](15-yves-frontend-atomic.md)) |
| Publish data missing in storage | `publish:trigger-events` (→ [Publish & Sync](07-publish-sync.md)) |
| Everything unclear | `cache:empty-all` |

Fastest reliable way to rebuild Pyz without a database rebuild: `docker/sdk cli vendor/bin/install -r docker -s build -s build-development` (exactly the invocation of `Codebase::build`).

## Instructions

1. **Initial installation:** `./docker/sdk bootstrap deploy.dev.yml`, `./docker/sdk up` (force sub-steps: `--build --assets --data`).
2. **Daily:** change code, run the matching row of the table above, check in the browser. Always run Composer in the container: `./docker/sdk cli composer …`.
3. **Debugging:** `./docker/sdk up -x` or `./docker/sdk cli -x`, breakpoints in PhpStorm (port 9003, mapping `/data`).
4. **Hook your own command into the build:** add a section to `config/install/docker.yml` (project file), do not change SDK scripts (example below); recipe changes take effect immediately, re-bootstrap only when the deploy file changed.
5. **Re-run just one section:** `./docker/sdk cli vendor/bin/install -r docker -s demodata` or `./docker/sdk demo demodata`; dry run `-d`.
6. **Evaluator/quality:** → [Upgradability](28-upgradability.md), [Coding guidelines](27-coding-guidelines.md).

Cheat sheet (all lines executed `[live verified (202610.0)]`, except the ones marked destructive `demo`, `clean-data`, `reset`; `up`/`testing` need a TTY):
```
./docker/sdk help
./docker/sdk bootstrap deploy.dev.yml
./docker/sdk up | up -x | up --build | up --assets | up --jobs   # `up --data` not executed (destructive)
./docker/sdk ps | stop | restart | down
./docker/sdk cli
./docker/sdk cli composer install
./docker/sdk console transfer:generate -vvv
./docker/sdk console cache:empty-all
./docker/sdk cli vendor/bin/install -r docker -s build -s build-development
./docker/sdk cli vendor/bin/install -r docker -d
./docker/sdk testing codecept build
./docker/sdk start                              # back from testing mode (scheduler runs again, webdriver off)
./docker/sdk demo                               # DESTRUCTIVE: drops all tables of the region (propel:tables:drop)
./docker/sdk clean-data                         # not executed (deletes volumes)
./docker/sdk reset                              # not executed, data is lost
./docker/sdk logs                               # points to http://spryker.local/logs, on `y` exception.log; container output: docker logs <container>
./docker/sdk sync logs                          # blocks (mutagen sync monitor), Ctrl-C
```

## Examples

**Excerpt `deploy.dev.yml`** (real): application, endpoint, service, mount, debug:
```yaml
groups:
    EU:
        region: EU
        applications:
            yves_eu: # custom name to differentiate between two applications, incorrect for the cloud usage
                application: yves
                endpoints:
                    yves.eu.spryker.local:
                        region: EU
                        services:
                            session:
                                namespace: 2
            boffice_eu:
                application: backoffice
                endpoints:
                    backoffice.eu.spryker.local:
                        region: EU
                        primal: true
                        services:
                            session:
                                namespace: 3
services:
    database:
        engine: mysql
        version: mariadb-10.4
        root:
            username: 'root'
            password: 'secret'
        endpoints:
            localhost:3306:
                protocol: tcp
docker:
    debug:
        enabled: true
        xdebug:
            enabled: true
    mount:
        native:
            platforms:
                - linux
        docker-sync:
            platforms:
                - windows
        mutagen:
            platforms:
                - macos
```

**Custom recipe section** (proposal, not in the release; structure matches the existing recipe, `example:warm-up` is a placeholder), `config/install/docker.yml`:
```yaml
sections:
    demodata:
        # … existing commands …
        example-warm-up:
            command: 'vendor/bin/console example:warm-up'
```
Only this section: `./docker/sdk cli vendor/bin/install -r docker -s demodata`.

**Directories (release):** `deploy.yml`, `deploy.dev.yml`, `deploy.ci.*.yml` (7 files), `deploy.aws-env-template.yml`, `tooling.yml`, `config/install/*.yml`, `docker/` (SDK clone: `sdk`, `bin/`, `context/`, `generator/`, `docs/`), `docker/deployment/default/` (generated: `deploy`, `docker-compose.yml`, `project.yml`, `env/*.env`), `vendor/spryker/{install,installer,setup,setup-frontend}/`. Running containers (`docker ps`, namespace `spryker_b2b_dev`): `gateway`, `frontend`, `yves_{eu,us}`, `boffice_{eu,us}`, `glue_{eu,us}`, `gluestorefront_{eu,us}`, `gluebackend_{eu,us}`, `backgw_{eu,us}`, `backapi_{eu,us}`, `broker`, `database`, `scheduler`, `swagger` and others.

## Pitfalls

- Deploy files drift apart (full copies); carry changes over into all relevant files or deliberately change only locally.
- A change to `deploy.dev.yml` without `bootstrap` has no effect. `image: environment:` is embedded in images and needs `up --build`.
- Editing `docker/deployment/` by hand: gets overwritten on the next `bootstrap`; do not change `docker/` itself (external SDK clone, upgradability).
- `composer install` on the host instead of in the container: wrong PHP version/extensions.
- `reset`/`clean-data` delete volumes including database, Redis, search; `demo`/`up --data` drop the tables of the region (`propel:tables:drop`).
- `up`/`down` recreates the `cli` container: `/data/node_modules` is empty afterwards, `npm run yves|zed` aborts with `ERR_MODULE_NOT_FOUND` (e.g. `fast-glob`). Remedy: `docker/sdk up --assets`. `frontend:zed:install-dependencies` is deprecated and fails (exit 1), use `frontend:project:install-dependencies` or `up --assets` instead.
- `up --build` deletes `src/Generated` and re-runs `Codebase::build` (sections `build`, `build-development`); the Glue controller cache and the routers of the other regions are missing afterwards: `vendor/bin/glue glue-api:controller:cache:warm-up`, for US prefixed with `SPRYKER_CURRENT_REGION=US SPRYKER_REGION=US APPLICATION_STORE=US`.
- Do not version generated files in `src/Generated`, `src/Orm`, `data/` and do not change them by hand.
- `Class … not found`: forgot transfer/Propel/router cache (table above); prod additionally autoloader/`cache:class-resolver:build`.
- Mutagen session hangs: `mutagen sync list` (status "Watching for changes"), `./docker/sdk sync logs` blocks (monitor). The host sees freshly created container files with a delay (seconds); check sizes and existence only after a short wait.
- Wrong recipe name with `-r`: name = file name without `.yml`, otherwise `ConfigurationFileNotFoundException`.
- Xdebug without `-x`: breakpoints do not trigger; port 9003.
- Secrets: do not carry demo passwords (`secret`, `change123`) in `deploy*.yml` over into real environments (→ [Configuration](06-configuration.md)).
- The `*.dynamic-store-off.yml` recipes probably only apply to projects without Dynamic Store (`SPRYKER_DYNAMIC_STORE_MODE` is `true` here); the selection mechanism lives in the cloud pipeline `[Assumption]`.
- Docker CLI not in the PATH (macOS, as happened during testing): `export PATH=$PATH:/Applications/Docker.app/Contents/Resources/bin`, otherwise "'docker' is not found".

## Checklist

- [ ] After a `deploy.yml` change: `bootstrap` + `up`
- [ ] Composer/console only in the container (`docker/sdk cli …`)
- [ ] After a transfer/schema/route/navigation/OMS/Glue change, the matching commands from the table were executed
- [ ] Frontend built after SCSS/TS change
- [ ] Custom build steps as a section in `config/install/docker.yml`
- [ ] Xdebug only when needed (`-x`), not in production
- [ ] No `reset`/`clean-data`/`destructive` on environments with valuable data
- [ ] Generated folders not committed, `docker/deployment/` not edited

## Sources

Docs (base `https://docs.spryker.com/docs/dg/dev/sdks/the-docker-sdk/`, not fetched; cross-checked locally): `the-docker-sdk.html`, `docker-sdk-quick-start-guide.html`, `docker-sdk-configuration-reference.html`, `running-commands-with-the-docker-sdk.html`, `choosing-a-mount-mode.html`, `installation-recipes-of-deployment-pipelines.html`, `reset-docker-environments.html`.

Code/local docs: `deploy.yml`, `deploy.dev.yml`, `deploy.ci.*.yml`, `docker/sdk`, `docker/bin/command/{install,compose,cli,runtime,build,internal}/*.sh`, `docker/bin/sdk/{codebase,assets,data,mount}/*.sh`, `docker/bin/sdk/compose.sh`, `docker/generator/src/templates/deploy.bash.twig`, `docker/generator/deploy-file-generator/src/Processor/Executor/PreExecutors/PrepareDeployFileTransferExecutor.php`, `docker/context/php/debug/etc/php/debug.conf.d/69-xdebug.ini`, `docker/docs/02-development/{01-choosing-a-mount-mode,05-configuring-debugging,07-configuring-a-mount-mode}.md`, `docker/docs/07-deploy-file/*.md`, `config/install/*.yml`, `vendor/spryker/install/src/Spryker/Zed/Install/{InstallConfig.php,Communication/Console/InstallConsole.php,Business/Configuration/Loader/ConfigurationLoader.php}`.

References: [Configuration](06-configuration.md), [Console/Cron/Queue](22-console-cron-queue.md), [Tests](26-tests.md), [Performance/Caching](25-performance-caching.md), [Upgradability](28-upgradability.md).

Deprecations/deviations: `docker/bin/command/install/clean.sh` contains `sync clean # TODO deprecated, use Registry::Flow::addAfterDown in mounts` (SDK-internal); local SDK docs deviate (`EU/production.yml`, `stores:` per region, `import:`), the code applies; Xdebug 2 directives in `69-xdebug.ini` are legacy; recipe `development.yml` is not authoritative for Docker `[Assumption]`; `export` (baked only) and `build` not for development. Stack defaults in the release: PHP 8.4 image, Node 24, MariaDB 10.4, Elasticsearch 7.10 (the SDK additionally contains `docker/context/opensearch` and `otel-stack`; docs reference not checked).
