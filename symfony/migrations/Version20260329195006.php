<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260329195006 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE admin_audit_log (id INT AUTO_INCREMENT NOT NULL, user_email VARCHAR(255) NOT NULL, action VARCHAR(20) NOT NULL, table_name VARCHAR(100) DEFAULT NULL, row_identifier VARCHAR(100) DEFAULT NULL, old_values JSON DEFAULT NULL, new_values JSON DEFAULT NULL, ip_address VARCHAR(45) DEFAULT NULL, `sql` LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, INDEX idx_audit_table (table_name), INDEX idx_audit_date (created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE admin_audit_log');
    }
}
