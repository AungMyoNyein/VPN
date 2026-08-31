<?php

namespace App\Enums;

enum NodeHealthStatus: string
{
    case Healthy = 'HEALTHY';
    case Degraded = 'DEGRADED';
    case Down = 'DOWN';
    case Unknown = 'UNKNOWN';
}
