<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\PageSetting;

final class PageVisibilityControllerTest extends ApiTestCase
{
    public function testAllPagesEnabledByDefault(): void
    {
        $this->api('GET', '/api/v1/pages');

        self::assertResponseIsSuccessful();
        $data = $this->json();
        self::assertSame(
            ['was-ist-gestura' => true, 'maus-gesten' => true, 'vergleich' => true, 'beispiele' => true],
            $data,
        );
    }

    public function testDisabledPageIsReflectedInResponse(): void
    {
        $vergleich = $this->em->getRepository(PageSetting::class)->findOneBy(['pageKey' => 'vergleich']);
        self::assertNotNull($vergleich);
        $vergleich->enabled = false;
        $this->em->flush();
        $this->em->clear();

        $this->api('GET', '/api/v1/pages');

        self::assertResponseIsSuccessful();
        $data = $this->json();
        self::assertFalse($data['vergleich']);
        self::assertTrue($data['was-ist-gestura']);
        self::assertTrue($data['maus-gesten']);
        self::assertTrue($data['beispiele']);
    }
}
