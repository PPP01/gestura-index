<?php

declare(strict_types=1);

namespace App\Service;

use App\Api\SyncContract;
use App\Entity\SyncState;
use App\Exception\SyncProblem;
use App\Repository\SyncStateRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Die Fachschicht der vier Locator-Sync-Endpunkte (Vertrag, apiLevel 3).
 * Alle vier laufen hierüber, damit die Regeln der Aufbewahrung und der
 * Quote an EINER Stelle stehen und nicht in vier Controllern.
 *
 * Die Auffrischung der Aufbewahrungsfrist ist so eine Regel: jeder Lese- UND
 * Schreibzugriff frischt »lastAccessAt« auf, »updatedAt« bleibt unberührt –
 * das ist der Wert, den der Client anzeigt.
 */
final class LocatorSyncService
{
    /**
     * Ab welchem Alter ein Lesezugriff die Frist überhaupt fortschreibt. Die
     * Frist selbst läuft über 12 Monate; ein Tag Auflösung ändert daran
     * nichts, erspart aber jedem wiederholten Lesezugriff ein UPDATE samt
     * Index-Pflege auf einem sonst rein lesenden Pfad.
     */
    private const TOUCH_AFTER = '-1 day';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SyncStateRepository $states,
    ) {
    }

    /**
     * Die Stände eines Locators, ohne Nutzlast – ein unbekannter Locator
     * liefert eine leere Liste, keinen Fehler.
     *
     * @return list<array{stateId: string, size: int, updatedAt: string, meta: string}>
     */
    public function list(string $locatorHash): array
    {
        $found = $this->states->listByLocator($locatorHash);
        if ($found !== []) {
            $this->touch($locatorHash);
        }

        return array_map(static fn (array $row): array => [
            'stateId' => $row['stateId'],
            'size' => $row['sizeBytes'],
            'updatedAt' => $row['updatedAt']->format(\DateTimeInterface::ATOM),
            'meta' => $row['meta'],
        ], $found);
    }

    /**
     * Die Nutzlast eines Standes.
     *
     * @throws SyncProblem 404 not-found
     */
    public function read(string $locatorHash, string $stateId): SyncState
    {
        $state = $this->states->findOneByLocatorAndState($locatorHash, $stateId);
        if ($state === null) {
            throw SyncProblem::notFound();
        }
        $this->touch($locatorHash);

        return $state;
    }

    /**
     * Legt einen Stand an oder ersetzt ihn.
     *
     * @param ?string $basePayloadHash null = bedingungslos schreiben (so
     *                                 entsteht ein neuer Stand, und so sagt
     *                                 ein Client nach einem Konflikt
     *                                 »trotzdem überschreiben«)
     *
     * @throws SyncProblem 409 quota-states, 404 not-found, 412 conflict
     */
    public function write(
        string $locatorHash,
        string $stateId,
        string $meta,
        string $payload,
        ?string $basePayloadHash,
    ): SyncState {
        // WARUM DIE GUARDS NICHT WERFEN: wrapInTransaction() schließt bei
        // JEDER durchgereichten Exception den EntityManager (Vendor-finally:
        // close() + rollBack()). Ein 412, das aus der Closure heraus fliegt,
        // machte den EM danach unbenutzbar – und der Client merged nach einer
        // Ablehnung neu und schreibt bis zu dreimal je Knopfdruck. Aus einem
        // sauberen Konflikt würde so ein 500er. Deshalb Sentinel zurückgeben
        // und NACH dem (leeren) Commit werfen; siehe .claude/lessons.md.
        /** @var SyncState|SyncProblem $result */
        $result = $this->em->wrapInTransaction(function () use ($locatorHash, $stateId, $meta, $payload, $basePayloadHash) {
            // Laden und Sperren in einem Zug: ohne Sperre sehen zwei
            // gleichzeitige Uploads dieselbe Basis, beide halten sie für
            // passend, und der letzte Commit gewinnt – genau der stille
            // Verlust, den basePayloadHash verhindern soll.
            $state = $this->states->findOneForUpdate($locatorHash, $stateId);

            if ($state === null) {
                // Ein basePayloadHash beschreibt einen Stand, den es nicht
                // gibt: der Client baut auf einer Basis auf, die der Server
                // nicht kennt. Stillschweigend anzulegen hieße, eine fremde
                // Löschung zu übergehen.
                if ($basePayloadHash !== null) {
                    return SyncProblem::notFound();
                }
                // Die Ständegrenze gilt NUR beim Anlegen. Ein Locator über
                // der Grenze behält seine Stände und bleibt beschreibbar.
                if ($this->states->countByLocator($locatorHash) >= SyncContract::MAX_STATES_PER_LOCATOR) {
                    return SyncProblem::quotaStates();
                }
                $state = new SyncState($locatorHash, $stateId, $meta, $payload);
                $this->em->persist($state);

                return $state;
            }

            if ($basePayloadHash !== null && !hash_equals($state->payloadHash, $basePayloadHash)) {
                return SyncProblem::conflict($state->updatedAt);
            }

            $state->replaceBlobs($meta, $payload);

            return $state;
        });

        if ($result instanceof SyncProblem) {
            throw $result;
        }

        return $result;
    }

    /**
     * Löscht einen einzelnen Stand.
     *
     * @throws SyncProblem 404 not-found
     */
    public function delete(string $locatorHash, string $stateId): int
    {
        if ($this->states->deleteOne($locatorHash, $stateId) === 0) {
            throw SyncProblem::notFound();
        }

        return 1;
    }

    /** Löscht alles unter einem Locator; ein leerer Locator meldet 0. */
    public function deleteAll(string $locatorHash): int
    {
        return $this->states->deleteByLocator($locatorHash);
    }

    /** Schreibt die Aufbewahrungsfrist fort, sofern sie nennenswert altert. */
    private function touch(string $locatorHash): void
    {
        $now = new \DateTimeImmutable();
        $this->states->touchLocator($locatorHash, $now, $now->modify(self::TOUCH_AFTER));
    }
}
