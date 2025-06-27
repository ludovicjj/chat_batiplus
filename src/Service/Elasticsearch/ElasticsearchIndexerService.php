<?php

namespace App\Service\Elasticsearch;

use App\Dto\ClientCaseDto;
use Exception;
use JoliCode\Elastically\Client;
use Symfony\Component\Console\Style\SymfonyStyle;

class ElasticsearchIndexerService
{
    private const BATCH_SIZE = 50;

    public function __construct(
        private readonly Client               $elasticClient,
        private readonly StreamingDataFetcher $streamingDataFetcher,
    ) {}

    /**
     * Indexe tous les ClientCases avec reprise possible
     */
    public function indexAllClientCases(SymfonyStyle $io, ?int $startFromId = null): array
    {
        $startTime = microtime(true);
        $totalIndexed = 0;
        $errors = [];

        try{
            // 1. Fetch and display total ClientCase
            $io->text('Counting total ClientCases...');
            $totalToIndex = $this->streamingDataFetcher->countTotalClientCases($startFromId);
            $io->success("Found {$totalToIndex} ClientCases to index");

            // 2. Init une progress bar
            $progressBar = $io->createProgressBar($totalToIndex);
            $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% - %message%');
            $progressBar->setMessage('Starting...');
            $progressBar->start();

            // 3. Fetch ClientCase by Batch
            foreach ($this->streamingDataFetcher->streamClientCases(self::BATCH_SIZE, $startFromId) as $clientCaseData) {
                $currentId = $clientCaseData['id'] ?? 0;

                // Resume index to startFromId
                if ($startFromId && $currentId <= $startFromId) {
                    continue;
                }

                try {
                    $dto = ClientCaseDto::fromStreamingData($clientCaseData);
                    $document = $dto->toElasticsearchDocument();

                    // Index Document
                    $this->elasticClient->index([
                        'index' => 'client_case',
                        'id' => $document['id'],
                        'body' => $document
                    ]);

                    $totalIndexed++;

                    // Update progress bar
                    $progressBar->advance();
                    $progressBar->setMessage("Processing ID: {$currentId}");

                    // Detail Log every 100 documents
                    if ($totalIndexed % 100 === 0) {
                        $elapsed = microtime(true) - $startTime;
                        $rate = round($totalIndexed / $elapsed, 2);
                        $remaining = $totalToIndex - $totalIndexed;
                        $eta = $rate > 0 ? round($remaining / $rate / 60, 1) : 0;

                        $progressBar->setMessage("ID: {$currentId} | Rate: {$rate}/s | ETA: {$eta}min");
                    }
                } catch (Exception $exception) {
                    $errors[] = [
                        'client_case_id' => $currentId,
                        'error' => $exception->getMessage(),
                        'timestamp' => date('H:i:s')
                    ];

                    // Log de l'erreur sans casser la progress bar
                    $progressBar->clear();
                    $io->warning("❌ Error indexing ClientCase {$currentId}: " . $exception->getMessage());
                    $progressBar->display();
                }
            }
        } catch (Exception $e) {
            $io->error('💥 Critical error: ' . $e->getMessage());
            $errors[] = $e->getMessage();
        }

        $totalTime = round(microtime(true) - $startTime, 2);

        // Résultats finaux
        $io->section('📊 Final Results');
        $io->table(['Metric', 'Value'], [
            ['Documents indexed', number_format($totalIndexed)],
            ['Errors', count($errors)],
            ['Execution time', gmdate('H:i:s', $totalTime)],
            ['Average rate', $totalIndexed > 0 ? round($totalIndexed / $totalTime, 2) . '/sec' : '0/sec']
        ]);

        if (!empty($errors)) {
            $io->section('❌ Errors Details');
            foreach (array_slice($errors, 0, 10) as $error) {
                if (is_array($error)) {
                    $io->text("• ClientCase {$error['client_case_id']}: {$error['error']}");
                } else {
                    $io->text("• {$error}");
                }
            }

            if (count($errors) > 10) {
                $io->text('... and ' . (count($errors) - 10) . ' more errors');
            }
        }

        return [
            'total_indexed' => $totalIndexed,
            'total_errors' => count($errors),
            'execution_time_seconds' => $totalTime,
            'errors' => $errors
        ];
    }

    /**
     * Index a specific ClientCase by ID
     * @throws Exception
     */
    public function indexSingleClientCase(int $clientCaseId): void
    {
        // Fetch clientCase with Report and Reviews
        $clientCaseData = $this->streamingDataFetcher->fetchClientCaseComplete($clientCaseId);

        if (!$clientCaseData) {
            throw new Exception('Client case not found');
        }

        // Build DTO and transform to ES
        $dto = ClientCaseDto::fromStreamingData($clientCaseData);
        $document = $dto->toElasticsearchDocument();

        // Indexer
        $this->indexDocument($document);
    }

    /**
     * Indexe un document dans Elasticsearch
     */
    private function indexDocument(array $document): void
    {
        $params = [
            'index' => 'client_case',
            'id' => $document['id'],
            'body' => $document
        ];

        $response = $this->elasticClient->index($params);

        // Vérifier la réponse
        if (isset($response['result']) && in_array($response['result'], ['created', 'updated'])) {
            // Success
        } else {
            throw new \RuntimeException('Unexpected Elasticsearch response: ' . json_encode($response));
        }
    }
}