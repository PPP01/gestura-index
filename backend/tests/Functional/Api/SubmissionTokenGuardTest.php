<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Functional\ApiTestCase;

final class SubmissionTokenGuardTest extends ApiTestCase
{
    public function testSubmissionContainingAccountTokenIsRejected(): void
    {
        $this->api('POST', '/api/v1/entries', [
            'payload' => $this->menuPayload(['name' => ['en' => 'My ' . $this->accountToken() . ' menu']]),
            'categories' => ['shopping', 'other'],
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    public function testSubmissionContainingAccountTokenInChangelogIsRejected(): void
    {
        $this->api('POST', '/api/v1/entries', [
            'payload' => $this->menuPayload(),
            'categories' => ['shopping', 'other'],
            'changelog' => $this->accountToken(),
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    public function testSubmissionContainingAccountTokenInSuccessorFormatIdIsRejected(): void
    {
        $this->api('POST', '/api/v1/entries', [
            'payload' => $this->menuPayload(),
            'categories' => ['shopping', 'other'],
            'successorFormatId' => $this->accountToken(),
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    private function accountToken(): string
    {
        return 'gacc_' . str_repeat('a', 16) . '_' . str_repeat('b', 43);
    }
}
