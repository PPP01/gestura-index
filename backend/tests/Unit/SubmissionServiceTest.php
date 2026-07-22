<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Entry;
use App\Entity\Submitter;
use App\Enum\EntryType;
use App\Exception\ApiProblem;
use App\Repository\EntryVersionRepository;
use App\Service\ExchangeValidator;
use App\Service\PayloadAnalyzer;
use App\Service\SubmissionService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Prüft das Body-Parsing und die Metadaten-Übertragung isoliert – ohne
 * Datenbank, da parseSubmissionBody()/applyMetadata() reine Eingabe-Logik sind.
 */
final class SubmissionServiceTest extends TestCase
{
    private function service(): SubmissionService
    {
        return new SubmissionService(
            new ExchangeValidator(\dirname(__DIR__, 3) . '/schema/exchange-schema.json'),
            new PayloadAnalyzer(),
            $this->createStub(EntryVersionRepository::class),
        );
    }

    /** @param array<string, mixed> $body */
    private function parse(array $body, bool $categoriesRequired = false): array
    {
        $request = Request::create('/api/v1/entries', 'POST', content: json_encode($body, JSON_THROW_ON_ERROR));

        return $this->service()->parseSubmissionBody($request, $categoriesRequired);
    }

    private function entry(): Entry
    {
        return new Entry('com.example.shop', EntryType::Menu, new Submitter('selector', 'hash'));
    }

    public function testScalarTagsAreRejectedInsteadOfCoerced(): void
    {
        $this->expectException(ApiProblem::class);

        // Ein einzelner String darf NICHT stillschweigend zu ["tag"] werden.
        $this->parse(['payload' => ['x' => 1], 'tags' => 'einzeltag']);
    }

    public function testDeprecatedAsStringIsRejected(): void
    {
        $this->expectException(ApiProblem::class);

        // "false" ist truthy → (bool) würde true ergeben. Muss abgelehnt werden.
        $this->parse(['payload' => ['x' => 1], 'deprecated' => 'false']);
    }

    public function testDeprecatedBooleanIsHonoredExactly(): void
    {
        self::assertFalse($this->parse(['payload' => ['x' => 1], 'deprecated' => false])['deprecated']);
        self::assertTrue($this->parse(['payload' => ['x' => 1], 'deprecated' => true])['deprecated']);
        self::assertNull($this->parse(['payload' => ['x' => 1]])['deprecated']);
    }

    public function testExplicitNullSuccessorFormatIdClearsExistingValue(): void
    {
        $entry = $this->entry();
        $entry->successorFormatId = 'com.example.alt';

        $meta = $this->parse(['payload' => ['x' => 1], 'successorFormatId' => null]);
        $this->service()->applyMetadata($entry, $meta);

        self::assertNull($entry->successorFormatId);
    }

    public function testAbsentSuccessorFormatIdLeavesExistingValueUnchanged(): void
    {
        $entry = $this->entry();
        $entry->successorFormatId = 'com.example.alt';

        $meta = $this->parse(['payload' => ['x' => 1]]);
        $this->service()->applyMetadata($entry, $meta);

        self::assertSame('com.example.alt', $entry->successorFormatId);
    }

    public function testSuccessorFormatIdStringIsApplied(): void
    {
        $entry = $this->entry();

        $meta = $this->parse(['payload' => ['x' => 1], 'successorFormatId' => 'com.example.neu']);
        $this->service()->applyMetadata($entry, $meta);

        self::assertSame('com.example.neu', $entry->successorFormatId);
    }

    public function testInvalidJsonBodyIsRejected(): void
    {
        $request = Request::create('/api/v1/entries', 'POST', content: '{kaputt');

        $this->expectException(ApiProblem::class);
        $this->service()->parseSubmissionBody($request, false);
    }

    public function testMissingPayloadObjectIsRejected(): void
    {
        $this->expectException(ApiProblem::class);

        // Body ohne "payload"-Schlüssel überhaupt.
        $this->parse(['foo' => 'bar']);
    }

    public function testCategoriesMustBeAList(): void
    {
        $this->expectException(ApiProblem::class);

        $this->parse(['payload' => ['x' => 1], 'categories' => 'shopping']);
    }

    public function testNonStringCategoryKeyIsRejected(): void
    {
        $this->expectException(ApiProblem::class);

        $this->parse(['payload' => ['x' => 1], 'categories' => [123]]);
    }

    public function testNonStringTagIsRejected(): void
    {
        $this->expectException(ApiProblem::class);

        $this->parse(['payload' => ['x' => 1], 'tags' => [123]]);
    }

    public function testEmptyAndDuplicateTagsAreSkippedNotStored(): void
    {
        // " Shop " (getrimmt/lowercase "shop"), Leerstring und ein exaktes
        // Duplikat dürfen den bereits gesammelten Eintrag nicht verdoppeln.
        $result = $this->parse(['payload' => ['x' => 1], 'tags' => ['Shop', ' shop ', '', 'Shop']]);

        self::assertSame(['shop'], $result['tags']);
    }

    public function testTagExceedingLengthLimitIsRejected(): void
    {
        $this->expectException(ApiProblem::class);

        $this->parse(['payload' => ['x' => 1], 'tags' => [str_repeat('a', 51)]]);
    }

    public function testMoreThanTenTagsAreRejected(): void
    {
        $this->expectException(ApiProblem::class);

        $tags = [];
        for ($i = 0; $i < 11; ++$i) {
            $tags[] = 'tag' . $i;
        }
        $this->parse(['payload' => ['x' => 1], 'tags' => $tags]);
    }

    public function testNonStringChangelogIsRejected(): void
    {
        $this->expectException(ApiProblem::class);

        $this->parse(['payload' => ['x' => 1], 'changelog' => 12345]);
    }

    public function testOversizedChangelogIsRejected(): void
    {
        $this->expectException(ApiProblem::class);

        $this->parse(['payload' => ['x' => 1], 'changelog' => str_repeat('x', 2001)]);
    }

    public function testNonStringSuccessorFormatIdIsRejected(): void
    {
        $this->expectException(ApiProblem::class);

        $this->parse(['payload' => ['x' => 1], 'successorFormatId' => 12345]);
    }

    public function testOversizedSuccessorFormatIdIsRejected(): void
    {
        $this->expectException(ApiProblem::class);

        $this->parse(['payload' => ['x' => 1], 'successorFormatId' => str_repeat('a', 129)]);
    }

    public function testValidatePayloadRejectsTypeMismatch(): void
    {
        $menu = [
            'gesturaMenu' => 1,
            'id' => 'com.example.shop',
            'version' => '1.0.0',
            'name' => 'Example Shop',
            'patterns' => ['*example.com*'],
            'items' => [
                ['id' => 'orders', 'label' => 'Bestellungen', 'action' => 'newTab'],
            ],
        ];
        $payloadJson = json_encode($menu, JSON_THROW_ON_ERROR);

        $this->expectException(ApiProblem::class);
        $this->expectExceptionMessage('Payload type does not match entry type');
        // Eintrag erwartet Engine, Payload ist tatsächlich ein Menü.
        $this->service()->validatePayload($payloadJson, EntryType::Engine, null);
    }

    public function testApplyMetadataSetsDeprecatedFlagFromMeta(): void
    {
        $entry = $this->entry();
        self::assertFalse($entry->deprecated);

        $meta = $this->parse(['payload' => ['x' => 1], 'deprecated' => true]);
        $this->service()->applyMetadata($entry, $meta);

        self::assertTrue($entry->deprecated);
    }
}
