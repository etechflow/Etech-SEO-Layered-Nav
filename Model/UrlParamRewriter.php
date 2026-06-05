<?php
declare(strict_types=1);

namespace ETechFlow\SeoLayeredNav\Model;

/**
 * Replaces a single query parameter's value in a URL while preserving scheme,
 * host, path, every other query param, and the fragment. Shared by the
 * dropdown (Filter\Item) and swatch (RenderLayered) outbound plugins.
 */
class UrlParamRewriter
{
    /** Remove a query parameter entirely, preserving the rest of the URL. */
    public function removeParam(string $url, string $key): string
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['query'])) {
            return $url;
        }
        parse_str($parts['query'], $query);
        if (!array_key_exists($key, $query)) {
            return $url;
        }
        unset($query[$key]);

        $rebuilt = '';
        if (isset($parts['scheme'], $parts['host'])) {
            $rebuilt .= $parts['scheme'] . '://' . $parts['host'];
            if (isset($parts['port'])) {
                $rebuilt .= ':' . $parts['port'];
            }
        }
        $rebuilt .= $parts['path'] ?? '';
        if ($query) {
            $rebuilt .= '?' . http_build_query($query);
        }
        if (isset($parts['fragment'])) {
            $rebuilt .= '#' . $parts['fragment'];
        }
        return $rebuilt;
    }

    public function replaceParam(string $url, string $key, string $newValue): string
    {
        $parts = parse_url($url);
        // Bail on anything that isn't a normal URL with a query (e.g. "javascript:void();").
        if ($parts === false || empty($parts['query'])) {
            return $url;
        }
        parse_str($parts['query'], $query);
        if (!array_key_exists($key, $query) || is_array($query[$key])) {
            return $url; // not our param, or multi-value (out of Phase-1 scope)
        }
        $query[$key] = $newValue;

        $rebuilt = '';
        if (isset($parts['scheme'], $parts['host'])) {
            $rebuilt .= $parts['scheme'] . '://' . $parts['host'];
            if (isset($parts['port'])) {
                $rebuilt .= ':' . $parts['port'];
            }
        }
        $rebuilt .= $parts['path'] ?? '';
        $rebuilt .= '?' . http_build_query($query);
        if (isset($parts['fragment'])) {
            $rebuilt .= '#' . $parts['fragment'];
        }
        return $rebuilt;
    }
}
