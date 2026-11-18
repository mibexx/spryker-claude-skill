# Konsolenkommandos, Cronjobs (Scheduler/Jenkins) und Queue-Worker (Recherche T-22)

> Status: Release 202610.0 (spryker/kernel 3.87.1, Symfony 6.4.48). Verifiziert am laufenden Projekt (Container `spryker_b2b_dev_*`, Environment `docker.dev`, Regionen EU und US): alle Befehle unten wurden ausgeführt `[live verifiziert (202610.0)]` (Protokoll `live-verification.md`), einschließlich `scheduler:suspend/resume/setup/clean`, `queue:setup`, `queue:worker:start --stop-when-empty`, `queue:dump`, `multi-process:run`; Lesezugriff auf `/var/jenkins_home/jobs` im `scheduler`-Container. Doku-Seiten waren nicht auswertbar (nur Navigation); Aussagen stammen aus dem Code `[code-verifiziert, Doku nicht abgeglichen]`.

## Zweck
Eigene Konsolenkommandos schreiben und registrieren, wiederkehrende Jobs über `config/Zed/cronjobs/jenkins.php` und den Scheduler definieren, Queue-Worker verstehen und eigene Queues samt Prozessor-Plugin anlegen. Abgrenzung: P&S-Queue-Semantik: [07-publish-sync](07-publish-sync.md); Docker/Install-Recipes: [30-docker-sdk-workflow](30-docker-sdk-workflow.md); Konfiguration: [06-konfiguration](06-konfiguration.md); Logging/Monitoring: [23-fehler-logging-debugging](23-fehler-logging-debugging.md); `message-broker:consume`: [24-mail-message-broker](24-mail-message-broker.md).

## Konzepte

### Konsolenkommando
- Klasse `src/Pyz/Zed/<Modul>/Communication/Console/<Name>Console.php`, Basis `Spryker\Zed\Kernel\Communication\Console\Console` (erbt von Symfony `Command`). Löst per Konvention `getFacade()`, `getFactory()`, `getRepository()` auf; keine Logik in der Console, nur Facade-Aufruf.
- `configure()`: `setName()` (Format `<modul>:<aktion>`), `setDescription()`, dann `parent::configure()`. `execute(InputInterface $input, OutputInterface $output): int` liefert `static::CODE_SUCCESS` / `static::CODE_ERROR` (`public const int`, nachgeprüft). Ausgabehilfe `$this->info(array|string $message, bool $wrapInInfoTags = true)`.
- Registrierung **explizit**, kein Auto-Discovery: `src/Pyz/Zed/Console/ConsoleDependencyProvider.php::getConsoleCommands(Container $container): array` (168 Einträge im Basis-Array, dazu 44 im Dev-Block = 212, plus die Propel-Kommandos aus `propel()->facade()->getConsoleCommands()`). Entwicklungs-Kommandos (Sniffer, Codeception, IDE-Autocompletion, ...) stehen im Block `if ($this->getConfig()->isDevelopmentConsoleCommandsEnabled())`; Flag aus `Pyz\Zed\Console\ConsoleConfig` / `Pyz\Shared\Console\ConsoleConstants::ENABLE_DEVELOPMENT_CONSOLE_COMMANDS`.
- Weitere Extension Points im selben Provider: `getConsolePostRunHookPlugins()` (Pyz: `EventBehaviorPostHookPlugin` triggert nach jedem Kommando gesammelte Events), `getConsolePreRunHookPlugins()` (`ConsolePreRunHookPluginInterface`), `getApplicationPlugins()` (`ConsoleLocaleApplicationPlugin`, `ConsoleSecurityApplicationPlugin`, `PropelApplicationPlugin`, `TwigApplicationPlugin`, `FormApplicationPlugin`), `getEventSubscriber()` (`MonitoringConsolePlugin`, `DirectSynchronizationConsolePlugin`).
- Mehrere Namen für dieselbe Klasse über Konstruktor-Argument (`new DataImportConsole(DataImportConsole::DEFAULT_NAME . ':' . $importType)`, [09-data-import](09-data-import.md)).
- Aufruf: Zed `vendor/bin/console <name>`, Glue `vendor/bin/glue <name>` (`vendor/bin` enthält `console`, `glue`, `yves`); Kurzform `docker/sdk console <name>`. Globale Optionen jedes Kommandos: `--no-pre`, `--no-post`, `--repeatable[=N]`, `-max/--max-duration`, `-min/--min-duration`, `--profile`.

### Cronjobs: `jenkins.php` und Scheduler
- Jobs in `config/Zed/cronjobs/jenkins.php` (Dateiname = Scheduler-ID, `SchedulerConfig::getPhpSchedulerReaderPath()`); per `include` eingelesen, füllt `$jobs[]`.
- Job-Schlüssel (`vendor/spryker/scheduler/.../PhpScheduleReader/Mapper/PhpScheduleMapper.php`):

| Schlüssel | Bedeutung |
|---|---|
| `name` | Pflicht, keine Leerzeichen/URL-Sonderzeichen; Jenkins-Jobname `<REGION>_<STORE>_<name>` (Region nur bei Dynamic Store Mode); live z. B. `EU_check-oms-timeouts` |
| `command` | `$PHP_BIN vendor/bin/console ...` (Glue: `vendor/bin/glue`) |
| `schedule` | Cron-Ausdruck |
| `enable` | `true/false`, Default `false` |
| `stores` | optionale Store-Liste; ohne `stores` und ohne `global` entsteht bei **Dynamic Store** ein Job **je Region** (live `EU_<job>`, nicht je Store), ohne Dynamic Store je Store (`PhpScheduleMapper::isDynamicStoreEnabled()`/`getStoreList()`, beide BC-deprecated) |
| `global` | `true` = ein Job ohne Store-Bezug (z. B. `sync-order-matrix`) |
| `role` | `admin` (Default), `reporting`, `empty` |
| `concurrentBuild` | `true` erlaubt parallele Läufe, Default `false` |
| `description` | Freitext; weitere Keys landen im Payload |

- Das Ende von `jenkins.php` enthält `foreach ($jobs as $job) { $job['region'] = ...; }`: wirkungslos (Iteration per Wert, `region` ist kein ausgewerteter Schlüssel; Region kommt aus `APPLICATION_REGION`). Unsauberkeit im Release, nicht nachbauen.
- Registrierung: `src/Pyz/Zed/Scheduler/SchedulerDependencyProvider.php`: `getScheduleReaderPlugins()` -> `PhpScheduleReaderPlugin`; `getSchedulerAdapterPlugins()` -> `[SchedulerConfig::SCHEDULER_JENKINS => new SchedulerJenkinsAdapterPlugin()]` (`Pyz\Shared\Scheduler\SchedulerConfig::SCHEDULER_JENKINS = 'jenkins'`). Aktivierung `config/Shared/config_default.php` (Abschnitt SCHEDULER): `SchedulerConstants::ENABLED_SCHEDULERS = ['jenkins']`, `SchedulerJenkinsConstants::JENKINS_CONFIGURATION`, `JENKINS_TEMPLATE_PATH`.
- Jenkins ist ein Container (`scheduler`, Image `spryker/jenkins:2.492.3`, `deploy.dev.yml`, Endpoint `scheduler.spryker.local`); verifiziert: `/var/jenkins_home/jobs` enthält 38 Jobs (19 EU, 19 US).
- Kommandos (`vendor/spryker/scheduler/.../Communication/Console/`): `scheduler:setup` (legt/aktualisiert Jobs; `-r/--roles`, `-s/--schedulers`, `--store`), `scheduler:suspend` / `scheduler:resume` (zusätzlich `-j/--jobs`), `scheduler:clean`. Install-Recipes rufen `scheduler:clean` (destruktiv) und `scheduler:setup -vvv --no-ansi || true` ([30-docker-sdk-workflow](30-docker-sdk-workflow.md)).
- Standard-Jobs (Auszug): `queue-worker-start` (jede Minute), `check-oms-conditions`/`check-oms-timeouts` (jede Minute), `clear-oms-locks`, `event-trigger-timeout` (alle 5 Min.), `update-product-label-relations`, `apply-price-product-schedule`, `order-invoice-send`, `glue-api-generate-documentation` (Glue-Binary), `generate-sitemap-files`, `message-broker-consume-channels` (nur bei `MessageBrokerConstants::IS_ENABLED`). State-Machine-Block auskommentiert.
- Legacy: `config/Zed/cronjobs/cron.conf` und `README` (nennt `jobs.php`/Crontab) sind überholt; `cron.conf` wird nicht gelesen `[Annahme]` (kein Verweis gefunden).
- Eigener Scheduler statt Jenkins: `SchedulerAdapterPluginInterface` und `ScheduleReaderPluginInterface` (`vendor/spryker/scheduler-extension/`), Eintrag im `SchedulerDependencyProvider`.

### Queue-Worker
- `queue:worker:start` (`QueueWorkerConsole`, Option `-s/--stop-when-empty`) ruft `QueueFacade::startWorker('<vendor>/bin/console queue:task:start', ...)`. `Business/Worker/Worker.php` läuft in Runden bis `QUEUE_WORKER_MAX_THRESHOLD_SECONDS` (Default `QueueConfig::DEFAULT_THRESHOLD = 59` s, daher Cron jede Minute, nachgeprüft), startet je Queue Kindprozesse `queue:task:start <queue>` (max. `CONFIG_MAX_WORKER_NUMBER`), verarbeitet in Chunks (`getChunkSize()` des Prozessor-Plugins; überschreibbar über `QueueConstants::QUEUE_MESSAGE_CHUNK_SIZE_MAP`, Env `QUEUE_MESSAGE_CHUNK_SIZE_MAP` als JSON). Danach wartet er auf laufende Kinder.
- Prozesse je Queue (`config/Shared/config_default.php`): queue-spezifische Einträge haben Vorrang vor `QUEUE_ADAPTER_CONFIGURATION_DEFAULT`:
```php
$config[QueueConstants::QUEUE_ADAPTER_CONFIGURATION] = [
    EventConstants::EVENT_QUEUE => [
        QueueConfig::CONFIG_QUEUE_ADAPTER => RabbitMqAdapter::class,
        QueueConfig::CONFIG_MAX_WORKER_NUMBER => 5,
    ],
];
$config[QueueConstants::QUEUE_ADAPTER_CONFIGURATION_DEFAULT] = [
    QueueConfig::CONFIG_QUEUE_ADAPTER => RabbitMqAdapter::class,
    QueueConfig::CONFIG_MAX_WORKER_NUMBER => 1,
];
```
In `config_default.php`: `event` bis 5 Prozesse, alle anderen 1. Die Umgebungsdateien `config_default-docker.dev.php`, `config_default-docker.production.php` und `config_default-ci.php` setzen `QUEUE_ADAPTER_CONFIGURATION[EVENT_QUEUE][CONFIG_MAX_WORKER_NUMBER] = 1`: der event-Worker läuft in `docker.dev`, `production` und `ci` mit **1 Prozess**, nicht mit 5 (die 5 gelten nur dort, wo keine Umgebungsdatei überschreibt). Skalierung: mehr Jobs/Container oder höhere `MAX_WORKER_NUMBER` ([25-performance-caching](25-performance-caching.md)).
- Graceful Shutdown: `Pyz\Zed\Queue\QueueConfig::getSignalsForGracefulWorkerShutdown()` (SIGINT/SIGQUIT/SIGABRT/SIGTERM, Basis `[]`).
- Stabile (ressourcenbewusste) Worker, Konstanten `vendor/spryker/queue/src/Spryker/Shared/Queue/QueueConstants.php`, Defaults `Spryker\Zed\Queue\QueueConfig`: `RESOURCE_AWARE_QUEUE_WORKER_ENABLED` (Default `false`), `QUEUE_WORKER_MAX_PROCESSES` (5), `QUEUE_WORKER_FREE_MEMORY_BUFFER` (350 MB), `QUEUE_WORKER_MEMORY_MAX_GROWTH_FACTOR` (50), `QUEUE_WORKER_MEMORY_READ_PROCESS_TIMEOUT` (5), `QUEUE_WORKER_IGNORE_MEMORY_READ_FAILURE`; Wartebegrenzung `QUEUE_WORKER_WAIT_LIMIT_ENABLED` (`false`), `..._MAX_WAITING_SECONDS` (30), `..._MAX_WAITING_ROUNDS` (3); `MAX_QUEUE_TASK_MEMORY_SIZE` (1024), `MAX_QUEUE_TASK_MEMORY_CHUNK_SIZE` (1024); `QUEUE_WORKER_INTERVAL_MILLISECONDS` (1000), `QUEUE_WORKER_DELAY_WHEN_NOT_EMPTY_MILLISECONDS` (100), `QUEUE_PROCESS_TRIGGER_INTERVAL_MICROSECONDS` (Pyz 1001), `QUEUE_BULK_MESSAGE_CHECK_ENABLED` (Pyz `false`). Im Release sind die Stable-Worker-Flags nicht gesetzt.
- Debug: `queue:task:start <queue>` einzeln (`-k/--no-ack` lässt Nachrichten liegen); `queue:dump <queue>` (nur Dev-Block); `multi-process:run "<child>" [total_timeout] -t <child_timeout> -m <child_min_duration> [-s]` (`MultiProcessRunConsole`, nur Dev-Block; in Produktion selbst registrieren `[Annahme]`).

### Eigene Queue: drei Stellen mit identischem Queue-Namen
1. **RabbitMQ-Topologie** `src/Pyz/Client/RabbitMq/RabbitMqConfig.php::getQueueConfiguration()` (merged Publish- und Synchronization-Konfiguration). Syntax: `'QueueName'` (erzeugt Queue + `QueueName.error`) oder `'QueueName' => ['routingKey' => 'RetryQueueName']`; Beispiel `EventConstants::EVENT_QUEUE => [EventConfig::EVENT_ROUTING_KEY_RETRY => EVENT_QUEUE_RETRY, EVENT_ROUTING_KEY_ERROR => EVENT_QUEUE_ERROR]`.
2. **Verarbeitung** `src/Pyz/Zed/Queue/QueueDependencyProvider.php::getProcessorMessagePlugins(Container $container): array` - Map `Queuename => QueueMessageProcessorPluginInterface` (`processMessages(array $queueMessageTransfers)`, `getChunkSize()`). Fertige: `EventQueueMessageProcessorPlugin`, `EventRetryQueueMessageProcessorPlugin`, `SynchronizationStorageQueueMessageProcessorPlugin`, `SynchronizationSearchQueueMessageProcessorPlugin`.
3. **Anlegen in RabbitMQ** `queue:setup` (`QueueSetupConsole`, in Pyz registriert, von allen Install-Recipes aufgerufen). Zur Laufzeit nur bei `RabbitMqEnv::RABBITMQ_ENABLE_RUNTIME_SETTING_UP` = true (Release: `false`).
- Producer: `Spryker\Client\Queue\QueueClient::sendMessage($queueName, QueueSendMessageTransfer)`; Adapter in `src/Pyz/Client/Queue/QueueDependencyProvider.php::createQueueAdapters()`.
- Prozessor-Vertrag: je Nachricht `setAcknowledge(true)` bei Erfolg; bei Fehler `setAcknowledge(false)`, `setHasError(true)` (ggf. `setReject(true)`/Retry); Referenz `vendor/spryker/event/.../Queue/Consumer/EventQueueConsumer.php`. Felder `QueueReceiveMessage{queueName, queueMessage, acknowledge, reject, hasError, isRetryExist, deliveryTag}`.
- Receiver-Optionen: `Pyz\Zed\Queue\QueueConfig::getQueueReceiverOptions()` (Default-Key `QueueConstants::QUEUE_DEFAULT_RECEIVER`); Message-Check: `getMessageCheckOptions()` + `RabbitMqQueueMessageCheckerPlugin` in `getQueueMessageCheckerPlugins()`.

## Anleitung

**Neues Kommando**
1. Console-Klasse im Modul (Muster unten), Logik in Facade ([02-projektmodul](02-projektmodul.md)).
2. In `ConsoleDependencyProvider::getConsoleCommands()` eintragen (plus `use`).
3. `docker/sdk cli console list --raw` prüfen, Aufruf testen.

**Neuer Cronjob**
1. Eintrag in `config/Zed/cronjobs/jenkins.php`; Store-Wirkung klären (`stores` / `global`).
2. `docker/sdk cli console scheduler:setup` (ohne diesen Schritt kein Effekt).
3. In Jenkins (`scheduler.spryker.local`) prüfen; Zugang im Dev-Setup `[Annahme]` ohne Login, nicht geprüft.

**Neue Queue**
1. Queue in `RabbitMqConfig::getQueueConfiguration()`.
2. Prozessor-Plugin in `QueueDependencyProvider::getProcessorMessagePlugins()`.
3. `docker/sdk cli console queue:setup`; optional `MAX_WORKER_NUMBER` in `config_default.php`.
4. Worker läuft per Jenkins-Job `queue-worker-start`; Test: `queue:worker:start --stop-when-empty`.

**Konsolenbefehle** `[live verifiziert (202610.0)]` (alle ausgeführt; `queue:dump` und `multi-process:run` nur im Dev-Block; `api:generate:documentation` steht in `vendor/bin/glue list`):
```bash
docker/sdk cli 'vendor/bin/console list --raw'               # alle Kommandos
docker/sdk console queue:worker:start --stop-when-empty      # Queues leerarbeiten
docker/sdk cli 'vendor/bin/console queue:task:start event'   # eine Queue, ein Prozess
docker/sdk cli 'vendor/bin/console queue:dump <queue>'       # Inhalt ansehen (Dev); Queue-Name wie `publish`, `event`; nicht-leer: JSON (bleibt in der Queue, `-a` bestätigt), leer: Core-Bug TypeError (Exit 255)
docker/sdk cli 'vendor/bin/console queue:setup'              # Exchanges/Queues anlegen
docker/sdk cli 'vendor/bin/console queue:queue:purge-all'    # alle Queues leeren; `queue:queue:delete-all`/`queue:exchanges:delete-all` + `queue:setup` stellt die 92 Queues der Region wieder her
docker/sdk cli 'vendor/bin/console scheduler:setup'          # Jobs nach Jenkins
docker/sdk cli 'vendor/bin/console scheduler:setup --store=DE -r admin'
docker/sdk cli 'vendor/bin/console scheduler:suspend -j <jobname>'   # wirkt nur auf die Jobs der aktuellen Region (EU: 19 von 38); `--store=US` wählt keine andere Region; laufende Builds laufen zu Ende, daher hält ein laufender Worker Nachrichten noch kurz als unacked
docker/sdk cli 'vendor/bin/console scheduler:resume'
docker/sdk cli 'vendor/bin/console scheduler:clean'   # entfernt die Jobs der Region (19), danach scheduler:setup (legt alle 38 neu an) und scheduler:resume
docker/sdk cli 'vendor/bin/console multi-process:run "queue:worker:start" 120 -t 60 -m 2'
docker/sdk cli 'vendor/bin/console event:trigger:timeout'    # siehe 07-publish-sync
docker/sdk cli 'vendor/bin/glue api:generate:documentation'  # Glue-Binary
```

## Beispiele

Core-Vorbild `vendor/spryker/product-validity/.../Communication/Console/ProductValidityConsole.php`:
```php
class ProductValidityConsole extends Console
{
    public const COMMAND_NAME = 'product:check-validity';

    protected function configure(): void
    {
        $this->setName(static::COMMAND_NAME);
        $this->setDescription('(Un)Publish products based on validity date ranges.');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->getFacade()->checkProductValidityDateRangeAndTouch();

        return static::CODE_SUCCESS;
    }
}
```

Echte Job-Definitionen `config/Zed/cronjobs/jenkins.php` (live im Jenkins-Container verifiziert: `EU_check-oms-timeouts` mit `<spec>* * * * *</spec>`):
```php
$jobs[] = [
    'name' => 'check-oms-timeouts',
    'command' => '$PHP_BIN vendor/bin/console oms:check-timeout',
    'schedule' => '* * * * *',
    'enable' => true,
];

$jobs[] = [
    'name' => 'sync-order-matrix',
    'command' => '$PHP_BIN vendor/bin/console order-matrix:sync',
    'schedule' => '*/1 * * * *',
    'enable' => true,
    'global' => true,
];
```

Muster (nicht im Release, nicht ausgeführt): eigenes Kommando `project-report:generate` in einem Modul `ProjectReport`:
```php
// src/Pyz/Zed/ProjectReport/Communication/Console/ProjectReportGenerateConsole.php
/**
 * @method \Pyz\Zed\ProjectReport\Business\ProjectReportFacadeInterface getFacade()
 */
class ProjectReportGenerateConsole extends Console
{
    public const COMMAND_NAME = 'project-report:generate';

    protected const OPTION_LIMIT = 'limit';

    protected function configure(): void
    {
        $this->setName(static::COMMAND_NAME);
        $this->setDescription('Generates the project report.');
        $this->addOption(static::OPTION_LIMIT, 'l', InputOption::VALUE_REQUIRED, 'Max items.', '100');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->info('Generating report...');
        $this->getFacade()->generateReport((int)$input->getOption(static::OPTION_LIMIT));

        return static::CODE_SUCCESS;
    }
}
```
Registrierung `ConsoleDependencyProvider::getConsoleCommands()`: `new ProjectReportGenerateConsole(),`. Cronjob:
```php
$jobs[] = [
    'name' => 'project-report-generate',
    'command' => '$PHP_BIN vendor/bin/console project-report:generate --limit=500',
    'schedule' => '0 3 * * *',
    'enable' => true,
    'stores' => ['DE'],        // optional; ohne 'stores' ein Job je Region (Dynamic Store) bzw. je Store, mit 'global' => true genau einer
];
```
Eigene Queue: `'project-report-queue',` in `RabbitMqConfig::getQueueConfiguration()`, `'project-report-queue' => new ProjectReportQueueMessageProcessorPlugin(),` in `QueueDependencyProvider::getProcessorMessagePlugins()`:
```php
// src/Pyz/Zed/ProjectReport/Communication/Plugin/Queue/ProjectReportQueueMessageProcessorPlugin.php
class ProjectReportQueueMessageProcessorPlugin extends AbstractPlugin implements QueueMessageProcessorPluginInterface
{
    public function processMessages(array $queueMessageTransfers)
    {
        foreach ($queueMessageTransfers as $queueMessageTransfer) {
            $queueMessageTransfer->setAcknowledge(true);
        }

        return $queueMessageTransfers;
    }

    public function getChunkSize()
    {
        return 100;
    }
}
```
Danach `queue:setup`. Interface-Signaturen (`processMessages(array $queueMessageTransfers)`, `getChunkSize()`) nachgeprüft.

## Fallstricke
- Kommando nicht in `ConsoleDependencyProvider` registriert -> "Command not defined". Dev-Kommandos fehlen in Produktion (`ENABLE_DEVELOPMENT_CONSOLE_COMMANDS` aus).
- Logik in `execute()` statt Business-Layer; Fremdmodule nur über deren Facade ([01-architektur](01-architektur.md)).
- Jobname mit Leerzeichen/Sonderzeichen -> Job nicht anlegbar. `jenkins.php` wirkt erst nach `scheduler:setup`.
- Store-Wirkung: ohne `stores`/`global` entsteht bei Dynamic Store ein Job je **Region** (live `EU_<job>`), sonst je Store; store-/regionsunabhängige Aufgaben mit `'global' => true`, sonst laufen sie mehrfach.
- Queue nur in `RabbitMqConfig`, ohne Prozessor-Plugin -> `MissingQueuePluginException` (`vendor/spryker/queue/.../Business/Exception/`). Ohne `queue:setup` existiert die Queue in RabbitMQ nicht.
- Prozessor ohne `setAcknowledge(true)` -> Nachrichten bleiben liegen/werden erneut zugestellt.
- Worker beendet sich nach ca. 59 s (Absicht); minütlicher Neustart per Cron nötig.
- Änderungen nur in `src/Pyz/**` und `config/**`, nie in `vendor/**` ([03-core-erweitern](03-core-erweitern.md), [28-upgradability](28-upgradability.md)); per `Constants`-Option konfigurieren statt Config-Klassen überschreiben.
- Große Queues: `QUEUE_MESSAGE_CHUNK_SIZE_MAP` und `MAX_QUEUE_TASK_MEMORY_SIZE` abstimmen; Stable Worker erst bei Bedarf.
- Keine Passwörter/Hosts in `jenkins.php`; Konfiguration via Env ([06-konfiguration](06-konfiguration.md)).
- **Deprecated/Legacy:** `ConsoleDependencyProvider::SERVICE_PROVIDERS` zugunsten Application-Plugins; `PhpScheduleMapper::isDynamicStoreEnabled()` ("deprecated, exists for BC-reasons", Store-Verhalten hängt vom Dynamic-Store-Mode ab, [19-multi-store-i18n](19-multi-store-i18n.md)); `config/Zed/cronjobs/README` und `cron.conf` überholt, verbindlich `jenkins.php`; die tote `region`-Schleife am Ende von `jenkins.php`.
- Verhalten von `ResourceAwareQueueWorker` im Detail und Wirkung von `cron.conf` nicht geprüft `[Annahme]`.

## Checkliste
- [ ] Console: `<modul>:<aktion>`, Description, `parent::configure()`, Rückgabe `CODE_SUCCESS`/`CODE_ERROR`
- [ ] In `ConsoleDependencyProvider` registriert, per `list` sichtbar
- [ ] Logik in Facade, Console dünn
- [ ] Cronjob in `jenkins.php` mit `enable`, passender `stores`/`global`-Wahl, `scheduler:setup` ausgeführt
- [ ] Queue: `RabbitMqConfig` + `getProcessorMessagePlugins()` + `queue:setup`
- [ ] Prozessor setzt `acknowledge`/`hasError` korrekt
- [ ] Worker-Prozesszahl (`MAX_WORKER_NUMBER`) und Chunk-Größen bewusst gewählt
- [ ] Nichts in `vendor/` geändert, keine Secrets im Job

## Quellen
Doku (Basis `https://docs.spryker.com/docs/`; Inhalt nicht abrufbar): `dg/dev/backend-development/console-commands/console-commands.html`, `.../implement-console-commands.html`, `dg/dev/backend-development/cronjobs/cronjobs.html`, `.../add-and-configure-cronjobs.html`, `.../create-a-custom-scheduler.html`, `.../stable-workers.html`, `.../optimizing-jenkins-execution.html`, `dg/dev/backend-development/data-manipulation/data-publishing/specific-use-cases-and-problem-solving/worker-configuration-for-optimal-memory-and-cpu-usage.html`.

Code: `src/Pyz/Zed/Console/*`, `src/Pyz/Zed/Scheduler/SchedulerDependencyProvider.php`, `src/Pyz/Zed/Queue/*`, `src/Pyz/Client/{Queue,RabbitMq}/*`, `src/Pyz/Shared/Scheduler/SchedulerConfig.php`, `config/Zed/cronjobs/jenkins.php`, `config/Shared/config_default.php` (QUEUE, SCHEDULER), `config/install/destructive.yml`, `deploy.dev.yml`, `vendor/spryker/{console,scheduler,scheduler-jenkins,scheduler-extension,queue,queue-extension,rabbit-mq,event,kernel,product-validity}`.

Nachbarn: [07-publish-sync](07-publish-sync.md), [09-data-import](09-data-import.md), [10-oms-state-machine](10-oms-state-machine.md), [30-docker-sdk-workflow](30-docker-sdk-workflow.md), [24-mail-message-broker](24-mail-message-broker.md).
