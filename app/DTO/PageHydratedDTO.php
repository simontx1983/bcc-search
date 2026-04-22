<?php

namespace BCC\Search\DTO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Immutable fully-hydrated page row from SearchRepository::hydratePages().
 *
 * Nullability reflects actual schema:
 *   - id, title, slug: NOT NULL in wp_posts (required non-null)
 *   - categoryName / categorySlug: LEFT JOIN on peepso_page_categories —
 *     null when a page has no category linked
 *   - avatarHash: LEFT JOIN on postmeta — null when no avatar set
 */
final class PageHydratedDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $slug,
        public readonly ?string $categoryName,
        public readonly ?string $categorySlug,
        public readonly ?string $avatarHash,
    ) {
    }
}
