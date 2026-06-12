<?php
declare(strict_types=1);

namespace ETechFlow\SeoLayeredNav\Block\Adminhtml\Alias;

use ETechFlow\SeoLayeredNav\Model\AliasRebuilder;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form\FormKey;
use Magento\Store\Model\System\Store as SystemStore;

class Rebuild extends Template
{
    public function __construct(
        Context $context,
        private readonly AliasRebuilder $rebuilder,
        private readonly FormKey $formKey,
        private readonly SystemStore $systemStore,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getRebuildUrl(): string
    {
        return $this->getUrl('etechflow_seonav/alias/rebuild');
    }

    public function getFormKey(): string
    {
        return $this->formKey->getFormKey();
    }

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
