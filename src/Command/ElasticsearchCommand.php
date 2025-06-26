<?php

namespace App\Command;

use App\Dto\ClientCaseDto;
use App\Service\ElasticsearchSearchService;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use JoliCode\Elastically\Client;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use JoliCode\Elastically\IndexBuilder;

#[AsCommand(
    name: 'app:es',
    description: 'Test Elasticsearch connection'
)]
class ElasticsearchCommand extends Command
{
    public function __construct(
        private readonly Client $elasticClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly ElasticsearchSearchService $elasticsearchSearchService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Limit number of records', 100);
        $this->addOption('action', null, InputOption::VALUE_REQUIRED, 'action to execute', 100);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = $input->getOption('action');

        if ($action === 'index') {
            $this->elasticsearchSearchService->createIndex('client_case');
            $io->success('Index created');

            $mapping = $this->elasticClient->indices()->getMapping(['index' => 'client_case']);
            return Command::SUCCESS;
        }

        if ($action === 'delete') {
            $this->elasticsearchSearchService->deleteIndex('client_case');
            $io->success('Index deleted');
            return Command::SUCCESS;
        }

        if ($action === 'mapping') {
            return $this->mapping($io);
        }

        if ($action === 'search') {
            return $this->search($io);
        }

        if ($action === 'check-mapping') {
            return $this->checkMapping($io);
        }

        $io->error('Action required: --action=check|mapping|search|debug|delete|check-mapping');
        return Command::FAILURE;
    }

    private function check(SymfonyStyle $io): int
    {
        try {
            $info = $this->elasticClient->info();
            $io->table(
                ['Property', 'Value'],
                [
                    ['Cluster Name', $info['cluster_name']],
                    ['Version', $info['version']['number']],
                    ['Status', '🟢 Connected'],
                ]
            );

            return Command::SUCCESS;
        } catch (Exception $e) {
            $io->error('Connection failed: ' . $e->getMessage());

            return Command::FAILURE;
        }
    }

    private function debug(SymfonyStyle $io): int
    {
        // 1. Voir tous les documents
        $allDocs = $this->elasticClient->search([
            'index' => 'client_case',
            'body' => ['query' => ['match_all' => new \stdClass()], 'size' => 10]
        ]);

        $io->section('📋 Documents indexés:');
        foreach ($allDocs['hits']['hits'] as $doc) {
            $io->writeln('ID: ' . $doc['_source']['id'] . ' | Ref: "' . $doc['_source']['reference'] . '"');
        }

        // 2. Test term avec une référence qui existe
        $termSearch = $this->elasticClient->search([
            'index' => 'client_case',
            'body' => ['query' => ['term' => ['reference' => '94P0242305']]]
        ]);

        $io->section('🔍 Recherche term "94P0242305":');
        $io->writeln('Résultats: ' . $termSearch['hits']['total']['value']);

        // 3. Test match avec la même référence
        $matchSearch = $this->elasticClient->search([
            'index' => 'client_case',
            'body' => ['query' => ['match' => ['reference' => '94P0242305']]]
        ]);

        $io->section('🔍 Recherche match "94P0242305":');
        $io->writeln('Résultats: ' . $matchSearch['hits']['total']['value']);

        return Command::SUCCESS;
    }

    private function mapping(SymfonyStyle $io): int
    {
        $processed = 0;
        $clientCasesData = $this->fetchClientCases(5);
        $io->progressStart(count($clientCasesData));

        foreach ($clientCasesData as $rawData) {
            $document = ClientCaseDto::fromArray($rawData);
            $this->elasticClient->index([
                'index' => 'client_case',
                'id' => $document->id,
                'body' => $document->toArray()
            ]);

            $processed++;
            $io->progressAdvance();
        }

        $this->elasticClient->indices()->refresh(['index' => 'client_case']);
        $io->note(sprintf('Indexed %d ClientCases', $processed));

        return Command::SUCCESS;
    }

    private function search(SymfonyStyle $io): int
    {
        try {
            // Search in multiple fields
            $searchParams = [
                'index' => 'client_case',
                'body' => [
                    'query' => [
                        'multi_match' => [
                            'query' => '94P0242305',
                            'fields' => [
                                'reference^3',      // Boost reference matches
                                'project_name^2',   // Boost project name matches
                                'clientName',
                                'agencyName',
                                'managerName'
                            ],
                            'type' => 'best_fields',
                            'fuzziness' => 'AUTO'
                        ]
                    ],
                    'size' => 10
                ]
            ];
            $response = $this->elasticClient->search($searchParams);
            $hits = $response['hits'];
            $total = $hits['total']['value'];

            if ($total === 0) {
                $io->warning('No results found');
                return Command::SUCCESS;
            }

            $io->success(sprintf('Found %d result(s)', $total));
            dd($hits);

            return Command::SUCCESS;
        } catch (Exception $e) {
            $io->error('Search failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function fetchClientCases(int $limit): array
    {
        $connection = $this->entityManager->getConnection();

        $sql = "
            SELECT 
                cc.id,
                cc.reference,
                cc.project_name,
                a.name as agency_name,
                c.company_name as client_name,
                ccs.name as status_name,
                CONCAT(COALESCE(col.firstname, ''), ' ', COALESCE(col.lastname, '')) as manager_name
            FROM client_case cc
            LEFT JOIN agency a ON cc.agency_id = a.id
            LEFT JOIN client c ON cc.client_id = c.id
            LEFT JOIN client_case_status ccs ON cc.client_case_status_id = ccs.id
            LEFT JOIN collaborator col ON cc.manager_id = col.id
            WHERE cc.deleted_at IS NULL
            ORDER BY cc.id DESC
            LIMIT {$limit}
        ";

        $statement = $connection->prepare($sql);

        $result = $statement->executeQuery();

        return $result->fetchAllAssociative();
    }

    private function checkMapping(SymfonyStyle $io): int
    {
        try {
            $io->section('🔍 Vérification du mapping pour l\'index "client_case"');

            // 1. Vérifier si l'index existe
            $indexExists = $this->elasticClient->indices()->exists(['index' => 'client_case']);

            if (!$indexExists) {
                $io->error('❌ L\'index "client_case" n\'existe pas');
                return Command::FAILURE;
            }

            $io->success('✅ Index "client_case" existe');

            // 2. Récupérer le mapping
            $mappingResponse = $this->elasticClient->indices()->getMapping(['index' => 'client_case']);
            $mapping = $mappingResponse->asArray();

            $properties = [];

            foreach ($mapping as $indexName => $indexData) {
                if (isset($indexData['mappings']['properties'])) {
                    $properties = $indexData['mappings']['properties'];
                    break;
                }
            }

            if (empty($properties)) {
                $io->warning('⚠️ Aucune propriété trouvée dans le mapping');
                return Command::SUCCESS;
            }

            // Afficher le mapping sous forme de tableau
            $tableData = [];
            foreach ($properties as $fieldName => $fieldConfig) {
                $type = $fieldConfig['type'] ?? 'unknown';
                $analyzer = $fieldConfig['analyzer'] ?? 'N/A';
                $fields = '';

                // Vérifier s'il y a des sous-champs
                if (isset($fieldConfig['fields'])) {
                    $subFields = [];
                    foreach ($fieldConfig['fields'] as $subFieldName => $subFieldConfig) {
                        $subFields[] = $subFieldName . ' (' . ($subFieldConfig['type'] ?? 'unknown') . ')';
                    }
                    $fields = implode(', ', $subFields);
                }

                $tableData[] = [
                    $fieldName,
                    $type,
                    $analyzer,
                    $fields ?: 'Aucun'
                ];
            }

            $io->table(
                ['Champ', 'Type', 'Analyzer', 'Sous-champs'],
                $tableData
            );

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('❌ Erreur lors de la vérification du mapping: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}