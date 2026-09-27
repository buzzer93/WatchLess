<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260926194500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute une intention facultative aux sujets';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE topic ADD COLUMN intent VARCHAR(30) NOT NULL DEFAULT ''");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE topic DROP COLUMN intent');
    }
}
