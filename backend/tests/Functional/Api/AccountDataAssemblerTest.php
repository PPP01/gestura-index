<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Account;
use App\Entity\Entry;
use App\Entity\EntryVersion;
use App\Entity\Rating;
use App\Entity\Submitter;
use App\Entity\SyncBlob;
use App\Enum\VersionStatus;
use App\Service\AccountDataAssembler;
use App\Service\PayloadAnalyzer;
use App\Tests\Functional\ApiTestCase;

final class AccountDataAssemblerTest extends ApiTestCase
{
    /**
     * Assembler direkt instanziieren (statt aus dem Container), mit den echten
     * Repositories aus dem EntityManager – robust gegen private-Service-Regeln
     * und explizit über die Abhängigkeiten.
     */
    private function assembler(): AccountDataAssembler
    {
        return new AccountDataAssembler(
            $this->em->getRepository(SyncBlob::class),
            $this->em->getRepository(Submitter::class),
            $this->em->getRepository(Entry::class),
            $this->em->getRepository(EntryVersion::class),
            $this->em->getRepository(Rating::class),
        );
    }

    private function makeAccount(string $selector): Account
    {
        $account = new Account($selector, 'hash-' . $selector);
        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    public function testEmptyAccountYieldsEmptyStructures(): void
    {
        $account = $this->makeAccount(str_pad('a', 16, 'a'));

        $data = $this->assembler()->assemble($account);

        self::assertArrayHasKey('createdAt', $data['account']);
        self::assertArrayHasKey('lastSeenAt', $data['account']);
        self::assertArrayNotHasKey('tokenHash', $data['account']);
        self::assertArrayNotHasKey('tokenSelector', $data['account']);
        // Leeres sync MUSS als Objekt ({}) serialisieren, nicht als []:
        self::assertInstanceOf(\stdClass::class, $data['sync']);
        self::assertSame([], (array) $data['sync']);
        self::assertSame([], $data['submitters']);
    }

    public function testFullProfileContainsAllData(): void
    {
        $account = $this->makeAccount(str_pad('b', 16, 'b'));

        // Zwei Sync-Blobs (verschiedene Collections):
        $this->em->persist(new SyncBlob($account, 'settings', 'cipher-settings'));
        $this->em->persist(new SyncBlob($account, 'menus', 'cipher-menus'));

        // Verknüpfter Submitter mit einem Entry und zwei Versionen:
        [$submitter] = $this->createSubmitterWithToken();
        $submitter->account = $account;
        $submitter->approvedCount = 3;
        $this->em->flush();

        $entry = $this->createPublishedEntry('com.example.shop', [], $submitter); // legt Version 1.0.0 (approved) an
        $analyzer = new PayloadAnalyzer();
        $payload2 = $this->menuPayload(['id' => 'com.example.shop', 'version' => '1.1.0']);
        $v2 = new EntryVersion($entry, '1.1.0', $payload2, $analyzer->contentHash($payload2));
        $v2->status = VersionStatus::Pending;
        $this->em->persist($v2);
        $this->em->flush();

        $data = $this->assembler()->assemble($account);

        // sync (inkl. Chiffrat):
        $sync = (array) $data['sync'];
        self::assertSame('cipher-settings', $sync['settings']['ciphertext']);
        self::assertSame(1, $sync['settings']['version']);
        self::assertSame(\strlen('cipher-menus'), $sync['menus']['size']);
        self::assertArrayHasKey('updatedAt', $sync['menus']);

        // submitters (ohne tokenHash):
        self::assertCount(1, $data['submitters']);
        $s = $data['submitters'][0];
        self::assertSame($submitter->tokenSelector, $s['tokenSelector']);
        self::assertSame(3, $s['approvedCount']);
        self::assertFalse($s['banned']);
        self::assertArrayNotHasKey('tokenHash', $s);

        // entries + versions (ohne payload):
        self::assertCount(1, $s['entries']);
        $e = $s['entries'][0];
        self::assertSame('com.example.shop', $e['formatId']);
        self::assertSame('menu', $e['type']);
        self::assertSame('published', $e['status']);
        self::assertArrayNotHasKey('payload', $e);
        $semvers = array_column($e['versions'], 'semver');
        self::assertContains('1.0.0', $semvers);
        self::assertContains('1.1.0', $semvers);
    }

    public function testAccountIsolationExcludesForeignData(): void
    {
        $mine = $this->makeAccount(str_pad('c', 16, 'c'));
        $other = $this->makeAccount(str_pad('d', 16, 'd'));

        $this->em->persist(new SyncBlob($other, 'settings', 'foreign-cipher'));
        [$foreignSub] = $this->createSubmitterWithToken();
        $foreignSub->account = $other;
        $this->em->flush();

        $thatEntry = $this->createPublishedEntry('com.example.iso-rating');
        $this->em->persist(new Rating($other, $thatEntry, 5));
        $this->em->flush();

        $data = $this->assembler()->assemble($mine);

        self::assertSame([], (array) $data['sync']);
        self::assertSame([], $data['submitters']);
        self::assertSame([], $data['ratings']);
    }
}
