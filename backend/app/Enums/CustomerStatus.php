<?php

namespace App\Enums;

enum CustomerStatus: string
{
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
    case Blocked = 'BLOCKED';
    case Closed = 'CLOSED';
}
