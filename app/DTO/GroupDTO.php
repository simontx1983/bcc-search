<?php

namespace BCC\Search\DTO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-only DTO for a group-search row.
 *
 * Fields map 1:1 to the columns projected by GroupSearchRepository
 * (ID, post_title, post_name, avatar_hash, group_kind_raw). URL and
 * avatar URL are resolved downstream by GroupSearchService — not
 * stored here, same boundary as UserDTO.
 *
 * description is optional (null when not requested / not stored).
 * Kept short in the service layer: the UI only needs a single sub-
 * line, not the full body text.
 *
 * kindRaw is the raw `_bcc_group_kind` post-meta value ('hall' /
 * 'holders' / 'delegators' / 'system'; null when absent). The
 * raw→public mapping ('holders'→'nft', 'delegators'→'validator',
 * absent→'user') is the service's job, not the DTO's.
 */
final class GroupDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly ?string $avatarHash,
        public readonly ?string $description,
        public readonly ?string $kindRaw = null,
    ) {
    }
}
