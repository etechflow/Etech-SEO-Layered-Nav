# Publishing etechflow/module-seo-layered-nav

Package version: **1.0.0**. Validated (`composer validate` passes; all PHP lints clean).

## What it ships
- **Readable filter URLs** (Phase 1): `?manufacturer=yale` instead of `?manufacturer=2069`, two-way slug↔option-id, dropdown + swatch + inbound. Console: `bin/magento etechflow:seo-nav:generate-aliases`.
- **Canonical + robots** (Phase 2): single indexable filter → self-canonical + INDEX,FOLLOW; multi/price/non-indexable → base canonical + NOINDEX,FOLLOW. Head-block based (theme-agnostic).
- **Sitemap discovery** (Phase 2.5): indexable filter landing pages emitted into the XML sitemap (whitelist-gated, products-only, capped).
- All behind config flags under **Stores → Config → eTechFlow → SEO Layered Navigation**, default OFF.

## Compatibility
Magento Open Source / Adobe Commerce 2.4.x, PHP >=8.1, Luma + Hyva. Query-style URLs only (path-style is a future major version). On a store already running another SEO-filter/canonical module (e.g. Mirasvit_SeoFilter / Mirasvit_Seo), leave Phase 2/2.5 off to avoid duplicate canonicals.

## How to publish (registry not yet configured)
There is currently **no etechflow Composer registry** in the target project's `composer.json`. Pick one mechanism:

### Option A — VCS (git) repository (simplest)
1. Put this module in its own git repo (e.g. `git@github.com:etechflow/module-seo-layered-nav.git`).
2. Tag the release: `git tag 1.0.0 && git push --tags`.
3. In each consuming project's `composer.json` add:
   ```json
   "repositories": { "etechflow-seo": { "type": "vcs", "url": "git@github.com:etechflow/module-seo-layered-nav.git" } }
   ```
4. `composer require etechflow/module-seo-layered-nav:^1.0`

### Option B — Satis / Private Packagist (a real `modules.etechflow.com`)
1. Stand up Satis (or Private Packagist) at `modules.etechflow.com`, pointing at the module's git repo + tag.
2. Add to consuming projects:
   ```json
   "repositories": { "etechflow": { "type": "composer", "url": "https://modules.etechflow.com/" } }
   ```
3. `composer require etechflow/module-seo-layered-nav:^1.0` (with auth in `auth.json`).

**Needed to proceed:** the git remote (or Satis host) + credentials. Once provided, tag 1.0.0 and wire the repo.
