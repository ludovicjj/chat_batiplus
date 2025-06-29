<?php

namespace App\Service\LLM;

class IntentService extends AbstractLLMService
{
    public const INTENT_INFO = 'INFO';
    public const INTENT_DOWNLOAD = 'DOWNLOAD';
    public const INTENT_CHITCHAT = 'CHITCHAT';

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

        return in_array($intent, [self::INTENT_INFO, self::INTENT_DOWNLOAD, self::INTENT_CHITCHAT])
            ? $intent
            : self::INTENT_INFO; // Default fallback
    }

    private function buildClassificationPrompt(): string
    {
        //        return "Tu es un classificateur d'intentions pour un système de base de données d'entreprise de bâtiment.\n\n" .
//            "Ton rôle est d'analyser la question de l'utilisateur et de déterminer son intention.\n\n" .
//            "TYPES D'INTENTIONS:\n" .
//            "- INFO: L'utilisateur veut des informations, statistiques, comptages, listes textuelles\n" .
//            "- DOWNLOAD: L'utilisateur veut récupérer, télécharger, obtenir des fichiers/rapports\n\n" .
//            "MOTS-CLÉS POUR DOWNLOAD:\n" .
//            "- Verbes: donner, fournir, envoyer, télécharger, récupérer, exporter\n" .
//            "- Expressions: 'peux-tu me donner', 'j'ai besoin de', 'je voudrais récupérer'\n\n" .
//            "MOTS-CLÉS POUR INFO:\n" .
//            "- Verbes: combien, comment, qui, quand, où, lister, afficher, montrer\n" .
//            "- Questions: 'combien de', 'qui a fait', 'quels sont'\n\n" .
//            "INSTRUCTIONS:\n" .
//            "- Réponds UNIQUEMENT par 'INFO' ou 'DOWNLOAD'\n" .
//            "- En cas de doute, choisis 'INFO'\n" .
//            "- Analyse le verbe principal et l'intention globale\n";
        return <<<PROMPT
Tu es un classificateur d'intentions pour un système de base de données d'entreprise de bâtiment.

Ton rôle est d'analyser la question de l'utilisateur et de déterminer son intention.

TYPES D'INTENTIONS:
- INFO: L'utilisateur veut des informations, statistiques, comptages, listes textuelles sur les données métier
- DOWNLOAD: L'utilisateur veut récupérer, télécharger, obtenir des fichiers/rapports
- CHITCHAT: L'utilisateur fait de la conversation générale, salue, remercie, ou pose des questions non liées aux données métier

MOTS-CLÉS POUR DOWNLOAD:
- Verbes: donner, fournir, envoyer, télécharger, récupérer, exporter
- Expressions: 'peux-tu me donner', 'j'ai besoin de', 'je voudrais récupérer'

MOTS-CLÉS POUR INFO:
- Verbes: combien, comment, qui, quand, où, lister, afficher, montrer
- Questions: 'combien de', 'qui a fait', 'quels sont'

MOTS-CLÉS POUR CHITCHAT:
- Salutations: bonjour, salut, bonsoir, hello
- Politesse: merci, s'il vous plaît, au revoir, bonne journée
- Questions personnelles: comment vas-tu, qui es-tu, que fais-tu
- Questions générales non métier: quel temps fait-il, aide-moi
- Pas de mention d'affaires, rapports, avis, clients, managers

RÈGLE IMPORTANTE:
- Si la question mélange politesse + demande métier → Choisir l'intent métier (INFO/DOWNLOAD)
- CHITCHAT uniquement pour les questions PUREMENT sociales/conversationnelles

EXEMPLES:
- 'Salut comment vas-tu ?' → CHITCHAT (purement social)
- 'Bonjour, combien d'affaires ?' → INFO (demande métier + politesse)
- 'Merci pour les rapports' → CHITCHAT (purement social)
- 'Bonjour, peux-tu télécharger les fichiers ?' → DOWNLOAD (demande métier + politesse)

INSTRUCTIONS:
- Réponds UNIQUEMENT par 'INFO', 'DOWNLOAD' ou 'CHITCHAT'
- En cas de doute sur les données métier, choisis 'INFO'
- En cas de doute sur la conversation pure, choisis 'CHITCHAT'
- Analyse le verbe principal et l'intention globale
PROMPT;
    }
}