# Upgradability: Evaluator, Spryker Code Upgrader, Modulversionen (Recherche T-28)

> Status: Release 202610.0 (Commit d45396b8; spryker/kernel 3.87.1, spryker/oms 11.55.0; Symfony 6.4.48; PHP-Laufzeit im Container **8.4.27** (live: `docker/sdk cli php -v`), `composer.json` verlangt nur `>=8.3` (Untergrenze, `config.platform` 8.3.13); Node `>=24.15.0`). Stand der Recherche 2026-11-18. **Der Evaluator wurde im Container wirklich ausgeführt** (`docker/sdk cli vendor/bin/evaluator ...`, Ergebnis siehe Beispiele); Konsolenbefehle ausgeführt `[live verifiziert (202610.0)]`; `composer show`, `outdated`, `why`, `require --dry-run` und `install --dry-run` wurden ausgeführt `[live verifiziert (202610.0)]`. Die Doku-Seiten wurden nur per Web-Abruf mit automatischer Zusammenfassung gelesen (`ignore-evaluation-errors`, `use-composer-constraint-for-customized-modules`, `container-set-function`, `run-the-evaluator-tool` ohne verwertbaren Volltext; die Bad/Good-Beispiele der Regel-Seiten sind nicht belastbar): `[code-verifiziert, Doku nicht abgeglichen]`, **Code hat Vorrang**.

## Zweck

Das Projekt so bauen und pflegen, dass Minor-/Patch-Updates (auch durch den Spryker Code Upgrader) ohne Handarbeit einfließen: Regeln, Evaluator, begründetes Ignorieren, Composer-Constraints, Vorgehen bei Major-Updates.

## Konzepte

**Regeln als Checker.** In 202610.0 sind die Upgradability-Regeln als 13 Checker im Evaluator kodiert (`vendor/spryker-sdk/evaluator/src/Checker/*`; Doku-URLs in `vendor/spryker-sdk/evaluator/config/checkers.yaml`, Basis `…/keeping-a-project-upgradable/upgradability-guidelines/<regel>.html`).

| Checker (`--checkers`) | Geprüft (aus dem Code) |
|---|---|
| `DEAD_CODE_CHECKER` | Projektklassen unter `src`, die Core-Klassen erweitern, aber nirgends mehr genutzt werden. Skip `@evaluator-skip-dead-code` |
| `DEPENDENCY_PROVIDER_ADDITIONAL_LOGIC_CHECKER` | `if`-Bedingungen in `*DependencyProvider.php` (ohne `vendor`); erlaubt nur Methodenaufruf `is…Development…()` und `class_exists()` |
| `CONTAINER_SET_FUNCTION_CHECKER` | Callback in `$container->set(...)` darf kein Array direkt zurückgeben, sondern muss eine Methode aufrufen |
| `MULTIDIMENSIONAL_ARRAY_CHECKER` | maximale Schachtelungstiefe der Plugin-Registrierung in DependencyProvidern `MAX_LEVEL = 2`. Skip `@evaluator-skip-multidimensional-array` |
| `SINGLE_PLUGIN_ARGUMENT` | Konstruktor-Argumente bei `new Plugin(...)` in DependencyProvidern nur int, float, string, Konstante, bool, `new`-Ausdruck ohne weitere Methodenaufrufe. Skip `@evaluator-skip-single-plugin-argument` |
| `PLUGINS_REGISTRATION_WITH_RESTRICTIONS_CHECKER` | Docblock-Block `Restrictions:` an Plugin-Methoden: Zeilen `* - (before\|after) {@link <Klasse>}` (+ optionaler Text), Klasse muss im DependencyProvider benutzt werden |
| `PHP_VERSION_CHECKER` | erlaubte PHP-Hauptversionen aus `checkers.yaml` (`['8']`); prüft `composer.json`, laufendes PHP, `deploy*.yml` |
| `MINIMUM_ALLOWED_SHOP_VERSION` | Modulversionen gegen `data/minimum-allowed-package-versions.json`, veraltete Features gegen `data/deprecated-features.json`; Shop-Mindestversion `202204.0` |
| `NPM_CHECKER` | `npm audit`; Schweregrade über `ALLOWED_SEVERITY_LEVELS` |
| `OPEN_SOURCE_VULNERABILITIES_CHECKER` | Composer-Audit (`--abandoned=ignore`) |
| `SPRYKER_SECURITY_CHECKER` | fragt per ReleaseApp (HTTP), ob für installierte Module Security-Updates existieren. **Braucht Netzzugang** |
| `SPRYKER_DEV_PACKAGES_CHECKER` | Spryker-Dev-Pakete nur in `require-dev` |
| `DISCOURAGED_PACKAGES_CHECKER` | nicht empfohlene Pakete |

Die allgemeinen Architekturregeln dahinter (keine private Core-API überschreiben, keine Core-Konstanten/-Methoden kopieren, Plugin-/Konfig-Erweiterung statt Vererbung) stehen in der Doku `keeping-a-project-upgradable.html` (Szenarien: Event-Subscriber-Registrierung, Modul-Konfiguration, Plugin-Registrierung) und sind **nicht** durch einen Checker abgedeckt (→ [Core erweitern](03-core-erweitern.md)).

Abweichung Doku ↔ Code: Die Doku-Zusammenfassung beschreibt „Plugin registration with restrictions“ als Registrierung per Feature-Flag; der Code (`RestrictionDocBlockValidator`) prüft ausschließlich das Docblock-Muster. Die Doku-URL im Release enthält den Tippfehler `restrintions`.

**Evaluator.** `spryker-sdk/evaluator ^0.3.9` (`require-dev`), Binary `vendor/bin/evaluator`, Kommando `evaluate`. Optionen (`EvaluatorCommand::configure()`): `--path=<ordner|datei>` (Default Projektwurzel), `--format=output|json|compact`, `-f/--file` bzw. `--no-file` (Ausgabe nach `./report`), `--checkers=A,B`, `--exclude-checkers=A,B`. Die README nennt `--checks=`; im Code existiert nur `--checkers`/`--exclude-checkers`. Exit-Code im Lauf auf dem Release `1` bei Befunden (nur beobachtet, nicht an der Klasse geprüft `[Annahme]`). CI: `.github/workflows/ci.yml` (Evaluator nur für Release-PRs nach `master` plus ein Lauf mit ausgeschlossenen Umgebungs-/Netz-Checkern) und `.github/workflows/scheduled.yml` (Job `evaluator`, geplant oder PR-Label `run-evaluator`).

**Befunde begründet ignorieren.** (1) Annotationen im Code für drei Checker: `@evaluator-skip-dead-code`, `@evaluator-skip-multidimensional-array`, `@evaluator-skip-single-plugin-argument`; Platzierung im Docblock der Klasse/Methode mit Begründung `[Annahme]` (nicht für jeden Checker einzeln geprüft). (2) `tooling.yml` im Projektroot (Standard `tooling.yml`, `EVALUATOR_TOOLING_FILE`): Schlüssel `ignoreErrors` mit Regex (`preg_match`) auf Message **oder** Target, optional `checker:` zur Einschränkung (`ReportFilter::isViolationShouldBeIgnored`); ungültige Regex wirft `InvalidArgumentException`. `ignoreErrors` blendet Befunde nur im Bericht aus; die Ursache bleibt im Code sichtbar.

**Spryker Code Upgrader** (SaaS/CI, nur aus der Doku `ca/devscu/developing-with-spryker-code-upgrader.html`): verbindet sich mit einem Basis-Branch (GitHub, GitLab, Azure oder selbst gehostet), analysiert den Code nach Zeitplan und erstellt einen Pull Request mit den nötigen Updates; ändert bestehende PRs nicht, erstellt einen neuen erst nach Merge/Schließen des vorherigen. Voraussetzungen: Projekt nach den Upgradability-Guidelines aufgebaut, Anpassungen und Upgrader-PRs durch automatisierte Tests abgesichert. **Major-Upgrades macht der Upgrader nicht.** Lokal nicht prüfbar: keine Upgrader-Konfiguration im Repo; `vendor/spryker-sdk/` enthält nur `benchmark`, `composer-constrainer`, `evaluator`, `phpstan-spryker`, `spryk`, `utils`; der Integrator ist per `config.allow-plugins` (`"spryker-sdk/integrator": false`) deaktiviert. Interna des Upgraders sind `[Annahme]`/nicht belegt. Der Evaluator ist die lokale Vorabprüfung, ob der Upgrader das Projekt sauber aktualisieren kann.

**Semantic Versioning und Constraints.** Major = BC-Bruch der **öffentlichen** Modul-API, Minor = rückwärtskompatible Funktion, Patch = Bugfix; private API darf sich auch in Minor/Patch ändern (Doku `semantic-versioning-major-vs.-minor-vs.-patch-release`). Wer Core-Modulverhalten auf Projektebene ändert (nicht-API-Klassen erweitert), soll `~` statt `^` setzen, damit `composer update` nur Patch-Versionen zieht (README composer-constrainer). Im Release steht **kein** `~`-Constraint für Spryker-Module in `composer.json`: entweder werden keine Nicht-API-Core-Klassen erweitert oder der Constrainer lief nie (nicht geklärt).

**composer-constrainer.** `spryker-sdk/composer-constrainer ^0.2.8` (`require-dev`), Kommando `code:constraint:modules` (`ComposerConstraintConsole::COMMAND_NAME` verifiziert). Ohne Option **ändert** es `composer.json` (`^` → `~`); `-d/--dry-run` validiert nur (Exit 0/1, empfohlener CI-Hook); `-s/--strict`; `-o csv/--output-format=csv` (Strict-Modus); `-w/--with-foreign`; `-v` Versionsdetails. Gefunden werden erweiterte API- und Nicht-API-Klassen in `src/` (`ExtendedModuleFinder`); ausgenommen sind erweiterte `*DependencyProvider` und `*Config`. **Befund im Release:** Die Konsole ist **nicht registriert** (kein Treffer in `src/Pyz/Zed/Console/ConsoleDependencyProvider.php`); `code:constraint:modules` fehlt in `console list code`.

**Major-Updates.** Doku-Einstieg `dg/dev/upgrade-and-migrate/upgrade-and-migrate.html` mit Guides für PHP 8.3/8.4/8.5, Symfony 6/7, Node.js/npm, Webpack 5, Twig v1→v3, API Platform u. a. Das Release läuft mit PHP 8.4.27 (Image `spryker/php:8.4`, → [Docker SDK Workflow](30-docker-sdk-workflow.md); 8.3 ist nur die Composer-Untergrenze `>=8.3`) und Symfony 6.4.48; ob 202610.0 Symfony 7 unterstützt, ist nicht geprüft.

## Anleitung

1. **Vor jedem Commit** den Evaluator offlinefähig laufen lassen und Befunde im Projektcode beheben statt ignorieren:
   `docker/sdk cli vendor/bin/evaluator evaluate --format=compact --exclude-checkers=SPRYKER_SECURITY_CHECKER,OPEN_SOURCE_VULNERABILITIES_CHECKER`.
2. **DependencyProvider sauber halten:** keine `if`-Logik außer `is…Development…()`/`class_exists()`; `container->set()`-Callbacks rufen eine Methode auf; Plugin-Konstruktoren nur mit einfachen Argumenten; Plugin-Arrays nicht tiefer als Ebene 2.
3. **Constrainer aktivieren** (einmalig `[Annahme]` zur Ausführung): in `src/Pyz/Zed/Console/ConsoleDependencyProvider.php` im `isDevelopmentConsoleCommandsEnabled()`-Block `$commands[] = new \SprykerSdk\Zed\ComposerConstrainer\Communication\Console\ComposerConstraintConsole();` ergänzen (Muster laut README). Ob zusätzliche Namespace-/Modul-Auflösung nötig ist (Paket im Namespace `SprykerSdk\`), ist nicht geprüft.
4. **CI:** `code:constraint:modules -d` (Exit 1 = Fehlschlag).
5. **Constraints anpassen:** Lauf ohne `-d` ändert `^`→`~` für Module mit erweiterten Nicht-API-Klassen, danach `composer update` und `composer.lock` committen.
6. **Updates:** Minor/Patch regelmäßig; vor jedem Update `evaluate`; Major je Modul: Upgrade-Guide lesen, BC-Brüche der öffentlichen API suchen, `src/Pyz` anpassen, Transfer/Schema regenerieren (→ [Transfer](04-transfer.md), [Propel](05-propel-persistenz.md)), Tests (→ [Tests](26-tests.md)). Reihenfolge ist `[Annahme]`, die Doku gibt keine feste Abfolge vor.
7. **Upgrader-tauglich bleiben:** Tests für alle Anpassungen, Evaluator sauber (→ [Checkliste](../checklists/upgradability.md)).

Befehle (Evaluator-Befehle wurden im Container ausgeführt; Ausgabe `[]` bei `--format=json` ohne Befunde beobachtet; `composer show` ausgeführt; `code:constraint:modules` ist im Release **nicht registriert** (nicht in `console list`) und erst nach Eintrag in den `ConsoleDependencyProvider` aufrufbar; `composer outdated` `[live verifiziert (202610.0)]`). Der Evaluator ohne Einschränkung endet mit Exit 1, weil `OPEN_SOURCE_VULNERABILITIES_CHECKER` (14) und `SPRYKER_SECURITY_CHECKER` (27) Advisories melden (tagesabhängig, Netz nötig); der CI-Lauf unten schließt beide aus (Exit 0). Docker-CLI ggf. im PATH ergänzen: `export PATH=$PATH:/Applications/Docker.app/Contents/Resources/bin`.
```
docker/sdk cli vendor/bin/evaluator evaluate
docker/sdk cli vendor/bin/evaluator evaluate --format=compact          # wie CI
docker/sdk cli vendor/bin/evaluator evaluate --format=json
docker/sdk cli vendor/bin/evaluator evaluate --checkers=PHP_VERSION_CHECKER,DEAD_CODE_CHECKER
docker/sdk cli vendor/bin/evaluator evaluate --exclude-checkers=SPRYKER_SECURITY_CHECKER,OPEN_SOURCE_VULNERABILITIES_CHECKER
docker/sdk cli vendor/bin/evaluator evaluate --path=src/Pyz/Zed/Console
docker/sdk cli vendor/bin/evaluator evaluate --file                    # legt ./report an
docker/sdk cli composer outdated "spryker*/*" --direct                # [live verifiziert (202610.0)] nur EIN Paketargument erlaubt (Composer 2.11: mehrere -> "Too many arguments")
docker/sdk cli composer show spryker/kernel                            # [live verifiziert (202610.0)]
docker/sdk cli console code:constraint:modules -d                      # erst nach Registrierung der Konsole, im Release nicht in `console list`
docker/sdk cli console code:constraint:modules -d -w
docker/sdk cli console code:constraint:modules                         # ÄNDERT composer.json
```
Projektcode-Lauf wie in `ci.yml`:
```
vendor/bin/evaluator evaluate --format=compact --exclude-checkers=DISCOURAGED_PACKAGES_CHECKER,MINIMUM_ALLOWED_SHOP_VERSION,NPM_CHECKER,OPEN_SOURCE_VULNERABILITIES_CHECKER,PHP_VERSION_CHECKER,SPRYKER_DEV_PACKAGES_CHECKER,SPRYKER_SECURITY_CHECKER
```

## Beispiele

**Erlaubte Bedingung im DependencyProvider** (`src/Pyz/Zed/Console/ConsoleDependencyProvider.php`, Ausschnitt ab ~Zeile 405, Release):
```php
$commands = array_merge($commands, $propelCommands);

if ($this->getConfig()->isDevelopmentConsoleCommandsEnabled()) {
    $commands[] = new EventListenerDumpConsole();
    $commands[] = new CodeTestConsole();
    $commands[] = new CodeStyleSnifferConsole();
    $commands[] = new CodeArchitectureSnifferConsole();
    // ... weitere Development-Kommandos
```
Akzeptiert, weil `DependencyProviderAdditionalLogicChecker::isAcceptedCondition()` Methodenaufrufe zulässt, deren Name zu `/^is.*Development.*/` passt. Jede andere `if`-Bedingung würde gemeldet (→ [Core erweitern](03-core-erweitern.md)).

**Echtes Evaluator-Ergebnis auf dem unveränderten Release** (`vendor/bin/evaluator evaluate`, 2026-11-18): `Success!` für CONTAINER_SET_FUNCTION, DEAD_CODE, DEPENDENCY_PROVIDER_ADDITIONAL_LOGIC, SPRYKER_DEV_PACKAGES, DISCOURAGED_PACKAGES, MINIMUM_ALLOWED_SHOP_VERSION, MULTIDIMENSIONAL_ARRAY, NPM, PHP_VERSION, PLUGINS_REGISTRATION_WITH_RESTRICTIONS, SINGLE_PLUGIN_ARGUMENT. Befunde: `OPEN_SOURCE_VULNERABILITIES_CHECKER` 2 (gRPC PHP GHSA-gvrc-7wf9-p7hg, phpseclib GHSA-w3p9-g4p2-h3rr), `SPRYKER_SECURITY_CHECKER` 27 Zeilen „Security update available for the module …“ (z. B. `spryker/security` 1.12.0 → 1.13.0, `spryker/glue-application` 1.81.1 → 1.82.1, `spryker-shop/customer-page` 2.88.1 → 2.90.0). Exit-Code 1. Projektcode-Architektur-Checker sauber; die Befunde betreffen nur Abhängigkeits-Aktualität (Momentbild, abhängig vom Netzzugriff auf die Release App).

**`tooling.yml`** (Release, komplett) und erweitertes Muster (Syntax aus der Evaluator-README, Checker-Name aus dem Release):
```yaml
evaluator:
    checkerConfiguration:
        - checker: NPM_CHECKER
          var:
              ALLOWED_SEVERITY_LEVELS: [critical]
    ignoreErrors:
        # generisch: Regex auf Message ODER Target (Begründung + Ticket als Kommentar)
        - '#Pyz\\Zed\\LegacyThing\\LegacyThingDependencyProvider#'
        # nur für einen Checker
        - messages:
              - '#deploy\.dev\.yml#'
          checker: PHP_VERSION_CHECKER
```
Im Release enthält `tooling.yml` nur den `checkerConfiguration`-Teil.

**Restrictions-Docblock** (Muster aus `RestrictionDocBlockValidator::RULE_PATTERN`, **nicht** aus dem Release; weder in `src/Pyz` noch in Spryker-DependencyProvidern gefunden, nicht gegen den Checker ausgeführt):
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
Beide Klassen müssen im selben DependencyProvider benutzt werden, sonst `Class "%s" is not used in current dependency provider`.

## Fallstricke

| Anti-Pattern | Warum problematisch |
|---|---|
| Logik (`if`, `foreach`) im DependencyProvider | Zusammenführen mit Core-Änderungen scheitert; nur `is…Development…`/`class_exists` erlaubt |
| `$container->set(KEY, function () { return [ ... ]; })` | Array-Literal im Callback; Methode aufrufen |
| Plugin-Arrays tiefer als 2 Ebenen | `MULTIDIMENSIONAL_ARRAY_CHECKER` |
| `new Plugin($a->get(), $b)` | `SINGLE_PLUGIN_ARGUMENT`; nur Skalare, Konstanten, `new` ohne Aufrufe |
| Projektklasse erweitert Core-Klasse und wird nicht mehr genutzt | `DEAD_CODE_CHECKER` |
| Core-Nicht-API-Klassen in `src/Pyz` überschreiben, Constraint bleibt `^` | Minor-Updates können Verhalten brechen |
| Spryker-Dev-Pakete in `require` | `SPRYKER_DEV_PACKAGES_CHECKER` |
| `ignoreErrors` als Dauerlösung ohne Begründung | verdeckt Befunde |
| `evaluate --checks=…` (README) | existiert im Code nicht, richtig `--checkers` |
| Core-Konstanten/-Methoden kopieren, private API überschreiben | allgemeine Doku-Regel, **nicht automatisch erkannt** |
| Netz-Checker offline | `SPRYKER_SECURITY_CHECKER` braucht Release App; Verhalten ohne Netz `[Annahme]` (nicht getestet) |
| Major-Update vom Upgrader erwarten | laut Doku manuell |

Weitere: Constrainer-Konsole nicht registriert (siehe oben). `checkers.yaml` verweist auf `docs/scos/dev/…`-URLs, die aktuelle Doku nutzt `docs/dg/dev/…` (Weiterleitung `[Annahme]`). Doku nennt PHP 8.4/8.5 und Symfony 7 als Upgrade-Ziele; das Release läuft bereits mit PHP 8.4.27 (Composer-Untergrenze 8.3) und Symfony 6.4. API Platform als Migrationsziel ist im Release laut Rechercheplan nicht installiert (→ [Glue Backend](13-glue-backend.md)).

Best Practices: Evaluator in die CI (Muster `ci.yml`, `scheduled.yml`); Netz-/Umgebungs-Checker getrennt von Projektcode-Checkern; Skip-Annotationen/`ignoreErrors` nur mit Begründung und Ticket; Erweiterungen über Plugins/Konfiguration statt Klassenvererbung.

## Checkliste

- [ ] `evaluate` läuft lokal und in der CI; Projektcode-Checker `Success!`
- [ ] Keine `if`-Logik außer `is…Development…`/`class_exists` in DependencyProvidern
- [ ] `container->set()`-Callbacks rufen Methoden auf
- [ ] Plugin-Konstruktoren mit einfachen Argumenten, Schachtelung ≤ 2
- [ ] Keine ungenutzten Projektklassen, die Core-Klassen erweitern
- [ ] `ignoreErrors`/Skip-Annotationen begründet
- [ ] `ComposerConstraintConsole` registriert, `code:constraint:modules -d` in CI
- [ ] Erweiterte Nicht-API-Module mit `~` in `composer.json`
- [ ] Security-Befunde priorisiert und geplant
- [ ] Tests für alle Anpassungen vorhanden
- [ ] Major-Updates mit Upgrade-Guide geplant (Modul, PHP, Symfony, Node)

## Quellen

Doku (relativ zu `https://docs.spryker.com/docs/`; teils nur Teilinhalt): `dg/dev/guidelines/keeping-a-project-upgradable/{keeping-a-project-upgradable,run-the-evaluator-tool,ignore-evaluation-errors}.html`, `…/upgradability-guidelines/{multidimensional-array,plugin-registration-with-restrintions,additional-logic-in-dependency-provider,container-set-function,single-plugin-argument}.html`, `ca/devscu/developing-with-spryker-code-upgrader.html`, `dg/dev/architecture/module-api/{semantic-versioning-major-vs.-minor-vs.-patch-release,use-composer-constraint-for-customized-modules}.html`, `dg/dev/upgrade-and-migrate/upgrade-and-migrate.html`.

Code: `vendor/spryker-sdk/evaluator/{README.md,config/checkers.yaml,config/services.yaml,data/*.json,src/Console/Command/EvaluatorCommand.php,src/Filter/ReportFilter.php,src/Checker/*}`, `vendor/spryker-sdk/composer-constrainer/{README.md,src/SprykerSdk/Zed/ComposerConstrainer/…}`, `tooling.yml`, `composer.json` (Z. 6, 281-284, 334, 348), `package.json` (`engines`), `.github/workflows/{ci,scheduled}.yml`, `src/Pyz/Zed/Console/ConsoleDependencyProvider.php`.

Verweise: [Core erweitern](03-core-erweitern.md), [Coding-Guidelines](27-coding-guidelines.md), [Features/Abhängigkeiten](29-feature-integration.md), [Tests](26-tests.md), [Docker SDK Workflow](30-docker-sdk-workflow.md); Checkliste [upgradability](../checklists/upgradability.md).

Deprecations/Abweichungen: README-Option `--checks` nicht vorhanden; Tippfehler `restrintions` in der Release-Config; `composer-constrainer/composer.json` verlangt `phpstan/phpstan ^1.2.0` (dev), der Evaluator `^2.1.0` (nur deren eigene Entwicklung).
