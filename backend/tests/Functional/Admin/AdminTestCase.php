<?php
declare(strict_types=1);
namespace App\Tests\Functional\Admin;

use App\Entity\AdminUser;
use App\Enum\AdminRole;
use App\Enum\AdminUserStatus;
use App\Tests\Functional\ApiTestCase;

abstract class AdminTestCase extends ApiTestCase
{
    protected function createAdmin(string $email = 'chef@example.com', AdminRole $role = AdminRole::Admin, AdminUserStatus $status = AdminUserStatus::Active): AdminUser
    {
        $u = new AdminUser('Chef', $email, $role);
        $u->status = $status;
        $this->em->persist($u);
        $this->em->flush();
        return $u;
    }
}
