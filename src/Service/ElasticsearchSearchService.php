<?php

namespace App\Service;

use JoliCode\Elastically\Client;
use JoliCode\Elastically\IndexBuilder;

class ElasticsearchSearchService
{
    public function __construct(
        private readonly Client $elasticClient,
        private readonly IndexBuilder $indexBuilder,
    ) {}

    public function createIndex($name): void
    {
        $index = $this->indexBuilder->createIndex($name, [
            'filename' => 'client_case.yaml'
        ]);
        $this->indexBuilder->markAsLive($index, $name);
    }

    public function deleteIndex($index): void
    {
        $this->elasticClient->indices()->delete(['index' => $index . '*']);
    }

    public function searchClientCases(array $criteria, int $size = 20): array
    {
        $query = $this->buildQuery($criteria);

        $searchParams = [
            'index' => 'client_case',
            'body' => [
                'query' => $query,
                'size' => $size,
                'sort' => [
                    ['_score' => ['order' => 'desc']],
                    ['id' => ['order' => 'desc']]
                ]
            ]
        ];

        $response = $this->elasticClient->search($searchParams);

        return [
            'total' => $response['hits']['total']['value'],
            'results' => array_map(fn($hit) => [
                'score' => $hit['_score'],
                'data' => $hit['_source']
            ], $response['hits']['hits'])
        ];
    }

    private function buildQuery(array $criteria): array
    {
        $must = [];

        // Recherche exacte par référence (type keyword)
        if (!empty($criteria['reference'])) {
            $must[] = ['term' => ['reference' => $criteria['reference']]];
        }

        // Recherche dans le nom du projet (type text)
        if (!empty($criteria['projectName'])) {
            $must[] = ['match' => ['projectName' => $criteria['projectName']]];
        }

        // Recherche dans le nom de l'agence (type text)
        if (!empty($criteria['agencyName'])) {
            $must[] = ['match' => ['agencyName' => $criteria['agencyName']]];
        }

        // Recherche dans le nom du client (type text)
        if (!empty($criteria['clientName'])) {
            $must[] = ['match' => ['clientName' => $criteria['clientName']]];
        }

        // Filtre par statut (type keyword - exact)
        if (!empty($criteria['status'])) {
            $must[] = ['term' => ['statusName' => $criteria['status']]];
        }

        // Recherche dans le nom du manager (type text)
        if (!empty($criteria['managerName'])) {
            $must[] = ['match' => ['managerName' => $criteria['managerName']]];
        }

        // Si des critères sont définis, créer une requête bool
        if (!empty($must)) {
            return [
                'bool' => [
                    'must' => $must
                ]
            ];
        }

        // Si aucun critère, retourner tous les documents
        return ['match_all' => new \stdClass()];
    }
}