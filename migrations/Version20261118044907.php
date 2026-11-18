<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261118044907 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE notification (id UUID NOT NULL, kind VARCHAR(40) NOT NULL, tone VARCHAR(12) NOT NULL, title VARCHAR(200) NOT NULL, body TEXT NOT NULL, url VARCHAR(300) NOT NULL, dedupe_key VARCHAR(160) DEFAULT NULL, created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, read_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL, workspace_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_notification_user_time ON notification (user_id, created_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_notification_dedupe ON notification (user_id, dedupe_key)');
        $this->addSql('CREATE INDEX IDX_BF5476CA82D40A1F ON notification (workspace_id)');
        $this->addSql('CREATE INDEX IDX_BF5476CAA76ED395 ON notification (user_id)');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspace (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CAA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT FK_BF5476CA82D40A1F');
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT FK_BF5476CAA76ED395');
        $this->addSql('DROP TABLE notification');
    }
}
