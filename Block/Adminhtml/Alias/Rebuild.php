<?php
declare(strict_types=1);

namespace ETechFlow\SeoLayeredNav\Block\Adminhtml\Alias;

use ETechFlow\SeoLayeredNav\Model\AliasRebuilder;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Store\Model\System\Store as SystemStore;

class Rebuild extends Template
{
    public function __construct(
        Context $context,
        private readonly AliasRebuilder $rebuilder,
        private readonly SystemStore $systemStore,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getRebuildUrl(): string
    {
        return $this->getUrl('etechflow_seonav/alias/rebuild');
    }

    // getFormKey() is inherited from Magento\Backend\Block\Template (returns the
    // form-key string); do NOT redeclare a $formKey property here — PHP 8.1 forbids
    // re-promoting the parent's non-readonly $formKey as readonly.

    public function getCurrentAliasCount(): int
    {
        return $this->rebuilder->currentAliasCount();
    }

    /** @return array<int,array{value:int,label:string}> store views for the optional scope picker */
    public function getStoreOptions(): array
    {
        $options = [['value' => 0, 'label' => (string) __('Default (admin labels)')]];
        foreach ($this->systemStore->getStoreCollection() as $store) {
            $options[] = ['value' => (int) $store->getId(), 'label' => (string) $store->getName()];
        }
        return $options;
    }
}
