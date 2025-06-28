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

        $clarificationSection = "\n\n🎯 ATTENTION - STRUCTURE OPTIMISÉE POUR LLM :\n";
        $clarificationSection .= "• caseReference = référence de L'AFFAIRE (ex: '94P0237518')\n";
        $clarificationSection .= "• reports.reportReference = référence D'UN RAPPORT (ex: 'AD-001')\n";
        $clarificationSection .= "• caseManager = responsable de l'affaire\n";
        $clarificationSection .= "• caseClient = client de l'affaire\n";
        $clarificationSection .= "• reports.reportReviews = avis dans les rapports\n";
        $clarificationSection .= "• reports.reportReviews.reviewDomain = domaine technique (Portes, SSI...)\n";
        $clarificationSection .= "• reports.reportReviews.reviewValueName = valeur décodée (Favorable, Défavorable...)\n\n";

        $queryInstructions = "\n\nINSTRUCTIONS ELASTICSEARCH:\n\n";
        $queryInstructions .= "1. STRUCTURE DE RÉPONSE:\n";
        $queryInstructions .= "   - Génère UNIQUEMENT le body JSON de la requête Elasticsearch\n";
        $queryInstructions .= "   - Format: JSON valide sans explications\n";
        $queryInstructions .= "   - Pas de commentaires dans le JSON\n\n";

        $queryInstructions .= "2. RÈGLES DE REQUÊTE:\n";
        $queryInstructions .= "   - Pour comptages simples: utiliser size: 0 et track_total_hits: true\n";
        $queryInstructions .= "   - Pour recherche texte: utiliser match sur les champs text\n";
        $queryInstructions .= "   - Pour filtrage exact: utiliser term sur les champs keyword\n";
        $queryInstructions .= "   - Pour champs integer: utiliser term avec valeur numérique (ex: \"caseId\": 123)\n";
        $queryInstructions .= "   - Pour agrégations normalisées: utiliser les champs .normalized (caseClient.normalized, caseAgency.normalized, etc.)\n";
        $queryInstructions .= "   - Pour agrégations exactes: utiliser les champs .keyword\n";
        $queryInstructions .= "   - ATTENTION: caseId est integer, pas keyword ! Utiliser {\"term\": {\"caseId\": 869}} pas {\"term\": {\"caseId.keyword\": \"869\"}}\n\n";

        $queryInstructions .= "3. GESTION DES CHAMPS VIDES ET NULL:\n";
        $queryInstructions .= "   - \"sans manager\", \"pas de manager\", \"manager vide\" → {\"term\": {\"caseManager.keyword\": \"\"}}\n";
        $queryInstructions .= "   - \"sans client\", \"pas de client\", \"client vide\" → {\"term\": {\"caseClient.keyword\": \"\"}}\n";
        $queryInstructions .= "   - \"sans agence\", \"pas d'agence\", \"agence vide\" → {\"term\": {\"caseAgency.keyword\": \"\"}}\n";
        $queryInstructions .= "   - En général: \"sans [CHAMP]\" = champ vide (\"\"), PAS champ inexistant\n";
        $queryInstructions .= "   - NE PAS utiliser {\"must_not\": {\"exists\": {\"field\": \"xxx\"}}} pour les champs métier\n\n";

        $queryInstructions .= "4. EXEMPLES DE REQUÊTES:\n";
        $queryInstructions .= "   RECHERCHE PAR ID:\n";
        $queryInstructions .= "   {\n";
        $queryInstructions .= "     \"query\": { \"term\": { \"caseId\": 869 }},\n";
        $queryInstructions .= "     \"_source\": [\"caseClient\"]\n";
        $queryInstructions .= "   }\n\n";

        $queryInstructions .= "   RECHERCHE PAR RÉFÉRENCE D'AFFAIRE:\n";
        $queryInstructions .= "   {\n";
        $queryInstructions .= "     \"query\": { \"term\": { \"caseReference\": \"94P0237518\" }}\n";
        $queryInstructions .= "   }\n\n";

        $queryInstructions .= "   RECHERCHE DANS LES RAPPORTS (NESTED):\n";
        $queryInstructions .= "   {\n";
        $queryInstructions .= "     \"query\": {\n";
        $queryInstructions .= "       \"nested\": {\n";
        $queryInstructions .= "         \"path\": \"reports\",\n";
        $queryInstructions .= "         \"query\": { \"term\": { \"reports.reportReference\": \"AD-001\" }}\n";
        $queryInstructions .= "       }\n";
        $queryInstructions .= "     }\n";
        $queryInstructions .= "   }\n\n";

        $queryInstructions .= "   RECHERCHE DANS LES AVIS (DOUBLE NESTED):\n";
        $queryInstructions .= "   {\n";
        $queryInstructions .= "     \"query\": {\n";
        $queryInstructions .= "       \"nested\": {\n";
        $queryInstructions .= "         \"path\": \"reports\",\n";
        $queryInstructions .= "         \"query\": {\n";
        $queryInstructions .= "           \"nested\": {\n";
        $queryInstructions .= "             \"path\": \"reports.reportReviews\",\n";
        $queryInstructions .= "             \"query\": { \"term\": { \"reports.reportReviews.reviewValueName.keyword\": \"Favorable\" }}\n";
        $queryInstructions .= "           }\n";
        $queryInstructions .= "         }\n";
        $queryInstructions .= "       }\n";
        $queryInstructions .= "     }\n";
        $queryInstructions .= "   }\n\n";

        $queryInstructions .= "   AGGREGATION PAR CLIENT (normalisé - recommandé):\n";
        $queryInstructions .= "   {\n";
        $queryInstructions .= "     \"aggs\": {\n";
        $queryInstructions .= "       \"clients\": {\n";
        $queryInstructions .= "         \"terms\": { \"field\": \"caseClient.normalized\" }\n";
        $queryInstructions .= "       }\n";
        $queryInstructions .= "     },\n";
        $queryInstructions .= "     \"size\": 0\n";
        $queryInstructions .= "   }\n\n";

        $queryInstructions .= "   AGGREGATION PAR AGENCE (normalisé - recommandé):\n";
        $queryInstructions .= "   {\n";
        $queryInstructions .= "     \"aggs\": {\n";
        $queryInstructions .= "       \"agencies\": {\n";
        $queryInstructions .= "         \"terms\": { \"field\": \"caseAgency.normalized\" }\n";
        $queryInstructions .= "       }\n";
        $queryInstructions .= "     },\n";
        $queryInstructions .= "     \"size\": 0\n";
        $queryInstructions .= "   }\n\n";

        return $schemaDescription . $clarificationSection . $queryInstructions . $this->getIntentSpecificInstructions($intent);
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
        return "5. OPTIMISATION POUR INFO:\n" .
            "   - Utiliser size: 0 pour les comptages\n" .
            "   - Utiliser aggregations pour les statistiques\n" .
            "   - Limiter les champs retournés avec _source si nécessaire\n" .
            "   - Optimiser pour la vitesse de réponse\n\n";
    }

    private function getDownloadSpecificInstructions(): string
    {
        return "5. OPTIMISATION POUR TÉLÉCHARGEMENT:\n" .
            "   - SIMPLE: Récupérer uniquement reports.reportS3Path\n" .
            "   - _source: [\"reports.reportS3Path\"] suffit pour le téléchargement\n" .
            "   - LIMITE PRAGMATIQUE: Éviter les téléchargements massifs (>100 fichiers)\n" .
            "   - RÈGLE SIMPLE: Une affaire = environ 5-15 fichiers en moyenne\n" .
            "   - CALCUL CONSERVATEUR: size: 8 pour rester sous 100 fichiers (~8×12=96)\n" .
            "   - EXCEPTION: Si affaire unique (recherche par référence), pas de limite\n" .
            "   - PAS de filtre sur reportImported: tous les rapports sont téléchargeables\n" .
            "   - Le service utilisera directement les chemins S3\n\n" .

            "   RÈGLES DE LIMITATION:\n" .
            "   - Recherche par AFFAIRE SPÉCIFIQUE (caseReference): pas de limite (1 seule affaire)\n" .
            "   - Recherche par MANAGER/CLIENT: size: 8 (estimation: 8×12≈100 fichiers max)\n" .
            "   - Recherche LARGE (range, multi-critères): size: 5 (très prudent)\n" .
            "   - TOUJOURS préciser dans un commentaire le nombre d'affaires limitées\n\n" .

            "   EXEMPLES DE REQUÊTES TÉLÉCHARGEMENT:\n" .
            "   Pour une affaire spécifique (pas de limite):\n" .
            "   {\n" .
            "     \"query\": {\"term\": {\"caseReference\": \"[REFERENCE_AFFAIRE]\"}},\n" .
            "     \"_source\": [\"reports.reportS3Path\"]\n" .
            "   }\n\n" .

            "   Pour un manager (limite conservative):\n" .
            "   {\n" .
            "     \"query\": {\"term\": {\"caseManager.keyword\": \"[NOM_MANAGER]\"}},\n" .
            "     \"_source\": [\"reports.reportS3Path\"],\n" .
            "     \"size\": 8\n" .
            "   }\n\n" .

            "   Pour plusieurs affaires (limite stricte):\n" .
            "   {\n" .
            "     \"query\": {\"range\": {\"reportsCount\": {\"gt\": 5}}},\n" .
            "     \"_source\": [\"reports.reportS3Path\"],\n" .
            "     \"size\": 5\n" .
            "   }\n\n" .

            "   AVERTISSEMENT À INCLURE:\n" .
            "   - Toujours préciser dans la réponse le nombre estimé de fichiers\n" .
            "   - Suggérer de préciser la recherche si trop de résultats\n" .
            "   - Mentionner la possibilité de filtrer par date ou référence\n\n";
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