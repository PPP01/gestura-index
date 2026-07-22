<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Entity\AdminUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class CredentialListController
{
    #[Route('/api/admin/credentials', methods: ['GET'])]
    public function __invoke(Security $security): JsonResponse
    {
        /** @var AdminUser $user */
        $user = $security->getUser();
        $out = [];
        foreach ($user->credentials as $c) {
            $out[] = [
                'id' => $c->id,
                'label' => $c->label,
                'createdAt' => $c->createdAt->format(\DateTimeInterface::ATOM),
                'lastUsedAt' => $c->lastUsedAt?->format(\DateTimeInterface::ATOM),
            ];
        }
        return new JsonResponse($out);
    }
}
