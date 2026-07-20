<?php

declare(strict_types=1);

namespace App\Service;

final readonly class GeneratedToken
{
    public function __construct(
        public string $token,
        public string $selector,
        public string $hash,
    ) {
    }
}
