<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260720150315 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE entry (id INT AUTO_INCREMENT NOT NULL, format_id VARCHAR(128) NOT NULL, type VARCHAR(10) NOT NULL, status VARCHAR(10) NOT NULL, tags JSON NOT NULL, domains JSON NOT NULL, search_text LONGTEXT NOT NULL, install_count INT NOT NULL, deprecated TINYINT NOT NULL, successor_format_id VARCHAR(128) DEFAULT NULL, screenshot_path VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, submitter_id INT NOT NULL, current_version_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_2B219D70D629F605 (format_id), INDEX IDX_2B219D70919E5513 (submitter_id), UNIQUE INDEX UNIQ_2B219D709407EE77 (current_version_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE entry_category (id INT AUTO_INCREMENT NOT NULL, category VARCHAR(20) NOT NULL, entry_id INT NOT NULL, INDEX IDX_680BF989BA364942 (entry_id), INDEX IDX_680BF98964C19C1 (category), UNIQUE INDEX UNIQ_680BF989BA36494264C19C1 (entry_id, category), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE entry_version (id INT AUTO_INCREMENT NOT NULL, semver VARCHAR(17) NOT NULL, payload JSON NOT NULL, content_hash VARCHAR(64) NOT NULL, changelog LONGTEXT DEFAULT NULL, status VARCHAR(10) NOT NULL, has_transform_code TINYINT NOT NULL, submitted_at DATETIME NOT NULL, entry_id INT NOT NULL, INDEX IDX_93FA7C54BA364942 (entry_id), INDEX IDX_93FA7C541CDA8F7D (content_hash), UNIQUE INDEX UNIQ_93FA7C54BA3649425217923F (entry_id, semver), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE report (id INT AUTO_INCREMENT NOT NULL, reason VARCHAR(20) NOT NULL, comment LONGTEXT DEFAULT NULL, status VARCHAR(10) NOT NULL, created_at DATETIME NOT NULL, entry_id INT NOT NULL, INDEX IDX_C42F7784BA364942 (entry_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE submitter (id INT AUTO_INCREMENT NOT NULL, token_selector VARCHAR(16) NOT NULL, token_hash VARCHAR(255) NOT NULL, approved_count INT NOT NULL, banned TINYINT NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_E6D2588BE2CD3FDE (token_selector), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE entry ADD CONSTRAINT FK_2B219D70919E5513 FOREIGN KEY (submitter_id) REFERENCES submitter (id)');
        $this->addSql('ALTER TABLE entry ADD CONSTRAINT FK_2B219D709407EE77 FOREIGN KEY (current_version_id) REFERENCES entry_version (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE entry_category ADD CONSTRAINT FK_680BF989BA364942 FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE entry_version ADD CONSTRAINT FK_93FA7C54BA364942 FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE report ADD CONSTRAINT FK_C42F7784BA364942 FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE entry DROP FOREIGN KEY FK_2B219D70919E5513');
        $this->addSql('ALTER TABLE entry DROP FOREIGN KEY FK_2B219D709407EE77');
        $this->addSql('ALTER TABLE entry_category DROP FOREIGN KEY FK_680BF989BA364942');
        $this->addSql('ALTER TABLE entry_version DROP FOREIGN KEY FK_93FA7C54BA364942');
        $this->addSql('ALTER TABLE report DROP FOREIGN KEY FK_C42F7784BA364942');
        $this->addSql('DROP TABLE entry');
        $this->addSql('DROP TABLE entry_category');
        $this->addSql('DROP TABLE entry_version');
        $this->addSql('DROP TABLE report');
        $this->addSql('DROP TABLE submitter');
    }
}
