<?php
declare(strict_types=1);

namespace ETechFlow\SeoLayeredNav\Block;

use ETechFlow\SeoLayeredNav\Model\MetaPolicy;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

/**
 * Head block for filtered category pages. Renders our own <link rel="canonical">
 * (page-config canonical assets are not reliably rendered by every theme, so we
 * emit the tag directly) and sets the robots directive via pageConfig in
 * _prepareLayout. Outputs nothing on non-filter pages.
 *
 * Theme-agnostic: works on Luma and Hyva. On a store with another SEO module
 * that also emits canonical, disable that module's canonical to avoid duplicates.
 */
class FilterMeta extends Template
{
    private ?string $canonical = null;

    public function __construct(
        Context $context,
        private readonly MetaPolicy $policy,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _prepareLayout()
    {
        $meta = $this->policy->resolve();
        if ($meta !== null) {
            $this->canonical = $meta['canonical'];
            $this->pageConfig->setRobots($meta['robots']);
        }
        return parent::_prepareLayout();
    }

    public function getCanonicalUrl(): ?string
    {
        return $this->canonical;
    }
}
