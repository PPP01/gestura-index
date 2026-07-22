<?php
declare(strict_types=1);
namespace App\Security;

use App\Exception\ApiProblem;
use App\Service\AdminSession;

final class StepUpGuard
{
    private const MAX_AGE = 300;

    public function __construct(private readonly AdminSession $session) {}

    public function assertFresh(): void
    {
        if (!$this->session->isFresh(self::MAX_AGE)) {
            throw new ApiProblem(403, 'Step-up required', ['stepUpRequired' => true]);
        }
    }
}
