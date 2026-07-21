<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Entry;
use App\Enum\EntryStatus;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ScreenshotTest extends ApiTestCase
{
    private function privateFile(string $formatId = 'com.example.shop'): string
    {
        return static::getContainer()->getParameter('kernel.project_dir')
            . '/var/media/screenshots/' . $formatId . '.webp';
    }

    protected function tearDown(): void
    {
        // Dateisystem rollt (anders als die DB) nicht zurück.
        $file = $this->privateFile();
        if (is_file($file)) {
            unlink($file);
        }
        parent::tearDown();
    }

    private function makePngUpload(int $width = 1600, int $height = 1000): UploadedFile
    {
        $img = imagecreatetruecolor($width, $height);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 90, 156, 246));
        $path = tempnam(sys_get_temp_dir(), 'shot') . '.png';
        imagepng($img, $path);

        return new UploadedFile($path, 'screenshot.png', 'image/png', test: true);
    }

    private function upload(string $token, string $formatId = 'com.example.shop'): void
    {
        $this->client->request('POST', '/api/v1/entries/' . $formatId . '/screenshot',
            files: ['screenshot' => $this->makePngUpload()],
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );
    }

    public function testUploadStoresPrivatelyAndReturnsApiUrl(): void
    {
        [$submitter, $token] = $this->createSubmitterWithToken();
        $this->createPublishedEntry('com.example.shop', submitter: $submitter);

        $this->upload($token);

        self::assertResponseIsSuccessful();
        // Öffentliche URL zeigt auf den statusgeprüften API-Endpunkt, nicht auf
        // eine erratbare Datei im Docroot.
        self::assertSame('/api/v1/entries/com.example.shop/screenshot', $this->json()['screenshotUrl']);

        // Datei liegt privat außerhalb des Docroots und ist ein herunterskaliertes WebP.
        $file = $this->privateFile();
        self::assertFileExists($file);
        [$w, $h] = getimagesize($file);
        self::assertLessThanOrEqual(1280, $w);
        self::assertLessThanOrEqual(800, $h);
        self::assertSame('image/webp', mime_content_type($file));

        $this->em->clear();
        $entry = $this->em->getRepository(Entry::class)->findOneBy(['formatId' => 'com.example.shop']);
        self::assertSame('com.example.shop.webp', $entry->screenshotPath);
    }

    public function testScreenshotOfPublishedEntryIsServed(): void
    {
        [$submitter, $token] = $this->createSubmitterWithToken();
        $this->createPublishedEntry('com.example.shop', submitter: $submitter);
        $this->upload($token);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/v1/entries/com.example.shop/screenshot');

        self::assertResponseIsSuccessful();
        self::assertSame('image/webp', $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testScreenshotOfHiddenEntryIsNotServed(): void
    {
        [$submitter, $token] = $this->createSubmitterWithToken();
        $entry = $this->createPublishedEntry('com.example.shop', submitter: $submitter);
        $this->upload($token);
        self::assertResponseIsSuccessful();

        // Eintrag wird (z.B. durch Meldungen) versteckt – das Bild darf trotz
        // vorhandener Datei nicht mehr öffentlich abrufbar sein.
        $entry->status = EntryStatus::Hidden;
        $this->em->flush();

        $this->client->request('GET', '/api/v1/entries/com.example.shop/screenshot');
        self::assertResponseStatusCodeSame(404);
    }

    public function testNonImageYields400(): void
    {
        [$submitter, $token] = $this->createSubmitterWithToken();
        $this->createPublishedEntry('com.example.shop', submitter: $submitter);

        $path = tempnam(sys_get_temp_dir(), 'fake') . '.png';
        file_put_contents($path, 'kein bild');
        $upload = new UploadedFile($path, 'fake.png', 'image/png', test: true);

        $this->client->request('POST', '/api/v1/entries/com.example.shop/screenshot',
            files: ['screenshot' => $upload],
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );
        self::assertResponseStatusCodeSame(400);
    }

    public function testMissingTokenYields401(): void
    {
        $this->createPublishedEntry('com.example.shop');

        $this->client->request('POST', '/api/v1/entries/com.example.shop/screenshot',
            files: ['screenshot' => $this->makePngUpload(100, 100)],
        );
        self::assertResponseStatusCodeSame(401);
    }

    public function testOversizedDimensionsYield400(): void
    {
        [$submitter, $token] = $this->createSubmitterWithToken();
        $this->createPublishedEntry('com.example.shop', submitter: $submitter);

        $this->client->request('POST', '/api/v1/entries/com.example.shop/screenshot',
            files: ['screenshot' => $this->makePngUpload(4100, 50)],
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );
        self::assertResponseStatusCodeSame(400);
    }

    public function testDeleteRemovesScreenshotFile(): void
    {
        [$submitter, $token] = $this->createSubmitterWithToken();
        $this->createPublishedEntry('com.example.shop', submitter: $submitter);

        $this->upload($token);
        self::assertResponseIsSuccessful();
        $file = $this->privateFile();
        self::assertFileExists($file);

        $this->api('DELETE', '/api/v1/entries/com.example.shop', token: $token);
        self::assertResponseStatusCodeSame(204);

        self::assertFileDoesNotExist($file);
        $this->em->clear();
        $entry = $this->em->getRepository(Entry::class)->findOneBy(['formatId' => 'com.example.shop']);
        self::assertNull($entry->screenshotPath);
    }
}
