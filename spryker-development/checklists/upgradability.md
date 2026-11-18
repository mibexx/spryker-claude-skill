# Upgradability-Checkliste

Ziel: Updates (auch durch den Spryker Code Upgrader) laufen ohne Handarbeit ein. Abgeleitet aus [Upgradability](../references/28-upgradability.md), [Features/Abhängigkeiten](../references/29-feature-integration.md), [Coding-Guidelines](../references/27-coding-guidelines.md) und [Core erweitern](../references/03-core-erweitern.md). Evaluator-, Konsolen- und `composer`-Befehle wurden im Container ausgeführt `[live verifiziert (202610.0)]`.

## DependencyProvider-Regeln (Evaluator-Checker)
- [ ] Keine `if`-Logik in `*DependencyProvider`, außer Methodenaufruf `is…Development…()` und `class_exists()` (`DEPENDENCY_PROVIDER_ADDITIONAL_LOGIC_CHECKER`)
- [ ] `$container->set(KEY, function () {…})` ruft eine Methode auf, gibt kein Array-Literal zurück (`CONTAINER_SET_FUNCTION_CHECKER`)
- [ ] Plugin-Arrays höchstens 2 Ebenen tief (`MULTIDIMENSIONAL_ARRAY_CHECKER`)
- [ ] `new Plugin(...)` nur mit int, float, string, Konstante, bool oder `new` ohne weitere Aufrufe (`SINGLE_PLUGIN_ARGUMENT`)
- [ ] `Restrictions:`-Docblocks nur im Muster `* - before|after {@link <Klasse>}`, Klasse im selben Provider genutzt (`PLUGINS_REGISTRATION_WITH_RESTRICTIONS_CHECKER`)
- [ ] Keine ungenutzten Projektklassen, die Core-Klassen erweitern (`DEAD_CODE_CHECKER`)

## Erweiterungsstrategie ([Core erweitern](../references/03-core-erweitern.md))
- [ ] Reihenfolge eingehalten: Konfiguration vor Plugin vor Vererbung/Replacement
- [ ] Keine Dateien in `vendor/` geändert, keine `replace_*`-Pakete
- [ ] Keine private Core-API überschrieben, keine Core-Methoden/-Konstanten kopiert (`parent::` aufrufen); nicht vom Evaluator erkannt, im Review prüfen
- [ ] Plugins nur in Pyz-DependencyProvidern (Subklasse des Core-Providers), Feature-Kommentar gesetzt
- [ ] Core-Config per Vererbung überschrieben (`extends SprykerXxxConfig`), keine Kopie
- [ ] Keine Pyz-Referenzen im Core, keine Bridges im Projekt (Fremd-Facade/-Client direkt per Locator holen). Prüfung: `code:sniff:architecture` meldet Bridges nicht (Core-Ruleset), daher `docker/sdk cli vendor/bin/phpmd src/Pyz text vendor/spryker/architecture-sniffer/src/Project/ruleset.xml` (enthält `ProjectNoBridgeRule`)

## Evaluator
- [ ] `docker/sdk cli vendor/bin/evaluator evaluate --format=compact --exclude-checkers=SPRYKER_SECURITY_CHECKER,OPEN_SOURCE_VULNERABILITIES_CHECKER` ergibt für Projektcode `Success!`
- [ ] Vollständiger Lauf (`evaluate`) regelmäßig und vor Updates; Option heißt `--checkers`/`--exclude-checkers` (nicht `--checks`)
- [ ] Evaluator in der CI (Muster `.github/workflows/ci.yml`, `scheduled.yml`), Netz-Checker getrennt von Projektcode-Checkern
- [ ] `ignoreErrors` in `tooling.yml` und `@evaluator-skip-*`-Annotationen nur mit Begründung und Ticket
- [ ] Spryker-Dev-Pakete nur in `require-dev` (`SPRYKER_DEV_PACKAGES_CHECKER`)

## Composer und Versionen ([Features/Abhängigkeiten](../references/29-feature-integration.md))
- [ ] `ComposerConstraintConsole` in `ConsoleDependencyProvider` registriert (im Release nicht der Fall; `code:constraint:modules` fehlt in `console list`)
- [ ] Nach der Registrierung `docker/sdk cli console code:constraint:modules -d` in der CI
- [ ] Module mit erweiterten Nicht-API-Klassen mit `~` statt `^` in `composer.json`; danach `composer update`, `composer.lock` committet
- [ ] Features als Metapaket (`spryker-feature/*`) installiert, nicht einzelne Module
- [ ] `composer.lock` eingecheckt, Updates im Container (`docker/sdk cli composer …`)

## Sicherheit und Plattform
- [ ] Befunde von `SPRYKER_SECURITY_CHECKER` und `OPEN_SOURCE_VULNERABILITIES_CHECKER` priorisiert und geplant (Release-Momentbild: 27 Security-Updates, 2 Vulnerabilities)
- [ ] `NPM_CHECKER`-Schweregrad in `tooling.yml` bewusst gesetzt
- [ ] PHP-/Symfony-/Node-Versionen gegen Upgrade-Guides geplant (Release: PHP `>=8.3`, Symfony 6.4, Node `>=24.15.0`)

## Absicherung
- [ ] Tests für alle Projektanpassungen ([Tests](../references/26-tests.md)); Mocks nur an Facade/Client/Dependency
- [ ] Major-Updates manuell mit Upgrade-Guide des Moduls geplant (der Upgrader macht keine Majors)
- [ ] Transfers/Propel nach Updates regeneriert ([Docker SDK Workflow](../references/30-docker-sdk-workflow.md))
