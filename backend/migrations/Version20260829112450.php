<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260829112450 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seiten-Sichtbarkeits-Flags (page_setting) für schaltbare Marketing-Seiten';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE page_setting (id INT AUTO_INCREMENT NOT NULL, page_key VARCHAR(64) NOT NULL, enabled TINYINT(1) NOT NULL, updated_at DATETIME NOT NULL, updated_by VARCHAR(190) DEFAULT NULL, UNIQUE INDEX uniq_page_setting_key (page_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        foreach (['was-ist-gestura', 'maus-gesten', 'vergleich', 'beispiele'] as $key) {
            $this->addSql('INSERT INTO page_setting (page_key, enabled, updated_at) VALUES (:k, 1, :now)', ['k' => $key, 'now' => $now]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE page_setting');
    }
}
