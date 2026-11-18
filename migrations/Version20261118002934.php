<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261118002934 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE regression_test (id UUID NOT NULL, title VARCHAR(200) NOT NULL, purpose TEXT NOT NULL, preconditions JSON NOT NULL, scenario TEXT NOT NULL, expected TEXT NOT NULL, code TEXT NOT NULL, class_name VARCHAR(120) NOT NULL, citations JSON NOT NULL, shared_files JSON NOT NULL, source VARCHAR(160) NOT NULL, static_check VARCHAR(10) NOT NULL, static_issues JSON NOT NULL, reviewed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, workspace_id UUID NOT NULL, incident_id UUID NOT NULL, investigation_id UUID DEFAULT NULL, reviewed_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_rtest_ws ON regression_test (workspace_id, created_at)');
        $this->addSql('CREATE INDEX IDX_FC89A1BE82D40A1F ON regression_test (workspace_id)');
        $this->addSql('CREATE INDEX IDX_FC89A1BE59E53FB9 ON regression_test (incident_id)');
        $this->addSql('CREATE INDEX IDX_FC89A1BEDC64C387 ON regression_test (investigation_id)');
        $this->addSql('CREATE INDEX IDX_FC89A1BEFC6B21F1 ON regression_test (reviewed_by_id)');
        $this->addSql('CREATE TABLE test_execution (id UUID NOT NULL, variant VARCHAR(8) NOT NULL, batch_id UUID NOT NULL, status VARCHAR(10) NOT NULL, exit_code INT DEFAULT NULL, duration_ms INT DEFAULT NULL, assertions INT DEFAULT NULL, failures INT DEFAULT NULL, errors INT DEFAULT NULL, output TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, test_id UUID NOT NULL, started_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_texec_test ON test_execution (test_id, created_at)');
        $this->addSql('CREATE INDEX IDX_803D40F01E5D0459 ON test_execution (test_id)');
        $this->addSql('CREATE INDEX IDX_803D40F09740C9D5 ON test_execution (started_by_id)');
        $this->addSql('ALTER TABLE regression_test ADD CONSTRAINT FK_FC89A1BE82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspace (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE regression_test ADD CONSTRAINT FK_FC89A1BE59E53FB9 FOREIGN KEY (incident_id) REFERENCES incident (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE regression_test ADD CONSTRAINT FK_FC89A1BEDC64C387 FOREIGN KEY (investigation_id) REFERENCES investigation (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE regression_test ADD CONSTRAINT FK_FC89A1BEFC6B21F1 FOREIGN KEY (reviewed_by_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE test_execution ADD CONSTRAINT FK_803D40F01E5D0459 FOREIGN KEY (test_id) REFERENCES regression_test (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE test_execution ADD CONSTRAINT FK_803D40F09740C9D5 FOREIGN KEY (started_by_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE regression_test DROP CONSTRAINT FK_FC89A1BE82D40A1F');
        $this->addSql('ALTER TABLE regression_test DROP CONSTRAINT FK_FC89A1BE59E53FB9');
        $this->addSql('ALTER TABLE regression_test DROP CONSTRAINT FK_FC89A1BEDC64C387');
        $this->addSql('ALTER TABLE regression_test DROP CONSTRAINT FK_FC89A1BEFC6B21F1');
        $this->addSql('ALTER TABLE test_execution DROP CONSTRAINT FK_803D40F01E5D0459');
        $this->addSql('ALTER TABLE test_execution DROP CONSTRAINT FK_803D40F09740C9D5');
        $this->addSql('DROP TABLE regression_test');
        $this->addSql('DROP TABLE test_execution');
    }
}
