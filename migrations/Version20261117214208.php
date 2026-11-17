<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261117214208 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE incident (id UUID NOT NULL, number INT NOT NULL, fingerprint VARCHAR(64) NOT NULL, title VARCHAR(255) NOT NULL, exception_class VARCHAR(255) DEFAULT NULL, normalized_message VARCHAR(500) NOT NULL, service VARCHAR(64) NOT NULL, status VARCHAR(16) NOT NULL, severity VARCHAR(12) NOT NULL, first_seen_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, last_seen_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, occurrences INT NOT NULL, last_event_id UUID DEFAULT NULL, resolved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, reopen_count INT NOT NULL, seeded BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, workspace_id UUID NOT NULL, application_id UUID NOT NULL, assignee_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_incident_ws_last ON incident (workspace_id, last_seen_at)');
        $this->addSql('CREATE INDEX idx_incident_ws_status ON incident (workspace_id, status)');
        $this->addSql('CREATE UNIQUE INDEX uniq_incident_fingerprint ON incident (application_id, fingerprint)');
        $this->addSql('CREATE UNIQUE INDEX uniq_incident_number ON incident (workspace_id, number)');
        $this->addSql('CREATE INDEX IDX_3D03A11A82D40A1F ON incident (workspace_id)');
        $this->addSql('CREATE INDEX IDX_3D03A11A3E030ACD ON incident (application_id)');
        $this->addSql('CREATE INDEX IDX_3D03A11A59EC7D60 ON incident (assignee_id)');
        $this->addSql('CREATE TABLE incident_event (occurred_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, incident_id UUID NOT NULL, event_id UUID NOT NULL, PRIMARY KEY (incident_id, event_id))');
        $this->addSql('CREATE INDEX idx_incident_event_time ON incident_event (incident_id, occurred_at)');
        $this->addSql('CREATE INDEX IDX_609AA8CD59E53FB9 ON incident_event (incident_id)');
        $this->addSql('CREATE INDEX IDX_609AA8CD71F7E88B ON incident_event (event_id)');
        $this->addSql('CREATE TABLE incident_note (id UUID NOT NULL, body TEXT NOT NULL, kind VARCHAR(16) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, incident_id UUID NOT NULL, author_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_note_incident ON incident_note (incident_id, created_at)');
        $this->addSql('CREATE INDEX IDX_25B18DAB59E53FB9 ON incident_note (incident_id)');
        $this->addSql('CREATE INDEX IDX_25B18DABF675F31B ON incident_note (author_id)');
        $this->addSql('ALTER TABLE incident ADD CONSTRAINT FK_3D03A11A82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspace (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE incident ADD CONSTRAINT FK_3D03A11A3E030ACD FOREIGN KEY (application_id) REFERENCES application (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE incident ADD CONSTRAINT FK_3D03A11A59EC7D60 FOREIGN KEY (assignee_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE incident_event ADD CONSTRAINT FK_609AA8CD59E53FB9 FOREIGN KEY (incident_id) REFERENCES incident (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE incident_event ADD CONSTRAINT FK_609AA8CD71F7E88B FOREIGN KEY (event_id) REFERENCES event (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE incident_note ADD CONSTRAINT FK_25B18DAB59E53FB9 FOREIGN KEY (incident_id) REFERENCES incident (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE incident_note ADD CONSTRAINT FK_25B18DABF675F31B FOREIGN KEY (author_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE event ALTER occurred_at TYPE TIMESTAMP(6) WITH TIME ZONE');
        $this->addSql('ALTER TABLE event ALTER received_at TYPE TIMESTAMP(6) WITH TIME ZONE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE incident DROP CONSTRAINT FK_3D03A11A82D40A1F');
        $this->addSql('ALTER TABLE incident DROP CONSTRAINT FK_3D03A11A3E030ACD');
        $this->addSql('ALTER TABLE incident DROP CONSTRAINT FK_3D03A11A59EC7D60');
        $this->addSql('ALTER TABLE incident_event DROP CONSTRAINT FK_609AA8CD59E53FB9');
        $this->addSql('ALTER TABLE incident_event DROP CONSTRAINT FK_609AA8CD71F7E88B');
        $this->addSql('ALTER TABLE incident_note DROP CONSTRAINT FK_25B18DAB59E53FB9');
        $this->addSql('ALTER TABLE incident_note DROP CONSTRAINT FK_25B18DABF675F31B');
        $this->addSql('DROP TABLE incident');
        $this->addSql('DROP TABLE incident_event');
        $this->addSql('DROP TABLE incident_note');
        $this->addSql('ALTER TABLE event ALTER occurred_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE event ALTER received_at TYPE TIMESTAMP(0) WITH TIME ZONE');
    }
}
