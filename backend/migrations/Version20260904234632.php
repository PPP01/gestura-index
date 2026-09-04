<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Locator-adressierte Sync-Stände des Extension-Vertrags (apiLevel 3).
 *
 * locator_hash statt Locator: der Klartext ist ein Bearer-Token, und Zugriff
 * auf die Datenbank darf nicht das Recht bedeuten, fremde Stände zu listen
 * oder zu löschen. payload ist MEDIUMTEXT, weil der Vertrag 512 KiB je
 * Envelope erlaubt und MySQL-TEXT nur 64 KiB fasst.
 */
final class Version20260904234632 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Locator-adressierte Sync-Stände (Extension-Vertrag apiLevel 3)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE sync_state (id INT AUTO_INCREMENT NOT NULL, locator_hash VARCHAR(64) NOT NULL, state_id VARCHAR(32) NOT NULL, meta TEXT NOT NULL, payload MEDIUMTEXT NOT NULL, payload_hash VARCHAR(43) NOT NULL, size_bytes INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, last_access_at DATETIME NOT NULL, INDEX idx_sync_state_last_access (last_access_at), UNIQUE INDEX uniq_sync_state_locator_state (locator_hash, state_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE sync_state');
    }
}
