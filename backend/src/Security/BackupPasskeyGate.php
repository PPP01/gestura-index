<?php
declare(strict_types=1);
namespace App\Security;

use App\Entity\AdminUser;
use App\Exception\ApiProblem;

final class BackupPasskeyGate
{
    public function assertEnough(AdminUser $u): void
    {
        if ($u->credentialCount() < 2) {
            throw new ApiProblem(409, 'Backup passkey required', ['backupRequired' => true]);
        }
    }
}
