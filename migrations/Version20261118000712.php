<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261118000712 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE evidence_item (id UUID NOT NULL, ref VARCHAR(12) NOT NULL, kind VARCHAR(12) NOT NULL, event_id UUID DEFAULT NULL, label VARCHAR(255) NOT NULL, excerpt TEXT NOT NULL, data JSON NOT NULL, investigation_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_evidence_ref ON evidence_item (investigation_id, ref)');
        $this->addSql('CREATE INDEX IDX_91A41B87DC64C387 ON evidence_item (investigation_id)');
        $this->addSql('CREATE TABLE finding (id UUID NOT NULL, kind VARCHAR(12) NOT NULL, statement TEXT NOT NULL, rationale TEXT NOT NULL, confidence VARCHAR(8) NOT NULL, citations JSON NOT NULL, contradictions JSON NOT NULL, source VARCHAR(160) NOT NULL, grounding VARCHAR(10) NOT NULL, grounding_reason TEXT DEFAULT NULL, rank INT NOT NULL, investigation_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_finding_inv ON finding (investigation_id, rank)');
        $this->addSql('CREATE INDEX IDX_A7191336DC64C387 ON finding (investigation_id)');
        $this->addSql('CREATE TABLE investigation (id UUID NOT NULL, trigger VARCHAR(12) NOT NULL, status VARCHAR(12) NOT NULL, engine VARCHAR(160) NOT NULL, prompt_version VARCHAR(16) NOT NULL, focus_event_id UUID DEFAULT NULL, trace_id VARCHAR(64) DEFAULT NULL, summary TEXT DEFAULT NULL, report JSON NOT NULL, usage JSON NOT NULL, error TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, workspace_id UUID NOT NULL, incident_id UUID NOT NULL, requested_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_investigation_ws_created ON investigation (workspace_id, created_at)');
        $this->addSql('CREATE INDEX idx_investigation_incident ON investigation (incident_id, created_at)');
        $this->addSql('CREATE INDEX IDX_C3A58AA682D40A1F ON investigation (workspace_id)');
        $this->addSql('CREATE INDEX IDX_C3A58AA659E53FB9 ON investigation (incident_id)');
        $this->addSql('CREATE INDEX IDX_C3A58AA64DA1E751 ON investigation (requested_by_id)');
        $this->addSql('CREATE TABLE investigation_step (id UUID NOT NULL, stage VARCHAR(32) NOT NULL, status VARCHAR(12) NOT NULL, summary TEXT DEFAULT NULL, error TEXT DEFAULT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, investigation_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_step ON investigation_step (investigation_id, stage)');
        $this->addSql('CREATE INDEX IDX_5C7BA948DC64C387 ON investigation_step (investigation_id)');
        $this->addSql('ALTER TABLE evidence_item ADD CONSTRAINT FK_91A41B87DC64C387 FOREIGN KEY (investigation_id) REFERENCES investigation (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE finding ADD CONSTRAINT FK_A7191336DC64C387 FOREIGN KEY (investigation_id) REFERENCES investigation (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE investigation ADD CONSTRAINT FK_C3A58AA682D40A1F FOREIGN KEY (workspace_id) REFERENCES workspace (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE investigation ADD CONSTRAINT FK_C3A58AA659E53FB9 FOREIGN KEY (incident_id) REFERENCES incident (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE investigation ADD CONSTRAINT FK_C3A58AA64DA1E751 FOREIGN KEY (requested_by_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE investigation_step ADD CONSTRAINT FK_5C7BA948DC64C387 FOREIGN KEY (investigation_id) REFERENCES investigation (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE evidence_item DROP CONSTRAINT FK_91A41B87DC64C387');
        $this->addSql('ALTER TABLE finding DROP CONSTRAINT FK_A7191336DC64C387');
        $this->addSql('ALTER TABLE investigation DROP CONSTRAINT FK_C3A58AA682D40A1F');
        $this->addSql('ALTER TABLE investigation DROP CONSTRAINT FK_C3A58AA659E53FB9');
        $this->addSql('ALTER TABLE investigation DROP CONSTRAINT FK_C3A58AA64DA1E751');
        $this->addSql('ALTER TABLE investigation_step DROP CONSTRAINT FK_5C7BA948DC64C387');
        $this->addSql('DROP TABLE evidence_item');
        $this->addSql('DROP TABLE finding');
        $this->addSql('DROP TABLE investigation');
        $this->addSql('DROP TABLE investigation_step');
    }
}
