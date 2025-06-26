# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

ChatBot BatiPlus is a secure AI-powered chatbot for querying construction company data. It's a Symfony 7.3 application that translates natural language questions into SQL queries while maintaining strict security controls.

**Tech Stack:**
- Backend: PHP 8.3+ with Symfony 7.3
- Database: MySQL/MariaDB (read-only access)
- AI: OpenAI GPT-4
- Frontend: JavaScript, Bootstrap 5, Sass, Webpack Encore
- Storage: AWS S3
- Real-time: Server-Sent Events (SSE)

## Development Commands

### Setup and Run
```bash
# Install dependencies
composer install
npm install

# Run development server
symfony server:start
# OR
php -S localhost:8000 -t public/

# Build frontend assets
npm run dev      # One-time build
npm run watch    # Watch mode
npm run build    # Production build
```

### Database
```bash
# Run migrations
php bin/console doctrine:migrations:migrate

# Clear cache
php bin/console cache:clear

# Update schema (dev only)
php bin/console doctrine:schema:update --force
```

### Testing
```bash
# Test chatbot functionality (when enabled)
php bin/console chatbot:test
php bin/console chatbot:test --schema      # Show DB schema
php bin/console chatbot:test --security    # Test SQL validation
php bin/console chatbot:test -q "Question" # Test specific question
```

## Architecture

### Request Flow
1. **API Endpoints**:
   - `POST /api/chatbot/ask` - Standard JSON response
   - `POST /api/chatbot/ask-stream-llm` - Streaming SSE response

2. **Processing Pipeline**:
   ```
   Request → RequestValidator → ChatbotService → IntentService
                                      ↓
                              SqlGeneratorService
                                      ↓
                              SqlSecurityService
                                      ↓
                              SqlExecutorService
                                      ↓
                              HumanResponseService
                                      ↓
                              [If DOWNLOAD: ReportArchiveService]
   ```

3. **Security Layers**:
   - Read-only database user
   - SQL query validation (SELECT only)
   - Whitelisted tables (via ALLOWED_TABLES env)
   - Blocked dangerous keywords
   - Request validation

### Key Services

- **ChatbotService** (`src/Service/Chatbot/`): Main orchestrator
- **LLM Services** (`src/Service/LLM/`):
  - IntentService: Classifies intent (INFO/DOWNLOAD)
  - SqlGeneratorService: Natural language → SQL
  - HumanResponseService: Results → Human text
- **SQL Services** (`src/Service/SQL/`):
  - SqlSecurityService: Validates query safety
  - SqlExecutorService: Executes validated queries
  - SqlSchemaService: Caches DB structure
- **Archive Services** (`src/Service/Archive/`): ZIP file generation
- **Streaming** (`src/Service/Streaming/`): Real-time SSE responses

### Business Rules

1. **Intent Types**:
   - INFO: General queries, statistics
   - DOWNLOAD: File retrieval requests

2. **Default Filters** (unless keywords like "tous" present):
   - Excludes deleted items: `deleted_at IS NULL`
   - Only active items: `is_enabled = TRUE`

3. **Limits**:
   - Query timeout: 30 seconds
   - Download limit: 50 results
   - Question length: 3-500 characters

## Environment Configuration

Required `.env` variables:
```
# Core
APP_ENV=dev
DATABASE_URL="mysql://user:pass@127.0.0.1:3306/db"
OPENAI_API_KEY="sk-..."

# Security
ALLOWED_TABLES="table1,table2,table3"
MAX_QUERY_EXECUTION_TIME=30

# AWS S3
AWS_ACCESS_KEY_ID=""
AWS_SECRET_ACCESS_KEY=""
AWS_REGION="eu-west-1"
AWS_S3_BUCKET=""
```

## Common Development Tasks

### Adding New LLM Features
1. Extend `AbstractLLMService` in `src/Service/LLM/`
2. Implement the service logic
3. Register in `services.yaml` if needed
4. Update `ChatbotService` or `StreamingResponseService` to use it

### Modifying SQL Security Rules
1. Edit `src/Service/SQL/SqlSecurityService.php`
2. Update `BLOCKED_KEYWORDS` or `ALLOWED_FUNCTIONS`
3. Add tests to verify security rules

### Adding New API Endpoints
1. Add method to `src/Controller/ChatbotController.php`
2. Create RequestHandler/Validator if needed
3. Update routing in `config/routes.yaml` or use annotations

### Frontend Changes
1. JavaScript: Edit files in `assets/js/`
2. Styles: Edit SCSS in `assets/styles/`
3. Run `npm run watch` for live reload

## Error Handling

- All exceptions are caught by `ExceptionSubscriber`
- Custom exceptions: `ValidatorException`, `UnsafeSqlException`
- Errors return appropriate HTTP status codes with safe messages
- Development mode shows detailed errors, production mode shows generic messages