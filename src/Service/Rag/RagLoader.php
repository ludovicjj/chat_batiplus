<?php

namespace App\Service\Rag;

use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class RagLoader
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] protected string $projectDir,
    ) {
    }

    public function loadAllExamples(): array
    {
        $data = $this->loadRawData();
        return $data['examples'] ?? [];
    }

    /**
     * Load raw JSON data
     */
    private function loadRawData(): array
    {
        $jsonPath = $this->projectDir . '/config/rag/dataset_examples.json';

        if (!file_exists($jsonPath)) {
            throw new InvalidArgumentException("RAG examples file not found: {$jsonPath}");
        }

        $jsonContent = file_get_contents($jsonPath);
        if ($jsonContent === false) {
            throw new InvalidArgumentException("Cannot read RAG examples file: {$jsonPath}");
        }

        $data = json_decode($jsonContent, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException("Invalid JSON in RAG examples file: " . json_last_error_msg());
        }

        return $data;
    }

    public function getStats(): array
    {
        $examples = $this->loadAllExamples();

        $stats = [
            'total_examples' => count($examples),
            'by_operation' => [],
            'by_entity' => [],
            'by_complexity' => [],
            'version' => $this->loadRawData()['version'] ?? 'unknown'
        ];

        foreach ($examples as $example) {
            $metadata = $example['metadata'] ?? [];

            // Stats by operation
            $operation = $metadata['operation'] ?? 'unknown';
            $stats['by_operation'][$operation] = ($stats['by_operation'][$operation] ?? 0) + 1;

            // Stats by entity
            $entity = $metadata['entity_type'] ?? 'unknown';
            $stats['by_entity'][$entity] = ($stats['by_entity'][$entity] ?? 0) + 1;

            // Stats by complexity
            $complexity = $metadata['complexity'] ?? 'unknown';
            $stats['by_complexity'][$complexity] = ($stats['by_complexity'][$complexity] ?? 0) + 1;
        }

        return $stats;
    }
}