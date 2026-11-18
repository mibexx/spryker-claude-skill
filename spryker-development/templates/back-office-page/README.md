# Template: back-office-page (Zed-Modul `ProductBadgeGui`)

## Zweck

Back-Office-Seite für das Laufbeispiel ProductBadge: Liste (Tabelle mit Suche/Sortierung, Ajax), Anlegen per Symfony-Formular mit DataProvider, Menüeintrag, Übersetzungen (CSV), ACL-Rolle per Installer-Plugin. Konzepte und Fallstricke: [../../references/18-back-office.md](../../references/18-back-office.md).

Voraussetzung: das Business-Modul `Pyz\Zed\ProductBadge` (Template `zed-module`) mit `ProductBadgeFacadeInterface::createProductBadge(ProductBadgeTransfer): ProductBadgeTransfer` und die Propel-Tabelle `spy_product_badge` mit Namespace `Orm\Zed\ProductBadge\Persistence` (Template `transfer-and-propel`; Namespace live bestätigt).

## Dateiliste (Zielpfad unter dem Projektroot)

| Datei | Zweck |
|---|---|
| `src/Pyz/Zed/ProductBadgeGui/ProductBadgeGuiConfig.php` | URL-Konstanten |
| `src/Pyz/Zed/ProductBadgeGui/ProductBadgeGuiDependencyProvider.php` | stellt `FACADE_PRODUCT_BADGE` und `PROPEL_QUERY_PRODUCT_BADGE` bereit |
| `…/Communication/ProductBadgeGuiCommunicationFactory.php` | Table, Form, DataProvider, Facade, Query |
| `…/Communication/Controller/ListController.php` | `indexAction` (Seite), `tableAction` (Ajax-JSON) |
| `…/Communication/Controller/CreateController.php` | `indexAction` (Formular, Facade-Aufruf, Redirect); fängt `InvalidProductBadgeException` (unbekannter Badge-Key / unbekanntes Produkt), zeigt `addErrorMessage` und rendert das Formular erneut statt HTTP 500 |
| `…/Communication/Table/ProductBadgeTable.php` | `AbstractTable` mit `configure()`/`prepareData()` |
| `…/Communication/Form/ProductBadgeForm.php` | Formular (`data_class` = `ProductBadgeTransfer`) |
| `…/Communication/Form/DataProvider/ProductBadgeFormDataProvider.php` | `getData()`, `getOptions()` |
| `…/Communication/Plugin/Acl/ProductBadgeGuiAclInstallerPlugin.php` | Gruppe `product_badge_managers`, Rolle mit Regel `product-badge-gui/*/*` |
| `…/Communication/navigation.xml` | Modul-Navigation (Unterseite "Create", `visible 0`) |
| `…/Presentation/List/index.twig`, `…/Presentation/Create/index.twig` | Templates (`@ProductBadgeGui/List/index.twig`, `…/Create/index.twig`) |
| `src/Pyz/Zed/Acl/AclDependencyProvider.php` | **neue Datei** (existiert im Release nicht), registriert das ACL-Plugin |
| `src/Pyz/Zed/Translator/data/ProductBadgeGui/{de_DE,en_US}.csv` | Zed-Übersetzungen |

Bewusst nicht enthalten: Edit/Delete (der Facade-Vertrag hat nur `createProductBadge` und `getProductBadgeCollection`). Für Löschen das Muster aus `vendor/spryker/tax` (`DeleteRateController::indexAction()`/`confirmAction()`, Remove-Button per POST mit CSRF-Form, `generateRemoveButton()`) bzw. `vendor/spryker/api-key-gui` verwenden.

## Platzhalter / Anpassung

- `ProductBadgeGui`/`product-badge-gui` -> eigener Modulname (kebab-case in URL, `bundle` in `navigation.xml` und ACL-Regel).
- Facade/Query kommen direkt vom Locator (`$container->getLocator()->productBadge()->facade()`); im Projekt ohne Bridge; Factory-Getter sind auf das Interface des Fremdmoduls typisiert (Bridges sind ein Core-Muster, siehe Referenz 02).
- Die Tabelle fragt `SpyProductBadgeQuery` direkt ab (gängiger GUI-Kompromiss wie in `RateTable`/`ApiKeyTable`).
- Feldtypen/Validierung im Formular anpassen (statt Integer-Feld für `fkProductAbstract` ggf. Autosuggest).

## Registrierung

1. **Navigation (Ausschnitt, in bestehende Datei `config/Zed/navigation.xml` unter `<catalog><pages>` einfügen):**

```xml
<product-badge>
    <label>Product Badges</label>
    <title>Product Badges</title>
    <bundle>product-badge-gui</bundle>
    <controller>list</controller>
    <action>index</action>
</product-badge>
```

Grund: Pyz nutzt `BREADCRUMB_MERGE_STRATEGY` (`Pyz\Zed\ZedNavigation\ZedNavigationConfig`). Laut `BreadcrumbNavigationMergeStrategy` kommt die Menüstruktur aus `config/Zed/navigation.xml`; aus der Modul-`navigation.xml` werden nur die `pages` von Knoten mit **gleichem Schlüsselnamen (`product-badge`) und gleichem `bundle`** übernommen (hier die Unterseite `product-badge-create`). Live bestätigt: Nach `navigation:build-cache` steht "Product Badges" unter Catalog (Menü auf jeder Back-Office-Seite).
2. **ACL:** `Pyz\Zed\Acl\AclDependencyProvider` (neu, Datei im Template) registriert `ProductBadgeGuiAclInstallerPlugin`. Existiert die Datei im Projekt bereits, nur den Plugin-Eintrag in `getAclInstallerPlugins()` ergänzen. Der Installer ist additiv/idempotent (`AclInstallerPlugin` in `Pyz\Zed\Installer\InstallerDependencyProvider`). Er legt Gruppe und Rolle an, **weist aber keinen User zu** -> User der Gruppe im Back Office (Menü "Users") zuordnen. Root-User (`root_group`, `*/*/*`) sehen die Seite ohne Zutun. Die Regel mit Wildcard auf Controller/Action deckt auch die Ajax-Action `list/table` ab.
3. **Übersetzung:** CSVs liegen unter `src/Pyz/Zed/Translator/data/ProductBadgeGui/` (Ordnername frei, Dateiname `<locale>.csv`). Menü-Labels (`Product Badges`) stehen in derselben CSV, alternativ in `ZedNavigation/*.csv`. Nach Änderung Cache neu bauen.
4. Modul-Klassen werden per Class-Resolver gefunden (kein weiterer Eintrag nötig); neue Transfers/Propel nur falls geändert.

## Befehle danach `[live verifiziert (202610.0)]`

Alle fünf Befehle liefen im Shop 202610.0 mit Exit 0 (`setup:init-db` installiert die ACL-Gruppe `product_badge_managers`, Rolle `product_badge_manager_role`, Regel `product-badge-gui/*/*`, idempotent). Beim Router-Cache zusätzlich `docker/sdk cli console router:cache:warm-up:backoffice`.

```bash
docker/sdk cli console transfer:generate        # falls ProductBadge-Transfers neu
docker/sdk cli console propel:install           # falls spy_product_badge neu (siehe Template transfer-and-propel)
docker/sdk cli console navigation:build-cache
docker/sdk cli console translator:generate-cache
docker/sdk cli console setup:init-db            # führt u. a. die ACL-Installer-Plugins aus (idempotent)
```

Test: Back Office -> Catalog -> Product Badges; als Nicht-Root-User mit Gruppe `product_badge_managers` prüfen.

Live per curl geprüft (Demo-User `admin@spryker.com` / `change123`; das Login-Formular `auth` postet nach **`/login_check`** mit Feld `auth[_token]` aus dem Login-HTML, Cookie-Jar mitführen):
- `GET /product-badge-gui/list` `200`, `/product-badge-gui/list/table` `200` mit `{"draw":1,"recordsTotal":2,"data":[[2,5,"sale","Sale"],[1,4,"new","New!"]],...}`, `/product-badge-gui/create` `200`.
- `POST /product-badge-gui/create` (Felder `product_badge[fkProductAbstract|badgeKey|label|_token]`) mit gültigen Werten -> `302` auf `/product-badge-gui/list`, Zeile in `spy_product_badge`, nach dem Worker-Lauf Redis-Key `kv:product_badge:<id>`. Mit `badgeKey=invalidkey` -> `200`, Formular erneut mit "invalidkey" is not allowed.
- ACL: Ohne Login `302` auf `/security-gui/login`. Ein per SQL angelegter User in Gruppe `product_badge_managers` erhält `200` auf allen drei URLs, auf fremden Modulen (`/product-category-filter-gui/`) `302` auf `/acl/index/denied`; ein User ohne Gruppe wird auf allen vier URLs auf `/acl/index/denied` umgeleitet.

## Vorbild-Klassen / Quelle

- `vendor/spryker/api-key-gui/src/Spryker/Zed/ApiKeyGui/` (Controller `ListController`/`CreateController`, `ApiKeyTable`, `ApiKeyGuiDependencyProvider::addApiKeyPropelQuery()`, Factory, Presentation, `navigation.xml`)
- `vendor/spryker/tax/src/Spryker/Zed/Tax/Communication/{Table/RateTable.php,Form/TaxRateForm.php,Form/DataProvider/TaxRateFormDataProvider.php}` (`setUrl`, `setDefaultSortColumnIndex`, `addRawColumn`)
- `vendor/spryker/customer-access-gui` und `src/Pyz/Zed/CustomerAccessGui/` (Form + DataProvider + Pyz-Template)
- `vendor/spryker/acl-extension/…/AclInstallerPluginInterface.php`, `vendor/spryker/acl/src/Spryker/Zed/Acl/Business/Model/Installer.php`
- `src/Pyz/Zed/ZedNavigation/ZedNavigationConfig.php`, `config/Zed/navigation.xml`

## Verifikationsstatus

Klassen, Namespaces, Methodensignaturen (`AbstractController`, `AbstractTable`, `TableConfiguration::setUrl/setHeader/setSearchable/setSortable/setDefaultSortColumnIndex/setDefaultSortDirection/addRawColumn`, `AbstractType`, `AbstractCommunicationFactory::getFormFactory/getProvidedDependency`, `AclInstallerPluginInterface`, Transfer-Setter `Role::setAclGroup/addAclRule`, `AclConstants`) gegen `vendor/` geprüft. Durchgespielt im Shop 202610.0 (Seite, Tabelle, Formular, Navigation, ACL; `code:sniff:style` Exit 0, PHPStan Level 6 ohne Befund); abhängig von den Templates `zed-module` und `transfer-and-propel`. Der Navigations-Merge (`BREADCRUMB_MERGE_STRATEGY`) ist live bestätigt. `[live verifiziert (202610.0)]` Symfony-Constraints mit Named Arguments (`new Length(max: 255)`), da die Array-Form im installierten Validator als deprecated markiert ist (Core-Code nutzt noch die Array-Form).
