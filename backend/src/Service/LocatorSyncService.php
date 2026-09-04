<?php

declare(strict_types=1);

namespace App\Service;

use App\Api\SyncContract;
use App\Entity\SyncState;
use App\Exception\SyncProblem;
use App\Repository\SyncStateRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Der Zustandsübergang eines Sync-Standes (Vertrag, apiLevel 3): Quote
 * prüfen, »basePayloadHash« vergleichen, schreiben – in EINER Transaktion
 * mit Sperre auf der Zeile, damit zwei gleichzeitige Uploads nicht beide
 * »passt« sehen und der letzte gewinnt.
 *
 * WARUM DIE GUARDS NICHT WERFEN: EntityManager::wrapInTransaction() schließt
 * bei JEDER durchgereichten Exception den EntityManager (Vendor-finally:
 * close() + rollBack()). Ein 412, das aus der Closure heraus fliegt, machte
 * den EM danach unbenutzbar – und der Vertrag verlangt ausdrücklich, dass
 * nach einer Ablehnung serverseitig alles unverändert und benutzbar ist, weil
 * der Client neu merged und es bis zu dreimal je Knopfdruck erneut versucht.
 * Deshalb gibt die Closure ein Sentinel zurück und der Aufrufer wirft NACH
 * dem (leeren) Commit. Dasselbe Muster steht in .claude/lessons.md.
 */
final class LocatorSyncService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SyncStateRepository $states,
    ) {
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
        /** @var SyncState|SyncProblem $result */
        $result = $this->em->wrapInTransaction(function () use ($locatorHash, $stateId, $meta, $payload, $basePayloadHash) {
            $state = $this->states->findOneByLocatorAndState($locatorHash, $stateId);

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

            // Zwei gleichzeitige Uploads auf denselben Stand serialisieren:
            // ohne Sperre sehen beide dieselbe Basis, beide halten sie für
            // passend, und der letzte Commit gewinnt – genau der stille
            // Verlust, den basePayloadHash verhindern soll. Nach dem Sperren
            // neu laden, sonst prüft der Vergleich den Stand VOR der Sperre.
            $this->em->lock($state, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($state);

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
}
