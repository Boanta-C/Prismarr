<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260323202359 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE infrastructure_alert (id INT AUTO_INCREMENT NOT NULL, severity VARCHAR(20) NOT NULL, message VARCHAR(255) NOT NULL, source VARCHAR(50) DEFAULT NULL, acknowledged TINYINT DEFAULT 0 NOT NULL, acknowledged_at DATETIME DEFAULT NULL, resolved_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, device_id INT DEFAULT NULL, INDEX IDX_FC75220994A4C7D4 (device_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE infrastructure_device (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, type VARCHAR(50) NOT NULL, ip_address VARCHAR(45) DEFAULT NULL, hostname VARCHAR(100) DEFAULT NULL, os VARCHAR(50) DEFAULT NULL, is_monitored TINYINT DEFAULT NULL, status VARCHAR(20) DEFAULT \'unknown\' NOT NULL, last_seen_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE infrastructure_metric (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(50) NOT NULL, value DOUBLE PRECISION NOT NULL, unit VARCHAR(20) DEFAULT NULL, recorded_at DATETIME NOT NULL, device_id INT NOT NULL, INDEX IDX_1282EC0594A4C7D4 (device_id), INDEX idx_metric_device_name_time (device_id, name, recorded_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE infrastructure_service_status (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, url VARCHAR(255) DEFAULT NULL, status VARCHAR(20) DEFAULT \'unknown\' NOT NULL, response_time_ms INT DEFAULT NULL, http_code INT DEFAULT NULL, checked_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, device_id INT NOT NULL, INDEX IDX_D50320EF94A4C7D4 (device_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE media_download (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, media_type VARCHAR(20) NOT NULL, source VARCHAR(20) NOT NULL, status VARCHAR(20) DEFAULT \'queued\' NOT NULL, size_bytes DOUBLE PRECISION DEFAULT NULL, downloaded_bytes DOUBLE PRECISION DEFAULT NULL, progress_percent DOUBLE PRECISION DEFAULT NULL, eta INT DEFAULT NULL, download_speed DOUBLE PRECISION DEFAULT NULL, download_client_id VARCHAR(100) DEFAULT NULL, quality VARCHAR(10) DEFAULT NULL, started_at DATETIME DEFAULT NULL, completed_at DATETIME DEFAULT NULL, synced_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE media_episode (id INT AUTO_INCREMENT NOT NULL, sonarr_episode_id INT DEFAULT NULL, season_number INT NOT NULL, episode_number INT NOT NULL, title VARCHAR(255) DEFAULT NULL, overview LONGTEXT DEFAULT NULL, air_date DATETIME DEFAULT NULL, has_file TINYINT DEFAULT 0 NOT NULL, monitored TINYINT DEFAULT 0 NOT NULL, quality VARCHAR(10) DEFAULT NULL, series_id INT NOT NULL, INDEX IDX_BC7D9A935278319C (series_id), INDEX idx_episode_series_se (series_id, season_number, episode_number), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE media_movie (id INT AUTO_INCREMENT NOT NULL, radarr_id INT DEFAULT NULL, tmdb_id INT DEFAULT NULL, imdb_id INT DEFAULT NULL, title VARCHAR(255) NOT NULL, original_title VARCHAR(255) DEFAULT NULL, year INT DEFAULT NULL, overview LONGTEXT DEFAULT NULL, poster_path VARCHAR(255) DEFAULT NULL, status VARCHAR(20) DEFAULT \'unknown\' NOT NULL, has_file TINYINT DEFAULT NULL, size_on_disk DOUBLE PRECISION DEFAULT NULL, quality VARCHAR(10) DEFAULT NULL, added_at DATETIME DEFAULT NULL, synced_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE media_series (id INT AUTO_INCREMENT NOT NULL, sonarr_id INT DEFAULT NULL, tvdb_id INT DEFAULT NULL, tmdb_id INT DEFAULT NULL, title VARCHAR(255) NOT NULL, original_title VARCHAR(255) DEFAULT NULL, year INT DEFAULT NULL, overview LONGTEXT DEFAULT NULL, poster_path VARCHAR(255) DEFAULT NULL, series_type VARCHAR(20) DEFAULT \'continuing\' NOT NULL, status VARCHAR(20) DEFAULT \'unknown\' NOT NULL, season_count INT DEFAULT NULL, episode_count INT DEFAULT NULL, episode_file_count INT DEFAULT NULL, size_on_disk DOUBLE PRECISION DEFAULT NULL, added_at DATETIME DEFAULT NULL, synced_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE notification_channel (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, type VARCHAR(30) NOT NULL, config JSON NOT NULL, enabled TINYINT DEFAULT 1 NOT NULL, triggers JSON DEFAULT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE notification_history (id INT AUTO_INCREMENT NOT NULL, subject VARCHAR(255) NOT NULL, body LONGTEXT DEFAULT NULL, status VARCHAR(20) DEFAULT \'pending\' NOT NULL, event VARCHAR(50) DEFAULT NULL, error_message LONGTEXT DEFAULT NULL, sent_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, channel_id INT NOT NULL, INDEX IDX_32A4FAFC72F5A1AA (channel_id), INDEX idx_notif_history_sent_at (sent_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE productivity_calendar_event (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, type VARCHAR(20) DEFAULT \'personal\' NOT NULL, color VARCHAR(7) DEFAULT NULL, start_at DATETIME NOT NULL, end_at DATETIME DEFAULT NULL, all_day TINYINT DEFAULT 0 NOT NULL, external_id INT DEFAULT NULL, external_source VARCHAR(50) DEFAULT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_4E7B3D44A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE productivity_note (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, content LONGTEXT DEFAULT NULL, category VARCHAR(50) DEFAULT NULL, pinned TINYINT DEFAULT 0 NOT NULL, color VARCHAR(7) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_C1E984B0A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE productivity_todo (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, status VARCHAR(20) DEFAULT \'todo\' NOT NULL, priority VARCHAR(10) DEFAULT \'medium\' NOT NULL, category VARCHAR(50) DEFAULT NULL, due_at DATETIME DEFAULT NULL, completed_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_545AC804A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE infrastructure_alert ADD CONSTRAINT FK_FC75220994A4C7D4 FOREIGN KEY (device_id) REFERENCES infrastructure_device (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE infrastructure_metric ADD CONSTRAINT FK_1282EC0594A4C7D4 FOREIGN KEY (device_id) REFERENCES infrastructure_device (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE infrastructure_service_status ADD CONSTRAINT FK_D50320EF94A4C7D4 FOREIGN KEY (device_id) REFERENCES infrastructure_device (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE media_episode ADD CONSTRAINT FK_BC7D9A935278319C FOREIGN KEY (series_id) REFERENCES media_series (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE notification_history ADD CONSTRAINT FK_32A4FAFC72F5A1AA FOREIGN KEY (channel_id) REFERENCES notification_channel (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE productivity_calendar_event ADD CONSTRAINT FK_4E7B3D44A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE productivity_note ADD CONSTRAINT FK_C1E984B0A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE productivity_todo ADD CONSTRAINT FK_545AC804A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE infrastructure_alert DROP FOREIGN KEY FK_FC75220994A4C7D4');
        $this->addSql('ALTER TABLE infrastructure_metric DROP FOREIGN KEY FK_1282EC0594A4C7D4');
        $this->addSql('ALTER TABLE infrastructure_service_status DROP FOREIGN KEY FK_D50320EF94A4C7D4');
        $this->addSql('ALTER TABLE media_episode DROP FOREIGN KEY FK_BC7D9A935278319C');
        $this->addSql('ALTER TABLE notification_history DROP FOREIGN KEY FK_32A4FAFC72F5A1AA');
        $this->addSql('ALTER TABLE productivity_calendar_event DROP FOREIGN KEY FK_4E7B3D44A76ED395');
        $this->addSql('ALTER TABLE productivity_note DROP FOREIGN KEY FK_C1E984B0A76ED395');
        $this->addSql('ALTER TABLE productivity_todo DROP FOREIGN KEY FK_545AC804A76ED395');
        $this->addSql('DROP TABLE infrastructure_alert');
        $this->addSql('DROP TABLE infrastructure_device');
        $this->addSql('DROP TABLE infrastructure_metric');
        $this->addSql('DROP TABLE infrastructure_service_status');
        $this->addSql('DROP TABLE media_download');
        $this->addSql('DROP TABLE media_episode');
        $this->addSql('DROP TABLE media_movie');
        $this->addSql('DROP TABLE media_series');
        $this->addSql('DROP TABLE notification_channel');
        $this->addSql('DROP TABLE notification_history');
        $this->addSql('DROP TABLE productivity_calendar_event');
        $this->addSql('DROP TABLE productivity_note');
        $this->addSql('DROP TABLE productivity_todo');
    }
}
