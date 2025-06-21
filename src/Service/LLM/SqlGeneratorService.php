<?php

namespace App\Service\LLM;

class SqlGeneratorService extends AbstractLLMService
{
    /**
     * Generate SQL query from natural language question
     */
    public function generateForIntent(string $question, array $databaseSchema, string $intent): string
    {
        $systemPrompt = $this->buildSystemPromptForSqlWithIntent($databaseSchema, $intent);
        $userPrompt = "Génère une requête SQL pour répondre à cette question: {$question}";

        $response = $this->callLlm($systemPrompt, $userPrompt);

        // Extract SQL from response
        return $this->extractSqlFromResponse($response);
    }

    private function buildSystemPromptForSqlWithIntent(array $databaseSchema, string $intent): string
    {
        $schemaDescription = "Voici la structure de la base de données d'une entreprise de bâtiment:\n\n";

        foreach ($databaseSchema as $table => $columns) {
            $schemaDescription .= "Table: {$table}\n";
            $schemaDescription .= "Colonnes: " . implode(', ', $columns) . "\n\n";
        }

        $baseInstructions = $schemaDescription .
            "INSTRUCTIONS IMPORTANTES:\n" .
            "- Génère UNIQUEMENT des requêtes SELECT\n" .
            "- N'utilise que les tables et colonnes mentionnées ci-dessus\n" .
            "- Utilise des JOINs appropriés si nécessaire\n" .
            "- Retourne UNIQUEMENT le code SQL, sans explications\n" .
            "- Limite les résultats si approprié (LIMIT)\n" .
            "RÈGLES MÉTIER:\n" .
            "- PAR DÉFAUT: ajouter WHERE deleted_at IS NULL (exclure supprimés)\n" .
            "- Pour les collaborateurs: ajouter is_enabled = TRUE\n" .
            "- SAUF si l'utilisateur utilise des mots comme: tous, total, ensemble, y compris, même, supprimés, effacés, archivés\n";

        return match($intent) {
            IntentService::INTENT_INFO => $baseInstructions . $this->getInfoSpecificInstructions(),
            IntentService::INTENT_DOWNLOAD => $baseInstructions . $this->getDownloadSpecificInstructions(),
            default => $baseInstructions
        };
    }

    private function getInfoSpecificInstructions(): string
    {
        return "TYPE DE REQUÊTE: INFORMATION\n" .
            "- Optimise pour l'affichage d'informations textuelles\n" .
            "- Utilise COUNT, SUM, AVG pour les statistiques\n" .
            "- Sélectionne uniquement les colonnes nécessaires à l'information demandée\n";
    }

    private function getDownloadSpecificInstructions(): string
    {
        return "TYPE DE REQUÊTE: TÉLÉCHARGEMENT DE FICHIERS\n" .
            "- OBLIGATOIRE pour les rapports: inclure ces colonnes exactes:\n" .
            "  SELECT r.id, r.imported, r.filename, r.reference, r.created_at,\n" .
            "         cc.id as client_case_id, cc.short_reference as client_case_short_reference\n" .
            "- TOUJOURS faire le JOIN: FROM report r JOIN client_case cc ON r.client_case_id = cc.id\n" .
            "- Ces colonnes sont nécessaires pour générer les chemins de téléchargement\n" .
            "- Limiter à 50 résultats maximum pour éviter les téléchargements trop volumineux\n";
    }

    private function extractSqlFromResponse(string $response): string
    {
        // Remove markdown code blocks if present
        $response = preg_replace('/```sql\s*/', '', $response);
        $response = preg_replace('/```\s*$/', '', $response);

        // Remove extra whitespace and ensure it ends with semicolon
        $sql = trim($response);
        if (!str_ends_with($sql, ';')) {
            $sql .= ';';
        }

        return $sql;
    }
}