<?php
declare(strict_types=1);

namespace ETechFlow\SeoLayeredNav\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * Two-way slug <-> option-id resolver for layered-nav filter values.
 *
 * Three cache layers, cheapest first:
 *   1. in-memory (per request) — dedupes repeat lookups within one render;
 *   2. Magento cache (cross-request) — a serialized map per (attribute, store),
 *      so a cold render does NOT hit the DB once per attribute;
 *   3. DB — only on a full cache miss.
 *
 * The Magento layer is tagged and flushed by the generate-aliases command, so
 * regeneration is immediately visible; cache:flush clears it too.
 */
class AliasResolver
{
    private const TABLE = 'etechflow_seo_filter_alias';
    public const CACHE_TAG = 'ETECHFLOW_SEONAV_ALIAS';
    private const CACHE_PREFIX = 'etechflow_seonav_alias_';
    private const CACHE_LIFETIME = 86400; // 24h backstop; explicit invalidation on regenerate

    /** @var array<string, array{byAlias: array<string,int>, byOption: array<int,string>}> */
    private array $memo = [];

    /** @var array<string,int|null> attribute_code => attribute_id (cached per request) */
    private array $codeToId = [];

    /** @var array<string,bool>|null set of filterable attribute codes (cached per request) */
    private ?array $filterableCodes = null;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly CacheInterface $cache,
        private readonly SerializerInterface $serializer
    ) {
    }

    /** Resolve a catalog_product attribute code to its id (cached per request). */
    public function attributeIdByCode(string $code): ?int
    {
        if (array_key_exists($code, $this->codeToId)) {
            return $this->codeToId[$code];
        }
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['a' => $this->resource->getTableName('eav_attribute')], ['attribute_id'])
            ->join(
                ['et' => $this->resource->getTableName('eav_entity_type')],
                'et.entity_type_id = a.entity_type_id',
                []
            )
            ->where('et.entity_type_code = ?', \Magento\Catalog\Model\Product::ENTITY)
            ->where('a.attribute_code = ?', $code)
            ->limit(1);
        $id = $connection->fetchOne($select);
        return $this->codeToId[$code] = $id !== false ? (int) $id : null;
    }

    /** True if $code is a filterable select/multiselect catalog_product attribute. */
    public function isFilterableAttribute(string $code): bool
    {
        if ($this->filterableCodes === null) {
            $connection = $this->resource->getConnection();
            $select = $connection->select()
                ->from(['a' => $this->resource->getTableName('eav_attribute')], ['attribute_code'])
                ->join(
                    ['c' => $this->resource->getTableName('catalog_eav_attribute')],
                    'c.attribute_id = a.attribute_id',
                    []
                )
                ->join(
                    ['et' => $this->resource->getTableName('eav_entity_type')],
                    'et.entity_type_id = a.entity_type_id',
                    []
                )
                ->where('et.entity_type_code = ?', \Magento\Catalog\Model\Product::ENTITY)
                ->where('c.is_filterable > 0')
                ->where('a.frontend_input IN (?)', ['select', 'multiselect']);
            $this->filterableCodes = [];
            foreach ($connection->fetchCol($select) as $c) {
                $this->filterableCodes[(string) $c] = true;
            }
        }
        return isset($this->filterableCodes[$code]);
    }

    /** slug -> option_id (null when the slug is unknown for this attribute/store). */
    public function aliasToOptionId(int $attributeId, int $storeId, string $alias): ?int
    {
        return $this->load($attributeId, $storeId)['byAlias'][$alias] ?? null;
    }

    /** option_id -> slug (null when the option has no alias for this attribute/store). */
    public function optionIdToAlias(int $attributeId, int $storeId, int $optionId): ?string
    {
        return $this->load($attributeId, $storeId)['byOption'][$optionId] ?? null;
    }

    /** Drop the cross-request cache (called after regeneration). */
    public function flush(): void
    {
        $this->memo = [];
        $this->cache->clean(\Zend_Cache::CLEANING_MODE_MATCHING_TAG, [self::CACHE_TAG]);
    }

    /**
     * @return array{byAlias: array<string,int>, byOption: array<int,string>}
     */
    private function load(int $attributeId, int $storeId): array
    {
        $key = $attributeId . ':' . $storeId;
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $cacheId = self::CACHE_PREFIX . $key;
        $cached = $this->cache->load($cacheId);
        if ($cached !== false && $cached !== null) {
            try {
                $map = $this->serializer->unserialize($cached);
                if (is_array($map) && isset($map['byAlias'], $map['byOption'])) {
                    return $this->memo[$key] = $map;
                }
            } catch (\Throwable $e) {
                // fall through to a fresh DB build on any corrupt cache entry
            }
        }

        $map = $this->buildFromDb($attributeId, $storeId);
        $this->cache->save(
            $this->serializer->serialize($map),
            $cacheId,
            [self::CACHE_TAG],
            self::CACHE_LIFETIME
        );
        return $this->memo[$key] = $map;
    }

    /**
     * @return array{byAlias: array<string,int>, byOption: array<int,string>}
     */
    private function buildFromDb(int $attributeId, int $storeId): array
    {
        $byAlias = [];
        $byOption = [];

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE), ['option_id', 'alias', 'store_id'])
            ->where('attribute_id = ?', $attributeId)
            ->where('store_id IN (?)', [0, $storeId])
            // store 0 first so a store-specific row overwrites it for byOption.
            ->order('store_id ASC');

        foreach ($connection->fetchAll($select) as $row) {
            $optionId = (int) $row['option_id'];
            $alias = (string) $row['alias'];
            $byAlias[$alias] = $optionId;   // every alias resolves inbound
            $byOption[$optionId] = $alias;  // store-specific wins for outbound
        }

        return ['byAlias' => $byAlias, 'byOption' => $byOption];
    }
}
