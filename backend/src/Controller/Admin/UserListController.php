<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\AdminUserRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Listet alle Admin-Nutzer (unabhängig vom Status) für die Nutzerverwaltung.
 */
final class UserListController
{
    #[Route('/api/admin/users', methods: ['GET'])]
    public function __invoke(AdminUserRepository $users): JsonResponse
    {
        $out = [];
        foreach ($users->findBy([], ['createdAt' => 'ASC']) as $user) {
            $out[] = [
                'id' => $user->id,
                'displayName' => $user->displayName,
                'email' => $user->email,
                'role' => $user->role->value,
                'status' => $user->status->value,
                'createdAt' => $user->createdAt->format(\DateTimeInterface::ATOM),
                'lastLoginAt' => $user->lastLoginAt?->format(\DateTimeInterface::ATOM),
            ];
        }

        return new JsonResponse(['users' => $out]);
    }
}
