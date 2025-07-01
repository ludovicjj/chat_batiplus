<?php

declare(strict_types=1);

namespace DoctrineMigrations\Rag;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250630233853 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // Add pgvector extension pgvector
        $this->addSql('CREATE EXTENSION IF NOT EXISTS vector');

        $this->addSql(<<<'SQL'
            CREATE SEQUENCE rag_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE rag (id INT NOT NULL, question TEXT NOT NULL, query TEXT NOT NULL, intent VARCHAR(50) NOT NULL, embedding vector(384) DEFAULT NULL, metadata JSON DEFAULT NULL, tags TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, active BOOLEAN NOT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX rag_intent_idx ON rag (intent)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX rag_active_idx ON rag (active)
        SQL);

        $this->addSql('CREATE INDEX rag_embedding_hnsw_idx ON rag USING hnsw (embedding vector_cosine_ops)');
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN rag.tags IS '(DC2Type:simple_array)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN rag.created_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN rag.updated_at IS '(DC2Type:datetime_immutable)'
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SCHEMA public
        SQL);
        $this->addSql(<<<'SQL'
            DROP SEQUENCE rag_id_seq CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE rag
        SQL);
    }
}
