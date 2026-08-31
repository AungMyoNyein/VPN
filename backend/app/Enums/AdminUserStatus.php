<?php

namespace App\Enums;

enum AdminUserStatus: string
{
    case Active = 'ACTIVE';
    case Disabled = 'DISABLED';
}
