<?php
declare(strict_types=1);

namespace ETechFlow\SeoLayeredNav\Controller;

use ETechFlow\SeoLayeredNav\Model\Config;
use ETechFlow\SeoLayeredNav\Model\PathResolver;
use Magento\Framework\App\Action\Forward;
use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\RouterInterface;
use Magento\Framework\Url as UrlAlias;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Front router for path-format layered-nav URLs (/category/attribute/value...).
 *
 * Runs after the url-rewrite router (so real category/product/CMS rewrites win)
 * and before the default router (which would 404). Resolves the path to a category
 * + filter params, then forwards to catalog/category/view — no url_rewrite rows are
 * created, so new options work instantly with nothing to regenerate.
 */
class Router implements RouterInterface
{
    public function __construct(
        private readonly ActionFactory $actionFactory,
        private readonly Config $config,
        private readonly PathResolver $pathResolver,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function match(RequestInterface $request): ?ActionInterface
    {
        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
            if (!$this->config->isEnabled($storeId) || !$this->config->isPathFormat($storeId)) {
                return null;
            }

            $identifier = rawurldecode(trim((string) $request->getPathInfo(), '/'));
            $parsed = $this->pathResolver->parse($identifier, $storeId);
            if ($parsed === null || empty($parsed['params'])) {
                return null; // not ours — let the normal routers handle it
            }

            // Point the request at catalog/category/view + the category id and filter
            // params, then Forward (same pattern as the CMS router). This router runs
            // AFTER the standard router (sortOrder 35), so on the forwarded second pass
            // the standard router dispatches catalog/category/view and we don't re-match.
            $request->setModuleName('catalog')
                ->setControllerName('category')
                ->setActionName('view')
                ->setParam('id', $parsed['category_id']);

            foreach ($parsed['params'] as $code => $optionId) {
                $request->setParam($code, $optionId);
            }

            $request->setAlias(UrlAlias::REWRITE_REQUEST_PATH_ALIAS, $identifier);

            return $this->actionFactory->create(Forward::class);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
