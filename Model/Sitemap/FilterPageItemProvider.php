<?php
declare(strict_types=1);

namespace ETechFlow\SeoLayeredNav\Model\Sitemap;

use ETechFlow\SeoLayeredNav\Model\AliasResolver;
use ETechFlow\SeoLayeredNav\Model\Config;
use ETechFlow\SeoLayeredNav\Model\PathUrlBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Sitemap\Model\ItemProvider\ConfigReaderInterface;
use Magento\Sitemap\Model\ItemProvider\ItemProviderInterface;
use Magento\Sitemap\Model\SitemapItemInterfaceFactory;
use Psr\Log\LoggerInterface;

/**
 * Feeds indexable single-filter landing pages (e.g. /cylinder-locks?manufacturer=yale)
 * into the XML sitemap, so Google can DISCOVER them — the other half of making them
 * self-canonical/indexable (see Block\FilterMeta).
 *
 * Safe by construction:
 *  - gated by its own flag (seo/sitemap_filter_pages), default off;
 *  - only the explicitly whitelisted attributes (seo/indexable_attributes) — never a
 *    cartesian explosion; blank whitelist emits nothing (and logs why);
 *  - only (category, option) pairs that have at least one ENABLED product, so no
 *    empty pages land in the sitemap;
 *  - hard-capped per store, with a log line if the cap truncates (no silent cap).
 *
 * Runs at sitemap generation (cron/manual), never on a page request.
 */
class FilterPageItemProvider implements ItemProviderInterface
{
    private const MAX_ITEMS_PER_STORE = 20000;

    public function __construct(
        private readonly Config $config,
        private readonly AliasResolver $resolver,
        private readonly ResourceConnection $resource,
        private readonly SitemapItemInterfaceFactory $itemFactory,
        private readonly ConfigReaderInterface $configReader,
        private readonly PathUrlBuilder $pathBuilder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param int $storeId
     * @return \Magento\Sitemap\Model\SitemapItemInterface[]
     */
    public function getItems($storeId)
    {
        $storeId = (int) $storeId;
        if (!$this->config->managesMeta($storeId)
            || !$this->config->singleFilterIndexable($storeId)
            || !$this->config->sitemapFilterPages($storeId)
        ) {
            return [];
        }

        $whitelist = $this->config->indexableAttributes($storeId);
        if ($whitelist === []) {
            $this->logger->notice(
                'ETechFlow_SeoLayeredNav: sitemap filter pages enabled but no attributes whitelisted '
                . '(seo/indexable_attributes is blank) — emitting none.'
            );
            return [];
        }

        $priority = $this->configReader->getPriority($storeId);
        $changeFrequency = $this->configReader->getChangeFrequency($storeId);
        $categoryPaths = $this->categoryPaths($storeId);
        $asPath = $this->config->isPathFormat($storeId);

        $items = [];
        $truncated = false;

        foreach ($whitelist as $code) {
            $attributeId = $this->resolver->attributeIdByCode($code);
            if ($attributeId === null || !$this->resolver->isFilterableAttribute($code)) {
                continue;
            }

            foreach ($this->categoryOptionPairs($attributeId) as $pair) {
                $categoryId = (int) $pair['category_id'];
                $optionId = (int) $pair['option_id'];
                if (!isset($categoryPaths[$categoryId])) {
                    continue;
                }
                $slug = $this->resolver->optionIdToAlias($attributeId, $storeId, $optionId);
                if ($slug === null) {
                    continue;
                }

                if (count($items) >= self::MAX_ITEMS_PER_STORE) {
                    $truncated = true;
                    break 2;
                }

                $url = $this->pathBuilder->appendFilters($categoryPaths[$categoryId], [$code => $slug], $asPath);
                $items[] = $this->itemFactory->create([
                    'url' => $url,
                    'priority' => $priority,
                    'changeFrequency' => $changeFrequency,
                ]);
            }
        }

        if ($truncated) {
            $this->logger->warning(sprintf(
                'ETechFlow_SeoLayeredNav: filter-page sitemap hit the %d-item cap for store %d; '
                . 'some landing pages were omitted.',
                self::MAX_ITEMS_PER_STORE,
                $storeId
            ));
        }

        return $items;
    }

    /** @return array<int,string> category_id => relative request path (active categories with a rewrite). */
    private function categoryPaths(int $storeId): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['ur' => $this->resource->getTableName('url_rewrite')], ['entity_id', 'request_path'])
            ->where('ur.entity_type = ?', 'category')
            ->where('ur.store_id = ?', $storeId)
            ->where('ur.redirect_type = ?', 0)
            ->where('ur.metadata IS NULL');

        $paths = [];
        foreach ($connection->fetchAll($select) as $row) {
            // first rewrite per category wins (canonical request path)
            $paths[(int) $row['entity_id']] ??= (string) $row['request_path'];
        }
        return $paths;
    }

    /**
     * Distinct (category, option) pairs with >=1 enabled product. Select attributes only
     * (value in catalog_product_entity_int). Offline query — heavy joins are acceptable.
     *
     * @return array<int,array{category_id:string, option_id:string}>
     */
    private function categoryOptionPairs(int $attributeId): array
    {
        $statusId = $this->resolver->attributeIdByCode('status');
        $connection = $this->resource->getConnection();
        $ccp = $this->resource->getTableName('catalog_category_product');
        $intTable = $this->resource->getTableName('catalog_product_entity_int');

        $select = $connection->select()
            ->distinct()
            ->from(['ccp' => $ccp], ['category_id'])
            ->join(
                ['ev' => $intTable],
                'ev.entity_id = ccp.product_id AND ev.attribute_id = ' . (int) $attributeId . ' AND ev.store_id = 0',
                ['option_id' => 'ev.value']
            )
            ->where('ev.value IS NOT NULL')
            ->where('ev.value > 0');

        if ($statusId !== null) {
            $select->join(
                ['st' => $intTable],
                'st.entity_id = ccp.product_id AND st.attribute_id = ' . (int) $statusId
                . ' AND st.store_id = 0 AND st.value = 1',
                []
            );
        }

        return $connection->fetchAll($select);
    }
}
