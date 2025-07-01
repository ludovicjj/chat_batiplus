# ChatBot

Chatbot intelligent pour interroger les données Elasticsearch avec du langage naturel.
vous pouvez utiliser la structure du projet et l'adapter a votre propre use case
Ce projet a ete concu en collaboration avec une IA.

## Fonctionnalités

- **Questions naturelles** → Requêtes Elasticsearch automatiques
- **RAG (Retrieval-Augmented Generation)** → Apprentissage par exemples
- **Streaming SSE** → Réponses en temps réel
- **Sécurité** → Validation des requêtes générées
- **Dates dynamiques** → Gestion automatique des périodes

## Workflow

```
Question utilisateur
    ↓
1. Normalisation de la question
2. Classification d'intent (INFO/CHITCHAT/DOWNLOAD)  
3. Recherche d'exemples similaires (RAG - micro service dockerisé)
4. Génération query Elasticsearch (LLM)
5. Validation sécurité
6. Exécution sur Elasticsearch
7. Réponse humaine (LLM + streaming)
```

## Cas d'usage supportés

### Comptage
- `"Combien d'affaires au total ?"`
- `"Nombre de rapports créés cette année"`
- `"Affaires en travaux"`

### Recherche d'informations
- `"Donne-moi des infos sur l'affaire ID 1360"`
- `"Détails de l'affaire référence 94P0237518"`

### Filtres temporels
- `"Rapports validés ce mois"`
- `"Affaires créées cette année"`

### Filtres de statut
- `"Affaires en conception/travaux/réception"`
- `"Répartition par statut"`

## Installation

```bash
# Dépendances
composer install
npm install

# Configuration
cp .env .env.local
# Configurer DATABASE_URL, ELASTICSEARCH_URL, etc.

# Base de données
php bin/console doctrine:migrations:migrate

# Assets
npm run build
```

## Configuration

### Elasticsearch
```yaml
# config/elasticsearch/mapping.yaml
# Définit la structure des données
```

### RAG Dataset
```json
# config/rag/dataset_examples.json
# Exemples d'apprentissage question/query
```

### Prompts LLM
```
# config/elasticsearch/prompts/
├── base.md              # Instructions de base
├── rules-core.md        # Règles JSON/syntaxe  
├── rules-fields.md      # Champs disponibles
└── rules-counting.md    # Patterns de comptage
```

## Commandes principales

### RAG (Retrieval-Augmented Generation)

```bash
# Charger les exemples RAG en base
php bin/console rag:test --action=add --reset

# Tester la similarité des questions
php bin/console rag:test --action=similarity

# Statistiques du dataset
php bin/console rag:test --action=stats

# Vérifier la santé du service d'embedding
php bin/console rag:test --action=health

# Vérifier les connexions DB
php bin/console rag:test --action=connection
```

### Test du ChatBot

```bash
# Test complet du workflow
php bin/console chatbot:test

# Le test inclut automatiquement :
# - Normalisation de la question
# - Classification d'intent  
# - Recherche RAG d'exemples similaires
# - Génération de query Elasticsearch
# - Validation sécurité
# - Exécution et réponse
```

### Enrichissement RAG

```bash
# Générer de nouveaux exemples (développement)
php bin/console rag:enrich --preview
php bin/console rag:enrich --category=temporal --count=5
php bin/console rag:enrich --generate
```

##  Architecture technique

### Services principaux

- **`RagService`** → Gestion des exemples d'apprentissage
- **`ElasticsearchGeneratorService`** → Génération de requêtes
- **`HumanResponseService`** → Réponses en langage naturel
- **`ServerSentEventService`** → Streaming temps réel

### Sécurité

- Validation des requêtes générées
- Sanitisation des inputs utilisateur
- Limitation des champs accessibles

### Performance

- Cache des embeddings RAG
- Streaming SSE pour UX fluide
- Gestion des timeouts et mémoire

## Métriques & Monitoring

### Scores RAG
- **>85%** → Match excellent
- **70-85%** → Match bon
- **<70%** → Pas de match utilisé

### Questions supportées
- **~80%** des questions métier courantes
- **25+ patterns** Elasticsearch couverts
- **50+ variations** linguistiques

## 🧪 Développement

### Ajouter un nouveau cas d'usage

1. **Créer l'exemple** dans `dataset_examples.json`
```json
{
  "questions": ["Nouvelle question ?", "Variante question"],
  "query": "{\"query\": {...}}",
  "intent": "INFO",
  "metadata": {...},
  "tags": [...]
}
```

2. **Recharger le RAG**
```bash
php bin/console rag:test --action=add --reset
```

3. **Tester**
```bash
php bin/console rag:test --action=similarity
```

### Debug

```bash
# Logs des requêtes générées
tail -f var/log/dev.log | grep "Elasticsearch"

# Test d'une question spécifique  
# Modifier la question dans ChatbotTestCommand.php
php bin/console chatbot:test
```

## Statut du projet

- ✅ **POC fonctionnel**
- ✅ **Cas d'usage métier couverts**
- ✅ **Architecture évolutive**
- 🔄 **En amélioration continue**