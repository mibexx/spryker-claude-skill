# CMS: pages, blocks, content items, slots

> Status: release 202610.0 (`spryker/cms` 7.22.1, `cms-block` 3.11.1, `cms-slot` 1.2.0, `cms-slot-block` 1.2.1, `content` 2.6.0, `content-storage` 2.8.0, `spryker-shop/cms-page` 1.9.0, `cms-block-widget` 2.5.0, `shop-cms-slot` 1.0.0). `[code-verified, docs not cross-checked]`: docs URLs not fetched. Console commands executed `[live verified (202610.0)]` (import, publish, worker, `cache:empty-all`). Spot checks confirmed during the authoring run: placeholder pattern, `appendPrefixToCmsPageUrl() = true`, block template example, CSV header. `[Assumption]` applies to `autofilled` slot parameters, details of `syncTemplate`, debug switch `ShopCmsSlotConfig`.

## Purpose

Develop editorial content: CMS page templates with placeholders, CMS blocks, content items (banners, product lists, navigation, files), slots with visibility conditions, plus connection to Publish & Synchronization. Frontend structure of the templates → [15](15-yves-frontend-atomic.md); Publish/Sync mechanics → [07](07-publish-sync.md); importers → [09](09-data-import.md).

## Concepts

| Object | Purpose | Twig location | Registration |
|---|---|---|---|
| CMS page | URL-addressable page from a template + placeholder texts per locale | `src/Pyz/Shared/Cms/Theme/<theme>/templates/<name>/<name>.twig` (`@Cms/templates/…`) | `spy_cms_template` (import `cms-template` or `syncTemplate` in the Back Office) |
| CMS block | reusable fragment, embeddable by key/category/product/slot | `src/Pyz/Shared/CmsBlock/Theme/<theme>/template/<name>.twig` (`@CmsBlock/template/…`) | `spy_cms_block_template` (import `cms-block`) |
| Content item | content with a key (`spy_content`: banner, product list, product set, navigation, file list) | widget Twig per template identifier | Twig function + template identifier map |
| Slot | named place (`slt-…`) in a Storefront Twig (slot template), filled with blocks | `{% cms_slot 'slt-x' %}` in Storefront Twig | imports `cms-slot-template`, `cms-slot`, `cms-slot-block` |

**Placeholders:** in the template as an HTML comment. Page: `/<!-- CMS_PLACEHOLDER : "[a-zA-Z0-9._-]*" -->/` (`CmsConfig::CMS_PLACEHOLDER_PATTERN`), rendered with `{{ spyCms('<name>') | raw }}`. Block: `<!-- CMS_BLOCK_PLACEHOLDER : "title" -->` (`CmsBlockConfig::CMS_BLOCK_PLACEHOLDER_PATTERN`), rendered with `{{ spyCmsBlockPlaceholder('title') | raw }}`. Without the comment, the Back Office does not know the placeholder.

**Template paths:** `CmsConfig::getTemplateRealPaths()` searches per theme (active, then `default`) in `Yves` and `Shared`. `Pyz\Zed\Cms\CmsConfig::appendPrefixToCmsPageUrl()` returns `true` (locale prefix `/de/…` is prepended; core default `false`).

**Block retrieval in Yves:** Twig function `spyCmsBlock(options)` (`CmsBlockWidgetTwigPlugin`); evaluation in `CmsBlockStorage::getCmsBlocksByOptions()`: `key`/`keys` -> `name` -> otherwise assignment readers (`category` + `position` `top|middle|bottom`, `product` abstract ID). Assignment via modules `cms-block-category-connector`, `cms-block-product-connector` (+ `-storage`). Visibility: `CmsBlockValidator::isValid()` (invalid if now <= `valid_from` or now > `valid_to`; then `''`). The block Twig renders independently (`renderCmsBlock`) -> content must be inside `{% block content %}`.

**Content items** (Twig function -> widget provider, template identifier as a string):

| Type | Function | Identifier (core / Pyz) |
|---|---|---|
| Banner | `content_banner('<key>', '<tpl>')` | `bottom-title`, `top-title`; Pyz `home-page` |
| Product list | `content_product_abstract_list(...)` | `bottom-title`, `top-title`, slider (Pyz views) |
| Product set | `content_product_set(...)` | e.g. `landing-page` |
| Navigation | `content_navigation(...)` | Pyz `navigation-header`, `navigation-footer`, `navigation-footer-checkout`, `footer-partners`, `footer-social-links` |
| File list | `content_file_list(...)` | - |

An unknown identifier renders `<b>"x" is not supported name of template.</b>`, an unknown key `<b>Content Banner with key x not found.</b>`. Registration of the Twig functions: `src/Pyz/Yves/Twig/TwigDependencyProvider.php` (among others `ContentBannerTwigPlugin`, `CmsBlockTwigPlugin`, `CmsTwigPlugin`, `ShopCmsSlotTwigPlugin`; `CmsBlockWidgetTwigPlugin` is strictly required for slot blocks, but is **not** registered in the `TwigDependencyProvider`, rather in `Pyz\Yves\CmsBlockWidget\CmsBlockWidgetDependencyProvider::getTwigExtensionPlugins()`; the `TwigDependencyProvider` only contains the collector `CmsBlockTwigPlugin`). Reading: `Client\ContentStorage` (`findContentTypeContextByKey()`, `getContentTypeContextByKeys()`). Editor integration in the Back Office: `Pyz\Zed\ContentGui\ContentGuiDependencyProvider` (`getContentPlugins()`, `getContentEditorPlugins()`). A second, older track "content widgets in text" (product, file, CMS block): `Pyz\Yves\CmsContentWidget\CmsContentWidgetDependencyProvider::getCmsContentWidgetPlugins()`; do not confuse it with the `content_*` functions.

**Slots:** slot template (`spy_cms_slot_template`, Storefront Twig such as `@HomePage/views/home/home.twig`) != CMS page template. Slot (`spy_cms_slot`: key `slt-…`, `content_provider`, `template_path`, `is_active`). Assignment `spy_cms_slot_block` (slot+template+block+`position` unique, `conditions` JSON). Provider `SprykerCmsSlotBlock`. Syntax (parser `ShopCmsSlotTokenParser`, order key, `autofilled`, `required`, `with`): `{% cms_slot 'slt-6' required ['idCategory'] with { idCategory: _widget.idCategory } %}`. If a `required` key is missing: `MissingRequiredParameterException`; in non-debug mode the provider catches it and renders `''`. Runtime: `ShopCmsSlotTwigPlugin` -> `CmsSlotDataProvider::getSlotContent()` -> `CmsSlotBlockWidgetCmsSlotContentPlugin` -> blocks via `spyCmsBlock({keys})`.

**Visibility conditions:** which condition types a slot template offers: `Pyz\Zed\CmsSlotBlock\CmsSlotBlockConfig::getTemplateConditionsAssignment()` (template path -> condition keys). GUI forms: `CmsSlotBlockGuiDependencyProvider::getCmsSlotBlockFormPlugins()`. Evaluation in Yves: `Pyz\Client\CmsSlotBlock\CmsSlotBlockDependencyProvider::getCmsSlotBlockVisibilityResolverPlugins()` (`CmsSlotBlockVisibilityResolverPluginInterface`: `isApplicable()`, `isCmsBlockVisibleInSlot()`). A block without conditions is always visible.

**Visibility store/validity:** page: `spy_cms_page_store`, `valid_from`/`valid_to` (only checked if **both** are set), `is_active`, published version; Yves check `CmsController::executePageAction()` -> 404. Block: `spy_cms_block_store`, both limits individually, `active`. Slot: `is_active`.

**Publish & Synchronization:** via event subscribers (not PublisherDependencyProvider stacks): `Pyz\Zed\Event\EventDependencyProvider` (`CmsStorageEventSubscriber`, `CmsBlockStorageEventSubscriber`, `CmsBlockCategoryStorageEventSubscriber`, `CmsBlockProductStorageEventSubscriber`, `ContentStorageEventSubscriber`, `CmsSlotStorageEventSubscriber`); events from the `event` behavior in Pyz schemas. A page only becomes visible in Storage through **publishing** (version, `CmsEvents::CMS_VERSION_PUBLISH`). Resource names: `cms_page`, `cms_block`, `cms_slot`, `cms_slot_block`, `content`, `cms_block_category`, `cms_block_product`, `cms_page_search`. The Message Broker channel `cms-page-events` is only for external consumers ([24](24-mail-message-broker.md)).

## Instructions

**New CMS page template**
1. `src/Pyz/Shared/Cms/Theme/default/templates/<name>/<name>.twig` with `extends template('page-layout-main')`, `<!-- CMS_PLACEHOLDER : "x" -->` + `{{ spyCms('x') | raw }}`.
2. Row in `data/import/common/common/cms_template.csv` (`template_name,template_path`), `docker/sdk cli console data:import:cms-template`.
3. Create the page in the Back Office or import it via `cms_page.csv`; **publish** it; assign a store (`cms_page_store.csv`).

**New block type**
1. `…/Shared/CmsBlock/Theme/default/template/<name>.twig` with `{% block content %}` and placeholders.
2. `cms_block.csv` (`block_key,block_name,template_name,template_path,active,placeholder.<name>.<loc>`) + store in `cms_block_store.csv` (`block_key,store_name`).
3. Embed via `spyCmsBlock({key: 'blck-1'})`, category/product assignment in the Back Office, or via a slot (see below).

**New slot usage**
1. In the Storefront Twig `{% cms_slot 'slt-x' required [...] with {...} %}`.
2. `cms_slot_template.csv` (`name,description,template_path`), `cms_slot.csv` (`slot_key,name,description,content_provider,template_path,is_active`), `cms_slot_block.csv` (`slot_key,block_key,position,template_path,conditions.…`; position unique per slot+template).
3. New condition: template path in `getTemplateConditionsAssignment()` + GUI form plugin + client resolver plugin; `required` keys in the Twig must match the conditions (`idCategory`, `idProductAbstract`, `idCmsPage`).

**New content item template identifier**: navigation via `ContentNavigationWidgetConfig::getAvailableTemplateList()` (config only). Banner: extend Pyz `ContentBannerTwigFunctionProvider::getAvailableTemplates()` **and** override `ContentBannerWidgetFactory::createContentBannerTwigFunctionProvider()` (see Examples).

Import order according to `data/import/common/content_management_import_config_EU.yml`: template -> block -> content -> page -> slot template -> slot -> slot block -> store relations ([09](09-data-import.md)). Commands (all executed `[live verified (202610.0)]`, imports `-t` exit 0, 16 actions of the config, `cms-block` 56 data sets):

```bash
docker/sdk cli console data:import --config=data/import/common/content_management_import_config_EU.yml
docker/sdk cli console data:import:cms-block        # dedicated commands only exist for cms-block, cms-block-store, cms-template, content-navigation
docker/sdk cli console data:import cms-page         # all other importers (cms-page, cms-page-store, cms-slot-template, cms-slot,
                                                    # cms-slot-block, content-banner, ...) via argument; list: data:import:dump
docker/sdk cli console publish:trigger-events -r cms_page,cms_block,cms_slot,cms_slot_block,content   # several resources comma-separated, repeated -r only counts the last one
docker/sdk cli console queue:worker:start --stop-when-empty
docker/sdk cli console cache:empty-all              # Twig path resolution is cached
```

## Examples

**Page template (real)** `src/Pyz/Shared/Cms/Theme/default/templates/placeholders-title-content-slot/placeholders-title-content-slot.twig`

```twig
{% extends template('page-layout-main') %}

{% define data = {
    idCmsPage: _view.idCmsPage,
    title: _view.pageTitle | default('global.spryker.shop' | trans),
    metaTitle: _view.pageTitle | default('global.spryker.shop' | trans),
    metaDescription: _view.pageDescription | default,
    metaKeywords: _view.pageKeywords | default,
} %}

{% block title %}
    <div class="cms-page-title">
        <!-- CMS_PLACEHOLDER : "title" -->
        {{ spyCms('title') | raw }}
    </div>
{% endblock %}
{% block content %}
    <!-- CMS_PLACEHOLDER : "content" -->
    {{ spyCms('content') | raw }}

    {% cms_slot 'slt-10' required ['idCmsPage'] with {
        idCmsPage: data.idCmsPage,
    } %}
{% endblock %}
```

**Block template (real)** `src/Pyz/Shared/CmsBlock/Theme/default/template/title_and_content_block.twig`

```twig
{% block content %}
    <!-- CMS_BLOCK_PLACEHOLDER : "title" -->
    <!-- CMS_BLOCK_PLACEHOLDER : "content" -->

    {{ spyCmsBlockPlaceholder('title') | raw }}

    {{ spyCmsBlockPlaceholder('content') | raw }}
{% endblock %}
```

**Conditions per slot template (real)** `src/Pyz/Zed/CmsSlotBlock/CmsSlotBlockConfig.php`

```php
<?php

declare(strict_types = 1);

namespace Pyz\Zed\CmsSlotBlock;

use Spryker\Shared\CmsSlotBlockCategoryConnector\CmsSlotBlockCategoryConnectorConfig;
use Spryker\Shared\CmsSlotBlockCmsConnector\CmsSlotBlockCmsConnectorConfig;
use Spryker\Shared\CmsSlotBlockProductCategoryConnector\CmsSlotBlockProductCategoryConnectorConfig;
use Spryker\Zed\CmsSlotBlock\CmsSlotBlockConfig as SprykerCmsSlotBlockConfig;

class CmsSlotBlockConfig extends SprykerCmsSlotBlockConfig
{
    /**
     * @return array<array<string>>
     */
    public function getTemplateConditionsAssignment(): array
    {
        return [
            '@CatalogPage/views/catalog-with-cms-slot/catalog-with-cms-slot.twig' => [
                CmsSlotBlockCategoryConnectorConfig::CONDITION_KEY,
            ],
            '@Cms/templates/placeholders-title-content-slot/placeholders-title-content-slot.twig' => [
                CmsSlotBlockCmsConnectorConfig::CONDITION_KEY,
            ],
            '@ProductDetailPage/views/pdp/pdp.twig' => [
                CmsSlotBlockProductCategoryConnectorConfig::CONDITION_KEY,
            ],
        ];
    }
}
```

**Extend banner template identifiers (real)** `src/Pyz/Yves/ContentBannerWidget/Twig/ContentBannerTwigFunctionProvider.php`

```php
protected function getAvailableTemplates(): array
{
    $contentWidgetTemplates = parent::getAvailableTemplates();

    return [
            static::WIDGET_TEMPLATE_IDENTIFIER_HOME_PAGE => '@ContentBannerWidget/views/banner-home-page/banner-home-page.twig',
        ] + $contentWidgetTemplates;
}
```

Class `Pyz\Yves\ContentBannerWidget\Twig\ContentBannerTwigFunctionProvider extends SprykerShop\Yves\ContentBannerWidget\Twig\ContentBannerTwigFunctionProvider`, constant `WIDGET_TEMPLATE_IDENTIFIER_HOME_PAGE = 'home-page'`. For it to take effect, `Pyz\Yves\ContentBannerWidget\ContentBannerWidgetFactory` overrides the method `createContentBannerTwigFunctionProvider(Environment $twig, string $localeName): TwigFunctionProvider` and returns `new ContentBannerTwigFunctionProvider($twig, $localeName, $this->getContentBannerClient())`.

**Register slot plugins (real)**

```php
// src/Pyz/Yves/ShopCmsSlot/ShopCmsSlotDependencyProvider.php
protected function getCmsSlotContentPlugins(): array
{
    return [
        CmsSlotBlockConfig::CMS_SLOT_CONTENT_PROVIDER_TYPE => new CmsSlotBlockWidgetCmsSlotContentPlugin(),
    ];
}

// src/Pyz/Client/CmsSlotBlock/CmsSlotBlockDependencyProvider.php
protected function getCmsSlotBlockVisibilityResolverPlugins(): array
{
    return [
        new CategoryCmsSlotBlockConditionResolverPlugin(),
        new CmsPageCmsSlotBlockConditionResolverPlugin(),
        new ProductCategoryCmsSlotBlockConditionResolverPlugin(),
    ];
}
```

**Minimal example teaser block on the home page** `[Assumption: not executed against a running installation]`:
1. `src/Pyz/Shared/CmsBlock/Theme/default/template/teaser_block.twig` with `{% block content %}`, two placeholders and `<section class="teaser">…</section>`.
2. `cms_block.csv`: `blck-99,Teaser,Teaser block,@CmsBlock/template/teaser_block.twig,1,<title de>,<title en>,<content de>,<content en>,,,,` (all 13 columns according to the header `block_key,block_name,template_name,template_path,active,placeholder.title.de_DE,placeholder.title.en_US,placeholder.content.de_DE,placeholder.content.en_US,placeholder.imageUrl.en_US,placeholder.imageUrl.de_DE,placeholder.link.en_US,placeholder.link.de_DE`; the example has 4 empty placeholder columns at the end; fewer values than header columns aborts with `DataSetWithHeaderCombineFailedException`); `data/import/common/DE/cms_block_store.csv`: `blck-99,DE`.
3. `cms_slot_block.csv`: `slt-3,blck-99,3,@HomePage/views/home/home.twig,,,,,,,` (position 3 free in `slt-3`).
4. Import (`data:import:cms-block`, `data:import:cms-block-store`, `data:import cms-slot-block`), then work off the queue.

## Pitfalls

1. Placeholder comment missing -> placeholder invisible in the Back Office; `spyCms('x')` alone is not enough.
2. Template only in the file system, not in `spy_cms_template`/`spy_cms_block_template` -> not selectable (trigger the import or `syncTemplate`).
3. Page visible in the Back Office, 404 in Yves -> not published, no store (`spy_cms_page_store`), inactive, validity window, template not found (`'The Cms Page template is not found.'`), queues not worked off.
4. Slot stays empty -> `required` key not in `with {…}`; the error is swallowed in non-debug mode (`ShopCmsSlotConfig::isDebugModeEnabled()` `[Assumption]`).
5. Condition does not appear in the Back Office -> template path in `getTemplateConditionsAssignment()` differs from the slot's `template_path`, or the form plugin is missing; does not take effect in Yves -> resolver plugin missing.
6. `CmsBlockTwigFunctionMissingException` -> `CmsBlockWidgetTwigPlugin` is missing in `Pyz\Yves\CmsBlockWidget\CmsBlockWidgetDependencyProvider::getTwigExtensionPlugins()` (add it there, not in the Yves `TwigDependencyProvider`; the `TwigDependencyProvider` only contains the collector `CmsBlockTwigPlugin`).
7. Unknown content item identifier -> add it in `getAvailableTemplates()`/`getAvailableTemplateList()`; for the banner additionally a Factory override.
8. Two blocks with the same `position` in the same slot+template -> unique key violated, import aborts.
9. Block without `{% block content %}` -> nothing rendered `[Assumption]`.
10. Changing the database directly -> events/storage do not run; always use Facade/importer/Back Office.
11. Page validity: only one limit set = always valid; for blocks, by contrast, both are checked individually.
12. Two widget tracks (content items `content_*` vs. content widgets `CmsContentWidget`) have separate registration.
13. Per theme, the template must be located under `Theme/<theme>/…` (fallback `default`); SCSS/TS in block templates need the frontend build ([15](15-yves-frontend-atomic.md)).

**Deprecated (from the code):** `CmsConfig::getTemplateRealPath()` -> `getTemplateRealPaths()`; `Shared\Cms\CmsConstants` (constant) -> `CmsConfig::getThemeName()`; one `CmsFacade` method (`CmsFacadeInterface.php:73`) -> `createPage()`/`updatePage()`/`publishWithVersion`; `Cms\Business\Page\PageManager` almost entirely "Will be removed in the next major release"; `MissingPlaceholdersException` "Will be removed without replacement"; `CmsSlotBlockFacade`/repository one method -> `getPaginatedCmsBlocksWithSlotRelations()`; `CmsBlockKeyProvider` old method -> `generateUniqueKey()`; `ContentBannerConfig::WIDGET_TEMPLATE_IDENTIFIER_DEFAULT` -> `WIDGET_TEMPLATE_IDENTIFIER_BOTTOM_TITLE`.

## Checklist

- [ ] Placeholder comment and Twig output present (`spyCms` / `spyCmsBlockPlaceholder`)
- [ ] Template imported via CSV and import order respected
- [ ] Page published, store assigned, active, validity checked
- [ ] Slot: `required`/`with` match the conditions; resolver and GUI plugin registered
- [ ] New Twig function/identifier registered in the Pyz provider (and Factory for the banner)
- [ ] `publish:trigger-events` and queue worker have run, `cache:empty-all`
- [ ] Changes in `src/Pyz`/data, no edits in `vendor/`; editors only maintain items/blocks

## Sources

Docs (base `https://docs.spryker.com/docs/`, not fetched): `pbc/all/content-management-system/latest/base-shop/cms-feature-overview/{cms-feature-overview,cms-pages-overview,cms-blocks-overview}.html`, `…/content-items-feature-overview.html`, `…/manage-in-the-back-office/best-practices-add-content-to-the-storefront-pages-using-templates-and-slots.html`.

Code: `vendor/spryker/cms/src/Spryker/Zed/Cms/{CmsConfig.php,Business/CmsFacadeInterface.php}`, `vendor/spryker/cms-block/src/Spryker/Zed/CmsBlock/CmsBlockConfig.php`, `vendor/spryker/cms-block-storage/src/Spryker/Client/CmsBlockStorage/Storage/CmsBlockStorage.php`, `vendor/spryker-shop/cms-block-widget/src/SprykerShop/Yves/CmsBlockWidget/{Plugin/Twig/*,Validator/CmsBlockValidator.php}`, `vendor/spryker/cms-slot-block/…`, `vendor/spryker-shop/shop-cms-slot/src/SprykerShop/Yves/ShopCmsSlot/{Business/CmsSlotDataProvider.php,Twig/TokenParser/*}`, `vendor/spryker-shop/content-*-widget/…/Twig/*TwigFunctionProvider.php`, `vendor/spryker-shop/cms-page/src/SprykerShop/Yves/CmsPage/Controller/CmsController.php`, `src/Pyz/Zed/{Cms,CmsSlotBlock,CmsSlotBlockGui,ContentGui,Event}/…`, `src/Pyz/Client/CmsSlotBlock/…`, `src/Pyz/Yves/{Twig,ShopCmsSlot,ContentBannerWidget,CmsContentWidget}/…`, `data/import/common/content_management_import_config_EU.yml`, `data/import/common/**/cms_*.csv`.

Neighbors: [07 Publish & Sync](07-publish-sync.md), [08 Client/Storage](08-client-storage-search.md), [09 Data Import](09-data-import.md), [14 Yves routing](14-yves-controller-routing.md), [15 Frontend](15-yves-frontend-atomic.md), [16 Widgets](16-widgets.md), [19 i18n](19-multi-store-i18n.md).
