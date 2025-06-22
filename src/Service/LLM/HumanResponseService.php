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
}