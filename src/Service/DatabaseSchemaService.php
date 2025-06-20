<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

class DatabaseSchemaService
{
    private array $cachedSchema = [];
    private ?DateTimeImmutable $lastCacheUpdate = null;
    private int $cacheTimeout = 3600; // 1 hour

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function getTablesStructure(): array
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
            dd($exception->getMessage());
        }
    }

    /**
     * Get the database schema for allowed tables
     */
//    public function getSchema(): array
//    {
//        // Return cached schema if still valid
//        if ($this->isCacheValid()) {
//            return $this->cachedSchema;
//        }
//
//        $schema = [];
//        $allowedTables = $this->sqlSecurity->getAllowedTables();
//
//        if (empty($allowedTables)) {
//            $this->logger->warning('No allowed tables configured');
//            return [];
//        }
//
//        try {
//            $schemaManager = $this->connection->createSchemaManager();
//
//            foreach ($allowedTables as $tableName) {
//                if ($schemaManager->tablesExist([$tableName])) {
//                    $schema[$tableName] = $this->getTableColumns($schemaManager, $tableName);
//                } else {
//                    $this->logger->warning("Table {$tableName} does not exist in database");
//                }
//            }
//
//            // Cache the schema
//            $this->cachedSchema = $schema;
//            $this->lastCacheUpdate = new \DateTimeImmutable();
//
//            $this->logger->info('Database schema loaded', [
//                'tables_count' => count($schema),
//                'tables' => array_keys($schema)
//            ]);
//
//        } catch (\Exception $e) {
//            $this->logger->error('Failed to load database schema', [
//                'error' => $e->getMessage()
//            ]);
//            throw new \RuntimeException('Cannot load database schema: ' . $e->getMessage());
//        }
//
//        return $schema;
//    }

    /**
     * Get detailed information about a specific table
     */
//    public function getTableInfo(string $tableName): array
//    {
//        if (!in_array($tableName, $this->sqlSecurity->getAllowedTables())) {
//            throw new \InvalidArgumentException("Table {$tableName} is not allowed");
//        }
//
//        try {
//            $schemaManager = $this->connection->createSchemaManager();
//
//            if (!$schemaManager->tablesExist([$tableName])) {
//                throw new \InvalidArgumentException("Table {$tableName} does not exist");
//            }
//
//            $table = $schemaManager->introspectTable($tableName);
//            $columns = [];
//
//            foreach ($table->getColumns() as $column) {
//                $columns[$column->getName()] = [
//                    'type' => $column->getType()->getName(),
//                    'nullable' => !$column->getNotnull(),
//                    'default' => $column->getDefault(),
//                    'comment' => $column->getComment()
//                ];
//            }
//
//            // Get foreign keys
//            $foreignKeys = [];
//            foreach ($table->getForeignKeys() as $foreignKey) {
//                $foreignKeys[] = [
//                    'columns' => $foreignKey->getLocalColumns(),
//                    'references_table' => $foreignKey->getForeignTableName(),
//                    'references_columns' => $foreignKey->getForeignColumns()
//                ];
//            }
//
//            // Get indexes
//            $indexes = [];
//            foreach ($table->getIndexes() as $index) {
//                $indexes[$index->getName()] = [
//                    'columns' => $index->getColumns(),
//                    'unique' => $index->isUnique(),
//                    'primary' => $index->isPrimary()
//                ];
//            }
//
//            return [
//                'name' => $tableName,
//                'columns' => $columns,
//                'foreign_keys' => $foreignKeys,
//                'indexes' => $indexes
//            ];
//
//        } catch (\Exception $e) {
//            $this->logger->error("Failed to get table info for {$tableName}", [
//                'error' => $e->getMessage()
//            ]);
//            throw new \RuntimeException("Cannot get table info: " . $e->getMessage());
//        }
//    }

    /**
     * Get sample data from a table (limited rows for LLM context)
     */
//    public function getSampleData(string $tableName, int $limit = 5): array
//    {
//        if (!in_array($tableName, $this->sqlSecurity->getAllowedTables())) {
//            throw new \InvalidArgumentException("Table {$tableName} is not allowed");
//        }
//
//        try {
//            $sql = "SELECT * FROM {$tableName} LIMIT :limit";
//            $statement = $this->connection->prepare($sql);
//            $statement->bindValue('limit', $limit, \PDO::PARAM_INT);
//            $result = $statement->executeQuery();
//
//            return $result->fetchAllAssociative();
//
//        } catch (\Exception $e) {
//            $this->logger->error("Failed to get sample data for {$tableName}", [
//                'error' => $e->getMessage()
//            ]);
//            return [];
//        }
//    }

    /**
     * Clear the schema cache
     */
//    public function clearCache(): void
//    {
//        $this->cachedSchema = [];
//        $this->lastCacheUpdate = null;
//        $this->logger->info('Database schema cache cleared');
//    }

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

//    private function isCacheValid(): bool
//    {
//        if (empty($this->cachedSchema) || $this->lastCacheUpdate === null) {
//            return false;
//        }
//
//        $now = new \DateTimeImmutable();
//        $cacheAge = $now->getTimestamp() - $this->lastCacheUpdate->getTimestamp();
//
//        return $cacheAge < $this->cacheTimeout;
//    }
}
