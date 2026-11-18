# Template: oms-process (Prozess ExampleOrder01)

## Zweck
Minimaler OMS-Prozess `ExampleOrder01` mit allen drei Mechanismen: Command (Event mit `onEnter`), Condition (Transition ohne Event, per `oms:check-condition`), Timeout (Event mit `timeout`, per `oms:check-timeout`), plus manuelle Events. Unabhängig vom Laufbeispiel ProductBadge. Hintergrund: [10-oms-state-machine](../../references/10-oms-state-machine.md).

Prozessgraph: `new` --confirm(onEnter, Command)--> `confirmed` --(Condition `ExampleOrder/HasPayment`)--> `paid` --ship(manual)--> `shipped` --close(manual)--> `closed`; `confirmed` --cancel unpaid(Timeout 14 days)--> `cancelled`.

## Dateiliste (Zielpfad)
| Datei | Rolle |
|---|---|
| `config/Zed/oms/ExampleOrder01.xml` | Prozessdefinition (Namespace `spryker:oms-01`) |
| `src/Pyz/Zed/OrderPaymentCheck/Communication/Plugin/Oms/Command/LogOrderConfirmedCommandPlugin.php` | Command (`CommandByOrderInterface`), XML-Key `ExampleOrder/LogOrderConfirmed` |
| `src/Pyz/Zed/OrderPaymentCheck/Communication/Plugin/Oms/Condition/HasPaymentConditionPlugin.php` | Condition (`ConditionInterface`), XML-Key `ExampleOrder/HasPayment`, delegiert nur an die Facade |
| `src/Pyz/Zed/OrderPaymentCheck/Business/OrderPaymentCheckFacade(Interface).php`, `...BusinessFactory.php` | Facade `hasOrderPayment(int $idSalesOrder): bool` |
| `src/Pyz/Zed/OrderPaymentCheck/Persistence/OrderPaymentCheckRepository(Interface).php`, `...PersistenceFactory.php` | Repository mit der Propel-Abfrage auf `spy_sales_payment` (`getSalesPaymentPropelQuery()`) |
| `tests/PyzTest/Zed/OrderPaymentCheck/{codeception.yml,_support/OrderPaymentCheckBusinessTester.php,Business/ExampleOrderProcessTest.php}` | Test mit echter Bestellung (Command, Condition, manuelle Events, Timeout) |

**Hilfsmodul `OrderPaymentCheck`:** Plugins gehören in ein eigenes kleines Projektmodul, nicht in `Pyz\Zed\Oms` (dessen Facade ist die Core-Oms-Facade). Schichtenfolge Plugin (Communication) -> Facade (Business) -> Repository (Persistence); die Condition enthält keine Propel-Abfrage. Der Modulname enthält bewusst nicht `Example`: die Architecture-Sniffer-Regel `DisallowedModuleNameRule` (Project-Ruleset) verbietet `test|dummy|example|antelope` im Modulnamen. Das Modul braucht weder Schema noch Transfer noch DependencyProvider (die Entity `Orm\Zed\Payment\Persistence\SpySalesPaymentQuery` gehört dem Core-Modul `spryker/payment`). Eigene Regeln: Facade-Methode und Repository-Methode ersetzen, Plugin-Klassen bleiben dünn.

## Platzhalter / Anpassung
- Prozessname `ExampleOrder01` (Dateiname = Prozessname + `.xml`; neue Version = neuer Name `ExampleOrder02`, laufende Bestellungen bleiben auf der alten Version).
- Plugin-Keys (`ExampleOrder/...`) müssen im XML und bei `->add()` identisch sein, sonst `CommandNotFoundException` / `ConditionNotFoundException`.
- Die Condition ist ein Beispiel (Zahlungsdatensatz vorhanden); die echte Regel gehört in die Facade, der DB-Zugriff ins Repository (so im Template umgesetzt).
- Zustandsnamen `display="oms.state.<x>"` brauchen Glossar-Einträge; im Demo-Glossar (`data/import/common/common/glossary.csv`) existieren `oms.state.new/confirmed/paid/shipped/closed/canceled`. Bei eigenen Zuständen eigene Schlüssel ergänzen, sonst erscheint der Schlüssel. Siehe [19-multi-store-i18n](../../references/19-multi-store-i18n.md).

## Registrierung (Ausschnitte in bestehenden Dateien)

1. Command und Condition, `src/Pyz/Zed/Oms/OmsDependencyProvider.php`:
```php
use Pyz\Zed\OrderPaymentCheck\Communication\Plugin\Oms\Command\LogOrderConfirmedCommandPlugin;
use Pyz\Zed\OrderPaymentCheck\Communication\Plugin\Oms\Condition\HasPaymentConditionPlugin;
use Spryker\Zed\Oms\Dependency\Plugin\Command\CommandCollectionInterface;
use Spryker\Zed\Oms\Dependency\Plugin\Condition\ConditionCollectionInterface;

public function provideBusinessLayerDependencies(Container $container): Container
{
    $container = parent::provideBusinessLayerDependencies($container);
    $container = $this->extendCommandPlugins($container);
    $container = $this->extendConditionPlugins($container); // neu

    return $container;
}

protected function extendCommandPlugins(Container $container): Container
{
    $container->extend(self::COMMAND_PLUGINS, function (CommandCollectionInterface $commandCollection) {
        // ... bestehende Einträge ...
        $commandCollection->add(new LogOrderConfirmedCommandPlugin(), 'ExampleOrder/LogOrderConfirmed');

        return $commandCollection;
    });

    return $container;
}

protected function extendConditionPlugins(Container $container): Container // neu
{
    $container->extend(self::CONDITION_PLUGINS, function (ConditionCollectionInterface $conditionCollection) {
        $conditionCollection->add(new HasPaymentConditionPlugin(), 'ExampleOrder/HasPayment');

        return $conditionCollection;
    });

    return $container;
}
```
Der Timeout-Processor `OmsTimeout/Initiation` ist im Release bereits über `getTimeoutProcessorPlugins()` registriert (`Pyz\Zed\Oms\Communication\Plugin\Oms\InitiationTimeoutProcessorPlugin`); nichts zu tun.

2. Prozess aktivieren und einer Zahlart zuordnen. **Anhängen, nicht zuweisen:** `config/Shared/config_default.php` setzt `ACTIVE_PROCESSES` und das Mapping per Zuweisung `=` (`ForeignPaymentB2CStateMachine01`). Darüber lädt die Umgebungsdatei `config_default-docker.dev.php` (ebenso `-docker.demo`, `-docker.production`, `config_default-ci.php`) per `require` `common/config_oms-development.php`, die `DummyPayment01` und `Nopayment01` per `array_merge`/`array_replace` voranstellt. Live (Release 202610.0, `docker.dev`, per `Config::get()` gelesen) wirksam sind deshalb genau `DummyPayment01`, `Nopayment01`, `ForeignPaymentB2CStateMachine01`. Eine erneute Zuweisung `=` in einer später geladenen Umgebungsdatei würde diese Liste ersetzen (Dummy-/Foreign-Prozesse fielen weg). `config_default-docker.php` wird bei `docker.dev` nicht geladen; Änderungen dort (z. B. Z. 123) sind wirkungslos. Eintrag in der **wirksamen Umgebungsdatei** anhängen, im Demo-Shop z. B. am Ende von `config/Shared/common/config_oms-development.php` (nach den bestehenden Zuweisungen) oder in der Datei der jeweiligen Umgebung:
```php
$config[OmsConstants::ACTIVE_PROCESSES] = array_merge($config[OmsConstants::ACTIVE_PROCESSES], [
    'ExampleOrder01',
]);
$config[SalesConstants::PAYMENT_METHOD_STATEMACHINE_MAPPING] = array_replace(
    $config[SalesConstants::PAYMENT_METHOD_STATEMACHINE_MAPPING],
    [
        // Zahlart/Provider -> Prozess; hier nur zu Testzwecken, z. B. DummyPaymentConfig::PAYMENT_METHOD_INVOICE => 'ExampleOrder01',
    ],
);
```
(`$config[OmsConstants::ACTIVE_PROCESSES][] = 'ExampleOrder01';` leistet für die Prozessliste dasselbe.) Wirksamkeit prüfen: Back Office Administration -> OMS (Prozess muss in der Liste stehen).
Verzeichnis `config/Zed/oms` ist bereits in `OmsConstants::PROCESS_LOCATION` (Default `OmsConfig::DEFAULT_PROCESS_LOCATION`) enthalten.

## Befehle danach
```bash
docker/sdk cli console cache:empty-all               # [live verifiziert (202610.0)]
docker/sdk cli console oms:process-cache:warm-up     # [live verifiziert (202610.0)] Prozess-Cache ist per Default AN, XML-Änderung wirkt sonst nicht
docker/sdk cli console oms:check-condition           # [live verifiziert (202610.0)] normalerweise per Cronjob (jede Minute)
docker/sdk cli console oms:check-timeout             # [live verifiziert (202610.0)] normalerweise per Cronjob (jede Minute)
# Prozess als Test (Codeception, mit echter Bestellung): templates/oms-process/tests/PyzTest/Zed/OrderPaymentCheck
docker/sdk cli vendor/bin/codecept run -c tests/PyzTest/Zed/OrderPaymentCheck   # [live verifiziert (202610.0)] 2 Tests
```
Alle vier Konsolenbefehle liefen live mit Exit 0. Prozessgraph: Back Office `Administration -> OMS` (live: `ExampleOrder01` steht in der Liste, `GET /oms/index/draw?process=ExampleOrder01&format=svg&font=14&state=` liefert `image/svg+xml` mit den Zuständen, der Graph enthält `HasPayment`).

**Test mit echter Bestellung (im Template enthalten, Dateien unter `tests/PyzTest/Zed/OrderPaymentCheck/`):** `SalesDataHelper::haveOrder(['unitPrice' => 100], 'ExampleOrder01')` legt eine Bestellung im Prozess an, `OmsHelper::addCommand()/addCondition()` registrieren die Plugins im Test-Container, `triggerEventForNewOrderItems()` feuert `confirm` (onEnter, Command läuft, Item -> `confirmed`), `checkCondition()` lässt das Item ohne Zahlung in `confirmed` und setzt es mit einer Zeile in `spy_sales_payment` auf `paid`, `OmsFacade::triggerEventForOrderItems('ship'|'close', ...)` führt zu `shipped` und `closed`; `moveItemAfterTimeOut()` plus `checkTimeout()` bringt ein unbezahltes Item nach `cancelled`. `codecept run` funktioniert für diese Zed-Business-Suite auch im Normalmodus über `docker/sdk cli` (kein Testing-Modus nötig); vorher `docker/sdk cli vendor/bin/codecept build`.

## Verwendete Quelle (Vorbild)
- `config/Zed/oms/DummyPayment01.xml`, `config/Zed/oms/Nopayment01.xml`
- `vendor/spryker/dummy-payment/src/Spryker/Zed/DummyPayment/Communication/Plugin/Oms/{Command/PayPlugin,Condition/IsPayedPlugin}.php`
- `src/Pyz/Zed/Oms/OmsDependencyProvider.php` (`extendCommandPlugins()`), `src/Pyz/Zed/Oms/Communication/Plugin/Oms/InitiationTimeoutProcessorPlugin.php`
- Interfaces: `Spryker\Zed\Oms\Dependency\Plugin\Command\CommandByOrderInterface`, `...\Condition\ConditionInterface`, Collections `...\Command\CommandCollectionInterface`, `...\Condition\ConditionCollectionInterface`

## Verifikationsstatus
Durchgespielt im Shop 202610.0: Registrierung (Command, Condition, `ACTIVE_PROCESSES` in `config_oms-development.php`) wie beschrieben, Prozess im Back Office sichtbar und gezeichnet, Command, Condition, manuelle Events und Timeout mit echter Bestellung im Codeception-Test ausgeführt (2 Tests grün), `code:sniff:style` und PHPStan Level 6 für `OrderPaymentCheck` ohne Befund. Der Rückgabetyp `: array` bzw. `: bool` in den Plugins ist gegenüber den untypisierten Core-Interfaces kovariant zulässig. `[live verifiziert (202610.0)]`
