<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Service\WebAuthn\WebAuthnCeremony;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class StepUpOptionsController
{
    #[Route('/api/admin/stepup/options', methods: ['POST'])]
    public function __invoke(Security $security, WebAuthnCeremony $ceremony): JsonResponse
    {
        return JsonResponse::fromJsonString($ceremony->requestOptionsJson($security->getUser()));
    }
}
