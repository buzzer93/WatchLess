<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260926095854 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sujets, sélection de vidéos et synthèses avec références aux sources';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE topic (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(200) NOT NULL, description CLOB NOT NULL, context CLOB NOT NULL, status VARCHAR(20) NOT NULL, revision INTEGER NOT NULL, summary_revision INTEGER DEFAULT NULL, summary CLOB DEFAULT NULL, summary_sources CLOB NOT NULL, error_message CLOB DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $this->addSql('CREATE TABLE topic_video (topic_id INTEGER NOT NULL, video_id INTEGER NOT NULL, PRIMARY KEY (topic_id, video_id), CONSTRAINT FK_C7F4764E1F55203D FOREIGN KEY (topic_id) REFERENCES topic (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_C7F4764E29C1004E FOREIGN KEY (video_id) REFERENCES video (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_C7F4764E1F55203D ON topic_video (topic_id)');
        $this->addSql('CREATE INDEX IDX_C7F4764E29C1004E ON topic_video (video_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE topic_video');
        $this->addSql('DROP TABLE topic');
    }
}
