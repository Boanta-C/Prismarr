<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the user.avatar_path column that stores the local filename of the
 * uploaded profile picture (inside var/data/avatars/). Introduced in
 * Session 9d — profile page with avatar upload. Applies cleanly on fresh
 * installs too because baseline runs first.
 */
final class Version20260423220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.avatar_path for uploaded profile pictures.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD COLUMN avatar_path VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP COLUMN avatar_path');
    }
}
