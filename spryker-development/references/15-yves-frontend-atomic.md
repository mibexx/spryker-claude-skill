# Yves Atomic Frontend, themes and frontend build

> Status: release b2b-demo-shop 202610.0 (`spryker-shop/shop-ui` 2.6.0, `spryker/setup-frontend` 1.9.0, `spryker/twig` 3.33.0). `[code-verified, docs not cross-checked]`: statements come from `vendor/` and `src/Pyz`; only the docs pages "Frontend builder for Yves v2", "multi-theme" and "yves-frontend" were read for content. `npm` commands executed in the container `[live verified (202610.0)]`; the `frontend:*` console commands executed `[live verified (202610.0)]` (`frontend:zed:install-dependencies` is deprecated and ends with exit 1 in the project). The new-component example (product-badge) is written following core patterns and was not built `[Assumption]`. Note: `$setting-color-alert` does not exist (only the map `$setting-color-actions`); `rem()`/`fw()` exist as Pyz helpers (`src/Pyz/Yves/ShopUi/Theme/default/styles/helpers/`).

## Purpose

How UI in Yves is built, overridden and compiled: Atomic Design (atom, molecule, organism, template, view), Twig inheritance, theme resolution, TypeScript custom elements, frontend builder v2. Controllers/routing → [14](14-yves-controller-routing.md); widgets → [16](16-widgets.md); CMS slots → [17](17-cms.md); translations (`| trans`) → [19](19-multi-store-i18n.md).

## Concepts

**Levels** (per Yves module under `…/Yves/<Module>/Theme/<theme>/`, default theme `default`):

| Level | Path | Twig call | Example |
|---|---|---|---|
| Atom | `components/atoms/<name>/` | `atom('<name>', '<Module>')` | button, icon, title |
| Molecule | `components/molecules/<name>/` | `molecule(...)` | search-form, product-item |
| Organism | `components/organisms/<name>/` | `organism(...)` | header, footer |
| Template | `templates/<name>/` | `template(...)` | `page-layout-main`, `page-layout-catalog` |
| View | `views/<name>/` | `view(...)` | rendered by the controller |
| Model | `models/` (ShopUi only) | `model('component')`, `model('template')` | base Twig |

Module name default `ShopUi`. Files per component (folder = component = file name): `<name>.twig`, **`index.ts`** (entry point; without it nothing is built), optional `<name>.ts`, `style.scss` + `<name>.scss`, `README.md`. Names kebab-case = custom element tag = BEM block (`name__element`, `name--modifier`); JS hooks `js-<name>__<element>` (`config.jsName`).

**Twig functions** (`ShopUiTwigExtension`): `atom/molecule/organism/template/view(name, module)`, `model(name)`, `publicPath(rel)`, `componentClass`, `componentAttributes`, `qa`. The tag `{% define var = {…} %}` sets defaults; values passed from outside override them; the value `required` throws an error in dev mode if not set. Standard variables of every component (`models/component.twig`): `config` (`name`, `jsName`, `tag`), `data`, `attributes`, `modifiers`, `class`, `embed`, `qa`. Blocks: `component`, `class`, `extraClass`, `attributes`, `body`.

**Including:** `include` static, `embed` additionally with block override, `extends` for inheriting. Convention: always use `only` with `include`/`embed` (avoid context leaks). Extending a core component: `{% extends molecule('x', '@SprykerShop:ShopUi') %}` (syntax `@<Organization>:<Module>[:<theme>]`; without the prefix the Pyz file would inherit from itself `[Assumption]`).

**Theme resolution** (`Spryker\Yves\Twig\TwigConfig::getTemplatePaths()`): project namespace with current theme, then project `default`, then core `default` only. Rule: current theme > default, project > core. Core only ships `default`; further themes exist only in the project. Config `config/Shared/config_default-docker.php:173-175`: `TwigConstants::YVES_THEME` and `CmsConstants::YVES_THEME` (set both, otherwise CMS templates find nothing → [17](17-cms.md)).

**Overriding core** (three ways): (1) replace = same path structure in `src/Pyz/Yves/<Module>/Theme/default/…` (the core styles of this component are then not built along); (2) extend (upgrade-friendly): `extends … '@SprykerShop:<Module>'` + only changed blocks, TS `extends <Core>Component`; (3) styles via base hooks `shop-ui-<name>-base-hook`.

**TypeScript:** base `ShopUi/models/component` (`Component extends HTMLElement`; fields `name`, `jsName`; `init()`, `dispatchCustomEvent()`). Custom element tag = component name (contains a hyphen), in Twig `config.tag`. Registration via `register(tag, () => import(...))` from `ShopUi/app/registry` in `index.ts`; the chunk is loaded lazily, only when the element is in the DOM. Configuration via HTML attributes (`attributes`) + `this.getAttribute()`.
**[Deprecated/removed] `readyCallback()`** since ShopUi 2.0.0 -> only `protected init(): void`. A leftover `readyCallback()` compiles but is never called. Check: `grep -rn "readyCallback" src/Pyz` (no hits in the release).

**Build (frontend builder v2)** lives in `vendor/spryker-shop/shop-ui/src/SprykerShop/Yves/ShopUi/FrontendBuilder/` (TypeScript `.mts`, Webpack 5, `sass-embedded`, Node >= 24.15). **[Deprecated]** Builder v1 (`frontend/settings.js`, `frontend/libs`) no longer exists in the project. Source scan order (later wins): `vendor/spryker-shop` -> `vendor/spryker` -> `vendor/spryker-eco` -> `vendor/spryker-feature` -> `src/Pyz/Yves`. Namespaces/themes: `config/Yves/frontend-build-config.json` (`codeBucket`, `namespace`, `themes`, `defaultTheme`; `themes: []` = default only). Output: `public/Yves/assets/<SPRYKER_BUILD_HASH|current>/<theme>/{css,js,fonts,icons,images}`; CSS bundles `critical`, `non-critical`, `app` (= both, since ShopUi 2.4.0), `util`. URL pattern `ShopUiConstants::YVES_ASSETS_URL_PATTERN` (`config/Shared/config_default.php:714`). Assets in Twig always via `publicPath('images/…')`.

**Sass contract v2** (for new components): `<name>.scss` only defines `@mixin shop-ui-<name>($name: '.<name>') { … }` (mixin at the start of the line, otherwise the mixin index does not find it), `style.scss` calls `@include helper-import(<level>, <name>) { @include shop-ui-<name>; }`, `index.ts` imports `./style.scss`. Variables (`$setting-color-*`), helpers (`helper-color-light()`, `rem()`, `fw()`) are injected automatically (`styles/shared.scss`), do not import them. Parts of the Pyz code still use the v1 contract (`index.ts` = `import './x.scss'`, SCSS emits itself), which runs via "Legacy style rescue" (`--debug-injection` shows it).

## Instructions

1. **Choose the location:** new UI in your own Pyz module `src/Pyz/Yves/<Module>/Theme/default/…` or in the existing Pyz ShopUi (`src/Pyz/Yves/ShopUi/Theme/default/components/…`). Never edit in `vendor/` ([28](28-upgradability.md)).
2. **Create the component:** folder `components/<level>/<name>/` with `<name>.twig` (`{% extends model('component') %}`, `config`/`data`/`attributes` via `define`, `{% block body %}`), `<name>.scss` (mixin), `style.scss`, `index.ts`; for behavior additionally `<name>.ts` (custom element) and `register(...)` in `index.ts`.
3. **Include:** `{% include molecule('<name>') with { data: {…} } only %}` from view/template/widget Twig.
4. **Adapt a core component:** create a folder with the same name in Pyz, Twig with `extends … '@SprykerShop:ShopUi'`, change only blocks; styles with base hook (`@mixin shop-ui-button-base-hook { … }` as a top-level mixin in the Pyz component folder `[Assumption: file placement]`; not used in the release).
5. **Build:** `docker/sdk cli 'npm run yves'` (dev), `yves:watch`, `yves:production`. A new component (new `index.ts`) requires restarting `yves:watch`. Afterwards, if needed, `docker/sdk cli console cache:empty-all` ([25](25-performance-caching.md)).
6. **Additional theme:** set `TwigConstants::YVES_THEME`/`CmsConstants::YVES_THEME` to the name, place only deviations under `…/Theme/<theme>/…`, `frontend:yves:build` or add the name to `themes` in `frontend-build-config.json`. Not tried in the release `[Assumption]` (derived from `settings.mts`, `AssetsUrlProvider`). Do not confuse: the "multi-theme" docs (2021) mention `moduleSuffix`, today it is called `codeBucket`.

Commands (in the project root): all `npm` lines and `console frontend:*` lines executed `[live verified (202610.0)]` (dev build ~3-8 s, `yves:production` ~11 s, `watch` compiles successfully and runs until aborted). Note: `/data/node_modules` only exists in the container; after `docker/sdk down`/`up` (container recreated) it is empty, `docker/sdk up --assets` (~100 s) reinstalls:

| Command | Effect |
|---|---|
| `docker/sdk cli 'npm install'` | workspace dependencies; `postinstall` updates the `tsconfig.yves.json` under `vendor/spryker-shop/shop-ui/…/FrontendBuilder/` (the project root only contains `tsconfig.json`) |
| `docker/sdk cli 'npm run yves'` / `yves:watch` / `yves:production` | dev build / watch with live reload / minified |
| `docker/sdk cli 'npm run yves -- -i'` | show namespaces/themes |
| `docker/sdk cli 'npm run yves -- -t default'` | build only the theme; the namespace option `-n` is not useful here: the project only knows the namespace `""` (`npm run yves -- -i` reports it, `[live verified (202610.0)]`) |
| `docker/sdk cli 'npm run yves -- --debug-injection'` | Sass injection, mixin resolution, legacy rescue |
| `docker/sdk cli 'npm run yves:lint'` / `yves:stylelint` | ESLint / Stylelint |
| `docker/sdk cli console frontend:yves:install-dependencies` | npm install via console |
| `docker/sdk cli console frontend:yves:build` | writes `data/cache/Yves/frontend/build-config.json`, starts the build (`-e production`) |

`frontend:yves:cleanup-assets` is **not registered**: the class `YvesAssetsCleanUpConsole` lives in `vendor/spryker/setup-frontend`, but is not in `Pyz\Zed\Console\ConsoleDependencyProvider` and is missing from `console list` (the same applies to `ZedAssetsCleanUpConsole`). Only use the commands from `console list`.

**[Deprecated]** `frontend:npm:run` (`RunnerConsole`, "Will be removed without replacement") -> `frontend:yves:build` / `frontend:zed:build`.

## Examples

Complete template (molecule `product-badge`, organism `product-badge-list`): [../templates/twig-molecule-organism/](../templates/twig-molecule-organism/README.md).

**Extending core (real, Pyz):** `src/Pyz/Yves/ShopUi/Theme/default/components/molecules/product-item/product-item.twig`

```twig
{% extends molecule('product-item', '@SprykerShop:ShopUi') %}
{% import _self as component %}

{% define data = { category: '' } %}

{% block extraClass %}
    {{ config.jsName }}
{% endblock %}

{% block body %}
    <div class="{{ component.renderClass(config.name ~ '__container', modifiers) }}">
        {% block imageContainer %}
            {% set modifiers = ['catalog'] | merge(modifiers) %}
            {{ parent() }}
        {% endblock %}
        {# … #}
    </div>
{% endblock %}
```

`product-item.ts` in the Pyz folder: `import ProductItemCore from 'ShopUi/components/molecules/product-item/product-item'; export default class ProductItem extends ProductItemCore { protected init(): void { …; super.init(); } }`; `index.ts` calls `register('product-item', () => import(/* webpackMode: "lazy" */ /* webpackChunkName: "product-item" */ './product-item'))`.

**Overriding the layout (real, shortened):** `src/Pyz/Yves/ShopUi/Theme/default/templates/page-layout-main/page-layout-main.twig`

```twig
{% extends template('page-layout-main', '@SprykerShop:ShopUi') %}

{% block header %}
    {% embed organism('header') with {
        data: { isAjaxMode: data.isAjaxHeader, ajaxCartUrl: data.ajaxCartUrl },
        attributes: { navigationId: navigationId },
    } only %}
        {% block navigation %}{% cms_slot 'slt-desktop-header' %}{% endblock %}
    {% endembed %}
{% endblock %}
```

**Core reference for the v2 contract (real):** `organisms/header`: `index.ts` = `import './style.scss';`, `style.scss` = `@include helper-import(organism, header) { @include shop-ui-header; }`, `header.scss` = `@mixin shop-ui-header($name: '.header') { #{$name} { … @if meta.mixin-exists(shop-ui-header-base-hook) { @include shop-ui-header-base-hook; } … @content; } }` with `@use 'sass:meta';`.

**Base layout chain:** `template('page-blank')` (HTML skeleton, assets) <- `template('page-layout-main')` (header, footer, slots) <- module-specific `page-layout-*` (catalog, cart, checkout, customer, company, cms, …) <- `view(...)`.

## Pitfalls

1. `readyCallback()` instead of `init()` -> component silently does nothing (ShopUi >= 2.0.0).
2. `index.ts` missing or folder name != file name -> not built / Twig path not found.
3. `@mixin` not at the start of the line, or top-level CSS without import from `index.ts` -> "unknown mixin" or legacy rescue.
4. `include`/`embed` without `only` -> context leak.
5. Overriding core without `@SprykerShop:<Module>` -> self-inheritance `[Assumption]`.
6. Hard-coded assets instead of `publicPath()` -> breaks with hash/theme.
7. Committing build artifacts (`public/Yves/assets/…`, generated `frontend/assets/global/default/icons/sprite.svg`).
8. Node < 24 -> builder (native `.mts`) does not start.
9. Forgetting `CmsConstants::YVES_THEME` when switching themes.
10. `@use`/`@forward` in `styles/shared.scss` with the same names on both levels -> Sass error; set core values only via `@forward '~ShopUi/styles/shared' with (…)`.
11. Type checking in `yves:lint` is off by default in the project layout (`typecheck: false`, can be enabled in `frontend/yves.settings.mts`, which does not exist in the release).
12. The variable `$setting-color-alert` does not exist: action colors via the map `$setting-color-actions` (`success`, `warning`, `alert`) with `map.get`.

## Checklist

- [ ] Component in `src/Pyz/Yves/<Module>/Theme/default/…`, nothing in `vendor/`
- [ ] Folder = file name = tag = BEM block, `index.ts` present
- [ ] Twig: `extends model('component')`, `define` with `required` for mandatory values, inclusion with `only`
- [ ] SCSS in the v2 contract (mixin + `style.scss`), no core copy if a block/hook is enough
- [ ] TS only `init()`, `super.init()` when extending, tag with a hyphen
- [ ] Assets only via `publicPath()`
- [ ] `npm run yves` (and `yves:lint`, `yves:stylelint`) runs, `yves:production` checked before release
- [ ] Theme switch: `TwigConstants::YVES_THEME` and `CmsConstants::YVES_THEME`

## Sources

Docs (base `https://docs.spryker.com/docs/`): `dg/dev/frontend-development/latest/yves/frontend-builder-for-yves-v2.html` (as of Oct 7, 2026), `…/yves/multi-theme.html` (2021, partly outdated), `…/yves/yves-frontend.html`, `…/yves/frontend-assets-building-and-loading.html`, `…/yves/adding-and-using-external-libraries-in-yves.html`, `…/npm-workspaces-for-frontend-builders.html`.

Code: `vendor/spryker-shop/shop-ui/src/SprykerShop/Yves/ShopUi/{FrontendBuilder/{build,settings}.mts,Twig/ShopUiTwigExtension.php,Twig/TokenParser/ShopUiDefineTwigTokenParser.php,Theme/default/{models/component.{ts,twig},app/registry.ts,components/**,styles/**}}`, `vendor/spryker/twig/src/Spryker/Yves/Twig/TwigConfig.php`, `vendor/spryker/setup-frontend/…/Console/{YvesBuildFrontendConsole,Npm/RunnerConsole}.php`, `src/Pyz/Yves/ShopUi/Theme/default/**`, `config/Yves/frontend-build-config.json`, `package.json`.

Neighbors: [14 Controller/routing](14-yves-controller-routing.md), [16 Widgets](16-widgets.md), [17 CMS](17-cms.md), [19 Multi-store/i18n](19-multi-store-i18n.md), [28 Upgradability](28-upgradability.md), [30 Docker SDK](30-docker-sdk-workflow.md). Template: [../templates/twig-molecule-organism/](../templates/twig-molecule-organism/README.md).
