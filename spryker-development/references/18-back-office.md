# Back Office (Zed-UI): Seiten, Tabellen, Formulare, Navigation, Übersetzungen, ACL (Recherche T-18)

> Status: Release 202610.0 (kernel 3.87.1, Symfony 6.4.48). `[code-verifiziert, Doku nicht abgeglichen]`: Doku-Seiten lieferten nur Titel, alle Aussagen am Code geprüft. Beim Autorenlauf zusätzlich bestätigt: `BreadcrumbNavigationMergeStrategy`, `Installer`/`AclInstallerPluginInterface`, Vorbildmodul `vendor/spryker/api-key-gui`. Die Beispiele sind aus Vendor-Mustern abgeleitet und nicht ausgeführt `[Annahme: lauffähig]`. Konsolenbefehle im Container ausgeführt `[live verifiziert (202610.0)]`; `npm run zed`, `zed:production` und `frontend:zed:build` ausgeführt `[live verifiziert (202610.0)]` (`zed:production` braucht im lokalen Docker `NODE_OPTIONS=--max-old-space-size=2048`, sonst OOM-Kill mit Exit 137). Storefront-Rechte (Company-Rollen) → [20](20-b2b-permissions.md); Login/MFA/CSRF → [21](21-security-session.md).

## Zweck

Back-Office-Seiten (Zed `Communication` + `Presentation`) entwickeln: Controller, `AbstractTable`, Symfony-Formulare mit DataProvider, Menüeintrag, Übersetzungen, ACL. Modulgerüst → [02](02-projektmodul.md); Plugin-Erweiterung bestehender GUIs → [03](03-core-erweitern.md). Vollständige Vorlage: [../templates/back-office-page/](../templates/back-office-page/README.md).

## Konzepte

**URL -> Code:** `/<module-kebab>/<controller-kebab>/<action-kebab>` -> `Pyz\Zed\<Module>\Communication\Controller\<Controller>Controller::<action>Action()` -> Template `Presentation/<Controller>/<action>.twig`, Namespace `@<Module>/<Controller>/<action>.twig`. Beispiel: `/tax/rate/list` -> `RateController::listAction()` -> `Presentation/Rate/list.twig`.

**Controller** erbt `Spryker\Zed\Kernel\Communication\Controller\AbstractController`; `getFactory()`/`getFacade()` per `@method`-Docblock typisiert. Rückgabe: `array` (Twig-Variablen), `viewResponse([...])`, `redirectResponse($url, 302)`, `jsonResponse($data)`. Helfer: `castId()`, `addSuccessMessage('Text %d', ['%d' => $id])`, `addInfoMessage()`, `addErrorMessage()`.

**Templates:** Pfadauflösung Projekt vor Core (`Spryker\Zed\Twig\TwigConfig::getTemplatePaths()`); ein gleichnamiges Template in `src/Pyz/Zed/<Modul>/Presentation/…` überschreibt Core (Replacement, → [03](03-core-erweitern.md)). Layout `{% extends '@Gui/Layout/layout.twig' %}`, Blöcke `head_title`, `section_title`, `action` (Buttons im Seitenkopf), `content`, `footer_js`, `head_css`. Partials: `{% embed '@Gui/Partials/widget.twig' with { widget_title: '…' | trans } %}{% block widget_content %}…{% endblock %}{% endembed %}`. Twig-Helfer: `createActionButton(url, title)`, `backActionButton(url, title)`, `removeActionButton(url, title)`, `url('/modul/controller/action', {…})`, `assetsPath('…')`. Das 202610.0-Layout ist Bootstrap 5 (neu); ältere Doku/`ibox`-Markup teils veraltet (Partials existieren noch). Block "Reset password" im Layout ist `{% deprecated %}` (wird Plugin im nächsten Major).

**Tabellen** (`Spryker\Zed\Gui\Communication\Table\AbstractTable`): abstrakt `configure(TableConfiguration $config)` (Header, `setSearchable`, `setSortable`, `setRawColumns`/`addRawColumn`, `setUrl`) und `prepareData(TableConfiguration $config)` (Zeilen-Array, typischerweise `$this->runQuery($query, $config, true)`). Default-Ajax-URL ist `table` -> Controller braucht `tableAction`; abweichend mit `$config->setUrl(…)` (Tax: `listTableAction`). Render: `return ['table' => $table->render()]`, im Twig `{{ table | raw }}`; Ajax: `jsonResponse($table->fetchData())`. Button-Helfer: `generateCreateButton`, `generateEditButton`, `generateViewButton`, `generateRemoveButton($url, $title, $options, $formClassName = DeleteForm::class)`, `generateButtonGroup`. Aktionsspalte muss Raw-Spalte sein, alle anderen werden escaped. Neu in 202610.0: adaptiver "Large-Table-Mode" und CSV-Download (Schwellwert `GuiConfig::getLargeTableRowCountThreshold()`, Code-verifiziert, vom Frontend-Reviewer bestätigt). Erweiterung bestehender Tabellen über Expander-Plugins (z. B. `UserTableConfigExpanderPluginInterface`).

**Löschen** nur per POST mit CSRF-Bestätigungsformular (Tax: `DeleteRateController::indexAction()` + `confirmAction()`); der alte `deleteAction()` im `RateController` ist `@deprecated`.

**Formulare:** Form erbt `Spryker\Zed\Kernel\Communication\Form\AbstractType`, Daten als Transfer (`data_class`). DataProvider = einfache Klasse mit `getData(?int $id)` und `getOptions(): array` (Vorbild `TaxRateFormDataProvider`). Die Factory erzeugt: `$this->getFormFactory()->create(Form::class, $transfer, $options)`. Controller: `handleRequest`, `isSubmitted() && isValid()`, Facade-Aufruf, `createView()`. Twig: `form_start`, `form_row`/`form_widget`, Submit `<input type="submit" class="btn btn-primary safe-submit" …>`, `form_end`. Aktive Form-Plugins (`Pyz\Zed\Form\FormDependencyProvider`): `ValidatorFormPlugin`, `HttpFoundationFormPlugin`, `CsrfFormPlugin`, `NoValidateFormTypeExtensionFormPlugin`, `SanitizeXssTypeExtensionFormPlugin`, `MultiFactorAuthExtensionFormPlugin` -> CSRF aktiv, XSS-Sanitierung per Typ-Extension. Erweiterung fremder Forms: Expander-Plugins (`UserFormExpanderPluginInterface`).

**Navigation:** `navigation.xml` je Modul unter `…/Zed/<Modul>/Communication/` (`ZedNavigationConfig::getNavigationSchemaPathPattern()`), `navigation-secondary.xml` für die zweite Navigation. Knoten: `label`, `title`, `bundle` (Modul kebab-case), `controller`, `action`, `uri` (`#` = Gruppe), `icon` (`fa-…`), `visible` (`0` = nur Breadcrumb/Berechtigung), verschachtelt in `<pages>`; max. 5 Ebenen. **Merge-Strategie in Pyz = `BREADCRUMB_MERGE_STRATEGY`** (`Pyz\Zed\ZedNavigation\ZedNavigationConfig`). Code-Befund (`BreadcrumbNavigationMergeStrategy::mergeNavigation()`): Ergebnis ist die Struktur von `config/Zed/navigation.xml`; aus den Modul-Dateien werden nur die `pages` von Knoten übernommen, die im Root unter **gleichem Schlüsselnamen und gleichem `bundle`** stehen. Folge: Menüpunkt (Ebene 1 und 2) muss im Root eingetragen sein; Unterseiten (create/edit, `visible 0`) kommen aus dem Modul-XML. Dies löst die Annahme der Recherche auf (nicht live ausgeführt). Navigationsfilter: `AclNavigationItemCollectionFilterPlugin` (Menü folgt der ACL), `BackofficeNavigationItemCollectionFilterPlugin`. Deprecated: `ZedNavigationConfig::getNavigationSchemaFileNamePattern()` -> `getNavigationSchemaFileNamePatterns()`; `getRootNavigationSchema()` -> `getRootNavigationSchemaPaths()`; `isAddingGlobalVariables()` nur BC (Twig `navigation()`/`breadcrumb()`).

**Übersetzungen (Zed)** sind CSV-Dateien (Komma, Zeile `"Quelltext",Übersetzung`), keine Glossar-DB. Core: `vendor/spryker/<modul>/data/translation/Zed/<locale>.csv`. **Projekt: `src/Pyz/Zed/Translator/data/<Name>/<locale>.csv`** (Pattern `src/<Namespace>/Zed/Translator/data/*/xx_XX.csv`, Ordnername frei); das Top-Level-`data/translation/` wird nicht mehr gelesen (nur README). Merge `core` dann `project` (Projekt überschreibt `[Annahme: Loader nicht separat geprüft]`). Fallback `TranslatorConstants::TRANSLATION_ZED_FALLBACK_LOCALES` (`['de_DE' => ['en_US']]`). Cache `src/Generated/Zed/Translator/…`; nach jeder CSV-Änderung `translator:generate-cache`. Nutzung: `{{ 'Text' | trans }}`, PHP-Messages und Form-Labels automatisch. Quelltext = Schlüssel (englischer Satz), Locales `en_US.csv`, `de_DE.csv`. Yves-Texte sind ein separater Mechanismus → [19](19-multi-store-i18n.md).

**ACL:** User <-> Group (n:m) <-> Role <-> Rule (`bundle`, `controller`, `action`, `type` `allow|deny`). Prüfung durch `AccessControlEventDispatcherPlugin` auf `KernelEvents::REQUEST` (registriert in `Pyz\Zed\EventDispatcher\EventDispatcherDependencyProvider`); ohne Berechtigung Redirect `/acl/index/denied`. Auswertung (`RuleValidator`): **Deny schlägt Allow**, Wildcard `*` (`AclConstants::VALIDATOR_WILDCARD`) auf Bundle/Controller/Action; kein Allow = verboten. Rule-Keys = URL-Segmente = `navigation.xml`-Werte (kebab-case). Globale Regeln in `config/Shared/config_default.php` (`ACL_DEFAULT_CREDENTIALS`, `ACL_DEFAULT_RULES`, `ACL_USER_RULE_WHITELIST`). Der Installer ist idempotent und **nur additiv** (existierende Gruppen übersprungen, Rollen nach Name gesucht, Regeln mit `existsRoleRule()` geprüft); er läuft über `AclInstallerPlugin` (in `Pyz\Zed\Installer\InstallerDependencyProvider`), d. h. bei `setup:init-db`. Bestehende Regeln ändern: Back Office (Menü **Users** -> "User Roles"/"User Groups", `config/Zed/navigation.xml`) oder eigene Migration. Eigene Rollen/Gruppen: `AclInstallerPluginInterface` (`getRoles()`, `getGroups()`), registriert in `Pyz\Zed\Acl\AclDependencyProvider::getAclInstallerPlugins()` (existiert in Pyz nicht, anlegen). Weitere Extension Points: `AclRolesExpanderPluginInterface`, `AclRolePostSavePluginInterface`, `AclAccessCheckerStrategyPluginInterface`. Neue Seiten sind für Nicht-Root-User unsichtbar/gesperrt, bis die Regel vergeben ist.

**Abgrenzung AclEntity:** Dieses ACL regelt den Zugriff auf URLs (Bundle/Controller/Action) im Back Office. Die datensatzbezogene Zugriffskontrolle auf Propel-Entities ("AclEntity", Segmente/Scopes pro Entity) ist ein eigenes Feature und **im Release nicht installiert**: `spryker/acl-entity` fehlt in `vendor/`; vorhanden ist nur `spryker/acl-entity-extension` (die Interfaces `AclEntityMetadataConfigExpanderPluginInterface`, `AclEntityDisablerPluginInterface`), und `src/Pyz` nutzt AclEntity nicht. Pläne mit AclEntity (z. B. Datensichtbarkeit je Merchant/Segment) erfordern erst die Installation des Moduls samt Feature-Integration `[Annahme: Details nicht geprüft]`; Storefront-Rechte (Company-Rollen) sind wieder ein anderer Mechanismus ([20](20-b2b-permissions.md)).

**Assets:** `npm run zed`, `zed:watch`, `zed:production`, `zed:lint`, `zed:stylelint` bzw. `frontend:zed:build` (`-e production`). Builder `vendor/spryker/gui/src/Spryker/Zed/Gui/FrontendBuilder/` findet `**/Zed/**/*.entry.js|ts` in Core, Features, Eco, SDK und `./src/Pyz/Zed`; Ausgabe `public/Backoffice/assets`. Modul-Assets `assets/Zed/js/<name>.entry.js` (+ `modules/`, `scss/`); Pfad im Pyz-Modul `src/Pyz/Zed/<Modul>/assets/Zed/…` nicht an einem Pyz-Modul verifiziert `[Annahme]`. Builder ist TypeScript (`.mts`); Doku zum Webpack-Build ist veraltet. Allgemein → [15](15-yves-frontend-atomic.md).

## Anleitung

1. **Modul** `Pyz\Zed\<Name>Gui` mit `Communication/` (Controller, Factory, Table, Form, `navigation.xml`) und `Presentation/` anlegen ([02](02-projektmodul.md)); Daten über Facade des Business-Moduls, Tabellen-Query per DependencyProvider als `PROPEL_QUERY_*` bereitstellen (Muster `ApiKeyGuiDependencyProvider`).
2. **Controller:** `listAction` + `tableAction` (Tabelle), `createAction` (Form). Löschen mit Bestätigungsformular.
3. **Twig:** `@Gui/Layout/layout.twig`, `widget.twig`, `{{ table | raw }}`; Texte mit `| trans`.
4. **Navigation:** Knoten in `config/Zed/navigation.xml` (z. B. unter `<catalog><pages>`) und `pages` im Modul-`navigation.xml` (gleicher Schlüssel + `bundle`).
5. **Übersetzung:** `src/Pyz/Zed/Translator/data/<Name>Gui/{de_DE,en_US}.csv`, Menü-Labels in dieselbe oder `ZedNavigation/*.csv`.
6. **ACL:** Rolle/Gruppe per Installer-Plugin oder im Back Office vergeben; Wildcard auf Controller einplanen (Ajax-Action `table` ist eigene Action).
7. **Aktivieren** (alle Befehle `[live verifiziert (202610.0)]`):

```bash
docker/sdk cli console transfer:generate        # nur bei neuen Transfers
docker/sdk cli console navigation:build-cache   # Menü (auch: navigation:cache:remove)
docker/sdk cli console translator:generate-cache
docker/sdk cli console setup:init-db            # führt u. a. ACL-Installer-Plugins aus (idempotent)
docker/sdk cli 'npm run zed'                    # nur bei eigenen Assets
```

## Beispiele

Komplett lauffähig gedachte Dateien (ProductBadgeGui: Controller, Table, Form, DataProvider, Factory, DependencyProvider, Twig, `navigation.xml`, ACL-Plugin, CSV): [../templates/back-office-page/](../templates/back-office-page/README.md).

**Echt (Pyz):** Navigation-Filter `src/Pyz/Zed/ZedNavigation/ZedNavigationDependencyProvider.php`

```php
protected function getNavigationItemCollectionFilterPlugins(): array
{
    return [
        new AclNavigationItemCollectionFilterPlugin(),
        new BackofficeNavigationItemCollectionFilterPlugin(),
    ];
}
```

**Echt (Pyz):** Installer-User `src/Pyz/Zed/Acl/AclConfig.php`

```php
public function getInstallerUsers(): array
{
    return [
        'admin@spryker.com' => ['group' => AclConstants::ROOT_GROUP],
        'admin_de@spryker.com' => ['group' => AclConstants::ROOT_GROUP],
    ];
}
```

**Echt (Core, Referenzstruktur):** `vendor/spryker/api-key-gui` (modern, Controller `List`/`Create`/`Edit`/`Delete`, `ApiKeyTable` mit `setRawColumns`, `setTableIdentifier`, `setDefaultSortField`, DataProvider, Presentation je Controller) und `vendor/spryker/tax` (`RateTable`, `TaxRateForm`, `TaxRateFormDataProvider`, Delete-Bestätigung).

**Echt (Core):** Aktionsspalte in `RateTable::createDeleteButton()`: `$this->generateRemoveButton(Url::generate('/tax/delete-rate', ['id-tax-rate' => $id]), 'Delete')`.

**Echt (Pyz-Override, Form+DataProvider):** `src/Pyz/Zed/CustomerAccessGui/Presentation/Index/index.twig` nutzt `{% extends '@Gui/Layout/layout.twig' %}`, `{% embed '@Gui/Partials/widget.twig' … %}`, `form_start(form)` … `<input type="submit" class="btn btn-primary safe-submit" value="{{ 'Save' | trans }}" />`.

## Fallstricke

1. **Menüpunkt fehlt:** Cache nicht neu gebaut, Knoten nicht in `config/Zed/navigation.xml` (Pyz-Merge-Strategie), `bundle`/`controller`/`action` != URL-Segmente (kebab-case), oder ACL-Regel fehlt (Filter blendet aus).
2. **Redirect `/acl/index/denied`:** Regel fehlt; Deny schlägt Allow; Ajax-Action `table` braucht eigene Erlaubnis (Wildcard auf Controller).
3. **Tabelle zeigt HTML als Text:** Aktionsspalte nicht Raw. Umgekehrt keine Benutzereingaben in Raw-Spalten ohne Escape (XSS).
4. **Tabelle lädt nicht:** `tableAction` fehlt oder `setUrl()` zeigt ins Leere; Header-Keys müssen den Keys aus `prepareData` entsprechen.
5. **Übersetzung wirkt nicht:** `translator:generate-cache` vergessen, falscher Delimiter (muss `,` sein), Datei nicht `xx_XX.csv`, Datei im alten `data/translation/`.
6. **Löschen per GET:** nie; Bestätigungsseite + POST mit CSRF-Form.
7. **Template nicht gefunden:** Controller-Name != Presentation-Ordner (`RateController` -> `Presentation/Rate/`), `listAction` -> `list.twig`.
8. **ACL-Installer ändert nichts an Bestandsdaten:** nur additiv; Änderungen laufender Systeme über Back Office/Migration.
9. Default-Passwort `change123` in `Pyz\Zed\User\UserConfig` nur Dev.
10. Core-`navigation.xml` kopieren, Core-Controller/-Templates komplett kopieren, wo ein Plugin-Punkt existiert -> Upgrade-Risiko ([28](28-upgradability.md)).
11. Controller dünn halten: Logik in Facade; Query direkt in der Tabelle ist gängiger GUI-Kompromiss, Daten sonst über Repository/Facade ([01](01-architektur.md)).

## Checkliste

- [ ] Controller dünn, `@method`-Docblocks, `declare(strict_types = 1)`
- [ ] Tabelle: Raw-Spalte, `tableAction`, Suche/Sortierung gesetzt
- [ ] Formular über Factory + DataProvider, CSRF aktiv (Standard), Löschen per POST
- [ ] Knoten in `config/Zed/navigation.xml` und Modul-`navigation.xml`, `navigation:build-cache`
- [ ] CSV in `src/Pyz/Zed/Translator/data/<Name>/` für `de_DE` und `en_US`, `translator:generate-cache`
- [ ] ACL-Rolle/Regel (`bundle`/`*`/`*`) vergeben (URL-ACL; AclEntity ist nicht installiert), Menü mit Nicht-Root-User geprüft
- [ ] Alle Texte mit `| trans`; keine Edits in `vendor/`

## Quellen

Doku (Basis `https://docs.spryker.com/docs/`, nur Startpunkte): `dg/dev/backend-development/zed/zed.html`, `…/zed/communication-layer/communication-layer.html`, `…/zed-ui-tables/create-and-configure-zed-tables.html`, `…/zed-ui-tables/adding-buttons-to-zed-tables.html`, `…/forms/create-forms.html`, `…/zed/add-navigation-in-the-back-office.html` (Stand 2021), `pbc/all/back-office/latest/base-shop/back-office-translations-overview.html`, `pbc/all/user-management/latest/base-shop/user-and-rights-overview.html`.

Code: `vendor/spryker/gui/src/Spryker/Zed/Gui/Communication/Table/AbstractTable.php`, `…/Presentation/{Layout,Partials}/*`, `vendor/spryker/tax/src/Spryker/Zed/Tax/Communication/**`, `vendor/spryker/api-key-gui/src/Spryker/Zed/ApiKeyGui/**`, `vendor/spryker/zed-navigation/src/Spryker/Zed/ZedNavigation/{ZedNavigationConfig.php,Business/Strategy/BreadcrumbNavigationMergeStrategy.php}`, `vendor/spryker/translator/src/Spryker/Zed/Translator/TranslatorConfig.php`, `vendor/spryker/acl/src/Spryker/Zed/Acl/{AclDependencyProvider.php,Business/Model/{Installer,RuleValidator}.php}`, `vendor/spryker/acl-extension/…/AclInstallerPluginInterface.php`, `vendor/spryker/kernel/src/Spryker/Zed/Kernel/Communication/Controller/AbstractController.php`, `config/Zed/navigation.xml`, `config/install/docker.yml`, `src/Pyz/Zed/{Acl,ZedNavigation,Form,Gui,CustomerAccessGui,Translator}/…`.

Nachbarn: [02 Projektmodul](02-projektmodul.md), [03 Core erweitern](03-core-erweitern.md), [05 Propel](05-propel-persistenz.md), [20 B2B-Rechte](20-b2b-permissions.md), [21 Security](21-security-session.md), [19 i18n](19-multi-store-i18n.md).
