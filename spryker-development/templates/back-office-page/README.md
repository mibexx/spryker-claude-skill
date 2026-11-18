# Template: back-office-page (Zed module `ProductBadgeGui`)

## Purpose

Back Office page for the running example ProductBadge: list (table with search/sorting, Ajax), creation via a Symfony form with DataProvider, menu entry, translations (CSV), ACL role via installer plugin. Concepts and pitfalls: [../../references/18-back-office.md](../../references/18-back-office.md).

Prerequisite: the business module `Pyz\Zed\ProductBadge` (template `zed-module`) with `ProductBadgeFacadeInterface::createProductBadge(ProductBadgeTransfer): ProductBadgeTransfer` and the Propel table `spy_product_badge` with namespace `Orm\Zed\ProductBadge\Persistence` (template `transfer-and-propel`; namespace confirmed live).

## File list (target path below the project root)

| File | Purpose |
|---|---|
| `src/Pyz/Zed/ProductBadgeGui/ProductBadgeGuiConfig.php` | URL constants |
| `src/Pyz/Zed/ProductBadgeGui/ProductBadgeGuiDependencyProvider.php` | provides `FACADE_PRODUCT_BADGE` and `PROPEL_QUERY_PRODUCT_BADGE` |
| `…/Communication/ProductBadgeGuiCommunicationFactory.php` | Table, Form, DataProvider, Facade, Query |
| `…/Communication/Controller/ListController.php` | `indexAction` (page), `tableAction` (Ajax JSON) |
| `…/Communication/Controller/CreateController.php` | `indexAction` (form, Facade call, redirect); catches `InvalidProductBadgeException` (unknown badge key / unknown product), shows `addErrorMessage` and renders the form again instead of HTTP 500 |
| `…/Communication/Table/ProductBadgeTable.php` | `AbstractTable` with `configure()`/`prepareData()` |
| `…/Communication/Form/ProductBadgeForm.php` | form (`data_class` = `ProductBadgeTransfer`) |
| `…/Communication/Form/DataProvider/ProductBadgeFormDataProvider.php` | `getData()`, `getOptions()` |
| `…/Communication/Plugin/Acl/ProductBadgeGuiAclInstallerPlugin.php` | group `product_badge_managers`, role with rule `product-badge-gui/*/*` |
| `…/Communication/navigation.xml` | module navigation (subpage "Create", `visible 0`) |
| `…/Presentation/List/index.twig`, `…/Presentation/Create/index.twig` | templates (`@ProductBadgeGui/List/index.twig`, `…/Create/index.twig`) |
| `src/Pyz/Zed/Acl/AclDependencyProvider.php` | **new file** (does not exist in the release), registers the ACL plugin |
| `src/Pyz/Zed/Translator/data/ProductBadgeGui/{de_DE,en_US}.csv` | Zed translations |

Deliberately not included: edit/delete (the Facade contract only has `createProductBadge` and `getProductBadgeCollection`). For deletion use the pattern from `vendor/spryker/tax` (`DeleteRateController::indexAction()`/`confirmAction()`, remove button via POST with CSRF form, `generateRemoveButton()`) or `vendor/spryker/api-key-gui`.

## Placeholders / customization

- `ProductBadgeGui`/`product-badge-gui` -> your own module name (kebab-case in the URL, `bundle` in `navigation.xml` and the ACL rule).
- Facade/Query come directly from the locator (`$container->getLocator()->productBadge()->facade()`); in the project without a bridge; Factory getters are typed to the foreign module's interface (bridges are a core pattern, see reference 02).
- The table queries `SpyProductBadgeQuery` directly (common GUI compromise as in `RateTable`/`ApiKeyTable`).
- Adjust field types/validation in the form (instead of an integer field for `fkProductAbstract`, possibly autosuggest).

## Registration

1. **Navigation (excerpt, insert into the existing file `config/Zed/navigation.xml` under `<catalog><pages>`):**

```xml
<product-badge>
    <label>Product Badges</label>
    <title>Product Badges</title>
    <bundle>product-badge-gui</bundle>
    <controller>list</controller>
    <action>index</action>
</product-badge>
```

Reason: Pyz uses `BREADCRUMB_MERGE_STRATEGY` (`Pyz\Zed\ZedNavigation\ZedNavigationConfig`). According to `BreadcrumbNavigationMergeStrategy`, the menu structure comes from `config/Zed/navigation.xml`; from the module `navigation.xml` only the `pages` of nodes with the **same key name (`product-badge`) and same `bundle`** are taken over (here the subpage `product-badge-create`). Confirmed live: after `navigation:build-cache`, "Product Badges" appears under Catalog (menu on every Back Office page).
2. **ACL:** `Pyz\Zed\Acl\AclDependencyProvider` (new, file in the template) registers `ProductBadgeGuiAclInstallerPlugin`. If the file already exists in the project, only add the plugin entry to `getAclInstallerPlugins()`. The installer is additive/idempotent (`AclInstallerPlugin` in `Pyz\Zed\Installer\InstallerDependencyProvider`). It creates the group and role, **but does not assign any user** -> assign users to the group in the Back Office (menu "Users"). Root users (`root_group`, `*/*/*`) see the page without further action. The rule with a wildcard on controller/action also covers the Ajax action `list/table`.
3. **Translation:** CSVs are located under `src/Pyz/Zed/Translator/data/ProductBadgeGui/` (folder name free, file name `<locale>.csv`). Menu labels (`Product Badges`) are in the same CSV, alternatively in `ZedNavigation/*.csv`. Rebuild the cache after changes.
4. Module classes are found via the class resolver (no further entry needed); new Transfers/Propel only if changed.

## Commands afterwards `[live verified (202610.0)]`

All five commands ran in shop 202610.0 with exit 0 (`setup:init-db` installs the ACL group `product_badge_managers`, role `product_badge_manager_role`, rule `product-badge-gui/*/*`, idempotent). For the router cache additionally `docker/sdk cli console router:cache:warm-up:backoffice`.

```bash
docker/sdk cli console transfer:generate        # if ProductBadge transfers are new
docker/sdk cli console propel:install           # if spy_product_badge is new (see template transfer-and-propel)
docker/sdk cli console navigation:build-cache
docker/sdk cli console translator:generate-cache
docker/sdk cli console setup:init-db            # runs, among others, the ACL installer plugins (idempotent)
```

Test: Back Office -> Catalog -> Product Badges; check as a non-root user with group `product_badge_managers`.

Checked live via curl (demo user `admin@spryker.com` / `change123`; the login form `auth` posts to **`/login_check`** with field `auth[_token]` from the login HTML, keep a cookie jar):
- `GET /product-badge-gui/list` `200`, `/product-badge-gui/list/table` `200` with `{"draw":1,"recordsTotal":2,"data":[[2,5,"sale","Sale"],[1,4,"new","New!"]],...}`, `/product-badge-gui/create` `200`.
- `POST /product-badge-gui/create` (fields `product_badge[fkProductAbstract|badgeKey|label|_token]`) with valid values -> `302` to `/product-badge-gui/list`, row in `spy_product_badge`, after the worker run Redis key `kv:product_badge:<id>`. With `badgeKey=invalidkey` -> `200`, form again with "invalidkey" is not allowed.
- ACL: without login `302` to `/security-gui/login`. A user created via SQL in group `product_badge_managers` gets `200` on all three URLs, on foreign modules (`/product-category-filter-gui/`) `302` to `/acl/index/denied`; a user without a group is redirected to `/acl/index/denied` on all four URLs.

## Model classes / source

- `vendor/spryker/api-key-gui/src/Spryker/Zed/ApiKeyGui/` (controllers `ListController`/`CreateController`, `ApiKeyTable`, `ApiKeyGuiDependencyProvider::addApiKeyPropelQuery()`, Factory, Presentation, `navigation.xml`)
- `vendor/spryker/tax/src/Spryker/Zed/Tax/Communication/{Table/RateTable.php,Form/TaxRateForm.php,Form/DataProvider/TaxRateFormDataProvider.php}` (`setUrl`, `setDefaultSortColumnIndex`, `addRawColumn`)
- `vendor/spryker/customer-access-gui` and `src/Pyz/Zed/CustomerAccessGui/` (Form + DataProvider + Pyz template)
- `vendor/spryker/acl-extension/…/AclInstallerPluginInterface.php`, `vendor/spryker/acl/src/Spryker/Zed/Acl/Business/Model/Installer.php`
- `src/Pyz/Zed/ZedNavigation/ZedNavigationConfig.php`, `config/Zed/navigation.xml`

## Verification status

Classes, namespaces, method signatures (`AbstractController`, `AbstractTable`, `TableConfiguration::setUrl/setHeader/setSearchable/setSortable/setDefaultSortColumnIndex/setDefaultSortDirection/addRawColumn`, `AbstractType`, `AbstractCommunicationFactory::getFormFactory/getProvidedDependency`, `AclInstallerPluginInterface`, Transfer setters `Role::setAclGroup/addAclRule`, `AclConstants`) checked against `vendor/`. Walked through in shop 202610.0 (page, table, form, navigation, ACL; `code:sniff:style` exit 0, PHPStan level 6 without findings); depends on the templates `zed-module` and `transfer-and-propel`. The navigation merge (`BREADCRUMB_MERGE_STRATEGY`) is confirmed live. `[live verified (202610.0)]` Symfony constraints with named arguments (`new Length(max: 255)`), because the array form is marked as deprecated in the installed Validator (core code still uses the array form).
