<?php
declare(strict_types=1);

namespace ETechFlow\SeoLayeredNav\Model;

use Magento\Framework\App\RequestInterface;

/**
 * Builds a filter option's link as a TOGGLE against the currently-active values:
 * if the option is already selected the link removes it, otherwise it adds it —
 * giving click-to-add multi-select. Query mode only (path multi-value is a
 * separate refinement). Values in the URL are slugs.
 */
class FilterToggle
{
    public function __construct(
        private readonly AliasResolver $resolver,
        private readonly UrlParamRewriter $rewriter,
        private readonly RequestInterface $request
    ) {
    }

    /**
     * @param string $url        the option link as built by core (…?code=thisOptionId…)
     * @param string $requestVar attribute code (= query param key)
     */
    public function toggleQueryUrl(string $url, string $requestVar, int $attributeId, int $thisOptionId, int $storeId): string
    {
        $thisSlug = $this->resolver->optionIdToAlias($attributeId, $storeId, $thisOptionId);
        if ($thisSlug === null) {
            return $url;
        }

        $active = $this->activeSlugs($requestVar, $attributeId, $storeId);

        if (in_array($thisSlug, $active, true)) {
            $next = array_values(array_diff($active, [$thisSlug])); // deselect
        } else {
            $next = array_merge($active, [$thisSlug]); // add
        }
        $next = array_values(array_unique($next));
        sort($next); // stable order

        if (!$next) {
            return $this->rewriter->removeParam($url, $requestVar);
        }
        return $this->rewriter->replaceParam($url, $requestVar, implode(',', $next));
    }

    /** Currently-selected slugs for this attribute, from the request (ids normalised to slugs). */
    private function activeSlugs(string $requestVar, int $attributeId, int $storeId): array
    {
        $raw = $this->request->getParam($requestVar);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $out = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (ctype_digit($part)) {
                $slug = $this->resolver->optionIdToAlias($attributeId, $storeId, (int) $part);
                if ($slug !== null) {
                    $out[] = $slug;
                }
            } else {
                $out[] = $part;
            }
        }
        return array_values(array_unique($out));
    }
}
