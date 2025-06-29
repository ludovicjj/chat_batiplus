# Exemples Elasticsearch - Requêtes simples

## Comptage de toutes les affaires

**Question :** "Combien d'affaires au total ?"

```json
{
  "query": {"match_all": {}},
  "size": 0,
  "track_total_hits": true
}
```

## Recherche par ID

**Question :** "Affaire avec l'ID 869"

```json
{
  "query": {"term": {"caseId": 869}},
  "_source": ["caseClient"]
}
```

## Recherche par référence

**Question :** "Affaire 94P0237518"

```json
{
  "query": {"term": {"caseReference": "94P0237518"}}
}
```

## Recherche par manager

**Question :** "Affaires pour le manager William BAANNAAA"

```json
{
  "query": {"term": {"caseManager.keyword": "William BAANNAAA"}},
  "size": 0,
  "track_total_hits": true
}
```

## Recherche par client

**Question :** "Affaires pour le client APHP"

```json
{
  "query": {"term": {"caseClient.keyword": "APHP"}},
  "size": 0,
  "track_total_hits": true
}
```
