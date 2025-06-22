<?php

namespace App\Controller;

use App\Exception\UnsafeSqlException;
use App\Service\SQL\SqlSecurityService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DebugController extends AbstractController
{
    #[Route('/debug-php-config', name: 'debug_php_config', methods: ['GET'])]
    public function debugPhpConfig(): JsonResponse
    {
        return new JsonResponse([
            'output_buffering' => [
                'value' => ini_get('output_buffering'),
                'status' => ini_get('output_buffering') ? '❌ ACTIVÉ (problématique)' : '✅ DÉSACTIVÉ (bon)'
            ],
            'zlib_output_compression' => [
                'value' => ini_get('zlib.output_compression'),
                'status' => ini_get('zlib.output_compression') ? '❌ ACTIVÉ (peut poser problème)' : '✅ DÉSACTIVÉ (bon)'
            ],
            'implicit_flush' => [
                'value' => ini_get('implicit_flush'),
                'status' => ini_get('implicit_flush') ? '✅ ACTIVÉ (aide le streaming)' : '⚠️ DÉSACTIVÉ'
            ],
            'ob_level' => [
                'value' => ob_get_level(),
                'status' => ob_get_level() > 0 ? '❌ Buffer actif (niveau ' . ob_get_level() . ')' : '✅ Pas de buffer'
            ],
            'max_execution_time' => ini_get('max_execution_time'),
            'memory_limit' => ini_get('memory_limit'),
            'php_version' => PHP_VERSION,
            'sapi' => PHP_SAPI
        ]);
    }

    #[Route('/test-security', name: 'test_security')]
    public function testSecurity(SqlSecurityService $securityService): Response
    {
        $testQueries = [
            // Should PASS ✅
            'SELECT COUNT(*) FROM collaborator WHERE is_enabled = TRUE AND deleted_at IS NULL;',
            'SELECT * FROM client_case WHERE is_archived = FALSE;',
            'SELECT id, firstname FROM collaborator;',

            // Should FAIL ❌
            'DROP TABLE collaborator;',
            'DELETE FROM collaborator;',
            'SELECT * FROM unauthorized_table;',
            'INSERT INTO collaborator (firstname) VALUES ("test");'
        ];

        $results = [];
        foreach ($testQueries as $query) {
            try {
                $securityService->validateQuery($query);
                $results[] = "✅ PASS: " . $query;
            } catch (UnsafeSqlException $e) {
                $results[] = "❌ BLOCKED: " . $query . " → " . $e->getMessage();
            }
        }

        return new Response('<pre>' . implode("\n", $results) . '</pre>');
    }
}