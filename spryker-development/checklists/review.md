# Review-Checkliste

Vor dem Merge/PR abhaken. Abgeleitet aus den Referenzen zu Performance, Tests, Coding-Guidelines, Upgradability, Docker-SDK-Workflow, Security und Konfiguration. Befehle im Container: `docker/sdk cli <befehl>` bzw. `docker/sdk cli console <cmd>`; alle Befehle wurden im Container ausgeführt `[live verifiziert (202610.0)]`.

## Architektur und Code-Stil ([Coding-Guidelines](../references/27-coding-guidelines.md))
- [ ] Lizenz-Docblock und `declare(strict_types = 1);`, Namespace `Pyz\…`, Dateiname = Klassenname
- [ ] Parameter, Rückgaben und Properties typisiert, `array<…>`-Docblocks
- [ ] `@method`-Annotationen für Facade/Factory/Config/Client vorhanden
- [ ] Objekte nur in Factory-`create*`-Methoden, Fremdabhängigkeiten über DependencyProvider, `getLocator()` nur dort
- [ ] Modulübergreifend nur Facade (Zed) bzw. Client (Yves/Glue); keine Propel-Queries/Repositories fremder Module
- [ ] Keine Bridges im Projektcode (Fremd-Facade/-Client direkt per Locator im DependencyProvider, Factory-Getter auf das Interface des Fremdmoduls typisiert); `ProjectNoBridgeRule` greift nur im Project-Ruleset (siehe Statische Analyse). Facade ohne Logik, Facade-Methoden mit Transfers
- [ ] Kein Propel/`new SpyEntity` in Business/Communication
- [ ] Modulname ohne `Example`, `Test`, `Dummy`, `Antelope`
- [ ] Suppressions (`phpcs:ignore`, `@SuppressWarnings`, `@phpstan-ignore`) begründet und minimal

## Statische Analyse ([Coding-Guidelines](../references/27-coding-guidelines.md))
- [ ] `transfer:generate` und `dev:ide-auto-completion:generate` vorab ausgeführt
- [ ] `code:sniff:style` ohne Befund (erst mit `-f`, dann ohne)
- [ ] `code:phpstan` (mindestens Level 6, wie CI) ohne neue Befunde
- [ ] `code:sniff:architecture` (Priorität 2, **Core-Ruleset**) ohne Befund; CI-Parität `phpmd src/ … --minimumpriority 2`
- [ ] Project-Ruleset ohne Befund (prüft u. a. `ProjectNoBridgeRule`): `docker/sdk cli vendor/bin/phpmd src/Pyz text vendor/spryker/architecture-sniffer/src/Project/ruleset.xml`
- [ ] `propel:schema:validate` und `transfer:validate` grün

## Tests ([Tests](../references/26-tests.md))
- [ ] Test im Pfad `tests/PyzTest/<App>/<Modul>/<Suite>/`, Modul-`codeception.yml` vorhanden
- [ ] Arrange/Act/Assert, `@group`-Annotationen, `strict_types`, Methodenname `testWhatShouldHappen`
- [ ] Mocks nur an Facade/Client/Dependency, nicht an internen Models
- [ ] Neuer Helper-Namespace `…\Business\BusinessHelper` (nicht der deprecated Alias)
- [ ] Testdaten per `have*`/DataBuilder; Transaktion bzw. Cleanup aktiv
- [ ] Glue-Cests mit `@depends loadFixtures`, Gruppe `EndToEnd`, OpenAPI-Schema geprüft
- [ ] `codecept build` gelaufen, `_generated/` nicht committet

## Performance ([Performance/Caching](../references/25-performance-caching.md))
- [ ] Yves-Lesepfad ohne Zed-Call (nur Client → Storage/Search)
- [ ] Mehrfach-Lesen per `getMulti`, keine Einzel-`get` in Schleifen
- [ ] Keine N+1-Queries (Join, `filterByIdIn`, Bulk-Repository)
- [ ] Neue Caches mit Warm-Up im Install-Recipe
- [ ] Chunk-Größen nur nach Messung geändert

## Security und Session ([Security/Session](../references/21-security-session.md))
- [ ] Neue Yves-Route in `CustomerConstants::CUSTOMER_SECURED_PATTERN`, falls nur für Angemeldete (sonst anonym erreichbar); Locale-/Store-Präfix im Regex beachtet
- [ ] Neue Zed-Route: Zugriff über ACL; öffentlich nur über `IGNORABLE_ROUTE_PATTERN`
- [ ] Glue-Ressourcen: `isProtected`/Autorisierungsstrategie bewusst gesetzt
- [ ] Formulare über `FormFactory` (CSRF aktiv), kein `|raw` für Nutzereingaben
- [ ] Keine Secrets im Code; `getenv()` nur in `config/Shared/*.php`

## Konfiguration ([Konfiguration](../references/06-konfiguration.md))
- [ ] Werte über `*Constants` + `*Config` gelesen, nicht per `Config::get()` in Business/DependencyProvider
- [ ] Einstellung in der tatsächlich geladenen Datei (nicht `config_default-docker.php` bei `docker.dev`)
- [ ] `Config::get()` immer mit Default, Array-Erweiterungen per `array_merge`
- [ ] Neue Env-Variablen in `deploy*.yml` ergänzt, Demo-Secrets nicht in Produktivumgebungen

## Fehler, Logging, Mail ([Fehler/Logging](../references/23-fehler-logging-debugging.md), [Mail](../references/24-mail-message-broker.md))
- [ ] Exceptions mit Kontext geloggt (`['exception' => $e]`), eigene Exceptions in `Business/Exception/`
- [ ] Sensible Felder in `LOG_SANITIZE_FIELDS`
- [ ] Mail-Typ-Plugin registriert, Glossar-Keys für alle Locales importiert

## Upgradability und Workflow
- [ ] [Upgradability-Checkliste](upgradability.md) abgearbeitet, Evaluator sauber
- [ ] Nach Änderungen die passenden Generierungs-/Cache-Befehle aus [Docker SDK Workflow](../references/30-docker-sdk-workflow.md) („Reihenfolge nach Codeänderungen“) ausgeführt
- [ ] Generierte Ordner (`src/Generated`, `src/Orm`, `data/`) und `docker/deployment/` nicht committet
