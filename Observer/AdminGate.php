<?php

declare(strict_types=1);

namespace ETechFlow\SeoLayeredNav\Observer;

use ETechFlow\SeoLayeredNav\Model\LicenseValidator;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\ActionFlag;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Admin gate for the module's own backend route (etechflow_seonav).
 *
 * Registered ONLY on controller_action_predispatch_etechflow_seonav, so its
 * blast radius is exactly this module's admin controllers — the "SEO Filter
 * URLs" management page (alias/index) and the "Rebuild SEO URLs" action
 * (alias/rebuild). It is the admin-side equivalent of the CLI generate-aliases
 * licence gate: an unlicensed merchant can neither view the page nor rebuild
 * the alias map.
 *
 * The licence screens themselves (controller "license": gate/checkout/activated)
 * are never gated — gating them would cause an infinite redirect loop.
 *
 * No other admin route fires this event, so no other admin page is affected.
 */
class AdminGate implements ObserverInterface
{
    public function __construct(
        private readonly LicenseValidator $licenseValidator,
        private readonly UrlInterface $backendUrl,
        private readonly ActionFlag $actionFlag
    ) {
    }

    public function execute(Observer $observer): void
    {
        $request = $observer->getEvent()->getRequest();
        if ($request === null) {
            return;
        }

        // Never gate the licence controllers (gate/checkout/activated) — would loop.
        if (strtolower((string) $request->getControllerName()) === 'license') {
            return;
        }

        if ($this->licenseValidator->isValid()) {
            return;
        }

        // Short-circuit the dispatch and send the admin to the licence gate.
        $this->actionFlag->set('', Action::FLAG_NO_DISPATCH, true);

        $action = $observer->getEvent()->getControllerAction();
        if ($action !== null && method_exists($action, 'getResponse')) {
            $action->getResponse()->setRedirect(
                $this->backendUrl->getUrl('etechflow_seonav/license/gate')
            );
        }
    }
}
