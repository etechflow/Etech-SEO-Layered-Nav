<?php
declare(strict_types=1);

namespace ETechFlow\SeoLayeredNav\Controller\Adminhtml\Alias;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    public const ADMIN_RESOURCE = 'ETechFlow_SeoLayeredNav::seonav';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('ETechFlow_SeoLayeredNav::seonav');
        $resultPage->getConfig()->getTitle()->prepend(__('SEO Filter URLs'));
        return $resultPage;
    }
}
