# Security und Session (Yves, Zed, Glue) (Recherche T-21)

> Status: Code in `vendor/`, `src/Pyz` und `config/` (202610.0, Symfony 6.4.48) direkt gelesen. Doku-Seiten (Session management 2021-10-04, Secure Coding Practices 2021-06-16, Redis session lock 2026-06-09) waren abrufbar, ihr Inhalt wurde nicht gegen den Code abgeglichen `[code-verifiziert, Doku nicht abgeglichen]`. Befehle im Container ausgeführt `[live verifiziert (202610.0)]`; Store-Präfix-Verhalten des Secured Pattern live per `curl` (GET, anonym) geprüft. Offen: umgebungsspezifische Configs (`config_default-*.php`) nicht auf abweichende Session-/Lock-Werte geprüft; weitere Deprecations in `spryker/security` nicht untersucht.

## Zweck
Firewalls und Zugriffsschutz (Yves Customer/Agent, Zed Back Office, Glue), CSRF, Brute-Force-Schutz (SecurityBlocker), Session-Handling, Secure-Coding-Regeln und das Absichern eigener Routen. Router/Controller → [14](14-yves-controller-routing.md); B2B-Rechte → [20](20-b2b-permissions.md); ACL → [18](18-back-office.md); Glue-Ressourcen → [12](12-glue-storefront.md), [13](13-glue-backend.md); Logging → [23](23-fehler-logging-debugging.md); Config/Env → [06](06-konfiguration.md).

## Konzepte
**Security-Component:** `spryker/security` kapselt Symfony Security. Einstieg als Application-Plugin: Yves `Spryker\Yves\Security\Plugin\Application\YvesSecurityApplicationPlugin` (in `Pyz\Yves\ShopApplication\ShopApplicationDependencyProvider::getApplicationPlugins()`), Zed `Spryker\Zed\Security\Communication\Plugin\Application\ZedSecurityApplicationPlugin` (in `Pyz\Zed\Application\ApplicationDependencyProvider`). Firewalls, Access-Rules, Handler schreiben **SecurityPlugins** (`Spryker\Shared\SecurityExtension\Dependency\Plugin\SecurityPluginInterface::extend(SecurityBuilderInterface, ContainerInterface): SecurityBuilderInterface`) in den `SecurityBuilder` (`addFirewall`, `mergeFirewall`, `addAccessRules`, `addRoleHierarchy`, `addAccessDeniedHandler`, ...).

**Yves:** `Pyz\Yves\Security\SecurityDependencyProvider::getSecurityPlugins()`: `RememberMeSecurityPlugin`, `CustomerRememberMeSecurityPlugin`, `YvesAgentPageSecurityPlugin`, `YvesCustomerPageSecurityPlugin`, `ValidateCustomerSessionSecurityPlugin`, `SaveCustomerSessionSecurityPlugin`, `ValidateAgentSessionSecurityPlugin`, `SaveAgentSessionSecurityPlugin`; dazu `getSecurityAuthenticationListenerFactoryTypeExpanderPlugins()` mit `SessionAgentValidationSecurityAuthenticationListenerFactoryTypeExpanderPlugin`.
- Customer-Firewall: Name `CustomerPageConfig::SECURITY_FIREWALL_NAME` (`Customer`), `pattern ^/`, `anonymous`, `remember_me`, Form-Login (`login_path` Route `login`, `with_csrf => true`, `csrf_parameter loginForm[_token]`), Logout (Route `logout` → `home`), `CustomerUserProvider`. Ältere lesbare Variante `CustomerPageSecurityPlugin` (`[Deprecated]` → `YvesCustomerPageSecurityPlugin`, Konstante `ROLE_USER` deprecated → `ROLE_NAME_USER`).
- **Welche Storefront-URL geschützt ist, entscheiden zwei Regex-Konstanten**, nicht der Controller: `CustomerConstants::CUSTOMER_SECURED_PATTERN` (→ `ROLE_USER`) und `CUSTOMER_ANONYMOUS_PATTERN` (`^/.*`, → `IS_AUTHENTICATED_ANONYMOUSLY`), `config/Shared/config_default.php` Zeile 263/264. Secured deckt `/login_check`, `customer`, `wishlist`, `shopping-list`, `quote-request`, `comment`, `company` (außer `/company/register`), `multi-cart`, `shared-cart`, `cart` (außer `/cart/add`), `checkout`, `cart-reorder`, `order-amendment` ab. Locale-Präfix (`(en|de)`) ist hart codiert. **Store-Präfix (`[A-Z]{2}`) ist nicht einheitlich abgedeckt:** nur `customer`, `wishlist`, `shopping-list`, `quote-request`, `multi-cart`, `comment`, `company`, `shared-cart` erlauben ein Store-Präfix; `cart`, `checkout`, `cart-reorder` und `order-amendment` haben **keins** (Regex `^(/en|/de)?/cart…`). Live: anonym `GET /DE/en/cart` -> HTTP 200 (nicht geschützt), `/en/cart` wird geschützt. Bei aktivem Store-Routing (`/DE/…`) ist das ein Fallstrick, siehe unten.
- Agent: `SprykerShop\Yves\AgentPage\Plugin\Security\YvesAgentPageSecurityPlugin`, Config `src/Pyz/Yves/AgentPage/AgentPageConfig.php`.
- Remember-Me: `CustomerPageConstants::CUSTOMER_REMEMBER_ME_SECRET` (Env `SPRYKER_CUSTOMER_REMEMBER_ME_SECRET`), `..._LIFETIME` = 31536000.
- Passwort-Hashing bcrypt: `CustomerConstants::PASSWORD_HASH_COST` (Default 12) bzw. `UserConstants::PASSWORD_HASH_COST`.

**Zed:** `Pyz\Zed\Security\SecurityDependencyProvider::getSecurityPlugins()`: `ZedUserSessionHandlerSecurityPlugin`, `ZedSystemUserSecurityPlugin`, `ZedUserSecurityPlugin` (security-gui), `ZedOauthUserSecurityPlugin`, `ZedValidateSessionUserSecurityPlugin`, `SaveSessionUserSecurityPlugin`. `SecurityGui\...\SecurityBuilderExpander`: Firewall `User`, `LoginFormAuthenticator`, Access-Rules in Reihenfolge (1) `getIgnorablePaths()` → `PUBLIC_ACCESS`, (2) dieselben → `ACCESS_MODE_PRE_AUTH`, (3) `getBackofficeRoutePattern()` (`^/`) → `ROLE_BACK_OFFICE_USER`; Access-Denied-Handler leitet auf `/security-gui/login`.
- `src/Pyz/Zed/SecurityGui/SecurityGuiConfig.php`: `IS_BACKOFFICE_USER_SECURITY_BLOCKER_ENABLED = true`, `IGNORABLE_ROUTE_PATTERN = '^/(security-gui|health-check|multi-factor-auth|_profiler/wdt|api/rest/.+)'`. **Jede neue Zed-Route ist automatisch hinter Login**; nur das Ignorable-Muster ist öffentlich. Passwortregeln (Core): 12-128 Zeichen, Groß-/Kleinbuchstabe, Ziffer, Sonderzeichen.
- Feine Rechte pro Route/Rolle: ACL → [18](18-back-office.md). MFA: `Pyz\Zed\MultiFactorAuth\MultiFactorAuthDependencyProvider` (`UserEmailMultiFactorAuthPlugin`, `CustomerEmailCodeSenderStrategyPlugin`, `UserEmailCodeSenderStrategyPlugin`, `PostUserLoginMultiFactorAuthenticationPlugin`); Yves-Seite `src/Pyz/Yves/MultiFactorAuth/`; `MultiFactorAuthExtensionFormPlugin` in Yves/Zed-Form-Providern.

**Glue:** Ressourcen sind per Default geschützt (`addGet($actionName, bool $isProtected = true, ...)`). Backend-Kette und Storefront-Kette (Request-Builder/-Validator/After-Routing-Plugins), Autorisierungs-Strategien (`ProtectedPath`, `ApiKey`, `UserOauthScope` in `Pyz\Zed\Authorization\AuthorizationDependencyProvider`), Token-Ressourcen (`OauthBackendApiTokenResource`, `OauthApiTokenResource`), Legacy-REST (`getRestRequestValidatorPlugins`, `getRestUserValidatorPlugins`, `getRestUserFinderPlugins`) → Details [12](12-glue-storefront.md), [13](13-glue-backend.md). Authentifizierung: `Pyz\Zed\Authentication\AuthenticationDependencyProvider` → `OauthAuthenticationServerPlugin`; OAuth-User-Provider in `Pyz\Zed\Oauth\OauthDependencyProvider`. OAuth-Schlüssel `OauthConstants::PRIVATE_KEY_PATH`, `PUBLIC_KEY_PATH`, `ENCRYPTION_KEY` (`config_default.php` 279-294). MFA-geschützte Glue-Ressourcen: `src/Pyz/Glue/MultiFactorAuth/MultiFactorAuthConfig.php` (REST: `customer-password, customers, addresses, carts, checkout, order-payments`; Backend: `warehouse-user-assignments`).

**CSRF:** `CsrfFormPlugin` in `Pyz\Yves\Form\FormDependencyProvider` (`Spryker\Yves\Form\Plugin\Form\CsrfFormPlugin`) und `Pyz\Zed\Form\FormDependencyProvider` (`Spryker\Zed\Form\Communication\Plugin\Form\CsrfFormPlugin`); jedes über `FormFactory` erzeugte Symfony-Form bekommt ein `_token`-Feld, solange `csrf_protection` nicht abgeschaltet ist. XSS-Sanitizing auf Form-Ebene: `SanitizeXssTypeExtensionFormPlugin` (Yves: `SprykerShop\Yves\ShopUi\Plugin\Form\`, Zed: `Spryker\Zed\Gui\Communication\Plugin\Form\`). State-ändernde Aktionen ohne Formular (Löschen-Links): Form-Objekt mit leerem Typ (Muster der Cart-FormWidgets `AddToCartFormWidget`, `RemoveFromCartFormWidget`) `[Annahme]` empfohlenes Muster, belegt nur für Cart.

**SecurityBlocker (Brute-Force, Redis):** Modul `spryker/security-blocker`; Client `SecurityBlockerClientInterface::incrementLoginAttemptCount()`, `isAccountBlocked()` (mit `SecurityCheckAuthContextTransfer` → `SecurityCheckAuthResponseTransfer`), `incrementAttemptCount(SecurityCheckAttemptContextTransfer)`. Pro Entity-Typ ein Settings-Expander in `Pyz\Client\SecurityBlocker\SecurityBlockerDependencyProvider::getSecurityBlockerConfigurationSettingsExpanderPlugins()` (BackofficeUser, Agent, Customer). Yves: `SecurityBlockerCustomerEventDispatcherPlugin`, `SecurityBlockerAgentEventDispatcherPlugin` in `Pyz\Yves\EventDispatcher\EventDispatcherDependencyProvider`; bei `LoginFailureEvent` wird gezählt, bei POST auf Route `login_check` (`KernelEvents::REQUEST`, Priorität 9) geprüft, blockiert → `HttpException(429)`. Kontext: IP (`$request->getClientIp()`); E-Mail nur wenn `SecurityBlockerPageConfig::useEmailContextForLoginSecurityBlocker()` true (Projekt: `false`, nur pro IP). Schwellen (`config_default.php` ca. 468-493): Redis DB 7. Wirksam sind die modulspezifischen Konstanten: Customer `SecurityBlockerStorefrontCustomerConstants::CUSTOMER_BLOCKING_TTL = 900`, `CUSTOMER_BLOCK_FOR_SECONDS = 360`, `CUSTOMER_BLOCKING_NUMBER_OF_ATTEMPTS = 9`; Agent `SecurityBlockerStorefrontAgentConstants::AGENT_*` (900/360/9); Backoffice `SecurityBlockerBackofficeConstants::BACKOFFICE_USER_*` (900/360/9). Die generischen `SecurityBlockerConstants::SECURITY_BLOCKER_BLOCKING_TTL = 600`, `SECURITY_BLOCKER_BLOCK_FOR = 300`, `SECURITY_BLOCKER_BLOCKING_NUMBER_OF_ATTEMPTS = 10` stehen ebenfalls in `config_default.php`, sind aber die Legacy-Werte; die Konstanten `SecurityBlockerConstants::SECURITY_BLOCKER_AGENT_*` sind im Core `@deprecated` und in `config_default.php` nicht gesetzt (nur in `config_default-ci.php`). Backoffice: `IS_BACKOFFICE_USER_SECURITY_BLOCKER_ENABLED`, Entity-Typ `back-office-user`. Glue: `SecurityBlockerCustomerRestRequestValidatorPlugin`, `SecurityBlockerAgentRestRequestValidatorPlugin`, `SecurityBlockerAgentControllerAfterActionPlugin` in `Pyz\Glue\GlueApplication\GlueApplicationDependencyProvider`.
- **Hinter Proxy/Load-Balancer** liefert `getClientIp()` die Proxy-IP, wenn Trusted Proxies fehlen. Konstanten existieren (`HttpConstants::YVES_TRUSTED_PROXIES`, `ZED_TRUSTED_PROXIES`, `GLUE_TRUSTED_PROXIES`, Default `[]`); in `config/Shared/config_default.php` ist keine davon gesetzt (per grep geprüft) → Betrieb prüfen ([30](30-docker-sdk-workflow.md)).

**Session:**
- Yves: `SessionApplicationPlugin` (`Spryker\Yves\Session\Plugin\Application`). Handler-Plugins `Pyz\Yves\Session\SessionDependencyProvider::getSessionHandlerPlugins()`: `SessionHandlerRedisProviderPlugin`, `SessionHandlerFileProviderPlugin`, `SessionHandlerConfigurableRedisLockingProviderPlugin`; gewählt per `SessionConstants::YVES_SESSION_SAVE_HANDLER = SessionRedisConfig::SESSION_HANDLER_CONFIGURABLE_REDIS_LOCKING` (`config_default.php` 425).
- Zed: `Spryker\Zed\Session\Communication\Plugin\Application\SessionApplicationPlugin`; Handler `...RedisProvider`, `...RedisLockingProvider`, `...FileProvider`; `ZED_SESSION_SAVE_HANDLER = SESSION_HANDLER_REDIS` (ohne Lock). Backend-Gateway: `MockArraySessionApplicationPlugin`.
- Handler-Konstanten (`SessionRedisConfig`): `redis`, `redis_locking`, `configurable_redis_locking`, `redis_write_only_locking`.

| Setting | Yves | Zed |
|---|---|---|
| Session-TTL | `SESSION_LIFETIME_1_HOUR` | `SESSION_LIFETIME_1_HOUR` |
| Cookie-TTL | `SESSION_LIFETIME_0_5_HOUR` | `SESSION_LIFETIME_BROWSER_SESSION` |
| SameSite | Env `SPRYKER_YVES_SESSION_COOKIE_SAMESITE` oder `Lax` | Env `SPRYKER_ZED_SESSION_COOKIE_SAMESITE` oder `Strict` |
| Redis | `SPRYKER_SESSION_FE_*`, DB-Default 2 | `SPRYKER_SESSION_BE_*`, DB-Default 2 |

- **Locking:** `LOCKING_TIMEOUT_MILLISECONDS`, `..._RETRY_DELAY_MICROSECONDS`, `..._LOCK_TTL_MILLISECONDS` stehen im Basis-Config auf **0** (Z. 416-418). `configurable_redis_locking` entscheidet pro Request über Condition-Plugins: `Pyz\Yves\SessionRedis\SessionRedisDependencyProvider::getSessionRedisLockingExclusionConditionPlugins()` → `UrlSessionRedisLockingExclusionConditionPlugin` (Muster `SessionRedisConfig::getSessionRedisLockingExcludedUrlPatterns()`, Default `error-page`, `health-check`), `BotSessionRedisLockingExclusionConditionPlugin`; Gegenstück `UrlSessionRedisLockingInclusionConditionPlugin` (`getSessionRedisLockingIncludedUrlPatterns()`: bei nicht leerer Liste nur für diese URLs gelockt). Lock-Releaser in Zed: `YvesSessionRedisLockReleaserPlugin`, `ZedSessionRedisLockReleaserPlugin`.
- Session-Validierung (Single-Session/Invalidierung): Customer `Pyz\Yves\SessionCustomerValidationPage\...DependencyProvider` (`RedisCustomerSessionSaverPlugin`, `RedisCustomerSessionValidatorPlugin`, `StorageInvalidationRecordCustomerSessionValidatorPlugin`), Agent `Pyz\Yves\SessionAgentValidation\...` (`SessionRedisSessionAgentSaverPlugin`/`...ValidatorPlugin`), Zed `Pyz\Zed\SessionUserValidation\...` (`SessionRedisSessionUserSaverPlugin`/`...ValidatorPlugin`). Diese Provider verlangen **einzelne** Saver/Validator, nur Customer hat zusätzlich eine Validator-Liste.

**Secure Coding:**
- Output-Escaping: Twig-Standard `html`; im Core wurde keine Autoescape-Überschreibung gefunden (`strict_variables` nur im Debug) `[Annahme]` Twig-Default. `|raw` nur für vertrauenswürdiges HTML (z. B. CMS), nie für Kundeneingaben.
- Input: Symfony-Validator (`ValidatorApplicationPlugin`, `ValidatorFormPlugin`), Constraints an Form-Types.
- SQL nur über Propel-Queries mit Binding ([05](05-propel-persistenz.md)).
- Secrets nur per Env (`getenv('SPRYKER_...')`: `SPRYKER_DB_PASSWORD`, `SPRYKER_KEY_VALUE_STORE_PASSWORD`, `SPRYKER_OAUTH_KEY_PRIVATE`, `SPRYKER_OAUTH_ENCRYPTION_KEY`, `SPRYKER_CUSTOMER_REMEMBER_ME_SECRET`); Logging maskiert `password` ([23](23-fehler-logging-debugging.md)).
- Security-Header: Yves `ApplicationConfig::getSecurityHeaders()` (X-Frame-Options, `Content-Security-Policy: frame-ancestors 'self'; base-uri 'self'; form-action 'self'`, X-Content-Type-Options, X-XSS-Protection, Referrer-Policy, Permissions-Policy); erweiterbar über `SecurityHeaderExpanderPluginInterface` in `Pyz\Yves\Application\ApplicationDependencyProvider::getSecurityHeaderExpanderPlugins()` (`ConfiguratorSecurityHeaderExpanderPlugin`). Glue: `SecurityHeaderResponseFormatterPlugin`, `StrictTransportSecurityHeaderResponseFormatterPlugin`; Pyz ergänzt `Cache-Control: no-cache, private`.

## Anleitung
**Eigene Yves-Route schützen:** Route anlegen ([14](14-yves-controller-routing.md)), dann URL-Regex in `CUSTOMER_SECURED_PATTERN` (`config/Shared/config_default.php`) ergänzen; ohne Eintrag ist sie anonym erreichbar. Präfixe (Store/Locale) im Regex berücksichtigen.

**Eigene Zed-Route öffentlich machen:** nur `IGNORABLE_ROUTE_PATTERN` in `Pyz\Zed\SecurityGui\SecurityGuiConfig` erweitern, die Firewall nicht abschalten.

**Eigene Security-Regel:** `SecurityPluginInterface`-Plugin in Pyz und Eintrag im jeweiligen `Pyz\...\Security\SecurityDependencyProvider::getSecurityPlugins()`. Reihenfolge der Access-Rules ist relevant (Symfony: erste passende gewinnt) `[Annahme]`.

**Eigene Glue-Ressource schützen:** Storefront `addGet('get')` (Default geschützt); Backend `getProtectedPaths()` ([13](13-glue-backend.md)).

Befehle `[live verifiziert (202610.0)]` (alle vier ausgeführt):
```bash
docker/sdk cli console session:lock:remove --yves <session_id>      # oder --zed <session_id> (Kurzformen -y / -z); ohne Option Exit 1 ("Missing application option"); im Projekt-Setup Exit 255 `NotALockingSessionHandlerException` (Handler `configurable_redis_locking` bzw. `redis` kennt kein Lock-Removal)
docker/sdk cli console oauth:refresh-token:remove-expired           # Exit 0 ("Removed 0 expired refresh tokens"); Cronjob in config/Zed/cronjobs/jenkins.php (Z. 139)
docker/sdk cli console oauth:scope-collection-file:generate
docker/sdk cli console cache:empty-all                              # [live verifiziert (202610.0)]
```
Session-DBs in Redis: Yves/Zed aus `SPRYKER_SESSION_FE_NAMESPACE`/`SPRYKER_SESSION_BE_NAMESPACE` (Default 2), SecurityBlocker DB 7 `[Annahme]`.

## Beispiele
Yves-Security-Plugins (echt, `src/Pyz/Yves/Security/SecurityDependencyProvider.php`, gekürzt auf die Methode):
```php
protected function getSecurityPlugins(): array
{
    return [
        new RememberMeSecurityPlugin(),
        new CustomerRememberMeSecurityPlugin(),
        new YvesAgentPageSecurityPlugin(),
        new YvesCustomerPageSecurityPlugin(),
        new ValidateCustomerSessionSecurityPlugin(),
        new SaveCustomerSessionSecurityPlugin(),
        new ValidateAgentSessionSecurityPlugin(),
        new SaveAgentSessionSecurityPlugin(),
    ];
}
```

Zed-Config (echt, `src/Pyz/Zed/SecurityGui/SecurityGuiConfig.php`):
```php
class SecurityGuiConfig extends SprykerSecurityGuiConfig
{
    protected const IS_BACKOFFICE_USER_SECURITY_BLOCKER_ENABLED = true;

    protected const IGNORABLE_ROUTE_PATTERN = '^/(security-gui|health-check|multi-factor-auth|_profiler/wdt|api/rest/.+)';
}
```

Redis-Lock-Ausschlüsse (echt, `src/Pyz/Yves/SessionRedis/SessionRedisDependencyProvider.php`; `use` auf `Spryker\Yves\SessionRedis\Plugin\SessionRedisLockingExclusion\...`):
```php
protected function getSessionRedisLockingExclusionConditionPlugins(): array
{
    return [
        new UrlSessionRedisLockingExclusionConditionPlugin(),
        new BotSessionRedisLockingExclusionConditionPlugin(),
    ];
}
```

Customer-Firewall-Schema (echt, `vendor/spryker-shop/customer-page/.../CustomerPageSecurityPlugin.php::addFirewalls()`, deprecated, aber lesbar):
```php
$securityBuilder->addFirewall(CustomerPageConfig::SECURITY_FIREWALL_NAME, [
    'anonymous' => true,
    'pattern' => '^/',
    'form' => [
        'login_path' => static::ROUTE_LOGIN,
        'check_path' => $this->getFactory()->createLoginCheckUrlFormatter()->getLoginCheckPath(),
        'with_csrf' => true,
        'csrf_parameter' => LoginForm::FORM_NAME . '[_token]',
        'csrf_token_id' => LoginForm::FORM_NAME,
    ],
    'logout' => ['logout_path' => static::ROUTE_LOGOUT, 'target_url' => static::ROUTE_HOME],
    'users' => function () { return $this->getFactory()->createCustomerUserProvider(); },
    // remember_me gekürzt
]);
```

Eigene Yves-Route schützen (abgeleitet, nicht ausgeführt `[Annahme]`): in `config/Shared/config_default.php` das bestehende Muster um einen Zweig ergänzen, z. B. `|^[/]*([A-Z]{2})?[/]*(en|de)?[/]*my-area($|/)`. Anonym `GET /de/my-area` führt dann zum Redirect auf die Login-Route (Verhalten aus `CustomerPageSecurityPlugin::addAccessRules/addAccessDeniedHandler` abgeleitet).

Eigenes Zed-SecurityPlugin (abgeleitet, nicht ausgeführt `[Annahme]`):
```php
namespace Pyz\Zed\MyModule\Communication\Plugin\Security;

class MyModuleSecurityPlugin extends AbstractPlugin implements SecurityPluginInterface
{
    public function extend(SecurityBuilderInterface $securityBuilder, ContainerInterface $container): SecurityBuilderInterface
    {
        return $securityBuilder->addAccessRules([
            ['^/my-module/public', 'PUBLIC_ACCESS'],
        ]);
    }
}
```
Imports: `Spryker\Service\Container\ContainerInterface`, `Spryker\Shared\SecurityExtension\Configuration\SecurityBuilderInterface`, `Spryker\Shared\SecurityExtension\Dependency\Plugin\SecurityPluginInterface`, `Spryker\Zed\Kernel\Communication\AbstractPlugin`. Einfacher und belegt: `IGNORABLE_ROUTE_PATTERN` erweitern.

## Fallstricke
- Neue Storefront-Route nicht in `CUSTOMER_SECURED_PATTERN` → öffentlich. Locale-Liste `en|de` ist hart codiert, bei neuer Locale mitpflegen ([19](19-multi-store-i18n.md)).
- **Store-Präfix-Lücke:** `cart`, `checkout`, `cart-reorder`, `order-amendment` haben im Secured Pattern kein `[A-Z]{2}`-Präfix (`config_default.php` Z. 263). Mit Store-Präfix (`/DE/en/cart`) greift das Pattern nicht: anonym HTTP 200 (live geprüft). Bei eigenen Mustern das Präfix `(/[A-Z]{2})?` mitnehmen und bestehende Muster bei aktivem Store-Routing prüfen; die Aussage "Secured Pattern deckt Store-Präfixe ab" gilt nur für die Zweige, die es enthalten.
- Neue Zed-Route ohne Login nötig (Healthcheck/Webhook) → `IGNORABLE_ROUTE_PATTERN` erweitern; zu weite Muster (`api/rest/.+` ist bereits öffentlich) vermeiden.
- Redis-Locking aktiv + langsame/parallele Requests (AJAX, Bots) → Locks stauen; Exclusion-/Inclusion-Plugins, `session:lock:remove`.
- `USE_EMAIL_CONTEXT_FOR_LOGIN_SECURITY_BLOCKER=false`: Zähler nur pro IP; hinter gemeinsamer NAT-IP blockiert das viele Nutzer, mit E-Mail-Kontext sind gezielte Lockouts einzelner Konten möglich (Abwägung).
- Trusted Proxies nicht gesetzt → SecurityBlocker zählt Proxy-IP.
- Core-Dateien ändern statt Pyz-Config/Plugin bricht den Upgrader ([03](03-core-erweitern.md), [28](28-upgradability.md)).
- CSRF nie global deaktivieren; Forms immer über `FormFactory`.
- `|raw` für Kundentexte = XSS; Secrets nie in Git/Transfer/Logs.
- Yves-Cookie `SameSite=Strict` kann Payment-Redirects brechen (Yves-Default `Lax`, Begründung `[Annahme]`).
- `SessionRedisConstants::LOCKING_*` im Basis-Config 0; Umgebungs-Configs können abweichen (nicht geprüft).
- Doku „Session management“ (2021) ist älter als `configurable_redis_locking` und die Condition-Plugins; maßgeblich ist `SessionRedisConfig` `[Annahme]` teilweise veraltet.

**Deprecations:** `CustomerPageSecurityPlugin` `[Deprecated]` → `YvesCustomerPageSecurityPlugin`; `SecurityBlockerPageConfig::isLocaleInCustomerLoginCheckPath()`/`isLocaleInAgentLoginCheckPath()` `[Deprecated]` (Pyz überschreibt beide auf `true`); `ApplicationConfig::addFeaturePolicyHeader()` (Feature-Policy) `[Deprecated]` → Permissions-Policy.

## Checkliste
- [ ] Neue Yves-Route: `CUSTOMER_SECURED_PATTERN` geprüft (Präfixe, Locales)
- [ ] Neue Zed-Route: Login-Pflicht gewollt? sonst `IGNORABLE_ROUTE_PATTERN`; Rechte per ACL
- [ ] Neue Glue-Ressource: `isProtected`/`getProtectedPaths()`/Scopes bewusst gesetzt
- [ ] Forms über `FormFactory` (CSRF aktiv), Aktionen ohne Form mit Token
- [ ] Keine `|raw`-Ausgabe von Nutzereingaben; Eingaben per Validator
- [ ] Secrets nur per Env, Trusted Proxies/SecurityBlocker-Kontext geprüft
- [ ] Session-Handler/Locking-Plugins passen zum Lastprofil
- [ ] Änderungen nur in Pyz-DependencyProvidern/Config, keine deprecated Plugins neu verwendet

## Quellen
Doku (Basis `https://docs.spryker.com/docs/`, Inhalt nicht abgeglichen): `dg/dev/guidelines/security-guidelines.html`, `dg/dev/guidelines/coding-guidelines/secure-coding-practices.html` (2021-06-16), `dg/dev/guidelines/coding-guidelines/secure-coding-practices-for-external-packages.html`, `dg/dev/backend-development/session-management.html` (2021-10-04), `dg/dev/troubleshooting/troubleshooting-performance-issues/redis-session-lock.html` (2026-06-09).

Quellcode: `src/Pyz/Yves/{Security,Session,SessionRedis,SessionAgentValidation,SessionCustomerValidationPage,SecurityBlockerPage,Form,EventDispatcher,Application,ShopApplication,MultiFactorAuth,CustomerPage,AgentPage}/`; `src/Pyz/Zed/{Security,SecurityGui,Session,SessionUserValidation,MultiFactorAuth,Form,Application,Oauth,Authentication,Authorization,Console}/`; `src/Pyz/Client/SecurityBlocker/SecurityBlockerDependencyProvider.php`; `src/Pyz/Glue/{GlueApplication,GlueBackendApiApplication,GlueStorefrontApiApplication,OauthApi,OauthBackendApi,MultiFactorAuth,AuthRestApi}/`; `config/Shared/config_default.php` (262-294, 413-478), `config/Zed/cronjobs/jenkins.php`; `vendor/spryker-shop/customer-page/.../Plugin/Security/*`, `vendor/spryker-shop/security-blocker-page/.../EventSubscriber/SecurityBlockerCustomerEventSubscriber.php`; `vendor/spryker/security-gui/.../{SecurityGuiConfig,Communication/Expander/SecurityBuilderExpander}.php`; `vendor/spryker/security-extension/.../{SecurityPluginInterface,SecurityBuilderInterface}.php`; `vendor/spryker/security-blocker/*`; `vendor/spryker/session-redis/*`; `vendor/spryker/session/.../SessionRemoveLockConsole.php`; `vendor/spryker/http/src/Spryker/Shared/Http/HttpConstants.php`; `vendor/spryker/application/.../ApplicationConfig.php`; `vendor/spryker/form/.../CsrfFormPlugin.php`.

Nachbarn: [14 Controller/Routing](14-yves-controller-routing.md), [18 Back Office](18-back-office.md), [20 B2B-Rechte](20-b2b-permissions.md), [12 Glue Storefront](12-glue-storefront.md), [13 Glue Backend](13-glue-backend.md).
