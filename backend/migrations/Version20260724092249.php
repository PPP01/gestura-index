<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260724092249 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE submitter ADD account_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE submitter ADD CONSTRAINT FK_E6D2588B9B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_E6D2588B9B6B5FBA ON submitter (account_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE submitter DROP FOREIGN KEY FK_E6D2588B9B6B5FBA');
        $this->addSql('DROP INDEX IDX_E6D2588B9B6B5FBA ON submitter');
        $this->addSql('ALTER TABLE submitter DROP account_id');
    }
}
