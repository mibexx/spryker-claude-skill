# Template: twig-molecule-organism (molecule `product-badge`, organism `product-badge-list`)

## Purpose

Yves atomic design components in the frontend builder v2 contract: a pure style/markup molecule (`product-badge`, label of a `ProductBadge`) and an organism with a TypeScript custom element (`product-badge-list`, shows at most N badges, the rest via a "+N" button). Basics: [../../references/15-yves-frontend-atomic.md](../../references/15-yves-frontend-atomic.md).

## File list (target path below the project root)

| File | Purpose |
|---|---|
| `src/Pyz/Yves/ShopUi/Theme/default/components/molecules/product-badge/product-badge.twig` | markup (`<span class="product-badge">`), `define` for `data.label` (required) and `data.badgeKey` |
| `…/molecules/product-badge/product-badge.scss` | Sass mixin `shop-ui-product-badge` (+ base hook, action modifiers from `$setting-color-actions`) |
| `…/molecules/product-badge/style.scss` | `helper-import(molecule, product-badge)` + `@include` |
| `…/molecules/product-badge/index.ts` | entry point (`import './style.scss';`, no custom element) |
| `…/organisms/product-badge-list/product-badge-list.twig` | markup, includes the molecule via `include … only` |
| `…/organisms/product-badge-list/product-badge-list.ts` | custom element (`extends Component`, only `init()`) |
| `…/organisms/product-badge-list/product-badge-list.scss` | mixin `shop-ui-product-badge-list` |
| `…/organisms/product-badge-list/style.scss` | `helper-import(organism, product-badge-list)` + `@include` |
| `…/organisms/product-badge-list/index.ts` | `import './style.scss'` + `register('product-badge-list', () => import(…))` |

## Placeholders / customization

- `product-badge` / `product-badge-list` / `ProductBadge` -> your own name (folder name = file name = tag = BEM block; mixin name `shop-ui-<name>`; tag with a hyphen).
- Location: here Pyz ShopUi (`molecule('product-badge')`, module default `ShopUi`). Alternative: your own Yves module, e.g. `src/Pyz/Yves/ProductBadgeWidget/Theme/default/components/...` (the builder finds it via `src/Pyz/Yves`); then `molecule('product-badge', 'ProductBadgeWidget')`.
- `data.badges`: list of objects with `label` and `badgeKey` (e.g. `ProductBadgeTransfer` from `ProductBadgeClientInterface::findProductBadgesByIdProductAbstract(int): array`).
- Modifiers (action colors) are passed to the molecule by the caller via `modifiers: ['alert']` (`success`, `warning`, `alert`); not hard-wired in the organism.
- Attributes of the organism: `max-visible` (default 3), `hidden-class-name`.

Inclusion (e.g. in a widget Twig, → template `widget`):

```twig
{% include organism('product-badge-list') with {
    data: { badges: _widget.badges },
    attributes: { 'max-visible': 2 },
} only %}
```

## Registration

No PHP registration. The builder finds the components via `components/*/*/index.ts` under `src/Pyz/Yves`. Add the glossary key for the button text to `data/import/common/common/glossary.csv`:

```csv
product_badge.show_more,Mehr anzeigen,de_DE
product_badge.show_more,Show more,en_US
```

(The glossary text is the button's `aria-label`; visibly, TypeScript sets `+N` or `-`.)

## Commands afterwards

`data:import:glossary`, `publish:trigger-events -r translation`, `queue:worker:start --stop-when-empty`, `npm run yves`, `yves:lint`, `yves:stylelint` ran in shop 202610.0 with exit 0 `[live verified (202610.0)]`

```bash
docker/sdk cli console data:import:glossary
docker/sdk cli console publish:trigger-events -r translation
docker/sdk cli console queue:worker:start --stop-when-empty
docker/sdk cli 'npm run yves'            # new component: restart a running yves:watch
docker/sdk cli 'npm run yves:lint'
docker/sdk cli 'npm run yves:stylelint'
```

## Model classes / source

- `vendor/spryker-shop/shop-ui/src/SprykerShop/Yves/ShopUi/Theme/default/components/organisms/header/{index.ts,style.scss,header.scss,header.twig}` (v2 contract, base hook)
- `…/components/atoms/badge/badge.scss` (action modifier loop over `$setting-color-actions`)
- `…/components/molecules/toggler-click/{index.ts,toggler-click.ts,toggler-click.twig}` (custom element, attributes)
- `src/Pyz/Yves/ShopUi/Theme/default/styles/helpers/{_calc-rem.scss,_font.scss}` (`rem()`, `fw()`)

## Verification status

Structure, Twig API (`model('component')`, `define`, `required`), registry import and Sass helpers (`helper-import`, `helper-font-size`, `helper-color-light/dark`, `$setting-color-actions`, `rem()`) checked against `vendor/` and `src/Pyz`. **Walked through in shop 202610.0:** `npm run yves` builds without adjustments (webpack "compiled successfully", 328 entry points, chunk `yves_default.product-badge-list.js`, styles in `yves_default.critical.css`), `yves:lint` and `yves:stylelint` without findings. Rendered via `{% include organism('product-badge-list') with {data: {badges: [...]}, attributes: {'max-visible': 2}} only %}` in a product page: `<product-badge-list class="custom-element ..." max-visible='2' hidden-class-name='product-badge-list__hidden'>` with three `<span class="product-badge ..." data-badge-key=...>` and a toggler button with `aria-label="Show more"` (glossary key `product_badge.show_more` imported and in Redis `kv:translation:en_us:product_badge.show_more`). The behavior of the custom element in the browser (`+N` button) was not executed, only the build and server-side rendering. The build is located in the container (`/data/public/Yves/assets/current/default`), not in the host project directory. `[live verified (202610.0)]` Note: `$setting-color-alert` does not exist, hence modifiers from `$setting-color-actions`. The custom element deliberately has no type import other than `ShopUi/models/component` (path alias from `tsconfig.yves.json`, as in Pyz components).
