# Docker SDK, deploy.yml, Install-Recipes und lokaler Workflow (Recherche T-30)

> Status: Release 202610.0 (Commit d45396b8). Code und lokale SDK-Doku (`docker/docs/…`) gelesen; die Online-Doku-URLs wurden nicht abgerufen, die Inhalte über die gleichnamigen Dateien der lokalen SDK-Doku und den Code abgeglichen (`[code-verifiziert, Doku nicht abgeglichen]`, Annahme: Online-Fassung entspricht der lokalen). Live verifiziert (ausgeführt, Protokoll `live-verification.md`): `help`, `bootstrap deploy.dev.yml`, `up`, `up --build`, `up --assets`, `up --jobs`, `up -t`, `start`, `run`, `stop`, `restart`, `down`, `ps`, `cli`, `cli -x`, `cli -t`, `console`, `exec`, `wait`, `logs`, `jobs start|stop`, `testing codecept …`, `pull`, `build codebase|assets`, `config`, `vendor/bin/install -r docker [-d] -s …` und alle Konsolenbefehle der Listen. **Nicht lokal ausgeführt** (zerstören Daten): `reset`, `clean-data`, `clean`, `prune`, `up --data`; `demo [<section>]` wurde ausgeführt und hat dabei **alle Tabellen der Region gelöscht** (Wiederherstellung per Dump), siehe Fallstricke. Zwei Verhaltensweisen, die der Code nicht verrät: `docker/sdk up`/`testing` brauchen ein TTY (ohne: `ERROR: failed to get console: provided file is not a console`), und `up` erzeugt Container neu, sodass `/data/node_modules` im `cli`-Container leer ist (→ `up --assets`). Docker liegt auf macOS unter `/Applications/Docker.app/Contents/Resources/bin` (ggf. in den PATH aufnehmen).

Diese Datei enthält die **Befehlsreihenfolge nach Codeänderungen** (Abschnitt „Reihenfolge nach Codeänderungen“), auf die SKILL.md verweist.

## Zweck

Lokale Umgebung und Deployment verstehen: Aufbau von `deploy.yml`, Docker-SDK-Befehle, Mount-Modi, Install-Recipes (`config/install/*.yml`, `vendor/bin/install`), Xdebug und die richtige Reihenfolge der Generierungs-/Cache-Befehle nach Änderungen. PHP-Konfigurationsdateien → [Konfiguration](06-konfiguration.md); Worker/Scheduler → [Console/Cron/Queue](22-console-cron-queue.md); Testing-Modus → [Tests](26-tests.md).

## Konzepte

**`deploy.yml`-Schlüssel** (`deploy.yml`, `deploy.dev.yml`):

| Schlüssel | Bedeutung |
|---|---|
| `version: '0.1'` | Format-Version |
| `namespace`, `tag` | Prefix/Tag für Docker-Projekt und Images; dev: `spryker_b2b_dev`/`dev`, Containernamen `<namespace>_<applikationsname>_1` (z. B. `spryker_b2b_dev_yves_eu_1`); `deploy.yml`: `spryker_b2b`/`1.0` |
| `environment` | wird `APPLICATION_ENV` (`docker.dev`, `docker.production`) und wählt `config/Shared/config_default-<env>.php` (→ [Konfiguration](06-konfiguration.md)) |
| `pipeline` | optional, Name des Install-Recipes; Default `docker` (`SPRYKER_PIPELINE=${SPRYKER_PIPELINE:-docker}`); CI-Dateien setzen z. B. `docker.ci.acceptance-no-mp` |
| `image` | `tag: spryker/php:8.4`, `php.enabled-extensions` (hier `otel`), `php.ini`, `environment` (eigene Variablen in alle PHP-Container, z. B. `SPRYKER_DYNAMIC_STORE_MODE: true`), `node.version: 24`, `node.npm: 10` |
| `composer` | `mode` (Flags für `composer install`; dev `''`, prod `--no-dev`), `autoload` (prod `--classmap-authoritative`) |
| `assets` | `mode: development`/`production` (prod mit `spryker/nginx-brotli`) |
| `regions` | je Region (`EU`, `US`): `mail.sender`, `database`, `broker.namespace`, `key_value_store.namespace`, `search.namespace`; **kein** `stores:` mehr (Dynamic Store, → [Multi-Store/i18n](19-multi-store-i18n.md)) |
| `groups.<g>.applications.<a>` | Instanz; Schlüssel frei, `application:` = Typ (`yves`, `glue`, `glue-storefront`, `glue-backend`, `backoffice`, `backend-gateway`, `zed`) |
| `…endpoints` | Hostnamen (`yves.eu.spryker.local`) mit `region`, optional `services.session.namespace`, `primal: true`, `entry-point` (`Configurator`, `BackendApi`) |
| `services` | `database` (mysql, `mariadb-10.4`), `broker` (rabbitmq 4.1), `session`/`key_value_store` (valkey), `search` (elastic 7.10), `scheduler` (jenkins 2.492.3), `mail_catcher` (mailpit), `swagger`, `dashboard`, `webdriver` (chromedriver), dev zusätzlich `redis-gui`; `endpoints` mit `protocol: tcp` publizieren Ports (`localhost:3306`, `5672`, `16379`, `9200`) |
| `docker` | `ssl`, `debug.enabled`, `debug.xdebug.enabled`, `testing.region` (dev) bzw. `testing.store` (prod-Datei, vermutlich Überbleibsel `[Annahme]`), `mount` |

Endpoints nutzen `*.spryker.local`; der Host braucht `/etc/hosts`-Einträge (`./docker/sdk install` listet sie; auf dieser Maschine gesetzt `[Annahme]`).

**Vererbung.** Im Release gibt es **keine** Vererbung zwischen `deploy.yml`, `deploy.dev.yml` und den `deploy.ci.*.yml`: alles Vollkopien (kein `imports` per grep). Änderungen müssen in allen relevanten Dateien gepflegt werden. Der SDK kann Vererbung per `imports:` (Code `PrepareDeployFileTransferExecutor`, `DeployFileGeneratorConstants::YAML_IMPORTS_KEY`; Vorlagen erst Projektebene, dann SDK-Basis); die lokale SDK-Doku schreibt an einer Stelle `import:`, im Code nur `imports` belegt (Doku-Tippfehler `[Annahme]`). Dateiwahl: `docker/sdk bootstrap <datei>`; ohne Argument `deploy.local.yml`, sonst `deploy.yml` (`deploy.local.yml` existiert nicht).

**Befehle** (Dispatch in `docker/sdk`; `boot|bootstrap` und `config` laufen im SDK, alle anderen über `docker/deployment/<projekt>/deploy`, existiert erst nach Bootstrap; Liste per `./docker/sdk help` verifiziert):

| Befehl | Wirkung |
|---|---|
| `bootstrap [-v] [datei]` | generiert `docker/deployment/default/` aus der Deploy-Datei |
| `config` | gibt die aufgelöste Deploy-Datei aus (`project.yml`) |
| `up [--build] [--assets] [--data] [--jobs]` | baut und startet alles; bereits gelaufene Schritte werden übersprungen, außer per Option erzwungen |
| `run`/`start`, `stop`, `restart`, `down`, `ps` | Lebenszyklus |
| `cli` / `cli <cmd>` | interaktive Shell bzw. Befehl im `cli`-Container (z. B. `./docker/sdk cli composer install`) |
| `console <cmd>` | Kurzform für `vendor/bin/console <cmd>` |
| `testing [<cmd>]` | wie `cli`, aber im Testing-Modus: setzt SDK-intern `SPRYKER_TESTING_ENABLE=1` (`Compose::ensureTestingMode` startet bei Bedarf den webdriver-Container), im Container wird `SPRYKER_TESTING_ENABLED=1` über `env/cli/testing.env` gesetzt (plus Webdriver-/Host-Variablen); wartet auf database, broker, search, key_value_store, session, webdriver. `APPLICATION_ENV` bleibt unverändert (`testing.sh`, `testing.env.twig`) |
| `exec <service> <cmd>` | Befehl in einem Service-Container |
| `demo` / `demo <section>` | **Zerstörend:** `Data::load --force` für jede Region: `queue`-Setup, dann `init-storages-per-region` mit `propel:tables:drop` (löscht alle Tabellen der Region), danach `demodata` (oder die Sektion). Nicht gegen Daten laufen lassen, die man behalten will `[live verifiziert (202610.0)]` (Lauf brach bei `propel:diff` ab, DB musste aus dem Dump wiederhergestellt werden) |
| `clean-data` | löscht alle Volumes (Datenbank, Redis, Search) |
| `jobs start/stop/clean` | Scheduler-Jobs (→ [Console/Cron/Queue](22-console-cron-queue.md)) |
| `logs` | zeigt "All logs are now available at http://spryker.local/logs", fragt `(y/n)` und tailt bei `y` fortlaufend `/var/log/spryker/exception.log` (keine Optionen, Abbruch Strg-C). Exceptions landen zusätzlich auf stderr der Container: `docker logs <container>` |
| `wait <service>...` | wartet auf Services |
| `pull`, `build [images\|codebase\|assets]`, `export` | `export` nur Baked-Modus |
| `reset` | `down` + Volumes löschen + `up --build --assets --data --jobs` (Demodaten neu, **Daten gehen verloren**) |
| `clean`, `prune [--f] [--a]` | Container/Images/Volumes bzw. alle Docker-Daten und Projekt-Artefakte entfernen |
| `sync logs` | startet `mutagen sync monitor` (läuft bis Abbruch, im Skript hängend; im Code als deprecated markiert) |

Optionen für `cli`, `console`, `testing`: `-v`, `-x` (Xdebug), `-t` (Testing-Modus); Variablen `VERBOSE=1`, `SPRYKER_XDEBUG_ENABLE=1`, `SPRYKER_TESTING_ENABLE=1`. Mehrere Projekte: `./docker/sdk -p <name> …` oder `SPRYKER_PROJECT_NAME=<name>` (Default `default`).

**Mount-Modi** (`docker/docs/02-development/01-choosing-a-mount-mode.md`, `docker/bin/sdk/mount/*.sh`): `baked` (Code ins Image, nicht änderbar; Demo/Produktion), `native` (Bind-Mount, Linux/WSL2), `mutagen` (macOS, schnell), `docker-sync` (macOS/Windows WSL1), `nfs` (Skript vorhanden, nicht konfiguriert, veraltet `[Annahme]`). `deploy.dev.yml` wählt plattformabhängig `native` (linux), `docker-sync` (windows), `mutagen` (macos); auf dieser Maschine `mutagen`. Container-erzeugte Dateien (`src/Generated`, `src/Orm`, `data/`) wandern per Sync zurück auf den Host; eine gestoppte Session lässt generierte Dateien in der IDE fehlen `[Annahme]`.

**Install-Recipes** (Modul `spryker/install`; `vendor/bin/install` ist ein Composer-Proxy). Aufruf (`InstallConsole`):
```
vendor/bin/install [-r <recipe>] [-s <section>]... [-g <group>]... [-x <exclude>]... [-e <include-excluded>]... [-d] [-i] [-b] [-a] [-l] [<store>]
```
`-r` Recipe (Default `APPLICATION_ENV`, sonst `config/Shared/console_env_local.php`, sonst `production`), `-s` Sektionen, `-g` Gruppen, `-x` ausschließen, `-e` als `excluded: true` markierte trotzdem, `-d` Trockenlauf, `-i/-b/-a` Rückfragen/Stopp, `-l` Log nach `data/install/logs/<datum>.log`. Auflösung `config/install/<recipe>.yml`, `config/install/<recipe>`, `<root>/<recipe>`, `<recipe>`; sonst `ConfigurationFileNotFoundException`.

Recipe-Dateien: `{docker,development,production,destructive,pre-deploy,dynamic-store,testing,sniffs,ci,ci.restore-db.stage2,docker.ci.acceptance,docker.ci.acceptance-no-mp,docker.ci.api,docker.ci.api.full}.yml` sowie `config/install/{EU,US}/*.dynamic-store-off.yml`. Abweichung zur lokalen SDK-Doku: dort `EU/{destructive,pre-deploy,production}.yml` und `docker.ci.functional.yml`; im Release gilt der Code. Aufbau: `env`, optional `command-timeout`/`stores`, `sections.<name>.<command>` mit `command`, `stores`, `groups`, `pre`, `condition`, `breakOnFailure`, `timeout`, `excluded`.

Sektionen der Recipe `docker`:

| Sektion | Aufruf | Inhalt |
|---|---|---|
| `build` | `Codebase::build`: `vendor/bin/install -r $SPRYKER_PIPELINE -s build -s build-development` | `transfer:generate`, Router-Cache (Yves, Backoffice, Backend-Gateway), `oms:process-cache:warm-up`, `twig:cache:warmer`, `navigation:build-cache`, `rest-api:build-request-validation-cache`, `propel:schema:copy`, `propel:model:build`, `transfer:entity:generate`, `search:setup:source-map`, `translator:generate-cache`, `oauth:scope-collection-file:generate`, `open-telemetry:generate` |
| `build-development` | s. o. | `transfer:generate`, `transfer:databuilder:generate`, `dev:ide-auto-completion:generate`, `rest-api:generate:documentation` |
| `build-production` | nur Baked/Prod (`excluded: true`) | `cache:class-resolver:build`, `twig:template:warmer` (Zed und Yves) |
| `build-static`, `build-static-<mode>` | `assets/mount.sh` | `frontend:project:install-dependencies`, `frontend:yves:build`, `frontend:zed:build`, `frontend:date-time-product-configurator:build` (`-e production` im Prod-Modus) |
| `scheduler-setup`/`-suspend`/`-clean` | `service/scheduler/jenkins.sh` | `scheduler:setup` usw. |
| `init-storages-per-store` | `Data::load` je Store | `queue:setup`, `search:setup:sources` |
| `init-storages-per-region` | `Data::load` je Region | `propel:schema:copy`, `propel:pg-sql-compat`, `propel:migration:delete`, `propel:tables:drop`, `propel:diff`, `propel:migrate`, `propel:migration:delete`, `setup:init-db` |
| `demodata` | `Data::load` / `demo <section>` | `queue:setup`, `data:import --config=data/import/local/full_${SPRYKER_REGION}.yml`, `search:setup:sources`, `product-label:relations:update`, `vendor/bin/glue glue-api:controller:cache:warm-up`, `vendor/bin/glue api:generate:documentation` (beide über das Glue-Binary, nicht über `console`) |

`Data::load` ruft vorab `-s clean-storage -s init-storage` auf; diese Sektionen fehlen in `docker.yml`, der Runner überspringt sie still ("Install docker finished in 0s") `[live verifiziert (202610.0)]`. Weitere Recipes: `development.yml` (Legacy mit Stores `DE`/`AT`/`US`, Nicht-Docker), `production.yml`/`pre-deploy.yml`/`destructive.yml`/`dynamic-store.yml` (Cloud-Pipeline; `destructive` löscht Storage und DB, nie auf Produktivdaten), `testing.yml` (`devtest`, `codecept build`, `code:fixtures`), `docker.ci.*.yml`/`ci.yml` (CI via `pipeline:`).

**Xdebug.** Voraussetzung `docker.debug.enabled: true` und `docker.debug.xdebug.enabled: true` (dev ja, `deploy.yml` beide `false`); das Deploy-Template setzt `SPRYKER_XDEBUG_MODE_ENABLE=1`, sonst meldet `Compose::verboseMode` „Debugging is disabled in deploy.yml“. Pro Aufruf einschalten: `./docker/sdk up -x` (Web), `./docker/sdk cli -x`, `console -x <cmd>`, `testing -x` (oder `SPRYKER_XDEBUG_ENABLE=1`); in der CLI `PHP_INI_SCAN_DIR=:/usr/local/etc/php/debug.conf.d` (lädt `69-xdebug.ini`). Ohne `-x` ist die Extension nicht geladen (`php -m | grep -i xdebug` im Container: leer, verifiziert). `69-xdebug.ini`: `xdebug.mode=debug,coverage`, `start_with_request=yes`, `client_host=${SPRYKER_XDEBUG_HOST_IP}`, `client_port=9003`, `max_nesting_level=1000`; Xdebug-2-Direktiven (Port 9000) sind Legacy. PhpStorm-Doku: Ports `9000,9003`, Server `spryker`, Host `spryker`, Port `80`, Pfad-Mapping auf `/data`. Host-IP überschreibbar mit `docker.debug.xdebug.custom_host_ip`. Nach Änderung an `docker.debug`: `./docker/sdk bootstrap deploy.dev.yml && ./docker/sdk up`. Beobachtung: `./docker/sdk help` zeigt hier „-x [DISABLED]“ trotz aktivierter Deploy-Datei, vermutlich reine Anzeige `[Annahme]`.

**Nach `deploy.yml`-Änderungen:** immer `./docker/sdk bootstrap <datei>` und `./docker/sdk up` (ggf. `--build`). `docker/deployment/` ist generiert; nicht editieren.

## Reihenfolge nach Codeänderungen

Alle Befehle im `cli`-Container (`docker/sdk cli …` bzw. `docker/sdk cli console …`; Kurzform `docker/sdk console …`). Die Reihenfolge folgt der Recipe `docker`, Sektion `build` bzw. `init-storages-per-region`. Alle Befehle dieser Liste wurden ausgeführt `[live verifiziert (202610.0)]`, Abweichungen stehen jeweils dabei.

Gesamtfolge, wenn mehrere Änderungsarten zusammenkommen:
1. `composer install` bzw. `composer dump-autoload` (neue Pakete/Klassen; Prod zusätzlich `cache:class-resolver:build`)
2. **Transfer:** `transfer:generate` (optional `transfer:databuilder:generate`, `dev:ide-auto-completion:generate`)
3. **Propel:** `propel:schema:copy` → `propel:model:build` → `propel:diff` → `propel:migrate` → `transfer:entity:generate` → danach `transfer:generate`, falls Entity-Transfers genutzt werden (Kurzform `propel:install`, → [Propel](05-propel-persistenz.md))
4. `search:setup:sources` (+ `search:setup:source-map`), `queue:setup`
5. **Router-Cache:** `vendor/bin/yves router:cache:warm-up`, `router:cache:warm-up:backoffice`, `router:cache:warm-up:backend-gateway`
6. **Navigation:** `navigation:build-cache`
7. Weitere Caches: `oms:process-cache:warm-up`, `twig:cache:warmer`, `translator:generate-cache`, `rest-api:build-request-validation-cache`, `vendor/bin/glue glue-api:controller:cache:warm-up`
8. **Frontend:** `frontend:yves:build`, `frontend:zed:build`
9. Daten: `data:import …`, `publish:trigger-events`
10. Unklarer Cachezustand: `cache:empty-all`, danach erneut die Warm-Ups

Je Änderung:

| Änderung | Befehle in dieser Reihenfolge |
|---|---|
| `*.transfer.xml` neu/geändert | `transfer:generate`, optional `transfer:databuilder:generate` (Tests), `dev:ide-auto-completion:generate` (IDE, → [Transfer](04-transfer.md)) |
| `*.schema.xml` neu/geändert | `propel:schema:copy` → `propel:model:build` → `propel:diff` → `propel:migrate` → `transfer:entity:generate`; `transfer:generate`, wenn Entity-Transfers verwendet werden (→ [Propel](05-propel-persistenz.md)) |
| Neuer Pyz-Controller, Route | Yves: `vendor/bin/yves router:cache:warm-up`; Backoffice: `router:cache:warm-up:backoffice`; Backend-Gateway: `router:cache:warm-up:backend-gateway` (→ [Yves-Routing](14-yves-controller-routing.md)) |
| Twig-Template | im Dev nicht nötig, `twig:cache:warmer` zum Aufwärmen; Prod `twig:template:warmer` |
| Backoffice-Navigation (`navigation.xml`) | `navigation:build-cache` (Entfernen: `navigation:cache:remove`, → [Back Office](18-back-office.md)) |
| OMS-Prozess-XML | `oms:process-cache:warm-up` (→ [OMS](10-oms-state-machine.md)) |
| Glue-Ressource | `rest-api:build-request-validation-cache`, `vendor/bin/glue glue-api:controller:cache:warm-up` (→ [Glue Storefront](12-glue-storefront.md)) |
| Zed-Übersetzungen | `translator:generate-cache` |
| Such-Mapping | `search:setup:sources` (+ `search:setup:source-map`, → [Client/Storage/Search](08-client-storage-search.md)) |
| Neue Queue | `queue:setup` (→ [Console/Cron/Queue](22-console-cron-queue.md)) |
| Neue Klassen/Module (Klassenauflösung) | `composer dump-autoload`, Prod `cache:class-resolver:build` |
| SCSS/TS (Yves/Zed) | `frontend:yves:build`, `frontend:zed:build` (→ [Atomic Frontend](15-yves-frontend-atomic.md)) |
| Publish-Daten fehlen im Storage | `publish:trigger-events` (→ [Publish & Sync](07-publish-sync.md)) |
| Alles unklar | `cache:empty-all` |

Schnellster verlässlicher Weg für einen Pyz-Neubau ohne Datenbank-Rebuild: `docker/sdk cli vendor/bin/install -r docker -s build -s build-development` (exakt der Aufruf von `Codebase::build`).

## Anleitung

1. **Erstinstallation:** `./docker/sdk bootstrap deploy.dev.yml`, `./docker/sdk up` (Teilschritte erzwingen: `--build --assets --data`).
2. **Täglich:** Code ändern, passende Zeile der Tabelle oben ausführen, im Browser prüfen. Composer immer im Container: `./docker/sdk cli composer …`.
3. **Debuggen:** `./docker/sdk up -x` bzw. `./docker/sdk cli -x`, Breakpoints in PhpStorm (Port 9003, Mapping `/data`).
4. **Eigenen Befehl in den Aufbau einhängen:** Sektion in `config/install/docker.yml` (Projektdatei) ergänzen, nicht SDK-Skripte ändern (Beispiel unten); Recipe-Änderungen wirken sofort, Re-Bootstrap nur bei geänderter Deploy-Datei.
5. **Nur eine Sektion neu laufen lassen:** `./docker/sdk cli vendor/bin/install -r docker -s demodata` bzw. `./docker/sdk demo demodata`; Trockenlauf `-d`.
6. **Evaluator/Qualität:** → [Upgradability](28-upgradability.md), [Coding-Guidelines](27-coding-guidelines.md).

Spickzettel (alle Zeilen ausgeführt `[live verifiziert (202610.0)]`, außer den als zerstörend markierten `demo`, `clean-data`, `reset`; `up`/`testing` brauchen ein TTY):
```
./docker/sdk help
./docker/sdk bootstrap deploy.dev.yml
./docker/sdk up | up -x | up --build | up --assets | up --jobs   # `up --data` nicht ausgeführt (zerstörend)
./docker/sdk ps | stop | restart | down
./docker/sdk cli
./docker/sdk cli composer install
./docker/sdk console transfer:generate -vvv
./docker/sdk console cache:empty-all
./docker/sdk cli vendor/bin/install -r docker -s build -s build-development
./docker/sdk cli vendor/bin/install -r docker -d
./docker/sdk testing codecept build
./docker/sdk start                              # zurück aus dem Testing-Modus (Scheduler läuft wieder, webdriver aus)
./docker/sdk demo                               # ZERSTÖREND: löscht alle Tabellen der Region (propel:tables:drop)
./docker/sdk clean-data                         # nicht ausgeführt (löscht Volumes)
./docker/sdk reset                              # nicht ausgeführt, Daten gehen verloren
./docker/sdk logs                               # Hinweis auf http://spryker.local/logs, bei `y` exception.log; Container-Ausgabe: docker logs <container>
./docker/sdk sync logs                          # blockiert (mutagen sync monitor), Strg-C
```

## Beispiele

**Auszug `deploy.dev.yml`** (echt): Applikation, Endpoint, Service, Mount, Debug:
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

**Eigene Recipe-Sektion** (Vorschlag, nicht im Release; Struktur entspricht der vorhandenen Recipe, `example:warm-up` ist ein Platzhalter), `config/install/docker.yml`:
```yaml
sections:
    demodata:
        # … vorhandene Commands …
        example-warm-up:
            command: 'vendor/bin/console example:warm-up'
```
Nur diese Sektion: `./docker/sdk cli vendor/bin/install -r docker -s demodata`.

**Verzeichnisse (Release):** `deploy.yml`, `deploy.dev.yml`, `deploy.ci.*.yml` (7 Dateien), `deploy.aws-env-template.yml`, `tooling.yml`, `config/install/*.yml`, `docker/` (SDK-Klon: `sdk`, `bin/`, `context/`, `generator/`, `docs/`), `docker/deployment/default/` (generiert: `deploy`, `docker-compose.yml`, `project.yml`, `env/*.env`), `vendor/spryker/{install,installer,setup,setup-frontend}/`. Laufende Container (`docker ps`, Namespace `spryker_b2b_dev`): `gateway`, `frontend`, `yves_{eu,us}`, `boffice_{eu,us}`, `glue_{eu,us}`, `gluestorefront_{eu,us}`, `gluebackend_{eu,us}`, `backgw_{eu,us}`, `backapi_{eu,us}`, `broker`, `database`, `scheduler`, `swagger` u. a.

## Fallstricke

- Deploy-Dateien driften auseinander (Vollkopien); Änderungen in allen relevanten Dateien nachziehen oder bewusst nur lokal ändern.
- Änderung an `deploy.dev.yml` ohne `bootstrap` wirkt nicht. `image: environment:` ist in Images eingebettet und braucht `up --build`.
- `docker/deployment/` von Hand editieren: wird beim nächsten `bootstrap` überschrieben; `docker/` selbst nicht ändern (externer SDK-Klon, Upgradability).
- `composer install` auf dem Host statt im Container: falsche PHP-Version/Extensions.
- `reset`/`clean-data` löschen Volumes inklusive Datenbank, Redis, Search; `demo`/`up --data` löschen die Tabellen der Region (`propel:tables:drop`).
- `up`/`down` erzeugt den `cli`-Container neu: `/data/node_modules` ist danach leer, `npm run yves|zed` bricht mit `ERR_MODULE_NOT_FOUND` (z. B. `fast-glob`) ab. Abhilfe: `docker/sdk up --assets`. `frontend:zed:install-dependencies` ist deprecated und scheitert (Exit 1), stattdessen `frontend:project:install-dependencies` oder `up --assets`.
- `up --build` löscht `src/Generated` und führt `Codebase::build` (Sektionen `build`, `build-development`) neu aus; Glue-Controller-Cache und Router der anderen Regionen fehlen danach: `vendor/bin/glue glue-api:controller:cache:warm-up`, für US mit `SPRYKER_CURRENT_REGION=US SPRYKER_REGION=US APPLICATION_STORE=US` davor.
- Generierte Dateien in `src/Generated`, `src/Orm`, `data/` nicht versionieren und nicht von Hand ändern.
- `Class … not found`: Transfer-/Propel-/Router-Cache vergessen (Tabelle oben); Prod zusätzlich Autoloader/`cache:class-resolver:build`.
- Mutagen-Session hängt: `mutagen sync list` (Status "Watching for changes"), `./docker/sdk sync logs` blockiert (Monitor). Host sieht frisch erzeugte Container-Dateien mit Verzögerung (Sekunden); Größen und Existenz erst nach kurzer Wartezeit prüfen.
- Falscher Recipe-Name bei `-r`: Name = Dateiname ohne `.yml`, sonst `ConfigurationFileNotFoundException`.
- Xdebug ohne `-x`: Breakpoints greifen nicht; Port 9003.
- Secrets: Demo-Passwörter (`secret`, `change123`) in `deploy*.yml` nicht in echte Umgebungen übernehmen (→ [Konfiguration](06-konfiguration.md)).
- Die `*.dynamic-store-off.yml`-Recipes gelten vermutlich nur für Projekte ohne Dynamic Store (`SPRYKER_DYNAMIC_STORE_MODE` ist hier `true`); der Auswahlmechanismus liegt in der Cloud-Pipeline `[Annahme]`.
- Docker-CLI nicht im PATH (macOS, so beim Test geschehen): `export PATH=$PATH:/Applications/Docker.app/Contents/Resources/bin`, sonst „'docker' is not found“.

## Checkliste

- [ ] Nach `deploy.yml`-Änderung: `bootstrap` + `up`
- [ ] Composer/Konsole nur im Container (`docker/sdk cli …`)
- [ ] Nach Transfer-/Schema-/Route-/Navigation-/OMS-/Glue-Änderung die passenden Befehle der Tabelle ausgeführt
- [ ] Frontend nach SCSS/TS-Änderung gebaut
- [ ] Eigene Aufbauschritte als Sektion in `config/install/docker.yml`
- [ ] Xdebug nur bei Bedarf (`-x`), nicht in Produktion
- [ ] Kein `reset`/`clean-data`/`destructive` auf Umgebungen mit wertvollen Daten
- [ ] Generierte Ordner nicht committet, `docker/deployment/` nicht editiert

## Quellen

Doku (Basis `https://docs.spryker.com/docs/dg/dev/sdks/the-docker-sdk/`, nicht abgerufen; lokal abgeglichen): `the-docker-sdk.html`, `docker-sdk-quick-start-guide.html`, `docker-sdk-configuration-reference.html`, `running-commands-with-the-docker-sdk.html`, `choosing-a-mount-mode.html`, `installation-recipes-of-deployment-pipelines.html`, `reset-docker-environments.html`.

Code/lokale Doku: `deploy.yml`, `deploy.dev.yml`, `deploy.ci.*.yml`, `docker/sdk`, `docker/bin/command/{install,compose,cli,runtime,build,internal}/*.sh`, `docker/bin/sdk/{codebase,assets,data,mount}/*.sh`, `docker/bin/sdk/compose.sh`, `docker/generator/src/templates/deploy.bash.twig`, `docker/generator/deploy-file-generator/src/Processor/Executor/PreExecutors/PrepareDeployFileTransferExecutor.php`, `docker/context/php/debug/etc/php/debug.conf.d/69-xdebug.ini`, `docker/docs/02-development/{01-choosing-a-mount-mode,05-configuring-debugging,07-configuring-a-mount-mode}.md`, `docker/docs/07-deploy-file/*.md`, `config/install/*.yml`, `vendor/spryker/install/src/Spryker/Zed/Install/{InstallConfig.php,Communication/Console/InstallConsole.php,Business/Configuration/Loader/ConfigurationLoader.php}`.

Verweise: [Konfiguration](06-konfiguration.md), [Console/Cron/Queue](22-console-cron-queue.md), [Tests](26-tests.md), [Performance/Caching](25-performance-caching.md), [Upgradability](28-upgradability.md).

Deprecations/Abweichungen: `docker/bin/command/install/clean.sh` enthält `sync clean # TODO deprecated, use Registry::Flow::addAfterDown in mounts` (SDK-intern); lokale SDK-Doku weicht ab (`EU/production.yml`, `stores:` je Region, `import:`), Code gilt; Xdebug-2-Direktiven in `69-xdebug.ini` Legacy; Recipe `development.yml` ist für Docker nicht maßgeblich `[Annahme]`; `export` (nur Baked) und `build` nicht für die Entwicklung. Stack-Defaults im Release: PHP 8.4-Image, Node 24, MariaDB 10.4, Elasticsearch 7.10 (der SDK enthält zusätzlich `docker/context/opensearch` und `otel-stack`; Doku-Bezug nicht geprüft).
