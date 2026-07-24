<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260724131852 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE rating (id INT AUTO_INCREMENT NOT NULL, stars INT NOT NULL, comment VARCHAR(500) DEFAULT NULL, comment_status VARCHAR(10) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, account_id INT NOT NULL, entry_id INT NOT NULL, INDEX IDX_D88926229B6B5FBA (account_id), INDEX IDX_D8892622BA364942 (entry_id), UNIQUE INDEX UNIQ_D88926229B6B5FBABA364942 (account_id, entry_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE rating ADD CONSTRAINT FK_D88926229B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE rating ADD CONSTRAINT FK_D8892622BA364942 FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE rating DROP FOREIGN KEY FK_D88926229B6B5FBA');
        $this->addSql('ALTER TABLE rating DROP FOREIGN KEY FK_D8892622BA364942');
        $this->addSql('DROP TABLE rating');
    }
}
