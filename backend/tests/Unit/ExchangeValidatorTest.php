<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\ExchangeValidator;
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
        self::assertFalse($this->check($menu)->ok);

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
}
