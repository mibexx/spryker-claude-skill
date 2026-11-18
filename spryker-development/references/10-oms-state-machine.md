# OMS and state machine

> Status: release 202610.0 (spryker/oms 11.55.0). The docs pages were not readable (navigation skeleton only). All statements are checked against the code `[code-verified, docs not cross-checked]`; the console commands were executed in the container `[live verified (202610.0)]` (see section Console). The template modules were checked in a sandbox with php -l, phpcs and phpstan (202610.0). While writing, additionally checked personally against `vendor/spryker/oms`, `config/Zed/oms/*.xml`, `src/Pyz/Zed/Oms` (results marked "re-checked" below).

## Purpose
Move order items (`spy_sales_order_item`) through an XML-modeled process: states, events, transitions, commands (actions), conditions (automatic transitions), timeouts. Ends before: saving the order in checkout ([11-cart-price-checkout](11-cart-price-checkout.md)). Cron/workers in general: [22-console-cron-queue](22-console-cron-queue.md). Template: [oms-process](../templates/oms-process/README.md).

## Concepts

**Process file**: `config/Zed/oms/<process name>.xml`, namespace `spryker:oms-01` (XSD `http://static.spryker.com/oms-01.xsd`). File name = process name (`Builder::loadXmlFromProcessName()`). A file has exactly one `<process main="true">`, optionally subprocesses.

Elements/attributes evaluated by the `Builder` (`vendor/spryker/oms/src/Spryker/Zed/Oms/Business/OrderStateMachine/Builder.php`) (re-checked):

| Element | Attributes / children | Meaning |
|---|---|---|
| `<process>` | `name`, `main="true"`, `file="Sub/X.xml"`, `prefix` | main process; `file` points to a subprocess file relative to the process folder |
| `<subprocesses><process>Name</process>` | text = process name | includes a subprocess (declare it at the end of the file as `<process name=... file=...>`) |
| `<state>` | `name`, `display` (translation key), `reserved="true"` (stock reserved), child `<flag>` | state; flags e.g. `cancellable`, `amendable`, `exclude from customer` |
| `<transition>` | `happy="true"`, `condition="Plugin/Key"`, children `<source>`, `<target>`, `<event>` | transition; **without** `<event>`, `oms:check-condition` checks it automatically |
| `<event>` | `name`, `manual="true"`, `onEnter="true"`, `timeout="2 hour"`, `timeoutProcessor="OmsTimeout/Initiation"`, `command="Plugin/Key"` | `manual`: button in the Back Office; `onEnter`: runs immediately on entering the source state; `timeout`: `oms:check-timeout` triggers it (`DateInterval::createFromDateString`, invalid format -> `ErrorException`) |

Subprocesses: states are renamed on loading with prefix + `getSubProcessPrefixDelimiter()` (default `' - '`); transitions in the subprocess reference main process states directly (example `config/Zed/oms/DummySubprocess/DummyInvoice01.xml`). Typos in source/target/event -> `LogicException` on loading. State names are case-sensitive strings (spaces allowed).

**Plugins** (registration in `Pyz\Zed\Oms\OmsDependencyProvider`, plugin key in the XML = string passed to `->add()`; otherwise `CommandNotFoundException`/`ConditionNotFoundException`):
- Command: `Spryker\Zed\Oms\Dependency\Plugin\Command\CommandByItemInterface::run(SpySalesOrderItem, ReadOnlyArrayObject)` or `CommandByOrderInterface::run(array $orderItems, SpySalesOrder, ReadOnlyArrayObject)`; returns an array. Collection: `CommandCollectionInterface::add($plugin, 'Key')` via `$container->extend(self::COMMAND_PLUGINS, ...)`.
- Condition: `Spryker\Zed\Oms\Dependency\Plugin\Condition\ConditionInterface::check(SpySalesOrderItem): bool`; `ConditionCollectionInterface` via `self::CONDITION_PLUGINS`.
- Timeout processor: `Spryker\Zed\OmsExtension\Dependency\Plugin\TimeoutProcessorPluginInterface` (`getName()`, `getLabel(OmsEventTransfer)`, `calculateTimeout(TimeoutProcessorTimeoutRequestTransfer): TimeoutProcessorTimeoutResponseTransfer`), registered via `getTimeoutProcessorPlugins()` in the Pyz provider; in the release `InitiationTimeoutProcessorPlugin` (`OmsTimeout/Initiation`).
- Further extension points in the `OmsDependencyProvider` (`vendor/spryker/oms-extension/.../Dependency/Plugin/`): `OmsOrderMailExpanderPluginInterface`, `OmsManualEventGrouperPluginInterface`, `OmsEventTriggeredListenerPluginInterface`, `OmsLockPluginInterface`, `ReservationPostSaveTerminationAwareStrategyPluginInterface`, `OmsReservationAggregationPluginInterface`, `OmsReservationReaderStrategyPluginInterface`, `OmsReservationWriterStrategyPluginInterface`, `ReservationRequestExpanderPluginInterface` (reservation/stock: not the topic here).
- Core modules register their own plugins via injector (`Dependency/Injector/OmsDependencyInjector.php`, e.g. `dummy-payment`), active via `KernelConstants::DEPENDENCY_INJECTOR_ZED['Oms']`. Project plugins belong in the Pyz provider.

**Mapping process <-> payment method (configuration, [06-configuration](06-configuration.md))**:
- `OmsConstants::ACTIVE_PROCESSES` (`OmsConfig::getActiveProcesses()`), `SalesConstants::PAYMENT_METHOD_STATEMACHINE_MAPPING` (payment method/provider -> process name), `OmsConstants::PROCESS_LOCATION` (directories, default `OmsConfig::DEFAULT_PROCESS_LOCATION` = `APPLICATION_ROOT_DIR/config/Zed/oms`).
- Release: `config/Shared/config_default.php` (OMS block) activates `ForeignPaymentB2CStateMachine01`, mapping `PaymentConfig::PAYMENT_FOREIGN_PROVIDER`, `PROCESS_LOCATION` additionally `vendor/spryker/sales-payment/config/Zed/Oms`; the assignment uses `=`. `config/Shared/common/config_oms-development.php` (via `require` from `config_default-docker.dev.php`, `-docker.demo`, `-docker.production`, `config_default-ci.php`) prepends `DummyPayment01` and `Nopayment01` via `array_merge`/`array_replace`. Effective live (`docker.dev`, read via `Config::get()`): `DummyPayment01`, `Nopayment01`, `ForeignPaymentB2CStateMachine01`. `config_default-docker.php` (sets via `=` only `DummyPayment01`, line 123) is not loaded for `docker.dev`. Therefore **append** your own processes (`array_merge` or `[] =`), never reassign via `=`.
- No store-specific mapping found in `OmsConfig` `[Assumption]`: store dependency only via different configuration files. The process must be in `ACTIVE_PROCESSES` and in the mapping, otherwise saving fails `[Assumption]`.

**Process cache (re-checked against the code):** `OmsConfig::isProcessCacheEnabled()` reads `OmsConstants::ENABLE_PROCESS_CACHE` with default **`true`**; not set in `config/`. `Builder::createProcess($name, $regenerateCache)` reads the cached process when the cache is active and only recreates it if not cached or `$regenerateCache`. `oms:process-cache:warm-up` calls `getProcesses(true)` (regenerates). Path `OmsConfig::getProcessCachePath()` (default `/data/cache/oms/process/`). Consequence: a change to an existing process XML only takes effect after the warm-up. Install recipes call the warm-up (`config/install/development.yml`, `ci.yml`).

**Generic StateMachine** (`vendor/spryker/state-machine`): entity-independent engine, XML schema `spryker:state-machine-01`, plugins work on `StateMachineItemTransfer`, connection via `StateMachineHandlerInterface` per state machine name; the handler provides command/condition plugins itself (unlike the OMS collections). In the release only as an example `src/Pyz/Zed/ExampleStateMachine/`, `config/Zed/StateMachine/Test/Invoice01.xml`, `Pyz\Zed\StateMachine\StateMachineDependencyProvider::getStateMachineHandlers()`. Assessment: order items -> OMS, other life cycles -> StateMachine module.

**Back Office:** `Administration -> OMS` (`oms/index/index`); `IndexController::drawAction` draws the process (Graphviz needed in the Zed container `[Assumption]`); manual events via `TriggerController` on the order detail page (`manual="true"`). Display names: `display="oms.state.xxx"`, fallback prefix `Pyz\Zed\Oms\OmsConfig::getFallbackDisplayNamePrefix()` = `'oms.state.'`. Transition log: `OmsConfig::isOmsTransitionLogEnabled()`.

## Guide

1. **Model the process**: XML in `config/Zed/oms/<Name>01.xml` (pattern: [oms-process](../templates/oms-process/README.md)). New variant = new name (`...02`); running orders stay on the old version. Do not copy core payment subprocesses if they suffice: `vendor/spryker/sales-payment/config/Zed/Oms/Subprocess/` via `PROCESS_LOCATION`.
2. **Write a command**: class in your own project module (template: `Pyz\Zed\OrderPaymentCheck\Communication\Plugin\Oms\Command\`; the Facade of `Pyz\Zed\Oms` is the core Oms Facade), extends `AbstractPlugin`, implements `CommandByOrderInterface` or `CommandByItemInterface`; delegate logic via the Facade.
3. **Write a condition**: implements `ConditionInterface::check(SpySalesOrderItem): bool`, stays thin and delegates to the Facade of your own module; the Facade queries a repository (layers Communication -> Business -> Persistence, no Propel query in the plugin).
4. **Register**: in `Pyz\Zed\Oms\OmsDependencyProvider` `extendCommandPlugins()` (exists) and analogously `extendConditionPlugins()` (new; container key `self::CONDITION_PLUGINS`), key `Namespace/Name`.
5. **Activate**: **append** `OmsConstants::ACTIVE_PROCESSES` + `SalesConstants::PAYMENT_METHOD_STATEMACHINE_MAPPING` **in the effective environment file** (`array_merge` / `array_replace` or `[] =`, e.g. at the end of `config/Shared/common/config_oms-development.php`), do not assign via `=`; check in the Back Office under Administration -> OMS.
6. **Cache and test**: `cache:empty-all`, `oms:process-cache:warm-up`, walk through a new order; check the graph in the Back Office.
7. **Operations**: cron jobs must run (`config/Zed/cronjobs/jenkins.php`: `check-oms-conditions`, `check-oms-timeouts` every minute, `clear-oms-locks` daily at 06:00), otherwise condition/timeout transitions get stuck ([22-console-cron-queue](22-console-cron-queue.md)).

**Console** (registered in `Pyz\Zed\Console\ConsoleDependencyProvider`, lines 223-229) `[live verified (202610.0)]` (executed, exit 0 without output with no orders; `-l 5 -p 1` likewise):
```bash
docker/sdk cli console oms:check-condition [-s <store>] [-l <n>] [-p 1,2]
docker/sdk cli console oms:check-timeout   [-s <store>] [-l <n>] [-p 1,2]
docker/sdk cli console oms:clear-locks
docker/sdk cli console oms:process-cache:warm-up
docker/sdk cli console state-machine:check-condition Test   # the state machine name is a mandatory argument (`Test` in the project)
docker/sdk cli console state-machine:check-timeout Test
docker/sdk cli console state-machine:clear-locks
```
- `state-machine:check-condition`/`check-timeout` without a name end with exit 255 (`HandlerResolver::get(NULL)`); with an unknown name the command reports `State machine "<Name>" was not found.` (exit 0) `[live verified (202610.0)]`.
- `oms:check-condition`: checks all condition transitions without an event (`OmsFacade::checkConditions()`); `-p` for multi-thread setup. `oms:check-timeout`: triggers expired timeouts; with `OmsConfig::isDeferredNewOrderItemProcessingEnabled()` also the initial processing of new items. `oms:clear-locks`: expired locks (`getStateMachineLockerTimeoutInterval()`). `oms:export-reservation` exists in vendor, but is **not** registered in Pyz.

## Examples

Complete template (XML, command, condition, registration): [templates/oms-process](../templates/oms-process/README.md).

Process excerpt with command, condition and timeout (from the template, pattern `config/Zed/oms/DummyPayment01.xml`):
```xml
<!-- config/Zed/oms/ExampleOrder01.xml -->
<transition happy="true"><source>new</source><target>confirmed</target><event>confirm</event></transition>
<transition happy="true" condition="ExampleOrder/HasPayment"><source>confirmed</source><target>paid</target></transition>
<transition><source>confirmed</source><target>cancelled</target><event>cancel unpaid</event></transition>
...
<event name="confirm" onEnter="true" command="ExampleOrder/LogOrderConfirmed"/>
<event name="cancel unpaid" timeout="14 days" timeoutProcessor="OmsTimeout/Initiation"/>
```

Command + condition (core model `vendor/spryker/dummy-payment/.../Plugin/Oms/`):
```php
// src/Pyz/Zed/OrderPaymentCheck/Communication/Plugin/Oms/Condition/HasPaymentConditionPlugin.php
// @method \Pyz\Zed\OrderPaymentCheck\Business\OrderPaymentCheckFacadeInterface getFacade()
class HasPaymentConditionPlugin extends AbstractPlugin implements ConditionInterface
{
    public function check(SpySalesOrderItem $orderItem): bool
    {
        return $this->getFacade()->hasOrderPayment((int)$orderItem->getFkSalesOrder());
    }
}
// Business/OrderPaymentCheckFacade.php
public function hasOrderPayment(int $idSalesOrder): bool
{
    return $this->getRepository()->hasSalesPaymentByIdSalesOrder($idSalesOrder);
}
// Persistence/OrderPaymentCheckRepository.php (query from the PersistenceFactory: getSalesPaymentPropelQuery())
public function hasSalesPaymentByIdSalesOrder(int $idSalesOrder): bool
{
    return $this->getFactory()->getSalesPaymentPropelQuery()->filterByFkSalesOrder($idSalesOrder)->exists();
}
// src/Pyz/Zed/OrderPaymentCheck/Communication/Plugin/Oms/Command/LogOrderConfirmedCommandPlugin.php
class LogOrderConfirmedCommandPlugin extends AbstractPlugin implements CommandByOrderInterface
{
    public function run(array $orderItems, SpySalesOrder $orderEntity, ReadOnlyArrayObject $data): array { /* ... */ return []; }
}
```
The plugins live in the helper module `OrderPaymentCheck` (Facade -> repository), not in `Pyz\Zed\Oms`; module name without `Example` (`DisallowedModuleNameRule`). Plugin registration stays in `Pyz\Zed\Oms\OmsDependencyProvider`. The dummy variant with a direct `SpySalesPaymentQuery` in the plugin (core model `DummyPayment`) violates the layer separation and must not be replicated in the project.
(Covariant return types are permitted compared to the untyped core interfaces.)

Registration, real excerpt `src/Pyz/Zed/Oms/OmsDependencyProvider.php`:
```php
protected function extendCommandPlugins(Container $container): Container
{
    $container->extend(self::COMMAND_PLUGINS, function (CommandCollectionInterface $commandCollection) {
        $commandCollection->add(new SendOrderConfirmationPlugin(), 'Oms/SendOrderConfirmation');
        $commandCollection->add(new GenerateOrderInvoiceCommandPlugin(), 'Invoice/Generate');
        // ... Payment/Capture, Payment/Refund, Return/StartReturn, OrderAmendment/* ...
        return $commandCollection;
    });

    return $container;
}
```
The core dummy example registers via injector with `Spryker\Zed\Oms\Communication\Plugin\Oms\Command\CommandCollectionInterface` (legacy namespace); in the project use `Spryker\Zed\Oms\Dependency\Plugin\Command\CommandCollectionInterface` (as Pyz does).

Timeout processor (real): `InitiationTimeoutProcessorPlugin::getName()` -> `'OmsTimeout/Initiation'`, `calculateTimeout()` delegates to `$this->getFacade()->calculateInitiationTimeout($transfer)` (Pyz `OmsFacade`/`OmsBusinessFactory::createInitiationTimeoutCalculator()`; pattern for project overrides, [03-extending-the-core](03-extending-the-core.md)).

Generic StateMachine (real, handler): `TestStateMachineHandlerPlugin` implements `StateMachineHandlerInterface` with `getCommandPlugins()` (key `'Test/Command'`, `CommandPluginInterface::run(StateMachineItemTransfer): bool`), `getConditionPlugins()`, `getStateMachineName()` (`'Test'`), `getActiveProcesses()`, `getInitialStateForProcess()`, `itemStateUpdated()`, `getStateMachineItemsByStateIds()`. Start `StateMachineFacade::triggerForNewStateMachineItem()`, events `triggerEvent()`.

## Pitfalls
- Plugin key in the XML != key passed to `->add()` -> `CommandNotFoundException`/`ConditionNotFoundException` (`vendor/spryker/oms/src/Spryker/Zed/Oms/Exception/`).
- Process not in `ACTIVE_PROCESSES` or not in the payment method mapping -> the order cannot be saved `[Assumption]`.
- Typos in states/events -> `LogicException` on loading; names are case-sensitive.
- **The process cache is on by default:** an XML change without `oms:process-cache:warm-up` has no effect (re-checked).
- Dev processes (`DummyPayment01`, `Nopayment01`) only come via `config_oms-development.php` (`config_default-docker.php` is not loaded for `docker.dev`); **append** your own processes there or in the environment file, an `=` assignment would replace the list; in production your own process (copy into `config/Zed/oms/`, name with version suffix) based on `ForeignPaymentB2CStateMachine01`.
- Cron jobs `oms:check-condition` / `oms:check-timeout` not active -> timeout/condition transitions get stuck.
- Long-running commands block locks; clean up via `oms:clear-locks`.
- Keep commands thin, business logic in your own Facade, no layer boundary violation ([01-architecture](01-architecture.md)).
- Project customization only in `Pyz` (plugins via DependencyProvider, XML in `config/Zed/oms`), never in `vendor/` ([28-upgradability](28-upgradability.md)).
- `CommandByOrderInterface` receives the items bundled (grouped by order and state, `OrderStateMachine`), `CommandByItemInterface` per item; with `ByOrder` the command is determined by the first item (specification in the code).
- **Deprecations** (`vendor/spryker/oms/src/Spryker/Zed/Oms/OmsDependencyProvider.php`): Sales QueryContainer (lines 84, 311) -> `QUERY_CONTAINER_SALES`/`addSalesQueryContainer()`; line 190 -> `getReservationPostSaveTerminationAwareStrategyPlugins()`; lines 202/345 -> `getOmsReservationAggregationPlugins()`; line 212 -> `getOmsReservationAggregationQueryCriteriaExpanderPlugins()`. Pyz already uses the new methods. Re-checked: `Spryker\Zed\Oms\Communication\Plugin\Oms\Command\CommandByOrderInterface` (legacy namespace) is `@deprecated`, replacement `Spryker\Zed\Oms\Dependency\Plugin\Command\CommandByOrderInterface`.
- Docs page "common pitfalls in OMS design" not verified.

## Checklist
- [ ] Process XML `config/Zed/oms/<Name>NN.xml`, state names and plugin keys consistent
- [ ] Command/condition registered in `OmsDependencyProvider` (identical key)
- [ ] Process appended (not assigned) to `ACTIVE_PROCESSES` and `PAYMENT_METHOD_STATEMACHINE_MAPPING`, in the effective environment file
- [ ] Condition/command: plugin thin, logic in the Facade, DB access in the repository
- [ ] Glossary keys for `display="oms.state.*"` present
- [ ] `cache:empty-all` and `oms:process-cache:warm-up`
- [ ] Cron jobs `check-oms-conditions`, `check-oms-timeouts` active; `scheduler:setup` after a job change
- [ ] Process graph checked in the Back Office, test order walked through
- [ ] New process version instead of a change for running orders

## Sources
Docs (base `https://docs.spryker.com/docs/`; content not retrievable, not verified): `pbc/all/order-management-system/latest/base-shop/state-machine-cookbook/state-machine-cookbook.html`, `.../state-machine-cookbook-build-a-state-machine.html`, `.../datapayload-conversion/state-machine/order-process-modelling-via-state-machines.html`, `.../state-machine-console-commands.html`, `.../common-pitfalls-in-oms-design.html`, `dg/dev/backend-development/data-manipulation/set-up-an-order-management-system.html`.

Code: `config/Zed/oms/*`, `config/Zed/StateMachine/Test/Invoice01.xml`, `config/Shared/config_default.php` (OMS block), `config/Shared/config_default-docker.dev.php`, `config/Shared/common/config_oms-development.php`, `config/Zed/cronjobs/jenkins.php`, `src/Pyz/Zed/Oms/*`, `src/Pyz/Zed/StateMachine/*`, `src/Pyz/Zed/ExampleStateMachine/*`, `src/Pyz/Zed/Console/ConsoleDependencyProvider.php`, `vendor/spryker/oms/` (`Business/OrderStateMachine/Builder.php`, `OmsConfig`, `OmsDependencyProvider`, `Communication/Console`, `Communication/Controller`), `vendor/spryker/oms-extension/`, `vendor/spryker/dummy-payment/`, `vendor/spryker/sales-payment/config/Zed/Oms/`, `vendor/spryker/state-machine/`.

Neighbors: [11-cart-price-checkout](11-cart-price-checkout.md), [22-console-cron-queue](22-console-cron-queue.md), [03-extending-the-core](03-extending-the-core.md), [18-back-office](18-back-office.md), [05-propel-persistence](05-propel-persistence.md). Template: [oms-process](../templates/oms-process/README.md).
