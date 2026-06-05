<?php
declare(strict_types=1);

namespace ETechFlow\SeoLayeredNav\Model;

/**
 * Turns an option label into a URL-safe slug.
 * "Premier 2000+ " -> "premier-2000"   "ABG Locks" -> "abg-locks"
 *
 * Deliberately dependency-free and deterministic so the same label always
 * yields the same slug across stores and re-runs.
 */
class SlugGenerator
{
    public function slugify(string $label): string
    {
        $slug = trim($label);

        // Transliterate accented characters to ASCII where the platform supports it.
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug);
            if ($converted !== false) {
                $slug = $converted;
            }
        }

        $slug = strtolower($slug);
        // Anything that is not a-z 0-9 becomes a separator.
        $slug = preg_replace('~[^a-z0-9]+~', '-', $slug) ?? '';
        // Collapse and trim separators.
        $slug = trim((string) preg_replace('~-+~', '-', $slug), '-');

        return $slug;
    }
}
