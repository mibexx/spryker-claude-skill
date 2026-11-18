# Warenkorb, Preis und Checkout: kundenspezifische Logik (Recherche T-11)

> Status: Release 202610.0 (spryker/kernel 3.87.1). Doku-URLs waren erreichbar, der Seitentext aber nicht auswertbar; alle Aussagen stammen aus dem installierten Code `[code-verifiziert, Doku nicht abgeglichen]`. Konsolenbefehle ausgeführt `[live verifiziert (202610.0)]`. Das Beispielmodul ist nicht ausgeführt; Interface-Signaturen und Transfer-Methoden wurden gegen `vendor/` und `src/Generated` nachgeprüft.

## Zweck
Wo und wie man kundenspezifische Logik in Warenkorb (Cart), Berechnung (Calculation), Preisermittlung und Checkout einhängt. Endet beim Speichern der Bestellung und Anstoßen des OMS; Prozessmodell: [10-oms-state-machine](10-oms-state-machine.md). Plugin-Prinzip: [03-core-erweitern](03-core-erweitern.md), Modul anlegen: [02-projektmodul](02-projektmodul.md), Glue-Cart/Checkout: [12-glue-storefront](12-glue-storefront.md), Rechte: [20-b2b-permissions](20-b2b-permissions.md).

## Konzepte

### Cart-Fluss
Yves `CartController::addAction` -> `CartClient::addItem` (Quote-Storage-Strategie Session oder DB) -> Client-Expander (`CartChangeRequestExpanderPluginInterface::expand(CartChangeTransfer, array $params)`, `Pyz\Client\Cart\CartDependencyProvider::getAddItemsRequestExpanderPlugins()`) -> Zed-Gateway -> `CartFacade` -> `Spryker\Zed\Cart\Business\Model\Operation`.

`Operation::addToCart()` (`vendor/spryker/cart/src/Spryker/Zed/Cart/Business/Model/Operation.php`, `addToCart(CartChangeTransfer): QuoteResponseTransfer`):
1. Quote gesperrt? -> Fehler.
2. `normalizeCartChangeTransfer` (`CartChangeTransferNormalizerPluginInterface`).
3. `preCheckCart` (`CartPreCheckPluginInterface::check(CartChangeTransfer)`; bei `TerminationAwareCartPreCheckPluginInterface` und `terminateOnFailure() === true` bricht die Kette ab).
4. `expandChangedItems` (`ItemExpanderPluginInterface::expandItems(CartChangeTransfer)`: Preis, Produktdaten, GroupKey).
5. `cartStorageProvider->addItems()` (Mergen in den Quote).
6. `executePostSavePlugins` (`CartOperationPostSavePluginInterface::postSave(QuoteTransfer)`).
7. Recalculate (Calculation).
8. `isTerminated` (`CartTerminationPluginInterface::isTerminated(string $terminationEventName, CartChangeTransfer, QuoteTransfer $calculatedQuoteTransfer): bool`).
9. Erfolgsmeldung.

`removeFromCart()`: `CartRemovalPreCheckPluginInterface` -> expand -> removeItems -> postSave -> recalculate -> termination. `reloadItemsInQuote()`: validate -> `PreReloadItemsPluginInterface` -> preCheck -> expand -> addItems -> postSave -> recalculate -> `PostReloadItemsPluginInterface` -> termination.

Extension-Interfaces: `vendor/spryker/cart-extension/src/Spryker/Zed/CartExtension/Dependency/Plugin/` und `.../Client/CartExtension/Dependency/Plugin/`. Registrierung in `src/Pyz/Zed/Cart/CartDependencyProvider.php`: `getExpanderPlugins`, `getCartBeforePreCheckNormalizerPlugins`, `getCartPreCheckPlugins`, `getPostSavePlugins`, `getPreReloadPlugins`, `getPostReloadItemsPlugins`, `getTerminationPlugins`, `getCartRemovalPreCheckPlugins`, `getQuoteChangeObserverPlugins`, `getCartAddItemStrategyPlugins`, `getCartRemoveItemStrategyPlugins`, `getQuoteLockPreResetPlugins`.

**Order Amendment (neu in 202610.0):** Parallele Stacks `getExpanderPluginsForOrderAmendment`, `getCartPreCheckPluginsForOrderAmendment`, `getPreReloadPluginsForOrderAmendment` (+ `...Async` im Core); der Core wählt per `StrategyResolverInterface` über `QuoteTransfer::quoteProcessFlow->name`. Ein eigenes Cart-Plugin muss in den Standard-Stack **und**, falls es beim Ändern bestehender Bestellungen gelten soll, in den `ForOrderAmendment`-Stack. Analog Checkout: `getCheckoutPreConditionsForOrderAmendment`, `getCheckoutOrderSaversForOrderAmendment`, `getCheckoutPostHooksForOrderAmendment`, `getCheckoutPreSaveHooksForOrderAmendment`. Im Standard-Stack steht `CartItemPricePlugin`, im Amendment-Stack `PriceItemExpanderPlugin` + `OriginalSalesOrderItemPriceItemExpanderPlugin`.

### Calculation-Pipeline
- Einstieg `CalculationFacade::recalculateQuote(QuoteTransfer, bool $executeQuotePlugins = true)` und `recalculateOrder(OrderTransfer)`.
- `QuoteCalculatorExecutor::recalculate()`: mappt `QuoteTransfer` -> `CalculableObjectTransfer` (mit `originalQuote`), ruft **in Listenreihenfolge** jeden Calculator `recalculate($calculableObjectTransfer)`, mappt zurück, danach `QuotePostRecalculatePluginStrategyInterface`-Plugins (Pyz: `QuoteSaveQuotePostRecalculateStrategyPlugin`).
- `src/Pyz/Zed/Calculation/CalculationDependencyProvider.php`: `getQuoteCalculatorPluginStack` (Cart/Checkout), `getOrderCalculatorPluginStack` (`recalculateOrder`), `getQuotePostRecalculatePlugins`.
- **Reihenfolge ist Vertrag.** Pyz-Stack: Remove-Plugins (Totals, Discounts, Promotion-Items, CanceledAmount, SalesOrderThreshold-Expense, ...) -> `PriceCalculatorPlugin` -> `ItemProductOptionPriceAggregatorPlugin` -> `ItemSubtotalAggregatorPlugin` -> `SubtotalCalculatorPlugin` -> `AddSalesOrderThresholdExpenseCalculatorPlugin` -> Tax-Rate-Plugins -> `InitialGrandTotalCalculatorPlugin` -> `DiscountCalculationPlugin` -> Discount-Aggregatoren -> `TaxAppCalculationPlugin` -> `PriceToPayAggregatorPlugin` -> `RefundableAmountCalculatorPlugin` -> `CalculateBundlePricesPlugin` -> Totals (`ShipmentTotal`, `ExpenseTotal`, `DiscountTotal`, `RefundTotal`, `TaxTotal`, `GrandTotal`, `NetTotal`) -> `PaymentCalculatorPlugin`.
- Interface: `Spryker\Zed\CalculationExtension\Dependency\Plugin\CalculationPluginInterface::recalculate(CalculableObjectTransfer)`; Änderung am übergebenen Objekt. **Deprecated:** `Spryker\Zed\Calculation\Dependency\Plugin\CalculatorPluginInterface` (arbeitet mit `QuoteTransfer`; Executor unterstützt ihn noch); der im Code-Tag genannte Ersatz `Spryker\Zed\Calculation\Dependency\Plugin\CalculationPluginInterface` ist selbst `@deprecated` (Ersatz: `Spryker\Zed\CalculationExtension\Dependency\Plugin\CalculationPluginInterface`); für neue Plugins direkt das Extension-Interface verwenden.
- Preismodus `Spryker\Shared\Price\PriceConfig::PRICE_MODE_NET = 'NET_MODE'` / `PRICE_MODE_GROSS = 'GROSS_MODE'`. Tax-App: `TaxAppCalculationPlugin` ersetzt laut Kommentar im Pyz-Provider alle Tax-Calculator-Plugins (ersetzte gehören in `TaxAppDependencyProvider::getFallbackQuoteCalculationPlugins`). `/calculation/debug` und `CalculationConstants::TAX_MODE` nicht verifiziert (Konstante in `config/` nicht gefunden `[Annahme]`: Core-Default).

### Preisermittlung
- Zed/Cart: `CartItemPricePlugin::expandItems` -> `PriceCartConnectorFacade::addPriceToItems` -> `PriceManager::addPriceToItems()` (`vendor/spryker/price-cart-connector/.../Business/Manager/PriceManager.php`): Preismodus setzen, `PriceProductFilterTransfer` bauen, `PriceProductFacade::getValidPrices`, `PriceProductExpanderPluginInterface`-Plugins (Pyz: `ProductConfigurationPriceProductExpanderPlugin`), `unitGrossPrice/unitNetPrice` schreiben.
- Preis-Override pro Item: `ItemTransfer::sourceUnitGrossPrice`/`sourceUnitNetPrice` (z. B. Quote Request) ersetzen Katalogpreise. **Deprecated:** `forcedUnitGrossPrice` (`price_cart_connector.transfer.xml`, "Will be removed with a next major release").
- `CartItemPricePreCheckPlugin` (`terminateOnFailure() = true`); `PriceCartConnectorConfig` (Pyz): `IS_ZERO_PRICE_ENABLED_FOR_CART_ACTIONS = false`, `getItemFieldsForIdentifier()` um SKU und QUANTITY erweitert (Volumenpreise: Identifier pro Menge eindeutig). `FilterItemsWithoutPricePlugin` (PreReload) entfernt Items ohne Preis.
- Client/Storage (PDP, Listing): `Spryker\Client\PriceProduct\ProductPriceResolver\ProductPriceResolver` + `PriceProductStorage`.
- Erweiterungspunkte: **Preisdimensionen** (Zed, `src/Pyz/Zed/PriceProduct/PriceProductDependencyProvider.php`): `PriceDimensionQueryCriteriaPluginInterface`, `PriceDimensionAbstractSaverPluginInterface`, `PriceDimensionConcreteSaverPluginInterface`, `PriceProductDimensionExpanderStrategyPluginInterface`, `PriceProductReaderPricesExtractorPluginInterface`; Pyz nutzt Merchant-Relationship und Volumenpreise. Standard `PriceProductConfig::PRICE_DIMENSION_DEFAULT`. **Filter** (Service, `src/Pyz/Service/PriceProduct/PriceProductDependencyProvider.php::getPriceProductDecisionPlugins`): `PriceProductFilterPluginInterface::filter(array, PriceProductFilterTransfer): array` + `getDimensionName()`; Reihenfolge Pyz: `MerchantRelationshipPriceProductFilterPlugin` zuerst (Kommentar: inaktive Merchant-Preise vor `PriceProductVolumeFilterPlugin`), ProductConfiguration, Volume, dann `parent::`. **Client** `Pyz\Client\PriceProductStorage\PriceProductStorageDependencyProvider` (`getPriceDimensionStorageReaderPlugins`, `getPriceProductPricesExtractorPlugins`, `getPriceProductFilterExpanderPlugins`), `Pyz\Client\PriceProduct\PriceProductDependencyProvider::getPriceProductPostResolvePlugins` (`PriceProductPostResolvePluginInterface::postResolve`).
- Merchant-Relationship-Preise (B2B): Module `price-product-merchant-relationship(-storage|-gui|-data-import)`, Preise je Firmen-/Business-Unit-Zuordnung; Sync/Events: [07-publish-sync](07-publish-sync.md), Import: [09-data-import](09-data-import.md).
- **Wahl bei kundenspezifischer Preislogik (Reihenfolge):** (1) Merchant-Relationship per Datenpflege; (2) eigene Preisdimension (aufwendig, schreibt auch Storage); (3) Filter-/Post-Resolve-Plugin zum Entscheiden/Anpassen vorhandener Preise; (4) eigener Calculator für Zu-/Abschläge. `[Annahme]`: kein weiterer dokumentierter "Customer Price"-Extension-Point.

### Checkout
`CheckoutWorkflow::placeOrder()` (`vendor/spryker/checkout/src/Spryker/Zed/Checkout/Business/Workflow/CheckoutWorkflow.php`, nachgeprüft):
1. `checkPreConditions`: alle `CheckoutPreConditionPluginInterface::checkCondition(QuoteTransfer, CheckoutResponseTransfer)` laufen (kein Short-Circuit, Ergebnisse per `&=`); Fehler -> nichts wird gespeichert. Plugin darf Quote nicht ändern, nicht schreiben.
2. `doPreSave`: `CheckoutPreSavePluginInterface::preSave(QuoteTransfer)` (Alt: `CheckoutPreSaveHookInterface`).
3. `doSaveOrder`: **eine DB-Transaktion** mit Retry (`CheckoutConfig::getSaveOrderTransactionMaxAttempts()`, `getRetryableExceptions()`, `getRetryableDriverErrorCodes()`) und Quote-Exklusiv-Lock (`ERROR_CODE_QUOTE_LOCKED`); je Plugin `CheckoutDoSaveOrderInterface::saveOrder(QuoteTransfer, SaveOrderTransfer)` (Alt: `CheckoutSaveOrderInterface` mit `CheckoutResponseTransfer`).
4. `omsFacade->triggerEventForNewOrderItems()` (außer `QuoteTransfer::shouldSkipStateMachineRun`). **Ab hier: [10-oms-state-machine](10-oms-state-machine.md).**
5. `doPostSave`: `CheckoutPostSaveInterface::executeHook(QuoteTransfer, CheckoutResponseTransfer)`.

**Befund 202610.0:** Schritt 4 und 5 laufen in `executeAfterOrderSave()`; ein `Throwable` wird nur geloggt (`error`, `orderReference`) und **nicht** weitergeworfen. Eine Exception im PostSave-Plugin oder OMS-Trigger macht die Bestellung nicht rückgängig und erzeugt keine Checkout-Fehlermeldung. Kritische Logik gehört in OrderSaver. Pflichtreihenfolge der Saver im Pyz-Provider: `OrderSaverPlugin`, `OrderTotalsSaverPlugin`, `SalesOrderShipmentSavePlugin`, `OrderItemsSaverPlugin` (`CustomerOrderSavePlugin` davor). Registrierung: `src/Pyz/Zed/Checkout/CheckoutDependencyProvider.php`: `getCheckoutPreConditions`, `getCheckoutOrderSavers`, `getCheckoutPostHooks`, `getCheckoutPreSaveHooks` (+ `...ForOrderAmendment`); Interfaces in `vendor/spryker/checkout-extension/.../Dependency/Plugin/`. API: `CheckoutFacade::placeOrder`, `isPlaceableOrder`; `CheckoutClient::placeOrder`, `isQuoteApplicableForCheckout`, `isPlaceableOrder`.

### Checkout-Schritte in Yves (StepEngine)
- Modul `vendor/spryker-shop/checkout-page`, Overrides in `src/Pyz/Yves/CheckoutPage/` (`CheckoutPageConfig`, `CheckoutPageDependencyProvider`, `Theme/default`). Routen `CheckoutPageRouteProviderPlugin` (`/checkout`, `/checkout/customer`, `/checkout/address`, `/checkout/shipment`, `/checkout/payment`, `/checkout/summary`, `/checkout/place-order`, `/checkout/error`, `/checkout/success`; Routenname-Konstanten `ROUTE_NAME_CHECKOUT_*`, die alten `CHECKOUT_*`-Konstanten sind `@deprecated`).
- Steps (`Process/StepFactory.php::getSteps()`): `EntryStep`, `CustomerStep`, `AddressStep`, `ShipmentStep`, `PaymentStep`, `SummaryStep`, `PlaceOrderStep`, `SuccessStep`, `ErrorStep`; Basis `Spryker\Yves\StepEngine\Dependency\Step\StepInterface` (`preCondition`, `requireInput`, `execute(Request, AbstractTransfer)`, `postCondition`, `getStepRoute`, `getEscapeRoute`, `getTemplateVariables`).
- Kundenspezifischer Ablauf (andere Schrittfolge): `CheckoutStepResolverStrategyPluginInterface` über `getCheckoutStepResolverStrategyPlugins()` (Pyz: `QuoteRequestCheckoutWorkflowStepResolverStrategyPlugin`, `QuoteWithCustomShipmentPriceCheckoutWorkflowStepResolverStrategyPlugin`, `QuoteRequestAgentCheckoutWorkflowStepResolverStrategyPlugin`, `PaymentAppExpressCheckoutWorkflowCheckoutStepResolverStrategyPlugin`). **Nicht** `StepFactory` überschreiben.
- Weitere Punkte im `CheckoutPageDependencyProvider`: `getCustomerStepSubForms`, `getAddressStepSubForms`, `getCheckoutAddressStepEnterPreCheckPlugins`, `getCheckoutShipmentStepEnterPreCheckPlugins`, `getCheckoutPaymentStepEnterPreCheckPlugins`, `getAddressStepExecutorAddressExpanderPlugins`, `getSummaryPageWidgetPlugins` ([16-widgets](16-widgets.md)), `getPaymentCollectionExtenderPlugins`, `extendPaymentMethodHandler`; Config `getLocalizedTermsAndConditionsPageLinks`, `getExcludedPaymentMethodKeys`. `PlaceOrderStep::execute()` ruft `CheckoutClient::placeOrder`, setzt `orderReference`/`isOrderPlacedSuccessfully`, übersetzt `CheckoutErrorTransfer::message` und mappt `errorCode` auf Fehlerrouten. Templates: [15-yves-frontend-atomic](15-yves-frontend-atomic.md).

## Anleitung
1. **Regel im Warenkorb**: `CartPreCheckPluginInterface` (Validierung, Fehlermeldungen), `ItemExpanderPluginInterface` (Item-Daten), `CartTerminationPluginInterface` (Abbruch nach Berechnung). Registrierung in `CartDependencyProvider` (Standard- **und** Amendment-Stack bedenken).
2. **Zu-/Abschlag**: `CalculationPluginInterface` in `getQuoteCalculatorPluginStack` (und `getOrderCalculatorPluginStack`, wenn bei `recalculateOrder` erhalten) an der richtigen Stelle; idempotent bauen.
3. **Preis**: nach der Entscheidungsreihenfolge oben; Preisänderungen publizieren.
4. **Regel im Checkout**: `CheckoutPreConditionPluginInterface` (zusätzlich zum Cart-Check, da umgehbar), `CheckoutDoSaveOrderInterface` für transaktionale Daten, PostSave nur für nicht-kritische Nachläufer.
5. **Anderer Checkout-Ablauf**: Step-Resolver-Strategy-Plugin, Enter-PreCheck-Plugins, Form-/Handler-Plugins.
6. Transfers nur bei neuen Properties ([04-transfer](04-transfer.md)); Glossar-Schlüssel übersetzen ([19-multi-store-i18n](19-multi-store-i18n.md)); Tests ([26-tests](26-tests.md)).

Befehle `[live verifiziert (202610.0)]` (alle ausgeführt, Exit 0; `data:import:product-price` importiert 1906 Zeilen je Store DE/AT, Upsert):
```bash
docker/sdk cli console transfer:generate         # nur bei eigenen Transfers
docker/sdk cli console cache:empty-all           # DependencyProvider-/Plugin-Änderungen
docker/sdk cli console data:import:product-price # Preise ([09-data-import](09-data-import.md))
docker/sdk cli console publish:trigger-events -r price_product_abstract,price_product_concrete   # mehrere Resources kommagetrennt in einem -r (mehrfaches -r übernimmt nur den letzten Wert); Resource-Namen = PriceProductStorageConstants::PRICE_*_RESOURCE_NAME
```

## Beispiele

Minimalbeispiel Projektmodul `CustomerCartRule` (Modulgerüst Config/Facade/Factory: [02-projektmodul](02-projektmodul.md); nutzt nur Core-Transfers, keine eigene `*.transfer.xml`; **nicht ausgeführt**). Regel: Kunde ohne Firmenzuordnung darf max. N Stück je SKU, plus Handling-Entgelt.

Cart-PreCheck (`CartPreCheckPluginInterface::check(CartChangeTransfer)` ohne Rückgabetyp im Interface, `CartPreCheckResponseTransfer` ist die Antwort):
```php
// src/Pyz/Zed/CustomerCartRule/Communication/Plugin/Cart/CustomerCartRuleCartPreCheckPlugin.php
class CustomerCartRuleCartPreCheckPlugin extends AbstractPlugin implements CartPreCheckPluginInterface
{
    public function check(CartChangeTransfer $cartChangeTransfer): CartPreCheckResponseTransfer
    {
        return $this->getFacade()->checkCartChange($cartChangeTransfer);
    }
}
// im Checker: Mengen aus Quote + CartChange je SKU addieren (verhindert Umgehung durch wiederholtes Hinzufügen)
$responseTransfer = (new CartPreCheckResponseTransfer())->setIsSuccess(true);
// ... bei Verstoß:
$responseTransfer->setIsSuccess(false)->addMessage(
    (new MessageTransfer())->setValue('customer-cart-rule.error.max-quantity')->setParameters(['%sku%' => $sku, '%max%' => $max]),
);
```

Calculator (idempotent; Vorbild `SalesOrderThreshold\...\ExpenseCalculator::createExpenseByPriceMode()`):
```php
// Communication/Plugin/Calculation/HandlingFeeCalculatorPlugin.php implements CalculationPluginInterface
public function recalculate(CalculableObjectTransfer $calculableObjectTransfer): void
{
    $this->getFacade()->calculateHandlingFee($calculableObjectTransfer);
}

// Business/Calculator/HandlingFeeCalculator.php
$expenses = new ArrayObject();
foreach ($calculableObjectTransfer->getExpenses() as $expenseTransfer) {      // eigene Expense vorher entfernen
    if ($expenseTransfer->getType() !== static::EXPENSE_TYPE_HANDLING_FEE) {
        $expenses->append($expenseTransfer);
    }
}
$calculableObjectTransfer->setExpenses($expenses);

$isNet = $calculableObjectTransfer->getPriceMode() === PriceConfig::PRICE_MODE_NET;
$calculableObjectTransfer->addExpense(
    (new ExpenseTransfer())
        ->setType(static::EXPENSE_TYPE_HANDLING_FEE)->setName('customer-cart-rule.handling-fee')
        ->setQuantity(1)->setTaxRate(0.0)                       // Annahme: echtes Entgelt braucht Steuersatz (Vorbild SalesOrderThreshold TaxRateReader)
        ->setUnitPrice($fee)->setSumPrice($fee)
        ->setUnitPriceToPayAggregation($fee)->setSumPriceToPayAggregation($fee)
        ->setUnitGrossPrice($isNet ? 0 : $fee)->setSumGrossPrice($isNet ? 0 : $fee)
        ->setUnitNetPrice($isNet ? $fee : 0)->setSumNetPrice($isNet ? $fee : 0),
);
```
Robuster als Selbst-Entfernen: separates Remove-Plugin am Stack-Anfang (Core: `RemoveSalesOrderThresholdExpenseCalculatorPlugin`).

Checkout-PreCondition (Regel erneut prüfen, nachgeprüft: `CheckoutErrorTransfer` hat `errorCode`, `message` und `parameters`):
```php
class CustomerCartRuleCheckoutPreConditionPlugin extends AbstractPlugin implements CheckoutPreConditionPluginInterface
{
    public function checkCondition(QuoteTransfer $quoteTransfer, CheckoutResponseTransfer $checkoutResponseTransfer): bool
    {
        return $this->getFacade()->checkQuote($quoteTransfer, $checkoutResponseTransfer);   // setIsSuccess(false)->addError(CheckoutErrorTransfer)
    }
}
```
B2B-Variante der Firmenprüfung: `$quoteTransfer->getCustomer()?->getCompanyUserTransfer()` statt `CustomerTransfer::company` (Property existiert, `company` kann bei Firmenkunden leer sein `[Annahme]`).

Registrierung (Auszüge Pyz):
```php
// src/Pyz/Zed/Cart/CartDependencyProvider.php, getCartPreCheckPlugins() (+ getCartPreCheckPluginsForOrderAmendment(), wenn gewünscht)
new CustomerCartRuleCartPreCheckPlugin(),
// src/Pyz/Zed/Calculation/CalculationDependencyProvider.php, getQuoteCalculatorPluginStack():
//   NACH SubtotalCalculatorPlugin / AddSalesOrderThresholdExpenseCalculatorPlugin, VOR ExpenseTotalCalculatorPlugin / GrandTotalCalculatorPlugin
new HandlingFeeCalculatorPlugin(),
// src/Pyz/Zed/Checkout/CheckoutDependencyProvider.php, getCheckoutPreConditions()
new CustomerCartRuleCheckoutPreConditionPlugin(),
```
Echter Auszug `CartDependencyProvider`: `getCartPreCheckPlugins()` listet `ProductExistsCartPreCheckPlugin`, `CheckAvailabilityPlugin`, `CartItemPricePreCheckPlugin`, ... `DiscountPromotionCartPreCheckPlugin`; `getTerminationPlugins()` `ConfiguredBundleQuantityCartTerminationPlugin`, `SalesOrderThresholdCartTerminationPlugin`.

## Fallstricke
- **Plugin nur in einem Stack:** Order-Amendment-Stacks (Cart, Checkout) beachten.
- **Falsche Calculator-Position:** vor `PriceCalculatorPlugin` keine Preise; nach `GrandTotalCalculatorPlugin` kein Effekt im Total; Expense-Calculator vor `ExpenseTotalCalculatorPlugin`, steuerrelevante Felder vor den Tax-Plugins.
- **Nicht idempotenter Calculator:** Quote wird bei jedem Cart-Vorgang neu berechnet, eigene Expenses verdoppeln sich ohne Entfernen.
- **Regel nur im Cart:** PreCheck ist umgehbar (Glue, Gast-Merge, Quote-Änderung); zusätzlich `CheckoutPreConditionPlugin`.
- **Kritische Logik in PostSave/OMS-Trigger:** Exceptions werden nur geloggt, Bestellung bleibt. Transaktionales gehört in `CheckoutDoSaveOrderInterface`.
- Schreibzugriffe in `CheckoutPreConditionPlugin` sind laut Interface-Spezifikation verboten.
- Saver-Reihenfolge einhalten; eigene Saver, die `idSalesOrder` brauchen, danach.
- `forcedUnitGrossPrice` nicht verwenden (deprecated); `sourceUnit*Price` oder Preisdimension/Filter.
- Preisänderung ohne Re-Publish: `price_product_*_storage` bleibt alt (PDP/Client); Zed/Cart liest direkt aus der DB ([07-publish-sync](07-publish-sync.md)).
- Filter-Reihenfolge in `getPriceProductDecisionPlugins`: Merchant-Relationship zuerst.
- `StepFactory`/Controller von CheckoutPage kopieren bricht Upgradability ([28-upgradability](28-upgradability.md)); Strategy-/PreCheck-/Form-Plugins nutzen. Core-Klassen (`Operation`, `CheckoutWorkflow`) nicht kopieren, `vendor/` nie ändern; Core-Plugins nicht ohne Not aus Pyz-Providern entfernen (bricht SalesOrderThreshold, QuoteApproval u. a.).
- Plugins rufen nur die eigene Facade, Fremdmodule über DependencyProvider ([02-projektmodul](02-projektmodul.md)); Yves nur über Client.
- **Deprecations:** `Spryker\Zed\Cart\Dependency\{ItemExpanderPluginInterface, PreReloadItemsPluginInterface, PostSavePluginInterface, CartPreCheckPluginInterface, TerminationAwareCartPreCheckPluginInterface}` `@deprecated` -> `CartExtension\...` (`CartOperationPostSavePluginInterface` für PostSave); `Calculation\Dependency\Plugin\CalculatorPluginInterface` `@deprecated`; `ItemTransfer::forcedUnitGrossPrice` deprecated; `Checkout\Dependency\Plugin\{CheckoutSaveOrderInterface, CheckoutPreSaveHookInterface}` Legacy-Pfad -> `CheckoutExtension\...`; `@deprecated` sind die alten `CHECKOUT_*`-Konstanten in `CheckoutPageRouteProviderPlugin` (Ersatz: `ROUTE_NAME_CHECKOUT_*`, die nicht deprecated sind; der Tag von `CHECKOUT_SUCCESS` verweist im Code fälschlich auf `ROUTE_NAME_CHECKOUT_INDEX`). `Operation::add/remove/reloadItems` (liefern `QuoteTransfer`) sind Alt-API neben `addToCart/removeFromCart/reloadItemsInQuote` (`QuoteResponseTransfer`); `@deprecated`-Tag nicht geprüft `[Annahme]`. Core-Plugins wie `CartItemPricePlugin` nutzen im Release noch das Legacy-Interface (Alias).
- Nicht untersucht: CartPage-Controller-Plugins/Widgets, `quote-approval`, `quote-request`, Discount-Calculator, Shipment-/Payment-Schritte inhaltlich.

## Checkliste
- [ ] Richtigen Extension-Point gewählt (PreCheck / Expander / Calculator / Preisdimension / Filter / Checkout-Plugin)
- [ ] Plugin im Standard- und (falls nötig) Order-Amendment-Stack registriert
- [ ] Calculator idempotent und an korrekter Stack-Position
- [ ] Regel zusätzlich im Checkout (PreCondition) abgesichert
- [ ] Transaktionale Daten im OrderSaver, nicht in PostSave
- [ ] Nur `*Extension`-Interfaces, keine Legacy-/deprecated-Interfaces
- [ ] Glossar-Schlüssel angelegt, Preise publiziert, Cache geleert
- [ ] Plugins delegieren an die eigene Facade; nichts in `vendor/` geändert

## Quellen
Doku (Einstiegspunkte, Erreichbarkeit geprüft, Inhalt nicht ausgewertet; Basis `https://docs.spryker.com/docs/pbc/all/cart-and-checkout/latest/base-shop/`): `extend-and-customize/calculation-3-0.html`, `.../calculator-plugins.html`, `.../calculation-data-structure.html`, `.../cart-module-reference-information.html`, `.../checkout-process-review-and-implementation.html`, `.../checkout-steps.html`, `tutorials-and-howtos/tutorial-calculator-plugin.html`.

Code: `src/Pyz/Zed/{Cart,Calculation,Checkout,PriceCartConnector,PriceProduct,Quote,PersistentCart}/*`, `src/Pyz/Client/{Cart,PriceProduct,PriceProductStorage}/*DependencyProvider.php`, `src/Pyz/Service/PriceProduct/PriceProductDependencyProvider.php`, `src/Pyz/Yves/CheckoutPage/*`; `vendor/spryker/cart/.../Zed/Cart/Business/Model/Operation.php`, `vendor/spryker/cart-extension/`, `calculation/.../QuoteCalculatorExecutor.php`, `calculation-extension/`, `checkout/.../CheckoutWorkflow.php`, `checkout-extension/`, `price-cart-connector/.../PriceManager.php`, `price-product/...`, `sales-order-threshold/.../ExpenseCalculator.php`, `price/.../PriceConfig.php`; `vendor/spryker-shop/checkout-page/...`, `vendor/spryker-shop/cart-page/...`, `vendor/spryker/step-engine/`.

Nachbarn: [10-oms-state-machine](10-oms-state-machine.md), [03-core-erweitern](03-core-erweitern.md), [14-yves-controller-routing](14-yves-controller-routing.md), [12-glue-storefront](12-glue-storefront.md), [20-b2b-permissions](20-b2b-permissions.md).
