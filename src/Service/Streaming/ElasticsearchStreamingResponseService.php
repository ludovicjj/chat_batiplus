<?php

namespace App\Service\Streaming;

use App\Exception\UnsafeElasticsearchQueryException;
use App\Service\Archive\ReportArchiveService;
use App\Service\Elasticsearch\ElasticsearchExecutorService;
use App\Service\Elasticsearch\ElasticsearchGeneratorService;
use App\Service\Elasticsearch\ElasticsearchSchemaService;
use App\Service\Elasticsearch\ElasticsearchSecurityService;
use App\Service\LLM\HumanResponseService;
use App\Service\LLM\IntentService;
use App\Service\QueryProcessor;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

readonly class ElasticsearchStreamingResponseService
{
    public function __construct(
        // Services LLM
        private IntentService $intentService,
        private ElasticsearchGeneratorService   $elasticsearchGeneratorService,
        private HumanResponseService            $humanResponseService,

        // Services ES
        private ElasticsearchSchemaService      $elasticsearchSchemaService,
        private ElasticsearchSecurityService    $elasticsearchSecurityService,
        private ElasticsearchExecutorService    $elasticsearchExecutorService,

        // Services techniques
        private ReportArchiveService            $reportArchiveService,
        private ServerSentEventService          $sseService,
        private LoggerInterface                 $logger,
        private QueryProcessor                  $queryProcessor
    ) {}

    public function createStreamingResponse(string $question): StreamedResponse
    {
        return new StreamedResponse(
            fn() => $this->executeStreamingWorkflow($question),
            Response::HTTP_OK,
            $this->sseService->getHeaders()
        );
    }

    private function executeStreamingWorkflow(string $question): void
    {
        try {
            // Workflow Elasticsearch
            $streamingData = $this->prepareElasticsearchData($question);

            $this->streamLlmResponse(
                $question,
                $streamingData['results'],
                $streamingData['query'],
                $streamingData['intent']
            );

            $this->handlePostStreamingActions(
                $streamingData['results'],
                $streamingData['intent'],
                $question
            );

            $this->sseService->sendFinalComplete();

        } catch (\Exception $e) {
            $this->logger->error('Elasticsearch streaming workflow error', [
                'question' => $question,
                'error' => $e->getMessage(),
            ]);

            $this->sseService->sendError($e->getMessage());
        }
    }

    /**
     * @throws UnsafeElasticsearchQueryException
     */
    private function prepareElasticsearchData(string $question): array
    {
        // Step 0: Normalize question
        $normalizedQuestion = $this->queryProcessor->normalizeQuestion($question);

        // Step 1: Classify user intent
        $intent = $this->intentService->classify($normalizedQuestion);

        // Step 2: Get Elasticsearch schema
        $schema = $this->elasticsearchSchemaService->getMappingsStructure();

        // Step 3: Generate ES query using LLM
        $queryBody = $this->elasticsearchGeneratorService->generateQueryBody($normalizedQuestion, $schema, $intent);

        // Step 4: Validate ES query for security
        $validatedQuery = $this->elasticsearchSecurityService->validateQuery($queryBody);

        // Step 5: Execute the query
        $results = $this->elasticsearchExecutorService->executeQuery($validatedQuery);

        return [
            'intent' => $intent,
            'query' => $validatedQuery,
            'results' => $results,
        ];
    }

    private function streamLlmResponse(
        string $question,
        array $results,
        array $validatedQuery,
        string $intent
    ): void {
        foreach ($this->humanResponseService->generateElasticsearchStreamingResponse($question, $results, $validatedQuery, $intent) as $chunk) {
            $this->sseService->sendChunk($chunk);
        }

        $this->sseService->sendLlmComplete();
    }

    /**
     * This method is just a wrapper for handle actions after that need to happen after streaming (like download generation)
     * I think, it will be possible to extend intent here if needed...
     */
    private function handlePostStreamingActions(array $results, string $intent, string $question): void
    {
        if ($intent === IntentService::INTENT_DOWNLOAD && !empty($results)) {
            $this->handleDownloadGeneration($results, $question);
        }

        // add your use case here
    }

    /**
     * Handle download generation with streaming updates
     */
    private function handleDownloadGeneration(array $results, string $question): void
    {
        try {
            // Helper for user
            $this->sseService->sendDownloadStep('📁 Préparation des fichiers...');
            $this->sseService->sendDownloadStep('☁️ Téléchargement depuis S3...');
            $this->sseService->sendDownloadStep("📦 Création de l\'archive ZIP...");

            $downloadResult = $this->reportArchiveService->generateDownloadPackage(
                $results,
                ReportArchiveService::TYPE_ES
            );

            // Handle Download Result with SSE
            if ($downloadResult['success']) {
                $stats = $downloadResult['stats'];
                $this->sseService->sendDownloadReady([
                    'available' => true,
                    'status' => 'ready',
                    'download_url' => $downloadResult['download_url'],
                    'filename' => $downloadResult['file_name'],
                    'file_count' => $stats['downloaded'],
                    'estimated_size' => $stats['total_size'],
                    'message' => 'Votre archive est prête au téléchargement !',
                    'stats' => $stats,
                    'error_count' => $stats['errors'],
                    'error_messages' => $stats['error_details']
                ]);
            } else {
                $this->sseService->sendDownloadError([
                    'available' => false,
                    'status' => 'error',
                    'message' => $downloadResult['error']
                ]);
            }

        } catch (Exception $e) {
            $this->logger->error('Download generation error', [
                'question' => $question,
                'error' => $e->getMessage()
            ]);

            $this->sseService->sendDownloadError([
                'available' => false,
                'status' => 'error',
                'message' => 'Erreur lors de la génération du téléchargement'
            ]);
        }
    }
}