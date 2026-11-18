# Cart, price and checkout: customer-specific logic

> Status: Release 202610.0 (spryker/kernel 3.87.1). The docs URLs were reachable, but the page text could not be evaluated; all statements come from the installed code `[code-verified, docs not cross-checked]`. Console commands executed `[live verified (202610.0)]`. The example module was not executed; interface signatures and transfer methods were re-checked against `vendor/` and `src/Generated`.

## Purpose
Where and how to hook customer-specific logic into cart, calculation, price resolution and checkout. Ends at saving the order and triggering the OMS; process model: [10-oms-state-machine](10-oms-state-machine.md). Plugin principle: [03-extending-the-core](03-extending-the-core.md), creating a module: [02-project-module](02-project-module.md), Glue cart/checkout: [12-glue-storefront](12-glue-storefront.md), permissions: [20-b2b-permissions](20-b2b-permissions.md).

## Concepts

### Cart flow
Yves `CartController::addAction` -> `CartClient::addItem` (quote storage strategy session or DB) -> client expanders (`CartChangeRequestExpanderPluginInterface::expand(CartChangeTransfer, array $params)`, `Pyz\Client\Cart\CartDependencyProvider::getAddItemsRequestExpanderPlugins()`) -> Zed gateway -> `CartFacade` -> `Spryker\Zed\Cart\Business\Model\Operation`.

`Operation::addToCart()` (`vendor/spryker/cart/src/Spryker/Zed/Cart/Business/Model/Operation.php`, `addToCart(CartChangeTransfer): QuoteResponseTransfer`):
1. Quote locked? -> error.
2. `normalizeCartChangeTransfer` (`CartChangeTransferNormalizerPluginInterface`).
3. `preCheckCart` (`CartPreCheckPluginInterface::check(CartChangeTransfer)`; with `TerminationAwareCartPreCheckPluginInterface` and `terminateOnFailure() === true` the chain aborts).
4. `expandChangedItems` (`ItemExpanderPluginInterface::expandItems(CartChangeTransfer)`: price, product data, GroupKey).
5. `cartStorageProvider->addItems()` (merging into the quote).
6. `executePostSavePlugins` (`CartOperationPostSavePluginInterface::postSave(QuoteTransfer)`).
7. Recalculate (Calculation).
8. `isTerminated` (`CartTerminationPluginInterface::isTerminated(string $terminationEventName, CartChangeTransfer, QuoteTransfer $calculatedQuoteTransfer): bool`).
9. Success message.

`removeFromCart()`: `CartRemovalPreCheckPluginInterface` -> expand -> removeItems -> postSave -> recalculate -> termination. `reloadItemsInQuote()`: validate -> `PreReloadItemsPluginInterface` -> preCheck -> expand -> addItems -> postSave -> recalculate -> `PostReloadItemsPluginInterface` -> termination.

Extension interfaces: `vendor/spryker/cart-extension/src/Spryker/Zed/CartExtension/Dependency/Plugin/` and `.../Client/CartExtension/Dependency/Plugin/`. Registration in `src/Pyz/Zed/Cart/CartDependencyProvider.php`: `getExpanderPlugins`, `getCartBeforePreCheckNormalizerPlugins`, `getCartPreCheckPlugins`, `getPostSavePlugins`, `getPreReloadPlugins`, `getPostReloadItemsPlugins`, `getTerminationPlugins`, `getCartRemovalPreCheckPlugins`, `getQuoteChangeObserverPlugins`, `getCartAddItemStrategyPlugins`, `getCartRemoveItemStrategyPlugins`, `getQuoteLockPreResetPlugins`.

**Order Amendment (new in 202610.0):** Parallel stacks `getExpanderPluginsForOrderAmendment`, `getCartPreCheckPluginsForOrderAmendment`, `getPreReloadPluginsForOrderAmendment` (+ `...Async` in the core); the core selects via `StrategyResolverInterface` based on `QuoteTransfer::quoteProcessFlow->name`. A custom cart plugin must go into the standard stack **and**, if it should apply when amending existing orders, into the `ForOrderAmendment` stack. Likewise for checkout: `getCheckoutPreConditionsForOrderAmendment`, `getCheckoutOrderSaversForOrderAmendment`, `getCheckoutPostHooksForOrderAmendment`, `getCheckoutPreSaveHooksForOrderAmendment`. The standard stack contains `CartItemPricePlugin`, the amendment stack `PriceItemExpanderPlugin` + `OriginalSalesOrderItemPriceItemExpanderPlugin`.

### Calculation pipeline
- Entry `CalculationFacade::recalculateQuote(QuoteTransfer, bool $executeQuotePlugins = true)` and `recalculateOrder(OrderTransfer)`.
- `QuoteCalculatorExecutor::recalculate()`: maps `QuoteTransfer` -> `CalculableObjectTransfer` (with `originalQuote`), calls **in list order** each calculator's `recalculate($calculableObjectTransfer)`, maps back, then the `QuotePostRecalculatePluginStrategyInterface` plugins (Pyz: `QuoteSaveQuotePostRecalculateStrategyPlugin`).
- `src/Pyz/Zed/Calculation/CalculationDependencyProvider.php`: `getQuoteCalculatorPluginStack` (cart/checkout), `getOrderCalculatorPluginStack` (`recalculateOrder`), `getQuotePostRecalculatePlugins`.
- **The order is a contract.** Pyz stack: remove plugins (totals, discounts, promotion items, CanceledAmount, SalesOrderThreshold expense, ...) -> `PriceCalculatorPlugin` -> `ItemProductOptionPriceAggregatorPlugin` -> `ItemSubtotalAggregatorPlugin` -> `SubtotalCalculatorPlugin` -> `AddSalesOrderThresholdExpenseCalculatorPlugin` -> tax rate plugins -> `InitialGrandTotalCalculatorPlugin` -> `DiscountCalculationPlugin` -> discount aggregators -> `TaxAppCalculationPlugin` -> `PriceToPayAggregatorPlugin` -> `RefundableAmountCalculatorPlugin` -> `CalculateBundlePricesPlugin` -> totals (`ShipmentTotal`, `ExpenseTotal`, `DiscountTotal`, `RefundTotal`, `TaxTotal`, `GrandTotal`, `NetTotal`) -> `PaymentCalculatorPlugin`.
- Interface: `Spryker\Zed\CalculationExtension\Dependency\Plugin\CalculationPluginInterface::recalculate(CalculableObjectTransfer)`; modifies the passed object. **Deprecated:** `Spryker\Zed\Calculation\Dependency\Plugin\CalculatorPluginInterface` (works with `QuoteTransfer`; the executor still supports it); the replacement named in the code tag, `Spryker\Zed\Calculation\Dependency\Plugin\CalculationPluginInterface`, is itself `@deprecated` (replacement: `Spryker\Zed\CalculationExtension\Dependency\Plugin\CalculationPluginInterface`); for new plugins use the extension interface directly.
- Price mode `Spryker\Shared\Price\PriceConfig::PRICE_MODE_NET = 'NET_MODE'` / `PRICE_MODE_GROSS = 'GROSS_MODE'`. Tax App: according to the comment in the Pyz provider, `TaxAppCalculationPlugin` replaces all tax calculator plugins (replaced ones belong in `TaxAppDependencyProvider::getFallbackQuoteCalculationPlugins`). `/calculation/debug` and `CalculationConstants::TAX_MODE` not verified (constant not found in `config/` `[Assumption]`: core default).

### Price resolution
- Zed/Cart: `CartItemPricePlugin::expandItems` -> `PriceCartConnectorFacade::addPriceToItems` -> `PriceManager::addPriceToItems()` (`vendor/spryker/price-cart-connector/.../Business/Manager/PriceManager.php`): set price mode, build `PriceProductFilterTransfer`, `PriceProductFacade::getValidPrices`, `PriceProductExpanderPluginInterface` plugins (Pyz: `ProductConfigurationPriceProductExpanderPlugin`), write `unitGrossPrice/unitNetPrice`.
- Price override per item: `ItemTransfer::sourceUnitGrossPrice`/`sourceUnitNetPrice` (e.g. quote request) replace catalog prices. **Deprecated:** `forcedUnitGrossPrice` (`price_cart_connector.transfer.xml`, "Will be removed with a next major release").
- `CartItemPricePreCheckPlugin` (`terminateOnFailure() = true`); `PriceCartConnectorConfig` (Pyz): `IS_ZERO_PRICE_ENABLED_FOR_CART_ACTIONS = false`, `getItemFieldsForIdentifier()` extended by SKU and QUANTITY (volume prices: identifier unique per quantity). `FilterItemsWithoutPricePlugin` (PreReload) removes items without a price.
- Client/storage (PDP, listing): `Spryker\Client\PriceProduct\ProductPriceResolver\ProductPriceResolver` + `PriceProductStorage`.
- Extension points: **price dimensions** (Zed, `src/Pyz/Zed/PriceProduct/PriceProductDependencyProvider.php`): `PriceDimensionQueryCriteriaPluginInterface`, `PriceDimensionAbstractSaverPluginInterface`, `PriceDimensionConcreteSaverPluginInterface`, `PriceProductDimensionExpanderStrategyPluginInterface`, `PriceProductReaderPricesExtractorPluginInterface`; Pyz uses merchant relationship and volume prices. Default `PriceProductConfig::PRICE_DIMENSION_DEFAULT`. **Filter** (service, `src/Pyz/Service/PriceProduct/PriceProductDependencyProvider.php::getPriceProductDecisionPlugins`): `PriceProductFilterPluginInterface::filter(array, PriceProductFilterTransfer): array` + `getDimensionName()`; Pyz order: `MerchantRelationshipPriceProductFilterPlugin` first (comment: inactive merchant prices before `PriceProductVolumeFilterPlugin`), ProductConfiguration, Volume, then `parent::`. **Client** `Pyz\Client\PriceProductStorage\PriceProductStorageDependencyProvider` (`getPriceDimensionStorageReaderPlugins`, `getPriceProductPricesExtractorPlugins`, `getPriceProductFilterExpanderPlugins`), `Pyz\Client\PriceProduct\PriceProductDependencyProvider::getPriceProductPostResolvePlugins` (`PriceProductPostResolvePluginInterface::postResolve`).
- Merchant relationship prices (B2B): modules `price-product-merchant-relationship(-storage|-gui|-data-import)`, prices per company/business unit assignment; sync/events: [07-publish-sync](07-publish-sync.md), import: [09-data-import](09-data-import.md).
- **Choice for customer-specific price logic (in order):** (1) merchant relationship via data maintenance; (2) custom price dimension (costly, also writes storage); (3) filter/post-resolve plugin to decide on/adjust existing prices; (4) custom calculator for surcharges/discounts. `[Assumption]`: no further documented "customer price" extension point.

### Checkout
`CheckoutWorkflow::placeOrder()` (`vendor/spryker/checkout/src/Spryker/Zed/Checkout/Business/Workflow/CheckoutWorkflow.php`, re-checked):
1. `checkPreConditions`: all `CheckoutPreConditionPluginInterface::checkCondition(QuoteTransfer, CheckoutResponseTransfer)` run (no short-circuit, results via `&=`); error -> nothing is saved. A plugin must not change the quote and must not write.
2. `doPreSave`: `CheckoutPreSavePluginInterface::preSave(QuoteTransfer)` (old: `CheckoutPreSaveHookInterface`).
3. `doSaveOrder`: **one DB transaction** with retry (`CheckoutConfig::getSaveOrderTransactionMaxAttempts()`, `getRetryableExceptions()`, `getRetryableDriverErrorCodes()`) and exclusive quote lock (`ERROR_CODE_QUOTE_LOCKED`); per plugin `CheckoutDoSaveOrderInterface::saveOrder(QuoteTransfer, SaveOrderTransfer)` (old: `CheckoutSaveOrderInterface` with `CheckoutResponseTransfer`).
4. `omsFacade->triggerEventForNewOrderItems()` (except `QuoteTransfer::shouldSkipStateMachineRun`). **From here on: [10-oms-state-machine](10-oms-state-machine.md).**
5. `doPostSave`: `CheckoutPostSaveInterface::executeHook(QuoteTransfer, CheckoutResponseTransfer)`.

**Finding 202610.0:** Steps 4 and 5 run in `executeAfterOrderSave()`; a `Throwable` is only logged (`error`, `orderReference`) and **not** rethrown. An exception in a PostSave plugin or the OMS trigger does not roll back the order and produces no checkout error message. Critical logic belongs in an OrderSaver. Mandatory order of the savers in the Pyz provider: `OrderSaverPlugin`, `OrderTotalsSaverPlugin`, `SalesOrderShipmentSavePlugin`, `OrderItemsSaverPlugin` (`CustomerOrderSavePlugin` before them). Registration: `src/Pyz/Zed/Checkout/CheckoutDependencyProvider.php`: `getCheckoutPreConditions`, `getCheckoutOrderSavers`, `getCheckoutPostHooks`, `getCheckoutPreSaveHooks` (+ `...ForOrderAmendment`); interfaces in `vendor/spryker/checkout-extension/.../Dependency/Plugin/`. API: `CheckoutFacade::placeOrder`, `isPlaceableOrder`; `CheckoutClient::placeOrder`, `isQuoteApplicableForCheckout`, `isPlaceableOrder`.

### Checkout steps in Yves (StepEngine)
- Module `vendor/spryker-shop/checkout-page`, overrides in `src/Pyz/Yves/CheckoutPage/` (`CheckoutPageConfig`, `CheckoutPageDependencyProvider`, `Theme/default`). Routes `CheckoutPageRouteProviderPlugin` (`/checkout`, `/checkout/customer`, `/checkout/address`, `/checkout/shipment`, `/checkout/payment`, `/checkout/summary`, `/checkout/place-order`, `/checkout/error`, `/checkout/success`; route name constants `ROUTE_NAME_CHECKOUT_*`, the old `CHECKOUT_*` constants are `@deprecated`).
- Steps (`Process/StepFactory.php::getSteps()`): `EntryStep`, `CustomerStep`, `AddressStep`, `ShipmentStep`, `PaymentStep`, `SummaryStep`, `PlaceOrderStep`, `SuccessStep`, `ErrorStep`; base `Spryker\Yves\StepEngine\Dependency\Step\StepInterface` (`preCondition`, `requireInput`, `execute(Request, AbstractTransfer)`, `postCondition`, `getStepRoute`, `getEscapeRoute`, `getTemplateVariables`).
- Customer-specific flow (different step sequence): `CheckoutStepResolverStrategyPluginInterface` via `getCheckoutStepResolverStrategyPlugins()` (Pyz: `QuoteRequestCheckoutWorkflowStepResolverStrategyPlugin`, `QuoteWithCustomShipmentPriceCheckoutWorkflowStepResolverStrategyPlugin`, `QuoteRequestAgentCheckoutWorkflowStepResolverStrategyPlugin`, `PaymentAppExpressCheckoutWorkflowCheckoutStepResolverStrategyPlugin`). Do **not** override `StepFactory`.
- Further points in `CheckoutPageDependencyProvider`: `getCustomerStepSubForms`, `getAddressStepSubForms`, `getCheckoutAddressStepEnterPreCheckPlugins`, `getCheckoutShipmentStepEnterPreCheckPlugins`, `getCheckoutPaymentStepEnterPreCheckPlugins`, `getAddressStepExecutorAddressExpanderPlugins`, `getSummaryPageWidgetPlugins` ([16-widgets](16-widgets.md)), `getPaymentCollectionExtenderPlugins`, `extendPaymentMethodHandler`; config `getLocalizedTermsAndConditionsPageLinks`, `getExcludedPaymentMethodKeys`. `PlaceOrderStep::execute()` calls `CheckoutClient::placeOrder`, sets `orderReference`/`isOrderPlacedSuccessfully`, translates `CheckoutErrorTransfer::message` and maps `errorCode` to error routes. Templates: [15-yves-frontend-atomic](15-yves-frontend-atomic.md).

## Instructions
1. **Rule in the cart**: `CartPreCheckPluginInterface` (validation, error messages), `ItemExpanderPluginInterface` (item data), `CartTerminationPluginInterface` (abort after calculation). Registration in `CartDependencyProvider` (consider the standard **and** the amendment stack).
2. **Surcharge/discount**: `CalculationPluginInterface` in `getQuoteCalculatorPluginStack` (and `getOrderCalculatorPluginStack`, if it must be preserved during `recalculateOrder`) at the right position; build it idempotent.
3. **Price**: following the decision order above; publish price changes.
4. **Rule in checkout**: `CheckoutPreConditionPluginInterface` (in addition to the cart check, since that can be bypassed), `CheckoutDoSaveOrderInterface` for transactional data, PostSave only for non-critical follow-up work.
5. **Different checkout flow**: step resolver strategy plugin, enter pre-check plugins, form/handler plugins.
6. Transfers only for new properties ([04-transfer](04-transfer.md)); translate glossary keys ([19-multi-store-i18n](19-multi-store-i18n.md)); tests ([26-tests](26-tests.md)).

Commands `[live verified (202610.0)]` (all executed, exit 0; `data:import:product-price` imports 1906 rows per store DE/AT, upsert):
```bash
docker/sdk cli console transfer:generate         # only for custom transfers
docker/sdk cli console cache:empty-all           # DependencyProvider/plugin changes
docker/sdk cli console data:import:product-price # prices ([09-data-import](09-data-import.md))
docker/sdk cli console publish:trigger-events -r price_product_abstract,price_product_concrete   # several resources comma-separated in one -r (repeated -r only takes the last value); resource names = PriceProductStorageConstants::PRICE_*_RESOURCE_NAME
```

## Examples

Minimal example project module `CustomerCartRule` (module skeleton Config/Facade/Factory: [02-project-module](02-project-module.md); uses only core transfers, no custom `*.transfer.xml`; **not executed**). Rule: a customer without a company assignment may order at most N pieces per SKU, plus a handling fee.

Cart pre-check (`CartPreCheckPluginInterface::check(CartChangeTransfer)` without a return type in the interface, `CartPreCheckResponseTransfer` is the response):
```php
// src/Pyz/Zed/CustomerCartRule/Communication/Plugin/Cart/CustomerCartRuleCartPreCheckPlugin.php
class CustomerCartRuleCartPreCheckPlugin extends AbstractPlugin implements CartPreCheckPluginInterface
{
    public function check(CartChangeTransfer $cartChangeTransfer): CartPreCheckResponseTransfer
    {
        return $this->getFacade()->checkCartChange($cartChangeTransfer);
    }
}
// in the checker: add up quantities from quote + cart change per SKU (prevents bypassing by adding repeatedly)
$responseTransfer = (new CartPreCheckResponseTransfer())->setIsSuccess(true);
// ... on violation:
$responseTransfer->setIsSuccess(false)->addMessage(
    (new MessageTransfer())->setValue('customer-cart-rule.error.max-quantity')->setParameters(['%sku%' => $sku, '%max%' => $max]),
);
```

Calculator (idempotent; model `SalesOrderThreshold\...\ExpenseCalculator::createExpenseByPriceMode()`):
```php
// Communication/Plugin/Calculation/HandlingFeeCalculatorPlugin.php implements CalculationPluginInterface
public function recalculate(CalculableObjectTransfer $calculableObjectTransfer): void
{
    $this->getFacade()->calculateHandlingFee($calculableObjectTransfer);
}

// Business/Calculator/HandlingFeeCalculator.php
$expenses = new ArrayObject();
foreach ($calculableObjectTransfer->getExpenses() as $expenseTransfer) {      // remove own expense first
    if ($expenseTransfer->getType() !== static::EXPENSE_TYPE_HANDLING_FEE) {
        $expenses->append($expenseTransfer);
    }
}
$calculableObjectTransfer->setExpenses($expenses);

$isNet = $calculableObjectTransfer->getPriceMode() === PriceConfig::PRICE_MODE_NET;
$calculableObjectTransfer->addExpense(
    (new ExpenseTransfer())
        ->setType(static::EXPENSE_TYPE_HANDLING_FEE)->setName('customer-cart-rule.handling-fee')
        ->setQuantity(1)->setTaxRate(0.0)                       // Assumption: a real fee needs a tax rate (model SalesOrderThreshold TaxRateReader)
        ->setUnitPrice($fee)->setSumPrice($fee)
        ->setUnitPriceToPayAggregation($fee)->setSumPriceToPayAggregation($fee)
        ->setUnitGrossPrice($isNet ? 0 : $fee)->setSumGrossPrice($isNet ? 0 : $fee)
        ->setUnitNetPrice($isNet ? $fee : 0)->setSumNetPrice($isNet ? $fee : 0),
);
```
More robust than self-removal: a separate remove plugin at the start of the stack (core: `RemoveSalesOrderThresholdExpenseCalculatorPlugin`).

Checkout pre-condition (check the rule again; re-checked: `CheckoutErrorTransfer` has `errorCode`, `message` and `parameters`):
```php
class CustomerCartRuleCheckoutPreConditionPlugin extends AbstractPlugin implements CheckoutPreConditionPluginInterface
{
    public function checkCondition(QuoteTransfer $quoteTransfer, CheckoutResponseTransfer $checkoutResponseTransfer): bool
    {
        return $this->getFacade()->checkQuote($quoteTransfer, $checkoutResponseTransfer);   // setIsSuccess(false)->addError(CheckoutErrorTransfer)
    }
}
```
B2B variant of the company check: `$quoteTransfer->getCustomer()?->getCompanyUserTransfer()` instead of `CustomerTransfer::company` (property exists, `company` may be empty for company customers `[Assumption]`).

Registration (Pyz excerpts):
```php
// src/Pyz/Zed/Cart/CartDependencyProvider.php, getCartPreCheckPlugins() (+ getCartPreCheckPluginsForOrderAmendment(), if desired)
new CustomerCartRuleCartPreCheckPlugin(),
// src/Pyz/Zed/Calculation/CalculationDependencyProvider.php, getQuoteCalculatorPluginStack():
//   AFTER SubtotalCalculatorPlugin / AddSalesOrderThresholdExpenseCalculatorPlugin, BEFORE ExpenseTotalCalculatorPlugin / GrandTotalCalculatorPlugin
new HandlingFeeCalculatorPlugin(),
// src/Pyz/Zed/Checkout/CheckoutDependencyProvider.php, getCheckoutPreConditions()
new CustomerCartRuleCheckoutPreConditionPlugin(),
```
Real excerpt `CartDependencyProvider`: `getCartPreCheckPlugins()` lists `ProductExistsCartPreCheckPlugin`, `CheckAvailabilityPlugin`, `CartItemPricePreCheckPlugin`, ... `DiscountPromotionCartPreCheckPlugin`; `getTerminationPlugins()` `ConfiguredBundleQuantityCartTerminationPlugin`, `SalesOrderThresholdCartTerminationPlugin`.

## Pitfalls
- **Plugin in only one stack:** mind the Order Amendment stacks (cart, checkout).
- **Wrong calculator position:** before `PriceCalculatorPlugin` there are no prices; after `GrandTotalCalculatorPlugin` no effect on the total; expense calculators before `ExpenseTotalCalculatorPlugin`, tax-relevant fields before the tax plugins.
- **Non-idempotent calculator:** the quote is recalculated on every cart operation, own expenses double up without removal.
- **Rule only in the cart:** the pre-check can be bypassed (Glue, guest merge, quote change); add a `CheckoutPreConditionPlugin` as well.
- **Critical logic in PostSave/OMS trigger:** exceptions are only logged, the order remains. Transactional work belongs in `CheckoutDoSaveOrderInterface`.
- Write access in a `CheckoutPreConditionPlugin` is forbidden according to the interface specification.
- Keep the saver order; custom savers that need `idSalesOrder` go after them.
- Do not use `forcedUnitGrossPrice` (deprecated); use `sourceUnit*Price` or price dimension/filter.
- Price change without re-publish: `price_product_*_storage` stays stale (PDP/client); Zed/Cart reads directly from the DB ([07-publish-sync](07-publish-sync.md)).
- Filter order in `getPriceProductDecisionPlugins`: merchant relationship first.
- Copying `StepFactory`/controllers of CheckoutPage breaks upgradability ([28-upgradability](28-upgradability.md)); use strategy/pre-check/form plugins. Do not copy core classes (`Operation`, `CheckoutWorkflow`), never change `vendor/`; do not remove core plugins from Pyz providers without need (breaks SalesOrderThreshold, QuoteApproval and others).
- Plugins only call their own Facade, other modules via the DependencyProvider ([02-project-module](02-project-module.md)); Yves only via the Client.
- **Deprecations:** `Spryker\Zed\Cart\Dependency\{ItemExpanderPluginInterface, PreReloadItemsPluginInterface, PostSavePluginInterface, CartPreCheckPluginInterface, TerminationAwareCartPreCheckPluginInterface}` `@deprecated` -> `CartExtension\...` (`CartOperationPostSavePluginInterface` for PostSave); `Calculation\Dependency\Plugin\CalculatorPluginInterface` `@deprecated`; `ItemTransfer::forcedUnitGrossPrice` deprecated; `Checkout\Dependency\Plugin\{CheckoutSaveOrderInterface, CheckoutPreSaveHookInterface}` legacy path -> `CheckoutExtension\...`; the old `CHECKOUT_*` constants in `CheckoutPageRouteProviderPlugin` are `@deprecated` (replacement: `ROUTE_NAME_CHECKOUT_*`, which are not deprecated; the tag of `CHECKOUT_SUCCESS` wrongly points to `ROUTE_NAME_CHECKOUT_INDEX` in the code). `Operation::add/remove/reloadItems` (return `QuoteTransfer`) are the old API alongside `addToCart/removeFromCart/reloadItemsInQuote` (`QuoteResponseTransfer`); `@deprecated` tag not checked `[Assumption]`. Core plugins such as `CartItemPricePlugin` still use the legacy interface (alias) in the release.
- Not investigated: CartPage controller plugins/widgets, `quote-approval`, `quote-request`, discount calculators, the content of the shipment/payment steps.

## Checklist
- [ ] Right extension point chosen (pre-check / expander / calculator / price dimension / filter / checkout plugin)
- [ ] Plugin registered in the standard and (if needed) Order Amendment stack
- [ ] Calculator idempotent and at the correct stack position
- [ ] Rule additionally secured in checkout (pre-condition)
- [ ] Transactional data in the OrderSaver, not in PostSave
- [ ] Only `*Extension` interfaces, no legacy/deprecated interfaces
- [ ] Glossary keys created, prices published, cache cleared
- [ ] Plugins delegate to their own Facade; nothing changed in `vendor/`

## Sources
Docs (entry points, reachability checked, content not evaluated; base `https://docs.spryker.com/docs/pbc/all/cart-and-checkout/latest/base-shop/`): `extend-and-customize/calculation-3-0.html`, `.../calculator-plugins.html`, `.../calculation-data-structure.html`, `.../cart-module-reference-information.html`, `.../checkout-process-review-and-implementation.html`, `.../checkout-steps.html`, `tutorials-and-howtos/tutorial-calculator-plugin.html`.

Code: `src/Pyz/Zed/{Cart,Calculation,Checkout,PriceCartConnector,PriceProduct,Quote,PersistentCart}/*`, `src/Pyz/Client/{Cart,PriceProduct,PriceProductStorage}/*DependencyProvider.php`, `src/Pyz/Service/PriceProduct/PriceProductDependencyProvider.php`, `src/Pyz/Yves/CheckoutPage/*`; `vendor/spryker/cart/.../Zed/Cart/Business/Model/Operation.php`, `vendor/spryker/cart-extension/`, `calculation/.../QuoteCalculatorExecutor.php`, `calculation-extension/`, `checkout/.../CheckoutWorkflow.php`, `checkout-extension/`, `price-cart-connector/.../PriceManager.php`, `price-product/...`, `sales-order-threshold/.../ExpenseCalculator.php`, `price/.../PriceConfig.php`; `vendor/spryker-shop/checkout-page/...`, `vendor/spryker-shop/cart-page/...`, `vendor/spryker/step-engine/`.

Neighbors: [10-oms-state-machine](10-oms-state-machine.md), [03-extending-the-core](03-extending-the-core.md), [14-yves-controller-routing](14-yves-controller-routing.md), [12-glue-storefront](12-glue-storefront.md), [20-b2b-permissions](20-b2b-permissions.md).
