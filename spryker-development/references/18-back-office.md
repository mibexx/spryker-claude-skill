# Back Office (Zed UI): pages, tables, forms, navigation, translations, ACL

> Status: release 202610.0 (kernel 3.87.1, Symfony 6.4.48). `[code-verified, docs not cross-checked]`: docs pages only returned titles, all statements checked against the code. Additionally confirmed during the authoring run: `BreadcrumbNavigationMergeStrategy`, `Installer`/`AclInstallerPluginInterface`, reference module `vendor/spryker/api-key-gui`. The examples are derived from vendor patterns and were not executed `[Assumption: runnable]`. Console commands executed in the container `[live verified (202610.0)]`; `npm run zed`, `zed:production` and `frontend:zed:build` executed `[live verified (202610.0)]` (`zed:production` needs `NODE_OPTIONS=--max-old-space-size=2048` in local Docker, otherwise OOM kill with exit 137). Storefront permissions (company roles) → [20](20-b2b-permissions.md); login/MFA/CSRF → [21](21-security-session.md).

## Purpose

Develop Back Office pages (Zed `Communication` + `Presentation`): controllers, `AbstractTable`, Symfony forms with DataProvider, menu entry, translations, ACL. Module scaffold → [02](02-project-module.md); plugin extension of existing GUIs → [03](03-extending-the-core.md). Complete template: [../templates/back-office-page/](../templates/back-office-page/README.md).

## Concepts

**URL -> code:** `/<module-kebab>/<controller-kebab>/<action-kebab>` -> `Pyz\Zed\<Module>\Communication\Controller\<Controller>Controller::<action>Action()` -> template `Presentation/<Controller>/<action>.twig`, namespace `@<Module>/<Controller>/<action>.twig`. Example: `/tax/rate/list` -> `RateController::listAction()` -> `Presentation/Rate/list.twig`.

**Controller** extends `Spryker\Zed\Kernel\Communication\Controller\AbstractController`; `getFactory()`/`getFacade()` typed via `@method` docblock. Return: `array` (Twig variables), `viewResponse([...])`, `redirectResponse($url, 302)`, `jsonResponse($data)`. Helpers: `castId()`, `addSuccessMessage('Text %d', ['%d' => $id])`, `addInfoMessage()`, `addErrorMessage()`.

**Templates:** path resolution project before core (`Spryker\Zed\Twig\TwigConfig::getTemplatePaths()`); a template with the same name in `src/Pyz/Zed/<Module>/Presentation/…` overrides core (replacement, → [03](03-extending-the-core.md)). Layout `{% extends '@Gui/Layout/layout.twig' %}`, blocks `head_title`, `section_title`, `action` (buttons in the page header), `content`, `footer_js`, `head_css`. Partials: `{% embed '@Gui/Partials/widget.twig' with { widget_title: '…' | trans } %}{% block widget_content %}…{% endblock %}{% endembed %}`. Twig helpers: `createActionButton(url, title)`, `backActionButton(url, title)`, `removeActionButton(url, title)`, `url('/module/controller/action', {…})`, `assetsPath('…')`. The 202610.0 layout is Bootstrap 5 (new); older docs/`ibox` markup partly outdated (partials still exist). The "Reset password" block in the layout is `{% deprecated %}` (becomes a plugin in the next major).

**Tables** (`Spryker\Zed\Gui\Communication\Table\AbstractTable`): abstract `configure(TableConfiguration $config)` (header, `setSearchable`, `setSortable`, `setRawColumns`/`addRawColumn`, `setUrl`) and `prepareData(TableConfiguration $config)` (row array, typically `$this->runQuery($query, $config, true)`). Default Ajax URL is `table` -> controller needs `tableAction`; otherwise use `$config->setUrl(…)` (Tax: `listTableAction`). Render: `return ['table' => $table->render()]`, in Twig `{{ table | raw }}`; Ajax: `jsonResponse($table->fetchData())`. Button helpers: `generateCreateButton`, `generateEditButton`, `generateViewButton`, `generateRemoveButton($url, $title, $options, $formClassName = DeleteForm::class)`, `generateButtonGroup`. The action column must be a raw column, all others are escaped. New in 202610.0: adaptive "large table mode" and CSV download (threshold `GuiConfig::getLargeTableRowCountThreshold()`, code-verified, confirmed by the frontend reviewer). Existing tables are extended via expander plugins (e.g. `UserTableConfigExpanderPluginInterface`).

**Deleting** only via POST with CSRF confirmation form (Tax: `DeleteRateController::indexAction()` + `confirmAction()`); the old `deleteAction()` in `RateController` is `@deprecated`.

**Forms:** the form extends `Spryker\Zed\Kernel\Communication\Form\AbstractType`, data as Transfer (`data_class`). DataProvider = simple class with `getData(?int $id)` and `getOptions(): array` (reference `TaxRateFormDataProvider`). The Factory creates: `$this->getFormFactory()->create(Form::class, $transfer, $options)`. Controller: `handleRequest`, `isSubmitted() && isValid()`, Facade call, `createView()`. Twig: `form_start`, `form_row`/`form_widget`, submit `<input type="submit" class="btn btn-primary safe-submit" …>`, `form_end`. Active form plugins (`Pyz\Zed\Form\FormDependencyProvider`): `ValidatorFormPlugin`, `HttpFoundationFormPlugin`, `CsrfFormPlugin`, `NoValidateFormTypeExtensionFormPlugin`, `SanitizeXssTypeExtensionFormPlugin`, `MultiFactorAuthExtensionFormPlugin` -> CSRF active, XSS sanitization via type extension. Extending other modules' forms: expander plugins (`UserFormExpanderPluginInterface`).

**Navigation:** `navigation.xml` per module under `…/Zed/<Module>/Communication/` (`ZedNavigationConfig::getNavigationSchemaPathPattern()`), `navigation-secondary.xml` for the secondary navigation. Nodes: `label`, `title`, `bundle` (module kebab-case), `controller`, `action`, `uri` (`#` = group), `icon` (`fa-…`), `visible` (`0` = breadcrumb/permission only), nested in `<pages>`; max. 5 levels. **Merge strategy in Pyz = `BREADCRUMB_MERGE_STRATEGY`** (`Pyz\Zed\ZedNavigation\ZedNavigationConfig`). Code finding (`BreadcrumbNavigationMergeStrategy::mergeNavigation()`): the result is the structure of `config/Zed/navigation.xml`; from the module files only the `pages` of nodes are taken over that exist in the root under the **same key name and same `bundle`**. Consequence: the menu item (levels 1 and 2) must be registered in the root; subpages (create/edit, `visible 0`) come from the module XML. `[code-verified]` (not executed live). Navigation filters: `AclNavigationItemCollectionFilterPlugin` (menu follows the ACL), `BackofficeNavigationItemCollectionFilterPlugin`. Deprecated: `ZedNavigationConfig::getNavigationSchemaFileNamePattern()` -> `getNavigationSchemaFileNamePatterns()`; `getRootNavigationSchema()` -> `getRootNavigationSchemaPaths()`; `isAddingGlobalVariables()` BC only (Twig `navigation()`/`breadcrumb()`).

**Translations (Zed)** are CSV files (comma, line `"source text",translation`), not a glossary DB. Core: `vendor/spryker/<module>/data/translation/Zed/<locale>.csv`. **Project: `src/Pyz/Zed/Translator/data/<Name>/<locale>.csv`** (pattern `src/<Namespace>/Zed/Translator/data/*/xx_XX.csv`, folder name free); the top-level `data/translation/` is no longer read (README only). Merge `core` then `project` (project overrides `[Assumption: loader not checked separately]`). Fallback `TranslatorConstants::TRANSLATION_ZED_FALLBACK_LOCALES` (`['de_DE' => ['en_US']]`). Cache `src/Generated/Zed/Translator/…`; run `translator:generate-cache` after every CSV change. Usage: `{{ 'Text' | trans }}`, PHP messages and form labels automatically. Source text = key (English sentence), locales `en_US.csv`, `de_DE.csv`. Yves texts are a separate mechanism → [19](19-multi-store-i18n.md).

**ACL:** User <-> Group (n:m) <-> Role <-> Rule (`bundle`, `controller`, `action`, `type` `allow|deny`). Checked by `AccessControlEventDispatcherPlugin` on `KernelEvents::REQUEST` (registered in `Pyz\Zed\EventDispatcher\EventDispatcherDependencyProvider`); without permission redirect to `/acl/index/denied`. Evaluation (`RuleValidator`): **deny beats allow**, wildcard `*` (`AclConstants::VALIDATOR_WILDCARD`) on bundle/controller/action; no allow = forbidden. Rule keys = URL segments = `navigation.xml` values (kebab-case). Global rules in `config/Shared/config_default.php` (`ACL_DEFAULT_CREDENTIALS`, `ACL_DEFAULT_RULES`, `ACL_USER_RULE_WHITELIST`). The installer is idempotent and **only additive** (existing groups skipped, roles looked up by name, rules checked with `existsRoleRule()`); it runs via `AclInstallerPlugin` (in `Pyz\Zed\Installer\InstallerDependencyProvider`), i.e. during `setup:init-db`. Changing existing rules: Back Office (menu **Users** -> "User Roles"/"User Groups", `config/Zed/navigation.xml`) or your own migration. Custom roles/groups: `AclInstallerPluginInterface` (`getRoles()`, `getGroups()`), registered in `Pyz\Zed\Acl\AclDependencyProvider::getAclInstallerPlugins()` (does not exist in Pyz, create it). Further extension points: `AclRolesExpanderPluginInterface`, `AclRolePostSavePluginInterface`, `AclAccessCheckerStrategyPluginInterface`. New pages are invisible/blocked for non-root users until the rule is granted.

**Distinction from AclEntity:** this ACL controls access to URLs (bundle/controller/action) in the Back Office. Record-level access control on Propel entities ("AclEntity", segments/scopes per entity) is a separate feature and **not installed in the release**: `spryker/acl-entity` is missing from `vendor/`; only `spryker/acl-entity-extension` is present (the interfaces `AclEntityMetadataConfigExpanderPluginInterface`, `AclEntityDisablerPluginInterface`), and `src/Pyz` does not use AclEntity. Plans involving AclEntity (e.g. data visibility per merchant/segment) first require installing the module including feature integration `[Assumption: details not checked]`; Storefront permissions (company roles) are yet another mechanism ([20](20-b2b-permissions.md)).

**Assets:** `npm run zed`, `zed:watch`, `zed:production`, `zed:lint`, `zed:stylelint` or `frontend:zed:build` (`-e production`). Builder `vendor/spryker/gui/src/Spryker/Zed/Gui/FrontendBuilder/` finds `**/Zed/**/*.entry.js|ts` in core, features, Eco, SDK and `./src/Pyz/Zed`; output `public/Backoffice/assets`. Module assets `assets/Zed/js/<name>.entry.js` (+ `modules/`, `scss/`); the path in a Pyz module `src/Pyz/Zed/<Module>/assets/Zed/…` was not verified on a Pyz module `[Assumption]`. The builder is TypeScript (`.mts`); the docs on the Webpack build are outdated. General → [15](15-yves-frontend-atomic.md).

## Instructions

1. **Module:** create `Pyz\Zed\<Name>Gui` with `Communication/` (controller, Factory, table, form, `navigation.xml`) and `Presentation/` ([02](02-project-module.md)); data via the Facade of the business module, provide the table query via DependencyProvider as `PROPEL_QUERY_*` (pattern `ApiKeyGuiDependencyProvider`).
2. **Controller:** `listAction` + `tableAction` (table), `createAction` (form). Delete with confirmation form.
3. **Twig:** `@Gui/Layout/layout.twig`, `widget.twig`, `{{ table | raw }}`; texts with `| trans`.
4. **Navigation:** node in `config/Zed/navigation.xml` (e.g. under `<catalog><pages>`) and `pages` in the module `navigation.xml` (same key + `bundle`).
5. **Translation:** `src/Pyz/Zed/Translator/data/<Name>Gui/{de_DE,en_US}.csv`, menu labels in the same file or `ZedNavigation/*.csv`.
6. **ACL:** grant role/group via installer plugin or in the Back Office; plan a wildcard on the controller (Ajax action `table` is a separate action).
7. **Activate** (all commands `[live verified (202610.0)]`):

```bash
docker/sdk cli console transfer:generate        # only for new transfers
docker/sdk cli console navigation:build-cache   # menu (also: navigation:cache:remove)
docker/sdk cli console translator:generate-cache
docker/sdk cli console setup:init-db            # runs ACL installer plugins among others (idempotent)
docker/sdk cli 'npm run zed'                    # only with your own assets
```

## Examples

Files intended to be fully runnable (ProductBadgeGui: controller, table, form, DataProvider, Factory, DependencyProvider, Twig, `navigation.xml`, ACL plugin, CSV): [../templates/back-office-page/](../templates/back-office-page/README.md).

**Real (Pyz):** navigation filter `src/Pyz/Zed/ZedNavigation/ZedNavigationDependencyProvider.php`

```php
protected function getNavigationItemCollectionFilterPlugins(): array
{
    return [
        new AclNavigationItemCollectionFilterPlugin(),
        new BackofficeNavigationItemCollectionFilterPlugin(),
    ];
}
```

**Real (Pyz):** installer users `src/Pyz/Zed/Acl/AclConfig.php`

```php
public function getInstallerUsers(): array
{
    return [
        'admin@spryker.com' => ['group' => AclConstants::ROOT_GROUP],
        'admin_de@spryker.com' => ['group' => AclConstants::ROOT_GROUP],
    ];
}
```

**Real (core, reference structure):** `vendor/spryker/api-key-gui` (modern, controllers `List`/`Create`/`Edit`/`Delete`, `ApiKeyTable` with `setRawColumns`, `setTableIdentifier`, `setDefaultSortField`, DataProvider, Presentation per controller) and `vendor/spryker/tax` (`RateTable`, `TaxRateForm`, `TaxRateFormDataProvider`, delete confirmation).

**Real (core):** action column in `RateTable::createDeleteButton()`: `$this->generateRemoveButton(Url::generate('/tax/delete-rate', ['id-tax-rate' => $id]), 'Delete')`.

**Real (Pyz override, form+DataProvider):** `src/Pyz/Zed/CustomerAccessGui/Presentation/Index/index.twig` uses `{% extends '@Gui/Layout/layout.twig' %}`, `{% embed '@Gui/Partials/widget.twig' … %}`, `form_start(form)` … `<input type="submit" class="btn btn-primary safe-submit" value="{{ 'Save' | trans }}" />`.

## Pitfalls

1. **Menu item missing:** cache not rebuilt, node not in `config/Zed/navigation.xml` (Pyz merge strategy), `bundle`/`controller`/`action` != URL segments (kebab-case), or ACL rule missing (filter hides it).
2. **Redirect `/acl/index/denied`:** rule missing; deny beats allow; Ajax action `table` needs its own permission (wildcard on controller).
3. **Table shows HTML as text:** action column not raw. Conversely, no user input in raw columns without escaping (XSS).
4. **Table does not load:** `tableAction` missing or `setUrl()` points nowhere; header keys must match the keys from `prepareData`.
5. **Translation has no effect:** `translator:generate-cache` forgotten, wrong delimiter (must be `,`), file not named `xx_XX.csv`, file in the old `data/translation/`.
6. **Deleting via GET:** never; confirmation page + POST with CSRF form.
7. **Template not found:** controller name != Presentation folder (`RateController` -> `Presentation/Rate/`), `listAction` -> `list.twig`.
8. **ACL installer does not change existing data:** only additive; changes on running systems via Back Office/migration.
9. Default password `change123` in `Pyz\Zed\User\UserConfig` for dev only.
10. Copying core `navigation.xml`, copying core controllers/templates entirely where a plugin point exists -> upgrade risk ([28](28-upgradability.md)).
11. Keep controllers thin: logic in the Facade; a query directly in the table is a common GUI compromise, otherwise data via Repository/Facade ([01](01-architecture.md)).

## Checklist

- [ ] Controller thin, `@method` docblocks, `declare(strict_types = 1)`
- [ ] Table: raw column, `tableAction`, search/sorting set
- [ ] Form via Factory + DataProvider, CSRF active (default), delete via POST
- [ ] Node in `config/Zed/navigation.xml` and module `navigation.xml`, `navigation:build-cache`
- [ ] CSV in `src/Pyz/Zed/Translator/data/<Name>/` for `de_DE` and `en_US`, `translator:generate-cache`
- [ ] ACL role/rule (`bundle`/`*`/`*`) granted (URL ACL; AclEntity is not installed), menu checked with a non-root user
- [ ] All texts with `| trans`; no edits in `vendor/`

## Sources

Docs (base `https://docs.spryker.com/docs/`, starting points only): `dg/dev/backend-development/zed/zed.html`, `…/zed/communication-layer/communication-layer.html`, `…/zed-ui-tables/create-and-configure-zed-tables.html`, `…/zed-ui-tables/adding-buttons-to-zed-tables.html`, `…/forms/create-forms.html`, `…/zed/add-navigation-in-the-back-office.html` (as of 2021), `pbc/all/back-office/latest/base-shop/back-office-translations-overview.html`, `pbc/all/user-management/latest/base-shop/user-and-rights-overview.html`.

Code: `vendor/spryker/gui/src/Spryker/Zed/Gui/Communication/Table/AbstractTable.php`, `…/Presentation/{Layout,Partials}/*`, `vendor/spryker/tax/src/Spryker/Zed/Tax/Communication/**`, `vendor/spryker/api-key-gui/src/Spryker/Zed/ApiKeyGui/**`, `vendor/spryker/zed-navigation/src/Spryker/Zed/ZedNavigation/{ZedNavigationConfig.php,Business/Strategy/BreadcrumbNavigationMergeStrategy.php}`, `vendor/spryker/translator/src/Spryker/Zed/Translator/TranslatorConfig.php`, `vendor/spryker/acl/src/Spryker/Zed/Acl/{AclDependencyProvider.php,Business/Model/{Installer,RuleValidator}.php}`, `vendor/spryker/acl-extension/…/AclInstallerPluginInterface.php`, `vendor/spryker/kernel/src/Spryker/Zed/Kernel/Communication/Controller/AbstractController.php`, `config/Zed/navigation.xml`, `config/install/docker.yml`, `src/Pyz/Zed/{Acl,ZedNavigation,Form,Gui,CustomerAccessGui,Translator}/…`.

Neighbors: [02 Project module](02-project-module.md), [03 Extending the core](03-extending-the-core.md), [05 Propel](05-propel-persistence.md), [20 B2B permissions](20-b2b-permissions.md), [21 Security](21-security-session.md), [19 i18n](19-multi-store-i18n.md).
