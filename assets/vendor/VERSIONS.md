# Vendored third-party assets

Committed directly into the repo (no build step, no separate download step at deploy time).
Pinned versions — bump deliberately, not by tracking `latest`.

| File | Library | Pinned version | Source |
|---|---|---|---|
| `sortable.min.js` | [SortableJS](https://github.com/SortableJS/Sortable) | 1.15.6 | `https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js` |
| `vditor/` | [Vditor](https://github.com/Vanessa219/vditor) | 3.11.2 | `https://registry.npmjs.org/vditor/-/vditor-3.11.2.tgz` |

To update: re-fetch from the source above at a new pinned version and update this table.

## Vditor — what's vendored and why

Replaced EasyMDE (2.18.0, previously vendored here as `easymde.min.js`/`.min.css`) as the Script &
Notes editor — see SPEC.md §5.3 for the reasoning (EasyMDE's edit/preview toggle felt clunky;
Vditor's "ir" instant-render mode renders formatting inline as you type, no separate preview mode
at all).

Vditor's npm package is much more modular than EasyMDE's single-file bundle: most of its `dist/js/`
subfolders are optional, lazily-loaded-on-demand renderers for content this app will never produce
(math via KaTeX/MathJax, diagrams via Mermaid/Graphviz/PlantUML/flowchart.js, charts via ECharts,
music notation via ABCJS, chemistry via SMILES, mind maps via Markmap). Those are **not** vendored
— if net notes ever contain that kind of syntax, Vditor's dynamic loader will try to fetch them
from `options.cdn` (configured as the local `assets/vendor/vditor` path in `net.js`) and get a 404,
degrading gracefully to a plain-rendered code block rather than a crash.

What *is* vendored (required for the "ir" mode to work at all):

| Path | Purpose | Size |
|---|---|---|
| `vditor.min.js` | Core editor (renamed from `dist/index.min.js`) | 288 KB |
| `vditor.min.css` | Core styles (renamed from `dist/index.css`) | 44 KB |
| `dist/js/lute/lute.min.js` | **Required.** The markdown parsing/rendering engine "ir" mode calls directly (`SpinVditorIRDOM`) — this is not optional, and it's the overwhelming majority of the footprint below | 3.9 MB |
| `dist/js/icons/material.js` | Toolbar icon set (`icon: 'material'` in `net.js` — smaller than the `ant` default, 44 KB) | 24 KB |
| `dist/js/i18n/en_US.js` | UI strings (`lang: 'en_US'` in `net.js` — default is `zh_CN`) | 4 KB |
| `dist/images/` | Emoji picker graphics + logo/loading spinner | 108 KB |
| `dist/css/content-theme/dark.css` + `light.css` | Rendered content text colors — see gotcha below | ~7 KB |

**Total: ~4.4 MB** — dominated almost entirely by `lute.min.js`. Confirmed via the actual
`src/index.ts` source (not guessed) that `${cdn}/dist/js/lute/lute.min.js`,
`${cdn}/dist/js/icons/${icon}.js`, and `${cdn}/dist/js/i18n/${lang}.js` are the exact paths Vditor
constructs at runtime relative to the `cdn` option — hence the `dist/js/...` folder structure
being preserved here rather than flattened, unlike the top-level `vditor.min.js`/`.min.css` rename.

This is a meaningful size jump from EasyMDE's ~326 KB — acceptable for an internal ops tool
(one-time cached download, not a public/mobile-data-sensitive page), but worth knowing if this
decision gets revisited later.

### Gotcha: `theme` vs `preview.theme.current` are independent settings

Vditor has **two separate theme options** that are easy to conflate: `theme` (`'classic'` or
`'dark'`, controls the toolbar/UI chrome) and `preview.theme.current` (`'light'` or `'dark'`,
controls the color of the *rendered markdown content itself* — headings, bold, etc.). The latter
defaults to `"light"` regardless of what `theme` is set to. Setting only `theme: 'dark'` gets you
a dark toolbar with content text rendered in `preview.theme`'s default light-mode color — which,
against the dark editor background, computed to the *exact same RGB value* as the background in
testing, i.e. completely invisible text with a perfectly correct-looking DOM underneath (easy to
mistake for "the editor isn't loading" when it's actually rendering fine, just invisibly). Fixed by
setting both options together based on the same light/dark check — see `initScriptNotesEditor()`
in `net.js`. Confirmed via computed-style inspection (not just visually) in both themes before
shipping.
