<?php
declare(strict_types=1);

namespace ETechFlow\SeoLayeredNav\Plugin\Layer\Filter;

use ETechFlow\SeoLayeredNav\Model\AliasResolver;
use ETechFlow\SeoLayeredNav\Model\Config;
use Magento\Catalog\Model\Layer\Filter\ItemFactory;
use Magento\CatalogSearch\Model\Layer\Filter\Attribute as AttributeFilter;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * INBOUND: resolve readable slug(s) -> option id(s) before the filter runs, and
 * add multi-value (OR) filtering that stock Magento lacks.
 *
 * - Single value: translate slug -> id for the duration of apply(), restore after
 *   (so outbound links keep the readable slug), let core apply it.
 * - Multiple values (?manufacturer=yale,abus): core can't (convertAttributeValue
 *   casts to a single int). We OR-filter the collection ourselves and register one
 *   combined active-filter item.
 *
 * Reachable today via direct URL / sitemap. Click-to-ADD a second value in the UI
 * needs disjunctive faceting (the facet-driven / swatch renderers rebuild option
 * lists from the search facet, which collapses to the selected value) — that's a
 * separate, larger piece.
 */
class InboundAttributeApply
{
    public function __construct(
        private readonly Config $config,
        private readonly AliasResolver $resolver,
        private readonly StoreManagerInterface $storeManager,
        private readonly ItemFactory $itemFactory
    ) {
    }

    public function aroundApply(AttributeFilter $subject, callable $proceed, RequestInterface $request)
    {
        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
            if (!$this->config->isEnabled($storeId)) {
                return $proceed($request);
            }
            $attribute = $subject->getAttributeModel();
            if (!$attribute || !$attribute->getAttributeId()) {
                return $proceed($request);
            }

            $code = (string) $attribute->getAttributeCode();
            $raw = $request->getParam($code);
            if (!is_string($raw) || $raw === '') {
                return $proceed($request);
            }

            $ids = $this->toOptionIds((int) $attribute->getAttributeId(), $storeId, $raw);
            if ($ids === null) {
                return $proceed($request);
            }

            // Multi-select on: OR-filter + active item, but DON'T collapse — the
            // disjunctive-facet plugin keeps every option visible (click-to-add).
            if ($this->config->isMultiselect($storeId)) {
                return $this->applyMultiple($subject, $ids, false);
            }

            if (count($ids) > 1) {
                return $this->applyMultiple($subject, $ids, true);
            }

            $original = $request->getParams();
            $request->setParams(array_merge($original, [$code => $ids[0]]));
            try {
                return $proceed($request);
            } finally {
                $request->setParams($original);
            }
        } catch (\Throwable $e) {
            return $proceed($request);
        }
    }

    /**
     * OR-filter the collection by several option ids and add one combined state item.
     *
     * @param string[] $ids
     */
    private function applyMultiple(AttributeFilter $subject, array $ids, bool $collapse): AttributeFilter
    {
        $attribute = $subject->getAttributeModel();
        $intIds = array_map('intval', $ids);

        $subject->getLayer()->getProductCollection()
            ->addFieldToFilter($attribute->getAttributeCode(), $intIds);

        $labels = [];
        $source = $attribute->getSource();
        foreach ($intIds as $id) {
            $text = $source ? $source->getOptionText($id) : null;
            if ($text) {
                $labels[] = is_array($text) ? implode(' ', $text) : (string) $text;
            }
        }

        $subject->getLayer()->getState()->addFilter(
            $this->itemFactory->create()
                ->setFilter($subject)
                ->setLabel(implode(', ', $labels))
                ->setValue(implode(',', $ids))
                ->setCount(0)
        );

        if ($collapse) {
            $subject->setItems([]);
        }
        // else: leave items unset so _getItemsData rebuilds from the disjunctive facet

        return $subject;
    }

    /**
     * @return string[]|null
     */
    private function toOptionIds(int $attributeId, int $storeId, string $raw): ?array
    {
        $parts = array_filter(array_map('trim', explode(',', $raw)), static fn($p) => $p !== '');
        if (!$parts) {
            return null;
        }
        $ids = [];
        $changed = false;
        foreach ($parts as $part) {
            $id = $this->resolver->aliasToOptionId($attributeId, $storeId, $part);
            if ($id !== null) {
                $ids[] = (string) $id;
                $changed = true;
            } elseif (ctype_digit($part)) {
                $ids[] = $part;
            } else {
                return null;
            }
        }
        if (!$changed && count($ids) === 1) {
            return null;
        }
        return $ids;
    }
}
