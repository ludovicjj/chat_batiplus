<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\UnsafeSqlException;
use App\Service\LlmService;
use App\Service\Schema\DatabaseSchemaService;
use App\Service\SqlSecurityService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'home')]
    public function index(): Response
    {
        return $this->render('chat/index.html.twig', [
            'title' => 'ChatBot BatiPlus'
        ]);
    }

    #[Route('/test-schema', name: 'test_schema')]
    public function testSchema(
        DatabaseSchemaService $schemaService,
        LlmService $llmService,
    ): Response {
        $structure = $schemaService->getTablesStructure();
        $sql = $llmService->generateSqlQuery("Combien de collaborateurs actifs ?", $structure);
        dd($sql); // Pour voir le résultat
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
