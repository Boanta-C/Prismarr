<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260416132909 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE media_watchlist (id INT AUTO_INCREMENT NOT NULL, tmdb_id INT NOT NULL, media_type VARCHAR(10) NOT NULL, title VARCHAR(255) NOT NULL, poster_path VARCHAR(255) DEFAULT NULL, vote DOUBLE PRECISION DEFAULT NULL, year INT DEFAULT NULL, added_at DATETIME NOT NULL, notes LONGTEXT DEFAULT NULL, UNIQUE INDEX uniq_watchlist_tmdb (tmdb_id, media_type), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE media_watchlist');
    }
}
