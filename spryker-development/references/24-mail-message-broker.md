# Mail, Message Broker and app integrations

> Status: release 202610.0 (spryker/kernel 3.87.1, spryker/oms 11.55.0). Code (`vendor/`, `src/Pyz`, `config/`) read, `[code-verified, docs not cross-checked]` for everything except the ACP pages listed in the Sources section (retrieved, as of 02/2026 and 07/2025 respectively). The mail docs pages (as of 2021) are outdated; the code is authoritative. Console commands executed `[live verified (202610.0)]` (exception `message-broker:debug`, see command list). The environment file for `docker.dev` is `config_default-docker.dev.php`; `config_default-docker.php` is not loaded there (config chain: `config_default.php`, then `config_default-<environment name>.php`, see `Spryker\Shared\Config\Config::init()`). Template name resolution `newsletter/...` → `Newsletter/Presentation` only evidenced by examples `[Assumption]`.

## Purpose

Send mails via `MailFacade` (mail type plugin, templates, provider), understand Message Broker messages (channels, handlers, transport) and classify what KernelApp/PaymentApp/TaxApp do in the project. Important up front: **according to the docs, ACP is being shut down** ("is being sunset. No additional activations or changes are allowed", `dg/dev/acp/app-composition-platform.html`, as of 25.02.2026). Mention ACP only for existing projects; do not recommend it for new integrations. The Message Broker itself is also used without ACP for CMS/product/order events.

## Concepts

**Mail flow.**
1. A business model calls `Spryker\Zed\Mail\Business\MailFacade::handleMail(MailTransfer)` (signature without return type in the interface) and sets `MailTransfer::type` (string) beforehand.
2. `Spryker\Zed\Mail\Business\Model\Mailer\MailHandler::handleMail()` searches the mail type plugins (`getMailTypeBuilderPlugins()`) for the plugin whose `getName()` equals `type`, sets the default sender (`MailConfig::getSenderEmail()/getSenderName()`), calls `build()` and sends via the providers.
3. Provider selection: `MailProviderCollection::getProviderForMailType($type)`; registration via `$container->extend(MAIL_PROVIDER_COLLECTION, …)->addProvider($plugin, MailConfig::MAIL_TYPE_ALL | [types])`.
4. Project: `src/Pyz/Zed/Mail/MailDependencyProvider.php` with 19 type plugins (customer registration/password, newsletter, order confirmation/shipped, CompanyUserInvitation, CompanyStatus, AvailabilityNotification, UserPasswordReset, OrderInvoice, CustomerEmailChange, MultiFactorAuth Customer/User) and `SymfonyMailerProviderPlugin` for `MailConfig::MAIL_TYPE_ALL`.

**Mail type plugin** = class implementing `Spryker\Zed\MailExtension\Dependency\Plugin\MailTypeBuilderPluginInterface`: `getName(): string`, `build(MailTransfer): MailTransfer` (sets the subject as a glossary key, `MailTemplateTransfer` with `isHtml` true/false, `MailRecipientTransfer`). In the core, `MailTransfer` knows `type`, `sender`, `recipients`, `subject`, `templates`, `locale`, … Domain data (customer, subscriber) comes via properties that the respective module adds via `transfer.xml`.

**Templates.** Names `<module-dash-case>/mail/<name>.html.twig` or `.text.twig`, file under `<Module>/Presentation/Mail/` (example `vendor/spryker/newsletter/.../Presentation/Mail/subscribed.html.twig`). The layout `vendor/spryker/symfony-mailer/.../Presentation/SymfonyMailer/layout/mail_layout.html.twig` includes them. The project overrides via an identically named file under `src/Pyz/Zed/<Module>/Presentation/...` (evidence: `src/Pyz/Zed/CompanyMailConnector/Presentation/Mail/company_status.{html,text}.twig` renders a CMS block, → [CMS](17-cms.md)). The docs give CamelCase names (`FooBar/mail/…`), the core uses dash-case; use dash-case.

**Subject/sender.** The subject is a glossary key (`GlossaryTranslator`). If the sender is missing in the transfer: glossary keys `mail.sender.email`/`mail.sender.name` (`data/import/common/common/glossary.csv`). Global defaults `MailConstants::SENDER_EMAIL/SENDER_NAME` from `SPRYKER_MAIL_SENDER_EMAIL/NAME` (`config_default.php`, in `deploy.yml` under `regions.<R>.services.mail.sender`).

**Provider SymfonyMailer** (`spryker/symfony-mailer`): `SymfonyMailerProviderPlugin::sendMail()` → `SymfonyMailerFacade::send()` → the adapter builds `Symfony\Component\Mime\Email`, transport `EsmtpTransport(host, port, encrypted)`. Config `SymfonyMailerConstants::SMTP_HOST/PORT/ENCRYPTION/AUTH_MODE/USERNAME/PASSWORD` (env `SPRYKER_SMTP_*`).

**Testing via mail catcher.** `deploy.yml`: `mail_catcher: engine: mailpit`, endpoint `mail.spryker.local`; `config_default-docker.php` sets `MailConstants::MAILCATCHER_GUI` to `http://<HOST_ZED>:1080`, but is **not loaded** for `docker.dev` (there `config_default-docker.dev.php` applies, which sets no `MailConstants`); the value is therefore not effective for the dev environment (whether it is the same UI as the endpoint is an `[Assumption]`); CI uses `SMTP_PORT = 1025`.

**Error behavior.** `MailHandler::sendMail()` catches `Throwable` per provider, logs `Email sending failed. …`, sets `MailResponse.isSuccess=false` and, outside the CLI, shows a messenger message (`mail.error.send_failed`). **No exception is thrown**; if there is no plugin for `type`, nothing happens.

**Message Broker** (`spryker/message-broker`, `-extension`, `-aws`). A message is a transfer with the property `messageAttributes` (type `MessageAttributes`). `MessageBrokerFacade::sendMessage(TransferInterface): MessageResponseTransfer`:
1. `MessagePublisher` throws `MessageBrokerException` without `messageAttributes`, adds attributes via `MessageAttributeProviderPluginInterface` plugins;
2. `ValidationMiddlewarePlugin` validates;
3. `MessageSenderLocator` selects the transport: `MessageBrokerConstants::MESSAGE_TO_CHANNEL_MAP` (transfer class → channel), `CHANNEL_TO_SENDER_TRANSPORT_MAP` (channel → transport);
4. `MessageSenderPluginInterface` sends (here `HttpChannelMessageSenderPlugin` to `MessageBrokerAwsConstants::HTTP_CHANNEL_SENDER_BASE_URL`).

Receiving: the console command `message-broker:consume` (`MessageBrokerWorkerConsole`) reads, via `MessageReceiverPluginInterface` (`HttpChannelMessageReceiverPlugin`), the channels from `CHANNEL_TO_RECEIVER_TRANSPORT_MAP` and calls `MessageHandlerPluginInterface::handles()` (`array<string, callable>`: `[Transfer::class => callable]`).

Project wiring: `src/Pyz/Zed/MessageBroker/MessageBrokerDependencyProvider.php` (sender, receiver, 10 handlers incl. `AppConfigMessageHandlerPlugin`, `PaymentMethodMessageHandlerPlugin`, `TaxAppMessageHandlerPlugin`, `CmsPageMessageHandlerPlugin`; 6 attribute providers; `ValidationMiddlewarePlugin`; `ActiveAppFilterMessageChannelPlugin`), `MessageBrokerConfig::getDefaultWorkerChannels()` (app-events, payment-events, payment-method-commands, asset-commands, product-review-commands, search-commands, product-commands, tax-commands), `getSystemWorkerChannels()` (app-events), `MessageBrokerAwsDependencyProvider` (receiver request expander), maps in `config/Shared/config_default.php` (~lines 850-960).

**Activation.** `MessageBrokerConstants::IS_ENABLED` is only true if `SPRYKER_MESSAGE_BROKER_HTTP_CHANNEL_SENDER_BASE_URL` **and** `…RECEIVER_BASE_URL` are set; locally without ACP the broker is off (`ProductConstants::PUBLISHING_TO_MESSAGE_BROKER_ENABLED` follows the flag). Worker: Jenkins job `message-broker-consume-channels` (`config/Zed/cronjobs/jenkins.php`) only when enabled: `message-broker:consume --time-limit=15 --sleep=5`, every minute (→ [Console/Cron/Queue](22-console-cron-queue.md)).

**Apps (existing projects only).**

| Module | Purpose | Important classes |
|---|---|---|
| `kernel-app` | Store app config, HTTP requests to apps, channel filter | `KernelAppFacade::makeRequest(AcpHttpRequestTransfer)`, `writeAppConfig`, `filterMessageChannels`; `KernelAppClient::request()`; `RequestExpanderPluginInterface` |
| `payment-app` | PSP apps: pre-order payment, express checkout, payment status | `PaymentAppFacade`, OMS conditions `IsPaymentAppPaymentStatus*ConditionPlugin` |
| `tax-app` | Tax calculation via external tax app with fallback | `TaxAppFacade`, `TaxAppCalculationPlugin`, `TaxAppMessageHandlerPlugin` |
| `oauth-client`/`oauth-auth0`/`oauth-dummy` | Access token for app/broker requests | `OauthClientFacade::getAccessToken()`, `Auth0OauthAccessTokenProviderPlugin`, `DummyOauthAccessTokenProviderPlugin` |

Project files: `src/Pyz/Zed/{KernelApp,PaymentApp,TaxApp,OauthClient}/…DependencyProvider.php` (`OAuthRequestExpanderPlugin`, tax fallback calculation, Auth0/dummy provider). `Calculation/CalculationDependencyProvider` uses `TaxAppCalculationPlugin` (→ [Cart/Price/Checkout](11-cart-price-checkout.md)). OAuth config `config_default.php:938-959`; dev/CI switch to `OauthDummyConfig::PROVIDER_NAME`. ACP prerequisites: Spryker Cloud, env `SPRYKER_AOP_APPLICATION`, `SPRYKER_AOP_AUTHENTICATION`.

**External HTTP APIs.** Two evidenced patterns: (1) the ACP route via `KernelAppFacade::makeRequest(AcpHttpRequestTransfer)` (only if an ACP app exists; `AcpHttpRequestTransfer`: method, uri, headers, query, body; response `AcpHttpResponseTransfer`: httpStatusCode, content). (2) Own client with an HTTP adapter (Guzzle/Symfony HttpClient), URL/credentials from config constants, structured like `Client/KernelApp/Dependency/External/KernelAppToHttpClientAdapterInterface` (→ [Client/Storage/Search](08-client-storage-search.md)). `OauthClient` for arbitrary own APIs is an `[Assumption]` (interface `OauthAccessTokenProviderPluginInterface` exists).

## Guide

**Create a new mail type.**
1. Add transfer properties for `Mail` in the project module (→ [Transfer](04-transfer.md)), `docker/sdk cli console transfer:generate`.
2. Create the mail type plugin in `Communication/Plugin/Mail/` (example 1).
3. Templates `src/Pyz/Zed/<Module>/Presentation/Mail/<name>.{html,text}.twig`; import glossary keys per locale (→ [Data Import](09-data-import.md)).
4. Register the plugin in `Pyz\Zed\Mail\MailDependencyProvider::getMailTypeBuilderPlugins()`.
5. Trigger via `MailFacade::handleMail()` (example 2). Keep the type string in the module config; do not access a Communication plugin constant from Business (layer violation; the core does it in `CompanyStatusMailer`).
6. Clear the cache, trigger the action, check the mail in Mailpit (`http://mail.spryker.local`); on errors search the log for `Email sending failed.` (→ [Errors/Logging](23-errors-logging-debugging.md)).

**Custom mail layout.** Create `src/Pyz/Zed/SymfonyMailer/Presentation/SymfonyMailer/layout/mail_layout.html.twig`. The Pyz layout under `Mail/Mail/layout` (`src/Pyz/Zed/Mail/Presentation/Mail/layout/…`) belongs to the old path and is probably ineffective with `SymfonyMailerProviderPlugin` active `[Assumption]`, not checked with a test mail.

**Send/receive a message** (only makes sense with an ACP endpoint, see pitfalls): declare the transfer with `messageAttributes`, add it to `MESSAGE_TO_CHANNEL_MAP` and the transport maps, the handler plugin in `MessageBrokerDependencyProvider::getMessageHandlerPlugins()`, the channel in `MessageBrokerConfig::getDefaultWorkerChannels()` (example 3). For purely internal asynchrony use queue/event instead (→ [Console/Cron/Queue](22-console-cron-queue.md), [Publish & Sync](07-publish-sync.md)).

Commands `[live verified (202610.0)]` (executed; `message-broker:consume` with `--time-limit=5` exits after 5 s with exit 0, in the dev setup without messages):
```
docker/sdk cli console list                         # then filter for broker|mail|oauth
docker/sdk cli console message-broker:consume
docker/sdk cli console message-broker:consume app-events --time-limit=15 --sleep=5 --message-limit=10 --failure-limit=3
docker/sdk cli console message-broker:debug          # dev, registered, but exit 255: `Class SprykerSdk\AsyncApi\AsyncApi\Loader\AsyncApiLoader not found` (package `spryker-sdk/async-api` not installed)
docker/sdk cli console transfer:generate
docker/sdk cli console cache:empty-all
```
Options of `message-broker:consume`: `-l/--message-limit`, `-f/--failure-limit`, `-m/--memory-limit`, `-t/--time-limit`, `-s/--sleep`. `MessageBrokerDebugConsole` and the AWS console commands (`message-broker:aws-sns:create-topics`, `…aws-sqs:create-queues`, `…aws-sqs:subscribe-sqs-to-sns`) are registered in the `ConsoleDependencyProvider` only in the dev branch `[Assumption]` regarding the condition.

## Examples

**1. Mail type plugin ProductBadge** (`src/Pyz/Zed/ProductBadge/Communication/Plugin/Mail/ProductBadgeAssignedMailTypeBuilderPlugin.php`; pattern `NewsletterSubscribedMailTypeBuilderPlugin`, classes and interface checked against vendor; not executed). Prerequisite: `product_badge.transfer.xml` extended:
```xml
<transfer name="Mail">
    <property name="customer" type="Customer"/>
    <property name="productBadge" type="ProductBadge"/>
</transfer>
```
```php
<?php

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadge\Communication\Plugin\Mail;

use Generated\Shared\Transfer\MailRecipientTransfer;
use Generated\Shared\Transfer\MailTemplateTransfer;
use Generated\Shared\Transfer\MailTransfer;
use Spryker\Zed\Kernel\Communication\AbstractPlugin;
use Spryker\Zed\MailExtension\Dependency\Plugin\MailTypeBuilderPluginInterface;

class ProductBadgeAssignedMailTypeBuilderPlugin extends AbstractPlugin implements MailTypeBuilderPluginInterface
{
    public const string MAIL_TYPE = 'product badge assigned mail';

    protected const string MAIL_TEMPLATE_HTML = 'product-badge/mail/assigned.html.twig';

    protected const string MAIL_TEMPLATE_TEXT = 'product-badge/mail/assigned.text.twig';

    protected const string GLOSSARY_KEY_MAIL_SUBJECT = 'mail.product_badge.assigned.subject';

    public function getName(): string
    {
        return static::MAIL_TYPE;
    }

    public function build(MailTransfer $mailTransfer): MailTransfer
    {
        $customerTransfer = $mailTransfer->getCustomerOrFail();

        return $mailTransfer
            ->setSubject(static::GLOSSARY_KEY_MAIL_SUBJECT)
            ->addTemplate((new MailTemplateTransfer())->setName(static::MAIL_TEMPLATE_HTML)->setIsHtml(true))
            ->addTemplate((new MailTemplateTransfer())->setName(static::MAIL_TEMPLATE_TEXT)->setIsHtml(false))
            ->addRecipient(
                (new MailRecipientTransfer())
                    ->setEmail($customerTransfer->getEmailOrFail())
                    ->setName(trim($customerTransfer->getFirstName() . ' ' . $customerTransfer->getLastName())),
            );
    }
}
```
Template `src/Pyz/Zed/ProductBadge/Presentation/Mail/assigned.html.twig`: `<p>{{ 'mail.product_badge.assigned.text' | trans }}</p>`; `assigned.text.twig` likewise. Import glossary keys `mail.product_badge.assigned.subject`/`.text` per locale.

**2. Registration and triggering.** Add `new ProductBadgeAssignedMailTypeBuilderPlugin()` to `Pyz\Zed\Mail\MailDependencyProvider::getMailTypeBuilderPlugins()` (release pattern for provider registration):
```php
$container->extend(self::MAIL_PROVIDER_COLLECTION, function (MailProviderCollectionAddInterface $mailProviderCollection) {
    $mailProviderCollection->addProvider(new SymfonyMailerProviderPlugin(), [MailConfig::MAIL_TYPE_ALL]);

    return $mailProviderCollection;
});
```
Call from the business model (Facade via DependencyProvider, no bridge in the project, → [Coding guidelines](27-coding-guidelines.md)):
```php
$this->mailFacade->handleMail(
    (new MailTransfer())
        ->setType(ProductBadgeConfig::MAIL_TYPE_PRODUCT_BADGE_ASSIGNED)
        ->setCustomer($customerTransfer)
        ->setProductBadge($productBadgeTransfer)
        ->setLocale($customerTransfer->getLocale()),
);
```

**3. Message handler** (`MessageHandlerPluginInterface::handles()` verified; transfer `ProductBadgeCreated` with `messageAttributes` analogous to `ConfigureTaxApp` in `vendor/spryker/tax-app/.../tax_app.transfer.xml`):
```php
class ProductBadgeMessageHandlerPlugin extends AbstractPlugin implements MessageHandlerPluginInterface
{
    public function onProductBadgeCreated(ProductBadgeCreatedTransfer $transfer): void
    {
        // $this->getFacade()->...
    }

    public function handles(): iterable
    {
        return [ProductBadgeCreatedTransfer::class => [$this, 'onProductBadgeCreated']];
    }
}
```
Config: `$config[MessageBrokerConstants::MESSAGE_TO_CHANNEL_MAP][ProductBadgeCreatedTransfer::class] = 'product-badge-events';` plus transport maps.

## Pitfalls

- Type string ≠ the plugin's `getName()` → silently does nothing, no error.
- Missing glossary key: the key itself is sent as the subject.
- `getXOrFail()` in `build()` throws; `MailHandler` only logs, the mail is lost.
- Template not found: path convention `<module-dash>/mail/…` and `Presentation/Mail/`, clear the cache.
- Layout in the wrong place (see Guide).
- Deprecated: `MailConstants::SMTP_*`, `MailConfig::getSmtp*()` (→ `SymfonyMailerConstants`/`SymfonyMailerConfig`). `config_default-docker.php:297-298` still sets `MailConstants::SMTP_*`, but the file is not loaded for `docker.dev`; what takes effect is `SymfonyMailerConstants` from `config_default.php` (lines 622-627, same env variables). SwiftMailer has been removed (`SwiftMailerRemovedException`).
- Broker inactive locally without both `SPRYKER_MESSAGE_BROKER_HTTP_CHANNEL_*_BASE_URL`; then the Jenkins job is missing as well.
- Message without `messageAttributes` → `MessageBrokerException`; unknown channel (not in the maps) → nothing sent/read; `ActiveAppFilterMessageChannelPlugin` filters to active apps.
- The only configured transport is `HTTP_CHANNEL_TRANSPORT` to the ACP event platform endpoint; a purely project-internal message has no receiver there. SQS/SNS plugins in `message-broker-aws` are not wired `[Assumption]`, not checked.
- Never change anything in `vendor/`; only Pyz override of the same template name, new plugins, configuration. Upgrader-hostile: changing plugin interface methods, renaming core DependencyProvider constants, accessing `Business` classes of other modules instead of the Facade.
- OAuth secrets only from env; `MessageAttributes::authorization` is marked as sensitive.

## Checklist

- [ ] Mail type plugin registered in `getMailTypeBuilderPlugins()`, `getName()` = `MailTransfer::type`
- [ ] Templates (html + text) under `Presentation/Mail/`, dash-case names
- [ ] Glossary keys (subject, texts) imported for all locales
- [ ] Mail test in Mailpit; error log checked
- [ ] No new ACP project set up (sunset)
- [ ] Broker: transfer in `MESSAGE_TO_CHANNEL_MAP`, channel in transport maps and worker channels, activation variables known
- [ ] No bridge in the project module, Facade via DependencyProvider

## Sources

Docs (base https://docs.spryker.com/docs/): `pbc/all/emails/latest/howto-create-and-register-a-mail-type-builder-plugin.html` (2021), `…/howto-create-and-register-a-mail-provider.html` (2021), `…/emails.html`, `…/install-the-mailing-and-notifications-feature.html` (content not evaluated), `dg/dev/acp/app-composition-platform.html` (sunset), `dg/dev/acp/receive-acp-messages.html`, `dg/dev/acp/install-prerequisites-and-enable-acp.html`. A docs page "message-broker" under `dg/dev/acp/` does not exist (404).

Code: `vendor/spryker/mail`, `mail-extension`, `symfony-mailer`, `newsletter`, `company-mail-connector`, `vendor/spryker/oms/src/Spryker/Zed/Oms/Business/Mail/MailHandler.php`, `vendor/spryker/message-broker`, `message-broker-extension`, `message-broker-aws`, `kernel-app`, `payment-app`, `tax-app`, `oauth-client`; `src/Pyz/Zed/{Mail,MessageBroker,MessageBrokerAws,KernelApp,PaymentApp,TaxApp,OauthClient,CompanyMailConnector,Console}/`; `config/Shared/config_default.php`, `config_default-docker.dev.php`, `config_default-docker.php`, `config/Zed/cronjobs/jenkins.php`, `deploy.yml`.

References: [Transfer](04-transfer.md), [Data Import](09-data-import.md), [OMS](10-oms-state-machine.md), [Errors/Logging](23-errors-logging-debugging.md), [Console/Cron/Queue](22-console-cron-queue.md).

Deprecations (code): `MailTypePluginInterface`/`MAIL_TYPE_COLLECTION`/`MailBuilder` → `MailTypeBuilderPluginInterface`; `Mail\…\MailProviderPlugin` → `SymfonyMailerProviderPlugin`; `MailFacade::sendMail()` → `SymfonyMailerFacade::send()`; `MailRecipientTransfer::type` without replacement; `MessageBrokerConfig::getChannelToTransportMap()` → `getChannelToSenderTransportMap()`; `MessageAttributes::transferName/event/emitter/publisher` → `name`, `actorId`; `PaymentOperationsMessageHandlerPlugin` contains `@deprecated` BC methods; ACP as a whole is discontinued.
