<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261118013155 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE assistant_message (id UUID NOT NULL, role VARCHAR(12) NOT NULL, content TEXT NOT NULL, tool_calls JSON NOT NULL, evidence JSON NOT NULL, source VARCHAR(160) DEFAULT NULL, created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, workspace_id UUID NOT NULL, incident_id UUID NOT NULL, investigation_id UUID DEFAULT NULL, user_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_assistant_incident_time ON assistant_message (incident_id, created_at)');
        $this->addSql('CREATE INDEX IDX_8A36E1EF82D40A1F ON assistant_message (workspace_id)');
        $this->addSql('CREATE INDEX IDX_8A36E1EF59E53FB9 ON assistant_message (incident_id)');
        $this->addSql('CREATE INDEX IDX_8A36E1EFDC64C387 ON assistant_message (investigation_id)');
        $this->addSql('CREATE INDEX IDX_8A36E1EFA76ED395 ON assistant_message (user_id)');
        $this->addSql('ALTER TABLE assistant_message ADD CONSTRAINT FK_8A36E1EF82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspace (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE assistant_message ADD CONSTRAINT FK_8A36E1EF59E53FB9 FOREIGN KEY (incident_id) REFERENCES incident (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE assistant_message ADD CONSTRAINT FK_8A36E1EFDC64C387 FOREIGN KEY (investigation_id) REFERENCES investigation (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE assistant_message ADD CONSTRAINT FK_8A36E1EFA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE assistant_message DROP CONSTRAINT FK_8A36E1EF82D40A1F');
        $this->addSql('ALTER TABLE assistant_message DROP CONSTRAINT FK_8A36E1EF59E53FB9');
        $this->addSql('ALTER TABLE assistant_message DROP CONSTRAINT FK_8A36E1EFDC64C387');
        $this->addSql('ALTER TABLE assistant_message DROP CONSTRAINT FK_8A36E1EFA76ED395');
        $this->addSql('DROP TABLE assistant_message');
    }
}
