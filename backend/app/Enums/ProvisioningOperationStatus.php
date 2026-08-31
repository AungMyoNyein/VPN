<?php

namespace App\Enums;

enum ProvisioningOperationStatus: string
{
    case Pending = 'PENDING';
    case Running = 'RUNNING';
    case Succeeded = 'SUCCEEDED';
    case Failed = 'FAILED';
}
