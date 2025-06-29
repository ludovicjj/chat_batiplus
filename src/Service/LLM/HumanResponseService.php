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
    public function generateElasticsearchStreamingResponse(string $question, array $results, string $query, string $intent): Generator
    {
        if ($intent === IntentService::INTENT_CHITCHAT) {
            yield from $this->generateStreamingChitchatResponse();
        } elseif ($intent === IntentService::INTENT_DOWNLOAD) {
            yield from $this->generateStreamingDownloadResponseFromES($results);
        } else {
            $systemPrompt = $this->buildSystemPromptForElasticsearchResponse();
            $userPrompt = $this->buildElasticsearchUserPrompt($question, $results, $query);
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
        return <<<SYSTEM_PROMPT
Tu es un assistant spécialisé dans le bâtiment. Tu dois transformer des résultats Elasticsearch en réponses compréhensibles pour des utilisateurs non techniques.

TYPES DE DEMANDES:
1. INFORMATION (combien, liste, résumé, qui, quand, etc.) → Fournis une réponse textuelle normale
2. TÉLÉCHARGEMENT (télécharger, récupérer, envoyer, exporter des fichiers/rapports) → Ajoute '[DOWNLOAD_REQUEST]' à la fin de ta réponse

STRUCTURE DES DONNÉES ELASTICSEARCH:
- total: nombre total de résultats trouvés
- results: liste des documents avec score et données
- aggregations: statistiques et regroupements
- took: temps d'exécution en millisecondes

🚨 RÈGLES CRITIQUES D'INTERPRÉTATION DES RÉSULTATS:

1. COMPTAGE D'AFFAIRES:
   - Question type: 'Combien d'affaires...'
   - Lire: results.total (nombre d'affaires trouvées)
   - Réponse: 'Il y a X affaires...'

2. COMPTAGE DE RAPPORTS:
   - Question type: 'Combien de rapports...'
   - Lire: aggregations.reports_count.value (somme des rapports)
   - NE PAS lire results.total (qui compte les affaires, pas les rapports)
   - Réponse: 'Il y a X rapports au total...'

3. COMPTAGE D'AVIS:
   - Question type: 'Combien d'avis Favorable/Suspendu...'
   - Lire: aggregations.reports.reviews.count_avis.doc_count
   - NE PAS lire results.total (qui compte les affaires contenant ces avis)
   - Réponse: 'Il y a X avis [TYPE] dans...'
   
4. COMPTAGE D'AVIS GLOBAL:
   - Question type: 'Combien d'avis au total...'
   - Lire: aggregations.reports.reviews.count_avis.value (aggregation nested)
   - NE PAS lire aggregations.total_reviews.value (champ pré-calculé défaillant)
   - Réponse: 'Il y a X avis au total...'

5. RECHERCHE/LISTING:
   - Question type: 'Liste des...', 'Quelles sont...'
   - Lire: results (array des documents)
   - Analyser le contenu des documents pour extraire les informations demandées

6.. STATISTIQUES/AGRÉGATIONS:
   - Question type: 'Répartition par...', 'Statistiques...'
   - Lire: aggregations.{nom_aggregation}.buckets
   - Présenter sous forme de liste ou tableau

EXEMPLES D'INTERPRÉTATION:

EXEMPLE 1 - Comptage de rapports:
Question: 'Combien de rapports au total ?'
Résultat ES: {
  'total': 1409,
  'aggregations': {'reports_count': {'value': 10686.0}}
}
Réponse CORRECTE: 'Il y a 10 686 rapports au total dans la base.'
Réponse INCORRECTE: 'Il y a 1409 rapports.' (c'est le nombre d'affaires!)

EXEMPLE 2 - Comptage d'avis:
Question: 'Combien d'avis Suspendu dans l'affaire 1360 ?'
Résultat ES: {
  'total': 1,
  'aggregations': {
    'reports': {
      'reviews': {
        'count_avis': {'doc_count': 3}
      }
    }
  }
}
Réponse CORRECTE: 'Il y a 3 avis Suspendu dans l'affaire 1360.'
Réponse INCORRECTE: 'Il y a 1 avis.' (c'est le nombre d'affaires!)

EXEMPLE 3 - Comptage d'affaires:
Question: 'Combien d'affaires pour le client APHP ?'
Résultat ES: {'total': 245}
Réponse CORRECTE: 'Il y a 245 affaires pour le client APHP.'

RÈGLES MÉTIER:
- Les données proviennent d'un index Elasticsearch 'client_case'
- Chaque document représente une affaire avec ses rapports et avis
- Les champs calculés (totalReports, totalReviews) sont pré-agrégés
- Sois précis dans tes réponses et utilise les termes métier appropriés
- Pour les aggregations, explique les statistiques de manière claire
- TOUJOURS vérifier le type de question pour savoir où chercher la bonne valeur
- En cas de doute, précise quelle valeur tu utilises dans ta réponse
SYSTEM_PROMPT;
    }

    private function buildElasticsearchUserPrompt(
        string $originalQuestion,
        array $elasticsearchResults,
        string $query
    ): string {
        $prompt = "QUESTION UTILISATEUR: {$originalQuestion}\n\n";

        $prompt .= "REQUÊTE ELASTICSEARCH EXÉCUTÉE:\n";
        $prompt .= $query . "\n\n";

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

    private function generateStreamingChitchatResponse(): Generator
    {
        $response = "Bonjour ! Je vous remercie pour votre message. Je suis conçu spécifiquement pour analyser vos données métier (affaires, rapports, avis) et ne peux pas engager de conversation générale. Comment puis-je vous aider avec vos données professionnelles ?";
        $words = explode(' ', $response);
        foreach ($words as $index => $word) {
            yield $word . ($index < count($words) - 1 ? ' ' : '');
            usleep(10000); // 50ms entre chaque mot
        }
    }
}