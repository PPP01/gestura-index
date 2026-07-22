<?php
declare(strict_types=1);
namespace App\Enum;

enum AdminUserStatus: string
{
    case Invited = 'invited';
    case Active = 'active';
    case Disabled = 'disabled';
}
