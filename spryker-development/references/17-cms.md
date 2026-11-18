# CMS: Seiten, Blöcke, Content Items, Slots (Recherche T-17)

> Status: Release 202610.0 (`spryker/cms` 7.22.1, `cms-block` 3.11.1, `cms-slot` 1.2.0, `cms-slot-block` 1.2.1, `content` 2.6.0, `content-storage` 2.8.0, `spryker-shop/cms-page` 1.9.0, `cms-block-widget` 2.5.0, `shop-cms-slot` 1.0.0). `[code-verifiziert, Doku nicht abgeglichen]`: Doku-URLs nicht abgerufen. Konsolenbefehle ausgeführt `[live verifiziert (202610.0)]` (Import, Publish, Worker, `cache:empty-all`). Spot-Checks beim Autorenlauf bestätigt: Platzhalter-Muster, `appendPrefixToCmsPageUrl() = true`, Block-Template-Beispiel, CSV-Header. `[Annahme]` gilt für `autofilled`-Slotparameter, Details von `syncTemplate`, Debug-Schalter `ShopCmsSlotConfig`.

## Zweck

Redaktionelle Inhalte entwickeln: CMS-Seiten-Templates mit Platzhaltern, CMS-Blöcke, Content Items (Banner, Produktlisten, Navigation, Dateien), Slots mit Sichtbarkeitsbedingungen, plus Anbindung an Publish & Sync. Frontend-Aufbau der Templates → [15](15-yves-frontend-atomic.md); Publish/Sync-Mechanik → [07](07-publish-sync.md); Importer → [09](09-data-import.md).

## Konzepte

| Objekt | Zweck | Twig-Ort | Registrierung |
|---|---|---|---|
| CMS-Seite | URL-adressierbare Seite aus Template + Platzhalter-Texten je Locale | `src/Pyz/Shared/Cms/Theme/<theme>/templates/<name>/<name>.twig` (`@Cms/templates/…`) | `spy_cms_template` (Import `cms-template` oder `syncTemplate` im Back Office) |
| CMS-Block | wiederverwendbares Fragment, per Key/Kategorie/Produkt/Slot einbindbar | `src/Pyz/Shared/CmsBlock/Theme/<theme>/template/<name>.twig` (`@CmsBlock/template/…`) | `spy_cms_block_template` (Import `cms-block`) |
| Content Item | Inhalt mit Key (`spy_content`: Banner, Produktliste, Produkt-Set, Navigation, File-Liste) | Widget-Twig je Template-Identifier | Twig-Funktion + Template-Identifier-Map |
| Slot | benannter Platz (`slt-…`) in einem Storefront-Twig (Slot-Template), füllt sich mit Blöcken | `{% cms_slot 'slt-x' %}` in Storefront-Twig | Imports `cms-slot-template`, `cms-slot`, `cms-slot-block` |

**Platzhalter:** im Template als HTML-Kommentar. Seite: `/<!-- CMS_PLACEHOLDER : "[a-zA-Z0-9._-]*" -->/` (`CmsConfig::CMS_PLACEHOLDER_PATTERN`), gerendert mit `{{ spyCms('<name>') | raw }}`. Block: `<!-- CMS_BLOCK_PLACEHOLDER : "title" -->` (`CmsBlockConfig::CMS_BLOCK_PLACEHOLDER_PATTERN`), gerendert mit `{{ spyCmsBlockPlaceholder('title') | raw }}`. Ohne Kommentar kennt das Back Office den Platzhalter nicht.

**Template-Pfade:** `CmsConfig::getTemplateRealPaths()` sucht je Theme (aktives, dann `default`) in `Yves` und `Shared`. `Pyz\Zed\Cms\CmsConfig::appendPrefixToCmsPageUrl()` liefert `true` (Locale-Präfix `/de/…` wird vorangestellt; Core-Default `false`).

**Block-Abruf in Yves:** Twig-Funktion `spyCmsBlock(options)` (`CmsBlockWidgetTwigPlugin`); Auswertung in `CmsBlockStorage::getCmsBlocksByOptions()`: `key`/`keys` -> `name` -> sonst Zuordnungs-Reader (`category` + `position` `top|middle|bottom`, `product` Abstract-ID). Zuordnung über Module `cms-block-category-connector`, `cms-block-product-connector` (+ `-storage`). Sichtbarkeit: `CmsBlockValidator::isValid()` (ungültig, wenn jetzt <= `valid_from` oder jetzt > `valid_to`; dann `''`). Block-Twig rendert eigenständig (`renderCmsBlock`) -> Inhalt muss in `{% block content %}` stehen.

**Content Items** (Twig-Funktion -> Widget-Provider, Template-Identifier als Zeichenkette):

| Typ | Funktion | Identifier (Core / Pyz) |
|---|---|---|
| Banner | `content_banner('<key>', '<tpl>')` | `bottom-title`, `top-title`; Pyz `home-page` |
| Produktliste | `content_product_abstract_list(...)` | `bottom-title`, `top-title`, Slider (Pyz-Views) |
| Produkt-Set | `content_product_set(...)` | z. B. `landing-page` |
| Navigation | `content_navigation(...)` | Pyz `navigation-header`, `navigation-footer`, `navigation-footer-checkout`, `footer-partners`, `footer-social-links` |
| File-Liste | `content_file_list(...)` | - |

Unbekannter Identifier rendert `<b>"x" is not supported name of template.</b>`, unbekannter Key `<b>Content Banner with key x not found.</b>`. Registrierung der Twig-Funktionen: `src/Pyz/Yves/Twig/TwigDependencyProvider.php` (u. a. `ContentBannerTwigPlugin`, `CmsBlockTwigPlugin`, `CmsTwigPlugin`, `ShopCmsSlotTwigPlugin`, `CmsBlockWidgetTwigPlugin` wird für Slot-Blöcke zwingend gebraucht, ist aber **nicht** im `TwigDependencyProvider` registriert, sondern in `Pyz\Yves\CmsBlockWidget\CmsBlockWidgetDependencyProvider::getTwigExtensionPlugins()`; im `TwigDependencyProvider` steht nur der Sammler `CmsBlockTwigPlugin`). Lesen: `Client\ContentStorage` (`findContentTypeContextByKey()`, `getContentTypeContextByKeys()`). Editor-Integration im Back Office: `Pyz\Zed\ContentGui\ContentGuiDependencyProvider` (`getContentPlugins()`, `getContentEditorPlugins()`). Zweite, ältere Schiene "Content-Widgets im Text" (Produkt, Datei, CMS-Block): `Pyz\Yves\CmsContentWidget\CmsContentWidgetDependencyProvider::getCmsContentWidgetPlugins()`, nicht mit den `content_*`-Funktionen verwechseln.

**Slots:** Slot-Template (`spy_cms_slot_template`, Storefront-Twig wie `@HomePage/views/home/home.twig`) != CMS-Seiten-Template. Slot (`spy_cms_slot`: Key `slt-…`, `content_provider`, `template_path`, `is_active`). Zuordnung `spy_cms_slot_block` (Slot+Template+Block+`position` unique, `conditions` JSON). Provider `SprykerCmsSlotBlock`. Syntax (Parser `ShopCmsSlotTokenParser`, Reihenfolge Key, `autofilled`, `required`, `with`): `{% cms_slot 'slt-6' required ['idCategory'] with { idCategory: _widget.idCategory } %}`. Fehlt ein `required`-Key: `MissingRequiredParameterException`, im Nicht-Debug-Modus fängt der Provider und rendert `''`. Laufzeit: `ShopCmsSlotTwigPlugin` -> `CmsSlotDataProvider::getSlotContent()` -> `CmsSlotBlockWidgetCmsSlotContentPlugin` -> Blöcke via `spyCmsBlock({keys})`.

**Visibility Conditions:** Welche Condition-Typen ein Slot-Template anbietet: `Pyz\Zed\CmsSlotBlock\CmsSlotBlockConfig::getTemplateConditionsAssignment()` (Template-Pfad -> Condition-Keys). GUI-Formulare: `CmsSlotBlockGuiDependencyProvider::getCmsSlotBlockFormPlugins()`. Auswertung in Yves: `Pyz\Client\CmsSlotBlock\CmsSlotBlockDependencyProvider::getCmsSlotBlockVisibilityResolverPlugins()` (`CmsSlotBlockVisibilityResolverPluginInterface`: `isApplicable()`, `isCmsBlockVisibleInSlot()`). Block ohne Conditions ist immer sichtbar.

**Sichtbarkeit Store/Gültigkeit:** Seite: `spy_cms_page_store`, `valid_from`/`valid_to` (nur geprüft, wenn **beide** gesetzt), `is_active`, publizierte Version; Yves-Prüfung `CmsController::executePageAction()` -> 404. Block: `spy_cms_block_store`, beide Grenzen einzeln, `active`. Slot: `is_active`.

**Publish & Sync:** über Event-Subscriber (nicht PublisherDependencyProvider-Stacks): `Pyz\Zed\Event\EventDependencyProvider` (`CmsStorageEventSubscriber`, `CmsBlockStorageEventSubscriber`, `CmsBlockCategoryStorageEventSubscriber`, `CmsBlockProductStorageEventSubscriber`, `ContentStorageEventSubscriber`, `CmsSlotStorageEventSubscriber`); Events aus `event`-Behavior in Pyz-Schemas. Seite wird erst durch **Publish** (Version, `CmsEvents::CMS_VERSION_PUBLISH`) im Storage sichtbar. Resource-Namen: `cms_page`, `cms_block`, `cms_slot`, `cms_slot_block`, `content`, `cms_block_category`, `cms_block_product`, `cms_page_search`. Message-Broker-Channel `cms-page-events` ist nur für externe Konsumenten ([24](24-mail-message-broker.md)).

## Anleitung

**Neues CMS-Seiten-Template**
1. `src/Pyz/Shared/Cms/Theme/default/templates/<name>/<name>.twig` mit `extends template('page-layout-main')`, `<!-- CMS_PLACEHOLDER : "x" -->` + `{{ spyCms('x') | raw }}`.
2. Zeile in `data/import/common/common/cms_template.csv` (`template_name,template_path`), `docker/sdk cli console data:import:cms-template`.
3. Seite im Back Office anlegen oder per `cms_page.csv` importieren; **publizieren**; Store zuordnen (`cms_page_store.csv`).

**Neuer Block-Typ**
1. `…/Shared/CmsBlock/Theme/default/template/<name>.twig` mit `{% block content %}` und Platzhaltern.
2. `cms_block.csv` (`block_key,block_name,template_name,template_path,active,placeholder.<name>.<loc>`) + Store in `cms_block_store.csv` (`block_key,store_name`).
3. Einbindung per `spyCmsBlock({key: 'blck-1'})`, Kategorie/Produkt-Zuordnung im Back Office, oder per Slot (s. u.).

**Neuer Slot-Einsatz**
1. In Storefront-Twig `{% cms_slot 'slt-x' required [...] with {...} %}`.
2. `cms_slot_template.csv` (`name,description,template_path`), `cms_slot.csv` (`slot_key,name,description,content_provider,template_path,is_active`), `cms_slot_block.csv` (`slot_key,block_key,position,template_path,conditions.…`; Position pro Slot+Template eindeutig).
3. Neue Condition: Template-Pfad in `getTemplateConditionsAssignment()` + GUI-Form-Plugin + Client-Resolver-Plugin; `required`-Keys im Twig müssen zu den Conditions passen (`idCategory`, `idProductAbstract`, `idCmsPage`).

**Neuer Content-Item-Template-Identifier**: Navigation per `ContentNavigationWidgetConfig::getAvailableTemplateList()` (nur Config). Banner: Pyz-`ContentBannerTwigFunctionProvider::getAvailableTemplates()` erweitern **und** `ContentBannerWidgetFactory::createContentBannerTwigFunctionProvider()` überschreiben (siehe Beispiele).

Importreihenfolge laut `data/import/common/content_management_import_config_EU.yml`: Template -> Block -> Content -> Page -> Slot-Template -> Slot -> Slot-Block -> Store-Relationen ([09](09-data-import.md)). Befehle (alle ausgeführt `[live verifiziert (202610.0)]`, Imports `-t` Exit 0, 16 Actions der Config, `cms-block` 56 DataSets):

```bash
docker/sdk cli console data:import --config=data/import/common/content_management_import_config_EU.yml
docker/sdk cli console data:import:cms-block        # eigene Befehle gibt es nur für cms-block, cms-block-store, cms-template, content-navigation
docker/sdk cli console data:import cms-page         # alle anderen Importer (cms-page, cms-page-store, cms-slot-template, cms-slot,
                                                    # cms-slot-block, content-banner, ...) per Argument; Liste: data:import:dump
docker/sdk cli console publish:trigger-events -r cms_page,cms_block,cms_slot,cms_slot_block,content   # mehrere Ressourcen kommagetrennt, mehrfaches -r zählt nur das letzte
docker/sdk cli console queue:worker:start --stop-when-empty
docker/sdk cli console cache:empty-all              # Twig-Pfadauflösung wird gecacht
```

## Beispiele

**Seiten-Template (echt)** `src/Pyz/Shared/Cms/Theme/default/templates/placeholders-title-content-slot/placeholders-title-content-slot.twig`

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

**Block-Template (echt)** `src/Pyz/Shared/CmsBlock/Theme/default/template/title_and_content_block.twig`

```twig
{% block content %}
    <!-- CMS_BLOCK_PLACEHOLDER : "title" -->
    <!-- CMS_BLOCK_PLACEHOLDER : "content" -->

    {{ spyCmsBlockPlaceholder('title') | raw }}

    {{ spyCmsBlockPlaceholder('content') | raw }}
{% endblock %}
```

**Conditions je Slot-Template (echt)** `src/Pyz/Zed/CmsSlotBlock/CmsSlotBlockConfig.php`

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

**Banner-Template-Identifier erweitern (echt)** `src/Pyz/Yves/ContentBannerWidget/Twig/ContentBannerTwigFunctionProvider.php`

```php
protected function getAvailableTemplates(): array
{
    $contentWidgetTemplates = parent::getAvailableTemplates();

    return [
            static::WIDGET_TEMPLATE_IDENTIFIER_HOME_PAGE => '@ContentBannerWidget/views/banner-home-page/banner-home-page.twig',
        ] + $contentWidgetTemplates;
}
```

Klasse `Pyz\Yves\ContentBannerWidget\Twig\ContentBannerTwigFunctionProvider extends SprykerShop\Yves\ContentBannerWidget\Twig\ContentBannerTwigFunctionProvider`, Konstante `WIDGET_TEMPLATE_IDENTIFIER_HOME_PAGE = 'home-page'`. Damit sie greift, überschreibt `Pyz\Yves\ContentBannerWidget\ContentBannerWidgetFactory` die Methode `createContentBannerTwigFunctionProvider(Environment $twig, string $localeName): TwigFunctionProvider` und gibt `new ContentBannerTwigFunctionProvider($twig, $localeName, $this->getContentBannerClient())` zurück.

**Slot-Plugins registrieren (echt)**

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

**Minimalbeispiel Teaser-Block auf der Startseite** `[Annahme: nicht gegen laufende Installation ausgeführt]`:
1. `src/Pyz/Shared/CmsBlock/Theme/default/template/teaser_block.twig` mit `{% block content %}`, zwei Platzhaltern und `<section class="teaser">…</section>`.
2. `cms_block.csv`: `blck-99,Teaser,Teaser block,@CmsBlock/template/teaser_block.twig,1,<Titel de>,<Titel en>,<Inhalt de>,<Inhalt en>,,,,` (alle 13 Spalten laut Header `block_key,block_name,template_name,template_path,active,placeholder.title.de_DE,placeholder.title.en_US,placeholder.content.de_DE,placeholder.content.en_US,placeholder.imageUrl.en_US,placeholder.imageUrl.de_DE,placeholder.link.en_US,placeholder.link.de_DE`; Beispiel hat 4 leere Platzhalter-Spalten am Ende; weniger Werte als Header-Spalten bricht mit `DataSetWithHeaderCombineFailedException`); `data/import/common/DE/cms_block_store.csv`: `blck-99,DE`.
3. `cms_slot_block.csv`: `slt-3,blck-99,3,@HomePage/views/home/home.twig,,,,,,,` (Position 3 frei in `slt-3`).
4. Import (`data:import:cms-block`, `data:import:cms-block-store`, `data:import cms-slot-block`), danach Queue abarbeiten.

## Fallstricke

1. Platzhalter-Kommentar fehlt -> Platzhalter unsichtbar im Back Office, `spyCms('x')` allein reicht nicht.
2. Template nur im Dateisystem, nicht in `spy_cms_template`/`spy_cms_block_template` -> nicht auswählbar (Import oder `syncTemplate` auslösen).
3. Seite im Back Office sichtbar, in Yves 404 -> nicht publiziert, kein Store (`spy_cms_page_store`), inaktiv, Gültigkeitsfenster, Template nicht auffindbar (`'The Cms Page template is not found.'`), Queues nicht abgearbeitet.
4. Slot bleibt leer -> `required`-Key nicht in `with {…}`; Fehler wird im Nicht-Debug-Modus verschluckt (`ShopCmsSlotConfig::isDebugModeEnabled()` `[Annahme]`).
5. Condition erscheint nicht im Back Office -> Template-Pfad in `getTemplateConditionsAssignment()` weicht vom `template_path` des Slots ab, oder Form-Plugin fehlt; greift nicht in Yves -> Resolver-Plugin fehlt.
6. `CmsBlockTwigFunctionMissingException` -> `CmsBlockWidgetTwigPlugin` fehlt in `Pyz\Yves\CmsBlockWidget\CmsBlockWidgetDependencyProvider::getTwigExtensionPlugins()` (dort, nicht im Yves-`TwigDependencyProvider`, eintragen; im `TwigDependencyProvider` steht nur der Sammler `CmsBlockTwigPlugin`).
7. Unbekannter Content-Item-Identifier -> in `getAvailableTemplates()`/`getAvailableTemplateList()` ergänzen; beim Banner zusätzlich Factory-Override.
8. Zwei Blöcke mit gleicher `position` im selben Slot+Template -> Unique-Key verletzt, Import bricht ab.
9. Block ohne `{% block content %}` -> nichts gerendert `[Annahme]`.
10. Datenbank direkt ändern -> Events/Storage laufen nicht; immer Facade/Importer/Back Office.
11. Gültigkeit Seite: nur eine Grenze gesetzt = immer gültig; beim Block dagegen werden beide einzeln geprüft.
12. Zwei Widget-Schienen (Content Items `content_*` vs. Content-Widgets `CmsContentWidget`) haben getrennte Registrierung.
13. Pro Theme muss das Template unter `Theme/<theme>/…` liegen (Fallback `default`); SCSS/TS in Block-Templates brauchen den Frontend-Build ([15](15-yves-frontend-atomic.md)).

**Deprecated (aus dem Code):** `CmsConfig::getTemplateRealPath()` -> `getTemplateRealPaths()`; `Shared\Cms\CmsConstants` (Konstante) -> `CmsConfig::getThemeName()`; eine `CmsFacade`-Methode (`CmsFacadeInterface.php:73`) -> `createPage()`/`updatePage()`/`publishWithVersion`; `Cms\Business\Page\PageManager` nahezu vollständig "Will be removed in the next major release"; `MissingPlaceholdersException` "Will be removed without replacement"; `CmsSlotBlockFacade`/Repository eine Methode -> `getPaginatedCmsBlocksWithSlotRelations()`; `CmsBlockKeyProvider` alte Methode -> `generateUniqueKey()`; `ContentBannerConfig::WIDGET_TEMPLATE_IDENTIFIER_DEFAULT` -> `WIDGET_TEMPLATE_IDENTIFIER_BOTTOM_TITLE`.

## Checkliste

- [ ] Platzhalter-Kommentar und Twig-Ausgabe vorhanden (`spyCms` / `spyCmsBlockPlaceholder`)
- [ ] Template per CSV importiert und Import-Reihenfolge beachtet
- [ ] Seite publiziert, Store zugeordnet, aktiv, Gültigkeit geprüft
- [ ] Slot: `required`/`with` passen zu Conditions; Resolver- und GUI-Plugin registriert
- [ ] Neue Twig-Funktion/Identifier im Pyz-Provider (und Factory beim Banner) registriert
- [ ] `publish:trigger-events` und Queue-Worker gelaufen, `cache:empty-all`
- [ ] Änderungen in `src/Pyz`/Daten, keine Edits in `vendor/`; Redaktion pflegt nur Items/Blöcke

## Quellen

Doku (Basis `https://docs.spryker.com/docs/`, nicht abgerufen): `pbc/all/content-management-system/latest/base-shop/cms-feature-overview/{cms-feature-overview,cms-pages-overview,cms-blocks-overview}.html`, `…/content-items-feature-overview.html`, `…/manage-in-the-back-office/best-practices-add-content-to-the-storefront-pages-using-templates-and-slots.html`.

Code: `vendor/spryker/cms/src/Spryker/Zed/Cms/{CmsConfig.php,Business/CmsFacadeInterface.php}`, `vendor/spryker/cms-block/src/Spryker/Zed/CmsBlock/CmsBlockConfig.php`, `vendor/spryker/cms-block-storage/src/Spryker/Client/CmsBlockStorage/Storage/CmsBlockStorage.php`, `vendor/spryker-shop/cms-block-widget/src/SprykerShop/Yves/CmsBlockWidget/{Plugin/Twig/*,Validator/CmsBlockValidator.php}`, `vendor/spryker/cms-slot-block/…`, `vendor/spryker-shop/shop-cms-slot/src/SprykerShop/Yves/ShopCmsSlot/{Business/CmsSlotDataProvider.php,Twig/TokenParser/*}`, `vendor/spryker-shop/content-*-widget/…/Twig/*TwigFunctionProvider.php`, `vendor/spryker-shop/cms-page/src/SprykerShop/Yves/CmsPage/Controller/CmsController.php`, `src/Pyz/Zed/{Cms,CmsSlotBlock,CmsSlotBlockGui,ContentGui,Event}/…`, `src/Pyz/Client/CmsSlotBlock/…`, `src/Pyz/Yves/{Twig,ShopCmsSlot,ContentBannerWidget,CmsContentWidget}/…`, `data/import/common/content_management_import_config_EU.yml`, `data/import/common/**/cms_*.csv`.

Nachbarn: [07 Publish & Sync](07-publish-sync.md), [08 Client/Storage](08-client-storage-search.md), [09 Data Import](09-data-import.md), [14 Yves Routing](14-yves-controller-routing.md), [15 Frontend](15-yves-frontend-atomic.md), [16 Widgets](16-widgets.md), [19 i18n](19-multi-store-i18n.md).
