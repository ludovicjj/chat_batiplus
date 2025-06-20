<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Service for communicating with LLM (OpenAI GPT)
 */
readonly class LlmService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%env(LLM_API_URL)%')] private string $apiUrl,
        #[Autowire('%env(LLM_API_KEY)%')] private string $apiKey,
        #[Autowire('%env(LLM_MODEL)%')] private string $llmModel,
        private float $temperature = 0.1,
    ) {}

    /**
     * Generate SQL query from natural language question
     */
    public function generateSqlQuery(string $question, array $databaseSchema): string
    {
        $systemPrompt = $this->buildSystemPromptForSql($databaseSchema);
        $userPrompt = "Génère une requête SQL pour répondre à cette question: {$question}";

        $response = $this->callLlm($systemPrompt, $userPrompt);

        // Extract SQL from response
        return $this->extractSqlFromResponse($response);
    }

    /**
     * Generate human-readable response from SQL results
     */
    public function generateHumanResponse(string $originalQuestion, array $sqlResults, string $executedSql): string
    {
        $systemPrompt = "Tu es un assistant spécialisé dans le bâtiment. Tu dois transformer des résultats de base de données en réponses compréhensibles pour des utilisateurs non techniques.";

        $userPrompt = sprintf(
            "Question originale: %s\n\nRequête SQL exécutée: %s\n\nRésultats de la base de données: %s\n\nFournis une réponse claire et compréhensible à l'utilisateur.",
            $originalQuestion,
            $executedSql,
            json_encode($sqlResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        $response = $this->callLlm($systemPrompt, $userPrompt);

        return $response;
    }

    private function buildSystemPromptForSql(array $databaseSchema): string
    {
        $schemaDescription = "Voici la structure de la base de données d'une entreprise de bâtiment:\n\n";

        foreach ($databaseSchema as $table => $columns) {
            $schemaDescription .= "Table: {$table}\n";
            $schemaDescription .= "Colonnes: " . implode(', ', $columns) . "\n\n";
        }

        return $schemaDescription .
            "INSTRUCTIONS IMPORTANTES:\n" .
            "- Génère UNIQUEMENT des requêtes SELECT\n" .
            "- N'utilise que les tables et colonnes mentionnées ci-dessus\n" .
            "- Utilise des JOINs appropriés si nécessaire\n" .
            "- Retourne UNIQUEMENT le code SQL, sans explications\n" .
            "- Limite les résultats si approprié (LIMIT)\n";
    }

    private function extractSqlFromResponse(string $response): string
    {
        // Remove markdown code blocks if present
        $response = preg_replace('/```sql\s*/', '', $response);
        $response = preg_replace('/```\s*$/', '', $response);

        // Remove extra whitespace and ensure it ends with semicolon
        $sql = trim($response);
        if (!str_ends_with($sql, ';')) {
            $sql .= ';';
        }

        return $sql;
    }

    private function callLlm(string $systemPrompt, string $userPrompt): string
    {
        try {
            $response = $this->httpClient->request('POST', $this->apiUrl . '/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->llmModel,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $systemPrompt
                        ],
                        [
                            'role' => 'user',
                            'content' => $userPrompt
                        ]
                    ],
                    'temperature' => $this->temperature,
                    'reasoning_effort' => 'high'
                ],
                'timeout' => 600,
                'max_duration' => 600,
            ]);

            $data = $response->toArray();

            if (!isset($data['choices'][0]['message']['content'])) {
                throw new \RuntimeException('Invalid response from LLM API');
            }

            return trim($data['choices'][0]['message']['content']);

        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException('Failed to communicate with LLM service: ' . $e->getMessage());
        }
    }
}
