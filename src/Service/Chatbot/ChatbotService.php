<?php

declare(strict_types=1);

namespace App\Service\Chatbot;

use App\Exception\UnsafeSqlException;
use App\Service\DownloadService;
use App\Service\LLM\HumanResponseService;
use App\Service\LLM\IntentService;
use App\Service\LLM\SqlGeneratorService;
use App\Service\Schema\DatabaseSchemaService;
use App\Service\SqlSecurityService;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Main chatbot service that orchestrates the entire workflow
 */
readonly class ChatbotService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DatabaseSchemaService  $schemaService,
        private SqlSecurityService     $sqlSecurity,
        private LoggerInterface        $logger,
        private DownloadService        $downloadService,

        // LLM Service
        private IntentService          $intentService,
        private SqlGeneratorService    $sqlGeneratorService,
        private HumanResponseService   $humanResponseService,
    ) {}

    /**
     * Process a user question and return a structured response
     */
    public function processQuestion(string $question): array
    {
        // Peux-tu me donner tous les rapports ?
        // Peux tu me donner des informations sur le collaborateur ludovic.jahan@23prod.com ?
        // Peux tu me donner le nom des différentes agences ?
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
            $results = $this->executeQuery($validatedSql);
            
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

    public function classify(string $question): string
    {
        return $this->intentService->classify($question);
    }

    public function getTablesStructure(): array
    {
        return $this->schemaService->getTablesStructure();
    }

    public function generateSql(string $question, array $schema, string $intent): string
    {
        return $this->sqlGeneratorService->generateForIntent($question, $schema, $intent);
    }

    /**
     * @throws UnsafeSqlException
     */
    public function validateSql(string $generatedSql): string
    {
       return $this->sqlSecurity->validateQuery($generatedSql);
    }

    /**
     * Generate streaming response from SQL results using LLM
     */
    public function generateStreamingResponse(string $question, array $sqlResults, string $validatedSql, string $intent): \Generator
    {
        if ($intent === IntentService::INTENT_DOWNLOAD) {
            yield from $this->humanResponseService->generateStreamingDownloadResponse($sqlResults);
        } else {
            yield from $this->humanResponseService->generateStreamingHumanResponse(
                $question,
                $sqlResults,
                $validatedSql,
            );
        }
    }

    public function executeQuery(string $sql): array
    {
        try {
            $connection = $this->entityManager->getConnection();
            
            // Set a reasonable timeout for MySQL
            try {
                $connection->executeStatement('SET SESSION max_execution_time = 30000'); // 30 seconds
            } catch (Exception $e) {
                $this->logger->warning('Failed to set query timeout', ['error' => $e->getMessage()]);
            }

            // Prepare query
            try {
                $statement = $connection->prepare($sql);
            } catch (Exception $e) {
                throw new RuntimeException('Erreur de préparation de la requête SQL: ' . $e->getMessage());
            }
            
            // Execute the query
            try {
                $result = $statement->executeQuery();
            } catch (Exception $e) {
                throw new RuntimeException("Erreur d'exécution de la requête: " . $e->getMessage());
            }
            
            // Fetch results
            try {
                return $result->fetchAllAssociative();
            } catch (Exception $e) {
                throw new RuntimeException("Erreur de récupération des résultats: " . $e->getMessage());
            }
            
        } catch (RuntimeException $e) {
            // Re-throw runtime exceptions (our custom errors)
            throw $e;
        } catch (Exception $e) {
            // Catch any other unexpected exceptions
            throw new RuntimeException("Erreur inattendue lors de l'exécution de la requête");
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

    public function handleDownloadGeneration(array $results, string $question): void
    {
        $this->downloadService->streamDownloadGeneration($results, $question);
    }
}
