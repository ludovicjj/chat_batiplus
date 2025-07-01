<?php

namespace App\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

class VectorType extends Type
{
    public const VECTOR = 'vector';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        $length = $column['length'] ?? 384; // Default pour all-MiniLM-L6-v2
        return "vector($length)";
    }

    /**
     * Convert from PostgreSQL vector format to PHP array
     */
    public function convertToPHPValue($value, AbstractPlatform $platform): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        // PostgreSQL return "[1.0,2.0,3.0]" -> [1.0, 2.0, 3.0]
        $cleaned = trim($value, '[]');

        if (empty($cleaned)) {
            return [];
        }

        return array_map('floatval', explode(',', $cleaned));
    }

    /**
     * Convert from PHP array to PostgreSQL vector format
     */
    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if ($value === null || (is_array($value) && empty($value))) {
            return null;
        }

        if (!is_array($value)) {
            throw new \InvalidArgumentException('Expected array for vector type, got ' . gettype($value));
        }

        // [1.0, 2.0, 3.0] -> "[1.0,2.0,3.0]"
        return '[' . implode(',', $value) . ']';
    }

    public function getName(): string
    {
        return self::VECTOR;
    }

    /**
     * Whether this type maps to an already mapped database type
     */
    public function getMappedDatabaseTypes(AbstractPlatform $platform): array
    {
        return ['vector'];
    }

    /**
     * Whether this type should be searched for in the mapped types
     */
    public function requiresSQLCommentTypeHint(AbstractPlatform $platform): bool
    {
        return true;
    }
}