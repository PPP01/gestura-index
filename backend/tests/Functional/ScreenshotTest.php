<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Entry;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ScreenshotTest extends ApiTestCase
{
    private function makePngUpload(int $width = 1600, int $height = 1000): UploadedFile
    {
        $img = imagecreatetruecolor($width, $height);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 90, 156, 246));
        $path = tempnam(sys_get_temp_dir(), 'shot') . '.png';
        imagepng($img, $path);
        imagedestroy($img);

        return new UploadedFile($path, 'screenshot.png', 'image/png', test: true);
    }

    public function testUploadReencodesToWebpAndScalesDown(): void
    {
        [$submitter, $token] = $this->createSubmitterWithToken();
        $this->createPublishedEntry('com.example.shop', submitter: $submitter);

        $this->client->request('POST', '/api/v1/entries/com.example.shop/screenshot',
            files: ['screenshot' => $this->makePngUpload()],
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );

        self::assertResponseIsSuccessful();
        $url = $this->json()['screenshotUrl'];
        self::assertSame('/media/screenshots/com.example.shop.webp', $url);

        $file = static::getContainer()->getParameter('kernel.project_dir') . '/public' . $url;
        self::assertFileExists($file);
        [$w, $h] = getimagesize($file);
        self::assertLessThanOrEqual(1280, $w);
        self::assertLessThanOrEqual(800, $h);
        self::assertSame('image/webp', mime_content_type($file));

        $this->em->clear();
        $entry = $this->em->getRepository(Entry::class)->findOneBy(['formatId' => 'com.example.shop']);
        self::assertSame('media/screenshots/com.example.shop.webp', $entry->screenshotPath);

        unlink($file); // Testartefakt aufräumen (Dateisystem rollt nicht zurück)
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
}
