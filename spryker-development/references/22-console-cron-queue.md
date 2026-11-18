# Console commands, cron jobs (Scheduler/Jenkins) and queue workers

> Status: release 202610.0 (spryker/kernel 3.87.1, Symfony 6.4.48). Verified on the running project (containers `spryker_b2b_dev_*`, environment `docker.dev`, regions EU and US): all commands below were executed `[live verified (202610.0)]` in release 202610.0, including `scheduler:suspend/resume/setup/clean`, `queue:setup`, `queue:worker:start --stop-when-empty`, `queue:dump`, `multi-process:run`; read access to `/var/jenkins_home/jobs` in the `scheduler` container. The docs pages could not be evaluated (navigation only); statements come from the code `[code-verified, docs not cross-checked]`.

## Purpose
Write and register your own console commands, define recurring jobs via `config/Zed/cronjobs/jenkins.php` and the Scheduler, understand queue workers and create your own queues including a processor plugin. Scope boundaries: P&S queue semantics: [07-publish-sync](07-publish-sync.md); Docker/install recipes: [30-docker-sdk-workflow](30-docker-sdk-workflow.md); configuration: [06-configuration](06-configuration.md); logging/monitoring: [23-errors-logging-debugging](23-errors-logging-debugging.md); `message-broker:consume`: [24-mail-message-broker](24-mail-message-broker.md).

## Concepts

### Console command
- Class `src/Pyz/Zed/<Module>/Communication/Console/<Name>Console.php`, base `Spryker\Zed\Kernel\Communication\Console\Console` (extends Symfony `Command`). Resolves `getFacade()`, `getFactory()`, `getRepository()` by convention; no logic in the console, only a Facade call.
- `configure()`: `setName()` (format `<module>:<action>`), `setDescription()`, then `parent::configure()`. `execute(InputInterface $input, OutputInterface $output): int` returns `static::CODE_SUCCESS` / `static::CODE_ERROR` (`public const int`, checked). Output helper `$this->info(array|string $message, bool $wrapInInfoTags = true)`.
- Registration is **explicit**, no auto-discovery: `src/Pyz/Zed/Console/ConsoleDependencyProvider.php::getConsoleCommands(Container $container): array` (168 entries in the base array, plus 44 in the dev block = 212, plus the Propel commands from `propel()->facade()->getConsoleCommands()`). Development commands (sniffer, Codeception, IDE autocompletion, ...) are in the block `if ($this->getConfig()->isDevelopmentConsoleCommandsEnabled())`; flag from `Pyz\Zed\Console\ConsoleConfig` / `Pyz\Shared\Console\ConsoleConstants::ENABLE_DEVELOPMENT_CONSOLE_COMMANDS`.
- Further extension points in the same provider: `getConsolePostRunHookPlugins()` (Pyz: `EventBehaviorPostHookPlugin` triggers collected events after each command), `getConsolePreRunHookPlugins()` (`ConsolePreRunHookPluginInterface`), `getApplicationPlugins()` (`ConsoleLocaleApplicationPlugin`, `ConsoleSecurityApplicationPlugin`, `PropelApplicationPlugin`, `TwigApplicationPlugin`, `FormApplicationPlugin`), `getEventSubscriber()` (`MonitoringConsolePlugin`, `DirectSynchronizationConsolePlugin`).
- Several names for the same class via constructor argument (`new DataImportConsole(DataImportConsole::DEFAULT_NAME . ':' . $importType)`, [09-data-import](09-data-import.md)).
- Invocation: Zed `vendor/bin/console <name>`, Glue `vendor/bin/glue <name>` (`vendor/bin` contains `console`, `glue`, `yves`); short form `docker/sdk console <name>`. Global options of every command: `--no-pre`, `--no-post`, `--repeatable[=N]`, `-max/--max-duration`, `-min/--min-duration`, `--profile`.

### Cron jobs: `jenkins.php` and Scheduler
- Jobs in `config/Zed/cronjobs/jenkins.php` (file name = Scheduler ID, `SchedulerConfig::getPhpSchedulerReaderPath()`); read in via `include`, fills `$jobs[]`.
- Job keys (`vendor/spryker/scheduler/.../PhpScheduleReader/Mapper/PhpScheduleMapper.php`):

| Key | Meaning |
|---|---|
| `name` | Required, no spaces/URL special characters; Jenkins job name `<REGION>_<STORE>_<name>` (region only in Dynamic Store Mode); live e.g. `EU_check-oms-timeouts` |
| `command` | `$PHP_BIN vendor/bin/console ...` (Glue: `vendor/bin/glue`) |
| `schedule` | Cron expression |
| `enable` | `true/false`, default `false` |
| `stores` | optional store list; without `stores` and without `global`, **Dynamic Store** creates one job **per region** (live `EU_<job>`, not per store), without Dynamic Store one per store (`PhpScheduleMapper::isDynamicStoreEnabled()`/`getStoreList()`, both BC-deprecated) |
| `global` | `true` = one job without store reference (e.g. `sync-order-matrix`) |
| `role` | `admin` (default), `reporting`, `empty` |
| `concurrentBuild` | `true` allows parallel runs, default `false` |
| `description` | Free text; further keys end up in the payload |

- The end of `jenkins.php` contains `foreach ($jobs as $job) { $job['region'] = ...; }`: has no effect (iteration by value, `region` is not an evaluated key; the region comes from `APPLICATION_REGION`). A sloppiness in the release, do not replicate.
- Registration: `src/Pyz/Zed/Scheduler/SchedulerDependencyProvider.php`: `getScheduleReaderPlugins()` -> `PhpScheduleReaderPlugin`; `getSchedulerAdapterPlugins()` -> `[SchedulerConfig::SCHEDULER_JENKINS => new SchedulerJenkinsAdapterPlugin()]` (`Pyz\Shared\Scheduler\SchedulerConfig::SCHEDULER_JENKINS = 'jenkins'`). Activation `config/Shared/config_default.php` (section SCHEDULER): `SchedulerConstants::ENABLED_SCHEDULERS = ['jenkins']`, `SchedulerJenkinsConstants::JENKINS_CONFIGURATION`, `JENKINS_TEMPLATE_PATH`.
- Jenkins is a container (`scheduler`, image `spryker/jenkins:2.492.3`, `deploy.dev.yml`, endpoint `scheduler.spryker.local`); verified: `/var/jenkins_home/jobs` contains 38 jobs (19 EU, 19 US).
- Commands (`vendor/spryker/scheduler/.../Communication/Console/`): `scheduler:setup` (creates/updates jobs; `-r/--roles`, `-s/--schedulers`, `--store`), `scheduler:suspend` / `scheduler:resume` (additionally `-j/--jobs`), `scheduler:clean`. Install recipes call `scheduler:clean` (destructive) and `scheduler:setup -vvv --no-ansi || true` ([30-docker-sdk-workflow](30-docker-sdk-workflow.md)).
- Standard jobs (excerpt): `queue-worker-start` (every minute), `check-oms-conditions`/`check-oms-timeouts` (every minute), `clear-oms-locks`, `event-trigger-timeout` (every 5 min.), `update-product-label-relations`, `apply-price-product-schedule`, `order-invoice-send`, `glue-api-generate-documentation` (Glue binary), `generate-sitemap-files`, `message-broker-consume-channels` (only with `MessageBrokerConstants::IS_ENABLED`). State machine block commented out.
- Legacy: `config/Zed/cronjobs/cron.conf` and `README` (mentions `jobs.php`/crontab) are outdated; `cron.conf` is not read `[Assumption]` (no reference found).
- Own scheduler instead of Jenkins: `SchedulerAdapterPluginInterface` and `ScheduleReaderPluginInterface` (`vendor/spryker/scheduler-extension/`), entry in the `SchedulerDependencyProvider`.

### Queue worker
- `queue:worker:start` (`QueueWorkerConsole`, option `-s/--stop-when-empty`) calls `QueueFacade::startWorker('<vendor>/bin/console queue:task:start', ...)`. `Business/Worker/Worker.php` runs in rounds until `QUEUE_WORKER_MAX_THRESHOLD_SECONDS` (default `QueueConfig::DEFAULT_THRESHOLD = 59` s, hence cron every minute, checked), starts child processes `queue:task:start <queue>` per queue (max. `CONFIG_MAX_WORKER_NUMBER`), processes in chunks (`getChunkSize()` of the processor plugin; can be overridden via `QueueConstants::QUEUE_MESSAGE_CHUNK_SIZE_MAP`, env `QUEUE_MESSAGE_CHUNK_SIZE_MAP` as JSON). Afterwards it waits for running children.
- Processes per queue (`config/Shared/config_default.php`): queue-specific entries take precedence over `QUEUE_ADAPTER_CONFIGURATION_DEFAULT`:
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
In `config_default.php`: `event` up to 5 processes, all others 1. The environment files `config_default-docker.dev.php`, `config_default-docker.production.php` and `config_default-ci.php` set `QUEUE_ADAPTER_CONFIGURATION[EVENT_QUEUE][CONFIG_MAX_WORKER_NUMBER] = 1`: the event worker runs with **1 process** in `docker.dev`, `production` and `ci`, not with 5 (the 5 only apply where no environment file overrides it). Scaling: more jobs/containers or a higher `MAX_WORKER_NUMBER` ([25-performance-caching](25-performance-caching.md)).
- Graceful shutdown: `Pyz\Zed\Queue\QueueConfig::getSignalsForGracefulWorkerShutdown()` (SIGINT/SIGQUIT/SIGABRT/SIGTERM, base `[]`).
- Stable (resource-aware) workers, constants `vendor/spryker/queue/src/Spryker/Shared/Queue/QueueConstants.php`, defaults `Spryker\Zed\Queue\QueueConfig`: `RESOURCE_AWARE_QUEUE_WORKER_ENABLED` (default `false`), `QUEUE_WORKER_MAX_PROCESSES` (5), `QUEUE_WORKER_FREE_MEMORY_BUFFER` (350 MB), `QUEUE_WORKER_MEMORY_MAX_GROWTH_FACTOR` (50), `QUEUE_WORKER_MEMORY_READ_PROCESS_TIMEOUT` (5), `QUEUE_WORKER_IGNORE_MEMORY_READ_FAILURE`; wait limit `QUEUE_WORKER_WAIT_LIMIT_ENABLED` (`false`), `..._MAX_WAITING_SECONDS` (30), `..._MAX_WAITING_ROUNDS` (3); `MAX_QUEUE_TASK_MEMORY_SIZE` (1024), `MAX_QUEUE_TASK_MEMORY_CHUNK_SIZE` (1024); `QUEUE_WORKER_INTERVAL_MILLISECONDS` (1000), `QUEUE_WORKER_DELAY_WHEN_NOT_EMPTY_MILLISECONDS` (100), `QUEUE_PROCESS_TRIGGER_INTERVAL_MICROSECONDS` (Pyz 1001), `QUEUE_BULK_MESSAGE_CHECK_ENABLED` (Pyz `false`). In the release, the stable worker flags are not set.
- Debug: `queue:task:start <queue>` individually (`-k/--no-ack` leaves messages in place); `queue:dump <queue>` (dev block only); `multi-process:run "<child>" [total_timeout] -t <child_timeout> -m <child_min_duration> [-s]` (`MultiProcessRunConsole`, dev block only; register it yourself for production `[Assumption]`).

### Own queue: three places with an identical queue name
1. **RabbitMQ topology** `src/Pyz/Client/RabbitMq/RabbitMqConfig.php::getQueueConfiguration()` (merges publish and synchronization configuration). Syntax: `'QueueName'` (creates queue + `QueueName.error`) or `'QueueName' => ['routingKey' => 'RetryQueueName']`; example `EventConstants::EVENT_QUEUE => [EventConfig::EVENT_ROUTING_KEY_RETRY => EVENT_QUEUE_RETRY, EVENT_ROUTING_KEY_ERROR => EVENT_QUEUE_ERROR]`.
2. **Processing** `src/Pyz/Zed/Queue/QueueDependencyProvider.php::getProcessorMessagePlugins(Container $container): array` - map `Queuename => QueueMessageProcessorPluginInterface` (`processMessages(array $queueMessageTransfers)`, `getChunkSize()`). Ready-made: `EventQueueMessageProcessorPlugin`, `EventRetryQueueMessageProcessorPlugin`, `SynchronizationStorageQueueMessageProcessorPlugin`, `SynchronizationSearchQueueMessageProcessorPlugin`.
3. **Creation in RabbitMQ** `queue:setup` (`QueueSetupConsole`, registered in Pyz, called by all install recipes). At runtime only if `RabbitMqEnv::RABBITMQ_ENABLE_RUNTIME_SETTING_UP` = true (release: `false`).
- Producer: `Spryker\Client\Queue\QueueClient::sendMessage($queueName, QueueSendMessageTransfer)`; adapters in `src/Pyz/Client/Queue/QueueDependencyProvider.php::createQueueAdapters()`.
- Processor contract: per message `setAcknowledge(true)` on success; on error `setAcknowledge(false)`, `setHasError(true)` (possibly `setReject(true)`/retry); reference `vendor/spryker/event/.../Queue/Consumer/EventQueueConsumer.php`. Fields `QueueReceiveMessage{queueName, queueMessage, acknowledge, reject, hasError, isRetryExist, deliveryTag}`.
- Receiver options: `Pyz\Zed\Queue\QueueConfig::getQueueReceiverOptions()` (default key `QueueConstants::QUEUE_DEFAULT_RECEIVER`); message check: `getMessageCheckOptions()` + `RabbitMqQueueMessageCheckerPlugin` in `getQueueMessageCheckerPlugins()`.

## Instructions

**New command**
1. Console class in the module (pattern below), logic in the Facade ([02-project-module](02-project-module.md)).
2. Add it to `ConsoleDependencyProvider::getConsoleCommands()` (plus `use`).
3. Check with `docker/sdk cli console list --raw`, test the call.

**New cron job**
1. Entry in `config/Zed/cronjobs/jenkins.php`; clarify the store effect (`stores` / `global`).
2. `docker/sdk cli console scheduler:setup` (without this step, no effect).
3. Check in Jenkins (`scheduler.spryker.local`); access in the dev setup `[Assumption]` without login, not checked.

**New queue**
1. Queue in `RabbitMqConfig::getQueueConfiguration()`.
2. Processor plugin in `QueueDependencyProvider::getProcessorMessagePlugins()`.
3. `docker/sdk cli console queue:setup`; optionally `MAX_WORKER_NUMBER` in `config_default.php`.
4. The worker runs via the Jenkins job `queue-worker-start`; test: `queue:worker:start --stop-when-empty`.

**Console commands** `[live verified (202610.0)]` (all executed; `queue:dump` and `multi-process:run` only in the dev block; `api:generate:documentation` is listed in `vendor/bin/glue list`):
```bash
docker/sdk cli 'vendor/bin/console list --raw'               # all commands
docker/sdk console queue:worker:start --stop-when-empty      # work queues until empty
docker/sdk cli 'vendor/bin/console queue:task:start event'   # one queue, one process
docker/sdk cli 'vendor/bin/console queue:dump <queue>'       # view contents (dev); queue name such as `publish`, `event`; non-empty: JSON (stays in the queue, `-a` acknowledges), empty: core bug TypeError (exit 255)
docker/sdk cli 'vendor/bin/console queue:setup'              # create exchanges/queues
docker/sdk cli 'vendor/bin/console queue:queue:purge-all'    # empty all queues; `queue:queue:delete-all`/`queue:exchanges:delete-all` + `queue:setup` restores the region's 92 queues
docker/sdk cli 'vendor/bin/console scheduler:setup'          # jobs to Jenkins
docker/sdk cli 'vendor/bin/console scheduler:setup --store=DE -r admin'
docker/sdk cli 'vendor/bin/console scheduler:suspend -j <jobname>'   # only affects the jobs of the current region (EU: 19 of 38); `--store=US` does not select another region; running builds run to completion, so a running worker still briefly holds messages as unacked
docker/sdk cli 'vendor/bin/console scheduler:resume'
docker/sdk cli 'vendor/bin/console scheduler:clean'   # removes the region's jobs (19), then scheduler:setup (recreates all 38) and scheduler:resume
docker/sdk cli 'vendor/bin/console multi-process:run "queue:worker:start" 120 -t 60 -m 2'
docker/sdk cli 'vendor/bin/console event:trigger:timeout'    # see 07-publish-sync
docker/sdk cli 'vendor/bin/glue api:generate:documentation'  # Glue binary
```

## Examples

Core model `vendor/spryker/product-validity/.../Communication/Console/ProductValidityConsole.php`:
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

Real job definitions `config/Zed/cronjobs/jenkins.php` (verified live in the Jenkins container: `EU_check-oms-timeouts` with `<spec>* * * * *</spec>`):
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

Pattern (not in the release, not executed): own command `project-report:generate` in a module `ProjectReport`:
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
Registration `ConsoleDependencyProvider::getConsoleCommands()`: `new ProjectReportGenerateConsole(),`. Cron job:
```php
$jobs[] = [
    'name' => 'project-report-generate',
    'command' => '$PHP_BIN vendor/bin/console project-report:generate --limit=500',
    'schedule' => '0 3 * * *',
    'enable' => true,
    'stores' => ['DE'],        // optional; without 'stores' one job per region (Dynamic Store) or per store, with 'global' => true exactly one
];
```
Own queue: `'project-report-queue',` in `RabbitMqConfig::getQueueConfiguration()`, `'project-report-queue' => new ProjectReportQueueMessageProcessorPlugin(),` in `QueueDependencyProvider::getProcessorMessagePlugins()`:
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
Then `queue:setup`. Interface signatures (`processMessages(array $queueMessageTransfers)`, `getChunkSize()`) checked.

## Pitfalls
- Command not registered in `ConsoleDependencyProvider` -> "Command not defined". Dev commands are missing in production (`ENABLE_DEVELOPMENT_CONSOLE_COMMANDS` off).
- Logic in `execute()` instead of the business layer; other modules only via their Facade ([01-architecture](01-architecture.md)).
- Job name with spaces/special characters -> job cannot be created. `jenkins.php` only takes effect after `scheduler:setup`.
- Store effect: without `stores`/`global`, Dynamic Store creates one job per **region** (live `EU_<job>`), otherwise one per store; store-/region-independent tasks need `'global' => true`, otherwise they run multiple times.
- Queue only in `RabbitMqConfig`, without processor plugin -> `MissingQueuePluginException` (`vendor/spryker/queue/.../Business/Exception/`). Without `queue:setup` the queue does not exist in RabbitMQ.
- Processor without `setAcknowledge(true)` -> messages stay in place/are redelivered.
- The worker terminates after approx. 59 s (intentional); a restart every minute via cron is required.
- Changes only in `src/Pyz/**` and `config/**`, never in `vendor/**` ([03-extending-the-core](03-extending-the-core.md), [28-upgradability](28-upgradability.md)); configure via a `Constants` option instead of overriding config classes.
- Large queues: tune `QUEUE_MESSAGE_CHUNK_SIZE_MAP` and `MAX_QUEUE_TASK_MEMORY_SIZE`; stable workers only when needed.
- No passwords/hosts in `jenkins.php`; configuration via env ([06-configuration](06-configuration.md)).
- **Deprecated/legacy:** `ConsoleDependencyProvider::SERVICE_PROVIDERS` in favor of application plugins; `PhpScheduleMapper::isDynamicStoreEnabled()` ("deprecated, exists for BC-reasons", store behavior depends on Dynamic Store Mode, [19-multi-store-i18n](19-multi-store-i18n.md)); `config/Zed/cronjobs/README` and `cron.conf` outdated, `jenkins.php` is authoritative; the dead `region` loop at the end of `jenkins.php`.
- Behavior of `ResourceAwareQueueWorker` in detail and effect of `cron.conf` not checked `[Assumption]`.

## Checklist
- [ ] Console: `<module>:<action>`, description, `parent::configure()`, return `CODE_SUCCESS`/`CODE_ERROR`
- [ ] Registered in `ConsoleDependencyProvider`, visible via `list`
- [ ] Logic in the Facade, console thin
- [ ] Cron job in `jenkins.php` with `enable`, appropriate `stores`/`global` choice, `scheduler:setup` executed
- [ ] Queue: `RabbitMqConfig` + `getProcessorMessagePlugins()` + `queue:setup`
- [ ] Processor sets `acknowledge`/`hasError` correctly
- [ ] Worker process count (`MAX_WORKER_NUMBER`) and chunk sizes chosen deliberately
- [ ] Nothing changed in `vendor/`, no secrets in the job

## Sources
Docs (base `https://docs.spryker.com/docs/`; content not retrievable): `dg/dev/backend-development/console-commands/console-commands.html`, `.../implement-console-commands.html`, `dg/dev/backend-development/cronjobs/cronjobs.html`, `.../add-and-configure-cronjobs.html`, `.../create-a-custom-scheduler.html`, `.../stable-workers.html`, `.../optimizing-jenkins-execution.html`, `dg/dev/backend-development/data-manipulation/data-publishing/specific-use-cases-and-problem-solving/worker-configuration-for-optimal-memory-and-cpu-usage.html`.

Code: `src/Pyz/Zed/Console/*`, `src/Pyz/Zed/Scheduler/SchedulerDependencyProvider.php`, `src/Pyz/Zed/Queue/*`, `src/Pyz/Client/{Queue,RabbitMq}/*`, `src/Pyz/Shared/Scheduler/SchedulerConfig.php`, `config/Zed/cronjobs/jenkins.php`, `config/Shared/config_default.php` (QUEUE, SCHEDULER), `config/install/destructive.yml`, `deploy.dev.yml`, `vendor/spryker/{console,scheduler,scheduler-jenkins,scheduler-extension,queue,queue-extension,rabbit-mq,event,kernel,product-validity}`.

Neighbors: [07-publish-sync](07-publish-sync.md), [09-data-import](09-data-import.md), [10-oms-state-machine](10-oms-state-machine.md), [30-docker-sdk-workflow](30-docker-sdk-workflow.md), [24-mail-message-broker](24-mail-message-broker.md).
