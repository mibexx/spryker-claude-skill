# Release-/Deployment-Checkliste

Vor Auslieferung abhaken. Abgeleitet aus [Docker SDK Workflow](../references/30-docker-sdk-workflow.md), [Performance/Caching](../references/25-performance-caching.md), [Tests](../references/26-tests.md), [Coding-Guidelines](../references/27-coding-guidelines.md), [Upgradability](../references/28-upgradability.md), [Security/Session](../references/21-security-session.md) und [Konfiguration](../references/06-konfiguration.md). Alle Befehle wurden im Container ausgeführt `[live verifiziert (202610.0)]` (Konsole, `docker/sdk`-Lebenszyklus, Composer, Evaluator); nicht ausgeführt wurden nur zerstörende Befehle (`reset`, `clean-data`, `up --data`).

## Qualität und Tests (Gate)
- [ ] `code:sniff:style`, `code:phpstan` (Level 6), `code:sniff:architecture` (Core-Ruleset) sowie `phpmd` mit Project-Ruleset ohne Befund ([Coding-Guidelines](../references/27-coding-guidelines.md))
- [ ] Codeception-Läufe grün: functional, api (nach `codecept fixtures` und `queue:worker:start --stop-when-empty`), ggf. acceptance/Cypress ([Tests](../references/26-tests.md))
- [ ] Evaluator für Release-Branch: `vendor/bin/evaluator evaluate --format=compact` ohne Befunde im Projektcode; Security-Befunde bewertet ([Upgradability](../references/28-upgradability.md))
- [ ] `composer.lock` aktuell, keine Dev-Pakete in `require` (Produktion installiert mit `--no-dev`)

## Konfiguration und Secrets ([Konfiguration](../references/06-konfiguration.md))
- [ ] `deploy.yml` (Prod) statt `deploy.dev.yml`; `environment: docker.production`; Änderungen aus anderen Deploy-Dateien nachgezogen (alle sind Vollkopien)
- [ ] Debug/WebProfiler/Xdebug aus (`docker.debug.enabled: false`, `xdebug.enabled: false`, `IS_WEB_PROFILER_ENABLED` false, kein `SPRYKER_DEBUG_ENABLED`)
- [ ] Keine Demo-Secrets: `change123` (Product-Configurator-Key), `abc123` (OAuth-Client-Secret), `password: secret`, `dev_only_*.key`; Secrets nur über Env-Variablen der Plattform
- [ ] `config_local*.php`, `console_env_local.php`, `docker/` nicht im Repository
- [ ] SecurityBlocker, Session-Cookie (`SameSite`), Remember-Me-Secret, OAuth-Schlüssel für Produktion gesetzt ([Security/Session](../references/21-security-session.md))
- [ ] Mail-Absender, SMTP-Variablen, Message-Broker-Endpunkte (`SPRYKER_MESSAGE_BROKER_HTTP_CHANNEL_*_BASE_URL`) gesetzt oder bewusst leer ([Mail](../references/24-mail-message-broker.md))
- [ ] New Relic/OpenTelemetry nach Bedarf aktiviert (`NEW_RELIC_ENABLED`, Extension `otel`)

## Build und Caches ([Docker SDK Workflow](../references/30-docker-sdk-workflow.md), [Performance/Caching](../references/25-performance-caching.md))
- [ ] Recipe-Sektionen `build`, `build-production`, `build-static-production` ausgeführt (`transfer:generate`, Propel-Build, Router-Caches, `oms:process-cache:warm-up`, `twig:cache:warmer`, `navigation:build-cache`, `rest-api:build-request-validation-cache`, `translator:generate-cache`, `cache:class-resolver:build`, `twig:template:warmer`, Frontend `-e production`)
- [ ] Autoloader mit `--classmap-authoritative`
- [ ] Nach Deploy Caches gewärmt (Router, Twig, Class-Resolver, Navigation, OMS, REST-Validation, Glue-Controller)
- [ ] `IS_REPLICATION_ENABLED` bewusst gesetzt, falls DB-Replicas genutzt werden
- [ ] Generierte Ordner nicht von Hand geändert

## Datenbank und Daten
- [ ] Schema-Änderungen: `propel:diff` und `propel:migrate` geprüft (Migrationsdateien im Repo), `propel:migration:check` gegen Wartungsfenster geplant
- [ ] Neue Queues: `queue:setup`; neue Such-Quellen: `search:setup:sources`; Scheduler: `scheduler:setup` ([Console/Cron/Queue](../references/22-console-cron-queue.md))
- [ ] Glossar/Daten-Importe für neue Texte und Konfigurationsdaten (`data:import glossary` u. a.)
- [ ] Publish & Sync für geänderte Ressourcen ausgelöst (`publish:trigger-events`), Storage/Search geprüft ([Publish & Sync](../references/07-publish-sync.md))
- [ ] Keine `destructive`-Recipe und kein `reset`/`clean-data` auf Umgebungen mit Produktivdaten

## Betrieb
- [ ] Health-Check erreichbar (`/health-check`), Plugins für neue Infrastruktur ergänzt ([Fehler/Logging](../references/23-fehler-logging-debugging.md))
- [ ] Log-Ziele (stderr/stdout vs. Dateien) und Sanitize-Felder geprüft
- [ ] Scheduler/Worker-Jobs für neue Konsolenbefehle eingetragen
- [ ] Rollback-Weg definiert (vorheriges Image/Tag, Migrationen rückwärts kompatibel) `[Empfehlung, nicht aus der Recherche]`
