# B2B permissions in the Storefront: permissions, company roles, Customer Access

> Status: Release 202610.0 (`spryker/permission` 1.9.0, `company-role` 1.15.1, `customer-access` 1.5.0, `oauth-permission` 1.7.0, `company-user` 2.24.0, `spryker-shop/shop-permission` 1.3.0, `company-page` 2.38.1). Source code read, DB/console statements verified live on the container (DB `eu-docker`). `[code-verified, docs not cross-checked]`: docs pages only returned navigation. The custom example permission (`ExportInvoicePermissionPlugin`) is new, not executed `[Assumption]`. Corrected during the authoring run: role keys in the CSVs are named `<Company>_<Role>` (e.g. `Ottom_Buyer`), not `Spryker_Buyer`; `getBuyerRolePermissionKeys()` is `protected` in Pyz. Console commands (`data:import` of the role/permission importers, `publish:trigger-events -r unauthenticated_customer_access`, `queue:worker:start`) executed `[live verified (202610.0)]`. Back Office ACL (Zed user) → [18](18-back-office.md); login/session → [21](21-security-session.md); OAuth/Glue authorization → [13](13-glue-backend.md).

## Purpose

Storefront permissions in B2B: permission plugins, company roles with configurable permissions, Customer Access for visitors who are not logged in, OAuth connection for Glue. Introducing and checking custom permissions.

## Concepts

**Interfaces** (`vendor/spryker/permission-extension/src/Spryker/Shared/PermissionExtension/Dependency/Plugin/`):

| Interface | Methods | Meaning |
|---|---|---|
| `PermissionPluginInterface` | `getKey(): string` | Yes/no permission; present in the role = allowed |
| `ExecutablePermissionPluginInterface` | `can(array $configuration, $context = null)`, `getConfigurationSignature()` | Permission with configuration (types `CONFIG_FIELD_TYPE_INT`, `_STRING`, `_STORE_MULTI_CURRENCY`) |
| `InfrastructuralPermissionPluginInterface` | Marker | technical permission, not in the company role UI (Customer Access permissions `See*PermissionPlugin`) |

**Registration** in every checking layer (Client **and** Zed): `Pyz\Client\Permission\PermissionDependencyProvider` and `Pyz\Zed\Permission\PermissionDependencyProvider` (each `getPermissionPlugins()` + `getPermissionStoragePlugins()`); Yves Twig `can()` via `Pyz\Yves\ShopPermission\ShopPermissionDependencyProvider::getPermissionTwigExtensionPlugins()` (`PermissionTwigExtensionPlugin`).

**Checking:**
- Yves/Glue/Client: `PermissionClient::can($permissionKey, $context = null)`; trait `Spryker\Yves\Kernel\PermissionAwareTrait` (or the Client/Glue variant) with `protected function can($permissionKey, $context = null)`.
- Zed: `PermissionFacade::can($permissionKey, $identifier, $context = null)` (`$identifier` = `idCompanyUser`); trait `Spryker\Zed\Kernel\PermissionAwareTrait::can($permissionKey, $identifier, $context = null)`.
- Twig: `{% if can('SeePricePermissionPlugin') %}`, with context `can('WriteSharedCartPermissionPlugin', data.cart.idQuote)`.

**`PermissionExecutor::can()`** (same in Client and Zed): (1) **no registered plugin for this key -> `true`**; (2) plugin present, but the subject does not have the permission -> `false`; (3) plugin not executable -> `true`; (4) executable: for each permission (each role) `can($configuration, $context)`, **one approval is enough** (OR across roles: Junior up to 1000 + Senior up to 2000 = up to 2000). Client caches per request.

**Storage plugins** provide the subject's permissions: Client `CompanyRole\Plugin\PermissionStoragePlugin` (from `CustomerTransfer::getPermissions()` of the session), `CustomerAccessPermissionStoragePlugin`, `OauthPermission\…\StoredOauthPermissionStoragePlugin`; Zed `CompanyRole\Communication\Plugin\PermissionStoragePlugin` (+ SharedCart, ShoppingList).

**Data model:**

```
spy_company ─1:n─ spy_company_business_unit (hierarchy via fk_parent_company_business_unit)
     ├─1:n─ spy_company_user (fk_company, fk_customer, optional fk_company_business_unit)
     └─1:n─ spy_company_role
              ├ n:m spy_company_role_to_company_user
              └ n:m spy_company_role_to_permission (configuration JSON NOT NULL)
spy_permission (key UNIQUE, configuration_signature)
```

Permissions are attached to the **Company Role**; effective permission = union of all roles of the company user. The configuration lives on `spy_company_role_to_permission`, not on `spy_permission`. Permissions do **not** run through business units (exception: individual permissions such as `SeeBusinessUnitOrdersPermissionPlugin`). Default roles per new company: `CompanyRoleCreatePlugin` (in `Pyz\Zed\Company\CompanyDependencyProvider::getCompanyPostCreatePlugins()`) -> `CompanyRoleConfig::getPredefinedCompanyRoles()`.

**Permissions into the session:** Zed expands the `CustomerTransfer` (`Pyz\Zed\Customer\CustomerDependencyProvider::getCustomerTransferExpanderPlugins()`: `CustomerTransferCompanyUserExpanderPlugin`, `PermissionCustomerExpanderPlugin`, `QuotePermissionCustomerExpanderPlugin`, `ShoppingListPermissionCustomerExpanderPlugin`); afterwards the Client only reads the session. Role change: `CompanyRolePostSavePlugins` (`CustomerInvalidationCompanyRolePostSavePlugin` -> session is reloaded; `OauthPermissionUpdateCompanyRolePostSavePlugin`).

**New permission in `spy_permission`:** via `PermissionFacade::syncPermissionPlugins()` (merges Client and Zed plugins). Triggers: Back Office Maintenance -> "Sync permissions"; implicitly via `QuotePermissionGroupInstaller` (SharedCart) and `ShoppingListPermissionInstaller`, both in `Pyz\Zed\Installer\InstallerDependencyProvider` -> `setup:init-db` also runs the sync. There is **no dedicated console command** and no dedicated permission installer plugin; whoever removes these installers gets no new rows `[Assumption/risk]`. The import `company-role-permission` requires `spy_permission` (`EntityNotFoundException('Could not find permission by key "…"')`). Live: 30 rows.

**Customer Access** (what visitors who are not logged in see): modules `customer-access` (Zed), `-storage`, `-permission` (Client), `-gui`, `-rest-api`. Table `spy_unauthenticated_customer_access` (`content_type`, `is_restricted`); live 5 rows `price`, `order-place-submit`, `add-to-cart`, `wishlist`, `shopping-list`, all `is_restricted = 1`. Published into `spy_unauthenticated_customer_access_storage`. Content types in `Spryker\Shared\CustomerAccess\CustomerAccessConfig` (`CONTENT_TYPE_PRICE`, `_ORDER_PLACE_SUBMIT`, `_ADD_TO_CART`, `_WISHLIST`, `_SHOPPING_LIST`). Default on install: `Pyz\Zed\CustomerAccess\CustomerAccessConfig::getContentAccessByType()` (`false` = not granted -> `is_restricted = 1`). `CustomerAccessInstaller::install()` **skips existing types** ("You need to clean up table spy_unauthenticated_customer_access to get %s access config update.").
Effect: `CustomerAccessStorageReader::getUnauthenticatedCustomerAccess()` returns only granted types; `CustomerAccessPermissionStoragePlugin` maps them via `CustomerAccessPermissionConfig::CONTENT_TYPE_PERMISSION_PLUGIN` to `SeePricePermissionPlugin`, `SeeAddToCartPermissionPlugin`, `SeeOrderPlaceSubmitPermissionPlugin`, `SeeWishlistPermissionPlugin`, `SeeShoppingListPermissionPlugin`; logged-in customers receive all of them. Routes: `CustomerAccessSecuredPatternRulePlugin` (in `Pyz\Client\Customer\CustomerDependencyProvider::getCustomerSecuredPatternRulePlugins()`) removes granted patterns from `CUSTOMER_SECURED_PATTERN` (`config/Shared/config_default.php`). Glue: `Pyz\Glue\CustomerAccessRestApi\CustomerAccessRestApiConfig` maps types to resources.

**Pyz custom build (not a core feature):** `Pyz\Zed\CustomerAccess\CustomerAccessConfig::getManageableContentTypes()` (price, wishlist, shopping-list) does not exist in the core; Pyz Facade/Factory/Updater/EntityManager and the `CustomerAccessGui` form/DataProvider/template override the core module (non-manageable types `order-place-submit`, `add-to-cart` stay unchanged). Functional, but upgrade-sensitive (constructor signatures, `phpcs:disable`). P&S: `CustomerAccessPublisherTriggerPlugin`, `CustomerAccessSynchronizationDataBulkPlugin`, `CustomerAccessStorageEventSubscriber`, queue `sync.storage.customer_access` ([07](07-publish-sync.md)).

**OAuth permission (Glue):** permissions are kept in storage (Redis), not in the token: `StoredPermissionOauthCustomerIdentifierExpanderPlugin`, `StoredPermissionOauthCompanyUserIdentifierExpanderPlugin`, Client `StoredOauthPermissionStoragePlugin`; TTL `OauthPermissionConfig::getStoredPermissionTTL()` = 28800 s (>= token TTL). Pyz: `OauthPermissionConfig::getOauthUserIdentifierFilterKeys()` -> `[CustomerIdentifierTransfer::PERMISSIONS]`; Transfer extension `src/Pyz/Shared/OauthPermission/Transfer/oauth_permission.transfer.xml`. A custom permission needs **no** OAuth-specific registration. OAuth scopes (`oauth:scope-collection-file:generate`) are separate from permissions ([13](13-glue-backend.md)); scopes control endpoint access, permissions the business authorization `[Assumption]`.

**Default roles** (`Pyz\Zed\CompanyRole\CompanyRoleConfig`): Administrator (`AddCompanyUser`, `ManageCompanyUserInvitation`, `CompanyUserStatusChange`, `SeeCompanyMenu`; `isDefault`), Buyer (`AddCartItem`, `ChangeCartItem`, `RemoveCartItem`, `PlaceOrderWithAmountUpTo`), Approver (`ApproveQuote`), Buyer With Limit (Buyer + `RequestQuoteApproval`, `PlaceOrder`). Demo data: `data/import/common/common/{company_role,company_role_permission,company_user_role}.csv` (role key `<Company>_<Role>`, e.g. `Ottom_Buyer`), glossary `permission.name.<Key>`.

## Instructions

**Introducing a custom permission** (template below):
1. Plugin in the **Shared** layer (needed by Client and Zed); simple: `PermissionPluginInterface`; with configuration: `ExecutablePermissionPluginInterface` (the signature renders the role UI).
2. Register in `Pyz\Client\Permission\PermissionDependencyProvider::getPermissionPlugins()` **and** `Pyz\Zed\Permission\PermissionDependencyProvider::getPermissionPlugins()`.
3. Assign to a role: new companies via `CompanyRoleConfig` (`getBuyerRolePermissionKeys()` etc., only takes effect when a company is newly created), existing data via `company_role_permission.csv` (`Ottom_Buyer,ExportInvoicePermissionPlugin,`) or in Yves under Company -> Roles.
4. Glossary `permission.name.<Key>` per locale in `glossary.csv` (`CompanyRolePermissionsHandler::PERMISSION_KEY_GLOSSARY_PREFIX`), otherwise the roles page shows the raw key.
5. **Sync** before import: Back Office "Sync permissions" or `docker/sdk cli console setup:init-db`.
6. Import and publish:

```bash
docker/sdk cli console data:import company-role            # alias data:import:company-role-permission does NOT exist (live: "Command not defined")
docker/sdk cli console data:import company-role-permission
docker/sdk cli console data:import company-user-role       # importer name confirmed from CompanyRoleDataImportConfig
docker/sdk cli console data:import:dump                    # lists registered importers
docker/sdk cli console publish:trigger-events -r unauthenticated_customer_access   # republish Customer Access
docker/sdk cli console queue:worker:start --stop-when-empty                        # [live verified (202610.0)]
```

7. Check: Twig `{% if can('ExportInvoicePermissionPlugin') %}` or controller with `PermissionAwareTrait`.

**Changing Customer Access:** maintain it in the Back Office (Customers -> Customer Access); a config change only takes effect for new types or after clearing the table; afterwards `publish:trigger-events -r unauthenticated_customer_access`. Non-manageable types are only reachable via database/config.

**Checking state** (container `spryker_b2b_dev_database_1`): `docker exec spryker_b2b_dev_database_1 mysql -uroot -psecret eu-docker -e "select id_permission,\`key\` from spy_permission; select * from spy_unauthenticated_customer_access;"`. On macOS, Docker lives under `/Applications/Docker.app/Contents/Resources/bin`.

## Examples

New permission (not in the release; pattern based on `PlaceOrderWithAmountUpToPermissionPlugin` and `SeeCompanyMenuPermissionPlugin`), `src/Pyz/Shared/InvoiceExport/Plugin/Permission/ExportInvoicePermissionPlugin.php`:

```php
<?php

declare(strict_types = 1);

namespace Pyz\Shared\InvoiceExport\Plugin\Permission;

use Spryker\Shared\PermissionExtension\Dependency\Plugin\PermissionPluginInterface;

class ExportInvoicePermissionPlugin implements PermissionPluginInterface
{
    public const KEY = 'ExportInvoicePermissionPlugin';

    public function getKey(): string
    {
        return static::KEY;
    }
}
```

Check in the controller (pattern `CompanyRoleController`): `use \Spryker\Yves\Kernel\PermissionAwareTrait;` and `if (!$this->can(ExportInvoicePermissionPlugin::KEY)) { throw new AccessDeniedHttpException(); }`.

**Executable (real, shortened):** `vendor/spryker/checkout/src/Spryker/Shared/Checkout/Plugin/Permission/PlaceOrderWithAmountUpToPermissionPlugin.php`

```php
class PlaceOrderWithAmountUpToPermissionPlugin implements ExecutablePermissionPluginInterface
{
    public const KEY = 'PlaceOrderWithAmountUpToPermissionPlugin';
    protected const FIELD_CENT_AMOUNT = 'cent_amount';

    public function can(array $configuration, $context = null): bool
    {
        if ($context === null) { return false; }
        if (!isset($configuration[static::FIELD_CENT_AMOUNT])) { return true; }
        if (!is_array($context) && (int)$configuration[static::FIELD_CENT_AMOUNT] <= (int)$context) { return false; }
        return true;
    }

    public function getConfigurationSignature(): array
    {
        return [static::FIELD_CENT_AMOUNT => static::CONFIG_FIELD_TYPE_INT];
    }

    public function getKey(): string { return static::KEY; }
}
```

**Real (Pyz):** `src/Pyz/Zed/CompanyUser/Communication/Plugin/Permission/SeeCompanyMenuPermissionPlugin.php` (constant `KEY = 'SeeCompanyMenuPermissionPlugin'`, `getKey()`), registered in both `PermissionDependencyProvider`s, admin role in `getAdminRolePermissionKeys()`, used as `{% if can('SeeCompanyMenuPermissionPlugin') %}` in the Pyz `header.twig`. Oddity: namespace `Pyz\Zed\…`, base class `Spryker\Client\Kernel\AbstractPlugin`, registration in the Client (layer mixing, do not copy; permission plugins without dependencies need no base class).

**Real (Pyz, excerpt):** `Pyz\Zed\CustomerAccess\CustomerAccessConfig::getContentAccessByType()` returns `false` for each of `CONTENT_TYPE_PRICE`, `_ORDER_PLACE_SUBMIT`, `_ADD_TO_CART`, `_WISHLIST`, `_SHOPPING_LIST`.

**Live finding:** `PlaceOrderPermissionPlugin` of the role "Buyer With Limit" has `{"store_multi_currency":{"DE":{"EUR":70000,"CHF":80000}}}`; `PlaceOrderWithAmountUpToPermissionPlugin` signature `{"cent_amount":"CONFIG_FIELD_TYPE_INT"}`.

## Pitfalls

1. **Unregistered permission = allowed.** A typo in the key or a missing registration in *one* layer opens up the permission. Finding in the project: `src/Pyz/Yves/ProductSetWidget/Theme/default/components/organisms/{product-set-cms,product-set-details}/*.twig` uses `can('ROLE_USER')`; `ROLE_USER` is not a permission key -> always `true`; correct is `is_granted('ROLE_USER')`.
2. Register in Client **and** Zed; Zed `can()` only knows Zed plugins, the sync collects both.
3. Permission missing in `spy_permission` -> import throws `Could not find permission by key`; sync before import.
4. New permissions do not reach existing companies (`getPredefinedCompanyRoles()` only on company creation); existing data via CSV/migration/Yves roles.
5. Glossary key `permission.name.<Key>` missing -> raw key on the roles page.
6. Stale session: role changes via `CompanyRoleFacade::updateCompanyRole()` (triggers PostSave), not via a direct Propel write.
7. Do not maintain infrastructural permissions in roles (derived from Customer Access).
8. Customer Access config has no effect after install (existing types skipped); republish afterwards.
9. `CUSTOMER_SECURED_PATTERN` must contain routes that can be granted via Customer Access; type->pattern lives in `CustomerAccessPermissionConfig::CONTENT_TYPE_PERMISSION_ACCESS` `[Assumption: can be overridden via a custom Client config]`.
10. Use executable only with configuration/context (otherwise an empty form in the role UI).
11. Extensions only via Pyz DependencyProvider/plugins/config inheritance ([28](28-upgradability.md)); the Customer Access override breaks when the core changes constructor signatures.

**Deprecated (checked in code):** `CompanyRoleFacadeInterface::create()` -> `createCompanyRole()`, `update()` -> `updateCompanyRole()`; Client `OauthPermission\…\OauthPermissionStoragePlugin` -> `StoredOauthPermissionStoragePlugin`; `PermissionOauthCustomerIdentifierExpanderPlugin` (and analogously `…CompanyUser…` `[Assumption for the second name]`) as well as `CustomerIdentifierExpander` -> `Stored…` plugins (Pyz already uses these); `CustomerAccessPermissionClient` secured-pattern method refers to `CustomerSecuredPatternPermissionPlugin`, **this class does not exist in 202610.0** (the actual replacement is `CustomerAccessSecuredPatternRulePlugin`); `CustomerAccessPermissionConfig::getCustomerSecuredPattern()` (moved to the Customer module); `CustomerAccessSynchronizationDataPlugin`/`CustomerAccessStorageListener` -> `…BulkPlugin`/`…BulkListener` (Pyz uses bulk); `CustomerAccessStorageConfig` sync flag -> `SynchronizationBehaviorConfig::isSynchronizationEnabled()`.

## Checklist

- [ ] Plugin in Shared, registered in Client **and** Zed `PermissionDependencyProvider`
- [ ] Key in role defaults (`CompanyRoleConfig`) and/or CSV; glossary `permission.name.<Key>` per locale
- [ ] Permission sync has run (Back Office or `setup:init-db`), then import
- [ ] Check in Twig/controller (`can()`), key via constant, no typo risk
- [ ] Existing data migrated (CSV/Yves), new companies via `getPredefinedCompanyRoles()`
- [ ] Customer Access: table/config consistent, republish executed
- [ ] No layer mixing (no `Spryker\Client\Kernel\AbstractPlugin` in Zed/Shared)

## Sources

Docs (fetch returned only navigation, cross-check pending): `https://docs.spryker.com/docs/pbc/all/customer-relationship-management/latest/base-shop/company-account-feature-overview/company-user-roles-and-permissions-overview.html`, `…/base-shop/customer-access-feature-overview.html`.

Code: `vendor/spryker/permission-extension/…/PermissionExtension/Dependency/Plugin/*`, `vendor/spryker/permission/src/Spryker/{Client,Zed}/Permission/**`, `vendor/spryker/kernel/src/Spryker/{Yves,Client,Glue,Zed}/Kernel/PermissionAwareTrait.php`, `vendor/spryker/company-role/src/Spryker/{Zed,Client}/CompanyRole/**`, `vendor/spryker/company-role-data-import/…/CompanyRoleDataImportConfig.php`, `vendor/spryker/customer-access{,-storage,-permission,-gui,-rest-api}/**`, `vendor/spryker/oauth-permission/**`, `vendor/spryker/checkout/…/PlaceOrderWithAmountUpToPermissionPlugin.php`, `vendor/spryker-shop/shop-permission/…/PermissionTwigExtensionPlugin.php`, `vendor/spryker-shop/company-page/…/CompanyRoleController.php`, `src/Pyz/{Client/Permission,Zed/Permission,Yves/ShopPermission,Zed/CompanyRole,Zed/CompanyUser,Zed/CustomerAccess,Zed/CustomerAccessGui,Zed/OauthPermission,Glue/CustomerAccessRestApi,Shared/OauthPermission}/…`, `config/Shared/config_default.php`, `data/import/common/common/{company_role,company_role_permission,company_user_role,glossary}.csv`.

Neighbours: [07 Publish & Sync](07-publish-sync.md), [08 Storage](08-client-storage-search.md), [09 Data Import](09-data-import.md), [13 Glue Backend](13-glue-backend.md), [18 Back Office (ACL)](18-back-office.md), [19 Glossary/i18n](19-multi-store-i18n.md), [21 Security](21-security-session.md).
