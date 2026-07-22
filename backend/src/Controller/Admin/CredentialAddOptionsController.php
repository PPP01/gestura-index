<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Entity\AdminUser;
use App\Service\WebAuthn\WebAuthnCeremony;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class CredentialAddOptionsController
{
    #[Route('/api/admin/credentials/options', methods: ['POST'])]
    public function __invoke(Security $security, WebAuthnCeremony $ceremony): JsonResponse
    {
        /** @var AdminUser $user */
        $user = $security->getUser();
        return JsonResponse::fromJsonString($ceremony->creationOptionsJson($user));
    }
}
