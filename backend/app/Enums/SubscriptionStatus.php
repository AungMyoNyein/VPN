<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Pending = 'PENDING';
    case Active = 'ACTIVE';
    case Expired = 'EXPIRED';
    case Suspended = 'SUSPENDED';
    case Cancelled = 'CANCELLED';
}
