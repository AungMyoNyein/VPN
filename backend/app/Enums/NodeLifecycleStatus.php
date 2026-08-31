<?php

namespace App\Enums;

enum NodeLifecycleStatus: string
{
    case Active = 'ACTIVE';
    case Draining = 'DRAINING';
    case Maintenance = 'MAINTENANCE';
    case Retired = 'RETIRED';
}
