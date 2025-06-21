<?php

namespace App\Service\LLM;

class HumanResponseService extends AbstractLLMService
{
    /**
     * Generate human-readable response from SQL results
     */
    public function generateHumanResponse(string $originalQuestion, array $sqlResults, string $executedSql): string
    {
        $systemPrompt = $this->buildSystemPromptForHumanResponse();

        $userPrompt = sprintf(
            "Question originale: %s\n\nRequête SQL exécutée: %s\n\nRésultats de la base de données: %s\n\nFournis une réponse claire et compréhensible à l'utilisateur.",
            $originalQuestion,
            $executedSql,
            json_encode($sqlResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        return $this->callLlm($systemPrompt, $userPrompt);
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