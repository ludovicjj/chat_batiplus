<?php

declare(strict_types=1);

namespace App\Service\SQL;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use RuntimeException;

readonly class SqlExecutorService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {}

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
            // Re-throw runtime exceptions ? Create custom exception here ?
            throw $e;
        } catch (Exception) {
            // Catch any other unexpected exceptions
            throw new RuntimeException("Erreur inattendue lors de l'exécution de la requête");
        }
    }
}