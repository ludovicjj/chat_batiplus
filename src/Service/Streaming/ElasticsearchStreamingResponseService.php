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
use App\Service\Rag\RagService;
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
        private QueryProcessor                  $queryProcessor,

        // Rag
        private RagService                      $ragService,
    ) {}

    public function createStreamingResponse(string $question): StreamedResponse
    {
        return new StreamedResponse(
            function() use ($question) {
                $this->sseService->initializeStreaming();
                $this->executeStreamingWorkflow($question);
            },
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

        } catch (Exception $e) {
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

        // Step 1: Handle strange question
        if ($intent === IntentService::INTENT_CHITCHAT) {
            return [
                'intent' => $intent,
                'query' => '',
                'results' => [],
            ];
        }

        // Step 2: Get Elasticsearch schema
        $schema = $this->elasticsearchSchemaService->getMappingsStructure();

        // Step 3: Rag examples
        $ragExamples = $this->ragService->findSimilarExamples(
            question: $normalizedQuestion,
            intent: $intent,
            similarityThreshold: 0.65
        );

        // Step 4: Generate ES query using LLM
        $queryBody = $this->elasticsearchGeneratorService->generateQueryBody($normalizedQuestion, $schema, $intent, $ragExamples);

        // Step 5: Validate ES query for security
        $this->elasticsearchSecurityService->validateQuery($queryBody);

        // Step 6: Execute the query
        $results = $this->elasticsearchExecutorService->executeQuery($queryBody);

        return [
            'intent' => $intent,
            'query' => $queryBody,
            'results' => $results,
        ];
    }

    private function streamLlmResponse(
        string $question,
        array $results,
        string $queryBody,
        string $intent
    ): void {
        foreach ($this->humanResponseService->generateElasticsearchStreamingResponse($question, $results, $queryBody, $intent) as $chunk) {
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