# Optimisation pour Intent DOWNLOAD

## Règles spécifiques TÉLÉCHARGEMENT

### Approche simple
- Récupérer uniquement `reports.reportS3Path`
- `_source: ["reports.reportS3Path"]` suffit pour le téléchargement

### Limites pragmatiques
- **Éviter les téléchargements massifs** (>100 fichiers)
- **Règle simple :** Une affaire = environ 5-15 fichiers en moyenne
- **Calcul conservateur :** `size: 8` pour rester sous 100 fichiers (~8×12=96)

### Exceptions
- **Si affaire unique** (recherche par référence), pas de limite
- **PAS de filtre sur reportImported :** tous les rapports sont téléchargeables

## Règles de limitation

### Recherche par AFFAIRE SPÉCIFIQUE
```json
{
  "query": {"term": {"caseReference": "[REFERENCE_AFFAIRE]"}},
  "_source": ["reports.reportS3Path"]
}
```
**→ Pas de limite (1 seule affaire)**

### Recherche par MANAGER/CLIENT
```json
{
  "query": {"term": {"caseManager.keyword": "[NOM_MANAGER]"}},
  "_source": ["reports.reportS3Path"],
  "size": 8
}
```
**→ Limite conservative (estimation: 8×12≈100 fichiers max)**

### Recherche LARGE
```json
{
  "query": {"range": {"reportsCount": {"gt": 5}}},
  "_source": ["reports.reportS3Path"],
  "size": 5
}
```
**→ Limite stricte (très prudent)**

## Exemples de requêtes DOWNLOAD

### Pour une affaire spécifique
```json
{
  "query": {"term": {"caseReference": "94P0237518"}},
  "_source": ["reports.reportS3Path"]
}
```

### Pour un manager (limité)
```json
{
  "query": {"term": {"caseManager.keyword": "William BAANNAAA"}},
  "_source": ["reports.reportS3Path"],
  "size": 8
}
```

### Pour plusieurs affaires (très limité)
```json
{
  "query": {"range": {"reportsCount": {"gt": 5}}},
  "_source": ["reports.reportS3Path"],
  "size": 5
}
```

## Avertissements à inclure

- Toujours préciser dans la réponse le nombre estimé de fichiers
- Suggérer de préciser la recherche si trop de résultats
- Mentionner la possibilité de filtrer par date ou référence
