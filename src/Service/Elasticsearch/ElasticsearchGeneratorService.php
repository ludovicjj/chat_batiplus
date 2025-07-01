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

        // Fetch dynamic rule for Date
        $prompts[] = $this->generateDynamicDateSection();

        return implode("\n\n", array_filter($prompts));
    }

    private function generateDynamicDateSection(): string
    {
        $dateRules = $this->calculateCurrentDateRules();

        return "## ⚠️ GESTION DES DATES RELATIVES

**CONTEXTE ACTUEL :**
- Date du jour : {$dateRules['current_date']}
- Mois actuel : {$dateRules['current_month_name']} {$dateRules['current_year']}
- Année actuelle : {$dateRules['current_year']}

**RÈGLES DE CONVERSION :**
- \"Cette année\" = {$dateRules['current_year']} → \"{$dateRules['year_start']}\" à \"{$dateRules['year_end']}\"
- \"Ce mois\" = {$dateRules['current_month_name']} {$dateRules['current_year']} → \"{$dateRules['month_start']}\" à \"{$dateRules['month_end']}\"
- \"Le mois dernier\" = {$dateRules['last_month_name']} {$dateRules['last_month_year']} → \"{$dateRules['last_month_start']}\" à \"{$dateRules['last_month_end']}\"
- \"Cette semaine\" = semaine actuelle → \"{$dateRules['week_start']}\" à \"{$dateRules['week_end']}\"
- \"La semaine dernière\" = semaine précédente → \"{$dateRules['last_week_start']}\" à \"{$dateRules['last_week_end']}\"

**EXEMPLES CONCRETS :**
- Question: \"Ce mois\" → Utilise: \"{$dateRules['month_start']}\" à \"{$dateRules['month_end']}\"
- Question: \"Cette année\" → Utilise: \"{$dateRules['year_start']}\" à \"{$dateRules['year_end']}\"
- Question: \"Le mois dernier\" → Utilise: \"{$dateRules['last_month_start']}\" à \"{$dateRules['last_month_end']}\"

**FORMAT OBLIGATOIRE :**
- Toujours format ISO 8601 complet : \"yyyy-MM-ddTHH:mm:ssZ\"
- Début de période : \"T00:00:00Z\"
- Fin de période : \"T23:59:59Z\"";
    }

    /**
     * ✅ CALCULS DE DATES DYNAMIQUES
     */
    private function calculateCurrentDateRules(): array
    {
        $now = new \DateTime();
        $currentYear = $now->format('Y');
        $currentMonth = $now->format('m');

        // Mois actuel
        $monthStart = new \DateTime($currentYear . '-' . $currentMonth . '-01 00:00:00');
        $monthEnd = clone $monthStart;
        $monthEnd->modify('last day of this month')->setTime(23, 59, 59);

        // Mois dernier
        $lastMonth = clone $monthStart;
        $lastMonth->modify('-1 month');
        $lastMonthEnd = clone $lastMonth;
        $lastMonthEnd->modify('last day of this month')->setTime(23, 59, 59);

        // Année
        $yearStart = new \DateTime($currentYear . '-01-01 00:00:00');
        $yearEnd = new \DateTime($currentYear . '-12-31 23:59:59');

        // Semaines
        $weekStart = clone $now;
        $weekStart->modify('monday this week')->setTime(0, 0, 0);
        $weekEnd = clone $weekStart;
        $weekEnd->modify('+6 days')->setTime(23, 59, 59);

        $lastWeekStart = clone $weekStart;
        $lastWeekStart->modify('-1 week');
        $lastWeekEnd = clone $lastWeekStart;
        $lastWeekEnd->modify('+6 days')->setTime(23, 59, 59);

        // Noms des mois en français
        $monthNames = [
            '01' => 'janvier', '02' => 'février', '03' => 'mars', '04' => 'avril',
            '05' => 'mai', '06' => 'juin', '07' => 'juillet', '08' => 'août',
            '09' => 'septembre', '10' => 'octobre', '11' => 'novembre', '12' => 'décembre'
        ];

        return [
            'current_date' => $now->format('j') . ' ' . $monthNames[$currentMonth] . ' ' . $currentYear,
            'current_year' => $currentYear,
            'current_month_name' => $monthNames[$currentMonth],
            'last_month_name' => $monthNames[$lastMonth->format('m')],
            'last_month_year' => $lastMonth->format('Y'),

            // Formats ISO pour les queries
            'year_start' => $yearStart->format('Y-m-d\TH:i:s\Z'),
            'year_end' => $yearEnd->format('Y-m-d\TH:i:s\Z'),
            'month_start' => $monthStart->format('Y-m-d\TH:i:s\Z'),
            'month_end' => $monthEnd->format('Y-m-d\TH:i:s\Z'),
            'last_month_start' => $lastMonth->format('Y-m-d\TH:i:s\Z'),
            'last_month_end' => $lastMonthEnd->format('Y-m-d\TH:i:s\Z'),
            'week_start' => $weekStart->format('Y-m-d\TH:i:s\Z'),
            'week_end' => $weekEnd->format('Y-m-d\TH:i:s\Z'),
            'last_week_start' => $lastWeekStart->format('Y-m-d\TH:i:s\Z'),
            'last_week_end' => $lastWeekEnd->format('Y-m-d\TH:i:s\Z'),
        ];
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