<?php
declare(strict_types=1);

namespace ETechFlow\SeoLayeredNav\Plugin\Layer\Filter;

use ETechFlow\SeoLayeredNav\Model\AliasResolver;
use ETechFlow\SeoLayeredNav\Model\Config;
use ETechFlow\SeoLayeredNav\Model\FilterToggle;
use ETechFlow\SeoLayeredNav\Model\PathUrlBuilder;
use ETechFlow\SeoLayeredNav\Model\UrlParamRewriter;
use Magento\Catalog\Model\Layer\Filter\Item;
use Magento\Store\Model\StoreManagerInterface;

/**
 * OUTBOUND: rewrite a filter link to readable form.
 *
 * Query mode: Item::getUrl() builds "...?<code>=<optionId>"; swap that one value
 * for its alias, leaving other (already-slug) params intact.
 * Path mode: convert the whole built URL to /category/code/slug form.
 * Also covers getRemoveUrl() so the "remove filter" links match the format.
 * No-op unless the feature is enabled.
 */
class OutboundItemUrl
{
    public function __construct(
        private readonly Config $config,
        private readonly AliasResolver $resolver,
        private readonly StoreManagerInterface $storeManager,
        private readonly UrlParamRewriter $rewriter,
        private readonly PathUrlBuilder $pathBuilder,
        private readonly FilterToggle $toggle
    ) {
    }

    public function afterGetUrl(Item $subject, $result)
    {
        return $this->rewrite($subject, $result);
    }

    public function afterGetRemoveUrl(Item $subject, $result)
    {
        // Remove-filter links: in path mode the remaining filters must also be
        // path-form. In query mode the param is dropped entirely, so nothing to do.
        if (!is_string($result) || $result === '') {
            return $result;
        }
        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
            if ($this->config->isEnabled($storeId) && $this->config->isPathFormat($storeId)) {
                return $this->pathBuilder->toPath($result, $storeId);
            }
        } catch (\Throwable $e) {
            // fall through
        }
        return $result;
    }

    private function rewrite(Item $subject, $result)
    {
        if (!is_string($result) || $result === '') {
            return $result;
        }

        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
            if (!$this->config->isEnabled($storeId)) {
                return $result;
            }

            // Path mode rebuilds the whole URL (all active filters -> path segments).
            if ($this->config->isPathFormat($storeId)) {
                return $this->pathBuilder->toPath($result, $storeId);
            }

            $filter = $subject->getFilter();
            if (!$filter || !is_callable([$filter, 'getAttributeModel'])) {
                return $result;
            }
            $attribute = $filter->getAttributeModel();
            if (!$attribute || !$attribute->getAttributeId()) {
                return $result;
            }

            $requestVar = (string) $filter->getRequestVar();
            $value = $subject->getValue();
            if ($requestVar === '' || $value === null || is_array($value)) {
                return $result;
            }

            // Toggle this option against the active selection (click-to-add multi-select).
            return $this->toggle->toggleQueryUrl(
                $result,
                $requestVar,
                (int) $attribute->getAttributeId(),
                (int) $value,
                $storeId
            );
        } catch (\Throwable $e) {
            // Never break a storefront link because of alias rewriting.
            return $result;
        }
    }
}
