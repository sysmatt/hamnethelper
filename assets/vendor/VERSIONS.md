# Vendored third-party assets

Committed directly into the repo (no build step, no separate download step at deploy time).
Pinned versions — bump deliberately, not by tracking `latest`.

| File | Library | Pinned version | Source |
|---|---|---|---|
| `sortable.min.js` | [SortableJS](https://github.com/SortableJS/Sortable) | 1.15.6 | `https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js` |
| `easymde.min.js` | [EasyMDE](https://github.com/Ionaru/easy-markdown-editor) | 2.18.0 | `https://cdn.jsdelivr.net/npm/easymde@2.18.0/dist/easymde.min.js` |
| `easymde.min.css` | EasyMDE | 2.18.0 | `https://cdn.jsdelivr.net/npm/easymde@2.18.0/dist/easymde.min.css` |

To update: re-fetch from jsdelivr at a new pinned version and update this table.
