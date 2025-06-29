<?php

namespace App\Service\Elasticsearch;

use App\Exception\UnsafeElasticsearchQueryException;
use App\Exception\UnsafeSqlException;

class ElasticsearchSecurityService
{
    /**
     * @param string $queryBody
     * @throws UnsafeElasticsearchQueryException
     */
    public function validateQuery(string $queryBody): void
    {
        // Validation basique pour les tests
        $queryBodyArray = json_decode($queryBody, true);
        $allowedKeys = ['query', 'size', 'from', 'sort', '_source', 'track_total_hits', 'aggs', 'aggregations'];

        foreach (array_keys($queryBodyArray) as $key) {
            if (!in_array($key, $allowedKeys)) {
                throw new \InvalidArgumentException("Clé non autorisée: {$key}");
            }
        }

        // Validate size limits
        $this->validateSizeLimits($queryBodyArray);
    }

    /**
     * @throws UnsafeElasticsearchQueryException
     */
    private function validateSizeLimits(array $queryBody): void
    {
        // Limit result size for safety
        $maxSize = 1000;

        if (isset($queryBody['size']) && $queryBody['size'] > $maxSize) {
            throw new UnsafeElasticsearchQueryException("Query size limit exceeded. Maximum: {$maxSize}");
        }

        // Limit from parameter
        $maxFrom = 10000;
        if (isset($queryBody['from']) && $queryBody['from'] > $maxFrom) {
            throw new UnsafeElasticsearchQueryException("Query from limit exceeded. Maximum: {$maxFrom}");
        }
    }
}