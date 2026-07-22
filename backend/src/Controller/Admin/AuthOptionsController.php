<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Service\WebAuthn\WebAuthnCeremony;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class AuthOptionsController
{
    #[Route('/api/admin/auth/options', methods: ['POST'])]
    public function __invoke(WebAuthnCeremony $ceremony): JsonResponse
    {
        // Usernameless/discoverable Login: keine User-Bindung nötig
        return JsonResponse::fromJsonString($ceremony->requestOptionsJson(null));
    }
}
