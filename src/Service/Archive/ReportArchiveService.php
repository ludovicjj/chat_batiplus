<?php

namespace App\Service\Archive;

use App\Service\S3Service;
use Exception;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use ZipArchive;

class ReportArchiveService
{
    public const TYPE_ES = 'ES';
    public const TYPE_SQL = 'SQL';

    public function __construct(
        protected S3Service                                  $s3Service,
        protected Filesystem                                 $filesystem,
        #[Autowire('%kernel.project_dir%')] private string   $projectDir
    ) {
    }

    /**
     * Create Archive, fetch and move in each file from S3
     * Finally upload archive into Public dir...
     * (yes I know this service is horrible, sorry)
     *
     * @param array $results
     * @return array
     */
    public function generateDownloadPackage(array $results, string $type = 'SQL'): array
    {
        // 1. Init Validation
        if (!$this->validateResult($results, $type)) {
            return [
                'success' => false,
                'error' => 'Aucun rapport trouvé pour votre demande.'
            ];
        }

        try {
            // 2. PRÉPARATION DES RÉPERTOIRES
            $tempDir = $this->createTempDirectory();
            $this->ensureDownloadsDirectoryExists();

            $zipFilename = 'reports_' . date('Y-m-d_H-i-s') . '_' . uniqid() . '.zip';
            $zipPath = $tempDir . '/' . $zipFilename;

            // 3. CRÉATION DU ZIP
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE) !== TRUE) {
                throw new RuntimeException('Cannot create ZIP file');
            }

            // 4. INITIALISATION DES COMPTEURS
            $downloadedCount = 0;
            $errorCount = 0;
            $errors = [];
            $totalSize = 0;

            if ($type === 'ES') {
                foreach ($results['results'] as $result) {
                    $data = $result['data'];
                    $clientCaseId = $result['id'];

                    foreach ($data['reports'] ?? [] as $report) {
                        try {
                            // 5A. UTILISATION DIRECTE DU S3PATH
                            $s3Path = $report['reportS3Path'];

                            // 5B. GÉNÉRATION DU NOM DE FICHIER POUR LE ZIP
                            $fileName = $this->generateFileNameFromS3Path($s3Path, $clientCaseId);

                            // 5C. TÉLÉCHARGEMENT DEPUIS S3
                            $fileContent = $this->s3Service->downloadFile($s3Path);

                            // 5D. AJOUT AU ZIP OU GESTION D'ERREUR
                            if ($fileContent !== null) {
                                $zip->addFromString($fileName, $fileContent);
                                $downloadedCount++;
                                $totalSize += strlen($fileContent);
                            } else {
                                $errorCount++;
                                $errorMsg = "Fichier non trouvé sur S3: {$fileName} (clé: {$s3Path})";
                                $errors[] = $errorMsg;
                            }

                        } catch (Exception $e) {
                            $errorCount++;
                            $errorMsg = "Erreur pour le fichier {$s3Path}: " . $e->getMessage();
                            $errors[] = $errorMsg;
                        }
                    }
                }
            } else {
                try {
                    foreach ($results as $result) {
                        // 5A. CONSTRUCTION DE LA CLÉ S3
                        $reportKey = $this->buildReportKey($result);

                        // 5B. GÉNÉRATION DU NOM DE FICHIER POUR LE ZIP
                        $fileName = $this->generateFileName($result);

                        // 5C. TÉLÉCHARGEMENT DEPUIS S3
                        $fileContent = $this->s3Service->downloadFile($reportKey);

                        // 5D. AJOUT AU ZIP OU GESTION D'ERREUR
                        if ($fileContent !== null) {
                            $zip->addFromString($fileName, $fileContent);
                            $downloadedCount++;
                            $totalSize += strlen($fileContent);
                        } else {
                            $errorCount++;
                            $errorMsg = "Fichier non trouvé sur S3: {$fileName} (clé: {$reportKey})";
                            $errors[] = $errorMsg;
                        }
                    }
                } catch (Exception $e) {
                    $errorCount++;
                    $errorMsg = "Erreur pour le rapport {$result['reference']}: " . $e->getMessage();
                    $errors[] = $errorMsg;
                }
            }

            // 6. FINALISATION DU ZIP
            $zip->close();

            // 7. VÉRIFICATION - AU MOINS UN FICHIER TÉLÉCHARGÉ ?
            if ($downloadedCount === 0) {
                unlink($zipPath);
                rmdir($tempDir);

                return [
                    'success' => false,
                    'error' => "Aucun fichier n\'a pu être téléchargé.",
                    'details' => $errors
                ];
            }

            // 7. DÉPLACEMENT VERS LE DOSSIER PUBLIC
            $finalPath = $this->moveToPublicDownloads($zipPath, $zipFilename);

            // 8. NETTOYAGE DU DOSSIER TEMPORAIRE
            $this->cleanupTempDirectory($tempDir);

            // 10. RETOUR DU RÉSULTAT DE SUCCÈS
            return [
                'success' => true,
                'file_path' => $finalPath,
                'file_name' => $zipFilename,
                'download_url' => '/downloads/' . $zipFilename,
                'stats' => [
                    'total_requested' => $this->countTotalReports($results, $type),
                    'downloaded' => $downloadedCount,
                    'errors' => $errorCount,
                    'total_size' => $this->formatBytes($totalSize),
                    'error_details' => $errors
                ]
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Erreur lors de la génération du package de téléchargement: ' . $e->getMessage()
            ];
        }
    }

    public function validateResult(array $result, string $type): bool
    {
        if ($type === 'SQL' && empty($result)) {
            return false;
        }

        if ($type === 'ES' && empty($result['results'])) {
            return false;
        }

        return true;
    }

    /**
     * Create temporary directory
     */
    protected function createTempDirectory(): string
    {
        try {
            $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'chatbot_downloads_' . uniqid();

            $this->filesystem->mkdir($tempDir, 0755);

            return $tempDir;
        } catch (IOExceptionInterface $e) {
            throw new RuntimeException('Cannot create temporary directory: ' . $e->getMessage());
        }
    }

    /**
     * Ensure downloads directory exists in public folder
     */
    protected function ensureDownloadsDirectoryExists(): void
    {
        $downloadsDir = $this->projectDir . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'downloads';

        try {
            if (!$this->filesystem->exists($downloadsDir)) {
                $this->filesystem->mkdir($downloadsDir, 0755);
            }
        } catch (IOExceptionInterface $e) {
            throw new RuntimeException('Cannot create downloads directory: ' . $e->getMessage());
        }
    }

    /**
     * Generate filename from S3 path
     */
    private function generateFileNameFromS3Path(string $s3Path, string $clientCaseId): string
    {
        // Extract filename from path: "report/202/S0-0054-FCE-003.pdf" -> "S0-0054-FCE-003.pdf"
        $filename = basename($s3Path);

        // Add case ID prefix for organization: "202_S0-0054-FCE-003.pdf"
        return "{$clientCaseId}_{$filename}";
    }

    /**
     * Build S3 key from report data
     */
    private function buildReportKey(array $reportData): string
    {
        $imported = (bool) $reportData['imported'];
        $clientCaseId = $reportData['client_case_id'];

        if ($imported) {
            $filename = $reportData['filename'] ?? $reportData['reference'];
            return "report/{$clientCaseId}/{$filename}.pdf";
        }

        $shortReference = $reportData['client_case_short_reference'];
        $reference = $reportData['reference'];

        return "report/{$clientCaseId}/{$shortReference}-{$reference}.pdf";
    }

    /**
     * Generate human-readable filename for ZIP
     */
    private function generateFileName(array $reportData): string
    {
        $shortRef = $reportData['client_case_short_reference'];
        $reference = $reportData['reference'];
        $date = date('Y-m-d', strtotime($reportData['created_at']));

        // Sanitize filename for ZIP compatibility
        $filename = "{$shortRef}-{$reference}-{$date}.pdf";
        return $this->sanitizeFilename($filename);
    }

    /**
     * Sanitize filename for ZIP and filesystem compatibility
     */
    private function sanitizeFilename(string $filename): string
    {
        // Remove or replace problematic characters
        $filename = preg_replace('/[^\w\-_\.]/', '_', $filename);
        $filename = preg_replace('/_+/', '_', $filename); // Remove multiple underscores

        return $filename;
    }

    /**
     * Count total reports in ES results
     */
    private function countTotalReports(array $results, string $type = 'SQL'): int
    {
        if ($type === 'ES') {
            $total = 0;
            foreach ($results['results'] ?? [] as $result) {
                $total += count($result['data']['reports'] ?? []);
            }
            return $total;
        } else {
            return count($results);
        }
    }

    protected function moveToPublicDownloads(string $tempPath, string $filename): string
    {
        $publicDir = $this->projectDir . '/public/downloads';
        $finalPath = $publicDir . '/' . $filename;

        try {
            $this->filesystem->copy($tempPath, $finalPath);
            $this->filesystem->remove($tempPath);

            return $finalPath;
        } catch (IOExceptionInterface $e) {
            throw new RuntimeException('Cannot move file to public directory: ' . $e->getMessage());
        }
    }

    /**
     * Clean up temporary directory and files
     */
    protected function cleanupTempDirectory(string $tempDir, ?string $zipPath = null): void
    {
        try {
            if ($zipPath && $this->filesystem->exists($zipPath)) {
                $this->filesystem->remove($zipPath);
            }

            if ($this->filesystem->exists($tempDir)) {
                $this->filesystem->remove($tempDir);
            }

        } catch (IOExceptionInterface $e) {
            throw new RuntimeException('Cannot clean temp directory: ' . $e->getMessage());
        }
    }

    /**
     * Format bytes to human readable format
     */
    protected function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;
        $size = $bytes;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return round($size, 2) . ' ' . $units[$unitIndex];
    }
}