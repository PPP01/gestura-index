<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\ExchangeValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExchangeValidatorTest extends TestCase
{
    private ExchangeValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ExchangeValidator(\dirname(__DIR__, 3) . '/schema/exchange-schema.json');
    }

    /** @return array<string, mixed> */
    private function validMenu(): array
    {
        return [
            'gesturaMenu' => 1,
            'id' => 'com.example.shop',
            'version' => '1.2.0',
            'name' => ['en' => 'Example Shop', 'de' => 'Beispiel-Shop'],
            'patterns' => ['*example.com*'],
            'items' => [
                ['id' => 'orders', 'label' => ['de' => 'Bestellungen'], 'action' => 'openCustomUrl', 'customUrl' => 'https://example.com/orders'],
                ['id' => 'sep1', 'type' => 'separator'],
                ['id' => 'search', 'label' => 'Suche', 'action' => 'searchLink', 'url' => 'https://example.com/s?q='],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function validEngine(): array
    {
        return [
            'gesturaEngine' => 1,
            'id' => 'com.example.search',
            'version' => '1.0.0',
            'name' => 'Example Search',
            'url' => 'https://example.com/s?q=%s',
        ];
    }

    private function check(array $payload): \App\Service\ValidationResult
    {
        return $this->validator->validate(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function testAcceptsWellFormedMenu(): void
    {
        $result = $this->check($this->validMenu());
        self::assertTrue($result->ok, implode(', ', $result->errors));
        self::assertSame('menu', $result->type);
        self::assertSame('com.example.shop', $result->payload['id']);
    }

    public function testAcceptsWellFormedEngine(): void
    {
        $result = $this->check($this->validEngine());
        self::assertTrue($result->ok, implode(', ', $result->errors));
        self::assertSame('engine', $result->type);
    }

    public function testRejectsUnknownFormat(): void
    {
        $result = $this->check(['foo' => 'bar']);
        self::assertFalse($result->ok);
        self::assertContains('notGesturaFormat', $result->errors);
    }

    public function testRejectsInvalidJson(): void
    {
        $result = $this->validator->validate('{kaputt');
        self::assertFalse($result->ok);
        self::assertContains('invalidJson', $result->errors);
    }

    public function testRejectsJavascriptUrl(): void
    {
        $menu = $this->validMenu();
        $menu['items'][0]['customUrl'] = 'javascript:alert(1)';
        self::assertFalse($this->check($menu)->ok);
    }

    public function testRejectsHttpUrl(): void
    {
        $engine = $this->validEngine();
        $engine['url'] = 'http://example.com/s?q=%s';
        self::assertFalse($this->check($engine)->ok);
    }

    /**
     * Der autoritative JS-Validator (menu-exchange.js) prüft die Engine-URL
     * mit new URL() + Protokoll — ein reines Schema-`pattern: ^https://`
     * akzeptiert dagegen host-lose Schrott-URLs. Das PHP-Backend MUSS identisch
     * validieren (nicht verhandelbares Prinzip), sonst driften Client und Server.
     */
    #[DataProvider('provideSyntacticallyInvalidHttpsUrls')]
    public function testRejectsEngineUrlThatIsNotAValidHttpsUrl(string $url): void
    {
        $engine = $this->validEngine();
        $engine['url'] = $url;
        $result = $this->check($engine);
        self::assertFalse($result->ok, 'Engine-URL »' . $url . '« hätte abgelehnt werden müssen');
        self::assertContains('url', $result->errors);
    }

    /**
     * Menü-`homepage` ist optional, muss aber – wenn gesetzt – eine gültige
     * HTTPS-URL sein (JS-Validator, validateMenu).
     */
    #[DataProvider('provideSyntacticallyInvalidHttpsUrls')]
    public function testRejectsMenuHomepageThatIsNotAValidHttpsUrl(string $url): void
    {
        $menu = $this->validMenu();
        $menu['homepage'] = $url;
        $result = $this->check($menu);
        self::assertFalse($result->ok, 'Menü-homepage »' . $url . '« hätte abgelehnt werden müssen');
        self::assertContains('homepage', $result->errors);
    }

    public function testAcceptsMenuWithValidHomepage(): void
    {
        $menu = $this->validMenu();
        $menu['homepage'] = 'https://example.com/';
        $result = $this->check($menu);
        self::assertTrue($result->ok, implode(', ', $result->errors));
    }

    /** @return iterable<string, array{string}> */
    public static function provideSyntacticallyInvalidHttpsUrls(): iterable
    {
        yield 'nur Schema ohne Host' => ['https://'];
        yield 'Leerzeichen im Host' => ['https:// not a url'];
    }

    public function testRejectsDuplicateItemIds(): void
    {
        $menu = $this->validMenu();
        $menu['items'][1] = ['id' => 'orders', 'label' => 'Doppelt', 'action' => 'newTab'];
        $result = $this->check($menu);
        self::assertFalse($result->ok);
        self::assertContains('duplicateItemId', $result->errors);
    }

    public function testRejectsDisallowedAction(): void
    {
        $menu = $this->validMenu();
        $menu['items'][0]['action'] = 'executeScript';
        self::assertFalse($this->check($menu)->ok);
    }

    public function testRejectsNonSeparatorItemWithoutAction(): void
    {
        $menu = $this->validMenu();
        unset($menu['items'][0]['action']);
        $result = $this->check($menu);
        self::assertFalse($result->ok);
        self::assertContains('itemAction', $result->errors);
    }

    public function testRejectsSearchLinkWithoutEngineIdOrUrl(): void
    {
        $menu = $this->validMenu();
        $menu['items'][2] = ['id' => 'search', 'label' => 'Suche', 'action' => 'searchLink'];
        $result = $this->check($menu);
        self::assertFalse($result->ok);
        self::assertContains('itemSearch', $result->errors);
    }

    public function testRejectsBadSemver(): void
    {
        $menu = $this->validMenu();
        $menu['version'] = '1.0.0.0';
        $result = $this->check($menu);
        self::assertFalse($result->ok);
        // Typspezifische Validierung: kein Cross-Typ-Rauschen des jeweils
        // anderen Formats (z.B. "gesturaEngine fehlt") in der öffentlichen
        // Fehlerliste eines erkannten Menüs.
        self::assertNotContains('schema:/', $result->errors);

        $menu['version'] = '123456.0.0'; // SemVer-Overflow (>5 Stellen)
        self::assertFalse($this->check($menu)->ok);
    }

    public function testRejectsTooManyItems(): void
    {
        $menu = $this->validMenu();
        $menu['items'] = [];
        for ($i = 0; $i < 101; ++$i) {
            $menu['items'][] = ['id' => 'item' . $i, 'label' => 'X', 'action' => 'newTab'];
        }
        self::assertFalse($this->check($menu)->ok);
    }

    public function testRejectsOversizedBlob(): void
    {
        $menu = $this->validMenu();
        $menu['description'] = ['en' => str_repeat('x', 1500)];
        $json = json_encode($menu, JSON_THROW_ON_ERROR);
        // Riesen-JSON: über blobMax (102400 Bytes) aufpumpen
        $huge = substr_replace($json, str_repeat(' ', 103000), -1, 0);
        $result = $this->validator->validate($huge);
        self::assertFalse($result->ok);
        self::assertContains('tooLarge', $result->errors);
    }

    public function testRejectsOversizedTransformCode(): void
    {
        $engine = $this->validEngine();
        $engine['transformEnabled'] = true;
        $engine['transformCode'] = str_repeat('x', 10241);
        self::assertFalse($this->check($engine)->ok);
    }

    public function testRejectsUnsafeItemIdCharset(): void
    {
        $menu = $this->validMenu();
        $menu['items'][0]['id'] = '<script>';
        self::assertFalse($this->check($menu)->ok);
    }

    public function testConstructorThrowsWhenSchemaFileIsUnreadable(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exchange-schema.json nicht lesbar');
        // file_get_contents() selbst löst bei fehlendem Pfad zusätzlich eine
        // native PHP-Warning aus (von PHPUnit als Fehler gewertet) – hier
        // bewusst unterdrückt, da nur das RuntimeException-Verhalten geprüft wird.
        @new ExchangeValidator('/nicht/existierender/pfad/exchange-schema.json');
    }

    /**
     * detectType() erkennt den Typ nur an Sentinel-Properties eines JSON-
     * OBJEKTS. Ein syntaktisch gültiges JSON-Top-Level, das kein Objekt ist
     * (hier: eine Liste), muss ebenfalls "notGesturaFormat" ergeben statt
     * z.B. eine PHP-Warnung/TypeError auszulösen.
     */
    public function testRejectsNonObjectTopLevelJson(): void
    {
        $result = $this->validator->validate('[1,2,3]');
        self::assertFalse($result->ok);
        self::assertContains('notGesturaFormat', $result->errors);
    }

    /**
     * applyMenuRules() muss beim Fehlen des items-Feldes früh zurückkehren,
     * statt über eine Nicht-Liste zu iterieren – die Struktur wird bereits
     * vom JSON-Schema bemängelt, applyMenuRules() darf hier nicht crashen.
     */
    public function testMenuRulesReturnEarlyWhenItemsFieldIsMissing(): void
    {
        $menu = $this->validMenu();
        unset($menu['items']);
        $result = $this->check($menu);
        self::assertFalse($result->ok);
    }

    /**
     * Items ohne string-wertige id (Struktur bereits vom Schema bemängelt)
     * müssen von applyMenuRules() übersprungen werden (kein Duplicate-/
     * Action-Check für sie), statt eine Warnung auszulösen.
     */
    public function testMenuRulesSkipItemsWithoutStringId(): void
    {
        $menu = $this->validMenu();
        $menu['items'][] = ['label' => 'Ohne id', 'action' => 'newTab'];
        $result = $this->check($menu);
        self::assertFalse($result->ok);
    }
}
