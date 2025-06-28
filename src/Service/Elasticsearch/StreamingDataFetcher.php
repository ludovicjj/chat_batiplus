<?php

namespace App\Service\Elasticsearch;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use PDO;
use Psr\Log\LoggerInterface;

readonly class StreamingDataFetcher
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * @throws Exception
     */
    public function streamClientCases(int $batchSize = 1, ?int $startFromId = null): \Generator
    {
        $offset = 0;

        while (true) {
            // Fetch ClientCase by batch
            $clientCasesBatch = $this->fetchClientCasesBatch($batchSize, $offset, $startFromId);

            if (empty($clientCasesBatch)) {
                break;
            }

            // Traiter chaque ClientCase individuellement
            foreach ($clientCasesBatch as $clientCaseBasic) {
                $clientCaseComplete = $this->fetchClientCaseComplete($clientCaseBasic['id']);

                if ($clientCaseComplete) {
                    yield $clientCaseComplete;
                }


                // Clear memory between each clientCase
                unset($clientCaseComplete);
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }

            $offset += $batchSize;

            // Sécurité : pause entre les batches pour éviter la surcharge
            if ($batchSize > 10) {
                usleep(50000); // 50ms de pause
            }
        }
    }

    /**
     * Fetch ClientCase by Batch
     */
    private function fetchClientCasesBatch(int $limit, int $offset, ?int $startFromId = null): array
    {
        $connection = $this->entityManager->getConnection();
        try {
            $sql = "
                SELECT 
                    cc.id,
                    cc.reference
                FROM client_case cc
                WHERE cc.deleted_at IS NULL
                ORDER BY cc.id ASC
                LIMIT :limit OFFSET :offset
            ";

            if ($startFromId) {
                $sql .= " AND cc.id > :start_from_id";
            }

            $result = $connection->executeQuery($sql, [
                'limit' => $limit,
                'offset' => $offset
            ], [
                'limit' => PDO::PARAM_INT,
                'offset' => PDO::PARAM_INT
            ]);

            return $result->fetchAllAssociative();
        } catch (Exception $e) {
            dd('Batch request ClientCase Failed : ' . $e->getMessage());
        }
    }

    /**
     * Fetch clientCase with Report and Reviews
     * @throws Exception
     */
    public function fetchClientCaseComplete(int $clientCaseId): ?array
    {
        // ÉTAPE 1: ClientCase de base
        $clientCase = $this->fetchSingleClientCase($clientCaseId);
        if (!$clientCase) {
            return null;
        }

        // ÉTAPE 2: Reports de ce ClientCase
        $reports = $this->fetchReportsForClientCase($clientCaseId);

        // ÉTAPE 3: Reviews pour tous ces Reports (SANS limite)
        if (!empty($reports)) {
            $reportIds = array_column($reports, 'id');
            $reviews = $this->fetchReviewsForReports($reportIds);
            $reports = $this->assembleReportsWithReviews($reports, $reviews);
        }

        $clientCase['reports'] = $reports;

        return $clientCase;
    }

    /**
     * 1. Fetch 1 ClientCase
     * @throws Exception
     */
    private function fetchSingleClientCase(int $clientCaseId): ?array
    {
        $connection = $this->getConnection();

        try {
            $sql = "
                SELECT 
                    cc.id,
                    cc.reference,
                    cc.short_reference as shortReference,
                    cc.project_name as projectName,
                    a.name as agencyName,
                    c.company_name as clientName,
                    ccs.name as statusName,
                    TRIM(CONCAT(COALESCE(col.firstname, ''), ' ', COALESCE(col.lastname, ''))) as managerName
                FROM client_case cc
                LEFT JOIN agency a ON cc.agency_id = a.id
                LEFT JOIN client c ON cc.client_id = c.id
                LEFT JOIN client_case_status ccs ON cc.client_case_status_id = ccs.id
                LEFT JOIN collaborator col ON cc.manager_id = col.id
                WHERE cc.id = :client_case_id AND cc.deleted_at IS NULL
            ";

            $result = $connection->executeQuery($sql, [
                'client_case_id' => $clientCaseId
            ], [
                'client_case_id' => PDO::PARAM_INT
            ]);

            $clientCase = $result->fetchAssociative();
            return $clientCase ?: null;
        } catch (Exception $e) {
            throw new Exception('Failed to fetch ClientCase, error : ' . $e->getMessage());
        }
    }

    /**
     *  2. Fetch all Reports for this ClientCase
     * @throws Exception
     */
    private function fetchReportsForClientCase(int $clientCaseId): array
    {
        $connection = $this->getConnection();

        try {
            $sql = "
            SELECT 
                r.id,
                r.reference,
                r.filename,
                r.imported,
                r.client_case_id,
                r.is_draft as isDraft,
                r.created_at as createdAt,
                r.validated_at as validatedAt,
                rt.name as reportTypeName,
                rt.code as reportTypeCode
            FROM report r
            LEFT JOIN report_type rt ON r.report_type_id = rt.id
            WHERE r.client_case_id = :client_case_id
            ORDER BY r.created_at DESC
        ";

            $result = $connection->executeQuery($sql, [
                'client_case_id' => $clientCaseId
            ], [
                'client_case_id' => PDO::PARAM_INT
            ]);

            $reports = $result->fetchAllAssociative();

            // Ajouter le chemin S3 à chaque report
            foreach ($reports as &$report) {
                $report['s3Path'] = $this->buildReportS3Path($report);
            }

            return $reports;
        } catch (Exception $e) {
            throw new Exception('Failed to fetch report, error : ' . $e->getMessage());
        }
    }

    /**
     * 3. Fetch all clientCaseReview For given ReportIds
     * @throws Exception
     */
    private function fetchReviewsForReports(array $reportIds): array
    {
        if (empty($reportIds)) {
            return [];
        }

        $connection = $this->getConnection();

        try{
            $sql = "
            SELECT 
                ccr.id,
                ccr.number,
                ccr.observation,
                ccr.visited_at as visitedAt,
                ccr.created_at as createdAt,
                ccr.position,
                ccr.report_id as reportId,
                TRIM(CONCAT(COALESCE(rev_col.firstname, ''), ' ', COALESCE(rev_col.lastname, ''))) as createdBy,
                ccrg.id as reviewGroupId,
                ccrg.name as reviewGroupName,
                ccrv.id as reviewValueId,
                ccrv.name as reviewValueName,
                ROW_NUMBER() OVER (PARTITION BY ccr.report_id ORDER BY ccr.position ASC) as review_rank
            FROM client_case_review ccr
            LEFT JOIN collaborator rev_col ON ccr.created_by_id = rev_col.id
            LEFT JOIN client_case_review_group ccrg ON ccr.client_case_review_group_id = ccrg.id
            LEFT JOIN client_case_review_value ccrv ON ccr.client_case_review_value_id = ccrv.id
            WHERE ccr.deleted_at IS NULL 
              AND ccr.report_id IN (?)
            ORDER BY ccr.report_id, ccr.position ASC
        ";

            $params = [$reportIds];
            $types = [ArrayParameterType::INTEGER];

            $result = $connection->executeQuery($sql, $params, $types);

            return $result->fetchAllAssociative();
        } catch (Exception $e) {
            throw new Exception('Failed to fetch reviews, error : ' . $e->getMessage());
        }
    }

    public function analyzeClientCaseRisk(int $clientCaseId): array
    {
        $connection = $this->getConnection();
        $sql = "
            SELECT 
                COUNT(DISTINCT r.id) as reports_count,
                COUNT(ccr.id) as total_reviews,
                MAX(report_stats.reviews_per_report) as max_reviews_per_report
            FROM client_case cc
            LEFT JOIN report r ON cc.id = r.client_case_id
            LEFT JOIN client_case_review ccr ON r.id = ccr.report_id AND ccr.deleted_at IS NULL
            LEFT JOIN (
                SELECT report_id, COUNT(*) as reviews_per_report
                FROM client_case_review 
                WHERE deleted_at IS NULL 
                GROUP BY report_id
            ) report_stats ON r.id = report_stats.report_id
            WHERE cc.id = :client_case_id
        ";

        $result = $connection->executeQuery(
            $sql,
            ['client_case_id' => $clientCaseId],
            ['client_case_id' => PDO::PARAM_INT]
        );

        $stats = $result->fetchAssociative();
        return [
            'risk_level' => $this->calculateRiskLevel($stats),
            'stats' => $stats
        ];
    }

    private function calculateRiskLevel(array $stats): string
    {
        $totalReviews = $stats['total_reviews'] ?? 0;
        $maxReviewsPerReport = $stats['max_reviews_per_report'] ?? 0;

        if ($totalReviews > 5000 || $maxReviewsPerReport > 200) {
            return 'CRITICAL';
        } elseif ($totalReviews > 1000 || $maxReviewsPerReport > 100) {
            return 'HIGH';
        } elseif ($totalReviews > 200 || $maxReviewsPerReport > 50) {
            return 'MEDIUM';
        } else {
            return 'LOW';
        }
    }

    /**
     * Assembler Reports avec leurs Reviews
     */
    private function assembleReportsWithReviews(array $reports, array $reviews): array
    {
        // Index des reviews par report_id
        $reviewsByReport = [];
        foreach ($reviews as $review) {
            $reportId = $review['reportId'];
            unset($review['reportId'], $review['review_rank']); // Nettoyer les champs temporaires
            $reviewsByReport[$reportId][] = $review;
        }

        // Ajouter les reviews à chaque report
        foreach ($reports as &$report) {
            $report['reviews'] = $reviewsByReport[$report['id']] ?? [];
        }

        return $reports;
    }

    /**
     * Build S3 path for Report
     */
    private function buildReportS3Path(array $reportData): string
    {
        $imported = (bool) $reportData['imported'];
        $clientCaseId = $reportData['client_case_id'];

        if ($imported) {
            $filename = $reportData['filename'] ?? $reportData['reference'];
            return "report/{$clientCaseId}/{$filename}.pdf";
        }

        // Récupérer le shortReference du ClientCase (il faut l'ajouter à la requête report)
        $connection = $this->entityManager->getConnection();
        $shortReference = $connection->fetchOne(
            "SELECT short_reference FROM client_case WHERE id = ?",
            [$clientCaseId]
        );

        $reference = $reportData['reference'];
        return "report/{$clientCaseId}/{$shortReference}-{$reference}.pdf";
    }


    /**
     * @throws Exception
     */
    public function countTotalClientCases(?int $startFromId = null): int
    {
        $connection = $this->getConnection();

        $sql = "SELECT COUNT(*) as total FROM client_case WHERE deleted_at IS NULL";
        $params = [];
        $types = [];

        if ($startFromId) {
            $sql .= " AND id > :start_from_id";
            $params['start_from_id'] = $startFromId;
            $types['start_from_id'] = \PDO::PARAM_INT;
        }


        $result = $connection->executeQuery($sql, $params, $types);
        $count = $result->fetchAssociative();
        $total = (int) ($count['total'] ?? 0);

        if ($total === 0) {
            throw new Exception('Total client case not found');
        }

        return $total;
    }

    private function getConnection(): Connection
    {
        return $this->entityManager->getConnection();
    }

    /**
     * 📊 MÉTHODE UTILITAIRE : Estimation du travail total
     */
    public function estimateWorkload(): array
    {
        $connection = $this->entityManager->getConnection();

        $sql = "
            SELECT 
                COUNT(DISTINCT cc.id) as total_client_cases,
                COUNT(DISTINCT r.id) as total_reports,
                COUNT(ccr.id) as total_reviews,
                AVG(case_stats.reports_per_case) as avg_reports_per_case,
                AVG(report_stats.reviews_per_report) as avg_reviews_per_report,
                MAX(report_stats.reviews_per_report) as max_reviews_per_report
            FROM client_case cc
            LEFT JOIN report r ON cc.id = r.client_case_id
            LEFT JOIN client_case_review ccr ON r.id = ccr.report_id AND ccr.deleted_at IS NULL
            LEFT JOIN (
                SELECT client_case_id, COUNT(*) as reports_per_case
                FROM report 
                WHERE deleted_at IS NULL 
                GROUP BY client_case_id
            ) case_stats ON cc.id = case_stats.client_case_id
            LEFT JOIN (
                SELECT report_id, COUNT(*) as reviews_per_report
                FROM client_case_review 
                WHERE deleted_at IS NULL 
                GROUP BY report_id
            ) report_stats ON r.id = report_stats.report_id
            WHERE cc.deleted_at IS NULL
        ";

        return $connection->executeQuery($sql)->fetchAssociative();
    }
}