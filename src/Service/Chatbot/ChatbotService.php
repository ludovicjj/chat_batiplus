<?php

declare(strict_types=1);

namespace App\Service\Chatbot;

use App\Exception\UnsafeSqlException;
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
        $startTime = microtime(true);
        
        try {
            // Step 0: Classify user intent
            $intent = $this->intentService->classify($question);

            // Step 1: Get database schema
            $schema = $this->schemaService->getTablesStructure();
            
            // Step 2: Generate SQL query using LLM
            $generatedSql = $this->sqlGeneratorService->generateForIntent($question, $schema, $intent);

            dd($question, $generatedSql);
            
            // Step 3: Validate SQL query for security
            $validatedSql = $this->sqlSecurity->validateQuery($generatedSql);
            
            // Step 4: Execute the query
            $results = $this->executeQuery($validatedSql);
            
            // Step 5: Generate human-readable response
            $humanResponse = $this->humanResponseService->generateHumanResponse(
                $question,
                $results,
                $validatedSql
            );
            
            // Step 6: Check if download is requested
            $downloadInfo = $this->checkForDownloadRequest($humanResponse, $results, $question);
            
            $executionTime = microtime(true) - $startTime;
            
            $response = [
                'success' => true,
                'response' => $downloadInfo['cleaned_response'],
                'metadata' => [
                    'sql_query' => $validatedSql,
                    'execution_time' => round($executionTime, 3),
                    'result_count' => count($results),
                    'raw_results' => $results
                ]
            ];
            
            // Add download info if requested
            if ($downloadInfo['requested']) {
                $response['download'] = $downloadInfo['download_data'];
            }
            
            return $response;
            
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

    private function executeQuery(string $sql): array
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
    private function checkForDownloadRequest(string $response, array $results, string $originalQuestion): array
    {
        $downloadRequested = str_contains($response, '[DOWNLOAD_REQUEST]');
        
        if ($downloadRequested) {
            // Remove the marker from the response
            $cleanedResponse = str_replace('[DOWNLOAD_REQUEST]', '', $response);
            $cleanedResponse = trim($cleanedResponse);
            
            // Generate download info (placeholder for now)
            $downloadData = [
                'available' => true,
                'file_count' => count($results),
                'estimated_size' => $this->estimateDownloadSize($results),
                'message' => '📎 Génération du fichier de téléchargement en cours...'
            ];
            
            $this->logger->info('Download requested', [
                'question' => $originalQuestion,
                'result_count' => count($results)
            ]);
            
            return [
                'requested' => true,
                'cleaned_response' => $cleanedResponse,
                'download_data' => $downloadData
            ];
        }
        
        return [
            'requested' => false,
            'cleaned_response' => $response,
            'download_data' => null
        ];
    }
    
    /**
     * Estimate download size based on result count
     */
    private function estimateDownloadSize(array $results): string
    {
        $count = count($results);
        
        if ($count === 0) {
            return '0 KB';
        }
        
        // Rough estimation: ~500KB per PDF report
        $estimatedBytes = $count * 500 * 1024;
        
        if ($estimatedBytes < 1024 * 1024) {
            return round($estimatedBytes / 1024) . ' KB';
        }
        
        return round($estimatedBytes / (1024 * 1024), 1) . ' MB';
    }
}
