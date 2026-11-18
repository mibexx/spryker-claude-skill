# B2B-Rechte im Storefront: Permissions, Company-Rollen, Customer Access (Recherche T-20)

> Status: Release 202610.0 (`spryker/permission` 1.9.0, `company-role` 1.15.1, `customer-access` 1.5.0, `oauth-permission` 1.7.0, `company-user` 2.24.0, `spryker-shop/shop-permission` 1.3.0, `company-page` 2.38.1). Quellcode gelesen, DB-/Konsolenaussagen in der Recherche am Container verifiziert (DB `eu-docker`). `[code-verifiziert, Doku nicht abgeglichen]`: Doku-Seiten lieferten nur Navigation. Das eigene Beispielrecht (`ExportInvoicePermissionPlugin`) ist neu, nicht ausgeführt `[Annahme]`. Beim Autorenlauf korrigiert: Rollen-Keys in den CSVs heißen `<Firma>_<Rolle>` (z. B. `Ottom_Buyer`), nicht `Spryker_Buyer`; `getBuyerRolePermissionKeys()` ist in Pyz `protected`. Konsolenbefehle (`data:import` der Rollen-/Rechte-Importer, `publish:trigger-events -r unauthenticated_customer_access`, `queue:worker:start`) ausgeführt `[live verifiziert (202610.0)]`. Back-Office-ACL (Zed-User) → [18](18-back-office.md); Login/Session → [21](21-security-session.md); OAuth/Glue-Autorisierung → [13](13-glue-backend.md).

## Zweck

Storefront-Berechtigungen im B2B: Permission-Plugins, Company-Rollen mit konfigurierbaren Rechten, Customer Access für nicht angemeldete Besucher, OAuth-Anbindung für Glue. Eigene Rechte einführen und prüfen.

## Konzepte

**Interfaces** (`vendor/spryker/permission-extension/src/Spryker/Shared/PermissionExtension/Dependency/Plugin/`):

| Interface | Methoden | Bedeutung |
|---|---|---|
| `PermissionPluginInterface` | `getKey(): string` | Ja/Nein-Recht; in der Rolle vorhanden = erlaubt |
| `ExecutablePermissionPluginInterface` | `can(array $configuration, $context = null)`, `getConfigurationSignature()` | Recht mit Konfiguration (Typen `CONFIG_FIELD_TYPE_INT`, `_STRING`, `_STORE_MULTI_CURRENCY`) |
| `InfrastructuralPermissionPluginInterface` | Marker | technisches Recht, nicht in der Company-Rollen-UI (Customer-Access-Rechte `See*PermissionPlugin`) |

**Registrierung** in jeder prüfenden Schicht (Client **und** Zed): `Pyz\Client\Permission\PermissionDependencyProvider` und `Pyz\Zed\Permission\PermissionDependencyProvider` (je `getPermissionPlugins()` + `getPermissionStoragePlugins()`); Yves-Twig `can()` über `Pyz\Yves\ShopPermission\ShopPermissionDependencyProvider::getPermissionTwigExtensionPlugins()` (`PermissionTwigExtensionPlugin`).

**Prüfung:**
- Yves/Glue/Client: `PermissionClient::can($permissionKey, $context = null)`; Trait `Spryker\Yves\Kernel\PermissionAwareTrait` (bzw. Client/Glue-Variante) mit `protected function can($permissionKey, $context = null)`.
- Zed: `PermissionFacade::can($permissionKey, $identifier, $context = null)` (`$identifier` = `idCompanyUser`); Trait `Spryker\Zed\Kernel\PermissionAwareTrait::can($permissionKey, $identifier, $context = null)`.
- Twig: `{% if can('SeePricePermissionPlugin') %}`, mit Context `can('WriteSharedCartPermissionPlugin', data.cart.idQuote)`.

**`PermissionExecutor::can()`** (Client und Zed gleich): (1) **kein registriertes Plugin zu diesem Key -> `true`**; (2) Plugin da, aber Subjekt hat die Permission nicht -> `false`; (3) Plugin nicht executable -> `true`; (4) executable: für jede Permission (jede Rolle) `can($configuration, $context)`, **eine Zusage genügt** (ODER über Rollen: Junior bis 1000 + Senior bis 2000 = bis 2000). Client cacht pro Request.

**Storage-Plugins** liefern die Permissions des Subjekts: Client `CompanyRole\Plugin\PermissionStoragePlugin` (aus `CustomerTransfer::getPermissions()` der Session), `CustomerAccessPermissionStoragePlugin`, `OauthPermission\…\StoredOauthPermissionStoragePlugin`; Zed `CompanyRole\Communication\Plugin\PermissionStoragePlugin` (+ SharedCart, ShoppingList).

**Datenmodell:**

```
spy_company ─1:n─ spy_company_business_unit (Hierarchie über fk_parent_company_business_unit)
     ├─1:n─ spy_company_user (fk_company, fk_customer, optional fk_company_business_unit)
     └─1:n─ spy_company_role
              ├ n:m spy_company_role_to_company_user
              └ n:m spy_company_role_to_permission (configuration JSON NOT NULL)
spy_permission (key UNIQUE, configuration_signature)
```

Rechte hängen an der **Company Role**; effektives Recht = Vereinigung aller Rollen des Company Users. Die Konfiguration liegt an `spy_company_role_to_permission`, nicht an `spy_permission`. Rechte laufen **nicht** über Business Units (Ausnahme einzelne Permissions wie `SeeBusinessUnitOrdersPermissionPlugin`). Standardrollen pro neuer Firma: `CompanyRoleCreatePlugin` (in `Pyz\Zed\Company\CompanyDependencyProvider::getCompanyPostCreatePlugins()`) -> `CompanyRoleConfig::getPredefinedCompanyRoles()`.

**Permissions in die Session:** Zed expandiert den `CustomerTransfer` (`Pyz\Zed\Customer\CustomerDependencyProvider::getCustomerTransferExpanderPlugins()`: `CustomerTransferCompanyUserExpanderPlugin`, `PermissionCustomerExpanderPlugin`, `QuotePermissionCustomerExpanderPlugin`, `ShoppingListPermissionCustomerExpanderPlugin`); Client liest danach nur Session. Rollenänderung: `CompanyRolePostSavePlugins` (`CustomerInvalidationCompanyRolePostSavePlugin` -> Session wird neu geladen; `OauthPermissionUpdateCompanyRolePostSavePlugin`).

**Neues Recht in `spy_permission`:** per `PermissionFacade::syncPermissionPlugins()` (vereinigt Client- und Zed-Plugins). Auslöser: Back Office Maintenance -> "Sync permissions"; implizit durch `QuotePermissionGroupInstaller` (SharedCart) und `ShoppingListPermissionInstaller`, beide in `Pyz\Zed\Installer\InstallerDependencyProvider` -> `setup:init-db` ruft Sync mit auf. Es gibt **keinen eigenen Console-Befehl** und kein eigenes Permission-Installer-Plugin; wer diese Installer entfernt, bekommt keine neuen Rows `[Annahme/Risiko]`. Der Import `company-role-permission` setzt `spy_permission` voraus (`EntityNotFoundException('Could not find permission by key "…"')`). Live: 30 Rows.

**Customer Access** (was nicht angemeldete Besucher sehen): Module `customer-access` (Zed), `-storage`, `-permission` (Client), `-gui`, `-rest-api`. Tabelle `spy_unauthenticated_customer_access` (`content_type`, `is_restricted`); live 5 Zeilen `price`, `order-place-submit`, `add-to-cart`, `wishlist`, `shopping-list`, alle `is_restricted = 1`. Publiziert in `spy_unauthenticated_customer_access_storage`. Content-Typen in `Spryker\Shared\CustomerAccess\CustomerAccessConfig` (`CONTENT_TYPE_PRICE`, `_ORDER_PLACE_SUBMIT`, `_ADD_TO_CART`, `_WISHLIST`, `_SHOPPING_LIST`). Default beim Install: `Pyz\Zed\CustomerAccess\CustomerAccessConfig::getContentAccessByType()` (`false` = nicht gewährt -> `is_restricted = 1`). `CustomerAccessInstaller::install()` **überspringt vorhandene Typen** ("You need to clean up table spy_unauthenticated_customer_access to get %s access config update.").
Wirkung: `CustomerAccessStorageReader::getUnauthenticatedCustomerAccess()` liefert nur freigegebene Typen; `CustomerAccessPermissionStoragePlugin` mappt sie über `CustomerAccessPermissionConfig::CONTENT_TYPE_PERMISSION_PLUGIN` auf `SeePricePermissionPlugin`, `SeeAddToCartPermissionPlugin`, `SeeOrderPlaceSubmitPermissionPlugin`, `SeeWishlistPermissionPlugin`, `SeeShoppingListPermissionPlugin`; eingeloggte Kunden erhalten alle. Routen: `CustomerAccessSecuredPatternRulePlugin` (in `Pyz\Client\Customer\CustomerDependencyProvider::getCustomerSecuredPatternRulePlugins()`) entfernt freigegebene Muster aus `CUSTOMER_SECURED_PATTERN` (`config/Shared/config_default.php`). Glue: `Pyz\Glue\CustomerAccessRestApi\CustomerAccessRestApiConfig` mappt Typen auf Ressourcen.

**Pyz-Eigenbau (keine Core-Funktion):** `Pyz\Zed\CustomerAccess\CustomerAccessConfig::getManageableContentTypes()` (price, wishlist, shopping-list) existiert nicht im Core; Pyz-Facade/-Factory/-Updater/-EntityManager und `CustomerAccessGui`-Form/DataProvider/Template überschreiben das Core-Modul (nicht verwaltbare Typen `order-place-submit`, `add-to-cart` bleiben unverändert). Funktional, aber upgrade-sensibel (Konstruktorsignaturen, `phpcs:disable`). P&S: `CustomerAccessPublisherTriggerPlugin`, `CustomerAccessSynchronizationDataBulkPlugin`, `CustomerAccessStorageEventSubscriber`, Queue `sync.storage.customer_access` ([07](07-publish-sync.md)).

**OAuth-Permission (Glue):** Permissions werden im Storage (Redis) gehalten, nicht im Token: `StoredPermissionOauthCustomerIdentifierExpanderPlugin`, `StoredPermissionOauthCompanyUserIdentifierExpanderPlugin`, Client `StoredOauthPermissionStoragePlugin`; TTL `OauthPermissionConfig::getStoredPermissionTTL()` = 28800 s (>= Token-TTL). Pyz: `OauthPermissionConfig::getOauthUserIdentifierFilterKeys()` -> `[CustomerIdentifierTransfer::PERMISSIONS]`; Transfer-Erweiterung `src/Pyz/Shared/OauthPermission/Transfer/oauth_permission.transfer.xml`. Ein eigenes Recht braucht **keine** OAuth-spezifische Registrierung. OAuth-Scopes (`oauth:scope-collection-file:generate`) sind getrennt von Permissions ([13](13-glue-backend.md)); Scopes steuern Endpunktzugriff, Permissions die fachliche Berechtigung `[Annahme]`.

**Standardrollen** (`Pyz\Zed\CompanyRole\CompanyRoleConfig`): Administrator (`AddCompanyUser`, `ManageCompanyUserInvitation`, `CompanyUserStatusChange`, `SeeCompanyMenu`; `isDefault`), Buyer (`AddCartItem`, `ChangeCartItem`, `RemoveCartItem`, `PlaceOrderWithAmountUpTo`), Approver (`ApproveQuote`), Buyer With Limit (Buyer + `RequestQuoteApproval`, `PlaceOrder`). Demodaten: `data/import/common/common/{company_role,company_role_permission,company_user_role}.csv` (Rollen-Key `<Firma>_<Rolle>`, z. B. `Ottom_Buyer`), Glossar `permission.name.<Key>`.

## Anleitung

**Eigenes Recht einführen** (Vorlage unten):
1. Plugin in der **Shared**-Schicht (wird von Client und Zed gebraucht); einfach: `PermissionPluginInterface`; mit Konfiguration: `ExecutablePermissionPluginInterface` (Signatur zeichnet die Rollen-UI).
2. Registrieren in `Pyz\Client\Permission\PermissionDependencyProvider::getPermissionPlugins()` **und** `Pyz\Zed\Permission\PermissionDependencyProvider::getPermissionPlugins()`.
3. Rolle zuordnen: neue Firmen über `CompanyRoleConfig` (`getBuyerRolePermissionKeys()` usw., wirkt nur bei Firmen-Neuanlage), Bestand über `company_role_permission.csv` (`Ottom_Buyer,ExportInvoicePermissionPlugin,`) oder in Yves unter Company -> Roles.
4. Glossar `permission.name.<Key>` je Locale in `glossary.csv` (`CompanyRolePermissionsHandler::PERMISSION_KEY_GLOSSARY_PREFIX`), sonst zeigt die Rollenseite den rohen Key.
5. **Sync** vor Import: Back Office "Sync permissions" oder `docker/sdk cli console setup:init-db`.
6. Import und Publish:

```bash
docker/sdk cli console data:import company-role            # Alias data:import:company-role-permission existiert NICHT (live: "Command not defined")
docker/sdk cli console data:import company-role-permission
docker/sdk cli console data:import company-user-role       # Importername aus CompanyRoleDataImportConfig bestätigt
docker/sdk cli console data:import:dump                    # listet registrierte Importer
docker/sdk cli console publish:trigger-events -r unauthenticated_customer_access   # Customer Access neu publizieren
docker/sdk cli console queue:worker:start --stop-when-empty                        # [live verifiziert (202610.0)]
```

7. Prüfen: Twig `{% if can('ExportInvoicePermissionPlugin') %}` oder Controller mit `PermissionAwareTrait`.

**Customer Access ändern:** im Back Office (Customers -> Customer Access) pflegen; Config-Änderung wirkt nur für neue Typen oder nach Leeren der Tabelle; danach `publish:trigger-events -r unauthenticated_customer_access`. Nicht-verwaltbare Typen sind nur über Datenbank/Config erreichbar.

**Zustand prüfen** (Recherche, Container `spryker_b2b_dev_database_1`): `docker exec spryker_b2b_dev_database_1 mysql -uroot -psecret eu-docker -e "select id_permission,\`key\` from spy_permission; select * from spy_unauthenticated_customer_access;"`. Docker liegt auf macOS unter `/Applications/Docker.app/Contents/Resources/bin`.

## Beispiele

Neues Recht (nicht im Release; Muster nach `PlaceOrderWithAmountUpToPermissionPlugin` und `SeeCompanyMenuPermissionPlugin`), `src/Pyz/Shared/InvoiceExport/Plugin/Permission/ExportInvoicePermissionPlugin.php`:

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

Prüfung im Controller (Muster `CompanyRoleController`): `use \Spryker\Yves\Kernel\PermissionAwareTrait;` und `if (!$this->can(ExportInvoicePermissionPlugin::KEY)) { throw new AccessDeniedHttpException(); }`.

**Executable (echt, gekürzt):** `vendor/spryker/checkout/src/Spryker/Shared/Checkout/Plugin/Permission/PlaceOrderWithAmountUpToPermissionPlugin.php`

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

**Echt (Pyz):** `src/Pyz/Zed/CompanyUser/Communication/Plugin/Permission/SeeCompanyMenuPermissionPlugin.php` (Konstante `KEY = 'SeeCompanyMenuPermissionPlugin'`, `getKey()`), registriert in beiden `PermissionDependencyProvider`, Admin-Rolle in `getAdminRolePermissionKeys()`, Nutzung `{% if can('SeeCompanyMenuPermissionPlugin') %}` im Pyz-`header.twig`. Auffälligkeit: Namespace `Pyz\Zed\…`, Basisklasse `Spryker\Client\Kernel\AbstractPlugin`, Registrierung im Client (Layer-Mischung, nicht kopieren; Permission-Plugins ohne Abhängigkeiten brauchen keine Basisklasse).

**Echt (Pyz, Ausschnitt):** `Pyz\Zed\CustomerAccess\CustomerAccessConfig::getContentAccessByType()` liefert für `CONTENT_TYPE_PRICE`, `_ORDER_PLACE_SUBMIT`, `_ADD_TO_CART`, `_WISHLIST`, `_SHOPPING_LIST` jeweils `false`.

**Live-Befund:** `PlaceOrderPermissionPlugin` der Rolle "Buyer With Limit" hat `{"store_multi_currency":{"DE":{"EUR":70000,"CHF":80000}}}`; `PlaceOrderWithAmountUpToPermissionPlugin` Signatur `{"cent_amount":"CONFIG_FIELD_TYPE_INT"}`.

## Fallstricke

1. **Unregistriertes Recht = erlaubt.** Tippfehler im Key oder fehlende Registrierung in *einer* Schicht öffnen das Recht. Fund im Projekt: `src/Pyz/Yves/ProductSetWidget/Theme/default/components/organisms/{product-set-cms,product-set-details}/*.twig` nutzt `can('ROLE_USER')`; `ROLE_USER` ist kein Permission-Key -> immer `true`; richtig `is_granted('ROLE_USER')`.
2. In Client **und** Zed registrieren; Zed-`can()` kennt nur Zed-Plugins, Sync sammelt beide.
3. Permission fehlt in `spy_permission` -> Import wirft `Could not find permission by key`; Sync vor Import.
4. Neue Rechte erreichen bestehende Firmen nicht (`getPredefinedCompanyRoles()` nur bei Firmenanlage); Bestand per CSV/Migration/Yves-Rollen.
5. Glossar-Key `permission.name.<Key>` fehlt -> roher Key in der Rollenseite.
6. Session veraltet: Rollenänderungen über `CompanyRoleFacade::updateCompanyRole()` (löst PostSave aus), nicht per direktem Propel-Write.
7. Infrastructural Permissions nicht in Rollen pflegen (aus Customer Access abgeleitet).
8. Customer-Access-Config wirkt nach Install nicht (vorhandene Typen übersprungen); danach Re-Publish.
9. `CUSTOMER_SECURED_PATTERN` muss Routen enthalten, die per Customer Access freigebbar sind; Typ->Pattern liegt in `CustomerAccessPermissionConfig::CONTENT_TYPE_PERMISSION_ACCESS` `[Annahme: per eigener Client-Config überschreibbar]`.
10. Executable nur bei Konfiguration/Context nutzen (sonst leeres Formular in der Rollen-UI).
11. Erweiterungen nur über Pyz-DependencyProvider/Plugins/Config-Vererbung ([28](28-upgradability.md)); das Customer-Access-Override bricht, wenn der Core Konstruktorsignaturen ändert.

**Deprecated (am Code geprüft):** `CompanyRoleFacadeInterface::create()` -> `createCompanyRole()`, `update()` -> `updateCompanyRole()`; Client `OauthPermission\…\OauthPermissionStoragePlugin` -> `StoredOauthPermissionStoragePlugin`; `PermissionOauthCustomerIdentifierExpanderPlugin` (und analog `…CompanyUser…` `[Annahme für zweiten Namen]`) sowie `CustomerIdentifierExpander` -> `Stored…`-Plugins (Pyz nutzt bereits diese); `CustomerAccessPermissionClient` Secured-Pattern-Methode verweist auf `CustomerSecuredPatternPermissionPlugin`, **diese Klasse existiert in 202610.0 nicht** (Ersatz tatsächlich `CustomerAccessSecuredPatternRulePlugin`); `CustomerAccessPermissionConfig::getCustomerSecuredPattern()` (ins Customer-Modul verschoben); `CustomerAccessSynchronizationDataPlugin`/`CustomerAccessStorageListener` -> `…BulkPlugin`/`…BulkListener` (Pyz nutzt Bulk); `CustomerAccessStorageConfig` Sync-Flag -> `SynchronizationBehaviorConfig::isSynchronizationEnabled()`.

## Checkliste

- [ ] Plugin in Shared, in Client- **und** Zed-`PermissionDependencyProvider` registriert
- [ ] Key in Rollen-Defaults (`CompanyRoleConfig`) und/oder CSV; Glossar `permission.name.<Key>` je Locale
- [ ] Permission-Sync gelaufen (Back Office oder `setup:init-db`), dann Import
- [ ] Prüfung im Twig/Controller (`can()`), Key per Konstante, kein Tippfehler-Risiko
- [ ] Bestand migriert (CSV/Yves), neue Firmen über `getPredefinedCompanyRoles()`
- [ ] Customer Access: Tabelle/Config konsistent, Re-Publish ausgeführt
- [ ] Keine Layer-Mischung (kein `Spryker\Client\Kernel\AbstractPlugin` in Zed/Shared)

## Quellen

Doku (Abruf nur Navigation, Abgleich offen): `https://docs.spryker.com/docs/pbc/all/customer-relationship-management/latest/base-shop/company-account-feature-overview/company-user-roles-and-permissions-overview.html`, `…/base-shop/customer-access-feature-overview.html`.

Code: `vendor/spryker/permission-extension/…/PermissionExtension/Dependency/Plugin/*`, `vendor/spryker/permission/src/Spryker/{Client,Zed}/Permission/**`, `vendor/spryker/kernel/src/Spryker/{Yves,Client,Glue,Zed}/Kernel/PermissionAwareTrait.php`, `vendor/spryker/company-role/src/Spryker/{Zed,Client}/CompanyRole/**`, `vendor/spryker/company-role-data-import/…/CompanyRoleDataImportConfig.php`, `vendor/spryker/customer-access{,-storage,-permission,-gui,-rest-api}/**`, `vendor/spryker/oauth-permission/**`, `vendor/spryker/checkout/…/PlaceOrderWithAmountUpToPermissionPlugin.php`, `vendor/spryker-shop/shop-permission/…/PermissionTwigExtensionPlugin.php`, `vendor/spryker-shop/company-page/…/CompanyRoleController.php`, `src/Pyz/{Client/Permission,Zed/Permission,Yves/ShopPermission,Zed/CompanyRole,Zed/CompanyUser,Zed/CustomerAccess,Zed/CustomerAccessGui,Zed/OauthPermission,Glue/CustomerAccessRestApi,Shared/OauthPermission}/…`, `config/Shared/config_default.php`, `data/import/common/common/{company_role,company_role_permission,company_user_role,glossary}.csv`.

Nachbarn: [07 Publish & Sync](07-publish-sync.md), [08 Storage](08-client-storage-search.md), [09 Data Import](09-data-import.md), [13 Glue Backend](13-glue-backend.md), [18 Back Office (ACL)](18-back-office.md), [19 Glossar/i18n](19-multi-store-i18n.md), [21 Security](21-security-session.md).
