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
}
