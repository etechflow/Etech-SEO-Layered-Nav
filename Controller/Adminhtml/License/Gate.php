<?php

declare(strict_types=1);

namespace ETechFlow\SeoLayeredNav\Controller\Adminhtml\License;

use ETechFlow\SeoLayeredNav\Model\LicenseValidator;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * License-required gate page. Shows plan cards + "Enter License Key".
 * Redirects to the SEO Layered Navigation config section when already valid.
 *
 * Shares the module's own admin ACL (ETechFlow_SeoLayeredNav::seonav) and the
 * existing etechflow_seonav admin route, so the admin gate observer can redirect
 * the "SEO Filter URLs" page here without an ACL mismatch.
 */
class Gate extends Action
{
    public const ADMIN_RESOURCE = 'ETechFlow_SeoLayeredNav::seonav';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly LicenseValidator $licenseValidator
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        if ($this->licenseValidator->isValid()) {
            $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
            return $redirect->setPath('adminhtml/system_config/edit/section/etechflow_seonav');
        }

        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->prepend(__('SEO Layered Navigation — License Required'));
        return $page;
    }
}
