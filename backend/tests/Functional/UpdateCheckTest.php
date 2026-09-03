<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Api\ApiLevel;

final class UpdateCheckTest extends ApiTestCase
{
    public function testAnswersWithContractEnvelopeAndElementShape(): void
    {
        $entry = $this->createPublishedEntry('com.example.shop', ['version' => '2.1.0']);
        $entry->currentVersion->changelog = 'Two new patterns for /cart';
        $this->em->flush();

        $this->api('POST', '/api/v1/updates', ['apiLevel' => 2, 'entries' => [
            ['id' => 'com.example.shop', 'version' => '1.0.0'],
        ]]);

        self::assertResponseIsSuccessful();
        $body = $this->json();
        self::assertSame(ApiLevel::IMPLEMENTED, $body['apiLevel']);
        self::assertSame([[
            'id' => 'com.example.shop',
            'type' => 'menu',
            'version' => '2.1.0',
            'url' => 'http://localhost/api/v1/entries/com.example.shop/versions/2.1.0',
            'changelog' => 'Two new patterns for /cart',
            'deprecated' => false,
            'successor' => null,
        ]], $body['updates']);
    }

    public function testUrlFollowsTheRequestsOwnSchemeAndHost(): void
    {
        // Der Vertrag verlangt, dass »url« auf der antwortenden Origin liegt –
        // ein hart kodierter Basis-URL würde diesen Test trotzdem bestehen,
        // wenn er zufällig mit dem Standard-Testhost übereinstimmt. Ein
        // abweichender Host + HTTPS pinnt die tatsächlich fragilste Regel
        // des Vertrags: dass die URL aus dem Request selbst gebildet wird.
        $this->createPublishedEntry('com.example.shop', ['version' => '2.1.0']);

        $this->client->request('POST', '/api/v1/updates', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_HOST' => 'anderer-host.example',
            'HTTPS' => 'on',
        ], content: json_encode(['entries' => [
            ['id' => 'com.example.shop', 'version' => '1.0.0'],
        ]], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        self::assertSame(
            'https://anderer-host.example/api/v1/entries/com.example.shop/versions/2.1.0',
            $this->json()['updates'][0]['url'],
        );
    }

    public function testReportsEngineTypeFromEntry(): void
    {
        $this->createPublishedEntry('com.example.search', ['gesturaEngine' => 1, 'version' => '3.0.0']);

        $this->api('POST', '/api/v1/updates', ['entries' => [['id' => 'com.example.search', 'version' => '1.0.0']]]);

        $updates = $this->json()['updates'];
        self::assertCount(1, $updates);
        self::assertSame('engine', $updates[0]['type']);
        self::assertNull($updates[0]['changelog']);
    }

    public function testStaysSilentForEqualOrHigherClientVersion(): void
    {
        $this->createPublishedEntry('com.example.shop', ['version' => '1.3.0']);

        $this->api('POST', '/api/v1/updates', ['entries' => [
            ['id' => 'com.example.shop', 'version' => '1.3.0'],
        ]]);
        self::assertSame([], $this->json()['updates']);

        // Handimport einer neueren Fassung: 1.3.0 darf nicht als »Update« angeboten werden.
        $this->api('POST', '/api/v1/updates', ['entries' => [
            ['id' => 'com.example.shop', 'version' => '1.4.0'],
        ]]);
        self::assertSame([], $this->json()['updates']);
    }

    public function testComparesVersionsNumericallyNotLexically(): void
    {
        $this->createPublishedEntry('com.example.shop', ['version' => '1.10.0']);

        $this->api('POST', '/api/v1/updates', ['entries' => [['id' => 'com.example.shop', 'version' => '1.9.0']]]);

        self::assertSame('1.10.0', $this->json()['updates'][0]['version']);
    }

    public function testNullVersionAsksForCurrentVersion(): void
    {
        $this->createPublishedEntry('com.example.shop', ['version' => '1.3.0']);

        $this->api('POST', '/api/v1/updates', ['entries' => [['id' => 'com.example.shop', 'version' => null]]]);

        $updates = $this->json()['updates'];
        self::assertCount(1, $updates);
        self::assertSame('1.3.0', $updates[0]['version']);
    }

    public function testDeprecatedEntryIsReportedEvenWithUnchangedVersion(): void
    {
        $entry = $this->createPublishedEntry('com.example.old', ['version' => '1.0.0']);
        $entry->deprecated = true;
        $entry->successorFormatId = 'com.example.new';
        $this->em->flush();

        $this->api('POST', '/api/v1/updates', ['entries' => [['id' => 'com.example.old', 'version' => '1.0.0']]]);

        $updates = $this->json()['updates'];
        self::assertCount(1, $updates);
        self::assertTrue($updates[0]['deprecated']);
        self::assertSame('com.example.new', $updates[0]['successor']);
        self::assertSame('1.0.0', $updates[0]['version']);
    }

    public function testDeprecatedEntryWithNewerVersionCarriesBoth(): void
    {
        $entry = $this->createPublishedEntry('com.example.old', ['version' => '2.0.0']);
        $entry->deprecated = true;
        $this->em->flush();

        $this->api('POST', '/api/v1/updates', ['entries' => [['id' => 'com.example.old', 'version' => '1.0.0']]]);

        $updates = $this->json()['updates'];
        self::assertSame('2.0.0', $updates[0]['version']);
        self::assertTrue($updates[0]['deprecated']);
        self::assertNull($updates[0]['successor']);
    }

    public function testSkipsUnknownUnpublishedAndMalformedEntriesButKeepsOrder(): void
    {
        $this->createPublishedEntry('com.example.b', ['version' => '2.0.0']);
        $this->createPublishedEntry('com.example.a', ['version' => '2.0.0']);
        $this->createRejectedJunkEntry('com.example.junk');

        $this->api('POST', '/api/v1/updates', ['entries' => [
            ['id' => 'com.example.b', 'version' => '1.0.0'],
            ['id' => 'com.example.unbekannt', 'version' => '1.0.0'],
            ['id' => 'com.example.junk', 'version' => '1.0.0'],
            ['id' => 'com.example.a', 'version' => 'keine-semver'],       // Version ungültig
            ['id' => 'com.example.a', 'version' => 7],                   // Version kein String
            ['id' => '-fuehrender-bindestrich', 'version' => '1.0.0'],   // Kennung verletzt das Muster
            ['id' => str_repeat('a', 129), 'version' => '1.0.0'],        // Kennung zu lang
            ['id' => 'com.example.a'],                                    // version fehlt ganz (≠ null)
            'kein-objekt',
            ['version' => '1.0.0'],                                       // id fehlt
            ['id' => 'com.example.a', 'version' => '1.0.0'],
            ['id' => 'com.example.b', 'version' => '0.0.1'],             // Doppelt: der erste Posten gewinnt
        ]]);

        self::assertResponseIsSuccessful();
        self::assertSame(['com.example.b', 'com.example.a'], array_column($this->json()['updates'], 'id'));
    }

    public function testEmptyListIsTheHealthyAnswer(): void
    {
        $this->api('POST', '/api/v1/updates', ['apiLevel' => 2, 'entries' => []]);

        self::assertResponseIsSuccessful();
        // Reihenfolge und Form sind Vertrag; smoke.sh prüft dieselbe Form per Regex.
        self::assertSame(
            sprintf('{"apiLevel":%d,"updates":[]}', ApiLevel::IMPLEMENTED),
            $this->client->getResponse()->getContent(),
        );
    }

    public function testRejectsMalformedEnvelope(): void
    {
        $many = array_fill(0, 201, ['id' => 'com.example.x', 'version' => '1.0.0']);
        $this->api('POST', '/api/v1/updates', ['entries' => $many]);
        self::assertResponseStatusCodeSame(400);

        $this->api('POST', '/api/v1/updates', ['entries' => 'quatsch']);
        self::assertResponseStatusCodeSame(400);

        $this->api('POST', '/api/v1/updates', ['apiLevel' => 2]);
        self::assertResponseStatusCodeSame(400);

        $this->client->request('POST', '/api/v1/updates',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{kaputtes json',
        );
        self::assertResponseStatusCodeSame(400);
    }

    public function testChangelogIsTruncatedToTheOneThousandCharsTheClientKeeps(): void
    {
        $entry = $this->createPublishedEntry('com.example.shop', ['version' => '2.1.0']);
        $entry->currentVersion->changelog = str_repeat('x', 1500);
        $this->em->flush();

        $this->api('POST', '/api/v1/updates', ['entries' => [
            ['id' => 'com.example.shop', 'version' => '1.0.0'],
        ]]);

        $changelog = $this->json()['updates'][0]['changelog'];
        self::assertSame(1000, mb_strlen($changelog));
        self::assertSame(str_repeat('x', 1000), $changelog);
    }

    public function testChangelogByteBudgetKeepsEveryElementButNullsLaterChangelogs(): void
    {
        // Nicht-ASCII-Zeichen kosten beim Kodieren ohne JSON_UNESCAPED_UNICODE
        // bis zu 6 Byte pro Zeichen (\uXXXX) – nach der 1000-Zeichen-Kürzung
        // also bis zu 6002 Byte je changelog inkl. Anführungszeichen. 40
        // solche Elemente (240 KiB an changelog-Rohdaten) reißen sicher durch
        // das 200-KiB-Budget des Controllers, ohne die Vertragsgrenze von 200
        // Elementen zu berühren.
        $count = 40;
        for ($i = 0; $i < $count; ++$i) {
            $formatId = sprintf('com.example.budget%02d', $i);
            $entry = $this->createPublishedEntry($formatId, ['version' => '2.0.0']);
            $entry->currentVersion->changelog = str_repeat('日', 1500);
        }
        $this->em->flush();

        $this->api('POST', '/api/v1/updates', ['entries' => array_map(
            static fn (int $i): array => ['id' => sprintf('com.example.budget%02d', $i), 'version' => '1.0.0'],
            range(0, $count - 1),
        )]);

        self::assertResponseIsSuccessful();
        $rawBody = (string) $this->client->getResponse()->getContent();
        self::assertLessThan(256 * 1024, \strlen($rawBody), 'Antwort muss unter dem 256-KiB-Cap des Clients bleiben');

        $updates = $this->json()['updates'];
        // Kein Element darf fehlen – nur changelog darf leer werden.
        self::assertCount($count, $updates);

        $sawNull = false;
        foreach ($updates as $update) {
            if ($update['changelog'] === null) {
                $sawNull = true;
                continue;
            }
            // Sobald ein Element wegen des Budgets auf null gesetzt wurde,
            // bleibt es für den Rest der Antwort dabei (monotones Budget).
            self::assertFalse($sawNull, 'Nach dem ersten null-changelog dürfen keine weiteren changelogs mehr folgen');
            self::assertSame(1000, mb_strlen($update['changelog']));
        }
        // Bei 40 Elementen à maximal 6002 Byte (240 KiB) muss das 200-KiB-
        // Budget mindestens ein Element zum Verstummen bringen.
        self::assertTrue($sawNull, 'Testaufbau muss das Budget tatsächlich überschreiten');
    }

    public function testOldPathIsGone(): void
    {
        // /api/v1/entries/{formatId} existiert für GET/PUT/DELETE, daher 405 statt 404 – beides heißt: kein Update-Check mehr.
        $this->api('POST', '/api/v1/entries/updates', ['entries' => []]);
        self::assertContains($this->client->getResponse()->getStatusCode(), [404, 405]);
    }
}
