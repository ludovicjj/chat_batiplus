# Optimisation pour Intent INFO

## Règles spécifiques INFO

- Utiliser `size: 0` pour les comptages
- Utiliser aggregations pour les statistiques  
- Limiter les champs retournés avec `_source` si nécessaire
- Optimiser pour la vitesse de réponse

## 🚨 RAPPEL COMPTAGE

**Pour compter des avis, utiliser aggregations nested, PAS track_total_hits**

## Focus performance

- Minimiser les champs `_source` 
- Utiliser les champs `.normalized` pour les aggregations
- Préférer `term` à `match` quand possible

## Exemples optimisés INFO

### Comptage simple optimisé
```json
{
  "query": {"match_all": {}},
  "size": 0,
  "track_total_hits": true
}
```

### Aggregation optimisée
```json
{
  "aggs": {
    "stats": {
      "terms": {
        "field": "caseClient.normalized",
        "size": 20
      }
    }
  },
  "size": 0
}
```
