<?php

declare(strict_types=1);

namespace App\Service\Chatbot;

use App\Exception\UnsafeSqlException;
use App\Service\Archive\ReportArchiveService;
use App\Service\LLM\HumanResponseService;
use App\Service\LLM\IntentService;
use App\Service\LLM\SqlGeneratorService;
use App\Service\SQL\SqlExecutorService;
use App\Service\SQL\SqlSchemaService;
use App\Service\SQL\SqlSecurityService;
use Exception;

/**
 * Main chatbot service that orchestrates the entire workflow
 */
readonly class ChatbotService
{
    public function __construct(
        private SqlSchemaService       $schemaService,
        private SqlSecurityService     $sqlSecurity,
        private ReportArchiveService   $downloadService,
        private SqlExecutorService     $sqlExecutor,

        // LLM Service
        private IntentService          $intentService,
        private SqlGeneratorService    $sqlGeneratorService,
        private HumanResponseService   $humanResponseService,
    ) {}

    /**
     * Get system status information
     */
    public function getSystemStatus(): array
    {
        return [
            'database' => 'connected',
            'allowed_tables' => $this->sqlSecurity->getAllowedTables(),
            'timestamp' => new \DateTimeImmutable()
        ];
    }

    /**
     * Process a user question and return a structured response
     */
    public function processQuestion(string $question): array
    {
        $startTime = microtime(true);
        
        try {
            // Step 0: Classify user intent
            $intent = $this->intentService->classify($question);

            // Step 1: Get database schema
            $schema = $this->schemaService->getTablesStructure();
            
            // Step 2: Generate SQL query using LLM
            $generatedSql = $this->sqlGeneratorService->generateForIntent($question, $schema, $intent);
            
            // Step 3: Validate SQL query for security
            $validatedSql = $this->sqlSecurity->validateQuery($generatedSql);
            
            // Step 4: Execute the query
            $results = $this->sqlExecutor->executeQuery($validatedSql);
            
            // Step 5: Generate human-readable response
            $humanResponse = $this->humanResponseService->generateHumanResponse(
                $question,
                $results,
                $validatedSql,
                $intent
            );
            
            // Step 6: Handle response by intent
            return $this->handleResponseByIntent(
                $humanResponse,
                $results,
                $intent,
                $validatedSql,
                $startTime
            );
        } catch (UnsafeSqlException $e) {
            return [
                'success' => false,
                'error' => 'Votre question a généré une requête non autorisée pour des raisons de sécurité.',
                'error_type' => 'security',
                'error_message' => $e->getMessage(),
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Une erreur est survenue lors du traitement de votre question.',
                'error_type' => 'general',
                'error_message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check if the LLM response contains a download request and process it
     */
    private function handleResponseByIntent(
        string $humanResponse,
        array $results,
        string $intent,
        string $validatedSql,
        $startTime
    ): array {
        $executionTime = microtime(true) - $startTime;

        // Base response structure
        $response = [
            'success' => true,
            'response' => $humanResponse,
            'metadata' => [
                'intent' => $intent,
                'sql_query' => $validatedSql,
                'execution_time' => $executionTime,
                'result_count' => count($results)
            ]
        ];

        // Handle specific intent logic
        switch ($intent) {
            case IntentService::INTENT_DOWNLOAD:
                return $this->handleDownloadIntent($response, $results);

            case IntentService::INTENT_INFO:
            default:
                $response['download'] = [
                    'available' => false,
                    'message' => null
                ];
                $response['raw_results'] = $results;
                return $response;
        }
    }

    private function handleDownloadIntent(array $baseResponse,  array $results): array
    {
        try {
            $downloadResult = $this->downloadService->generateDownloadPackage($results);

            if ($downloadResult['success']) {
                $stats = $downloadResult['stats'];

                $baseResponse['download'] = [
                    'available' => true,
                    'status' => 'ready',
                    'download_url' => $downloadResult['download_url'],
                    'filename' => $downloadResult['file_name'],
                    'file_count' => $stats['downloaded'],
                    'estimated_size' => $stats['total_size'],
                    'message' => 'Votre archive ZIP est prête au téléchargement!',
                    'stats' => $stats
                ];
            } else {
                $baseResponse['download'] = [
                    'available' => false,
                    'status' => 'error',
                    'message' => $downloadResult['error']
                ];
            }
        } catch (Exception $e) {
            $baseResponse['download'] = [
                'available' => false,
                'status' => 'error',
                'message' => 'Erreur technique'
            ];
        }

        return $baseResponse;
    }
}
