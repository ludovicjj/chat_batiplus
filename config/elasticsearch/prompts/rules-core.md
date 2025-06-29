# Règles Elasticsearch - Core

## Règles de requête fondamentales

### Types de requêtes
- **Comptages simples** : `size: 0` et `track_total_hits: true`
- **Recherche texte** : `match` sur les champs text
- **Filtrage exact** : `term` sur les champs keyword
- **Champs integer** : `term` avec valeur numérique (ex: `"caseId": 123`)

### Gestion des champs keyword

#### ⚠️ ATTENTION - Types de champs différents

**Champs DIRECTEMENT keyword (SANS .keyword) :**
- `reviewValueName`
- `reviewValueCode` 
- `reviewDomain`

**Champs text AVEC .keyword :**
- `reviewCreatedBy` → `reviewCreatedBy.keyword`
- `caseManager` → `caseManager.keyword`
- `caseClient` → `caseClient.keyword`

#### Exemples corrects
```json
{"term": {"reports.reportReviews.reviewValueName": "Favorable"}}
{"term": {"caseManager.keyword": "William BAANNAAA"}}
```

### Champs spéciaux

**caseId est INTEGER, pas keyword !**
```json
{"term": {"caseId": 869}}
```

**❌ INCORRECT :**
```json
{"term": {"caseId.keyword": "869"}}
```

**caseTitle - Recherche exacte par titre :**
- Pour titre exact : utiliser `caseTitle.keyword`
- Pour recherche textuelle : utiliser `caseTitle`

**Exemple recherche exacte :**
```json
{"term": {"caseTitle.keyword": "TITRE EXACT DE L'AFFAIRE"}}
```

### Agrégations

**Pour agrégations normalisées :**
- `caseClient.normalized`
- `caseAgency.normalized`

**Pour agrégations exactes :**
- Champs `.keyword`
