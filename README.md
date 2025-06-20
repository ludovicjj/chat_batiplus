# ChatBot BatiPlus

Assistant virtuel sécurisé pour interroger les données d'une entreprise de bâtiment.

## Fonctionnalités

- **Sécurité renforcée** : Validation stricte des requêtes SQL, accès en lecture seule
- **Interface LLM** : Utilise GPT-4 pour transformer les questions en requêtes SQL et les résultats en réponses compréhensibles
- **Interface web** : Chat interface moderne et responsive
- **Logging complet** : Traçabilité de toutes les opérations
- **API REST** : Endpoints pour intégration avec d'autres systèmes

## Installation

### 1. Prérequis

- PHP 8.3+
- Composer
- MySQL/MariaDB
- Clé API OpenAI

### 2. Installation des dépendances

```bash
composer install
```

### 3. Configuration de la base de données

#### Créer un utilisateur en lecture seule

```sql
-- Créer l'utilisateur
CREATE USER 'readonly_user'@'localhost' IDENTIFIED BY 'secure_password';

-- Accorder uniquement les privilèges SELECT sur la base de données
GRANT SELECT ON company_db.* TO 'readonly_user'@'localhost';

-- Appliquer les changements
FLUSH PRIVILEGES;
```

#### Structure de base de données attendue

Le système attend ces tables (configurables via ALLOWED_TABLES) :

- `clients` : Informations clients
- `projets` : Projets de construction
- `interventions` : Interventions techniques
- `factures` : Factures émises
- `devis` : Devis proposés

## Utilisation

### 1. Démarrer le serveur de développement

```bash
symfony server:start
```

Ou avec PHP :

```bash
php -S localhost:8000 -t public/
```

### 2. Accéder à l'interface

- Interface chat : `http://localhost:8000`

### 3. API REST

#### Poser une question

```bash
curl -X POST http://localhost:8000/api/chatbot/ask \
  -H "Content-Type: application/json" \
  -d '{"question": "Combien de collaborateurs actifs ?"}'
```

#### Vérifier le statut

```bash
curl http://localhost:8000/api/chatbot/status
```

### 4. Commandes de test

```bash
# Test complet du système
php bin/console chatbot:test

# Afficher le schéma de la base
php bin/console chatbot:test --schema

# Tester la sécurité SQL
php bin/console chatbot:test --security

# Tester avec une question
php bin/console chatbot:test -q "Combien de clients avons-nous ?"
```

## Architecture

### Services principaux

1. **ChatbotService** : Orchestrateur principal
2. **LlmService** : Communication avec GPT-4
3. **SqlSecurityService** : Validation et sécurisation SQL
4. **DatabaseSchemaService** : Gestion du schéma de données

### Sécurité

#### Mesures de protection

- ✅ Utilisateur BDD en lecture seule
- ✅ Validation stricte des requêtes SQL
- ✅ Whitelist des tables autorisées
- ✅ Timeout sur les requêtes
- ✅ Blocage des mots-clés dangereux
- ✅ Logging de toutes les tentatives

#### Mots-clés bloqués

```
INSERT, UPDATE, DELETE, DROP, ALTER, CREATE, TRUNCATE, 
REPLACE, MERGE, CALL, EXEC, EXECUTE, GRANT, REVOKE,
LOAD, OUTFILE, DUMPFILE, INTO, INFORMATION_SCHEMA
```

## Exemples de questions

- "Combien de clients avons-nous ?"
- "Quels sont les projets en cours ?"
- "Montant total des factures de ce mois"
- "Liste des interventions de la semaine dernière"
- "Clients avec le plus de projets"
- "Évolution du chiffre d'affaires"

## Monitoring et logs

### Fichiers de logs

- `var/log/dev.log` : Logs généraux
- `var/log/security.log` : Événements de sécurité
- `var/log/chatbot.log` : Opérations du chatbot

### Surveillance

Surveillez ces métriques :

- Tentatives de requêtes non autorisées
- Temps de réponse des requêtes
- Erreurs de l'API LLM
- Utilisation des ressources

## Troubleshooting

### Problèmes courants

1. **Erreur de connexion base de données**
   - Vérifiez `DATABASE_URL`
   - Testez la connexion utilisateur

2. **Erreur API OpenAI**
   - Vérifiez `OPENAI_API_KEY`
   - Contrôlez les quotas API

3. **Tables non trouvées**
   - Vérifiez `ALLOWED_TABLES`
   - Confirmez l'existence des tables

### Debug

```bash
# Vérifier la configuration
php bin/console debug:config

# Tester la base de données
php bin/console dbal:run-sql "SELECT 1"

# Effacer le cache
php bin/console cache:clear
```

## Développement

### Ajouter de nouvelles fonctionnalités

1. **Nouvelle table** : Ajoutez-la à `ALLOWED_TABLES`
2. **Nouveau type de question** : Étendez les prompts dans `LlmService`
3. **Nouvelle validation** : Modifiez `SqlSecurityService`

### Tests

```bash
# Tests unitaires (à implémenter)
php bin/phpunit

# Tests d'intégration
php bin/console chatbot:test
```

## Contribution

1. Fork le projet
2. Créez une branche (`git checkout -b feature/nouvelle-fonctionnalite`)
3. Committez (`git commit -am 'Ajout nouvelle fonctionnalité'`)
4. Push (`git push origin feature/nouvelle-fonctionnalite`)
5. Créez une Pull Request
