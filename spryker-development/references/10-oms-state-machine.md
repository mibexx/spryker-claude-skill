# OMS und State Machine (Recherche T-10)

> Status: Release 202610.0 (spryker/oms 11.55.0). In der Recherche waren die Doku-Seiten nicht lesbar (nur Navigationsgerüst). Alle Aussagen sind am Code geprüft `[code-verifiziert, Doku nicht abgeglichen]`; die Konsolenbefehle wurden im Container ausgeführt `[live verifiziert (202610.0)]` (siehe Abschnitt Konsole). Die Template-Module wurden in einer Sandbox mit php -l, phpcs und phpstan geprüft (202610.0). Beim Schreiben zusätzlich selbst gegen `vendor/spryker/oms`, `config/Zed/oms/*.xml`, `src/Pyz/Zed/Oms` geprüft (Ergebnisse unten markiert "nachgeprüft").

## Zweck
Bestellpositionen (`spy_sales_order_item`) durch einen XML-modellierten Prozess führen: Zustände, Events, Transitionen, Commands (Aktionen), Conditions (automatische Übergänge), Timeouts. Endet vor: Speichern der Bestellung im Checkout ([11-cart-preis-checkout](11-cart-preis-checkout.md)). Cron/Worker allgemein: [22-console-cron-queue](22-console-cron-queue.md). Template: [oms-process](../templates/oms-process/README.md).

## Konzepte

**Prozessdatei**: `config/Zed/oms/<Prozessname>.xml`, Namespace `spryker:oms-01` (XSD `http://static.spryker.com/oms-01.xsd`). Dateiname = Prozessname (`Builder::loadXmlFromProcessName()`). Eine Datei hat genau einen `<process main="true">`, optional Subprozesse.

Vom `Builder` (`vendor/spryker/oms/src/Spryker/Zed/Oms/Business/OrderStateMachine/Builder.php`) ausgewertete Elemente/Attribute (nachgeprüft):

| Element | Attribute / Kinder | Bedeutung |
|---|---|---|
| `<process>` | `name`, `main="true"`, `file="Sub/X.xml"`, `prefix` | Hauptprozess; `file` verweist auf Subprozess-Datei relativ zum Prozessordner |
| `<subprocesses><process>Name</process>` | Text = Prozessname | bindet Subprozess ein (am Dateiende als `<process name=... file=...>` deklarieren) |
| `<state>` | `name`, `display` (Übersetzungsschlüssel), `reserved="true"` (Bestand reserviert), Kind `<flag>` | Zustand; Flags z. B. `cancellable`, `amendable`, `exclude from customer` |
| `<transition>` | `happy="true"`, `condition="Plugin/Key"`, Kinder `<source>`, `<target>`, `<event>` | Übergang; **ohne** `<event>` prüft `oms:check-condition` ihn automatisch |
| `<event>` | `name`, `manual="true"`, `onEnter="true"`, `timeout="2 hour"`, `timeoutProcessor="OmsTimeout/Initiation"`, `command="Plugin/Key"` | `manual`: Button im Back Office; `onEnter`: läuft sofort beim Betreten des Quellzustands; `timeout`: `oms:check-timeout` löst aus (`DateInterval::createFromDateString`, ungültiges Format -> `ErrorException`) |

Subprozesse: Zustände werden beim Laden mit Prefix + `getSubProcessPrefixDelimiter()` (Default `' - '`) umbenannt; Transitionen im Subprozess referenzieren Hauptprozess-Zustände direkt (Beispiel `config/Zed/oms/DummySubprocess/DummyInvoice01.xml`). Tippfehler in Source/Target/Event -> `LogicException` beim Laden. Zustandsnamen sind case-sensitive Strings (Leerzeichen erlaubt).

**Plugins** (Registrierung in `Pyz\Zed\Oms\OmsDependencyProvider`, Plugin-Key im XML = String bei `->add()`; sonst `CommandNotFoundException`/`ConditionNotFoundException`):
- Command: `Spryker\Zed\Oms\Dependency\Plugin\Command\CommandByItemInterface::run(SpySalesOrderItem, ReadOnlyArrayObject)` oder `CommandByOrderInterface::run(array $orderItems, SpySalesOrder, ReadOnlyArrayObject)`; Rückgabe Array. Collection: `CommandCollectionInterface::add($plugin, 'Key')` über `$container->extend(self::COMMAND_PLUGINS, ...)`.
- Condition: `Spryker\Zed\Oms\Dependency\Plugin\Condition\ConditionInterface::check(SpySalesOrderItem): bool`; `ConditionCollectionInterface` über `self::CONDITION_PLUGINS`.
- Timeout-Processor: `Spryker\Zed\OmsExtension\Dependency\Plugin\TimeoutProcessorPluginInterface` (`getName()`, `getLabel(OmsEventTransfer)`, `calculateTimeout(TimeoutProcessorTimeoutRequestTransfer): TimeoutProcessorTimeoutResponseTransfer`), registriert über `getTimeoutProcessorPlugins()` im Pyz-Provider; im Release `InitiationTimeoutProcessorPlugin` (`OmsTimeout/Initiation`).
- Weitere Extension Points im `OmsDependencyProvider` (`vendor/spryker/oms-extension/.../Dependency/Plugin/`): `OmsOrderMailExpanderPluginInterface`, `OmsManualEventGrouperPluginInterface`, `OmsEventTriggeredListenerPluginInterface`, `OmsLockPluginInterface`, `ReservationPostSaveTerminationAwareStrategyPluginInterface`, `OmsReservationAggregationPluginInterface`, `OmsReservationReaderStrategyPluginInterface`, `OmsReservationWriterStrategyPluginInterface`, `ReservationRequestExpanderPluginInterface` (Reservierung/Bestand: nicht Thema hier).
- Core-Module registrieren eigene Plugins per Injector (`Dependency/Injector/OmsDependencyInjector.php`, z. B. `dummy-payment`), aktiv über `KernelConstants::DEPENDENCY_INJECTOR_ZED['Oms']`. Projekt-Plugins gehören in den Pyz-Provider.

**Zuordnung Prozess <-> Zahlart (Konfiguration, [06-konfiguration](06-konfiguration.md))**:
- `OmsConstants::ACTIVE_PROCESSES` (`OmsConfig::getActiveProcesses()`), `SalesConstants::PAYMENT_METHOD_STATEMACHINE_MAPPING` (Zahlart/Provider -> Prozessname), `OmsConstants::PROCESS_LOCATION` (Verzeichnisse, Default `OmsConfig::DEFAULT_PROCESS_LOCATION` = `APPLICATION_ROOT_DIR/config/Zed/oms`).
- Release: `config/Shared/config_default.php` (OMS-Block) aktiv `ForeignPaymentB2CStateMachine01`, Mapping `PaymentConfig::PAYMENT_FOREIGN_PROVIDER`, `PROCESS_LOCATION` zusätzlich `vendor/spryker/sales-payment/config/Zed/Oms`; die Zuweisung geschieht per `=`. `config/Shared/common/config_oms-development.php` (per `require` aus `config_default-docker.dev.php`, `-docker.demo`, `-docker.production`, `config_default-ci.php`) stellt `DummyPayment01` und `Nopayment01` per `array_merge`/`array_replace` voran. Live wirksam (`docker.dev`, per `Config::get()` gelesen): `DummyPayment01`, `Nopayment01`, `ForeignPaymentB2CStateMachine01`. `config_default-docker.php` (setzt `=` nur `DummyPayment01`, Z. 123) wird bei `docker.dev` nicht geladen. Eigene Prozesse deshalb **anhängen** (`array_merge` bzw. `[] =`), nie per `=` neu zuweisen.
- Keine Store-spezifische Zuordnung in `OmsConfig` gefunden `[Annahme]`: Store-Abhängigkeit nur über unterschiedliche Konfigurationsdateien. Prozess muss in `ACTIVE_PROCESSES` stehen und im Mapping, sonst schlägt das Speichern fehl `[Annahme]`.

**Prozess-Cache (nachgeprüft, schließt Recherche-Lücke):** `OmsConfig::isProcessCacheEnabled()` liest `OmsConstants::ENABLE_PROCESS_CACHE` mit Default **`true`**; in `config/` nicht gesetzt. `Builder::createProcess($name, $regenerateCache)` liest bei aktivem Cache den gecachten Prozess und erzeugt ihn nur neu, wenn nicht gecacht oder `$regenerateCache`. `oms:process-cache:warm-up` ruft `getProcesses(true)` (regeneriert). Pfad `OmsConfig::getProcessCachePath()` (Default `/data/cache/oms/process/`). Folge: Änderung an einer vorhandenen Prozess-XML wirkt erst nach Warm-up. Install-Recipes rufen den Warm-up (`config/install/development.yml`, `ci.yml`).

**Generische StateMachine** (`vendor/spryker/state-machine`): entitätsunabhängiger Motor, XML-Schema `spryker:state-machine-01`, Plugins arbeiten auf `StateMachineItemTransfer`, Anbindung über `StateMachineHandlerInterface` je State-Machine-Name; Handler liefert Command-/Condition-Plugins selbst (anders als OMS-Collections). Im Release nur als Beispiel `src/Pyz/Zed/ExampleStateMachine/`, `config/Zed/StateMachine/Test/Invoice01.xml`, `Pyz\Zed\StateMachine\StateMachineDependencyProvider::getStateMachineHandlers()`. Bewertung: Bestellpositionen -> OMS, andere Lebenszyklen -> StateMachine-Modul.

**Back Office:** `Administration -> OMS` (`oms/index/index`); `IndexController::drawAction` zeichnet den Prozess (Graphviz im Zed-Container nötig `[Annahme]`); manuelle Events über `TriggerController` auf der Bestelldetailseite (`manual="true"`). Anzeigenamen: `display="oms.state.xxx"`, Fallback-Prefix `Pyz\Zed\Oms\OmsConfig::getFallbackDisplayNamePrefix()` = `'oms.state.'`. Transition-Log: `OmsConfig::isOmsTransitionLogEnabled()`.

## Anleitung

1. **Prozess modellieren**: XML in `config/Zed/oms/<Name>01.xml` (Muster: [oms-process](../templates/oms-process/README.md)). Neue Variante = neuer Name (`...02`), laufende Bestellungen bleiben auf der alten Version. Core-Zahlungs-Subprozesse nicht kopieren, wenn ausreichend: `vendor/spryker/sales-payment/config/Zed/Oms/Subprocess/` über `PROCESS_LOCATION`.
2. **Command schreiben**: Klasse in einem eigenen Projektmodul (Template: `Pyz\Zed\OrderPaymentCheck\Communication\Plugin\Oms\Command\`; die Facade von `Pyz\Zed\Oms` ist die Core-Oms-Facade), extends `AbstractPlugin`, implementiert `CommandByOrderInterface` bzw. `CommandByItemInterface`; Logik per Facade delegieren.
3. **Condition schreiben**: implementiert `ConditionInterface::check(SpySalesOrderItem): bool`, bleibt dünn und delegiert an die Facade des eigenen Moduls; die Facade fragt ein Repository ab (Schichten Communication -> Business -> Persistence, keine Propel-Query im Plugin).
4. **Registrieren**: in `Pyz\Zed\Oms\OmsDependencyProvider` `extendCommandPlugins()` (vorhanden) und analog `extendConditionPlugins()` (neu; Container-Key `self::CONDITION_PLUGINS`), Key `Namespace/Name`.
5. **Aktivieren**: `OmsConstants::ACTIVE_PROCESSES` + `SalesConstants::PAYMENT_METHOD_STATEMACHINE_MAPPING` in der **wirksamen Umgebungsdatei anhängen** (`array_merge` / `array_replace` bzw. `[] =`, z. B. am Ende von `config/Shared/common/config_oms-development.php`), nicht per `=` zuweisen; Prüfung im Back Office unter Administration -> OMS.
6. **Cache und Test**: `cache:empty-all`, `oms:process-cache:warm-up`, neue Bestellung durchspielen; Graph im Back Office prüfen.
7. **Betrieb**: Cronjobs müssen laufen (`config/Zed/cronjobs/jenkins.php`: `check-oms-conditions`, `check-oms-timeouts` jede Minute, `clear-oms-locks` täglich 06:00), sonst bleiben Condition-/Timeout-Transitionen hängen ([22-console-cron-queue](22-console-cron-queue.md)).

**Konsole** (in `Pyz\Zed\Console\ConsoleDependencyProvider` registriert, Z. 223-229) `[live verifiziert (202610.0)]` (ausgeführt, Exit 0 ohne Ausgabe bei leerem Bestand; `-l 5 -p 1` ebenfalls):
```bash
docker/sdk cli console oms:check-condition [-s <store>] [-l <n>] [-p 1,2]
docker/sdk cli console oms:check-timeout   [-s <store>] [-l <n>] [-p 1,2]
docker/sdk cli console oms:clear-locks
docker/sdk cli console oms:process-cache:warm-up
docker/sdk cli console state-machine:check-condition Test   # Name der State Machine ist Pflichtargument (im Projekt `Test`)
docker/sdk cli console state-machine:check-timeout Test
docker/sdk cli console state-machine:clear-locks
```
- `state-machine:check-condition`/`check-timeout` ohne Namen enden mit Exit 255 (`HandlerResolver::get(NULL)`); mit unbekanntem Namen meldet der Befehl `State machine "<Name>" was not found.` (Exit 0) `[live verifiziert (202610.0)]`.
- `oms:check-condition`: prüft alle Condition-Transitionen ohne Event (`OmsFacade::checkConditions()`); `-p` für Multi-Thread-Setup. `oms:check-timeout`: löst abgelaufene Timeouts aus; bei `OmsConfig::isDeferredNewOrderItemProcessingEnabled()` auch Erstverarbeitung neuer Items. `oms:clear-locks`: abgelaufene Locks (`getStateMachineLockerTimeoutInterval()`). `oms:export-reservation` existiert im Vendor, ist in Pyz **nicht** registriert.

## Beispiele

Vollständiges Template (XML, Command, Condition, Registrierung): [templates/oms-process](../templates/oms-process/README.md).

Prozess-Auszug mit Command, Condition und Timeout (aus dem Template, Muster `config/Zed/oms/DummyPayment01.xml`):
```xml
<!-- config/Zed/oms/ExampleOrder01.xml -->
<transition happy="true"><source>new</source><target>confirmed</target><event>confirm</event></transition>
<transition happy="true" condition="ExampleOrder/HasPayment"><source>confirmed</source><target>paid</target></transition>
<transition><source>confirmed</source><target>cancelled</target><event>cancel unpaid</event></transition>
...
<event name="confirm" onEnter="true" command="ExampleOrder/LogOrderConfirmed"/>
<event name="cancel unpaid" timeout="14 days" timeoutProcessor="OmsTimeout/Initiation"/>
```

Command + Condition (Core-Vorbild `vendor/spryker/dummy-payment/.../Plugin/Oms/`):
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
// Persistence/OrderPaymentCheckRepository.php (Query aus der PersistenceFactory: getSalesPaymentPropelQuery())
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
Die Plugins liegen im Hilfsmodul `OrderPaymentCheck` (Facade -> Repository), nicht in `Pyz\Zed\Oms`; Modulname ohne `Example` (`DisallowedModuleNameRule`). Plugin-Registrierung bleibt im `Pyz\Zed\Oms\OmsDependencyProvider`. Die Dummy-Variante mit direkter `SpySalesPaymentQuery` im Plugin (Core-Vorbild `DummyPayment`) verletzt die Schichtentrennung und ist im Projekt nicht nachzubauen.
(Kovariante Rückgabetypen sind gegenüber den untypisierten Core-Interfaces zulässig.)

Registrierung, echter Auszug `src/Pyz/Zed/Oms/OmsDependencyProvider.php`:
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
Das Core-Dummy-Beispiel registriert per Injector mit `Spryker\Zed\Oms\Communication\Plugin\Oms\Command\CommandCollectionInterface` (Legacy-Namespace); im Projekt `Spryker\Zed\Oms\Dependency\Plugin\Command\CommandCollectionInterface` nutzen (wie Pyz).

Timeout-Processor (echt): `InitiationTimeoutProcessorPlugin::getName()` -> `'OmsTimeout/Initiation'`, `calculateTimeout()` delegiert an `$this->getFacade()->calculateInitiationTimeout($transfer)` (Pyz-`OmsFacade`/-`OmsBusinessFactory::createInitiationTimeoutCalculator()`; Muster für Projekt-Overrides, [03-core-erweitern](03-core-erweitern.md)).

Generische StateMachine (echt, Handler): `TestStateMachineHandlerPlugin` implementiert `StateMachineHandlerInterface` mit `getCommandPlugins()` (Key `'Test/Command'`, `CommandPluginInterface::run(StateMachineItemTransfer): bool`), `getConditionPlugins()`, `getStateMachineName()` (`'Test'`), `getActiveProcesses()`, `getInitialStateForProcess()`, `itemStateUpdated()`, `getStateMachineItemsByStateIds()`. Start `StateMachineFacade::triggerForNewStateMachineItem()`, Events `triggerEvent()`.

## Fallstricke
- Plugin-Key im XML != Key bei `->add()` -> `CommandNotFoundException`/`ConditionNotFoundException` (`vendor/spryker/oms/src/Spryker/Zed/Oms/Exception/`).
- Prozess nicht in `ACTIVE_PROCESSES` oder nicht im Zahlart-Mapping -> Bestellung kann nicht gespeichert werden `[Annahme]`.
- Tippfehler in Zuständen/Events -> `LogicException` beim Laden; Namen case-sensitive.
- **Prozess-Cache ist per Default an:** XML-Änderung ohne `oms:process-cache:warm-up` bleibt unwirksam (nachgeprüft).
- Dev-Prozesse (`DummyPayment01`, `Nopayment01`) kommen nur über `config_oms-development.php` (`config_default-docker.php` wird bei `docker.dev` nicht geladen); eigene Prozesse dort bzw. in der Umgebungsdatei **anhängen**, eine Zuweisung `=` würde die Liste ersetzen; produktiv eigener Prozess (Kopie nach `config/Zed/oms/`, Name mit Versionssuffix) auf Basis `ForeignPaymentB2CStateMachine01`.
- Cronjobs `oms:check-condition` / `oms:check-timeout` nicht aktiv -> Timeout-/Condition-Transitionen hängen.
- Lang laufende Commands blockieren Locks; Aufräumen per `oms:clear-locks`.
- Commands dünn halten, Geschäftslogik in eigener Facade, keine Schichtgrenzverletzung ([01-architektur](01-architektur.md)).
- Projektanpassung nur in `Pyz` (Plugins über DependencyProvider, XML in `config/Zed/oms`), nie in `vendor/` ([28-upgradability](28-upgradability.md)).
- `CommandByOrderInterface` bekommt die Items gebündelt (Gruppierung nach Order und Zustand, `OrderStateMachine`), `CommandByItemInterface` pro Item; der Command wird bei `ByOrder` vom ersten Item bestimmt (Spezifikation im Code).
- **Deprecations** (`vendor/spryker/oms/src/Spryker/Zed/Oms/OmsDependencyProvider.php`): Sales-QueryContainer (Z. 84, 311) -> `QUERY_CONTAINER_SALES`/`addSalesQueryContainer()`; Z. 190 -> `getReservationPostSaveTerminationAwareStrategyPlugins()`; Z. 202/345 -> `getOmsReservationAggregationPlugins()`; Z. 212 -> `getOmsReservationAggregationQueryCriteriaExpanderPlugins()`. Pyz nutzt bereits die neuen Methoden. Nachgeprüft: `Spryker\Zed\Oms\Communication\Plugin\Oms\Command\CommandByOrderInterface` (Legacy-Namespace) ist `@deprecated`, Ersatz `Spryker\Zed\Oms\Dependency\Plugin\Command\CommandByOrderInterface`.
- Doku-Seite "common pitfalls in OMS design" nicht verifiziert.

## Checkliste
- [ ] Prozess-XML `config/Zed/oms/<Name>NN.xml`, Zustandsnamen und Plugin-Keys konsistent
- [ ] Command/Condition in `OmsDependencyProvider` registriert (Key identisch)
- [ ] Prozess in `ACTIVE_PROCESSES` und `PAYMENT_METHOD_STATEMACHINE_MAPPING` angehängt (nicht zugewiesen), in der wirksamen Umgebungsdatei
- [ ] Condition/Command: Plugin dünn, Logik in Facade, DB-Zugriff im Repository
- [ ] Glossar-Schlüssel für `display="oms.state.*"` vorhanden
- [ ] `cache:empty-all` und `oms:process-cache:warm-up`
- [ ] Cronjobs `check-oms-conditions`, `check-oms-timeouts` aktiv; `scheduler:setup` nach Job-Änderung
- [ ] Prozessgraph im Back Office geprüft, Testbestellung durchgespielt
- [ ] Neue Prozessversion statt Änderung für laufende Bestellungen

## Quellen
Doku (Basis `https://docs.spryker.com/docs/`; Inhalt nicht abrufbar, nicht verifiziert): `pbc/all/order-management-system/latest/base-shop/state-machine-cookbook/state-machine-cookbook.html`, `.../state-machine-cookbook-build-a-state-machine.html`, `.../datapayload-conversion/state-machine/order-process-modelling-via-state-machines.html`, `.../state-machine-console-commands.html`, `.../common-pitfalls-in-oms-design.html`, `dg/dev/backend-development/data-manipulation/set-up-an-order-management-system.html`.

Code: `config/Zed/oms/*`, `config/Zed/StateMachine/Test/Invoice01.xml`, `config/Shared/config_default.php` (OMS-Block), `config/Shared/config_default-docker.dev.php`, `config/Shared/common/config_oms-development.php`, `config/Zed/cronjobs/jenkins.php`, `src/Pyz/Zed/Oms/*`, `src/Pyz/Zed/StateMachine/*`, `src/Pyz/Zed/ExampleStateMachine/*`, `src/Pyz/Zed/Console/ConsoleDependencyProvider.php`, `vendor/spryker/oms/` (`Business/OrderStateMachine/Builder.php`, `OmsConfig`, `OmsDependencyProvider`, `Communication/Console`, `Communication/Controller`), `vendor/spryker/oms-extension/`, `vendor/spryker/dummy-payment/`, `vendor/spryker/sales-payment/config/Zed/Oms/`, `vendor/spryker/state-machine/`.

Nachbarn: [11-cart-preis-checkout](11-cart-preis-checkout.md), [22-console-cron-queue](22-console-cron-queue.md), [03-core-erweitern](03-core-erweitern.md), [18-back-office](18-back-office.md), [05-propel-persistenz](05-propel-persistenz.md). Template: [oms-process](../templates/oms-process/README.md).
