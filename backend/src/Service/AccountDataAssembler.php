<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Account;
use App\Entity\Entry;
use App\Entity\Submitter;
use App\Repository\EntryRepository;
use App\Repository\EntryVersionRepository;
use App\Repository\SubmitterRepository;
use App\Repository\SyncBlobRepository;

/**
 * Baut die vollständige Selbstauskunft eines End-Nutzer-Kontos (»Meine Daten«,
 * Phase 3 E) als serialisierbares Array: Konto-Metadaten, Sync-Blobs inkl.
 * Chiffrat und die verknüpften Submitter mit ihren Einträgen als Referenzliste.
 *
 * Bewusst NICHT enthalten: tokenHash (Konto UND Submitter – abgeleitetes
 * Geheimnis-Material) sowie Entry-Payloads (öffentlicher Index-Inhalt, über die
 * Browse-API abrufbar). Alle Queries filtern hart auf das übergebene Konto
 * (Isolation). Entry→Submitter und EntryVersion→Entry sind unidirektional –
 * die Kinder werden per Repository nachgeladen, nicht über Assoziationen.
 */
final class AccountDataAssembler
{
    public function __construct(
        private readonly SyncBlobRepository $blobs,
        private readonly SubmitterRepository $submitters,
        private readonly EntryRepository $entries,
        private readonly EntryVersionRepository $versions,
    ) {
    }

    /**
     * @return array{account: array<string, string>, sync: \stdClass, submitters: list<array<string, mixed>>}
     */
    public function assemble(Account $account): array
    {
        return [
            'account' => [
                'createdAt' => $account->createdAt->format(\DateTimeInterface::ATOM),
                'lastSeenAt' => $account->lastSeenAt->format(\DateTimeInterface::ATOM),
            ],
            // (object)-Cast: ein leeres Ergebnis serialisiert als {} statt [].
            'sync' => (object) $this->assembleSync($account),
            'submitters' => $this->assembleSubmitters($account),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function assembleSync(Account $account): array
    {
        $out = [];
        foreach ($this->blobs->findBy(['account' => $account]) as $blob) {
            $out[$blob->collection] = [
                'version' => $blob->version,
                'updatedAt' => $blob->updatedAt->format(\DateTimeInterface::ATOM),
                'size' => \strlen($blob->ciphertext),
                'ciphertext' => $blob->ciphertext,
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function assembleSubmitters(Account $account): array
    {
        $out = [];
        foreach ($this->submitters->findBy(['account' => $account]) as $submitter) {
            $out[] = [
                'tokenSelector' => $submitter->tokenSelector,
                'approvedCount' => $submitter->approvedCount,
                'banned' => $submitter->banned,
                'createdAt' => $submitter->createdAt->format(\DateTimeInterface::ATOM),
                'entries' => $this->assembleEntries($submitter),
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function assembleEntries(Submitter $submitter): array
    {
        $out = [];
        foreach ($this->entries->findBy(['submitter' => $submitter]) as $entry) {
            $out[] = [
                'formatId' => $entry->formatId,
                'type' => $entry->type->value,
                'status' => $entry->status->value,
                'createdAt' => $entry->createdAt->format(\DateTimeInterface::ATOM),
                'versions' => $this->assembleVersions($entry),
            ];
        }

        return $out;
    }

    /** @return list<array{semver: string, status: string}> */
    private function assembleVersions(Entry $entry): array
    {
        $out = [];
        foreach ($this->versions->findBy(['entry' => $entry]) as $version) {
            $out[] = [
                'semver' => $version->semver,
                'status' => $version->status->value,
            ];
        }

        return $out;
    }
}
