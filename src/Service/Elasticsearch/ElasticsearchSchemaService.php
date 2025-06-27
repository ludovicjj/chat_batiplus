<?php

namespace App\Service\Elasticsearch;

use JoliCode\Elastically\Client;

class ElasticsearchSchemaService
{
    public function __construct(
        private readonly Client $elasticClient,
    ) {}

    public function getMappingsStructure(): array
    {
        return $this->generateMappingsStructure();
    }

    private function generateMappingsStructure(): array
    {
        try {
            // Get mapping for client_case index
            $response = $this->elasticClient->indices()->getMapping(['index' => 'client_case']);
            $mapping = $response->asArray();
            $indexName = array_key_first($mapping);


            $mapping = $mapping[$indexName]['mappings']['properties'] ?? [];

            return [
                'client_case' => $this->formatMappingForLLM($mapping)
            ];

        } catch (\Exception $e) {
            // Fallback to static mapping description
            return $this->getStaticMappingDescription();
        }
    }

    private function formatMappingForLLM(array $mapping): array
    {
        $formatted = [];

        foreach ($mapping as $fieldName => $fieldConfig) {
            $fieldInfo = $this->extractFieldInfo($fieldName, $fieldConfig);
            $formatted[] = $fieldInfo;
        }

        return $formatted;
    }

    private function extractFieldInfo(string $fieldName, array $fieldConfig): string
    {
        $type = $fieldConfig['type'] ?? 'unknown';
        $description = "";

        switch ($type) {
            case 'keyword':
                $description = "exact match, filtering, aggregations";
                break;
            case 'text':
                $description = "full-text search, analyzed";
                if (isset($fieldConfig['fields']['keyword'])) {
                    $description .= " (has .keyword for exact match)";
                }
                break;
            case 'integer':
            case 'long':
                $description = "numeric, range queries, aggregations";
                break;
            case 'date':
                $description = "date/time, range queries, date aggregations";
                break;
            case 'boolean':
                $description = "true/false filtering";
                break;
            case 'nested':
                $description = "nested objects - use nested queries";
                if (isset($fieldConfig['properties'])) {
                    $nestedFields = array_keys($fieldConfig['properties']);
                    $description .= " (nested fields: " . implode(', ', $nestedFields) . ")";
                }
                break;
            default:
                $description = $type;
        }

        return "{$fieldName} ({$type}) - {$description}";
    }

    private function getStaticMappingDescription(): array
    {
        return [
            'client_case' => [
                'id (integer) - unique identifier',
                'reference (keyword) - exact client case reference',
                'short_reference (keyword) - short reference code',
                'projectName (text) - project name, full-text searchable',
                'clientName (text) - client name, full-text searchable',
                'agencyName (text) - agency name, full-text searchable',
                'managerEmail (keyword) - manager email for exact filtering',
                'managerName (text) - manager name, full-text searchable',
                'statusName (keyword) - status for exact filtering',
                'phase (keyword) - project phase (conception, réalisation, etc.)',
                'createdAt (date) - creation date',
                'updatedAt (date) - last update date',
                'deletedAt (date) - deletion date (null if not deleted)',
                'isEnabled (boolean) - active status',
                'reports (nested) - nested reports array with:',
                '  reports.id (integer) - report ID',
                '  reports.filename (keyword) - report filename',
                '  reports.reference (keyword) - report reference',
                '  reports.imported (boolean) - import status',
                '  reports.createdAt (date) - report creation date',
                '  reports.avis (nested) - nested avis array with:',
                '    reports.avis.id (integer) - avis ID',
                '    reports.avis.content (text) - avis content',
                '    reports.avis.rating (keyword) - rating (A, B, C, D)',
                '    reports.avis.userName (text) - user name',
                '    reports.avis.createdAt (date) - avis creation date',
                'totalReports (integer) - computed total reports count',
                'totalAvis (integer) - computed total avis count',
                'hasReports (boolean) - has reports flag',
                'hasAvis (boolean) - has avis flag'
            ]
        ];
    }
}