<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260724075955 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE sync_blob (id INT AUTO_INCREMENT NOT NULL, collection VARCHAR(16) NOT NULL, ciphertext MEDIUMTEXT NOT NULL, version INT NOT NULL, updated_at DATETIME NOT NULL, account_id INT NOT NULL, INDEX IDX_E2A909839B6B5FBA (account_id), UNIQUE INDEX UNIQ_E2A909839B6B5FBAFC4D6532 (account_id, collection), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE sync_blob ADD CONSTRAINT FK_E2A909839B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE sync_blob DROP FOREIGN KEY FK_E2A909839B6B5FBA');
        $this->addSql('DROP TABLE sync_blob');
    }
}
