<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261122073415 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'AI fix proposals for verified regression tests (Test Lab).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE fix_proposal (id UUID NOT NULL, summary TEXT NOT NULL, diff TEXT NOT NULL, citations JSON NOT NULL, files JSON NOT NULL, shared_files JSON NOT NULL, source VARCHAR(160) NOT NULL, static_issues JSON NOT NULL, reviewed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, batch_id UUID DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, workspace_id UUID NOT NULL, test_id UUID NOT NULL, reviewed_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_fix_test ON fix_proposal (test_id, created_at)');
        $this->addSql('CREATE INDEX IDX_591A7EA82D40A1F ON fix_proposal (workspace_id)');
        $this->addSql('CREATE INDEX IDX_591A7EA1E5D0459 ON fix_proposal (test_id)');
        $this->addSql('CREATE INDEX IDX_591A7EAFC6B21F1 ON fix_proposal (reviewed_by_id)');
        $this->addSql('ALTER TABLE fix_proposal ADD CONSTRAINT FK_591A7EA82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspace (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE fix_proposal ADD CONSTRAINT FK_591A7EA1E5D0459 FOREIGN KEY (test_id) REFERENCES regression_test (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE fix_proposal ADD CONSTRAINT FK_591A7EAFC6B21F1 FOREIGN KEY (reviewed_by_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE fix_proposal DROP CONSTRAINT FK_591A7EA82D40A1F');
        $this->addSql('ALTER TABLE fix_proposal DROP CONSTRAINT FK_591A7EA1E5D0459');
        $this->addSql('ALTER TABLE fix_proposal DROP CONSTRAINT FK_591A7EAFC6B21F1');
        $this->addSql('DROP TABLE fix_proposal');
    }
}
