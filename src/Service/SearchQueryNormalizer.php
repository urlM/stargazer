<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Service responsible for normalizing and validating user search queries.
 */
final class SearchQueryNormalizer
{
    private const MAX_QUERY_LENGTH = 100;

    /**
     * Normalizes a search query for safe database use.
     *
     * @param string $query The raw search query from user input.
     *
     * @return string|null The normalized query, or null if invalid/empty.
     */
    public function normalize(?string $query): ?string
    {
        if ($query === null || $query === '') {
            return null;
        }

        // Trim whitespace
        $normalized = trim($query);

        // Return null if empty after trimming
        if ($normalized === '') {
            return null;
        }

        // Enforce maximum length
        if (strlen($normalized) > self::MAX_QUERY_LENGTH) {
            $normalized = substr($normalized, 0, self::MAX_QUERY_LENGTH);
        }

        // Lowercase for case-insensitive search
        $normalized = mb_strtolower($normalized, 'UTF-8');

        // Escape wildcards for LIKE queries
        $normalized = $this->escapeLikeWildcards($normalized);

        return $normalized;
    }

    /**
     * Escapes SQL LIKE wildcards (%, _) in the query string.
     *
     * @param string $query The query string to escape.
     *
     * @return string The escaped query string.
     */
    private function escapeLikeWildcards(string $query): string
    {
        return strtr($query, ['%' => '\\%', '_' => '\\_']);
    }
}
