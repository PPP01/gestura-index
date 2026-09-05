<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Entry;
use App\Entity\EntryVersion;
use App\Entity\Submitter;
use App\Entity\SyncState;
use App\Enum\Category;
use App\Enum\EntryStatus;
use App\Enum\EntryType;
use App\Enum\VersionStatus;
use App\Service\EditTokenService;
use App\Api\SyncContract;
use App\Repository\SyncStateRepository;
use App\Service\PayloadAnalyzer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class ApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        // Rate-Limiter-Cache je Test zurücksetzen: die DB rollt per
        // dama/doctrine-test-bundle zurück, der Limiter-Cache (eigener Pool)
        // aber nicht — sonst würden Limiter-Zustände zwischen Tests (und
        // Testläufen) durchschlagen. Erst dadurch sind Limiter mit echten
        // Test-Limits (z.B. report_per_entry) deterministisch prüfbar.
        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    /** @return array{0: Submitter, 1: string} Submitter und Klartext-Token */
    protected function createSubmitterWithToken(): array
    {
        $generated = (new EditTokenService())->generate();
        $submitter = new Submitter($generated->selector, $generated->hash);
        $this->em->persist($submitter);
        $this->em->flush();

        return [$submitter, $generated->token];
    }

    /** @return array<string, mixed> */
    protected function menuPayload(array $overrides = []): array
    {
        return array_replace([
            'gesturaMenu' => 1,
            'id' => 'com.example.shop',
            'version' => '1.0.0',
            'name' => ['en' => 'Example Shop', 'de' => 'Beispiel-Shop'],
            'description' => ['en' => 'Shop menu'],
            'patterns' => ['*example.com*'],
            'items' => [
                ['id' => 'orders', 'label' => ['de' => 'Bestellungen'], 'action' => 'openCustomUrl', 'customUrl' => 'https://example.com/orders'],
            ],
        ], $overrides);
    }

    /** @return array<string, mixed> */
    protected function enginePayload(array $overrides = []): array
    {
        return array_replace([
            'gesturaEngine' => 1,
            'id' => 'com.example.search',
            'version' => '1.0.0',
            'name' => 'Example Search',
            'url' => 'https://example.com/s?q=%s',
        ], $overrides);
    }

    /**
     * Legt einen veröffentlichten Entry mit freigegebener Version an.
     *
     * @param array<string, mixed> $payloadOverrides
     */
    protected function createPublishedEntry(
        string $formatId = 'com.example.shop',
        array $payloadOverrides = [],
        ?Submitter $submitter = null,
    ): Entry {
        if ($submitter === null) {
            [$submitter] = $this->createSubmitterWithToken();
        }
        // Engine-Overrides dürfen NICHT über den Menü-Default gelegt werden:
        // sonst trägt ein »Engine«-Fixture heimlich items und patterns mit sich
        // und verfälscht alles, was daraus abgeleitet wird (Domains, Suchtext,
        // itemCount). Der Typ bestimmt daher die Basis, nicht nur das Label.
        $overrides = ['id' => $formatId] + $payloadOverrides;
        $payload = isset($overrides['gesturaEngine'])
            ? array_replace($this->enginePayload(), $overrides)
            : $this->menuPayload($overrides);
        $type = isset($payload['gesturaEngine']) ? EntryType::Engine : EntryType::Menu;
        $analyzer = new PayloadAnalyzer();

        $entry = new Entry($formatId, $type, $submitter);
        $entry->status = EntryStatus::Published;
        $entry->setCategories([Category::Shopping]);
        $entry->domains = $analyzer->extractDomains($payload);
        $entry->searchText = $analyzer->searchText($payload);

        $version = new EntryVersion($entry, $payload['version'], $payload, $analyzer->contentHash($payload));
        $version->status = VersionStatus::Approved;
        $entry->currentVersion = $version;

        $this->em->persist($entry);
        $this->em->persist($version);
        $this->em->flush();

        return $entry;
    }

    /**
     * Legt eine nie freigegebene, abgelehnte Junk-Einreichung an: Entry
     * deleted, Version rejected, currentVersion bleibt null. Dient dem
     * Test, dass solche Einreichungen weder fremden Content-Hash noch
     * fremde formatId dauerhaft blockieren dürfen.
     *
     * @param array<string, mixed> $payloadOverrides
     */
    protected function createRejectedJunkEntry(
        string $formatId,
        array $payloadOverrides = [],
        ?Submitter $submitter = null,
    ): Entry {
        if ($submitter === null) {
            [$submitter] = $this->createSubmitterWithToken();
        }
        $payload = $this->menuPayload(['id' => $formatId] + $payloadOverrides);
        $analyzer = new PayloadAnalyzer();

        $entry = new Entry($formatId, EntryType::Menu, $submitter);
        $entry->status = EntryStatus::Deleted;
        $entry->setCategories([Category::Shopping]);

        $version = new EntryVersion($entry, $payload['version'], $payload, $analyzer->contentHash($payload));
        $version->status = VersionStatus::Rejected;

        $this->em->persist($entry);
        $this->em->persist($version);
        $this->em->flush();

        return $entry;
    }

    // --- Locator-Sync (Extension-Vertrag apiLevel 3) -------------------

    /**
     * Der Locator aus »Derivation test vectors« des Vertrags (Geheimnis
     * 0001…1f) und ein zweiter daneben. Aus dem Vertrag übernommen, damit
     * die Tests dieselben Werte benutzen wie die Gegenseite.
     */
    protected const SYNC_LOCATOR = 'zoogXw2lwmt_ZqFnRu-lFOWYxyJaU2kpxfunpy3Umsk';
    protected const SYNC_OTHER_LOCATOR = '3qzyS44KqXaBNzKvFSontDE8CfLPp8lwOUVHroaeg7M';
    protected const SYNC_STATE_ID = '0123456789abcdef0123456789abcdef';

    /** Legt einen Sync-Stand an; der Locator wird dabei gehasht wie im Betrieb. */
    protected function seedSyncState(string $locator, string $stateId, string $payload = 'cGF5bG9hZA=='): SyncState
    {
        $state = new SyncState(SyncContract::locatorHash($locator), $stateId, 'bWV0YQ==', $payload);
        $this->em->persist($state);
        $this->em->flush();

        return $state;
    }

    protected function syncStates(): SyncStateRepository
    {
        return static::getContainer()->get(SyncStateRepository::class);
    }

    /** @param array<string, mixed>|null $body */
    protected function api(string $method, string $uri, ?array $body = null, ?string $token = null): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        $this->client->request($method, $uri, server: $server, content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    protected function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 64, JSON_THROW_ON_ERROR);
    }
}
