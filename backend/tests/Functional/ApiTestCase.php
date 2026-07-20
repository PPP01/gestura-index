<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Entry;
use App\Entity\EntryVersion;
use App\Entity\Submitter;
use App\Enum\Category;
use App\Enum\EntryStatus;
use App\Enum\EntryType;
use App\Enum\VersionStatus;
use App\Service\EditTokenService;
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
        $payload = $this->menuPayload(['id' => $formatId] + $payloadOverrides);
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
