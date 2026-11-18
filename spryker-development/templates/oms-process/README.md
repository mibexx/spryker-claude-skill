# Template: oms-process (process ExampleOrder01)

## Purpose
Minimal OMS process `ExampleOrder01` with all three mechanisms: command (event with `onEnter`), condition (transition without event, via `oms:check-condition`), timeout (event with `timeout`, via `oms:check-timeout`), plus manual events. Independent of the running example ProductBadge. Background: [10-oms-state-machine](../../references/10-oms-state-machine.md).

Process graph: `new` --confirm(onEnter, command)--> `confirmed` --(condition `ExampleOrder/HasPayment`)--> `paid` --ship(manual)--> `shipped` --close(manual)--> `closed`; `confirmed` --cancel unpaid(timeout 14 days)--> `cancelled`.

## File list (target path)
| File | Role |
|---|---|
| `config/Zed/oms/ExampleOrder01.xml` | process definition (namespace `spryker:oms-01`) |
| `src/Pyz/Zed/OrderPaymentCheck/Communication/Plugin/Oms/Command/LogOrderConfirmedCommandPlugin.php` | command (`CommandByOrderInterface`), XML key `ExampleOrder/LogOrderConfirmed` |
| `src/Pyz/Zed/OrderPaymentCheck/Communication/Plugin/Oms/Condition/HasPaymentConditionPlugin.php` | condition (`ConditionInterface`), XML key `ExampleOrder/HasPayment`, only delegates to the Facade |
| `src/Pyz/Zed/OrderPaymentCheck/Business/OrderPaymentCheckFacade(Interface).php`, `...BusinessFactory.php` | Facade `hasOrderPayment(int $idSalesOrder): bool` |
| `src/Pyz/Zed/OrderPaymentCheck/Persistence/OrderPaymentCheckRepository(Interface).php`, `...PersistenceFactory.php` | repository with the Propel query on `spy_sales_payment` (`getSalesPaymentPropelQuery()`) |
| `tests/PyzTest/Zed/OrderPaymentCheck/{codeception.yml,_support/OrderPaymentCheckBusinessTester.php,Business/ExampleOrderProcessTest.php}` | test with a real order (command, condition, manual events, timeout) |

**Helper module `OrderPaymentCheck`:** plugins belong in a separate small project module, not in `Pyz\Zed\Oms` (its Facade is the core Oms Facade). Layer order plugin (Communication) -> Facade (Business) -> repository (Persistence); the condition contains no Propel query. The module name deliberately does not contain `Example`: the Architecture Sniffer rule `DisallowedModuleNameRule` (project ruleset) forbids `test|dummy|example|antelope` in the module name. The module needs neither schema nor Transfer nor DependencyProvider (the entity `Orm\Zed\Payment\Persistence\SpySalesPaymentQuery` belongs to the core module `spryker/payment`). Your own rules: replace the Facade method and the repository method; plugin classes stay thin.

## Placeholders / customization
- Process name `ExampleOrder01` (file name = process name + `.xml`; new version = new name `ExampleOrder02`, running orders stay on the old version).
- Plugin keys (`ExampleOrder/...`) must be identical in the XML and in `->add()`, otherwise `CommandNotFoundException` / `ConditionNotFoundException`.
- The condition is an example (payment record present); the real rule belongs in the Facade, the DB access in the repository (implemented this way in the template).
- State names `display="oms.state.<x>"` need glossary entries; in the demo glossary (`data/import/common/common/glossary.csv`) `oms.state.new/confirmed/paid/shipped/closed/canceled` exist. For your own states add your own keys, otherwise the key is displayed. See [19-multi-store-i18n](../../references/19-multi-store-i18n.md).

## Registration (excerpts in existing files)

1. Command and condition, `src/Pyz/Zed/Oms/OmsDependencyProvider.php`:
```php
use Pyz\Zed\OrderPaymentCheck\Communication\Plugin\Oms\Command\LogOrderConfirmedCommandPlugin;
use Pyz\Zed\OrderPaymentCheck\Communication\Plugin\Oms\Condition\HasPaymentConditionPlugin;
use Spryker\Zed\Oms\Dependency\Plugin\Command\CommandCollectionInterface;
use Spryker\Zed\Oms\Dependency\Plugin\Condition\ConditionCollectionInterface;

public function provideBusinessLayerDependencies(Container $container): Container
{
    $container = parent::provideBusinessLayerDependencies($container);
    $container = $this->extendCommandPlugins($container);
    $container = $this->extendConditionPlugins($container); // new

    return $container;
}

protected function extendCommandPlugins(Container $container): Container
{
    $container->extend(self::COMMAND_PLUGINS, function (CommandCollectionInterface $commandCollection) {
        // ... existing entries ...
        $commandCollection->add(new LogOrderConfirmedCommandPlugin(), 'ExampleOrder/LogOrderConfirmed');

        return $commandCollection;
    });

    return $container;
}

protected function extendConditionPlugins(Container $container): Container // new
{
    $container->extend(self::CONDITION_PLUGINS, function (ConditionCollectionInterface $conditionCollection) {
        $conditionCollection->add(new HasPaymentConditionPlugin(), 'ExampleOrder/HasPayment');

        return $conditionCollection;
    });

    return $container;
}
```
The timeout processor `OmsTimeout/Initiation` is already registered in the release via `getTimeoutProcessorPlugins()` (`Pyz\Zed\Oms\Communication\Plugin\Oms\InitiationTimeoutProcessorPlugin`); nothing to do.

2. Activate the process and map it to a payment method. **Append, do not assign:** `config/Shared/config_default.php` sets `ACTIVE_PROCESSES` and the mapping by assignment `=` (`ForeignPaymentB2CStateMachine01`). On top of that, the environment file `config_default-docker.dev.php` (likewise `-docker.demo`, `-docker.production`, `config_default-ci.php`) loads `common/config_oms-development.php` via `require`, which prepends `DummyPayment01` and `Nopayment01` via `array_merge`/`array_replace`. Live (release 202610.0, `docker.dev`, read via `Config::get()`) exactly `DummyPayment01`, `Nopayment01`, `ForeignPaymentB2CStateMachine01` are therefore effective. A renewed assignment `=` in a later loaded environment file would replace this list (the dummy/foreign processes would be dropped). `config_default-docker.php` is not loaded with `docker.dev`; changes there (e.g. line 123) have no effect. Append the entry in the **effective environment file**, in the demo shop e.g. at the end of `config/Shared/common/config_oms-development.php` (after the existing assignments) or in the file of the respective environment:
```php
$config[OmsConstants::ACTIVE_PROCESSES] = array_merge($config[OmsConstants::ACTIVE_PROCESSES], [
    'ExampleOrder01',
]);
$config[SalesConstants::PAYMENT_METHOD_STATEMACHINE_MAPPING] = array_replace(
    $config[SalesConstants::PAYMENT_METHOD_STATEMACHINE_MAPPING],
    [
        // payment method/provider -> process; here only for testing purposes, e.g. DummyPaymentConfig::PAYMENT_METHOD_INVOICE => 'ExampleOrder01',
    ],
);
```
(`$config[OmsConstants::ACTIVE_PROCESSES][] = 'ExampleOrder01';` does the same for the process list.) Check effectiveness: Back Office Administration -> OMS (the process must be in the list).
The directory `config/Zed/oms` is already contained in `OmsConstants::PROCESS_LOCATION` (default `OmsConfig::DEFAULT_PROCESS_LOCATION`).

## Commands afterwards
```bash
docker/sdk cli console cache:empty-all               # [live verified (202610.0)]
docker/sdk cli console oms:process-cache:warm-up     # [live verified (202610.0)] the process cache is ON by default, otherwise an XML change has no effect
docker/sdk cli console oms:check-condition           # [live verified (202610.0)] normally via cronjob (every minute)
docker/sdk cli console oms:check-timeout             # [live verified (202610.0)] normally via cronjob (every minute)
# Process as a test (Codeception, with a real order): templates/oms-process/tests/PyzTest/Zed/OrderPaymentCheck
docker/sdk cli vendor/bin/codecept run -c tests/PyzTest/Zed/OrderPaymentCheck   # [live verified (202610.0)] 2 tests
```
All four console commands ran live with exit 0. Process graph: Back Office `Administration -> OMS` (live: `ExampleOrder01` is in the list, `GET /oms/index/draw?process=ExampleOrder01&format=svg&font=14&state=` returns `image/svg+xml` with the states, the graph contains `HasPayment`).

**Test with a real order (included in the template, files under `tests/PyzTest/Zed/OrderPaymentCheck/`):** `SalesDataHelper::haveOrder(['unitPrice' => 100], 'ExampleOrder01')` creates an order in the process, `OmsHelper::addCommand()/addCondition()` register the plugins in the test container, `triggerEventForNewOrderItems()` fires `confirm` (onEnter, command runs, item -> `confirmed`), `checkCondition()` leaves the item in `confirmed` without a payment and sets it to `paid` with a row in `spy_sales_payment`, `OmsFacade::triggerEventForOrderItems('ship'|'close', ...)` leads to `shipped` and `closed`; `moveItemAfterTimeOut()` plus `checkTimeout()` moves an unpaid item to `cancelled`. `codecept run` works for this Zed business suite also in normal mode via `docker/sdk cli` (no testing mode needed); beforehand `docker/sdk cli vendor/bin/codecept build`.

## Source used (model)
- `config/Zed/oms/DummyPayment01.xml`, `config/Zed/oms/Nopayment01.xml`
- `vendor/spryker/dummy-payment/src/Spryker/Zed/DummyPayment/Communication/Plugin/Oms/{Command/PayPlugin,Condition/IsPayedPlugin}.php`
- `src/Pyz/Zed/Oms/OmsDependencyProvider.php` (`extendCommandPlugins()`), `src/Pyz/Zed/Oms/Communication/Plugin/Oms/InitiationTimeoutProcessorPlugin.php`
- Interfaces: `Spryker\Zed\Oms\Dependency\Plugin\Command\CommandByOrderInterface`, `...\Condition\ConditionInterface`, collections `...\Command\CommandCollectionInterface`, `...\Condition\ConditionCollectionInterface`

## Verification status
Walked through in shop 202610.0: registration (command, condition, `ACTIVE_PROCESSES` in `config_oms-development.php`) as described, process visible and drawn in the Back Office, command, condition, manual events and timeout executed with a real order in the Codeception test (2 tests green), `code:sniff:style` and PHPStan level 6 for `OrderPaymentCheck` without findings. The return type `: array` or `: bool` in the plugins is covariantly permissible compared to the untyped core interfaces. `[live verified (202610.0)]`
