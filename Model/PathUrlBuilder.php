<?php
declare(strict_types=1);

namespace ETechFlow\SeoLayeredNav\Model;

/**
 * Builds path-format filter URLs (/category/attr/slug/attr2/slug2), and converts
 * an already-built query URL into path form. Shared by the outbound link plugins,
 * the canonical block, and the sitemap provider so every emitted URL matches.
 *
 * Filter segments are sorted by attribute code so the same filter set always
 * yields the same URL (stable canonicals, no duplicate-content drift).
 */
class PathUrlBuilder
{
    /** Query params that are never filters and must stay in the query string. */
    private const NON_FILTER_PARAMS = [
        'p', 'product_list_limit', 'product_list_order', 'product_list_dir',
        'product_list_mode', 'mode', 'q', '___store', '___from_store',
    ];

    public function __construct(
        private readonly AliasResolver $resolver
    ) {
    }

    /**
     * Convert a query-style filter URL (…/cat?manufacturer=2074&finish=123&p=2)
     * into path form (…/cat/finish/brass/manufacturer/yale?p=2). Filter values are
     * option ids here (the request carries ids in path mode); unresolved params are
     * left in the query string untouched.
     */
    public function toPath(string $url, int $storeId): string
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['path'])) {
            return $url;
        }
        $query = [];
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        $filters = [];   // code => slug
        $passthrough = []; // non-filter params kept as query
        foreach ($query as $key => $value) {
            $key = (string) $key;
            if (is_array($value) || $value === '' || in_array($key, self::NON_FILTER_PARAMS, true)) {
                $passthrough[$key] = $value;
                continue;
            }
            $slug = $this->slugFor($key, (string) $value, $storeId);
            if ($slug !== null) {
                $filters[$key] = $slug;
            } else {
                $passthrough[$key] = $value;
            }
        }

        if (!$filters) {
            return $url; // nothing to pathify
        }

        $base = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
        if (isset($parts['port'])) {
            $base .= ':' . $parts['port'];
        }

        $path = $this->appendFilterPath(rtrim($parts['path'], '/'), $filters);
        $out = $base . $path;
        if ($passthrough) {
            $out .= '?' . http_build_query($passthrough);
        }
        if (isset($parts['fragment'])) {
            $out .= '#' . $parts['fragment'];
        }
        return $out;
    }

    /**
     * Append filters to a base URL/path in the configured shape.
     *
     * @param array<string,string> $codeToSlug
     */
    public function appendFilters(string $base, array $codeToSlug, bool $asPath): string
    {
        if (!$codeToSlug) {
            return $base;
        }
        if ($asPath) {
            $frag = parse_url($base, PHP_URL_QUERY); // base shouldn't carry a query here, but be safe
            return $this->appendFilterPath(rtrim($base, '/'), $codeToSlug) . ($frag ? '?' . $frag : '');
        }
        $sep = str_contains($base, '?') ? '&' : '?';
        return $base . $sep . http_build_query($codeToSlug);
    }

    /** @param array<string,string> $codeToSlug */
    private function appendFilterPath(string $path, array $codeToSlug): string
    {
        ksort($codeToSlug); // deterministic order
        foreach ($codeToSlug as $code => $slug) {
            $path .= '/' . rawurlencode($code) . '/' . rawurlencode($slug);
        }
        return $path;
    }

    /** Resolve a request value (option id) to its slug for the given attribute code. */
    private function slugFor(string $code, string $value, int $storeId): ?string
    {
        if (!$this->resolver->isFilterableAttribute($code)) {
            return null;
        }
        $attributeId = $this->resolver->attributeIdByCode($code);
        if ($attributeId === null || !ctype_digit($value)) {
            return null;
        }
        return $this->resolver->optionIdToAlias($attributeId, $storeId, (int) $value);
    }
}
