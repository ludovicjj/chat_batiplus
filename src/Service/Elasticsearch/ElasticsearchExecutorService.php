<?php

namespace App\Service\Elasticsearch;

use Exception;
use JoliCode\Elastically\Client;
use RuntimeException;

class ElasticsearchExecutorService
{
    public function __construct(
        private readonly Client $elasticClient,
    )
    {
    }

    public function executeQuery(array $queryBody): array
    {
        try {
            // Add default timeout if not specified
            if (!isset($queryBody['timeout'])) {
                $queryBody['timeout'] = '30s';
            }

            // Execute the query
            $params = [
                'index' => 'client_case',
                'body' => $queryBody
            ];

            $response = $this->elasticClient->search($params);

            return $this->formatResponse($response);

        } catch (Exception $e) {
            throw new RuntimeException("Erreur d'exécution de la requête Elasticsearch: " . $e->getMessage());
        }
    }

    private function formatResponse($response): array
    {
        return [
            'total' => $response['hits']['total']['value'] ?? 0,
            'took' => $response['took'] ?? 0,
            'results' => array_map(fn($hit) => [
                'id' => $hit['_id'],
                'score' => $hit['_score'],
                'data' => $hit['_source']
            ], $response['hits']['hits'] ?? []),
            'aggregations' => $response['aggregations'] ?? []
        ];
    }
}