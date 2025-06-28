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

        $clarificationSection = <<<CLARIFICATION

🎯 ATTENTION - STRUCTURE OPTIMISÉE POUR LLM :
• caseReference = référence de L'AFFAIRE (ex: '94P0237518')
• reports.reportReference = référence D'UN RAPPORT (ex: 'AD-001')
• caseManager = responsable de l'affaire
• caseClient = client de l'affaire
• reports.reportReviews = avis dans les rapports
• reports.reportReviews.reviewDomain = domaine technique (Portes, SSI...)
• reports.reportReviews.reviewValueName = valeur décodée (Favorable, Défavorable...)

🔥 RÈGLE CRITIQUE - CHAMPS NESTED :
• Pour obtenir les données des rapports : _source doit inclure 'reports' (PAS 'reports.reportReference')
• Pour obtenir les données des avis : _source doit inclure 'reports' (contient reportReviews)
• Les champs nested ne peuvent pas être récupérés individuellement !
• EXEMPLE CORRECT pour 'références des rapports' : _source: ['caseId', 'caseReference', 'reports']

CLARIFICATION;

        $queryInstructions = <<<QUERY_RULES
INSTRUCTIONS ELASTICSEARCH:

1. STRUCTURE DE RÉPONSE:
   - Génère UNIQUEMENT le body JSON de la requête Elasticsearch
   - Format: JSON valide sans explications
   - Pas de commentaires dans le JSON

2. RÈGLES DE REQUÊTE:
   - Pour comptages simples: utiliser size: 0 et track_total_hits: true
   - Pour recherche texte: utiliser match sur les champs text
   - Pour filtrage exact: utiliser term sur les champs keyword
   - Pour champs integer: utiliser term avec valeur numérique (ex: "caseId": 123)
   - Pour agrégations normalisées: utiliser les champs .normalized (caseClient.normalized, caseAgency.normalized, etc.)
   - Pour agrégations exactes: utiliser les champs .keyword
   - ATTENTION: caseId est integer, pas keyword ! Utiliser {"term": {"caseId": 869}} pas {"term": {"caseId.keyword": "869"}}

   - 🔥 RÈGLE CRITIQUE CHAMPS KEYWORD:
     • reviewValueName, reviewValueCode, reviewDomain = directement keyword (SANS .keyword)
     • reviewCreatedBy, caseManager, caseClient = text avec .keyword (AVEC .keyword)
     • Exemple: {"term": {"reports.reportReviews.reviewValueName": "Favorable"}}
     
   - 🚨 RÈGLE CRITIQUE COMPTAGE SIMPLE:
      Pour "combien d'affaires pour [ENTITÉ]":
        • UTILISER UNIQUEMENT: {"query": {...}, "size": 0, "track_total_hits": true}
        • NE JAMAIS ajouter d'aggregations pour un comptage simple
        • Le résultat sera dans hits.total.value
        • Les aggregations sont SEULEMENT pour les répartitions ("répartition par client")
     
   - 🚨 RÈGLES CRITIQUES POUR COMPTAGE D'AVIS:
     • Quand l'utilisateur demande "combien d'avis [TYPE]", il faut compter les AVIS INDIVIDUELS, PAS les affaires
     • NE JAMAIS utiliser "size": 0 avec "track_total_hits": true pour compter des avis
     • TOUJOURS utiliser des aggregations nested pour compter les avis
     • Le résultat du comptage sera dans aggregations.reports.reviews.count_avis.doc_count
     
   🚨 SYNTAXE JSON ELASTICSEARCH OBLIGATOIRE:
     • TOUJOURS utiliser des objets JSON complets
     • "query": {"match_all": {}} ✅ CORRECT
     • "query": "match_all" ❌ INCORRECT
     • "aggs": {"name": {"sum": {"field": "xxx"}}} ✅ CORRECT
     • "aggs": "sum" ❌ INCORRECT

3. GESTION DES CHAMPS VIDES ET NULL:
   - "sans manager", "pas de manager", "manager vide" → {"term": {"caseManager.keyword": ""}}
   - "sans client", "pas de client", "client vide" → {"term": {"caseClient.keyword": ""}}
   - "sans agence", "pas d'agence", "agence vide" → {"term": {"caseAgency.keyword": ""}}
   - En général: "sans [CHAMP]" = champ vide (""), PAS champ inexistant
   - NE PAS utiliser {"must_not": {"exists": {"field": "xxx"}}} pour les champs métier

4. EXEMPLES DE REQUÊTES:

   RECHERCHE PAR ID:
   {
     "query": { "term": { "caseId": 869 }},
     "_source": ["caseClient"]
   }

   RECHERCHE PAR RÉFÉRENCE D'AFFAIRE:
   {
     "query": { "term": { "caseReference": "94P0237518" }}
   }

   RECHERCHE DANS LES RAPPORTS (NESTED SIMPLE):
   {
     "query": {
       "nested": {
         "path": "reports",
         "query": { "term": { "reports.reportReference": "AD-001" }}
       }
     }
   }

   RECHERCHE DANS LES AVIS (DOUBLE NESTED SEUL):
   {
     "query": {
       "nested": {
         "path": "reports",
         "query": {
           "nested": {
             "path": "reports.reportReviews",
             "query": { "term": { "reports.reportReviews.reviewValueName": "Favorable" }}
           }
         }
       }
     },
     "size": 0,
     "track_total_hits": true
   }

   🔥 COMBINAISON RACINE + NESTED (TRÈS IMPORTANT) :
   Question: 'avis favorables dans l'affaire 94P0237518'
   {
     "query": {
       "bool": {
         "must": [
           {"term": {"caseReference": "94P0237518"}},
           {"nested": {
             "path": "reports",
             "query": {
               "nested": {
                 "path": "reports.reportReviews",
                 "query": {
                   "term": {"reports.reportReviews.reviewValueName": "Favorable"}
                 }
               }
             }
           }}
         ]
       }
     },
     "size": 0,
     "track_total_hits": true
   }
   
   🚨 COMPTAGE D'AVIS DANS UNE AFFAIRE SPÉCIFIQUE (NOUVEAU - TRÈS IMPORTANT):
   Question: 'combien d'avis Suspendu dans l'affaire 1360'
   {
     "query": {"term": {"caseId": 1360}},
     "size": 0,
     "aggs": {
       "reports": {
         "nested": {"path": "reports"},
         "aggs": {
           "reviews": {
             "nested": {"path": "reports.reportReviews"},
             "aggs": {
               "count_avis": {
                 "filter": {"term": {"reports.reportReviews.reviewValueName": "Suspendu"}}
               }
             }
           }
         }
       }
     }
   }

   🚨 COMPTAGE D'AVIS AVEC FILTRE COMBINÉ:
   Question: 'combien d'avis Favorable dans l'affaire 94P0237518 dont le manager est William BAANNAAA'
   {
     "query": {
       "bool": {
         "must": [
           {"term": {"caseReference": "94P0237518"}},
           {"term": {"caseManager.keyword": "William BAANNAAA"}}
         ]
       }
     },
     "size": 0,
     "aggs": {
       "reports": {
         "nested": {"path": "reports"},
         "aggs": {
           "reviews": {
             "nested": {"path": "reports.reportReviews"},
             "aggs": {
               "count_avis": {
                 "filter": {"term": {"reports.reportReviews.reviewValueName": "Favorable"}}
               }
             }
           }
         }
       }
     }
   }

   EXEMPLE CORRECT - RÉFÉRENCES DES RAPPORTS DANS UNE AFFAIRE :
   Question: 'références des rapports dans l'affaire 94P0237518 dont le manager est Patrick Trouvé'
   {
     "query": {
       "bool": {
         "must": [
           {"term": {"caseReference": "94P0237518"}},
           {"term": {"caseManager.keyword": "Patrick Trouvé"}}
         ]
       }
     },
     "_source": ["caseId", "caseReference", "caseManager", "reports"]
   }
   
   EXEMPLE CORRECT pour "combien de rapports":
   {
     "size": 0,
     "aggs": {
        "total_reports": {
          "sum": {"field": "reportsCount"}
        }
     }
   }

   AGGREGATION PAR CLIENT (normalisé - recommandé):
   {
     "aggs": {
       "clients": {
         "terms": { "field": "caseClient.normalized" }
       }
     },
     "size": 0
   }

   AGGREGATION PAR AGENCE (normalisé - recommandé):
   {
     "aggs": {
       "agencies": {
         "terms": { "field": "caseAgency.normalized" }
       }
     },
     "size": 0
   }

   ❌ ERREURS FRÉQUENTES À ÉVITER :
   • _source: ["reports.reportReference"] → ❌ FAUX
   • _source: ["reports"] → ✅ CORRECT
   • Nested dans bool/must sans structure correcte → ❌ FAUX
   • Bool/must avec term racine + nested séparé → ✅ CORRECT

QUERY_RULES;

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
        return <<<INFO
5. OPTIMISATION POUR INFO:
   - Utiliser size: 0 pour les comptages
   - Utiliser aggregations pour les statistiques
   - Limiter les champs retournés avec _source si nécessaire
   - Optimiser pour la vitesse de réponse
   - 🚨 RAPPEL COMPTAGE: Pour compter des avis, utiliser aggregations nested, PAS track_total_hits

INFO;
    }

    private function getDownloadSpecificInstructions(): string
    {
        return <<<DOWNLOAD
5. OPTIMISATION POUR TÉLÉCHARGEMENT:
   - SIMPLE: Récupérer uniquement reports.reportS3Path
   - _source: ["reports.reportS3Path"] suffit pour le téléchargement
   - LIMITE PRAGMATIQUE: Éviter les téléchargements massifs (>100 fichiers)
   - RÈGLE SIMPLE: Une affaire = environ 5-15 fichiers en moyenne
   - CALCUL CONSERVATEUR: size: 8 pour rester sous 100 fichiers (~8×12=96)
   - EXCEPTION: Si affaire unique (recherche par référence), pas de limite
   - PAS de filtre sur reportImported: tous les rapports sont téléchargeables
   - Le service utilisera directement les chemins S3

   RÈGLES DE LIMITATION:
   - Recherche par AFFAIRE SPÉCIFIQUE (caseReference): pas de limite (1 seule affaire)
   - Recherche par MANAGER/CLIENT: size: 8 (estimation: 8×12≈100 fichiers max)
   - Recherche LARGE (range, multi-critères): size: 5 (très prudent)
   - TOUJOURS préciser dans un commentaire le nombre d'affaires limitées

   EXEMPLES DE REQUÊTES TÉLÉCHARGEMENT:
   Pour une affaire spécifique (pas de limite):
   {
     "query": {"term": {"caseReference": "[REFERENCE_AFFAIRE]"}},
     "_source": ["reports.reportS3Path"]
   }

   Pour un manager (limite conservative):
   {
     "query": {"term": {"caseManager.keyword": "[NOM_MANAGER]"}},
     "_source": ["reports.reportS3Path"],
     "size": 8
   }

   Pour plusieurs affaires (limite stricte):
   {
     "query": {"range": {"reportsCount": {"gt": 5}}},
     "_source": ["reports.reportS3Path"],
     "size": 5
   }

   AVERTISSEMENT À INCLURE:
   - Toujours préciser dans la réponse le nombre estimé de fichiers
   - Suggérer de préciser la recherche si trop de résultats
   - Mentionner la possibilité de filtrer par date ou référence

DOWNLOAD;
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