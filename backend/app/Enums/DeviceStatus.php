<?php

namespace App\Enums;

enum DeviceStatus: string
{
    case Active = 'ACTIVE';
    case Revoked = 'REVOKED';
    case Blocked = 'BLOCKED';
}
