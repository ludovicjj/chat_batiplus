<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\UnsafeSqlException;
use DateTimeImmutable;
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
        private DatabaseSchemaService $schemaService,
        private LlmService $llmService,
        private SqlSecurityService $sqlSecurity,
        private LoggerInterface $logger
    ) {}

    /**
     * Process a user question and return a structured response
     */
    public function processQuestion(string $question): array
    {
        $startTime = microtime(true);
        
        try {
            $this->logger->info('Processing user question', [
                'question' => $question,
                'timestamp' => new DateTimeImmutable()
            ]);

            // Step 1: Get database schema
            $schema = $this->schemaService->getTablesStructure();
            
            // Step 2: Generate SQL query using LLM
            $generatedSql = $this->llmService->generateSqlQuery($question, $schema);
            
            // Step 3: Validate SQL query for security
            $validatedSql = $this->sqlSecurity->validateQuery($generatedSql);
            
            // Step 4: Execute the query
            $results = $this->executeQuery($validatedSql);
            
            // Step 5: Generate human-readable response
            $humanResponse = $this->llmService->generateHumanResponse(
                $question,
                $results,
                $validatedSql
            );
            
            $executionTime = microtime(true) - $startTime;
            
            return [
                'success' => true,
                'response' => $humanResponse,
                'metadata' => [
                    'sql_query' => $validatedSql,
                    'execution_time' => round($executionTime, 3),
                    'result_count' => count($results),
                    'raw_results' => $results
                ]
            ];
            
        } catch (UnsafeSqlException $e) {
            $this->logger->warning('Unsafe SQL query blocked', [
                'question' => $question,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Votre question a généré une requête non autorisée pour des raisons de sécurité.',
                'error_type' => 'security',
            ];
            
        } catch (Exception $e) {
            $this->logger->error('Error processing question', [
                'question' => $question,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'error' => 'Une erreur est survenue lors du traitement de votre question.',
                'error_type' => 'general'
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
}
