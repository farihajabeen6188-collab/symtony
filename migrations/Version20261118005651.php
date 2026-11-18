<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261118005651 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE mcp_token (id UUID NOT NULL, label VARCHAR(80) NOT NULL, scopes JSON NOT NULL, token_hash VARCHAR(64) NOT NULL, prefix VARCHAR(16) NOT NULL, created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, last_used_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL, revoked_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL, workspace_id UUID NOT NULL, created_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_mcp_token_workspace ON mcp_token (workspace_id, created_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_mcp_token_hash ON mcp_token (token_hash)');
        $this->addSql('CREATE INDEX IDX_9DE4E92A82D40A1F ON mcp_token (workspace_id)');
        $this->addSql('CREATE INDEX IDX_9DE4E92AB03A8386 ON mcp_token (created_by_id)');
        $this->addSql('ALTER TABLE mcp_token ADD CONSTRAINT FK_9DE4E92A82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspace (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE mcp_token ADD CONSTRAINT FK_9DE4E92AB03A8386 FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE mcp_token DROP CONSTRAINT FK_9DE4E92A82D40A1F');
        $this->addSql('ALTER TABLE mcp_token DROP CONSTRAINT FK_9DE4E92AB03A8386');
        $this->addSql('DROP TABLE mcp_token');
    }
}
