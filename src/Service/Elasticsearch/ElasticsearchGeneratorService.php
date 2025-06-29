<?php

namespace App\Service\Elasticsearch;

use App\Service\LLM\AbstractLLMService;
use App\Service\LLM\IntentService;

class ElasticsearchGeneratorService extends AbstractLLMService
{
    public function generateQueryBody(string $question, array $mapping, string $intent): string
    {
        $systemPrompt = $this->buildSystemPrompt($mapping, $intent);
        $userPrompt = "Génère une requête Elasticsearch pour répondre à cette question: {$question}";

        $response = $this->callLlm($systemPrompt, $userPrompt);

        return $this->extractJsonFromResponse($response);
    }

    public function buildSystemPrompt(array $mapping, string $intent): string
    {
        $schemaDescription = "Voici la structure de l'index Elasticsearch 'client_case' d'une entreprise de bâtiment:\n\n";

        foreach ($mapping['client_case'] as $fieldInfo) {
            $schemaDescription .= "• {$fieldInfo}\n";
        }

        $promptPath = $this->projectDir . '/config/elasticsearch/prompts/';

        return $schemaDescription . "\n\n" .
            file_get_contents($promptPath . 'base.md') . "\n\n" .
            file_get_contents($promptPath . 'rules-core.md') . "\n\n" .
            file_get_contents($promptPath . 'rules-counting.md') . "\n\n" .
            file_get_contents($promptPath . 'rules-fields.md') . "\n\n" .
            file_get_contents($promptPath . 'examples-simple.md') . "\n\n" .
            file_get_contents($promptPath . 'examples-complex.md') . "\n\n" .
            file_get_contents($promptPath . "intent-{$intent}.md");
    }

    private function extractJsonFromResponse(string $response): string
    {
        // Remove markDown code blocks if present
        $response = preg_replace('/```json\s*/', '', $response);
        $response = preg_replace('/```\s*/', '', $response);
        $response = trim($response);

        json_decode($response);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid JSON response from LLM: ' . json_last_error_msg());
        }

        return $response;
    }
}