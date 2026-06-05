<?php
declare(strict_types=1);

namespace ETechFlow\SeoLayeredNav\Plugin\Layer\Filter;

use ETechFlow\SeoLayeredNav\Model\AliasResolver;
use ETechFlow\SeoLayeredNav\Model\Config;
use ETechFlow\SeoLayeredNav\Model\FilterToggle;
use ETechFlow\SeoLayeredNav\Model\PathUrlBuilder;
use ETechFlow\SeoLayeredNav\Model\UrlParamRewriter;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Swatches\Block\LayeredNavigation\RenderLayered;

/**
 * OUTBOUND (swatches): swatch filter links are built by
 * RenderLayered::buildUrl($attributeCode, $optionId) and never pass through
 * Filter\Item::getUrl(), so the dropdown plugin can't see them. We hook
 * buildUrl directly — it hands us exactly the code + option id we need.
 * No-op unless enabled and an alias exists.
 */
class OutboundSwatchUrl
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

    /**
     * @param string $result   the built URL (or "javascript:void();" for the active option)
     * @param string $attributeCode
     * @param int|string $optionId
     */
    public function afterBuildUrl(RenderLayered $subject, $result, $attributeCode, $optionId = null)
    {
        if (!is_string($result) || $result === '') {
            return $result;
        }

        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
            if (!$this->config->isEnabled($storeId)) {
                return $result;
            }

            // Path mode: convert the whole built URL to /category/code/slug form.
            if ($this->config->isPathFormat($storeId)) {
                return $this->pathBuilder->toPath($result, $storeId);
            }

            $code = (string) $attributeCode;
            if ($code === '' || $optionId === null || $optionId === '') {
                return $result;
            }

            $attributeId = $this->resolver->attributeIdByCode($code);
            if ($attributeId === null) {
                return $result;
            }

            // Toggle this swatch option against the active selection (click-to-add).
            return $this->toggle->toggleQueryUrl($result, $code, $attributeId, (int) $optionId, $storeId);
        } catch (\Throwable $e) {
            return $result;
        }
    }
}
