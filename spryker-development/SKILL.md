---
name: spryker-development
description: Entwicklung mit Spryker (b2b-demo-shop, Release 202610.0) in Backend, Frontend und Glue API. Greift bei Spryker-Modulen und Projektebene Pyz, Zed (Business/Communication/Persistence/Presentation), Yves (Controller, Routes, Twig, Widgets, Themes, Atomic Frontend mit Molecule/Organism, SCSS, TypeScript-Komponenten, npm run yves/Frontend-Build), Glue Storefront/Backend API, Client, Facade, Factory, DependencyProvider, Plugins/Extension Points, Transfer (transfer.xml), Propel-Schema, Repository/EntityManager, Publish & Synchronization, Data Import, OMS/State Machine, Cart/Preis/Checkout, CMS (Slots, Content Items), Back Office (Tabellen, Formulare, navigation.xml, ACL), Multi-Store/Locales, B2B-Permissions, Security/Session, Console/Cronjobs/Queue-Worker, Mail/Broker, Performance/Caching, Codeception-Tests, PHPStan/Sniffer, Upgradability/Evaluator, Feature-Integration, Docker SDK/deploy.yml. Auch bei "Pyz statt Core ändern", "Plugin registrieren", "docker/sdk cli".
---

# Spryker-Entwicklung (Release 202610.0)

Dieser Skill führt durch Entwicklung, Erweiterung und Betrieb eines Spryker-Projekts. SKILL.md enthält Prinzipien, Ablauf und Entscheidungshilfen. Details stehen in `references/`, Gerüste in `templates/`, Prüflisten in `checklists/`.

## Status der Grundlage (transparent)

- **Basis:** installiertes Release `spryker-shop/b2b-demo-shop` **202610.0** (Tag-Commit `d45396b8`, spryker/kernel 3.87.1, spryker/oms 11.55.0, PHP 8.4, Node 24/npm 10, Symfony 6.4). Der installierte Code unter `src/Pyz` und `vendor/` hat Vorrang vor der Doku.
- **Bekannte Lücke 1:** Die Doku-Seiten (docs.spryker.com) waren per WebFetch meist nur als Navigation lesbar. Die Inhalte sind **code-verifiziert, Doku nicht abgeglichen**. Abweichungen zwischen Doku („latest“) und Code sind in den Referenzen markiert.
- **Befehle:** Alle Befehle des Skills wurden in einer Live-Verifikation im Container gegen das Release 202610.0 **ausgeführt** (Exit-Codes und Kurzbefunde im Protokoll `live-verification.md`) und tragen **live verifiziert (202610.0)**. Dabei gefundene Abweichungen sind in den Referenzen korrigiert (z. B. `glue-api:controller:cache:warm-up` ohne Argument, `state-machine:check-condition <Name>`, `code:sniff:style <Pfad>` statt `-m Pyz.<Modul>`, `codecept run -c tests/PyzTest/Zed/<Modul>`, `propel:config:convert` defekt). **Nicht lokal ausgeführt** wurden Befehle, die Daten zerstören (`docker/sdk reset`, `clean-data`, `up --data`, `propel:tables:drop`, `propel:database:drop`, `propel:sql:insert`) oder SaaS/AWS voraussetzen; sie sind dort jeweils mit Begründung vermerkt. **Achtung:** `docker/sdk demo [<section>]` ist ein erzwungener Neuaufbau und löscht alle Tabellen der Region (siehe [Docker SDK und Workflow](references/30-docker-sdk-workflow.md)). Der Evaluator lief im Container (→ [Upgradability](references/28-upgradability.md)).
- **OMS (T-10):** Die Recherche wurde nur wegen dieser Verifikationslücke als FEHLER gemeldet. Der Inhalt ist übernommen und markiert (→ [OMS](references/10-oms-state-machine.md)).
- **Templates:** Alle zwölf Templates wurden im Release 202610.0 **durchgespielt**: vorübergehend in den Shop installiert, Registrierungen an den echten `Pyz`-Dateien eingefügt und die dokumentierte Reihenfolge ausgeführt (`transfer:generate`, echte Propel-Migration, Publish & Sync bis Redis, Data Import, Glue Storefront/Backend per curl, Yves-Route und Widget, Back-Office-Seite mit ACL, OMS-Prozess mit echter Bestellung, Frontend-Build, Codeception-Facade-Test und Glue-Cest), danach vollständig zurückgebaut. Dabei gefundene Fehler sind in den Templates korrigiert (u. a. `queue_pool` im Storage-Schema, Trigger-Plugin mit Entity-Transfers, Validator ohne Tax-Set-Observer, `@method getRepository()` im GatewayController, DataBuilder-`strict`). Jedes Template-README nennt seinen Status, Exit-Codes stehen im Protokoll `live-verification.md` (Abschnitt Phase 2).
- **Nicht im Umfang:** Merchant Portal/Marketplace, Oryx und die Spryker API Platform (`spryker/api-platform` ist im Release **nicht installiert**, siehe [Glue Backend](references/13-glue-backend.md)). Der Spryker Code Upgrader ist ein CI/SaaS-Dienst und lokal nicht prüfbar.

## Grundprinzipien

1. **Applikationen und Schichten.** Sechs Namespace-Schichten je Modul: `Yves`, `Zed`, `Glue`, `Client`, `Service`, `Shared` (gespiegelt in `src/Pyz/`). Einstiegspunkte unter `public/<App>/index.php`: Yves (Storefront), Backoffice (Zed-UI), BackendGateway (Zed intern), Glue (Legacy-REST), GlueStorefront, GlueBackend (→ [Architektur](references/01-architektur.md)).
2. **Erlaubte Kommunikation.** Yves/Glue → **Client** → Storage (Redis/Valkey) | Search (OpenSearch/Elasticsearch) | ZedRequest → Zed-Gateway. Yves ruft nie Zed-Klassen direkt. Zed-Modul → anderes Zed-Modul nur über dessen **Facade**. Auf Projektebene holt der DependencyProvider die fremde Facade bzw. den Client direkt über den Locator (keine Bridge), der Factory-Getter ist auf das Interface des Fremdmoduls typisiert (z. B. `ProductFacadeInterface`). Bridges sind ein reines Core-Muster (→ [Projektmodul](references/02-projektmodul.md)). Die Regel `ProjectNoBridgeRule` steckt nur im Project-Ruleset des Architecture Sniffers (Aufruf: `vendor/bin/phpmd src/Pyz text vendor/spryker/architecture-sniffer/src/Project/ruleset.xml`); `code:sniff:architecture` nutzt standardmäßig das Core-Ruleset und meldet Bridges nicht. Daten über Modulgrenzen nur als **Transfer-Objekte**.
3. **Zed-Modul-Layer.** Im Release vier: `Business` (Facade, BusinessFactory, Modelle), `Communication` (Controller, Plugins, Tables, Console), `Persistence` (Repository, EntityManager, Mapper, Propel-Schema), `Presentation` (Twig). Öffentliche API ist nur die Facade/der Client (mit `@api`), alles andere ist privat.
4. **Pyz statt Core ändern.** Nie in `vendor/` editieren. Namensauflösung: Projekt-Namespace (`Pyz`) wird **vor** den Core-Namespaces (`SprykerShop`, `SprykerEco`, `Spryker`, `SprykerSdk`) aufgelöst. Facade/Factory/Config/DependencyProvider einer Pyz-Klasse gleichen Namens ersetzen die Core-Klasse automatisch (Pyz-Klasse **extends** Core-Klasse).
5. **Erweiterungsstrategien in dieser Präferenzreihenfolge:** Configuration → Plug and play (Plugin im Pyz-DependencyProvider) → Projektmodul → Module customization (Klasse in Pyz überschreiben) → Module replacement (kein Upgrade-Support) (→ [Core erweitern](references/03-core-erweitern.md)).
6. **Upgradability.** Eine überschriebene Core-Methode ist eine Upgrade-Stelle. Keine privaten Core-APIs nutzen, keine Core-Methoden/-Konstanten kopieren, Plugin-Registrierung flach halten. Evaluator regelmäßig laufen lassen (→ [Upgradability](references/28-upgradability.md), [Checkliste](checklists/upgradability.md)).
7. **Coding-Guidelines.** PHPStan, Code Sniffer (`phpcs.xml`) und Architecture Sniffer (Core-Ruleset über `code:sniff:architecture`, zusätzlich Project-Ruleset per `phpmd`) vor jedem Commit (→ [Coding-Guidelines](references/27-coding-guidelines.md)).
8. **Logik gehört in die Business-Schicht**, nicht in Controller, Plugins oder Twig. Plugins delegieren nur an Facade/Factory des eigenen Moduls.
9. **Ressourcen schonen.** Yves liest nur aus Redis/Search (Client), keine Zed-Calls im Request-Pfad; Batch statt N+1 (→ [Performance](references/25-performance-caching.md)).

## Standard-Entwicklungsablauf

Alle Befehle im Container: `docker/sdk cli <befehl>`, Konsole `docker/sdk cli console <cmd>` (Kurzform `docker/sdk console <cmd>`). Die Befehle der Tabelle und die `docker/sdk`-Lebenszyklusbefehle (`up`, `up --build`, `up --assets`, `start`, `stop`, `restart`, `down`, `testing`) sind ausgeführt **live verifiziert (202610.0)**; `docker/sdk up` braucht ein TTY (ohne: `failed to get console`). Details und Reihenfolge in [Docker SDK und Workflow](references/30-docker-sdk-workflow.md).

1. **Plan:** Entscheidungshilfen unten anwenden, passende Referenz lesen, Template kopieren (`templates/`, Platzhalter `ProductBadge` ersetzen).
2. **Code schreiben:** Modul unter `src/Pyz/<Layer>/<Modul>`, Transfers in `*.transfer.xml`, Schema in `*.schema.xml`.
3. **Generieren/Migrieren/Registrieren** nach Art der Änderung:

| Änderung | Befehle (jeweils `docker/sdk cli console …`) |
|---|---|
| `*.transfer.xml` | `transfer:generate` (Tests: `transfer:databuilder:generate`) |
| `*.schema.xml` | `propel:schema:copy` → `propel:model:build` → `propel:diff` → `propel:migrate` → `transfer:entity:generate` → `transfer:generate`; Kurzform `propel:install` |
| Neue Klassen/Module | `composer dump-autoload` (Produktion: `cache:class-resolver:build`) |
| Plugin | Eintrag im **Pyz-DependencyProvider** des Zielmoduls (kein Befehl); Kontrolle: `dev:plugin-usage:dump` |
| Neue Route/Controller | Yves: `docker/sdk cli vendor/bin/yves router:cache:warm-up`; Back Office: `router:cache:warm-up:backoffice`; Gateway: `router:cache:warm-up:backend-gateway` |
| `navigation.xml` | `navigation:build-cache` |
| OMS-Prozess-XML | `oms:process-cache:warm-up` |
| Glue-Ressource | `rest-api:build-request-validation-cache`, `vendor/bin/glue glue-api:controller:cache:warm-up` **ohne Argument** (ein Applikationsname überschreibt den gemeinsamen Cache nur mit dem Anteil dieser Applikation) |
| Zed-Übersetzungen | `translator:generate-cache` |
| Such-Mapping | `search:setup:sources` |
| Neue Queue | `queue:setup` |
| Publish-Daten fehlen | `publish:trigger-events` |
| SCSS/TS | `frontend:yves:build`, `frontend:zed:build` (Details [Frontend](references/15-yves-frontend-atomic.md)) |
| Unklarer Cache | `cache:empty-all` |

   Komplett-Neubau nach Pyz-Änderungen (ohne DB-Rebuild): `docker/sdk cli vendor/bin/install -r docker -s build -s build-development`.
4. **Testen:** `docker/sdk testing` bzw. Codeception-Suiten ([Tests](references/26-tests.md), [Template](templates/codeception-test/README.md)).
5. **Prüfen:** Sniffer, PHPStan, Evaluator, [Review-Checkliste](checklists/review.md).
6. **Ausliefern:** [Release-/Deployment-Checkliste](checklists/release-deployment.md).

## Entscheidungshilfen

**Plugin vs. Vererbung vs. Konfiguration** (Reihenfolge einhalten)
- Geht es um einen **Wert** (Limit, Liste, Flag, Event-Liste)? → `Pyz\…\<Modul>Config` erweitern.
- Gibt es einen **Extension Point** (Plugin-Stack, Interface in `*-extension`)? → Plugin schreiben, im Pyz-DependencyProvider registrieren (Stack komplett in Pyz definieren oder `array_merge(parent::…)`; Entfernen = Stack ohne das Plugin definieren).
- Fehlt der Extension Point? → Spryker anfragen; Notlösung: Factory in Pyz überschreiben und neue `create…()`-Methode ergänzen (Customization). Modulversion mit `~` pinnen (→ [Core erweitern](references/03-core-erweitern.md)).
- Nur wenn nichts passt: Module replacement (kein Upgrade-Support).

**Facade vs. Client**
- Zed-intern (Modul A → Modul B): Facade von B, im Projekt direkt über den Locator im DependencyProvider (keine Bridge; `ProjectNoBridgeRule` des Project-Rulesets meldet Bridges in Projektmodulen, `code:sniff:architecture` im Standard prüft das nicht). Factory-Getter auf `…FacadeInterface` des Fremdmoduls typisieren.
- Yves/Glue brauchen **Lesedaten** (Produkte, CMS, Preise): **Client** auf Storage/Search (Daten per Publish & Sync).
- Yves/Glue brauchen **Schreibzugriff/Logik** (Kunde registrieren, Bestellung): Client mit Zed-Stub (`ZedRequest` → Zed-Gateway). Zed-Requests aus Yves sparsam einsetzen (Performance, → [Client](references/08-client-storage-search.md)).
- Nie: Yves/Glue → Zed-Facade direkt.

**Eigenes Modul vs. Projekterweiterung**
- Neue Fachlogik/Daten/Tabellen/UI → **neues Pyz-Modul** ([Projektmodul](references/02-projektmodul.md), [Template](templates/zed-module/README.md)). Hohe Upgrade-Sicherheit.
- Anpassung bestehenden Verhaltens → Config/Plugin im Pyz-DependencyProvider des Core-Moduls.
- Core-Schema um Spalte erweitern → Pyz-Schema mit gleichem Tabellennamen (`src/Pyz/Zed/<Modul>/Persistence/Propel/Schema/`, Merge, [Propel](references/05-propel-persistenz.md)).

**Glue Storefront vs. Backend API**
- Zielgruppe **Kunde/Shop-Frontend** (Kunden-OAuth) → Storefront. Im Release sind fast alle Commerce-Ressourcen im **klassischen GlueApplication** (`glue.*`, `ResourceRoutePlugin`); die decoupled `GlueStorefrontApiApplication` enthält nur wenige Ressourcen (token, stores, Multi-Factor-Auth). Details [Glue Storefront](references/12-glue-storefront.md).
- Zielgruppe **Back-Office-User/System-zu-System** → Glue Backend (`GlueBackendApiApplication`, `AbstractResourcePlugin`, OAuth Backend/API-Key). Details [Glue Backend](references/13-glue-backend.md).
- API Platform: nicht installiert, keine Empfehlung für neue Endpunkte.

**Repository/EntityManager vs. QueryContainer**
- Neue Module: **Repository** (lesen, Rückgabe als Transfer) + **EntityManager** (schreiben) + Mapper. QueryContainer ist Legacy (im Kernel ohne `@deprecated`-Annotation, in Pyz-Altmodulen im Einsatz); nur zum Erweitern bestehender Legacy-Module ([Propel](references/05-propel-persistenz.md)).

**Weitere Abgrenzungen**
- Daten von Zed nach Yves/Glue bringen → Publish & Sync ([Publisher](references/07-publish-sync.md)), nicht ZedRequest.
- Massenimport/Stammdaten → Data Import ([Data Import](references/09-data-import.md)).
- Statusfluss einer Bestellung → OMS; generische Zustandsautomaten → StateMachine ([OMS](references/10-oms-state-machine.md)).
- Rechte im Shop (Company-Rollen) → [B2B-Permissions](references/20-b2b-permissions.md); Rechte im Back Office (ACL) → [Back Office](references/18-back-office.md).

## Inhaltsverzeichnis

### Referenzen (`references/`)
| Nr. | Thema | Datei |
|---|---|---|
| 01 | Architektur: Applikationen, Schichten, Core vs. Pyz | [01-architektur.md](references/01-architektur.md) |
| 02 | Neues Projektmodul (Facade, Factory, DependencyProvider, Config; Bridges nur als Core-Muster) | [02-projektmodul.md](references/02-projektmodul.md) |
| 03 | Core erweitern: Plugins, Extension Points, Vererbung | [03-core-erweitern.md](references/03-core-erweitern.md) |
| 04 | Transfer-Objekte | [04-transfer.md](references/04-transfer.md) |
| 05 | Propel-Schema und Persistenz | [05-propel-persistenz.md](references/05-propel-persistenz.md) |
| 06 | Konfiguration und Umgebungen | [06-konfiguration.md](references/06-konfiguration.md) |
| 07 | Publish & Synchronization (Schreibseite) | [07-publish-sync.md](references/07-publish-sync.md) |
| 08 | Client: Storage, Search, ZedRequest (Leseseite) | [08-client-storage-search.md](references/08-client-storage-search.md) |
| 09 | Data Import | [09-data-import.md](references/09-data-import.md) |
| 10 | OMS und State Machine | [10-oms-state-machine.md](references/10-oms-state-machine.md) |
| 11 | Warenkorb, Preis, Checkout | [11-cart-preis-checkout.md](references/11-cart-preis-checkout.md) |
| 12 | Glue Storefront API | [12-glue-storefront.md](references/12-glue-storefront.md) |
| 13 | Glue Backend API, Autorisierung, Dynamic Entity | [13-glue-backend.md](references/13-glue-backend.md) |
| 14 | Yves: Controller, Routing, Twig-Erweiterung | [14-yves-controller-routing.md](references/14-yves-controller-routing.md) |
| 15 | Yves Atomic Frontend, Themes, Build | [15-yves-frontend-atomic.md](references/15-yves-frontend-atomic.md) |
| 16 | Widgets | [16-widgets.md](references/16-widgets.md) |
| 17 | CMS: Seiten, Blöcke, Content Items, Slots | [17-cms.md](references/17-cms.md) |
| 18 | Back Office: Seiten, Tabellen, Formulare, Navigation, ACL | [18-back-office.md](references/18-back-office.md) |
| 19 | Multi-Store, Regionen, Locales, Übersetzungen | [19-multi-store-i18n.md](references/19-multi-store-i18n.md) |
| 20 | B2B-Rechte im Storefront | [20-b2b-permissions.md](references/20-b2b-permissions.md) |
| 21 | Security und Session | [21-security-session.md](references/21-security-session.md) |
| 22 | Konsole, Cronjobs, Queue-Worker | [22-console-cron-queue.md](references/22-console-cron-queue.md) |
| 23 | Fehlerbehandlung, Logging, Debugging | [23-fehler-logging-debugging.md](references/23-fehler-logging-debugging.md) |
| 24 | Mail, Message Broker, ACP | [24-mail-message-broker.md](references/24-mail-message-broker.md) |
| 25 | Performance und Caching | [25-performance-caching.md](references/25-performance-caching.md) |
| 26 | Tests (Codeception, Testify) | [26-tests.md](references/26-tests.md) |
| 27 | Coding-Guidelines, statische Analyse | [27-coding-guidelines.md](references/27-coding-guidelines.md) |
| 28 | Upgradability: Evaluator, Modulversionen | [28-upgradability.md](references/28-upgradability.md) |
| 29 | Features integrieren, Abhängigkeiten | [29-feature-integration.md](references/29-feature-integration.md) |
| 30 | Docker SDK, deploy.yml, Install-Recipes, lokaler Workflow | [30-docker-sdk-workflow.md](references/30-docker-sdk-workflow.md) |

### Templates (`templates/`, gemeinsames Laufbeispiel: Modul `ProductBadge`)
| Template | Inhalt |
|---|---|
| [zed-module](templates/zed-module/README.md) | Zed Business/Communication/Persistence, Facade, Factory, DependencyProvider, Config, Repository, EntityManager, Mapper |
| [transfer-and-propel](templates/transfer-and-propel/README.md) | Transfer-XML, Propel-Schema (inkl. Storage-Tabelle) |
| [publisher-and-synchronization](templates/publisher-and-synchronization/README.md) | Publisher-, Synchronization-Plugin, Storage-Reader |
| [data-import-step](templates/data-import-step/README.md) | Data-Importer mit Steps und Writer |
| [oms-process](templates/oms-process/README.md) | OMS-Prozess-XML mit Command, Condition, Timeout |
| [glue-resource-storefront](templates/glue-resource-storefront/README.md) | Glue-Ressource (Storefront) |
| [glue-resource-backend](templates/glue-resource-backend/README.md) | Glue-Ressource (Backend) |
| [yves-controller-route](templates/yves-controller-route/README.md) | Yves-Controller, RouteProvider, Twig-View |
| [widget](templates/widget/README.md) | Yves-Widget |
| [twig-molecule-organism](templates/twig-molecule-organism/README.md) | Twig-Molecule/Organism mit index.ts und SCSS |
| [back-office-page](templates/back-office-page/README.md) | Back-Office-Seite mit navigation.xml-Eintrag und ACL-Hinweis |
| [codeception-test](templates/codeception-test/README.md) | Codeception-Test |

### Checklisten (`checklists/`)
[Review](checklists/review.md) · [Upgradability](checklists/upgradability.md) · [Release/Deployment](checklists/release-deployment.md)

## Wegweiser nach Aufgabe

- **Neues Feature als Projektmodul:** 02 → 04 → 05 → (07, 08) → Controller/Resource (14 / 12 / 13 / 18) → 26.
- **Core-Verhalten ändern:** 03 → 06 → 28.
- **Daten im Shop anzeigen:** 07 → 08 → 14 → 15/16.
- **Bestellprozess:** 11 → 10.
- **API bauen:** 12 oder 13 (Entscheidungshilfe oben).
- **Betrieb/Fehlersuche:** 30 → 23 → 22 → 25.
- **Neues Feature von Spryker einbinden:** 29 → 28.
