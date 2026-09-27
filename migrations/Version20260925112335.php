<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925112335 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial SQLite schema: videos and durable Messenger queues';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SQLitePlatform, 'This migration targets SQLite.');
        $this->addSql('CREATE TABLE video (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, youtube_id VARCHAR(11) NOT NULL, url VARCHAR(255) NOT NULL, title VARCHAR(1000) DEFAULT NULL, channel_name VARCHAR(500) DEFAULT NULL, thumbnail_url CLOB DEFAULT NULL, duration INTEGER DEFAULT NULL, published_at DATETIME DEFAULT NULL, status VARCHAR(255) NOT NULL, transcript CLOB DEFAULT NULL, transcript_with_timestamps CLOB DEFAULT NULL, transcript_language VARCHAR(100) DEFAULT NULL, transcript_source VARCHAR(100) DEFAULT NULL, summary CLOB DEFAULT NULL, error_message CLOB DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7CC7DA2CBB7E40D1 ON video (youtube_id)');
        $this->addSql('CREATE INDEX IDX_7CC7DA2C8B8E8428 ON video (created_at)');
        $this->addSql('CREATE TABLE messenger_messages (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, body CLOB NOT NULL, headers CLOB NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE video');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
