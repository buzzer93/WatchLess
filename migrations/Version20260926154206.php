<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260926154206 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Conserve une seule transcription canonique horodatée';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        // Prefer the old timestamped value when it exists, then remove the duplicate plain-text column.
        $this->addSql('UPDATE video SET transcript = transcript_with_timestamps WHERE transcript_with_timestamps IS NOT NULL AND trim(transcript_with_timestamps) <> \'\'');
        $this->addSql('CREATE TEMPORARY TABLE __temp__video AS SELECT id, youtube_id, url, title, channel_name, thumbnail_url, duration, published_at, status, transcript, transcript_language, transcript_source, summary, error_message, created_at, updated_at FROM video');
        $this->addSql('DROP TABLE video');
        $this->addSql('CREATE TABLE video (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, youtube_id VARCHAR(11) NOT NULL, url VARCHAR(255) NOT NULL, title VARCHAR(1000) DEFAULT NULL, channel_name VARCHAR(500) DEFAULT NULL, thumbnail_url CLOB DEFAULT NULL, duration INTEGER DEFAULT NULL, published_at DATETIME DEFAULT NULL, status VARCHAR(255) NOT NULL, transcript CLOB DEFAULT NULL, transcript_language VARCHAR(100) DEFAULT NULL, transcript_source VARCHAR(100) DEFAULT NULL, summary CLOB DEFAULT NULL, error_message CLOB DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $this->addSql('INSERT INTO video (id, youtube_id, url, title, channel_name, thumbnail_url, duration, published_at, status, transcript, transcript_language, transcript_source, summary, error_message, created_at, updated_at) SELECT id, youtube_id, url, title, channel_name, thumbnail_url, duration, published_at, status, transcript, transcript_language, transcript_source, summary, error_message, created_at, updated_at FROM __temp__video');
        $this->addSql('DROP TABLE __temp__video');
        $this->addSql('CREATE INDEX IDX_7CC7DA2C8B8E8428 ON video (created_at)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7CC7DA2CBB7E40D1 ON video (youtube_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE video ADD COLUMN transcript_with_timestamps CLOB DEFAULT NULL');
        $this->addSql('UPDATE video SET transcript_with_timestamps = transcript WHERE transcript IS NOT NULL');
    }
}
