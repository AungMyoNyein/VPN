<?php

namespace App\Enums;

enum IpAllocationStatus: string
{
    case Allocated = 'ALLOCATED';
    case Released = 'RELEASED';
}
