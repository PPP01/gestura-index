<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Functional\ApiTestCase;

final class SubmissionTokenGuardTest extends ApiTestCase
{
    public function testSubmissionContainingAccountTokenIsRejected(): void
    {
        // gültiges Menü-Payload, aber ein gacc_-Token versehentlich im Namen:
        $bogusToken = 'gacc_' . str_repeat('a', 16) . '_' . str_repeat('b', 43);

        $this->api('POST', '/api/v1/entries', [
            'payload' => $this->menuPayload(['name' => ['en' => 'My ' . $bogusToken . ' menu']]),
            'categories' => ['shopping', 'other'],
        ]);

        self::assertResponseStatusCodeSame(400);
    }
}
