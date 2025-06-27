<?php

namespace App\Service\Elasticsearch;

use App\Service\LLM\AbstractLLMService;
use App\Service\LLM\IntentService;

class ElasticsearchGeneratorService extends AbstractLLMService
{
    public function generateQueryBody(string $question, array $mapping, string $intent): array
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

        $queryInstructions = "\n\nINSTRUCTIONS ELASTICSEARCH:\n\n";
        $queryInstructions .= "1. STRUCTURE DE RÉPONSE:\n";
        $queryInstructions .= "   - Génère UNIQUEMENT le body JSON de la requête Elasticsearch\n";
        $queryInstructions .= "   - Format: JSON valide sans explications\n";
        $queryInstructions .= "   - Pas de commentaires dans le JSON\n\n";

        $queryInstructions .= "2. RÈGLES DE REQUÊTE:\n";
        $queryInstructions .= "   - Pour comptages simples: utiliser size: 0 et track_total_hits: true\n";
        $queryInstructions .= "   - Pour recherche texte: utiliser match sur les champs text\n";
        $queryInstructions .= "   - Pour filtrage exact: utiliser term sur les champs keyword\n";
        $queryInstructions .= "   - Pour champs integer: utiliser term avec valeur numérique (ex: \"id\": 123)\n";
        $queryInstructions .= "   - Pour agrégations normalisées: utiliser les champs .normalized (clientName.normalized, agencyName.normalized, etc.)\n";
        $queryInstructions .= "   - Pour agrégations exactes: utiliser les champs .keyword\n";
        $queryInstructions .= "   - ATTENTION: id est integer, pas keyword ! Utiliser {\"term\": {\"id\": 869}} pas {\"term\": {\"id.keyword\": \"869\"}}\n\n";

        $queryInstructions .= "3. GESTION DES CHAMPS VIDES ET NULL:\n";
        $queryInstructions .= "   - \"sans manager\", \"pas de manager\", \"manager vide\" → {\"term\": {\"managerName.keyword\": \"\"}}\n";
        $queryInstructions .= "   - \"sans client\", \"pas de client\", \"client vide\" → {\"term\": {\"clientName.keyword\": \"\"}}\n";
        $queryInstructions .= "   - \"sans agence\", \"pas d'agence\", \"agence vide\" → {\"term\": {\"agencyName.keyword\": \"\"}}\n";
        $queryInstructions .= "   - En général: \"sans [CHAMP]\" = champ vide (\"\"), PAS champ inexistant\n";
        $queryInstructions .= "   - NE PAS utiliser {\"must_not\": {\"exists\": {\"field\": \"xxx\"}}} pour les champs métier\n\n";

        $queryInstructions .= "4. EXEMPLES DE REQUÊTES:\n";

        $queryInstructions .= "4. EXEMPLES DE REQUÊTES:\n";
        $queryInstructions .= "   RECHERCHE PAR ID:\n";
        $queryInstructions .= "   {\n";
        $queryInstructions .= "     \"query\": { \"term\": { \"id\": 869 }},\n";
        $queryInstructions .= "     \"_source\": [\"clientName\"]\n";
        $queryInstructions .= "   }\n\n";

        $queryInstructions .= "   RECHERCHE PAR RÉFÉRENCE:\n";
        $queryInstructions .= "   {\n";
        $queryInstructions .= "     \"query\": { \"term\": { \"reference\": \"BF4523AS\" }}\n";
        $queryInstructions .= "   }\n\n";

        $queryInstructions .= "   AGGREGATION PAR CLIENT (normalisé - recommandé):\n";
        $queryInstructions .= "   {\n";
        $queryInstructions .= "     \"aggs\": {\n";
        $queryInstructions .= "       \"clients\": {\n";
        $queryInstructions .= "         \"terms\": { \"field\": \"clientName.normalized\" }\n";
        $queryInstructions .= "       }\n";
        $queryInstructions .= "     },\n";
        $queryInstructions .= "     \"size\": 0\n";
        $queryInstructions .= "   }\n\n";

        $queryInstructions .= "   AGGREGATION PAR AGENCE (normalisé - recommandé):\n";
        $queryInstructions .= "   {\n";
        $queryInstructions .= "     \"aggs\": {\n";
        $queryInstructions .= "       \"agencies\": {\n";
        $queryInstructions .= "         \"terms\": { \"field\": \"agencyName.normalized\" }\n";
        $queryInstructions .= "       }\n";
        $queryInstructions .= "     },\n";
        $queryInstructions .= "     \"size\": 0\n";
        $queryInstructions .= "   }\n\n";

        return $schemaDescription . $queryInstructions . $this->getIntentSpecificInstructions($intent);
    }

    private function getIntentSpecificInstructions(string $intent): string
    {
        return match($intent) {
            IntentService::INTENT_INFO => $this->getInfoSpecificInstructions(),
            IntentService::INTENT_DOWNLOAD => $this->getDownloadSpecificInstructions(),
            default => ""
        };
    }

    private function getInfoSpecificInstructions(): string
    {
        return "4. OPTIMISATION POUR INFO:\n" .
            "   - Utiliser size: 0 pour les comptages\n" .
            "   - Utiliser aggregations pour les statistiques\n" .
            "   - Limiter les champs retournés avec _source si nécessaire\n" .
            "   - Optimiser pour la vitesse de réponse\n\n";
    }

    private function getDownloadSpecificInstructions(): string
    {
        return "4. OPTIMISATION POUR TÉLÉCHARGEMENT:\n" .
            "   - OBLIGATOIRE: inclure les champs id, reference, reports.id, reports.filename\n" .
            "   - Utiliser _source pour sélectionner les champs nécessaires\n" .
            "   - Limiter à 50 résultats maximum\n" .
            "   - Filtrer sur reports.imported: true pour les fichiers disponibles\n" .
            "   - Exemple _source: [\"id\", \"reference\", \"reports.id\", \"reports.filename\", \"reports.reference\"]\n\n";
    }

    private function extractJsonFromResponse(string $response): array
    {
        // Remove markdown code blocks if present
        $response = preg_replace('/```json\s*/', '', $response);
        $response = preg_replace('/```\s*/', '', $response);
        $response = trim($response);

        // Try to decode JSON
        $decoded = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // If JSON parsing fails, try to extract JSON from mixed content
            $pattern = '/\{.*\}/s';
            if (preg_match($pattern, $response, $matches)) {
                $decoded = json_decode($matches[0], true);
            }

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('Invalid JSON response from LLM: ' . json_last_error_msg() . "\nResponse: " . $response);
            }
        }

        return $decoded;
    }
}