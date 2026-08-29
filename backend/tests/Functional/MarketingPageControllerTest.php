<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\PageSetting;

final class MarketingPageControllerTest extends ApiTestCase
{
    public function testEnabledPageServesPrerenderedHtmlPerLocale(): void
    {
        $this->client->request('GET', '/de/vergleich');

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('FIXTURE_VERGLEICH_DE', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/en/vergleich');

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('FIXTURE_VERGLEICH_EN', (string) $this->client->getResponse()->getContent());
    }

    public function testDisabledPageIsRealNotFound(): void
    {
        $vergleich = $this->em->getRepository(PageSetting::class)->findOneBy(['pageKey' => 'vergleich']);
        self::assertNotNull($vergleich);
        $vergleich->enabled = false;
        $this->em->flush();

        $this->client->request('GET', '/de/vergleich');

        self::assertResponseStatusCodeSame(404);
        self::assertSame('noindex', $this->client->getResponse()->headers->get('X-Robots-Tag'));
    }

    public function testEnabledPageWithoutBuildFileIsNotFoundNotServerError(): void
    {
        $this->client->request('GET', '/de/maus-gesten');

        self::assertResponseStatusCodeSame(404);
    }
}
