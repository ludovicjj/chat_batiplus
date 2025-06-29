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

    public function executeQuery(string $queryBody): array
    {
        try {
            // Add default timeout if not specified
//            if (!str_contains($queryBody, '"timeout"')) {
//                // Insérer timeout avant la dernière accolade
//                $queryBody = rtrim($queryBody, " \n\r\t}") . ',
//  "timeout": "30s"
//}';
//            }

            // Execute the query
            $params = [
                'index' => 'client_case',
                'body' => $queryBody,
                'timeout' => '30s'
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