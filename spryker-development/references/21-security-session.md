# Security and session (Yves, Zed, Glue)

> Status: Code in `vendor/`, `src/Pyz` and `config/` (202610.0, Symfony 6.4.48) read directly. Docs pages (Session management 2021-10-04, Secure Coding Practices 2021-06-16, Redis session lock 2026-06-09) were retrievable, their content was not cross-checked against the code `[code-verified, docs not cross-checked]`. Commands executed in the container `[live verified (202610.0)]`; store prefix behavior of the secured pattern checked live via `curl` (GET, anonymous). Open: environment-specific configs (`config_default-*.php`) not checked for deviating session/lock values; further deprecations in `spryker/security` not investigated.

## Purpose
Firewalls and access protection (Yves customer/agent, Zed Back Office, Glue), CSRF, brute-force protection (SecurityBlocker), session handling, secure coding rules and securing your own routes. Router/controller → [14](14-yves-controller-routing.md); B2B permissions → [20](20-b2b-permissions.md); ACL → [18](18-back-office.md); Glue resources → [12](12-glue-storefront.md), [13](13-glue-backend.md); logging → [23](23-errors-logging-debugging.md); config/env → [06](06-configuration.md).

## Concepts
**Security component:** `spryker/security` encapsulates Symfony Security. Entry as an application plugin: Yves `Spryker\Yves\Security\Plugin\Application\YvesSecurityApplicationPlugin` (in `Pyz\Yves\ShopApplication\ShopApplicationDependencyProvider::getApplicationPlugins()`), Zed `Spryker\Zed\Security\Communication\Plugin\Application\ZedSecurityApplicationPlugin` (in `Pyz\Zed\Application\ApplicationDependencyProvider`). Firewalls, access rules and handlers are written by **SecurityPlugins** (`Spryker\Shared\SecurityExtension\Dependency\Plugin\SecurityPluginInterface::extend(SecurityBuilderInterface, ContainerInterface): SecurityBuilderInterface`) into the `SecurityBuilder` (`addFirewall`, `mergeFirewall`, `addAccessRules`, `addRoleHierarchy`, `addAccessDeniedHandler`, ...).

**Yves:** `Pyz\Yves\Security\SecurityDependencyProvider::getSecurityPlugins()`: `RememberMeSecurityPlugin`, `CustomerRememberMeSecurityPlugin`, `YvesAgentPageSecurityPlugin`, `YvesCustomerPageSecurityPlugin`, `ValidateCustomerSessionSecurityPlugin`, `SaveCustomerSessionSecurityPlugin`, `ValidateAgentSessionSecurityPlugin`, `SaveAgentSessionSecurityPlugin`; additionally `getSecurityAuthenticationListenerFactoryTypeExpanderPlugins()` with `SessionAgentValidationSecurityAuthenticationListenerFactoryTypeExpanderPlugin`.
- Customer firewall: name `CustomerPageConfig::SECURITY_FIREWALL_NAME` (`Customer`), `pattern ^/`, `anonymous`, `remember_me`, form login (`login_path` route `login`, `with_csrf => true`, `csrf_parameter loginForm[_token]`), logout (route `logout` → `home`), `CustomerUserProvider`. Older readable variant `CustomerPageSecurityPlugin` (`[Deprecated]` → `YvesCustomerPageSecurityPlugin`, constant `ROLE_USER` deprecated → `ROLE_NAME_USER`).
- **Which Storefront URL is protected is decided by two regex constants**, not by the controller: `CustomerConstants::CUSTOMER_SECURED_PATTERN` (→ `ROLE_USER`) and `CUSTOMER_ANONYMOUS_PATTERN` (`^/.*`, → `IS_AUTHENTICATED_ANONYMOUSLY`), `config/Shared/config_default.php` line 263/264. Secured covers `/login_check`, `customer`, `wishlist`, `shopping-list`, `quote-request`, `comment`, `company` (except `/company/register`), `multi-cart`, `shared-cart`, `cart` (except `/cart/add`), `checkout`, `cart-reorder`, `order-amendment`. The locale prefix (`(en|de)`) is hard-coded. **The store prefix (`[A-Z]{2}`) is not covered uniformly:** only `customer`, `wishlist`, `shopping-list`, `quote-request`, `multi-cart`, `comment`, `company`, `shared-cart` allow a store prefix; `cart`, `checkout`, `cart-reorder` and `order-amendment` have **none** (regex `^(/en|/de)?/cart…`). Live: anonymous `GET /DE/en/cart` -> HTTP 200 (not protected), `/en/cart` is protected. With active store routing (`/DE/…`) this is a pitfall, see below.
- Agent: `SprykerShop\Yves\AgentPage\Plugin\Security\YvesAgentPageSecurityPlugin`, config `src/Pyz/Yves/AgentPage/AgentPageConfig.php`.
- Remember-me: `CustomerPageConstants::CUSTOMER_REMEMBER_ME_SECRET` (env `SPRYKER_CUSTOMER_REMEMBER_ME_SECRET`), `..._LIFETIME` = 31536000.
- Password hashing bcrypt: `CustomerConstants::PASSWORD_HASH_COST` (default 12) or `UserConstants::PASSWORD_HASH_COST`.

**Zed:** `Pyz\Zed\Security\SecurityDependencyProvider::getSecurityPlugins()`: `ZedUserSessionHandlerSecurityPlugin`, `ZedSystemUserSecurityPlugin`, `ZedUserSecurityPlugin` (security-gui), `ZedOauthUserSecurityPlugin`, `ZedValidateSessionUserSecurityPlugin`, `SaveSessionUserSecurityPlugin`. `SecurityGui\...\SecurityBuilderExpander`: firewall `User`, `LoginFormAuthenticator`, access rules in order (1) `getIgnorablePaths()` → `PUBLIC_ACCESS`, (2) the same → `ACCESS_MODE_PRE_AUTH`, (3) `getBackofficeRoutePattern()` (`^/`) → `ROLE_BACK_OFFICE_USER`; the access denied handler redirects to `/security-gui/login`.
- `src/Pyz/Zed/SecurityGui/SecurityGuiConfig.php`: `IS_BACKOFFICE_USER_SECURITY_BLOCKER_ENABLED = true`, `IGNORABLE_ROUTE_PATTERN = '^/(security-gui|health-check|multi-factor-auth|_profiler/wdt|api/rest/.+)'`. **Every new Zed route is automatically behind login**; only the ignorable pattern is public. Password rules (core): 12-128 characters, upper/lower case letter, digit, special character.
- Fine-grained permissions per route/role: ACL → [18](18-back-office.md). MFA: `Pyz\Zed\MultiFactorAuth\MultiFactorAuthDependencyProvider` (`UserEmailMultiFactorAuthPlugin`, `CustomerEmailCodeSenderStrategyPlugin`, `UserEmailCodeSenderStrategyPlugin`, `PostUserLoginMultiFactorAuthenticationPlugin`); Yves side `src/Pyz/Yves/MultiFactorAuth/`; `MultiFactorAuthExtensionFormPlugin` in Yves/Zed form providers.

**Glue:** Resources are protected by default (`addGet($actionName, bool $isProtected = true, ...)`). Backend chain and Storefront chain (request builder/validator/after-routing plugins), authorization strategies (`ProtectedPath`, `ApiKey`, `UserOauthScope` in `Pyz\Zed\Authorization\AuthorizationDependencyProvider`), token resources (`OauthBackendApiTokenResource`, `OauthApiTokenResource`), legacy REST (`getRestRequestValidatorPlugins`, `getRestUserValidatorPlugins`, `getRestUserFinderPlugins`) → details [12](12-glue-storefront.md), [13](13-glue-backend.md). Authentication: `Pyz\Zed\Authentication\AuthenticationDependencyProvider` → `OauthAuthenticationServerPlugin`; OAuth user providers in `Pyz\Zed\Oauth\OauthDependencyProvider`. OAuth keys `OauthConstants::PRIVATE_KEY_PATH`, `PUBLIC_KEY_PATH`, `ENCRYPTION_KEY` (`config_default.php` 279-294). MFA-protected Glue resources: `src/Pyz/Glue/MultiFactorAuth/MultiFactorAuthConfig.php` (REST: `customer-password, customers, addresses, carts, checkout, order-payments`; backend: `warehouse-user-assignments`).

**CSRF:** `CsrfFormPlugin` in `Pyz\Yves\Form\FormDependencyProvider` (`Spryker\Yves\Form\Plugin\Form\CsrfFormPlugin`) and `Pyz\Zed\Form\FormDependencyProvider` (`Spryker\Zed\Form\Communication\Plugin\Form\CsrfFormPlugin`); every Symfony form created via `FormFactory` gets a `_token` field as long as `csrf_protection` is not switched off. XSS sanitizing at form level: `SanitizeXssTypeExtensionFormPlugin` (Yves: `SprykerShop\Yves\ShopUi\Plugin\Form\`, Zed: `Spryker\Zed\Gui\Communication\Plugin\Form\`). State-changing actions without a form (delete links): form object with an empty type (pattern of the cart form widgets `AddToCartFormWidget`, `RemoveFromCartFormWidget`) `[Assumption]` recommended pattern, evidenced only for the cart.

**SecurityBlocker (brute force, Redis):** module `spryker/security-blocker`; client `SecurityBlockerClientInterface::incrementLoginAttemptCount()`, `isAccountBlocked()` (with `SecurityCheckAuthContextTransfer` → `SecurityCheckAuthResponseTransfer`), `incrementAttemptCount(SecurityCheckAttemptContextTransfer)`. One settings expander per entity type in `Pyz\Client\SecurityBlocker\SecurityBlockerDependencyProvider::getSecurityBlockerConfigurationSettingsExpanderPlugins()` (BackofficeUser, Agent, Customer). Yves: `SecurityBlockerCustomerEventDispatcherPlugin`, `SecurityBlockerAgentEventDispatcherPlugin` in `Pyz\Yves\EventDispatcher\EventDispatcherDependencyProvider`; on `LoginFailureEvent` the count is incremented, on POST to route `login_check` (`KernelEvents::REQUEST`, priority 9) it is checked, blocked → `HttpException(429)`. Context: IP (`$request->getClientIp()`); email only if `SecurityBlockerPageConfig::useEmailContextForLoginSecurityBlocker()` is true (project: `false`, per IP only). Thresholds (`config_default.php` approx. 468-493): Redis DB 7. The module-specific constants are the effective ones: customer `SecurityBlockerStorefrontCustomerConstants::CUSTOMER_BLOCKING_TTL = 900`, `CUSTOMER_BLOCK_FOR_SECONDS = 360`, `CUSTOMER_BLOCKING_NUMBER_OF_ATTEMPTS = 9`; agent `SecurityBlockerStorefrontAgentConstants::AGENT_*` (900/360/9); Back Office `SecurityBlockerBackofficeConstants::BACKOFFICE_USER_*` (900/360/9). The generic `SecurityBlockerConstants::SECURITY_BLOCKER_BLOCKING_TTL = 600`, `SECURITY_BLOCKER_BLOCK_FOR = 300`, `SECURITY_BLOCKER_BLOCKING_NUMBER_OF_ATTEMPTS = 10` are also in `config_default.php`, but they are the legacy values; the constants `SecurityBlockerConstants::SECURITY_BLOCKER_AGENT_*` are `@deprecated` in the core and not set in `config_default.php` (only in `config_default-ci.php`). Back Office: `IS_BACKOFFICE_USER_SECURITY_BLOCKER_ENABLED`, entity type `back-office-user`. Glue: `SecurityBlockerCustomerRestRequestValidatorPlugin`, `SecurityBlockerAgentRestRequestValidatorPlugin`, `SecurityBlockerAgentControllerAfterActionPlugin` in `Pyz\Glue\GlueApplication\GlueApplicationDependencyProvider`.
- **Behind a proxy/load balancer** `getClientIp()` returns the proxy IP if trusted proxies are missing. Constants exist (`HttpConstants::YVES_TRUSTED_PROXIES`, `ZED_TRUSTED_PROXIES`, `GLUE_TRUSTED_PROXIES`, default `[]`); none of them is set in `config/Shared/config_default.php` (checked via grep) → check operations ([30](30-docker-sdk-workflow.md)).

**Session:**
- Yves: `SessionApplicationPlugin` (`Spryker\Yves\Session\Plugin\Application`). Handler plugins `Pyz\Yves\Session\SessionDependencyProvider::getSessionHandlerPlugins()`: `SessionHandlerRedisProviderPlugin`, `SessionHandlerFileProviderPlugin`, `SessionHandlerConfigurableRedisLockingProviderPlugin`; selected via `SessionConstants::YVES_SESSION_SAVE_HANDLER = SessionRedisConfig::SESSION_HANDLER_CONFIGURABLE_REDIS_LOCKING` (`config_default.php` 425).
- Zed: `Spryker\Zed\Session\Communication\Plugin\Application\SessionApplicationPlugin`; handlers `...RedisProvider`, `...RedisLockingProvider`, `...FileProvider`; `ZED_SESSION_SAVE_HANDLER = SESSION_HANDLER_REDIS` (without lock). Backend gateway: `MockArraySessionApplicationPlugin`.
- Handler constants (`SessionRedisConfig`): `redis`, `redis_locking`, `configurable_redis_locking`, `redis_write_only_locking`.

| Setting | Yves | Zed |
|---|---|---|
| Session TTL | `SESSION_LIFETIME_1_HOUR` | `SESSION_LIFETIME_1_HOUR` |
| Cookie TTL | `SESSION_LIFETIME_0_5_HOUR` | `SESSION_LIFETIME_BROWSER_SESSION` |
| SameSite | env `SPRYKER_YVES_SESSION_COOKIE_SAMESITE` or `Lax` | env `SPRYKER_ZED_SESSION_COOKIE_SAMESITE` or `Strict` |
| Redis | `SPRYKER_SESSION_FE_*`, DB default 2 | `SPRYKER_SESSION_BE_*`, DB default 2 |

- **Locking:** `LOCKING_TIMEOUT_MILLISECONDS`, `..._RETRY_DELAY_MICROSECONDS`, `..._LOCK_TTL_MILLISECONDS` are set to **0** in the base config (lines 416-418). `configurable_redis_locking` decides per request via condition plugins: `Pyz\Yves\SessionRedis\SessionRedisDependencyProvider::getSessionRedisLockingExclusionConditionPlugins()` → `UrlSessionRedisLockingExclusionConditionPlugin` (patterns `SessionRedisConfig::getSessionRedisLockingExcludedUrlPatterns()`, default `error-page`, `health-check`), `BotSessionRedisLockingExclusionConditionPlugin`; counterpart `UrlSessionRedisLockingInclusionConditionPlugin` (`getSessionRedisLockingIncludedUrlPatterns()`: with a non-empty list, only these URLs are locked). Lock releasers in Zed: `YvesSessionRedisLockReleaserPlugin`, `ZedSessionRedisLockReleaserPlugin`.
- Session validation (single session/invalidation): customer `Pyz\Yves\SessionCustomerValidationPage\...DependencyProvider` (`RedisCustomerSessionSaverPlugin`, `RedisCustomerSessionValidatorPlugin`, `StorageInvalidationRecordCustomerSessionValidatorPlugin`), agent `Pyz\Yves\SessionAgentValidation\...` (`SessionRedisSessionAgentSaverPlugin`/`...ValidatorPlugin`), Zed `Pyz\Zed\SessionUserValidation\...` (`SessionRedisSessionUserSaverPlugin`/`...ValidatorPlugin`). These providers require **single** savers/validators; only the customer one additionally has a validator list.

**Secure coding:**
- Output escaping: Twig default `html`; no autoescape override was found in the core (`strict_variables` only in debug) `[Assumption]` Twig default. `|raw` only for trusted HTML (e.g. CMS), never for customer input.
- Input: Symfony Validator (`ValidatorApplicationPlugin`, `ValidatorFormPlugin`), constraints on form types.
- SQL only via Propel queries with binding ([05](05-propel-persistence.md)).
- Secrets only via env (`getenv('SPRYKER_...')`: `SPRYKER_DB_PASSWORD`, `SPRYKER_KEY_VALUE_STORE_PASSWORD`, `SPRYKER_OAUTH_KEY_PRIVATE`, `SPRYKER_OAUTH_ENCRYPTION_KEY`, `SPRYKER_CUSTOMER_REMEMBER_ME_SECRET`); logging masks `password` ([23](23-errors-logging-debugging.md)).
- Security headers: Yves `ApplicationConfig::getSecurityHeaders()` (X-Frame-Options, `Content-Security-Policy: frame-ancestors 'self'; base-uri 'self'; form-action 'self'`, X-Content-Type-Options, X-XSS-Protection, Referrer-Policy, Permissions-Policy); extensible via `SecurityHeaderExpanderPluginInterface` in `Pyz\Yves\Application\ApplicationDependencyProvider::getSecurityHeaderExpanderPlugins()` (`ConfiguratorSecurityHeaderExpanderPlugin`). Glue: `SecurityHeaderResponseFormatterPlugin`, `StrictTransportSecurityHeaderResponseFormatterPlugin`; Pyz adds `Cache-Control: no-cache, private`.

## Instructions
**Protect your own Yves route:** create the route ([14](14-yves-controller-routing.md)), then add the URL regex to `CUSTOMER_SECURED_PATTERN` (`config/Shared/config_default.php`); without an entry it is reachable anonymously. Take prefixes (store/locale) into account in the regex.

**Make your own Zed route public:** only extend `IGNORABLE_ROUTE_PATTERN` in `Pyz\Zed\SecurityGui\SecurityGuiConfig`, do not switch off the firewall.

**Custom security rule:** `SecurityPluginInterface` plugin in Pyz and an entry in the respective `Pyz\...\Security\SecurityDependencyProvider::getSecurityPlugins()`. The order of access rules matters (Symfony: first match wins) `[Assumption]`.

**Protect your own Glue resource:** Storefront `addGet('get')` (protected by default); backend `getProtectedPaths()` ([13](13-glue-backend.md)).

Commands `[live verified (202610.0)]` (all four executed):
```bash
docker/sdk cli console session:lock:remove --yves <session_id>      # or --zed <session_id> (short forms -y / -z); without option exit 1 ("Missing application option"); in the project setup exit 255 `NotALockingSessionHandlerException` (handler `configurable_redis_locking` or `redis` does not support lock removal)
docker/sdk cli console oauth:refresh-token:remove-expired           # exit 0 ("Removed 0 expired refresh tokens"); cron job in config/Zed/cronjobs/jenkins.php (line 139)
docker/sdk cli console oauth:scope-collection-file:generate
docker/sdk cli console cache:empty-all                              # [live verified (202610.0)]
```
Session DBs in Redis: Yves/Zed from `SPRYKER_SESSION_FE_NAMESPACE`/`SPRYKER_SESSION_BE_NAMESPACE` (default 2), SecurityBlocker DB 7 `[Assumption]`.

## Examples
Yves security plugins (real, `src/Pyz/Yves/Security/SecurityDependencyProvider.php`, shortened to the method):
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

Zed config (real, `src/Pyz/Zed/SecurityGui/SecurityGuiConfig.php`):
```php
class SecurityGuiConfig extends SprykerSecurityGuiConfig
{
    protected const IS_BACKOFFICE_USER_SECURITY_BLOCKER_ENABLED = true;

    protected const IGNORABLE_ROUTE_PATTERN = '^/(security-gui|health-check|multi-factor-auth|_profiler/wdt|api/rest/.+)';
}
```

Redis lock exclusions (real, `src/Pyz/Yves/SessionRedis/SessionRedisDependencyProvider.php`; `use` on `Spryker\Yves\SessionRedis\Plugin\SessionRedisLockingExclusion\...`):
```php
protected function getSessionRedisLockingExclusionConditionPlugins(): array
{
    return [
        new UrlSessionRedisLockingExclusionConditionPlugin(),
        new BotSessionRedisLockingExclusionConditionPlugin(),
    ];
}
```

Customer firewall schema (real, `vendor/spryker-shop/customer-page/.../CustomerPageSecurityPlugin.php::addFirewalls()`, deprecated but readable):
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
    // remember_me shortened
]);
```

Protect your own Yves route (derived, not executed `[Assumption]`): in `config/Shared/config_default.php` extend the existing pattern by a branch, e.g. `|^[/]*([A-Z]{2})?[/]*(en|de)?[/]*my-area($|/)`. An anonymous `GET /de/my-area` then leads to a redirect to the login route (behavior derived from `CustomerPageSecurityPlugin::addAccessRules/addAccessDeniedHandler`).

Custom Zed SecurityPlugin (derived, not executed `[Assumption]`):
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
Imports: `Spryker\Service\Container\ContainerInterface`, `Spryker\Shared\SecurityExtension\Configuration\SecurityBuilderInterface`, `Spryker\Shared\SecurityExtension\Dependency\Plugin\SecurityPluginInterface`, `Spryker\Zed\Kernel\Communication\AbstractPlugin`. Simpler and evidenced: extend `IGNORABLE_ROUTE_PATTERN`.

## Pitfalls
- New Storefront route not in `CUSTOMER_SECURED_PATTERN` → public. The locale list `en|de` is hard-coded; maintain it when adding a new locale ([19](19-multi-store-i18n.md)).
- **Store prefix gap:** `cart`, `checkout`, `cart-reorder`, `order-amendment` have no `[A-Z]{2}` prefix in the secured pattern (`config_default.php` line 263). With a store prefix (`/DE/en/cart`) the pattern does not match: anonymous HTTP 200 (checked live). In your own patterns include the prefix `(/[A-Z]{2})?` and check existing patterns when store routing is active; the statement "the secured pattern covers store prefixes" only holds for the branches that contain it.
- New Zed route needs no login (health check/webhook) → extend `IGNORABLE_ROUTE_PATTERN`; avoid overly broad patterns (`api/rest/.+` is already public).
- Redis locking active + slow/parallel requests (AJAX, bots) → locks pile up; exclusion/inclusion plugins, `session:lock:remove`.
- `USE_EMAIL_CONTEXT_FOR_LOGIN_SECURITY_BLOCKER=false`: counter per IP only; behind a shared NAT IP this blocks many users, with email context targeted lockouts of individual accounts are possible (trade-off).
- Trusted proxies not set → SecurityBlocker counts the proxy IP.
- Changing core files instead of Pyz config/plugin breaks the upgrader ([03](03-extending-the-core.md), [28](28-upgradability.md)).
- Never disable CSRF globally; always create forms via `FormFactory`.
- `|raw` for customer texts = XSS; never put secrets in Git/transfers/logs.
- Yves cookie `SameSite=Strict` can break payment redirects (Yves default `Lax`, rationale `[Assumption]`).
- `SessionRedisConstants::LOCKING_*` are 0 in the base config; environment configs may differ (not checked).
- The "Session management" docs (2021) are older than `configurable_redis_locking` and the condition plugins; `SessionRedisConfig` is authoritative `[Assumption]` partially outdated.

**Deprecations:** `CustomerPageSecurityPlugin` `[Deprecated]` → `YvesCustomerPageSecurityPlugin`; `SecurityBlockerPageConfig::isLocaleInCustomerLoginCheckPath()`/`isLocaleInAgentLoginCheckPath()` `[Deprecated]` (Pyz overrides both to `true`); `ApplicationConfig::addFeaturePolicyHeader()` (Feature-Policy) `[Deprecated]` → Permissions-Policy.

## Checklist
- [ ] New Yves route: `CUSTOMER_SECURED_PATTERN` checked (prefixes, locales)
- [ ] New Zed route: login requirement intended? Otherwise `IGNORABLE_ROUTE_PATTERN`; permissions via ACL
- [ ] New Glue resource: `isProtected`/`getProtectedPaths()`/scopes set deliberately
- [ ] Forms via `FormFactory` (CSRF active), actions without a form use a token
- [ ] No `|raw` output of user input; input via validator
- [ ] Secrets only via env, trusted proxies/SecurityBlocker context checked
- [ ] Session handler/locking plugins match the load profile
- [ ] Changes only in Pyz DependencyProviders/config, no deprecated plugins newly used

## Sources
Docs (base `https://docs.spryker.com/docs/`, content not cross-checked): `dg/dev/guidelines/security-guidelines.html`, `dg/dev/guidelines/coding-guidelines/secure-coding-practices.html` (2021-06-16), `dg/dev/guidelines/coding-guidelines/secure-coding-practices-for-external-packages.html`, `dg/dev/backend-development/session-management.html` (2021-10-04), `dg/dev/troubleshooting/troubleshooting-performance-issues/redis-session-lock.html` (2026-06-09).

Source code: `src/Pyz/Yves/{Security,Session,SessionRedis,SessionAgentValidation,SessionCustomerValidationPage,SecurityBlockerPage,Form,EventDispatcher,Application,ShopApplication,MultiFactorAuth,CustomerPage,AgentPage}/`; `src/Pyz/Zed/{Security,SecurityGui,Session,SessionUserValidation,MultiFactorAuth,Form,Application,Oauth,Authentication,Authorization,Console}/`; `src/Pyz/Client/SecurityBlocker/SecurityBlockerDependencyProvider.php`; `src/Pyz/Glue/{GlueApplication,GlueBackendApiApplication,GlueStorefrontApiApplication,OauthApi,OauthBackendApi,MultiFactorAuth,AuthRestApi}/`; `config/Shared/config_default.php` (262-294, 413-478), `config/Zed/cronjobs/jenkins.php`; `vendor/spryker-shop/customer-page/.../Plugin/Security/*`, `vendor/spryker-shop/security-blocker-page/.../EventSubscriber/SecurityBlockerCustomerEventSubscriber.php`; `vendor/spryker/security-gui/.../{SecurityGuiConfig,Communication/Expander/SecurityBuilderExpander}.php`; `vendor/spryker/security-extension/.../{SecurityPluginInterface,SecurityBuilderInterface}.php`; `vendor/spryker/security-blocker/*`; `vendor/spryker/session-redis/*`; `vendor/spryker/session/.../SessionRemoveLockConsole.php`; `vendor/spryker/http/src/Spryker/Shared/Http/HttpConstants.php`; `vendor/spryker/application/.../ApplicationConfig.php`; `vendor/spryker/form/.../CsrfFormPlugin.php`.

Neighbors: [14 Controller/Routing](14-yves-controller-routing.md), [18 Back Office](18-back-office.md), [20 B2B permissions](20-b2b-permissions.md), [12 Glue Storefront](12-glue-storefront.md), [13 Glue Backend](13-glue-backend.md).
