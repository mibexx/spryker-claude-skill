# Template: twig-molecule-organism (Molecule `product-badge`, Organism `product-badge-list`)

## Zweck

Yves-Atomic-Design-Komponenten im Frontend-Builder-v2-Kontrakt: eine reine Style-/Markup-Molecule (`product-badge`, Label eines `ProductBadge`) und ein Organism mit TypeScript-Custom-Element (`product-badge-list`, zeigt max. N Badges, Rest per "+N"-Button). Grundlagen: [../../references/15-yves-frontend-atomic.md](../../references/15-yves-frontend-atomic.md).

## Dateiliste (Zielpfad unter dem Projektroot)

| Datei | Zweck |
|---|---|
| `src/Pyz/Yves/ShopUi/Theme/default/components/molecules/product-badge/product-badge.twig` | Markup (`<span class="product-badge">`), `define` für `data.label` (Pflicht) und `data.badgeKey` |
| `…/molecules/product-badge/product-badge.scss` | Sass-Mixin `shop-ui-product-badge` (+ Base-Hook, Aktions-Modifier aus `$setting-color-actions`) |
| `…/molecules/product-badge/style.scss` | `helper-import(molecule, product-badge)` + `@include` |
| `…/molecules/product-badge/index.ts` | Entry-Point (`import './style.scss';`, kein Custom Element) |
| `…/organisms/product-badge-list/product-badge-list.twig` | Markup, bindet die Molecule per `include … only` ein |
| `…/organisms/product-badge-list/product-badge-list.ts` | Custom Element (`extends Component`, nur `init()`) |
| `…/organisms/product-badge-list/product-badge-list.scss` | Mixin `shop-ui-product-badge-list` |
| `…/organisms/product-badge-list/style.scss` | `helper-import(organism, product-badge-list)` + `@include` |
| `…/organisms/product-badge-list/index.ts` | `import './style.scss'` + `register('product-badge-list', () => import(…))` |

## Platzhalter / Anpassung

- `product-badge` / `product-badge-list` / `ProductBadge` -> eigener Name (Ordnername = Dateiname = Tag = BEM-Block; Mixin-Name `shop-ui-<name>`; Tag mit Bindestrich).
- Ablageort: hier Pyz-ShopUi (`molecule('product-badge')`, Modul-Default `ShopUi`). Alternative: eigenes Yves-Modul, z. B. `src/Pyz/Yves/ProductBadgeWidget/Theme/default/components/...` (Builder findet es über `src/Pyz/Yves`); dann `molecule('product-badge', 'ProductBadgeWidget')`.
- `data.badges`: Liste von Objekten mit `label` und `badgeKey` (z. B. `ProductBadgeTransfer` aus `ProductBadgeClientInterface::findProductBadgesByIdProductAbstract(int): array`).
- Modifier (Aktionsfarben) werden vom Aufrufer über `modifiers: ['alert']` (`success`, `warning`, `alert`) an die Molecule gegeben; im Organism nicht fest verdrahtet.
- Attribute des Organism: `max-visible` (Default 3), `hidden-class-name`.

Einbindung (z. B. in einem Widget-Twig, → Template `widget`):

```twig
{% include organism('product-badge-list') with {
    data: { badges: _widget.badges },
    attributes: { 'max-visible': 2 },
} only %}
```

## Registrierung

Keine PHP-Registrierung. Der Builder findet die Komponenten über `components/*/*/index.ts` unter `src/Pyz/Yves`. Glossar-Key für den Button-Text in `data/import/common/common/glossary.csv` ergänzen:

```csv
product_badge.show_more,Mehr anzeigen,de_DE
product_badge.show_more,Show more,en_US
```

(Der Glossar-Text ist das `aria-label` des Buttons; sichtbar setzt TypeScript `+N` bzw. `-`.)

## Befehle danach

`data:import:glossary`, `publish:trigger-events -r translation`, `queue:worker:start --stop-when-empty`, `npm run yves`, `yves:lint`, `yves:stylelint` liefen im Shop 202610.0 mit Exit 0 `[live verifiziert (202610.0)]`

```bash
docker/sdk cli console data:import:glossary
docker/sdk cli console publish:trigger-events -r translation
docker/sdk cli console queue:worker:start --stop-when-empty
docker/sdk cli 'npm run yves'            # Neue Komponente: bei laufendem yves:watch neu starten
docker/sdk cli 'npm run yves:lint'
docker/sdk cli 'npm run yves:stylelint'
```

## Vorbild-Klassen / Quelle

- `vendor/spryker-shop/shop-ui/src/SprykerShop/Yves/ShopUi/Theme/default/components/organisms/header/{index.ts,style.scss,header.scss,header.twig}` (v2-Kontrakt, Base-Hook)
- `…/components/atoms/badge/badge.scss` (Aktions-Modifier-Schleife über `$setting-color-actions`)
- `…/components/molecules/toggler-click/{index.ts,toggler-click.ts,toggler-click.twig}` (Custom Element, Attribute)
- `src/Pyz/Yves/ShopUi/Theme/default/styles/helpers/{_calc-rem.scss,_font.scss}` (`rem()`, `fw()`)

## Verifikationsstatus

Struktur, Twig-API (`model('component')`, `define`, `required`), Registry-Import und Sass-Helper (`helper-import`, `helper-font-size`, `helper-color-light/dark`, `$setting-color-actions`, `rem()`) gegen `vendor/` und `src/Pyz` geprüft. **Durchgespielt im Shop 202610.0:** `npm run yves` baut ohne Anpassung (webpack "compiled successfully", 328 Entry Points, Chunk `yves_default.product-badge-list.js`, Styles in `yves_default.critical.css`), `yves:lint` und `yves:stylelint` ohne Befund. Gerendert über `{% include organism('product-badge-list') with {data: {badges: [...]}, attributes: {'max-visible': 2}} only %}` in einer Produktseite: `<product-badge-list class="custom-element ..." max-visible='2' hidden-class-name='product-badge-list__hidden'>` mit drei `<span class="product-badge ..." data-badge-key=...>` und Toggler-Button mit `aria-label="Show more"` (Glossar-Key `product_badge.show_more` importiert und in Redis `kv:translation:en_us:product_badge.show_more`). Das Verhalten des Custom Elements im Browser (`+N`-Button) wurde nicht ausgeführt, nur Build und serverseitiges Rendering. Der Build liegt im Container (`/data/public/Yves/assets/current/default`), nicht im Host-Projektverzeichnis. `[live verifiziert (202610.0)]` Abweichung zur Recherche: `$setting-color-alert` existiert nicht, daher Modifier aus `$setting-color-actions`. Das Custom Element hat bewusst kein Typ-Import außer `ShopUi/models/component` (Pfad-Alias aus `tsconfig.yves.json`, wie in Pyz-Komponenten).
