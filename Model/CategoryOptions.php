<?php
declare(strict_types=1);

namespace ETechFlow\SeoLayeredNav\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * Which option ids of a (select) attribute exist in a category, with product
 * counts — read from the category product index (so anchor-inherited products
 * count too). Used by the multi-select layered nav to keep ALL of a category's
 * options clickable after one is selected (click-to-add), instead of collapsing.
 *
 * NOTE (Tier A): counts are category-level — they do NOT shrink as other filters
 * are applied. Accurate per-combination counts would need disjunctive faceting.
 */
class CategoryOptions
{
    public const CACHE_TAG = 'ETECHFLOW_SEONAV_CATOPT';
    private const CACHE_ID = 'etechflow_seonav_catopt_';
    private const CACHE_LIFETIME = 86400;

    /** @var array<string, array<int,int>> */
    private array $memo = [];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly CacheInterface $cache,
        private readonly SerializerInterface $serializer
    ) {
    }

    /**
     * @return array<int,int> optionId => product count (only options present in the category)
     */
    public function getOptionsWithCounts(int $categoryId, int $attributeId, int $storeId): array
    {
        $key = $categoryId . ':' . $attributeId . ':' . $storeId;
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $cacheId = self::CACHE_ID . $key;
        $cached = $this->cache->load($cacheId);
        if ($cached !== false && $cached !== null) {
            try {
                $map = $this->serializer->unserialize($cached);
                if (is_array($map)) {
                    return $this->memo[$key] = $map;
                }
            } catch (\Throwable $e) {
                // rebuild
            }
        }

        $map = $this->buildFromDb($categoryId, $attributeId, $storeId);
        $this->cache->save($this->serializer->serialize($map), $cacheId, [self::CACHE_TAG], self::CACHE_LIFETIME);
        return $this->memo[$key] = $map;
    }

    /** @return array<int,int> */
    private function buildFromDb(int $categoryId, int $attributeId, int $storeId): array
    {
        $connection = $this->resource->getConnection();
        $indexTable = $this->resource->getTableName('catalog_category_product_index_store' . $storeId);
        if (!$connection->isTableExists($indexTable)) {
            $indexTable = $this->resource->getTableName('catalog_category_product_index');
        }
        $intTable = $this->resource->getTableName('catalog_product_entity_int');

        $select = $connection->select()
            ->from(['cpi' => $indexTable], [])
            ->join(
                ['ev' => $intTable],
                'ev.entity_id = cpi.product_id AND ev.attribute_id = ' . (int) $attributeId . ' AND ev.store_id = 0',
                ['option_id' => 'ev.value', 'cnt' => new \Zend_Db_Expr('COUNT(DISTINCT cpi.product_id)')]
            )
            ->where('cpi.category_id = ?', $categoryId)
            ->where('ev.value IS NOT NULL')
            ->where('ev.value > 0')
            ->group('ev.value');

        $map = [];
        foreach ($connection->fetchAll($select) as $row) {
            $map[(int) $row['option_id']] = (int) $row['cnt'];
        }
        return $map;
    }
}
