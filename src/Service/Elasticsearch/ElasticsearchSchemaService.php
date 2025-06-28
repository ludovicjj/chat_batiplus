<?php

namespace App\Service\Elasticsearch;

use JoliCode\Elastically\Client;

readonly class ElasticsearchSchemaService
{
    public function __construct(
        private Client $elasticClient,
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
                $additionalFields = [];
                if (isset($fieldConfig['fields']['keyword'])) {
                    $additionalFields[] = ".keyword for exact match";
                }
                if (isset($fieldConfig['fields']['normalized'])) {
                    $additionalFields[] = ".normalized for case-insensitive search";
                }
                if (!empty($additionalFields)) {
                    $description .= " (has " . implode(', ', $additionalFields) . ")";
                }
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
                // === IDENTIFIANTS ET RÉFÉRENCES AFFAIRE ===
                'caseId (integer) - unique identifier of the case',
                'caseReference (keyword) - exact case reference (ex: 94P0237518)',
                'caseShortReference (keyword) - short reference code',

                // === INFORMATIONS AFFAIRE ===
                'caseTitle (text + .keyword + .normalized) - case title/description, full-text searchable',
                'caseClient (text + .keyword + .normalized) - client name, full-text searchable',
                'caseAgency (text + .keyword + .normalized) - agency name, full-text searchable',
                'caseManager (text + .keyword + .normalized) - manager name, full-text searchable',
                'caseStatus (keyword + .normalized) - status for exact filtering',

                // === MÉTRIQUES CALCULÉES ===
                'reportsCount (integer) - computed total reports count',
                'reviewsCount (integer) - computed total reviews count',
                'hasReports (boolean) - has reports flag',
                'hasReviews (boolean) - has reviews flag',
                'hasObservations (boolean) - has observations flag',

                // === RECHERCHE GLOBALE ===
                'searchableText (text + .suggest) - global searchable text field',

                // === FACETTES ===
                'reportTypes (keyword) - array of report types present',
                'reviewValues (keyword) - array of review values present',
                'reviewGroups (keyword) - array of review domains present',

                // === STRUCTURE HIÉRARCHIQUE DES RAPPORTS ===
                'reports (nested) - nested reports array with:',
                '  reports.reportId (integer) - report ID',
                '  reports.reportReference (keyword + .normalized) - report reference (ex: AD-001)',
                '  reports.reportImported (boolean) - import status',
                '  reports.reportIsDraft (boolean) - draft status',
                '  reports.reportIsValidated (boolean) - validation status',
                '  reports.reportCreatedAt (date) - report creation date',
                '  reports.reportValidatedAt (date) - report validation date',
                '  reports.reportTypeName (text + .keyword + .normalized) - report type name',
                '  reports.reportTypeCode (keyword) - report type code',
                '  reports.reportS3Path (keyword) - S3 storage path',
                '  reports.reportReviewsCount (integer) - number of reviews in report',
                '  reports.reportHasReviews (boolean) - has reviews flag',

                // === STRUCTURE DES AVIS (nested dans reports) ===
                '  reports.reportReviews (nested) - nested reviews array with:',
                '    reports.reportReviews.reviewId (integer) - review ID',
                '    reports.reportReviews.reviewNumber (keyword) - review number',
                '    reports.reportReviews.reviewObservation (text + .keyword) - review observation content',
                '    reports.reportReviews.reviewCreatedBy (text + .keyword) - reviewer name',
                '    reports.reportReviews.reviewPosition (integer) - review position',
                '    reports.reportReviews.reviewVisitedAt (date) - visit date',
                '    reports.reportReviews.reviewCreatedAt (date) - review creation date',
                '    reports.reportReviews.reviewDomain (keyword + .normalized) - review domain (ex: Portes, SSI)',
                '    reports.reportReviews.reviewValueCode (keyword + .normalized) - review value code (F, D, S, PM)',
                '    reports.reportReviews.reviewValueName (keyword + .normalized) - review value name (Favorable, Défavorable, Suspendu)',
            ]
        ];
    }
}