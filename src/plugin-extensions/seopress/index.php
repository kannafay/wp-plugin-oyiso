<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

final class Oyiso_SEOPress
{
    public const OPTION_REMOVE_ARCHIVE_LINKS = 'oyiso_seopress_remove_archive_links';

    public static function filterSitemapArchiveLink(string $sitemapUrl, string $postType): string
    {
        if (!in_array($postType, ['post', 'product'], true)) {
            return $sitemapUrl;
        }

        $options = get_option('oyiso', []);
        if (!is_array($options) || empty($options[self::OPTION_REMOVE_ARCHIVE_LINKS])) {
            return $sitemapUrl;
        }

        // This hook only handles archive entries; page URLs and detail entries stay intact.
        return '';
    }
}

add_filter('seopress_sitemaps_no_archive_link', [Oyiso_SEOPress::class, 'filterSitemapArchiveLink'], 10, 2);
