<?php

declare(strict_types=1);

namespace App\Entity;

use App\Api\SyncContract;
use App\Repository\SyncStateRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ein Sync-Stand des Extension-Vertrags (apiLevel 3): eine
 * Einstellungs-Momentaufnahme unter einem Locator, abgelegt als zwei
 * Chiffrate unter demselben clientseitigen Schlüssel. Der Server sieht nur
 * Envelopes – er entschlüsselt nichts, kennt keine Struktur und merged nicht.
 *
 * NICHT zu verwechseln mit App\Entity\SyncBlob: das ist der kontogebundene
 * Settings-Sync aus dem Juli-Plan (/api/account/sync/{collection}). Dieser
 * hier ist anonym und wird über einen abgeleiteten Locator adressiert.
 *
 * Der Locator selbst wird NIE gespeichert – nur sein SHA-256. Wer die
 * Datenbank liest, kann die Chiffrate nicht entschlüsseln und soll auch nicht
 * das Recht erben, sie zu listen oder zu löschen.
 */
#[ORM\Entity(repositoryClass: SyncStateRepository::class)]
#[ORM\Table(name: 'sync_state')]
#[ORM\UniqueConstraint(name: 'uniq_sync_state_locator_state', columns: ['locator_hash', 'state_id'])]
#[ORM\Index(name: 'idx_sync_state_last_access', columns: ['last_access_at'])]
class SyncState
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    /** SHA-256 des Locators als Hex – der einzige Weg, eine Zeile zu finden. */
    #[ORM\Column(length: 64)]
    public string $locatorHash;

    /** Clientseitig erzeugt, unveränderlich (Vertrag): ^[0-9a-f]{32}$. */
    #[ORM\Column(length: 32)]
    public string $stateId;

    /** Meta-Envelope, Base64, höchstens 8 KiB wie übertragen – TEXT genügt. */
    #[ORM\Column(type: 'text', length: 65535)]
    public string $meta;

    /**
     * Payload-Envelope, Base64, höchstens 512 KiB wie übertragen.
     * MEDIUMTEXT ist Pflicht: MySQL-TEXT fasst nur 64 KiB, ein maximal
     * großer Stand würde stillschweigend abgeschnitten – und der Client
     * verwürfe ihn danach als unentschlüsselbar.
     */
    #[ORM\Column(type: 'text', length: 16777215)]
    public string $payload;

    /**
     * SHA-256 über die rohen Bytes des Payload-Envelopes, Base64url ohne
     * Padding. Abgeleitet, nicht vom Client übernommen – er ist der
     * Vergleichswert für »basePayloadHash« und darf deshalb nie etwas
     * anderes beschreiben als das, was tatsächlich gespeichert ist.
     */
    #[ORM\Column(length: 43)]
    public string $payloadHash;

    /**
     * Länge des Payload-Envelopes wie übertragen (Base64-Zeichen).
     *
     * payloadHash und sizeBytes sind aus payload ableitbar und werden
     * trotzdem gespeichert – nicht aus Bequemlichkeit: erst dadurch kann
     * SyncStateRepository::listByLocator() spaltenweise selektieren und die
     * bis zu 512 KiB Nutzlast je Stand ungelesen liegen lassen. Berechnete
     * Methoden würden die Zeile jedes Mal vollständig laden. Konsistent
     * gehalten werden beide durch das private setBlobs(), das der einzige
     * Weg ist, die Blobs zu setzen.
     */
    #[ORM\Column]
    public int $sizeBytes;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    /** Fachlich sichtbar: der Client zeigt und vergleicht diesen Wert. */
    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    /**
     * Nur für die Aufbewahrung: jeder Lese- UND Schreibzugriff frischt ihn
     * auf. Bewusst getrennt von updatedAt – ein Herunterladen darf den
     * Änderungszeitpunkt nicht verfälschen, den der Client anzeigt.
     *
     * Auf dem Lesepfad wird er nicht hier, sondern per Mengen-UPDATE in
     * SyncStateRepository::touchLocator() fortgeschrieben – ohne die Zeile
     * samt Nutzlast zu laden.
     */
    #[ORM\Column]
    public \DateTimeImmutable $lastAccessAt;

    public function __construct(string $locatorHash, string $stateId, string $meta, string $payload)
    {
        $this->locatorHash = $locatorHash;
        $this->stateId = $stateId;
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->lastAccessAt = $now;
        $this->setBlobs($meta, $payload);
    }

    /** Ersetzt beide Blobs gemeinsam und schreibt updatedAt fort. */
    public function replaceBlobs(string $meta, string $payload): void
    {
        $this->setBlobs($meta, $payload);
        $this->updatedAt = new \DateTimeImmutable();
        $this->lastAccessAt = $this->updatedAt;
    }

    /**
     * meta und payload werden immer ZUSAMMEN gesetzt – der Client prüft die
     * heruntergeladene Nutzlast gegen den payloadHash aus dem Meta-Blob, ein
     * gemischtes Paar lässt seinen Download fehlschlagen.
     */
    private function setBlobs(string $meta, string $payload): void
    {
        $this->meta = $meta;
        $this->payload = $payload;
        $this->payloadHash = SyncContract::payloadHash($payload);
        $this->sizeBytes = \strlen($payload);
    }
}
