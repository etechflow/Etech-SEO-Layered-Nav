<?php
declare(strict_types=1);

namespace ETechFlow\SeoLayeredNav\Model;

use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Decides the canonical URL + robots directive for a category page, based on
 * its active layered-nav filters. Shared by the head block that renders them.
 *
 * Policy:
 *   - no filters            -> null (leave the page as-is)
 *   - one indexable filter  -> self-canonical + INDEX,FOLLOW  (a real landing page)
 *   - anything else         -> base canonical + configured robots (default NOINDEX,FOLLOW)
 */
class MetaPolicy
{
    private const NON_FILTER_PARAMS = [
        'p', 'product_list_limit', 'product_list_order', 'product_list_dir',
        'product_list_mode', 'mode', 'q', '___store', '___from_store', 'nocache',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly AliasResolver $resolver,
        private readonly RequestInterface $request,
        private readonly LayerResolver $layerResolver,
        private readonly StoreManagerInterface $storeManager,
        private readonly PathUrlBuilder $pathBuilder
    ) {
    }

    /**
     * @return array{canonical:string, robots:string}|null
     */
    public function resolve(): ?array
    {
        try {
            if ($this->request->getFullActionName() !== 'catalog_category_view') {
                return null;
            }
            $storeId = (int) $this->storeManager->getStore()->getId();
            if (!$this->config->managesMeta($storeId)) {
                return null;
            }

            // Page 2+ of any listing (uses only the ?p param — no collection load).
            $isPaged = ((int) $this->request->getParam('p', 1)) > 1
                && $this->config->noindexPagination($storeId);

            [$attrFilters, $hasPrice, $hasCat] = $this->activeFilters();
            $total = count($attrFilters) + ($hasPrice ? 1 : 0) + ($hasCat ? 1 : 0);

            // Plain, page-1, unfiltered category: nothing to manage — leave it alone.
            if ($total === 0 && !$isPaged) {
                return null;
            }

            $category = $this->layerResolver->get()->getCurrentCategory();
            if (!$category || !$category->getId()) {
                return null;
            }
            $baseUrl = (string) $category->getUrl();

            $indexableSingle = $total === 1
                && count($attrFilters) === 1
                && $this->config->singleFilterIndexable($storeId)
                && $this->isIndexableAttribute((string) array_key_first($attrFilters), $storeId);

            if ($indexableSingle) {
                $code = (string) array_key_first($attrFilters);
                $slug = $this->toSlug($code, (string) $attrFilters[$code], $storeId);
                $canonical = $this->pathBuilder->appendFilters(
                    $baseUrl,
                    [$code => $slug],
                    $this->config->isPathFormat($storeId)
                );
                // Page 1 of a chosen filter = indexable landing page; page 2+ -> noindex,
                // canonical still points at page 1 of that filter (canonical carries no ?p).
                return [
                    'canonical' => $canonical,
                    'robots'    => $isPaged ? 'NOINDEX,FOLLOW' : 'INDEX,FOLLOW',
                ];
            }

            if ($total === 0) {
                // Unfiltered page 2+: noindex it, leave the canonical to the store.
                return ['canonical' => '', 'robots' => 'NOINDEX,FOLLOW'];
            }

            // multi-filter / price / non-indexable: configured robots, forced noindex on page 2+.
            $robots = $isPaged ? 'NOINDEX,FOLLOW' : $this->config->multiFilterRobots($storeId);
            return [
                'canonical' => $baseUrl,
                'robots'    => $robots,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array{0: array<string,string>, 1: bool, 2: bool}
     */
    private function activeFilters(): array
    {
        $attrFilters = [];
        $hasPrice = false;
        $hasCat = false;

        foreach ($this->request->getParams() as $key => $value) {
            if (in_array($key, self::NON_FILTER_PARAMS, true) || is_array($value) || $value === '') {
                continue;
            }
            if ($key === 'price') {
                $hasPrice = true;
            } elseif ($key === 'cat') {
                $hasCat = true;
            } elseif ($this->resolver->isFilterableAttribute((string) $key)) {
                $attrFilters[(string) $key] = (string) $value;
            }
        }

        return [$attrFilters, $hasPrice, $hasCat];
    }

    private function isIndexableAttribute(string $code, int $storeId): bool
    {
        $allowed = $this->config->indexableAttributes($storeId);
        return $allowed === [] || in_array($code, $allowed, true);
    }

    /** The request value is an option id in path mode, already a slug in query mode. */
    private function toSlug(string $code, string $value, int $storeId): string
    {
        if (!ctype_digit($value)) {
            return $value;
        }
        $attributeId = $this->resolver->attributeIdByCode($code);
        $slug = $attributeId !== null
            ? $this->resolver->optionIdToAlias($attributeId, $storeId, (int) $value)
            : null;
        return $slug ?? $value;
    }
}
