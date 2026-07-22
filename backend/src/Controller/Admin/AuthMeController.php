<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Entity\AdminUser;
use App\Service\AdminSession;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class AuthMeController
{
    #[Route('/api/admin/auth/me', methods: ['GET'])]
    public function __invoke(Security $security, AdminSession $session): JsonResponse
    {
        /** @var AdminUser $user */
        $user = $security->getUser();
        return new JsonResponse([
            'email' => $user->email,
            'displayName' => $user->displayName,
            'role' => $user->role->value,
            'credentialCount' => $user->credentialCount(),
            'stepUpFresh' => $session->isFresh(300),
        ]);
    }
}
