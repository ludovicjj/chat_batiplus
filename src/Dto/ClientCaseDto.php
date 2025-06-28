<?php

namespace App\Dto;

readonly class ClientCaseDto
{
    public function __construct(
        public int     $id,
        public ?string $reference,
        public ?string $shortReference,
        public ?string $projectName,
        public ?string $agencyName,
        public ?string $clientName,
        public ?string $statusName,
        public ?string $managerName,
        public array   $reports = [],
    ) {}

    /**
     * Crée un DTO depuis les données du StreamingDataFetcher
     */
    public static function fromStreamingData(array $data): self
    {
        return new self(
            id: (int) $data['id'],
            reference: $data['reference'] ?? null,
            shortReference: $data['shortReference'] ?? null,
            projectName: $data['projectName'] ?? null,
            agencyName: $data['agencyName'] ?? null,
            clientName: $data['clientName'] ?? null,
            statusName: $data['statusName'] ?? null,
            managerName: $data['managerName'] ?? null,
            reports: $data['reports'] ?? [],
        );
    }

    /**
     * Convertit en document Elasticsearch optimisé
     */
    public function toElasticsearchDocument(): array
    {
        $transformedReports = $this->transformReports();
        
        return [
            // Identifiants et références
            'caseId' => $this->id,
            'caseReference' => $this->reference,
            'caseShortReference' => $this->shortReference,
            
            // Informations générales du dossier
            'caseTitle' => $this->projectName,
            'caseAgency' => $this->agencyName,
            'caseClient' => $this->clientName,
            'caseStatus' => $this->statusName,
            'caseManager' => $this->managerName,
            
            // Structure hiérarchique des rapports
            'reports' => $transformedReports,
            
            // Métriques calculées pour optimiser les requêtes
            'reportsCount' => count($transformedReports),
            'reviewsCount' => $this->countTotalReviews($transformedReports),
            'hasReports' => count($transformedReports) > 0,
            'hasReviews' => $this->countTotalReviews($transformedReports) > 0,
            'hasObservations' => $this->hasObservations($transformedReports),
            
            // Données textuelles pour la recherche globale
            'searchableText' => $this->buildSearchableText($transformedReports),
            
            // Champs calculés pour les facettes et agrégations
            'reportTypes' => $this->extractReportTypes($transformedReports),
            'reviewValues' => $this->extractReviewValues($transformedReports),
            'reviewGroups' => $this->extractReviewGroups($transformedReports),
        ];
    }

    /**
     * Convertit en array simple (méthode existante conservée)
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'shortReference' => $this->shortReference,
            'projectName' => $this->projectName,
            'agencyName' => $this->agencyName,
            'clientName' => $this->clientName,
            'statusName' => $this->statusName,
            'managerName' => $this->managerName,
            'reports' => $this->reports
        ];
    }

    /**
     * Transforme les rapports pour Elasticsearch
     */
    private function transformReports(): array
    {
        if (empty($this->reports)) {
            return [];
        }

        return array_map(function (array $report) {
            $transformedReviews = $this->transformReviews($report['reviews'] ?? []);
            
            return [
                'reportId' => (int) $report['id'],
                'reportReference' => $report['reference'] ?? null,
                'reportTypeName' => $report['reportTypeName'] ?? null,
                'reportTypeCode' => $report['reportTypeCode'] ?? null,
                'reportS3Path' => $report['s3Path'] ?? null,

                // Report Status
                'reportImported' => (bool) ($report['imported'] ?? false),
                'reportIsDraft' => (bool) ($report['isDraft'] ?? false),
                'reportIsValidated' => !($report['isDraft'] ?? false),
                'reportCreatedAt' => $this->formatDate($report['createdAt'] ?? null),
                'reportValidatedAt' => $this->formatDate($report['validatedAt'] ?? null),
                
                // Avis du rapport
                'reportReviews' => $transformedReviews,
                
                // Métriques par rapport
                'reportReviewsCount' => count($transformedReviews),
                'hasReviews' => count($transformedReviews) > 0,
            ];
        }, $this->reports);
    }

    /**
     * Transforme les avis pour Elasticsearch
     */
    private function transformReviews(array $reviews): array
    {
        if (empty($reviews)) {
            return [];
        }

        return array_map(function (array $review) {
            return [
                'reviewId' => (int) $review['id'],
                'reviewNumber' => $review['number'] ?? null,
                'reviewObservation' => $this->cleanHtmlObservation($review['observation'] ?? ''),
                'reviewCreatedBy' => $review['createdBy'] ?? null,
                'reviewPosition' => (int) ($review['position'] ?? 0),

                // Date
                'reviewVisitedAt' => $this->formatDate($review['visitedAt'] ?? null),
                'reviewCreatedAt' => $this->formatDate($review['createdAt'] ?? null),

                // Classification
                'reviewDomain' => $review['reviewGroupName'] ?? null,
                'reviewValueCode' => $review['reviewValueName'] ?? null,
                'reviewValueName' => $this->decodeReviewValue($review['reviewValueName'])
            ];
        }, $reviews);
    }

    private function decodeReviewValue(?string $code): ?string
    {
        return match($code) {
            'F' => 'Favorable',
            'S' => 'Suspendu',
            'D' => 'Défavorable',
            'PM' => 'Pour mémoire',
            'SO' => 'Sans objet',
            'HM' => 'Hors mission',
            'C' => 'Conforme',
            'NC' => 'Non conforme',
            default => $code
        };
    }

    /**
     * Nettoie les observations HTML
     */
    private function cleanHtmlObservation(string $observation): string
    {
        if (empty($observation)) {
            return '';
        }
        
        // Supprimer les balises HTML mais garder le contenu
        $cleaned = strip_tags($observation);
        
        // Nettoyer les espaces multiples et caractères spéciaux
        $cleaned = preg_replace('/\s+/', ' ', $cleaned);
        $cleaned = trim($cleaned);
        
        return $cleaned;
    }

    /**
     * Formate les dates pour Elasticsearch
     */
    private function formatDate(?string $date): ?string
    {
        if (empty($date)) {
            return null;
        }
        
        try {
            $dateTime = new \DateTime($date);
            return $dateTime->format('Y-m-d\TH:i:s\Z');
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Compte le nombre total d'avis
     */
    private function countTotalReviews(array $reports): int
    {
        if (empty($reports)) {
            return 0;
        }

        return array_sum(array_map(fn($report) => count($report['reportReviews'] ?? []), $reports));
    }

    /**
     * Construit un texte searchable global
     */
    private function buildSearchableText(array $reports): string
    {
        $searchableElements = array_filter([
            $this->reference,
            $this->shortReference,
            $this->projectName,
            $this->agencyName,
            $this->clientName,
            $this->managerName
        ]);
        
        // Ajouter les références des rapports
        foreach ($reports as $report) {
            if (!empty($report['reference'])) {
                $searchableElements[] = $report['reference'];
            }
            if (!empty($report['filename'])) {
                $searchableElements[] = $report['filename'];
            }
        }
        
        return implode(' ', $searchableElements);
    }

    /**
     * Extrait les types de rapports uniques
     */
    private function extractReportTypes(array $reports): array
    {
        if (empty($reports)) {
            return [];
        }

        $types = [];
        foreach ($reports as $report) {
            if (!empty($report['reportTypeName'])) {
                $types[] = $report['reportTypeName'];
            }
            if (!empty($report['reportTypeCode'])) {
                $types[] = $report['reportTypeCode'];
            }
        }
        
        return array_values(array_unique($types));
    }

    /**
     * Extrait les valeurs d'avis uniques
     */
    private function extractReviewValues(array $reports): array
    {
        if (empty($reports)) {
            return [];
        }

        $values = [];
        foreach ($reports as $report) {
            foreach ($report['reportReviews'] ?? [] as $review) {
                if (!empty($review['reviewValueName'])) {
                    $values[] = $review['reviewValueName'];
                }
            }
        }
        
        return array_values(array_unique($values));
    }

    /**
     * Extrait les groupes d'avis uniques
     */
    private function extractReviewGroups(array $reports): array
    {
        if (empty($reports)) {
            return [];
        }

        $groups = [];
        foreach ($reports as $report) {
            foreach ($report['reportReviews'] ?? [] as $review) {
                if (!empty($review['reviewDomain'])) {
                    $groups[] = $review['reviewDomain'];
                }
            }
        }
        
        return array_values(array_unique($groups));
    }

    /**
     * Vérifie s'il y a des observations
     */
    private function hasObservations(array $reports): bool
    {
        if (empty($reports)) {
            return false;
        }

        foreach ($reports as $report) {
            foreach ($report['reportReviews'] ?? [] as $review) {
                if (!empty($review['reviewObservation'])) {
                    return true;
                }
            }
        }
        
        return false;
    }
}
