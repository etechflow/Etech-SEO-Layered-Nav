<?php
declare(strict_types=1);

namespace ETechFlow\SeoLayeredNav\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * Parses a path-format layered-nav URL back into a category + filter params.
 *
 *   cylinder-locks/manufacturer/yale            -> cat=17, manufacturer=2069
 *   brands/abus-locks/manufacturer/yale/finish/brass -> cat=..., manufacturer=.., finish=..
 *
 * Strategy: peel "<attribute>/<value>" pairs off the RIGHT (attribute segment must
 * be a known filterable code AND the value must resolve to an option), and treat the
 * remaining left segments as the category request path. Tries the greediest split
 * first, falling back to fewer filters — so a category whose own path happens to
 * contain a word that looks like an attribute still resolves correctly.
 */
class PathResolver
{
    public const CACHE_TAG = 'ETECHFLOW_SEONAV_CATPATH';
    private const CACHE_ID = 'etechflow_seonav_catpath_';
    private const CACHE_LIFETIME = 86400;

    /** @var array<int, array<string,int>> store => [request_path => category_id] */
    private array $memo = [];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly AliasResolver $resolver,
        private readonly CacheInterface $cache,
        private readonly SerializerInterface $serializer
    ) {
    }

    /**
     * @return array{category_id:int, request_path:string, params:array<string,string>}|null
     */
    public function parse(string $identifier, int $storeId): ?array
    {
        $identifier = trim($identifier, '/');
        if ($identifier === '') {
            return null;
        }
        $segments = explode('/', $identifier);
        $n = count($segments);
        if ($n < 3) {
            return null; // need at least category + one attr/value pair
        }

        $map = $this->categoryPathMap($storeId);
        $maxPairs = intdiv($n - 1, 2);

        for ($k = $maxPairs; $k >= 1; $k--) {
            $catSegs = array_slice($segments, 0, $n - 2 * $k);
            if (!$catSegs) {
                continue;
            }
            $catPath = implode('/', $catSegs);
            if (!isset($map[$catPath])) {
                continue;
            }

            $params = $this->resolvePairs(array_slice($segments, $n - 2 * $k), $storeId);
            if ($params !== null) {
                return [
                    'category_id'  => $map[$catPath],
                    'request_path' => $catPath,
                    'params'       => $params,
                ];
            }
        }

        return null;
    }

    /**
     * @param string[] $pairSegments flat [attr, value, attr, value, ...]
     * @return array<string,string>|null  [attributeCode => optionId] or null if any pair is invalid
     */
    private function resolvePairs(array $pairSegments, int $storeId): ?array
    {
        $params = [];
        $count = count($pairSegments);
        for ($i = 0; $i < $count; $i += 2) {
            $attr = $pairSegments[$i];
            $slug = $pairSegments[$i + 1] ?? '';
            if ($slug === '' || !$this->resolver->isFilterableAttribute($attr)) {
                return null;
            }
            $attributeId = $this->resolver->attributeIdByCode($attr);
            $optionId = $attributeId !== null
                ? $this->resolver->aliasToOptionId($attributeId, $storeId, $slug)
                : null;
            if ($optionId === null) {
                return null;
            }
            $params[$attr] = (string) $optionId;
        }
        return $params ?: null;
    }

    /** @return array<string,int> request_path => category_id (cached). */
    public function categoryPathMap(int $storeId): array
    {
        if (isset($this->memo[$storeId])) {
            return $this->memo[$storeId];
        }

        $cacheId = self::CACHE_ID . $storeId;
        $cached = $this->cache->load($cacheId);
        if ($cached !== false && $cached !== null) {
            try {
                $map = $this->serializer->unserialize($cached);
                if (is_array($map)) {
                    return $this->memo[$storeId] = $map;
                }
            } catch (\Throwable $e) {
                // rebuild
            }
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['ur' => $this->resource->getTableName('url_rewrite')], ['request_path', 'entity_id'])
            ->where('ur.entity_type = ?', 'category')
            ->where('ur.store_id = ?', $storeId)
            ->where('ur.redirect_type = ?', 0)
            ->where('ur.metadata IS NULL');

        $map = [];
        foreach ($connection->fetchAll($select) as $row) {
            $path = trim((string) $row['request_path'], '/');
            // first (canonical) rewrite per path wins
            $map[$path] ??= (int) $row['entity_id'];
        }

        $this->cache->save($this->serializer->serialize($map), $cacheId, [self::CACHE_TAG], self::CACHE_LIFETIME);
        return $this->memo[$storeId] = $map;
    }
}
