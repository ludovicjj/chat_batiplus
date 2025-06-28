<?php

namespace App\Service\LLM;

use Generator;

class HumanResponseService extends AbstractLLMService
{
    /**
     * Generate human-readable response from SQL results using LLM
     */
    public function generateHumanResponse(
        string $originalQuestion,
        array $sqlResults,
        string $executedSql,
        string $intent
    ): string {
        if ($intent === IntentService::INTENT_DOWNLOAD) {
            return $this->generateDownloadResponse($sqlResults);
        }

        $systemPrompt = $this->buildSystemPromptForHumanResponse();
        $userPrompt = $this->buildUserPrompt($originalQuestion, $sqlResults, $executedSql);

        return $this->callLlm($systemPrompt, $userPrompt);
    }

    /**
     * Generate streaming response from SQL results using LLM
     * @return Generator<string> Yields string chunks
     */
    public function generateStreamingResponse(string $question, array $sqlResults, string $validatedSql, string $intent): Generator
    {
        if ($intent === IntentService::INTENT_DOWNLOAD) {
            yield from $this->generateStreamingDownloadResponse($sqlResults);
        } else {
            yield from $this->generateStreamingHumanResponse(
                $question,
                $sqlResults,
                $validatedSql,
            );
        }
    }

    /**
     * Generate streaming response from ES query results using LLM
     * @return Generator<string> Yields string chunks
     */
    public function generateElasticsearchStreamingResponse(string $question, array $results, array $validatedQuery, string $intent): Generator
    {
        if ($intent === IntentService::INTENT_DOWNLOAD) {
            yield from $this->generateStreamingDownloadResponseFromES($results);
        } else {
            $systemPrompt = $this->buildSystemPromptForElasticsearchResponse();
            $userPrompt = $this->buildElasticsearchUserPrompt($question, $results, $validatedQuery);
            // Call LLM with stream mode
            yield from $this->callLlmStreaming($systemPrompt, $userPrompt);
        }

    }

    private function generateStreamingHumanResponse(
        string $originalQuestion,
        array $sqlResults,
        string $executedSql
    ): Generator {
        $systemPrompt = $this->buildSystemPromptForHumanResponse();
        $userPrompt = $this->buildUserPrompt($originalQuestion, $sqlResults, $executedSql);

        // Call LLM with stream mode
        yield from $this->callLlmStreaming($systemPrompt, $userPrompt);
    }

    private function generateStreamingDownloadResponse(array $sqlResults): Generator
    {
        $response = $this->generateDownloadResponse($sqlResults);
        $words = explode(' ', $response);

        foreach ($words as $index => $word) {
            yield $word . ($index < count($words) - 1 ? ' ' : '');

            // Délai entre les mots pour simuler la frappe
            usleep(100000); // 100ms entre chaque mot
        }
    }

    private function generateDownloadResponse(array $sqlResults): string
    {
        $count = count($sqlResults);

        return match (true) {
            $count === 0 => "Aucun rapport trouvé correspondant à votre demande.",
            $count === 1 => "J'ai trouvé 1 rapport correspondant à votre demande. Préparation du téléchargement...",
            default => "J'ai trouvé {$count} rapports correspondant à votre demande. Cela peut prendre quelques instants pour générer l'archive..."
        };
    }

    private function buildUserPrompt(string $originalQuestion, array $sqlResults, string $executedSql): string
    {
        return sprintf(
            "Question originale: %s\n\nRequête SQL exécutée: %s\n\nRésultats: %s\n\nFournis une réponse claire et compréhensible.",
            $originalQuestion,
            $executedSql,
            json_encode($sqlResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    private function buildSystemPromptForHumanResponse(): string
    {
        return "Tu es un assistant spécialisé dans le bâtiment. Tu dois transformer des résultats de base de données en réponses compréhensibles pour des utilisateurs non techniques.\n\n" .
            "TYPES DE DEMANDES:\n" .
            "1. INFORMATION (combien, liste, résumé, qui, quand, etc.) → Fournis une réponse textuelle normale\n" .
            "2. TÉLÉCHARGEMENT (télécharger, récupérer, envoyer, exporter des fichiers/rapports) → Ajoute '[DOWNLOAD_REQUEST]' à la fin de ta réponse\n\n" .
            "RÈGLES MÉTIER:\n" .
            "- Par défaut, considère uniquement les éléments actifs (is_enabled = TRUE, deleted_at IS NULL)\n" .
            "- Si l'utilisateur demande explicitement 'tous' ou 'y compris supprimés', alors inclus tout\n" .
            "- Sois précis dans tes réponses et utilise les termes métier appropriés\n";
    }

    private function buildSystemPromptForElasticsearchResponse(): string
    {
        return "Tu es un assistant spécialisé dans le bâtiment. Tu dois transformer des résultats Elasticsearch en réponses compréhensibles pour des utilisateurs non techniques.\n\n" .
            "TYPES DE DEMANDES:\n" .
            "1. INFORMATION (combien, liste, résumé, qui, quand, etc.) → Fournis une réponse textuelle normale\n" .
            "2. TÉLÉCHARGEMENT (télécharger, récupérer, envoyer, exporter des fichiers/rapports) → Ajoute '[DOWNLOAD_REQUEST]' à la fin de ta réponse\n\n" .
            "STRUCTURE DES DONNÉES ELASTICSEARCH:\n" .
            "- total: nombre total de résultats trouvés\n" .
            "- results: liste des documents avec score et données\n" .
            "- aggregations: statistiques et regroupements\n" .
            "- took: temps d'exécution en millisecondes\n\n" .
            "RÈGLES MÉTIER:\n" .
            "- Les données proviennent d'un index Elasticsearch 'client_case'\n" .
            "- Chaque document représente une affaire avec ses rapports et avis\n" .
            "- Les champs calculés (totalReports, totalReviews) sont pré-agrégés\n" .
            "- Sois précis dans tes réponses et utilise les termes métier appropriés\n" .
            "- Pour les aggregations, explique les statistiques de manière claire\n";
    }

    private function buildElasticsearchUserPrompt(
        string $originalQuestion,
        array $elasticsearchResults,
        array $validatedQuery
    ): string {
        $prompt = "QUESTION UTILISATEUR: {$originalQuestion}\n\n";

        $prompt .= "REQUÊTE ELASTICSEARCH EXÉCUTÉE:\n";
        $prompt .= json_encode($validatedQuery, JSON_PRETTY_PRINT) . "\n\n";

        $prompt .= "RÉSULTATS ELASTICSEARCH:\n";
        $prompt .= "- Total trouvé: " . ($elasticsearchResults['total'] ?? 0) . "\n";
        $prompt .= "- Temps d'exécution: " . ($elasticsearchResults['took'] ?? 0) . "ms\n";
        $prompt .= "- Résultats retournés: " . count($elasticsearchResults['results'] ?? []) . "\n\n";

        // Ajouter les agrégations si présentes
        if (!empty($elasticsearchResults['aggregations'])) {
            $prompt .= "AGRÉGATIONS:\n";
            $prompt .= json_encode($elasticsearchResults['aggregations'], JSON_PRETTY_PRINT) . "\n\n";
        }

        // Ajouter un échantillon des résultats si présents
        if (!empty($elasticsearchResults['results'])) {
            $prompt .= "ÉCHANTILLON DES RÉSULTATS (premiers 5):\n";
            $sampleResults = array_slice($elasticsearchResults['results'], 0, 5);
            foreach ($sampleResults as $index => $result) {
                $prompt .= "Document " . ($index + 1) . ":\n";
                $prompt .= json_encode($result['data'], JSON_PRETTY_PRINT) . "\n\n";
            }
        }

        $prompt .= "Transforme ces données techniques en réponse claire et compréhensible pour un utilisateur non technique.";

        return $prompt;
    }

    private function generateStreamingDownloadResponseFromES(array $results): Generator
    {
        $totalReport = $results['total'] ?? 0;
        $totalReportFound = count($results['results'] ?? []);

        // Compter le nombre total de fichiers dans les affaires retournées
        $totalFiles = 0;
        foreach ($results['results'] ?? [] as $result) {
            $data = $result['data'];
            $totalFiles += count($data['reports'] ?? []);
        }

        // Générer la réponse directement avec contexte
        $response = match (true) {
            $totalReport === 0 => "Aucun rapport trouvé correspondant à votre demande.",
            $totalFiles === 1 => "J'ai trouvé 1 rapport correspondant à votre demande. Préparation du téléchargement...",
            $totalReportFound < $totalReport => "J'ai trouvé {$totalFiles} rapports dans les {$totalReportFound} premières affaires (sur {$totalReport} au total). Pour récupérer plus de fichiers, précisez votre recherche par référence d'affaire. Cela peut prendre quelques instants pour générer l'archive...",
            default => "J'ai trouvé {$totalFiles} rapports correspondant à votre demande. Cela peut prendre quelques instants pour générer l'archive..."
        };

        // Simuler le streaming en découpant par mots
        $words = explode(' ', $response);
        foreach ($words as $index => $word) {
            yield $word . ($index < count($words) - 1 ? ' ' : '');
            usleep(10000); // 50ms entre chaque mot
        }
    }
}