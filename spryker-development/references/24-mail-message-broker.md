# Mail, Message Broker und App-Integrationen (Recherche T-24)

> Status: Release 202610.0 (spryker/kernel 3.87.1, spryker/oms 11.55.0). Code (`vendor/`, `src/Pyz`, `config/`) gelesen, `[code-verifiziert, Doku nicht abgeglichen]` für alles außer den im Abschnitt Quellen genannten ACP-Seiten (laut Recherche abgerufen, Stand 02/2026 bzw. 07/2025). Mail-Doku-Seiten (Stand 2021) sind veraltet, der Code gilt. Konsolenbefehle ausgeführt `[live verifiziert (202610.0)]` (Ausnahme `message-broker:debug`, siehe Befehlsliste). Umgebungsdatei bei `docker.dev` ist `config_default-docker.dev.php`; `config_default-docker.php` wird dort nicht geladen (Config-Kette: `config_default.php`, dann `config_default-<Umgebungsname>.php`, siehe `Spryker\Shared\Config\Config::init()`). Template-Namensauflösung `newsletter/...` → `Newsletter/Presentation` nur an Beispielen belegt `[Annahme]`.

## Zweck

Mails über `MailFacade` versenden (Mail-Typ-Plugin, Templates, Provider), Message-Broker-Nachrichten verstehen (Kanäle, Handler, Transport) und einordnen, was KernelApp/PaymentApp/TaxApp im Projekt tun. Wichtig vorab: **ACP wird laut Doku abgeschaltet** („is being sunset. No additional activations or changes are allowed“, `dg/dev/acp/app-composition-platform.html`, Stand 25.02.2026). ACP nur bei Bestandsprojekten erwähnen, für neue Integrationen nicht empfehlen. Der Message-Broker selbst wird auch ohne ACP für CMS-/Produkt-/Order-Events genutzt.

## Konzepte

**Mailfluss.**
1. Ein Business-Modell ruft `Spryker\Zed\Mail\Business\MailFacade::handleMail(MailTransfer)` auf (Signatur ohne Rückgabetyp im Interface) und setzt vorher `MailTransfer::type` (String).
2. `Spryker\Zed\Mail\Business\Model\Mailer\MailHandler::handleMail()` sucht in den Mail-Typ-Plugins (`getMailTypeBuilderPlugins()`) das Plugin, dessen `getName()` gleich `type` ist, setzt Default-Sender (`MailConfig::getSenderEmail()/getSenderName()`), ruft `build()` und sendet über die Provider.
3. Provider-Auswahl: `MailProviderCollection::getProviderForMailType($type)`; Registrierung per `$container->extend(MAIL_PROVIDER_COLLECTION, …)->addProvider($plugin, MailConfig::MAIL_TYPE_ALL | [typen])`.
4. Projekt: `src/Pyz/Zed/Mail/MailDependencyProvider.php` mit 19 Typ-Plugins (Customer-Registrierung/Passwort, Newsletter, Order-Confirmation/Shipped, CompanyUserInvitation, CompanyStatus, AvailabilityNotification, UserPasswordReset, OrderInvoice, CustomerEmailChange, MultiFactorAuth Customer/User) und `SymfonyMailerProviderPlugin` für `MailConfig::MAIL_TYPE_ALL`.

**Mail-Typ-Plugin** = Klasse mit `Spryker\Zed\MailExtension\Dependency\Plugin\MailTypeBuilderPluginInterface`: `getName(): string`, `build(MailTransfer): MailTransfer` (setzt Subject als Glossar-Key, `MailTemplateTransfer` mit `isHtml` true/false, `MailRecipientTransfer`). `MailTransfer` kennt im Core `type`, `sender`, `recipients`, `subject`, `templates`, `locale`, … Fachdaten (Customer, Subscriber) kommen über Properties, die das jeweilige Modul per `transfer.xml` ergänzt.

**Templates.** Namen `<modul-dash-case>/mail/<name>.html.twig` bzw. `.text.twig`, Datei unter `<Modul>/Presentation/Mail/` (Beispiel `vendor/spryker/newsletter/.../Presentation/Mail/subscribed.html.twig`). Das Layout `vendor/spryker/symfony-mailer/.../Presentation/SymfonyMailer/layout/mail_layout.html.twig` bindet sie ein. Projekt überschreibt durch gleich benannte Datei unter `src/Pyz/Zed/<Modul>/Presentation/...` (Beleg: `src/Pyz/Zed/CompanyMailConnector/Presentation/Mail/company_status.{html,text}.twig` rendert einen CMS-Block, → [CMS](17-cms.md)). Die Doku nennt CamelCase-Namen (`FooBar/mail/…`), der Core nutzt dash-case; dash-case verwenden.

**Subject/Absender.** Subject ist ein Glossar-Key (`GlossaryTranslator`). Fehlt der Sender im Transfer: Glossar-Keys `mail.sender.email`/`mail.sender.name` (`data/import/common/common/glossary.csv`). Globale Defaults `MailConstants::SENDER_EMAIL/SENDER_NAME` aus `SPRYKER_MAIL_SENDER_EMAIL/NAME` (`config_default.php`, in `deploy.yml` unter `regions.<R>.services.mail.sender`).

**Provider SymfonyMailer** (`spryker/symfony-mailer`): `SymfonyMailerProviderPlugin::sendMail()` → `SymfonyMailerFacade::send()` → Adapter baut `Symfony\Component\Mime\Email`, Transport `EsmtpTransport(host, port, encrypted)`. Config `SymfonyMailerConstants::SMTP_HOST/PORT/ENCRYPTION/AUTH_MODE/USERNAME/PASSWORD` (Env `SPRYKER_SMTP_*`).

**Test per Mail-Catcher.** `deploy.yml`: `mail_catcher: engine: mailpit`, Endpoint `mail.spryker.local`; `config_default-docker.php` setzt `MailConstants::MAILCATCHER_GUI` auf `http://<HOST_ZED>:1080`, wird aber bei `docker.dev` **nicht geladen** (dort gilt `config_default-docker.dev.php`, das keine `MailConstants` setzt); der Wert ist für die Dev-Umgebung also nicht wirksam (ob dasselbe UI wie der Endpoint, ist `[Annahme]`); CI nutzt `SMTP_PORT = 1025`.

**Fehlerverhalten.** `MailHandler::sendMail()` fängt `Throwable` je Provider, loggt `Email sending failed. …`, setzt `MailResponse.isSuccess=false` und zeigt außerhalb der CLI eine Messenger-Meldung (`mail.error.send_failed`). Es wird **keine Exception geworfen**; fehlt ein Plugin für `type`, passiert nichts.

**Message Broker** (`spryker/message-broker`, `-extension`, `-aws`). Eine Nachricht ist ein Transfer mit Property `messageAttributes` (Typ `MessageAttributes`). `MessageBrokerFacade::sendMessage(TransferInterface): MessageResponseTransfer`:
1. `MessagePublisher` wirft `MessageBrokerException` ohne `messageAttributes`, ergänzt Attribute über `MessageAttributeProviderPluginInterface`-Plugins;
2. `ValidationMiddlewarePlugin` validiert;
3. `MessageSenderLocator` wählt den Transport: `MessageBrokerConstants::MESSAGE_TO_CHANNEL_MAP` (Transfer-Klasse → Channel), `CHANNEL_TO_SENDER_TRANSPORT_MAP` (Channel → Transport);
4. `MessageSenderPluginInterface` sendet (hier `HttpChannelMessageSenderPlugin` an `MessageBrokerAwsConstants::HTTP_CHANNEL_SENDER_BASE_URL`).

Empfang: Konsole `message-broker:consume` (`MessageBrokerWorkerConsole`) liest per `MessageReceiverPluginInterface` (`HttpChannelMessageReceiverPlugin`) die Channels aus `CHANNEL_TO_RECEIVER_TRANSPORT_MAP` und ruft `MessageHandlerPluginInterface::handles()` (`array<string, callable>`: `[Transfer::class => callable]`).

Projektverdrahtung: `src/Pyz/Zed/MessageBroker/MessageBrokerDependencyProvider.php` (Sender, Receiver, 10 Handler u. a. `AppConfigMessageHandlerPlugin`, `PaymentMethodMessageHandlerPlugin`, `TaxAppMessageHandlerPlugin`, `CmsPageMessageHandlerPlugin`; 6 Attribute-Provider; `ValidationMiddlewarePlugin`; `ActiveAppFilterMessageChannelPlugin`), `MessageBrokerConfig::getDefaultWorkerChannels()` (app-events, payment-events, payment-method-commands, asset-commands, product-review-commands, search-commands, product-commands, tax-commands), `getSystemWorkerChannels()` (app-events), `MessageBrokerAwsDependencyProvider` (Receiver-Request-Expander), Maps in `config/Shared/config_default.php` (~Z. 850-960).

**Aktivierung.** `MessageBrokerConstants::IS_ENABLED` ist nur true, wenn `SPRYKER_MESSAGE_BROKER_HTTP_CHANNEL_SENDER_BASE_URL` **und** `…RECEIVER_BASE_URL` gesetzt sind; lokal ohne ACP ist der Broker aus (`ProductConstants::PUBLISHING_TO_MESSAGE_BROKER_ENABLED` folgt dem Flag). Worker: Jenkins-Job `message-broker-consume-channels` (`config/Zed/cronjobs/jenkins.php`) nur wenn aktiviert: `message-broker:consume --time-limit=15 --sleep=5`, jede Minute (→ [Console/Cron/Queue](22-console-cron-queue.md)).

**Apps (nur Bestandsprojekte).**

| Modul | Zweck | Wichtige Klassen |
|---|---|---|
| `kernel-app` | App-Config speichern, HTTP-Requests an Apps, Kanalfilter | `KernelAppFacade::makeRequest(AcpHttpRequestTransfer)`, `writeAppConfig`, `filterMessageChannels`; `KernelAppClient::request()`; `RequestExpanderPluginInterface` |
| `payment-app` | PSP-Apps: Pre-Order-Payment, Express-Checkout, Payment-Status | `PaymentAppFacade`, OMS-Conditions `IsPaymentAppPaymentStatus*ConditionPlugin` |
| `tax-app` | Steuerberechnung über externe Tax-App mit Fallback | `TaxAppFacade`, `TaxAppCalculationPlugin`, `TaxAppMessageHandlerPlugin` |
| `oauth-client`/`oauth-auth0`/`oauth-dummy` | Access-Token für App-/Broker-Requests | `OauthClientFacade::getAccessToken()`, `Auth0OauthAccessTokenProviderPlugin`, `DummyOauthAccessTokenProviderPlugin` |

Projektdateien: `src/Pyz/Zed/{KernelApp,PaymentApp,TaxApp,OauthClient}/…DependencyProvider.php` (`OAuthRequestExpanderPlugin`, Tax-Fallback-Kalkulation, Auth0/Dummy-Provider). `Calculation/CalculationDependencyProvider` nutzt `TaxAppCalculationPlugin` (→ [Cart/Preis/Checkout](11-cart-preis-checkout.md)). OAuth-Config `config_default.php:938-959`; Dev/CI stellen auf `OauthDummyConfig::PROVIDER_NAME` um. Voraussetzung ACP: Spryker Cloud, Env `SPRYKER_AOP_APPLICATION`, `SPRYKER_AOP_AUTHENTICATION`.

**Externe HTTP-APIs.** Zwei belegte Muster: (1) ACP-Weg über `KernelAppFacade::makeRequest(AcpHttpRequestTransfer)` (nur wenn eine ACP-App existiert; `AcpHttpRequestTransfer`: method, uri, headers, query, body; Antwort `AcpHttpResponseTransfer`: httpStatusCode, content). (2) Eigener Client mit HTTP-Adapter (Guzzle/Symfony HttpClient), URL/Credentials aus Config-Konstanten, Aufbau nach `Client/KernelApp/Dependency/External/KernelAppToHttpClientAdapterInterface` (→ [Client/Storage/Search](08-client-storage-search.md)). `OauthClient` für beliebige eigene APIs ist `[Annahme]` (Interface `OauthAccessTokenProviderPluginInterface` existiert).

## Anleitung

**Neuen Mail-Typ anlegen.**
1. Transfer-Properties für `Mail` im Projektmodul ergänzen (→ [Transfer](04-transfer.md)), `docker/sdk cli console transfer:generate`.
2. Mail-Typ-Plugin in `Communication/Plugin/Mail/` anlegen (Beispiel 1).
3. Templates `src/Pyz/Zed/<Modul>/Presentation/Mail/<name>.{html,text}.twig`; Glossar-Keys je Locale importieren (→ [Data Import](09-data-import.md)).
4. Plugin in `Pyz\Zed\Mail\MailDependencyProvider::getMailTypeBuilderPlugins()` eintragen.
5. Auslösen per `MailFacade::handleMail()` (Beispiel 2). Den Typ-String in der Modul-Config halten, nicht aus Business auf eine Communication-Plugin-Konstante zugreifen (Schichtverstoß; der Core tut es in `CompanyStatusMailer`).
6. Cache leeren, Aktion auslösen, Mail im Mailpit prüfen (`http://mail.spryker.local`), bei Fehlern `Email sending failed.` im Log suchen (→ [Fehler/Logging](23-fehler-logging-debugging.md)).

**Eigenes Mail-Layout.** `src/Pyz/Zed/SymfonyMailer/Presentation/SymfonyMailer/layout/mail_layout.html.twig` anlegen. Das Pyz-Layout unter `Mail/Mail/layout` (`src/Pyz/Zed/Mail/Presentation/Mail/layout/…`) gehört zum alten Pfad und ist bei aktivem `SymfonyMailerProviderPlugin` vermutlich wirkungslos `[Annahme]`, nicht per Testmail geprüft.

**Message senden/empfangen** (nur sinnvoll mit ACP-Endpunkt, siehe Fallstricke): Transfer mit `messageAttributes` deklarieren, in `MESSAGE_TO_CHANNEL_MAP` und Transport-Maps eintragen, Handler-Plugin in `MessageBrokerDependencyProvider::getMessageHandlerPlugins()`, Channel in `MessageBrokerConfig::getDefaultWorkerChannels()` (Beispiel 3). Für rein interne Asynchronität stattdessen Queue/Event (→ [Console/Cron/Queue](22-console-cron-queue.md), [Publish & Sync](07-publish-sync.md)).

Befehle `[live verifiziert (202610.0)]` (ausgeführt; `message-broker:consume` mit `--time-limit=5` beendet sich nach 5 s mit Exit 0, im Dev-Setup ohne Nachrichten):
```
docker/sdk cli console list                         # dann nach broker|mail|oauth filtern
docker/sdk cli console message-broker:consume
docker/sdk cli console message-broker:consume app-events --time-limit=15 --sleep=5 --message-limit=10 --failure-limit=3
docker/sdk cli console message-broker:debug          # Dev, registriert, aber Exit 255: `Class SprykerSdk\AsyncApi\AsyncApi\Loader\AsyncApiLoader not found` (Paket `spryker-sdk/async-api` nicht installiert)
docker/sdk cli console transfer:generate
docker/sdk cli console cache:empty-all
```
Optionen von `message-broker:consume` laut Recherche: `-l/--message-limit`, `-f/--failure-limit`, `-m/--memory-limit`, `-t/--time-limit`, `-s/--sleep`. `MessageBrokerDebugConsole` und die AWS-Konsolen (`message-broker:aws-sns:create-topics`, `…aws-sqs:create-queues`, `…aws-sqs:subscribe-sqs-to-sns`) sind im `ConsoleDependencyProvider` nur im Dev-Zweig registriert `[Annahme]` zur Bedingung.

## Beispiele

**1. Mail-Typ-Plugin ProductBadge** (`src/Pyz/Zed/ProductBadge/Communication/Plugin/Mail/ProductBadgeAssignedMailTypeBuilderPlugin.php`; Muster `NewsletterSubscribedMailTypeBuilderPlugin`, Klassen und Interface gegen vendor geprüft; nicht ausgeführt). Voraussetzung `product_badge.transfer.xml` ergänzt:
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
Template `src/Pyz/Zed/ProductBadge/Presentation/Mail/assigned.html.twig`: `<p>{{ 'mail.product_badge.assigned.text' | trans }}</p>`; `assigned.text.twig` analog. Glossar-Keys `mail.product_badge.assigned.subject`/`.text` je Locale importieren.

**2. Registrierung und Auslösen.** `Pyz\Zed\Mail\MailDependencyProvider::getMailTypeBuilderPlugins()` um `new ProductBadgeAssignedMailTypeBuilderPlugin()` ergänzen (Release-Muster Provider-Registrierung):
```php
$container->extend(self::MAIL_PROVIDER_COLLECTION, function (MailProviderCollectionAddInterface $mailProviderCollection) {
    $mailProviderCollection->addProvider(new SymfonyMailerProviderPlugin(), [MailConfig::MAIL_TYPE_ALL]);

    return $mailProviderCollection;
});
```
Aufruf aus dem Business-Modell (Facade via DependencyProvider, keine Bridge im Projekt, → [Coding-Guidelines](27-coding-guidelines.md)):
```php
$this->mailFacade->handleMail(
    (new MailTransfer())
        ->setType(ProductBadgeConfig::MAIL_TYPE_PRODUCT_BADGE_ASSIGNED)
        ->setCustomer($customerTransfer)
        ->setProductBadge($productBadgeTransfer)
        ->setLocale($customerTransfer->getLocale()),
);
```

**3. Message-Handler** (Muster aus T-24, `MessageHandlerPluginInterface::handles()` verifiziert; Transfer `ProductBadgeCreated` mit `messageAttributes` analog `ConfigureTaxApp` in `vendor/spryker/tax-app/.../tax_app.transfer.xml`):
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
Config: `$config[MessageBrokerConstants::MESSAGE_TO_CHANNEL_MAP][ProductBadgeCreatedTransfer::class] = 'product-badge-events';` plus Transport-Maps.

## Fallstricke

- Typ-String ≠ `getName()` des Plugins → stilles Nichtstun ohne Fehler.
- Fehlender Glossar-Key: es wird der Key selbst als Subject gesendet.
- `getXOrFail()` im `build()` wirft; `MailHandler` loggt nur, die Mail geht verloren.
- Template nicht gefunden: Pfadkonvention `<modul-dash>/mail/…` und `Presentation/Mail/`, Cache leeren.
- Layout am falschen Ort (siehe Anleitung).
- Deprecated: `MailConstants::SMTP_*`, `MailConfig::getSmtp*()` (→ `SymfonyMailerConstants`/`SymfonyMailerConfig`). `config_default-docker.php:297-298` setzt weiterhin `MailConstants::SMTP_*`, die Datei wird bei `docker.dev` aber nicht geladen; wirksam ist `SymfonyMailerConstants` aus `config_default.php` (Z. 622-627, gleiche Env-Variablen). SwiftMailer ist entfernt (`SwiftMailerRemovedException`).
- Broker lokal inaktiv ohne beide `SPRYKER_MESSAGE_BROKER_HTTP_CHANNEL_*_BASE_URL`; dann fehlt auch der Jenkins-Job.
- Message ohne `messageAttributes` → `MessageBrokerException`; unbekannter Channel (nicht in Maps) → nichts gesendet/gelesen; `ActiveAppFilterMessageChannelPlugin` filtert auf aktive Apps.
- Einziger konfigurierter Transport ist `HTTP_CHANNEL_TRANSPORT` zum ACP-Event-Platform-Endpoint; eine rein projektinterne Message hat dort keinen Empfänger. SQS/SNS-Plugins in `message-broker-aws` sind nicht verdrahtet `[Annahme]`, nicht geprüft.
- Nie in `vendor/` ändern; nur Pyz-Override desselben Template-Namens, neue Plugins, Konfiguration. Upgrader-feindlich: Plugin-Interface-Methoden ändern, Core-DependencyProvider-Konstanten umbenennen, auf `Business`-Klassen anderer Module statt Facade zugreifen.
- OAuth-Secrets nur aus Env; `MessageAttributes::authorization` ist als sensibel markiert.

## Checkliste

- [ ] Mail-Typ-Plugin in `getMailTypeBuilderPlugins()` registriert, `getName()` = `MailTransfer::type`
- [ ] Templates (html + text) unter `Presentation/Mail/`, dash-case-Namen
- [ ] Glossar-Keys (Subject, Texte) für alle Locales importiert
- [ ] Mailtest im Mailpit; Fehlerlog geprüft
- [ ] Kein ACP-Neuprojekt aufgesetzt (Sunset)
- [ ] Broker: Transfer in `MESSAGE_TO_CHANNEL_MAP`, Channel in Transport-Maps und Worker-Channels, Aktivierungsvariablen bekannt
- [ ] Keine Bridge im Projektmodul, Facade über DependencyProvider

## Quellen

Doku (Basis https://docs.spryker.com/docs/): `pbc/all/emails/latest/howto-create-and-register-a-mail-type-builder-plugin.html` (2021), `…/howto-create-and-register-a-mail-provider.html` (2021), `…/emails.html`, `…/install-the-mailing-and-notifications-feature.html` (Inhalt nicht ausgewertet), `dg/dev/acp/app-composition-platform.html` (Sunset), `dg/dev/acp/receive-acp-messages.html`, `dg/dev/acp/install-prerequisites-and-enable-acp.html`. Eine Doku-Seite „message-broker“ unter `dg/dev/acp/` existiert nicht (404).

Code: `vendor/spryker/mail`, `mail-extension`, `symfony-mailer`, `newsletter`, `company-mail-connector`, `vendor/spryker/oms/src/Spryker/Zed/Oms/Business/Mail/MailHandler.php`, `vendor/spryker/message-broker`, `message-broker-extension`, `message-broker-aws`, `kernel-app`, `payment-app`, `tax-app`, `oauth-client`; `src/Pyz/Zed/{Mail,MessageBroker,MessageBrokerAws,KernelApp,PaymentApp,TaxApp,OauthClient,CompanyMailConnector,Console}/`; `config/Shared/config_default.php`, `config_default-docker.dev.php`, `config_default-docker.php`, `config/Zed/cronjobs/jenkins.php`, `deploy.yml`.

Verweise: [Transfer](04-transfer.md), [Data Import](09-data-import.md), [OMS](10-oms-state-machine.md), [Fehler/Logging](23-fehler-logging-debugging.md), [Console/Cron/Queue](22-console-cron-queue.md).

Deprecations (Code): `MailTypePluginInterface`/`MAIL_TYPE_COLLECTION`/`MailBuilder` → `MailTypeBuilderPluginInterface`; `Mail\…\MailProviderPlugin` → `SymfonyMailerProviderPlugin`; `MailFacade::sendMail()` → `SymfonyMailerFacade::send()`; `MailRecipientTransfer::type` ohne Ersatz; `MessageBrokerConfig::getChannelToTransportMap()` → `getChannelToSenderTransportMap()`; `MessageAttributes::transferName/event/emitter/publisher` → `name`, `actorId`; `PaymentOperationsMessageHandlerPlugin` enthält `@deprecated` BC-Methoden; ACP insgesamt abgekündigt.
