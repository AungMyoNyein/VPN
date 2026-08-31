<?php

namespace App\Enums;

enum EntitlementState: string
{
    case Active = 'ACTIVE';
    case ExpiringSoon = 'EXPIRING_SOON';
    case Expired = 'EXPIRED';
    case Suspended = 'SUSPENDED';
    case NotStarted = 'NOT_STARTED';
    case None = 'NONE';
}
