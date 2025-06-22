<?php

declare(strict_types=1);

namespace App\Service\Streaming;

use App\Exception\UnsafeSqlException;
use App\Service\Archive\ReportArchiveService;
use App\Service\LLM\HumanResponseService;
use App\Service\LLM\IntentService;
use App\Service\LLM\SqlGeneratorService;
use App\Service\SQL\SqlExecutorService;
use App\Service\SQL\SqlSchemaService;
use App\Service\SQL\SqlSecurityService;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

readonly class StreamingResponseService
{
    public function __construct(
        // Services LLM
        private IntentService $intentService,
        private SqlGeneratorService $sqlGeneratorService,
        private HumanResponseService $humanResponseService,

        // Services SQL
        private SqlSchemaService $sqlSchemaService,
        private SqlSecurityService $sqlSecurityService,
        private SqlExecutorService $sqlExecutorService,

        // Services techniques
        private ReportArchiveService $reportArchiveService,
        private ServerSentEventService $sseService,
        private LoggerInterface $logger,
    ) {
    }

    public function createStreamingResponse(string $question): StreamedResponse
    {
        return new StreamedResponse(
            fn() => $this->executeStreamingWorkflow($question),
            Response::HTTP_OK,
            $this->sseService->getHeaders()
        );
    }

    /**
     * Execute the complete streaming workflow
     */
    private function executeStreamingWorkflow(string $question): void
    {
        try {
            // Step 1: Prepare all streaming data
            $streamingData = $this->prepareStreamingData($question);

            // Step 2: Stream the LLM response
            $this->streamLlmResponse(
                $question,
                $streamingData['results'],
                $streamingData['validatedSql'],
                $streamingData['intent']
            );

            // Step 3: Handle post-streaming actions
            $this->handlePostStreamingActions(
                $streamingData['results'],
                $streamingData['intent'],
                $question
            );

            // Step 4: Send final completion
            $this->sseService->sendFinalComplete();

        } catch (Exception $e) {
            $this->logger->error('Streaming workflow error', [
                'question' => $question,
                'error' => $e->getMessage(),
            ]);

            $this->sseService->sendError($e->getMessage());
        }
    }

    /**
     * @throws UnsafeSqlException
     */
    private function prepareStreamingData(string $question): array
    {
        // Step 0: Classify user intent
        $intent = $this->intentService->classify($question);

        // Step 1: Get database schema
        $schema = $this->sqlSchemaService->getTablesStructure();

        // Step 2: Generate SQL query using LLM
        $sql = $this->sqlGeneratorService->generateForIntent($question, $schema, $intent);

        // Step 3: Validate SQL query for security
        $validatedSql = $this->sqlSecurityService->validateQuery($sql);

        // Step 4: Execute the query
        $results = $this->sqlExecutorService->executeQuery($validatedSql);

        // Step 5: Return all needed data
        return [
            'intent' => $intent,
            'validatedSql' => $validatedSql,
            'results' => $results,
        ];
    }

    private function streamLlmResponse(
        string $question,
        array $results,
        string $validatedSql,
        string $intent
    ): void {
        foreach ($this->humanResponseService->generateStreamingResponse($question, $results, $validatedSql, $intent) as $chunk) {
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

            $downloadResult = $this->reportArchiveService->generateDownloadPackage($results);

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
                    'stats' => $stats
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