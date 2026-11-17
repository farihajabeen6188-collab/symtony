<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261117210539 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE application (id UUID NOT NULL, name VARCHAR(80) NOT NULL, slug VARCHAR(80) NOT NULL, description VARCHAR(500) NOT NULL, environment VARCHAR(16) NOT NULL, framework VARCHAR(16) NOT NULL, ingestion_method VARCHAR(24) NOT NULL, first_event_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_event_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, workspace_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_application_slug ON application (workspace_id, slug)');
        $this->addSql('CREATE INDEX IDX_A45BDDC182D40A1F ON application (workspace_id)');
        $this->addSql('CREATE TABLE audit_log (id UUID NOT NULL, actor_type VARCHAR(12) NOT NULL, actor VARCHAR(180) NOT NULL, action VARCHAR(64) NOT NULL, subject_type VARCHAR(40) DEFAULT NULL, subject_id VARCHAR(64) DEFAULT NULL, metadata JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, workspace_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_audit_ws_time ON audit_log (workspace_id, created_at)');
        $this->addSql('CREATE INDEX IDX_F6E1C0F582D40A1F ON audit_log (workspace_id)');
        $this->addSql('CREATE TABLE event (id UUID NOT NULL, external_id VARCHAR(64) DEFAULT NULL, occurred_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, received_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, severity VARCHAR(12) NOT NULL, type VARCHAR(16) NOT NULL, service VARCHAR(64) NOT NULL, environment VARCHAR(16) NOT NULL, release VARCHAR(64) DEFAULT NULL, name VARCHAR(200) DEFAULT NULL, message TEXT NOT NULL, normalized_message TEXT NOT NULL, trace_id VARCHAR(64) DEFAULT NULL, span_id VARCHAR(32) DEFAULT NULL, parent_span_id VARCHAR(32) DEFAULT NULL, request_id VARCHAR(64) DEFAULT NULL, duration_ms DOUBLE PRECISION DEFAULT NULL, exception_class VARCHAR(255) DEFAULT NULL, exception_message TEXT DEFAULT NULL, stack_trace JSON DEFAULT NULL, fingerprint VARCHAR(64) DEFAULT NULL, context JSONB NOT NULL, source VARCHAR(16) NOT NULL, redactions INT NOT NULL, workspace_id UUID NOT NULL, application_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_event_ws_time ON event (workspace_id, occurred_at)');
        $this->addSql('CREATE INDEX idx_event_app_time ON event (application_id, occurred_at)');
        $this->addSql('CREATE INDEX idx_event_ws_sev_time ON event (workspace_id, severity, occurred_at)');
        $this->addSql('CREATE INDEX idx_event_trace ON event (trace_id)');
        $this->addSql('CREATE INDEX idx_event_request ON event (request_id)');
        $this->addSql('CREATE INDEX idx_event_span ON event (span_id)');
        $this->addSql('CREATE INDEX idx_event_fingerprint ON event (fingerprint)');
        $this->addSql('CREATE UNIQUE INDEX uniq_event_external ON event (application_id, external_id)');
        $this->addSql('CREATE INDEX IDX_3BAE0AA782D40A1F ON event (workspace_id)');
        $this->addSql('CREATE INDEX IDX_3BAE0AA73E030ACD ON event (application_id)');
        $this->addSql('CREATE TABLE ingestion_credential (id UUID NOT NULL, label VARCHAR(80) NOT NULL, prefix VARCHAR(20) NOT NULL, token_hash VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, application_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_credential_hash ON ingestion_credential (token_hash)');
        $this->addSql('CREATE INDEX IDX_1B6457973E030ACD ON ingestion_credential (application_id)');
        $this->addSql('CREATE TABLE ingestion_failure (id UUID NOT NULL, reason VARCHAR(32) NOT NULL, detail TEXT NOT NULL, credential_prefix VARCHAR(20) DEFAULT NULL, excerpt TEXT DEFAULT NULL, received_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, application_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_failure_app_time ON ingestion_failure (application_id, received_at)');
        $this->addSql('CREATE INDEX IDX_9C69C89D3E030ACD ON ingestion_failure (application_id)');
        $this->addSql('ALTER TABLE application ADD CONSTRAINT FK_A45BDDC182D40A1F FOREIGN KEY (workspace_id) REFERENCES workspace (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE audit_log ADD CONSTRAINT FK_F6E1C0F582D40A1F FOREIGN KEY (workspace_id) REFERENCES workspace (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE event ADD CONSTRAINT FK_3BAE0AA782D40A1F FOREIGN KEY (workspace_id) REFERENCES workspace (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE event ADD CONSTRAINT FK_3BAE0AA73E030ACD FOREIGN KEY (application_id) REFERENCES application (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ingestion_credential ADD CONSTRAINT FK_1B6457973E030ACD FOREIGN KEY (application_id) REFERENCES application (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ingestion_failure ADD CONSTRAINT FK_9C69C89D3E030ACD FOREIGN KEY (application_id) REFERENCES application (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE application DROP CONSTRAINT FK_A45BDDC182D40A1F');
        $this->addSql('ALTER TABLE audit_log DROP CONSTRAINT FK_F6E1C0F582D40A1F');
        $this->addSql('ALTER TABLE event DROP CONSTRAINT FK_3BAE0AA782D40A1F');
        $this->addSql('ALTER TABLE event DROP CONSTRAINT FK_3BAE0AA73E030ACD');
        $this->addSql('ALTER TABLE ingestion_credential DROP CONSTRAINT FK_1B6457973E030ACD');
        $this->addSql('ALTER TABLE ingestion_failure DROP CONSTRAINT FK_9C69C89D3E030ACD');
        $this->addSql('DROP TABLE application');
        $this->addSql('DROP TABLE audit_log');
        $this->addSql('DROP TABLE event');
        $this->addSql('DROP TABLE ingestion_credential');
        $this->addSql('DROP TABLE ingestion_failure');
    }
}
