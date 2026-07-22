<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260722083628 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE admin_invite (id INT AUTO_INCREMENT NOT NULL, token_selector VARCHAR(16) NOT NULL, token_hash VARCHAR(255) NOT NULL, role VARCHAR(10) NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME DEFAULT NULL, admin_user_id INT NOT NULL, created_by_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_9A2A212EE2CD3FDE (token_selector), INDEX IDX_9A2A212E6352511C (admin_user_id), INDEX IDX_9A2A212EB03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE admin_user (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(190) NOT NULL, display_name VARCHAR(190) NOT NULL, role VARCHAR(10) NOT NULL, status VARCHAR(10) NOT NULL, created_at DATETIME NOT NULL, last_login_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_AD8A54A9E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE audit_log_entry (id INT AUTO_INCREMENT NOT NULL, action VARCHAR(64) NOT NULL, target_type VARCHAR(32) DEFAULT NULL, target_id VARCHAR(64) DEFAULT NULL, detail JSON DEFAULT NULL, created_at DATETIME NOT NULL, actor_id INT DEFAULT NULL, INDEX IDX_D2D938A210DAF24A (actor_id), INDEX IDX_D2D938A28B8E8428 (created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE webauthn_credential (id INT AUTO_INCREMENT NOT NULL, credential_id VARCHAR(255) NOT NULL, source LONGTEXT NOT NULL, label VARCHAR(64) NOT NULL, aaguid VARCHAR(64) DEFAULT NULL, created_at DATETIME NOT NULL, last_used_at DATETIME DEFAULT NULL, admin_user_id INT NOT NULL, UNIQUE INDEX UNIQ_850123F92558A7A5 (credential_id), INDEX IDX_850123F96352511C (admin_user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE admin_invite ADD CONSTRAINT FK_9A2A212E6352511C FOREIGN KEY (admin_user_id) REFERENCES admin_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE admin_invite ADD CONSTRAINT FK_9A2A212EB03A8386 FOREIGN KEY (created_by_id) REFERENCES admin_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE audit_log_entry ADD CONSTRAINT FK_D2D938A210DAF24A FOREIGN KEY (actor_id) REFERENCES admin_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE webauthn_credential ADD CONSTRAINT FK_850123F96352511C FOREIGN KEY (admin_user_id) REFERENCES admin_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE admin_invite DROP FOREIGN KEY FK_9A2A212E6352511C');
        $this->addSql('ALTER TABLE admin_invite DROP FOREIGN KEY FK_9A2A212EB03A8386');
        $this->addSql('ALTER TABLE audit_log_entry DROP FOREIGN KEY FK_D2D938A210DAF24A');
        $this->addSql('ALTER TABLE webauthn_credential DROP FOREIGN KEY FK_850123F96352511C');
        $this->addSql('DROP TABLE admin_invite');
        $this->addSql('DROP TABLE admin_user');
        $this->addSql('DROP TABLE audit_log_entry');
        $this->addSql('DROP TABLE webauthn_credential');
    }
}
