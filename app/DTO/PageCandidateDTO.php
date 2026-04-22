<?php

namespace BCC\Search\DTO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Immutable candidate row from SearchRepository::searchCandidates().
 *
 * Represents the minimal lookup shape used for candidate ranking before
 * hydration: just the post ID and title. Both fields are NOT NULL in
 * wp_posts, so both are required and non-nullable here.
 */
final class PageCandidateDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
    ) {
    }
}
