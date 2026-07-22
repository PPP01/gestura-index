<?php
declare(strict_types=1);
namespace App\Enum;

enum AdminRole: string
{
    case Admin = 'admin';
    case Moderator = 'moderator';
}
