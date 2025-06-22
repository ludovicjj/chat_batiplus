<?php

declare(strict_types=1);

namespace App\Service\SQL;

use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Throwable;

class SqlSchemaService
{
    private const CACHE_KEY = 'database_schema_structure';
    private const CACHE_TTL = 3600; // 1 hour

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly EntityManagerInterface $entityManager,
        private readonly CacheInterface $cache
    ) {}

    public function getTablesStructure(): array
    {
        return $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
            // Configure cache expiration
            $item->expiresAfter(self::CACHE_TTL);

            $this->logger->info('Generating fresh database schema (cache miss)');

            // Generate the schema (heavy computation)
            return $this->generateTablesStructure();
        });
    }

    /**
     * @throws Throwable
     */
    private function generateTablesStructure(): array
    {
        try {
            $connection = $this->entityManager->getConnection();
            $schemaManager = $connection->createSchemaManager();

            // Récupérer les tables autorisées (depuis .env)
            $allowedTables = explode(',', $_ENV['ALLOWED_TABLES'] ?? '');
            $allowedTables = array_map('trim', $allowedTables);

            $structure = [];
            foreach ($allowedTables as $tableName) {
                $structure[$tableName] = $this->getTableColumns($schemaManager, $tableName);
            }

            return $structure;
        } catch (Throwable $exception) {
            $this->logger->error('Failed to generate database schema', [
                'error' => $exception->getMessage()
            ]);

            throw $exception;
        }
    }

    private function getTableColumns(AbstractSchemaManager $schemaManager, string $tableName): array
    {
        try {
            $table = $schemaManager->introspectTable($tableName);
            $columnInfo = [];

            foreach ($table->getColumns() as $column) {
                $nullable = $column->getNotnull() ? 'NOT NULL' : 'NULL';
                $columnInfo[] = sprintf(
                    "%s (%s, %s)",
                    $column->getName(),
                    $column->getType()->getName(),
                    $nullable
                );
            }

            return $columnInfo;
        } catch (Throwable $exception) {
            dd($exception->getMessage());
        }
    }
}
