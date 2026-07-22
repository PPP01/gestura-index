<?php
declare(strict_types=1);
namespace App\Security;

use App\Entity\AdminUser;
use App\Enum\AdminUserStatus;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Prüft den Account-Status auf JEDEM authentifizierten Request (nicht nur
 * beim Login): ein nach dem Login deaktivierter Admin verliert damit sofort
 * den Zugriff, statt bis zum Session-Idle-Timeout (~30 min) authentifiziert
 * zu bleiben.
 */
final class AdminUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof AdminUser && $user->status !== AdminUserStatus::Active) {
            throw new CustomUserMessageAccountStatusException('Account is not active');
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
    }
}
