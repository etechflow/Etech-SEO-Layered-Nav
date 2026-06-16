<?php

declare(strict_types=1);

namespace ETechFlow\SeoLayeredNav\Controller\Adminhtml\License;

use ETechFlow\SeoLayeredNav\Model\LicenseValidator;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\HTTP\Client\Curl;

/**
 * Starts checkout by delegating to the eTechFlow webstore licensing broker
 * (module.etechflow.com). The broker opens a Paddle transaction on the
 * webstore's OWN Paddle account and returns the hosted pay URL; the portal
 * still issues the SP-XXXX key once payment clears. No card keys live in
 * Magento. Same redirect flow as the prior Stripe checkout.
 */
class Checkout extends Action
{
    public const ADMIN_RESOURCE = 'ETechFlow_SeoLayeredNav::seonav';

    private const MODULE_ID = 'seo-layered-nav';
    private const BROKER_URL = 'https://module.etechflow.com/api/license/checkout';
    private const LICENSE_TOKEN = 'lcsk_8f3b9d2a7c14e605b9af2e7c1d8043f6';

    /** Allowed plan slugs (the portal validates again server-side). */
    private const PLAN_SLUGS = ['slnav_weekly', 'slnav_monthly', 'slnav_yearly'];

    public function __construct(
        Context $context,
        private readonly Curl $curl,
        private readonly LicenseValidator $licenseValidator
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $plan   = trim((string) $this->getRequest()->getPost('plan', ''));
        $name   = trim((string) $this->getRequest()->getPost('name', ''));
        $email  = trim((string) $this->getRequest()->getPost('email', ''));
        $domain = $this->licenseValidator->getCurrentHost();

        $gate = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('etechflow_seonav/license/gate');

        if (!$plan || !in_array($plan, self::PLAN_SLUGS, true)) {
            $this->messageManager->addErrorMessage(__('Invalid plan selected.'));
            return $gate;
        }
        if (!$name || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->messageManager->addErrorMessage(__('Please enter a valid name and email address.'));
            return $gate;
        }

        $payload = json_encode([
            'plan'             => $plan,
            'name'             => $name,
            'email'            => $email,
            'domain'           => $domain,
            'module'           => self::MODULE_ID,
            'magento_callback' => $this->getUrl('etechflow_seonav/license/activated'),
            'magento_cancel'   => $this->getUrl('etechflow_seonav/license/gate'),
        ]);

        try {
            $this->curl->setTimeout(20);
            $this->curl->addHeader('Content-Type', 'application/json');
            $this->curl->addHeader('Accept', 'application/json');
            $this->curl->addHeader('X-ETF-License-Token', self::LICENSE_TOKEN);
            $this->curl->post(self::BROKER_URL, $payload);
            $status = (int) $this->curl->getStatus();
            $body   = (string) $this->curl->getBody();
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Could not reach the licensing portal. Please try again.'));
            return $gate;
        }

        $data = json_decode($body, true);
        if ($status === 200 && !empty($data['url'])) {
            return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setUrl((string) $data['url']);
        }

        $err = is_array($data) && !empty($data['error']) ? $data['error'] : ('Portal returned status ' . $status);
        $this->messageManager->addErrorMessage(__('Checkout error: %1', $err));
        return $gate;
    }
}
