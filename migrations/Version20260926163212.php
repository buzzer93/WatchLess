<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260926163212 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les formats de publication des sujets';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE topic_publication (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, format VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, source_revision INTEGER DEFAULT NULL, content CLOB DEFAULT NULL, error_message CLOB DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, topic_id INTEGER NOT NULL, CONSTRAINT FK_7442FD011F55203D FOREIGN KEY (topic_id) REFERENCES topic (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX topic_publication_topic_format ON topic_publication (topic_id, format)');
        $this->addSql('CREATE INDEX IDX_7442FD011F55203D ON topic_publication (topic_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE topic_publication');
    }
}
