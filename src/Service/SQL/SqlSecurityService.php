<?php

declare(strict_types=1);

namespace App\Service\SQL;

use App\Exception\UnsafeSqlException;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Service responsible for validating and securing SQL queries
 * Only allows safe SELECT queries on whitelisted tables
 */
class SqlSecurityService
{
    private array $allowedTables;
    private array $dangerousKeywords = [
        'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'CREATE', 'TRUNCATE',
        'REPLACE', 'MERGE', 'CALL', 'EXEC', 'EXECUTE', 'GRANT', 'REVOKE',
        'LOAD', 'OUTFILE', 'DUMPFILE', 'INTO', 'INFORMATION_SCHEMA',
        'MYSQL', 'PERFORMANCE_SCHEMA', 'SYS', 'SHOW', 'DESCRIBE', 'EXPLAIN'
    ];

    private array $allowedFunctions = [
        'COUNT', 'SUM', 'AVG', 'MAX', 'MIN', 'UPPER', 'LOWER', 'SUBSTRING',
        'CONCAT', 'DATE', 'YEAR', 'MONTH', 'DAY', 'NOW', 'CURDATE', 'CURTIME',
        'DATEDIFF', 'COALESCE', 'IFNULL', 'NULLIF', 'ROUND', 'FLOOR', 'CEIL'
    ];

    public function __construct(
        private readonly LoggerInterface $logger,
        #[Autowire('%env(ALLOWED_TABLES)%')] string $allowedTables = ''
    ) {
        $this->allowedTables = array_filter(
            array_map('trim', explode(',', $allowedTables))
        );
    }

    /**
     * Validates and sanitizes an SQL query
     *
     * @throws UnsafeSqlException if the query is deemed unsafe
     */
    public function validateQuery(string $query): string
    {
        $query = trim($query);

        // Log the query attempt
        $this->logger->info('SQL query validation attempt', [
            'query' => $query,
            'timestamp' => new DateTimeImmutable()
        ]);

        // Remove comments and normalize whitespace
        $cleanQuery = $this->cleanQuery($query);

        // Check if query starts with SELECT
        if (!$this->startsWithSelect($cleanQuery)) {
            $this->logger->warning('Non-SELECT query blocked', ['query' => $query]);
            throw new UnsafeSqlException('Only SELECT queries are allowed');
        }

        // Check for dangerous keywords
        if ($this->containsDangerousKeywords($cleanQuery)) {
            $this->logger->warning('Dangerous keywords detected', ['query' => $query]);
            throw new UnsafeSqlException('Query contains forbidden keywords');
        }

        // Validate table names
        if (!$this->validateTableNames($cleanQuery)) {
            $this->logger->warning('Unauthorized table access attempt', ['query' => $query]);
            throw new UnsafeSqlException('Query accesses unauthorized tables');
        }

        // Validate functions
        if (!$this->validateFunctions($cleanQuery)) {
            $this->logger->warning('Unauthorized function usage', ['query' => $query]);
            throw new UnsafeSqlException('Query uses unauthorized functions');
        }

        $this->logger->info('SQL query validated successfully', ['query' => $query]);

        return $cleanQuery;
    }

    private function cleanQuery(string $query): string
    {
        // Remove SQL comments
        $query = preg_replace('/--.*$/m', '', $query);
        $query = preg_replace('/\/\*.*?\*\//s', '', $query);

        // Normalize whitespace
        $query = preg_replace('/\s+/', ' ', $query);

        return trim($query);
    }

    private function startsWithSelect(string $query): bool
    {
        return stripos($query, 'SELECT') === 0;
    }

    private function containsDangerousKeywords(string $query): bool
    {
        $upperQuery = strtoupper($query);

        foreach ($this->dangerousKeywords as $keyword) {
            // Use word boundaries to avoid false positives
            // Exception: allow IS NULL and IS NOT NULL
            if ($keyword === 'NULL' && (str_contains($upperQuery, 'IS NULL') || str_contains($upperQuery, 'IS NOT NULL'))) {
                continue;
            }
            
            if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/', $upperQuery)) {
                return true;
            }
        }

        return false;
    }

    private function validateTableNames(string $query): bool
    {
        // Extract table names from FROM and JOIN clauses
        $pattern = '/(?:FROM|JOIN)\s+([a-zA-Z_][a-zA-Z0-9_]*)/i';
        preg_match_all($pattern, $query, $matches);

        if (empty($matches[1])) {
            return false; // No tables found
        }

        foreach ($matches[1] as $tableName) {
            if (!in_array(strtolower($tableName), array_map('strtolower', $this->allowedTables))) {
                return false;
            }
        }

        return true;
    }

    private function validateFunctions(string $query): bool
    {
        // Extract function calls
        $pattern = '/([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/i';
        preg_match_all($pattern, $query, $matches);

        if (empty($matches[1])) {
            return true; // No functions used
        }

        foreach ($matches[1] as $functionName) {
            if (!in_array(strtoupper($functionName), $this->allowedFunctions)) {
                return false;
            }
        }

        return true;
    }

    public function getAllowedTables(): array
    {
        return $this->allowedTables;
    }
}
