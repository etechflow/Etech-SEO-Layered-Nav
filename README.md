# ETechFlow_SeoLayeredNav

**SEO-friendly layered navigation** for Magento 2 — turns ugly filter URLs into human-readable slugs and manages canonical/robots/sitemap for filter pages. Theme-agnostic (Hyvä + Luma). Part of the **Etechflow SEO Suite**.

## The problem

Magento's layered navigation produces `?manufacturer=2069&blade=2071` — opaque, unindexable, and a duplicate-content minefield. This module makes those URLs readable, then controls exactly which filter combinations Google indexes.

## Features

**Phase 1 — Readable filter URLs** *(toggle: `general/enabled`)*
- `?manufacturer=yale` instead of `?manufacturer=2069`, fully two-way (slug ↔ option-id).
- Works on dropdown filters, swatches, and inbound requests; outbound links in the layer are rewritten automatically.
- Optional multi-select (disjunctive facets — click to add a value).
- Deterministic slugs (`SlugGenerator`): `"Premier 2000+"` → `premier-2000`, stable across stores and re-runs.
- Aliases stored in `etechflow_seo_filter_alias`. Rebuild from the admin — **Marketing → SEO Filter URLs → "Rebuild SEO URLs"** (one click, with an optional store-scope picker) — or via `bin/magento etechflow:seo-nav:generate-aliases`. Both share one code path (`Model\AliasRebuilder`). Run it after imports or attribute-option changes.

**Phase 2 — Canonical & robots for filter pages** *(toggle: `seo/manage_meta`, gated separately)*
- Single indexable filter → self-canonical + `INDEX,FOLLOW`.
- Multiple filters / price / non-whitelisted → base-category canonical + `NOINDEX,FOLLOW`.
- Configurable indexable-attribute whitelist; optional `NOINDEX` on pagination.
- Head-block based — renders in any theme.

**Phase 2.5 — Sitemap discovery** *(toggle: `seo/sitemap_filter_pages`)*
- Emits indexable single-filter landing pages into the XML sitemap (whitelist-gated, products-only, capped).

Everything is **OFF by default** — storefront URLs are untouched until a merchant opts in *and* aliases have been generated.

## Install

```bash
composer require etechflow/module-seo-layered-nav
bin/magento module:enable ETechFlow_SeoLayeredNav
bin/magento setup:upgrade
bin/magento setup:di:compile           # production
bin/magento etechflow:seo-nav:generate-aliases
```

## Configure

**Stores → Configuration → eTechFlow → SEO Layered Navigation**: enable, choose URL format, multi-select, and (separately) the Phase 2 canonical/robots + sitemap options.

> On a store already running another SEO-filter/canonical extension (e.g. Mirasvit_SeoFilter), enable Phase 1 only and leave Phase 2/2.5 off to avoid duplicate canonicals.

## Compatibility

Magento Open Source / Adobe Commerce 2.4.x · PHP ≥ 8.1 · Hyvä + Luma · query-style URLs (`?manufacturer=yale`). Path-style (`/category/manufacturer/yale`) is planned for a future major version.

## License

Proprietary — © eTechFlow.
