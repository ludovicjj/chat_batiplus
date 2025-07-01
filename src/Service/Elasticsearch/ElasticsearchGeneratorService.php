<?php

namespace App\Service\Elasticsearch;

use App\Service\LLM\AbstractLLMService;
use App\Service\LLM\IntentService;

class ElasticsearchGeneratorService extends AbstractLLMService
{
    public function generateQueryBody(string $question, array $mapping, string $intent, array $ragExamples = []): string
    {
        $systemPrompt = $this->buildSystemPrompt($mapping, $intent, $ragExamples);
        $userPrompt = "Génère une requête Elasticsearch pour répondre à cette question: {$question}";

        $response = $this->callLlm($systemPrompt, $userPrompt);

        return $this->extractJsonFromResponse($response);
    }

    public function buildSystemPrompt(array $mapping, string $intent, array $ragExamples = []): string
    {
        $schemaDescription = $this->buildSchemaDescription($mapping);
        $basePrompts = $this->loadBasePrompts($intent);

        if (!empty($ragExamples)) {
            $examplesSection = $this->buildRAGExamplesSection($ragExamples);
        } else {
            $examplesSection = $this->loadStaticExamples();
        }

        return $schemaDescription . "\n\n" . $basePrompts . "\n\n" . $examplesSection;
    }

    private function buildSchemaDescription(array $mapping): string
    {
        $schemaDescription = "Voici la structure de l'index Elasticsearch 'client_case' d'une entreprise de bâtiment:\n\n";

        foreach ($mapping['client_case'] as $fieldInfo) {
            $schemaDescription .= "• {$fieldInfo}\n";
        }

        return $schemaDescription;
    }

    private function loadBasePrompts(string $intent): string
    {
        $promptPath = $this->projectDir . '/config/elasticsearch/prompts/';

        $prompts = [
            file_get_contents($promptPath . 'base.md'),
            file_get_contents($promptPath . 'rules-core.md'),
            file_get_contents($promptPath . 'rules-fields.md'),
            file_get_contents($promptPath . 'rules-counting.md'),
        ];

        $intentPromptPath = $promptPath . "intent-{$intent}.md";
        if (file_exists($intentPromptPath)) {
            $prompts[] = file_get_contents($intentPromptPath);
        }

        return implode("\n\n", array_filter($prompts));
    }

    private function buildRAGExamplesSection(array $ragExamples): string
    {
        $section = "## Exemples similaires pertinents (générés dynamiquement):\n\n";
        $section .= "Voici des exemples de questions similaires avec leurs queries Elasticsearch correspondantes :\n\n";

        foreach ($ragExamples as $index => $example) {
            $similarity = number_format(($example->getSimilarityScore() ?? 0) * 100, 1);
            $section .= "### Exemple " . ($index + 1) . " (similarité: {$similarity}%)\n\n";
            $section .= "**Question:** {$example->getQuestion()}\n\n";
            $section .= "**Query Elasticsearch:**\n```json\n";
            $section .= $example->getQuery();
            $section .= "\n```\n\n";
        }

        $section .= "---\n\n";
        $section .= "**Important:** Utilise ces exemples similaires comme guide pour générer une query appropriée. ";
        $section .= "Adapte la structure et la logique selon la question posée.\n\n";

        return $section;
    }

    /**
     * Load static examples as fallback
     */
    private function loadStaticExamples(): string
    {
        $promptPath = $this->projectDir . '/config/elasticsearch/prompts/';

        $staticExamples = [
            file_get_contents($promptPath . 'examples-simple.md'),
            file_get_contents($promptPath . 'examples-complex.md'),
        ];

        return "## Exemples statiques:\n\n" . implode("\n\n", array_filter($staticExamples));
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