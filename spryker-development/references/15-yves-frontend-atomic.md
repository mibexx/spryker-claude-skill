# Yves Atomic Frontend, Themes und Frontend-Build (Recherche T-15)

> Status: Release b2b-demo-shop 202610.0 (`spryker-shop/shop-ui` 2.6.0, `spryker/setup-frontend` 1.9.0, `spryker/twig` 3.33.0). `[code-verifiziert, Doku nicht abgeglichen]`: Aussagen stammen aus `vendor/` und `src/Pyz`; nur die Doku-Seiten "Frontend builder for Yves v2", "multi-theme" und "yves-frontend" wurden inhaltlich gelesen. `npm`-Befehle im Container ausgeführt `[live verifiziert (202610.0)]`; die `frontend:*`-Konsolenbefehle ausgeführt `[live verifiziert (202610.0)]` (`frontend:zed:install-dependencies` ist deprecated und endet im Projekt mit Exit 1). Das Neu-Komponenten-Beispiel (product-badge) ist nach Core-Mustern geschrieben und nicht gebaut `[Annahme]`. Abweichung zur Recherche: `$setting-color-alert` existiert nicht (nur Map `$setting-color-actions`); `rem()`/`fw()` existieren als Pyz-Helper (`src/Pyz/Yves/ShopUi/Theme/default/styles/helpers/`).

## Zweck

Wie UI in Yves aufgebaut, überschrieben und gebaut wird: Atomic Design (Atom, Molecule, Organism, Template, View), Twig-Vererbung, Theme-Auflösung, TypeScript-Custom-Elements, Frontend-Builder v2. Controller/Routing → [14](14-yves-controller-routing.md); Widgets → [16](16-widgets.md); CMS-Slots → [17](17-cms.md); Übersetzungen (`| trans`) → [19](19-multi-store-i18n.md).

## Konzepte

**Ebenen** (je Yves-Modul unter `…/Yves/<Modul>/Theme/<theme>/`, Standard-Theme `default`):

| Ebene | Pfad | Twig-Aufruf | Beispiel |
|---|---|---|---|
| Atom | `components/atoms/<name>/` | `atom('<name>', '<Modul>')` | button, icon, title |
| Molecule | `components/molecules/<name>/` | `molecule(...)` | search-form, product-item |
| Organism | `components/organisms/<name>/` | `organism(...)` | header, footer |
| Template | `templates/<name>/` | `template(...)` | `page-layout-main`, `page-layout-catalog` |
| View | `views/<name>/` | `view(...)` | vom Controller gerendert |
| Model | `models/` (nur ShopUi) | `model('component')`, `model('template')` | Basis-Twig |

Modulname default `ShopUi`. Dateien je Komponente (Ordner = Komponente = Dateiname): `<name>.twig`, **`index.ts`** (Entry-Point; ohne ihn wird nicht gebaut), optional `<name>.ts`, `style.scss` + `<name>.scss`, `README.md`. Namen kebab-case = Custom-Element-Tag = BEM-Block (`name__element`, `name--modifier`); JS-Hooks `js-<name>__<element>` (`config.jsName`).

**Twig-Funktionen** (`ShopUiTwigExtension`): `atom/molecule/organism/template/view(name, modul)`, `model(name)`, `publicPath(rel)`, `componentClass`, `componentAttributes`, `qa`. Tag `{% define var = {…} %}` setzt Defaults; von außen übergebene Werte überschreiben; Wert `required` wirft im Dev-Modus einen Fehler, wenn nicht gesetzt. Standard-Variablen jeder Komponente (`models/component.twig`): `config` (`name`, `jsName`, `tag`), `data`, `attributes`, `modifiers`, `class`, `embed`, `qa`. Blöcke: `component`, `class`, `extraClass`, `attributes`, `body`.

**Einbinden:** `include` statisch, `embed` zusätzlich mit Block-Override, `extends` zum Erben. Konvention: immer `only` an `include`/`embed` (Kontext-Leck vermeiden). Core-Komponente erweitern: `{% extends molecule('x', '@SprykerShop:ShopUi') %}` (Syntax `@<Organisation>:<Modul>[:<theme>]`; ohne Präfix würde die Pyz-Datei sich selbst erben `[Annahme]`).

**Theme-Auflösung** (`Spryker\Yves\Twig\TwigConfig::getTemplatePaths()`): Projekt-Namespace mit aktuellem Theme, dann Projekt `default`, dann Core nur `default`. Regel: Current Theme > Default, Projekt > Core. Core liefert nur `default`; weitere Themes existieren nur im Projekt. Konfig `config/Shared/config_default-docker.php:173-175`: `TwigConstants::YVES_THEME` und `CmsConstants::YVES_THEME` (beide setzen, sonst finden CMS-Templates nichts → [17](17-cms.md)).

**Überschreiben von Core** (drei Wege): (1) ersetzen = gleiche Pfadstruktur in `src/Pyz/Yves/<Modul>/Theme/default/…` (Core-Styles dieser Komponente werden dann nicht mitgebaut); (2) erweitern (upgrade-freundlich): `extends … '@SprykerShop:<Modul>'` + nur geänderte Blöcke, TS `extends <Core>Component`; (3) Styles über Base-Hooks `shop-ui-<name>-base-hook`.

**TypeScript:** Basis `ShopUi/models/component` (`Component extends HTMLElement`; Felder `name`, `jsName`; `init()`, `dispatchCustomEvent()`). Custom-Element-Tag = Komponentenname (enthält Bindestrich), im Twig `config.tag`. Registrierung per `register(tag, () => import(...))` aus `ShopUi/app/registry` im `index.ts`; Chunk wird lazy geladen, nur wenn das Element im DOM steht. Konfiguration über HTML-Attribute (`attributes`) + `this.getAttribute()`.
**[Deprecated/entfernt] `readyCallback()`** seit ShopUi 2.0.0 -> nur `protected init(): void`. Ein übrig gebliebenes `readyCallback()` kompiliert, wird nie aufgerufen. Prüfung: `grep -rn "readyCallback" src/Pyz` (im Release ohne Treffer).

**Build (Frontend-Builder v2)** liegt in `vendor/spryker-shop/shop-ui/src/SprykerShop/Yves/ShopUi/FrontendBuilder/` (TypeScript `.mts`, Webpack 5, `sass-embedded`, Node >= 24.15). **[Deprecated]** Builder v1 (`frontend/settings.js`, `frontend/libs`) ist im Projekt nicht mehr vorhanden. Quellen-Scan-Reihenfolge (spätere gewinnt): `vendor/spryker-shop` -> `vendor/spryker` -> `vendor/spryker-eco` -> `vendor/spryker-feature` -> `src/Pyz/Yves`. Namespaces/Themes: `config/Yves/frontend-build-config.json` (`codeBucket`, `namespace`, `themes`, `defaultTheme`; `themes: []` = nur Default). Ausgabe: `public/Yves/assets/<SPRYKER_BUILD_HASH|current>/<theme>/{css,js,fonts,icons,images}`; CSS-Bundles `critical`, `non-critical`, `app` (= beides, ab ShopUi 2.4.0), `util`. URL-Muster `ShopUiConstants::YVES_ASSETS_URL_PATTERN` (`config/Shared/config_default.php:714`). Assets im Twig immer über `publicPath('images/…')`.

**Sass-Kontrakt v2** (für neue Komponenten): `<name>.scss` definiert nur `@mixin shop-ui-<name>($name: '.<name>') { … }` (Mixin am Zeilenanfang, sonst findet der Mixin-Index es nicht), `style.scss` ruft `@include helper-import(<ebene>, <name>) { @include shop-ui-<name>; }`, `index.ts` importiert `./style.scss`. Variablen (`$setting-color-*`), Helper (`helper-color-light()`, `rem()`, `fw()`) werden automatisch injiziert (`styles/shared.scss`), nicht importieren. Teile des Pyz-Codes nutzen noch den v1-Kontrakt (`index.ts` = `import './x.scss'`, SCSS emittiert selbst), der über "Legacy style rescue" läuft (`--debug-injection` zeigt ihn).

## Anleitung

1. **Ablageort wählen:** neue UI in eigenem Pyz-Modul `src/Pyz/Yves/<Modul>/Theme/default/…` oder im vorhandenen Pyz-ShopUi (`src/Pyz/Yves/ShopUi/Theme/default/components/…`). Nie in `vendor/` editieren ([28](28-upgradability.md)).
2. **Komponente anlegen:** Ordner `components/<ebene>/<name>/` mit `<name>.twig` (`{% extends model('component') %}`, `config`/`data`/`attributes` per `define`, `{% block body %}`), `<name>.scss` (Mixin), `style.scss`, `index.ts`; bei Verhalten zusätzlich `<name>.ts` (Custom Element) und `register(...)` im `index.ts`.
3. **Einbinden:** `{% include molecule('<name>') with { data: {…} } only %}` aus View/Template/Widget-Twig.
4. **Core-Komponente anpassen:** gleichnamigen Ordner in Pyz anlegen, Twig mit `extends … '@SprykerShop:ShopUi'`, nur Blöcke ändern; Styles mit Base-Hook (`@mixin shop-ui-button-base-hook { … }` als Top-Level-Mixin im Pyz-Komponentenordner `[Annahme: Dateiplatzierung]`; im Release nicht verwendet).
5. **Bauen:** `docker/sdk cli 'npm run yves'` (Dev), `yves:watch`, `yves:production`. Neue Komponente (neuer `index.ts`) erfordert Neustart von `yves:watch`. Danach ggf. `docker/sdk cli console cache:empty-all` ([25](25-performance-caching.md)).
6. **Weiteres Theme:** `TwigConstants::YVES_THEME`/`CmsConstants::YVES_THEME` auf Namen setzen, nur Abweichungen unter `…/Theme/<theme>/…` ablegen, `frontend:yves:build` oder Name in `frontend-build-config.json` `themes` eintragen. Nicht im Release ausprobiert `[Annahme]` (abgeleitet aus `settings.mts`, `AssetsUrlProvider`). Nicht verwechseln: Doku "multi-theme" (2021) nennt `moduleSuffix`, heute heißt es `codeBucket`.

Befehle (im Projektroot): alle `npm`-Zeilen und `console frontend:*`-Zeilen ausgeführt `[live verifiziert (202610.0)]` (Dev-Build ~3-8 s, `yves:production` ~11 s, `watch` kompiliert erfolgreich und läuft bis Abbruch). Hinweis: `/data/node_modules` liegt nur im Container; nach `docker/sdk down`/`up` (Container neu erzeugt) ist es leer, `docker/sdk up --assets` (~100 s) installiert neu:

| Befehl | Wirkung |
|---|---|
| `docker/sdk cli 'npm install'` | Workspaces-Abhängigkeiten; `postinstall` aktualisiert die `tsconfig.yves.json` unter `vendor/spryker-shop/shop-ui/…/FrontendBuilder/` (im Projektroot liegt nur `tsconfig.json`) |
| `docker/sdk cli 'npm run yves'` / `yves:watch` / `yves:production` | Dev-Build / Watch mit Live-Reload / minifiziert |
| `docker/sdk cli 'npm run yves -- -i'` | Namespaces/Themes anzeigen |
| `docker/sdk cli 'npm run yves -- -t default'` | nur Theme bauen; Namespace-Option `-n` hier nicht sinnvoll: das Projekt kennt nur den Namespace `""` (`npm run yves -- -i` meldet ihn, `[live verifiziert (202610.0)]`) |
| `docker/sdk cli 'npm run yves -- --debug-injection'` | Sass-Injection, Mixin-Auflösung, Legacy-Rescue |
| `docker/sdk cli 'npm run yves:lint'` / `yves:stylelint` | ESLint / Stylelint |
| `docker/sdk cli console frontend:yves:install-dependencies` | npm install via Console |
| `docker/sdk cli console frontend:yves:build` | schreibt `data/cache/Yves/frontend/build-config.json`, startet Build (`-e production`) |

`frontend:yves:cleanup-assets` ist **nicht registriert**: die Klasse `YvesAssetsCleanUpConsole` liegt in `vendor/spryker/setup-frontend`, steht aber nicht in `Pyz\Zed\Console\ConsoleDependencyProvider` und fehlt in `console list` (gleiches gilt für `ZedAssetsCleanUpConsole`). Nur die Befehle aus `console list` verwenden.

**[Deprecated]** `frontend:npm:run` (`RunnerConsole`, "Will be removed without replacement") -> `frontend:yves:build` / `frontend:zed:build`.

## Beispiele

Vollständige Vorlage (Molecule `product-badge`, Organism `product-badge-list`): [../templates/twig-molecule-organism/](../templates/twig-molecule-organism/README.md).

**Core erweitern (echt, Pyz):** `src/Pyz/Yves/ShopUi/Theme/default/components/molecules/product-item/product-item.twig`

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

`product-item.ts` im Pyz-Ordner: `import ProductItemCore from 'ShopUi/components/molecules/product-item/product-item'; export default class ProductItem extends ProductItemCore { protected init(): void { …; super.init(); } }`; `index.ts` ruft `register('product-item', () => import(/* webpackMode: "lazy" */ /* webpackChunkName: "product-item" */ './product-item'))`.

**Layout überschreiben (echt, gekürzt):** `src/Pyz/Yves/ShopUi/Theme/default/templates/page-layout-main/page-layout-main.twig`

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

**Core-Vorbild v2-Kontrakt (echt):** `organisms/header`: `index.ts` = `import './style.scss';`, `style.scss` = `@include helper-import(organism, header) { @include shop-ui-header; }`, `header.scss` = `@mixin shop-ui-header($name: '.header') { #{$name} { … @if meta.mixin-exists(shop-ui-header-base-hook) { @include shop-ui-header-base-hook; } … @content; } }` mit `@use 'sass:meta';`.

**Basis-Layout-Kette:** `template('page-blank')` (HTML-Gerüst, Assets) <- `template('page-layout-main')` (Header, Footer, Slots) <- modulspezifische `page-layout-*` (catalog, cart, checkout, customer, company, cms, …) <- `view(...)`.

## Fallstricke

1. `readyCallback()` statt `init()` -> Komponente tut stillschweigend nichts (ShopUi >= 2.0.0).
2. `index.ts` fehlt oder Ordnername != Dateiname -> nicht gebaut / Twig-Pfad nicht gefunden.
3. `@mixin` nicht am Zeilenanfang, oder Top-Level-CSS ohne Import aus `index.ts` -> "unknown mixin" bzw. Legacy-Rescue.
4. `include`/`embed` ohne `only` -> Kontext-Leck.
5. Core überschreiben ohne `@SprykerShop:<Modul>` -> Selbstvererbung `[Annahme]`.
6. Assets hartcodiert statt `publicPath()` -> bricht mit Hash/Theme.
7. Build-Artefakte committen (`public/Yves/assets/…`, generiertes `frontend/assets/global/default/icons/sprite.svg`).
8. Node < 24 -> Builder (`.mts` nativ) startet nicht.
9. `CmsConstants::YVES_THEME` bei Theme-Wechsel vergessen.
10. `@use`/`@forward` in `styles/shared.scss` mit gleichen Namen auf beiden Ebenen -> Sass-Fehler; Core-Werte nur über `@forward '~ShopUi/styles/shared' with (…)` setzen.
11. Typprüfung `yves:lint` ist im Projekt-Layout per Default aus (`typecheck: false`, aktivierbar in `frontend/yves.settings.mts`, existiert im Release nicht).
12. Variable `$setting-color-alert` existiert nicht: Aktionsfarben über Map `$setting-color-actions` (`success`, `warning`, `alert`) per `map.get`.

## Checkliste

- [ ] Komponente in `src/Pyz/Yves/<Modul>/Theme/default/…`, nichts in `vendor/`
- [ ] Ordner = Dateiname = Tag = BEM-Block, `index.ts` vorhanden
- [ ] Twig: `extends model('component')`, `define` mit `required` für Pflichtwerte, Einbindung mit `only`
- [ ] SCSS im v2-Kontrakt (Mixin + `style.scss`), keine Core-Kopie, wenn Block/Hook reicht
- [ ] TS nur `init()`, `super.init()` bei Erweiterung, Tag mit Bindestrich
- [ ] Assets nur über `publicPath()`
- [ ] `npm run yves` (und `yves:lint`, `yves:stylelint`) läuft, `yves:production` vor Release geprüft
- [ ] Theme-Wechsel: `TwigConstants::YVES_THEME` und `CmsConstants::YVES_THEME`

## Quellen

Doku (Basis `https://docs.spryker.com/docs/`): `dg/dev/frontend-development/latest/yves/frontend-builder-for-yves-v2.html` (Stand 7. Okt. 2026), `…/yves/multi-theme.html` (2021, teils veraltet), `…/yves/yves-frontend.html`, `…/yves/frontend-assets-building-and-loading.html`, `…/yves/adding-and-using-external-libraries-in-yves.html`, `…/npm-workspaces-for-frontend-builders.html`.

Code: `vendor/spryker-shop/shop-ui/src/SprykerShop/Yves/ShopUi/{FrontendBuilder/{build,settings}.mts,Twig/ShopUiTwigExtension.php,Twig/TokenParser/ShopUiDefineTwigTokenParser.php,Theme/default/{models/component.{ts,twig},app/registry.ts,components/**,styles/**}}`, `vendor/spryker/twig/src/Spryker/Yves/Twig/TwigConfig.php`, `vendor/spryker/setup-frontend/…/Console/{YvesBuildFrontendConsole,Npm/RunnerConsole}.php`, `src/Pyz/Yves/ShopUi/Theme/default/**`, `config/Yves/frontend-build-config.json`, `package.json`.

Nachbarn: [14 Controller/Routing](14-yves-controller-routing.md), [16 Widgets](16-widgets.md), [17 CMS](17-cms.md), [19 Multi-Store/i18n](19-multi-store-i18n.md), [28 Upgradability](28-upgradability.md), [30 Docker-SDK](30-docker-sdk-workflow.md). Template: [../templates/twig-molecule-organism/](../templates/twig-molecule-organism/README.md).
