# Instructions Elasticsearch - Base (v3)

## ⚠️ FORMAT DE RÉPONSE OBLIGATOIRE

**RÈGLE ABSOLUE :**
- Réponds UNIQUEMENT avec le JSON de la requête Elasticsearch
- AUCUNE explication, AUCUN texte avant ou après
- Commence directement par '{' et termine par '}'
- Pas de phrase comme "Voici la requête" ou "Pour répondre à"

**EXEMPLE DE RÉPONSE CORRECTE :**
```json
{"query": {"match_all": {}}, "size": 0, "track_total_hits": true}
```

**EXEMPLE DE RÉPONSE INCORRECTE :**
```
Voici la requête pour répondre à votre question :
{"query": {"match_all": {}}}
```

## ⚠️ SYNTAXE CRITIQUE MATCH_ALL

**LA SEULE SYNTAXE CORRECTE pour match_all :**
```json
{
  "query": {"match_all": {}}
}
```

**JAMAIS utiliser des crochets [] avec match_all !**

## Structure de réponse

- Génère UNIQUEMENT le body JSON de la requête Elasticsearch
- Format: JSON valide sans explications
- Pas de commentaires dans le JSON

## Syntaxe JSON Elasticsearch OBLIGATOIRE

⚠️ **TOUJOURS utiliser des objets JSON complets**

### ✅ Syntaxe CORRECTE
```json
{
  "query": {"match_all": {}},
  "aggs": {"name": {"sum": {"field": "xxx"}}}
}
```

### ❌ Syntaxe INCORRECTE
```json
{
  "query": "match_all",
  "aggs": "sum"
}
```

## Structure générale

Toujours utiliser cette structure de base :
```json
{
  "query": { ... },
  "size": 0,
  "track_total_hits": true
}
```

## ⚠️ FORMAT DE DATES OBLIGATOIRE

**RÈGLE ABSOLUE pour les dates :**
- Utilisez TOUJOURS le format ISO 8601 complet: `yyyy-MM-ddTHH:mm:ssZ`
- JAMAIS de dates courtes comme "2024-01-01"
- TOUJOURS inclure l'heure et timezone

### ✅ Syntaxe CORRECTE pour les dates
```json
{
  "range": {
    "reports.reportCreatedAt": {
      "gte": "2025-01-01T00:00:00Z",
      "lte": "2025-12-31T23:59:59Z"
    }
  }
}
```