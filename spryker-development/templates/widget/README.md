# Template: widget (Yves widget)

## Purpose
Yves widget `ProductBadgeWidget`: renders the badges of an abstract product (data from `Pyz\Client\ProductBadge\ProductBadgeClientInterface`, Storage) as a list. Included in any Twig template via `{% widget 'ProductBadgeWidget' args [idProductAbstract] only %}`. Explanation: [16-widgets](../../references/16-widgets.md).

## File list (target path below the project root)
```
src/Pyz/Yves/ProductBadgeWidget/Widget/ProductBadgeWidget.php
src/Pyz/Yves/ProductBadgeWidget/ProductBadgeWidgetFactory.php
src/Pyz/Yves/ProductBadgeWidget/ProductBadgeWidgetDependencyProvider.php
src/Pyz/Yves/ProductBadgeWidget/Theme/default/views/product-badge-widget/product-badge-widget.twig
```

## Placeholders / customization
- Replace `ProductBadgeWidget` (module, class, `getName()`), `product-badge-widget` (view folder and file name). `getName()` = class name, unique in scope (otherwise `UniqueWidgetNameException`).
- Constructor arguments = exactly the values from `args [...]` in the Twig tag (here `int $idProductAbstract`; a wrong type leads to `WidgetRenderException`).
- The template extends `template('widget')` and fills the block `body`; `isVisible` hides everything when the list is empty. CSS classes (`list`, `label`) are examples from the atomic frontend; create your own component if needed (→ [15-yves-frontend-atomic](../../references/15-yves-frontend-atomic.md)).
- The class uses `Spryker\Yves\Kernel\Widget\AbstractWidget` (not the deprecated `AbstractWidgetPlugin`).

## Registration
1. Global: `src/Pyz/Yves/ShopApplication/ShopApplicationDependencyProvider.php`, method `getGlobalWidgets()` (list of class names, no `new`):
   ```php
   use Pyz\Yves\ProductBadgeWidget\Widget\ProductBadgeWidget;
   ...
   protected function getGlobalWidgets(): array
   {
       return [
           // ...
           ProductBadgeWidget::class,
       ];
   }
   ```
   Alternatively page-specific: class in the widget list of the page module (e.g. `ProductDetailPageDependencyProvider::getProductDetailPageWidgetPlugins()`, empty by default in the release) and pass this list in the controller to `$this->view($data, $widgets, $template)`. The page module's provider must be overridden in the Pyz module for this.
2. Including in Twig (example product detail page): **In release 202610.0, Pyz already overrides the PDP** (`src/Pyz/Yves/ProductDetailPage/Theme/default/views/pdp/pdp.twig`, inherits from the core `pdp` and uses `image-gallery`, not `product-carousel`). Add the widget live in the block `title` after `</h1>` (verified):
   ```twig
   {% widget 'ProductBadgeWidget' args [data.product.idProductAbstract] only %}{% endwidget %}
   ```
   A copy of `product-carousel.twig` in the Pyz module has no effect as long as the Pyz PDP does not include the core `product-carousel` (tried live: no output). If the project has no PDP of its own, do not change the core template in `vendor/`, but create a file with the same name in the Pyz module, e.g. `src/Pyz/Yves/ProductDetailPage/Theme/default/components/molecules/product-carousel/product-carousel.twig` (copy of the core template; the core uses `{% widget 'ProductAbstractLabelWidget' args [data.product.idProductAbstract] only %}` there) and add:
   ```twig
   {% widget 'ProductBadgeWidget' args [data.product.idProductAbstract] only %}{% endwidget %}
   ```
   If the widget is not registered, the tag silently renders nothing. Debug: `{{ widgetGlobalExists('ProductBadgeWidget') ? 'yes' : 'no' }}`.

## Commands afterwards
`cache:empty-all` and `npm run yves` `[live verified (202610.0)]` (widget installed in the shop and rendered)
```bash
docker/sdk console cache:empty-all                         # Zed console
docker/sdk cli npm run yves                                # only if your own SCSS/TS was added (assumption, see reference 15)
```
New Twig files in Pyz modules are usually found directly in dev mode; in case of problems clear the Twig cache or rebuild (`docker/sdk up --build`).

## Source used (model)
- `src/Pyz/Yves/CustomerFullNameWidget/` (widget, Factory, DependencyProvider, Twig view, registration in `getGlobalWidgets()`)
- `vendor/spryker/kernel/src/Spryker/Yves/Kernel/Widget/AbstractWidget.php`, `Dependency/Widget/WidgetInterface.php`
- `vendor/spryker-shop/shop-ui/src/SprykerShop/Yves/ShopUi/Theme/default/templates/widget/widget.twig`

## Verification status
Base class, interface (`getName()`, `getTemplate()`, `addParameter()`), Twig base template and registration method `getGlobalWidgets()` checked against `vendor/` and `src/Pyz`. Walked through in shop 202610.0: registration in `getGlobalWidgets()`, inclusion in `pdp.twig`; the product page `/en/hdmi-cable-1` (abstract 4) renders `<li class="list__item" data-qa="product-badge-new">`, abstracts without a badge (`/en/computer-6`) render nothing. `code:sniff:style` exit 0, PHPStan level 6 without findings. `[live verified (202610.0)]`
