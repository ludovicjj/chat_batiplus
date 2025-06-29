# Règles de comptage - CRITIQUES

## 🚨 RÈGLE CRITIQUE COMPTAGE SIMPLE

**Pour "combien d'affaires pour [ENTITÉ]" :**
- ✅ UTILISER UNIQUEMENT : `{"query": {...}, "size": 0, "track_total_hits": true}`
- ❌ NE JAMAIS ajouter d'aggregations pour un comptage simple
- Le résultat sera dans `hits.total.value`
- Les aggregations sont SEULEMENT pour les répartitions ("répartition par client")

## 🚨 RÈGLES CRITIQUES POUR COMPTAGE D'AVIS

**Quand l'utilisateur demande "combien d'avis [TYPE]" :**
- Il faut compter les AVIS INDIVIDUELS, PAS les affaires
- ❌ NE JAMAIS utiliser `"size": 0` avec `"track_total_hits": true` pour compter des avis
- ✅ TOUJOURS utiliser des aggregations nested pour compter les avis
- Le résultat du comptage sera dans `aggregations.reports.reviews.count_avis.doc_count`

## 🔥 RÈGLE CRITIQUE - CHAMPS NESTED

**Pour obtenir les données :**
- **Rapports** : `_source` doit inclure `'reports'` (PAS `'reports.reportReference'`)
- **Avis** : `_source` doit inclure `'reports'` (contient reportReviews)
- **Les champs nested ne peuvent pas être récupérés individuellement !**

### Exemple CORRECT pour références des rapports
```json
{
  "_source": ["caseId", "caseReference", "reports"]
}
```

### ❌ INCORRECT
```json
{
  "_source": ["reports.reportReference"]
}
```
