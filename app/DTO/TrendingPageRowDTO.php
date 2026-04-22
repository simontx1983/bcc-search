<?php

namespace BCC\Search\DTO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Immutable row representing a trending page projection from the trust-engine
 * read model. Produced by SearchRepository::getTrendingFromReadModel() by
 * adapting the stdClass rows returned from the TrendingDataInterface contract.
 *
 * Kept separate from PageCandidateDTO / PageHydratedDTO because this shape is
 * trust-engine's read-model projection, not WP core page data — different
 * source, different lifecycle, different authority.
 */
final class TrendingPageRowDTO
{
    public function __construct(
        public readonly int $id,
        public readonly float $totalScore,
        public readonly string $reputationTier,
    ) {
    }
}
