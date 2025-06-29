# Gestion des champs vides et NULL

## Règles pour les champs vides

**Expressions utilisateur :**
- "sans manager", "pas de manager", "manager vide" → `{"term": {"caseManager.keyword": ""}}`
- "sans client", "pas de client", "client vide" → `{"term": {"caseClient.keyword": ""}}`
- "sans agence", "pas d'agence", "agence vide" → `{"term": {"caseAgency.keyword": ""}}`

## Principe général

**"sans [CHAMP]" = champ vide (""), PAS champ inexistant**

### ✅ CORRECT
```json
{"term": {"caseManager.keyword": ""}}
```

### ❌ INCORRECT
```json
{"must_not": {"exists": {"field": "caseManager"}}}
```

## Exemples pratiques

### Affaires sans manager
```json
{
  "query": {"term": {"caseManager.keyword": ""}},
  "size": 0,
  "track_total_hits": true
}
```

### Affaires sans client
```json
{
  "query": {"term": {"caseClient.keyword": ""}},
  "size": 0,
  "track_total_hits": true
}
```
