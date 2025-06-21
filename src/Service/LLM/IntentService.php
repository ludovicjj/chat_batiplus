<?php

namespace App\Service\LLM;

class IntentService extends AbstractLLMService
{
    public const INTENT_INFO = 'INFO';
    public const INTENT_DOWNLOAD = 'DOWNLOAD';

    /**
     * Classify user intent from question
     */
    public function classify(string $question): string
    {
        $systemPrompt = $this->buildClassificationPrompt();
        $userPrompt = "Question de l'utilisateur: {$question}";

        $response = $this->callLlm($systemPrompt, $userPrompt);

        // Clean response and validate
        $intent = trim(strtoupper($response));

        return in_array($intent, [self::INTENT_INFO, self::INTENT_DOWNLOAD])
            ? $intent
            : self::INTENT_INFO; // Default fallback
    }

    private function buildClassificationPrompt(): string
    {
        return "Tu es un classificateur d'intentions pour un système de base de données d'entreprise de bâtiment.\n\n" .
            "Ton rôle est d'analyser la question de l'utilisateur et de déterminer son intention.\n\n" .
            "TYPES D'INTENTIONS:\n" .
            "- INFO: L'utilisateur veut des informations, statistiques, comptages, listes textuelles\n" .
            "- DOWNLOAD: L'utilisateur veut récupérer, télécharger, obtenir des fichiers/rapports\n\n" .
            "MOTS-CLÉS POUR DOWNLOAD:\n" .
            "- Verbes: donner, fournir, envoyer, télécharger, récupérer, exporter\n" .
            "- Expressions: 'peux-tu me donner', 'j'ai besoin de', 'je voudrais récupérer'\n\n" .
            "MOTS-CLÉS POUR INFO:\n" .
            "- Verbes: combien, comment, qui, quand, où, lister, afficher, montrer\n" .
            "- Questions: 'combien de', 'qui a fait', 'quels sont'\n\n" .
            "INSTRUCTIONS:\n" .
            "- Réponds UNIQUEMENT par 'INFO' ou 'DOWNLOAD'\n" .
            "- En cas de doute, choisis 'INFO'\n" .
            "- Analyse le verbe principal et l'intention globale\n";
    }
}