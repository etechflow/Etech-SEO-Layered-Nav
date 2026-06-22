<?php
declare(strict_types=1);

namespace ETechFlow\SeoLayeredNav\Controller\Adminhtml\Alias;

use ETechFlow\SeoLayeredNav\Model\AliasRebuilder;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;

class Rebuild extends Action
{
    public const ADMIN_RESOURCE = 'ETechFlow_SeoLayeredNav::seonav';

    public function __construct(
        Context $context,
        private readonly AliasRebuilder $rebuilder
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $storeId = (int) $this->getRequest()->getParam('store_id', 0);

        try {
            $summary = $this->rebuilder->rebuild($storeId);
            if (!$summary['attributes']) {
                $this->messageManager->addWarningMessage(
                    __('No filterable select/multiselect attributes found — nothing to rebuild.')
                );
            } else {
                $this->messageManager->addSuccessMessage(
                    __('Rebuilt %1 SEO URL alias(es) across %2 attribute(s) for store %3.',
                        $summary['total'],
                        count($summary['attributes']),
                        $storeId)
                );
            }
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Rebuild failed: %1', $e->getMessage()));
        }

        return $resultRedirect->setPath('*/*/index');
    }
}
