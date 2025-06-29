# Exemples Elasticsearch - Requêtes complexes

## Recherche dans les rapports (NESTED SIMPLE)

**Question :** "Rapports avec référence AD-001"

```json
{
  "query": {
    "nested": {
      "path": "reports",
      "query": {"term": {"reports.reportReference": "AD-001"}}
    }
  }
}
```

## Recherche dans les avis (DOUBLE NESTED)

**Question :** "Avis favorables"

```json
{
  "query": {
    "nested": {
      "path": "reports",
      "query": {
        "nested": {
          "path": "reports.reportReviews",
          "query": {"term": {"reports.reportReviews.reviewValueName": "Favorable"}}
        }
      }
    }
  },
  "size": 0,
  "track_total_hits": true
}
```

## 🔥 COMBINAISON RACINE + NESTED (TRÈS IMPORTANT)

**Question :** "Avis favorables dans l'affaire 94P0237518"

```json
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
              "query": {"term": {"reports.reportReviews.reviewValueName": "Favorable"}}
            }
          }
        }}
      ]
    }
  },
  "size": 0,
  "track_total_hits": true
}
```

## 🚨 COMPTAGE D'AVIS DANS UNE AFFAIRE SPÉCIFIQUE

**Question :** "Combien d'avis Suspendu dans l'affaire 1360"

```json
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
```

## 🚨 COMPTAGE D'AVIS AVEC FILTRE COMBINÉ

**Question :** "Combien d'avis Favorable dans l'affaire 94P0237518 dont le manager est William BAANNAAA"

```json
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
```

## Exemple CORRECT - Références des rapports

**Question :** "Références des rapports dans l'affaire 94P0237518 dont le manager est Patrick Trouvé"

```json
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
```

## Comptage de rapports par titre exact

**Question :** "Combien de rapports dans l'affaire TITRE EXACT"

```json
{
  "query": {"term": {"caseTitle.keyword": "TITRE EXACT DE L'AFFAIRE"}},
  "size": 0,
  "aggs": {
    "total_reports": {
      "sum": {"field": "reportsCount"}
    }
  }
}
```

## Comptage de rapports au total

**Question :** "Combien de rapports"

```json
{
  "size": 0,
  "aggs": {
    "total_reports": {
      "sum": {"field": "reportsCount"}
    }
  }
}
```

## Agrégations par entité

### Par client (normalisé)
```json
{
  "aggs": {
    "clients": {
      "terms": {"field": "caseClient.normalized"}
    }
  },
  "size": 0
}
```

### Par agence (normalisé)
```json
{
  "aggs": {
    "agencies": {
      "terms": {"field": "caseAgency.normalized"}
    }
  },
  "size": 0
}
```
