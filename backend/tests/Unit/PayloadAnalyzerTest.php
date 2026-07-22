<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\PayloadAnalyzer;
use PHPUnit\Framework\TestCase;

final class PayloadAnalyzerTest extends TestCase
{
    private PayloadAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new PayloadAnalyzer();
    }

    public function testExtractDomainsFromPatterns(): void
    {
        $payload = ['patterns' => ['*example.com*', 'https://www.GitHub.com/foo/*', '*shop.example.com/cart*', 'kein-muster', '*example.com*']];
        self::assertSame(['example.com', 'www.github.com', 'shop.example.com'], $this->analyzer->extractDomains($payload));
    }

    public function testExtractDomainsSkipsNonStringPatterns(): void
    {
        // Fremdartige Werte in patterns (kein Schema-Verstoß auf dieser Ebene
        // erzwungen) dürfen die Domain-Extraktion nicht mit einem TypeError
        // zum Absturz bringen, sondern werden übersprungen.
        $payload = ['patterns' => [123, null, ['verschachtelt'], '*example.com*']];
        self::assertSame(['example.com'], $this->analyzer->extractDomains($payload));
    }

    public function testSearchTextCollectsAllLanguages(): void
    {
        $payload = [
            'name' => ['en' => 'Example Shop', 'de' => 'Beispiel-Shop'],
            'description' => 'A test',
        ];
        $text = $this->analyzer->searchText($payload);
        self::assertStringContainsString('example shop', $text);
        self::assertStringContainsString('beispiel-shop', $text);
        self::assertStringContainsString('a test', $text);
    }

    public function testContentHashIsKeyOrderIndependent(): void
    {
        $a = ['items' => [['id' => 'x']], 'name' => ['en' => 'A', 'de' => 'B']];
        $b = ['name' => ['de' => 'B', 'en' => 'A'], 'items' => [['id' => 'x']]];
        self::assertSame($this->analyzer->contentHash($a), $this->analyzer->contentHash($b));
        self::assertSame(64, \strlen($this->analyzer->contentHash($a)));
        self::assertNotSame($this->analyzer->contentHash($a), $this->analyzer->contentHash(['name' => 'C']));
    }

    public function testContentHashIgnoresIdAndVersion(): void
    {
        // Duplikat-Erkennung: derselbe Inhalt unter neuer Kennung/Version
        // muss denselben Hash ergeben (Spam-Szenario aus dem Spec).
        $a = ['id' => 'com.example.a', 'version' => '1.0.0', 'name' => 'Gleich', 'items' => [['id' => 'x']]];
        $b = ['id' => 'com.example.b', 'version' => '2.3.4', 'name' => 'Gleich', 'items' => [['id' => 'x']]];
        self::assertSame($this->analyzer->contentHash($a), $this->analyzer->contentHash($b));
    }

    public function testHasTransformRequiresEnabledAndNonEmptyCode(): void
    {
        self::assertTrue($this->analyzer->hasTransform(['transformEnabled' => true, 'transformCode' => 'return x;']));
        self::assertFalse($this->analyzer->hasTransform(['transformEnabled' => true, 'transformCode' => '   ']));
        self::assertFalse($this->analyzer->hasTransform(['transformCode' => 'return x;']));
        self::assertFalse($this->analyzer->hasTransform(['gesturaMenu' => 1]));
    }
}
