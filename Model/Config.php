<?php
declare(strict_types=1);

namespace ETechFlow\SeoLayeredNav\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Thin config reader. The single gate for the whole feature: while disabled,
 * every storefront plugin returns immediately, so the module is inert.
 */
class Config
{
    private const XML_ENABLED = 'etechflow_seonav/general/enabled';
    private const XML_URL_FORMAT = 'etechflow_seonav/general/url_format';

    public const URL_FORMAT_QUERY = 'query';
    public const URL_FORMAT_PATH = 'path';

    private const XML_MULTISELECT = 'etechflow_seonav/general/multiselect';
    private const XML_MANAGE_META = 'etechflow_seonav/seo/manage_meta';
    private const XML_SINGLE_INDEXABLE = 'etechflow_seonav/seo/single_filter_indexable';
    private const XML_INDEXABLE_ATTRS = 'etechflow_seonav/seo/indexable_attributes';
    private const XML_MULTI_ROBOTS = 'etechflow_seonav/seo/multi_filter_robots';
    private const XML_SITEMAP_FILTER_PAGES = 'etechflow_seonav/seo/sitemap_filter_pages';
    private const XML_NOINDEX_PAGINATION = 'etechflow_seonav/seo/noindex_pagination';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /** Phase 1: rewrite filter URLs to readable slugs. */
    public function isEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_ENABLED, $storeId);
    }

    /** Phase 3: 'query' (?manufacturer=yale) or 'path' (/category/manufacturer/yale). */
    public function getUrlFormat(?int $storeId = null): string
    {
        $v = (string) $this->scopeConfig->getValue(
            self::XML_URL_FORMAT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return $v === self::URL_FORMAT_PATH ? self::URL_FORMAT_PATH : self::URL_FORMAT_QUERY;
    }

    public function isPathFormat(?int $storeId = null): bool
    {
        return $this->getUrlFormat($storeId) === self::URL_FORMAT_PATH;
    }

    /** Multi-select layered nav (disjunctive facets + click-to-add). */
    public function isMultiselect(?int $storeId = null): bool
    {
        return $this->flag(self::XML_MULTISELECT, $storeId);
    }

    /** Phase 2 master switch: manage canonical/robots on filter pages. */
    public function managesMeta(?int $storeId = null): bool
    {
        return $this->flag(self::XML_MANAGE_META, $storeId);
    }

    public function singleFilterIndexable(?int $storeId = null): bool
    {
        return $this->flag(self::XML_SINGLE_INDEXABLE, $storeId);
    }

    /** @return array<int,string> attribute codes allowed to be indexable (empty = all). */
    public function indexableAttributes(?int $storeId = null): array
    {
        $raw = (string) $this->scopeConfig->getValue(
            self::XML_INDEXABLE_ATTRS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        if (trim($raw) === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', preg_split('~[,\s]+~', $raw) ?: [])));
    }

    /** Emit indexable single-filter landing pages into the XML sitemap. */
    public function sitemapFilterPages(?int $storeId = null): bool
    {
        return $this->flag(self::XML_SITEMAP_FILTER_PAGES, $storeId);
    }

    /** Add NOINDEX,FOLLOW to paginated listing pages (?p=2+). */
    public function noindexPagination(?int $storeId = null): bool
    {
        return $this->flag(self::XML_NOINDEX_PAGINATION, $storeId);
    }

    public function multiFilterRobots(?int $storeId = null): string
    {
        $v = (string) $this->scopeConfig->getValue(
            self::XML_MULTI_ROBOTS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return $v !== '' ? $v : 'NOINDEX,FOLLOW';
    }

    private function flag(string $path, ?int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
