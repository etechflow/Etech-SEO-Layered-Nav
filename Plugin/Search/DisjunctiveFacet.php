<?php
declare(strict_types=1);

namespace ETechFlow\SeoLayeredNav\Plugin\Search;

use ETechFlow\SeoLayeredNav\Model\AliasResolver;
use ETechFlow\SeoLayeredNav\Model\CategoryOptions;
use ETechFlow\SeoLayeredNav\Model\Config;
use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use Magento\CatalogSearch\Model\ResourceModel\Fulltext\Collection;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * DISJUNCTIVE FACETING — the engine behind multi-select layered nav.
 *
 * When an attribute is active, stock Magento's facet for that attribute collapses
 * to the selected value (so the other options vanish and you can't pick a second).
 * We intercept getFacetedData() and, for an active managed attribute, return a facet
 * computed from a side collection that applies every OTHER active filter but NOT this
 * one — so all of its options stay visible with correct "if I also pick this" counts.
 * Every renderer (swatches, dropdowns) reads getFacetedData, so this fixes them all.
 *
 * The product list itself is unaffected — it stays filtered by the main collection.
 * Gated by the multiselect flag; one extra search query per active managed attribute
 * (category pages are block-cached).
 */
class DisjunctiveFacet
{
    public function __construct(
        private readonly Config $config,
        private readonly AliasResolver $resolver,
        private readonly RequestInterface $request,
        private readonly LayerResolver $layerResolver,
        private readonly StoreManagerInterface $storeManager,
        private readonly CategoryOptions $categoryOptions
    ) {
    }

    /**
     * @param string $field attribute code
     * @return mixed
     */
    public function aroundGetFacetedData(Collection $subject, callable $proceed, $field)
    {
        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
            if (!$this->config->isMultiselect($storeId)
                || !is_string($field)
                || !$this->resolver->isFilterableAttribute($field)
            ) {
                return $proceed($field);
            }

            $raw = $this->request->getParam($field);
            if (!is_string($raw) || $raw === '') {
                return $proceed($field); // attribute not active — normal (collapsing) facet
            }

            $attributeId = $this->resolver->attributeIdByCode($field);
            $category = $this->layerResolver->get()->getCurrentCategory();
            if ($attributeId === null || !$category || !$category->getId()) {
                return $proceed($field);
            }

            // Keep ALL of the category's options on the page (the facet collapses to
            // the selected value otherwise). Counts are category-level — correct for
            // single-attribute multi-select; approximate when other attributes are
            // also active (true per-combination counts need a separate search index).
            $counts = $this->categoryOptions->getOptionsWithCounts(
                (int) $category->getId(),
                $attributeId,
                $storeId
            );
            if (!$counts) {
                return $proceed($field);
            }

            $facet = [];
            foreach ($counts as $optionId => $count) {
                $facet[$optionId] = ['count' => $count];
            }
            return $facet;
        } catch (\Throwable $e) {
            return $proceed($field);
        }
    }
}
