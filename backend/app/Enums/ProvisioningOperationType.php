<?php

namespace App\Enums;

enum ProvisioningOperationType: string
{
    case Provision = 'PROVISION';
    case Revoke = 'REVOKE';
}
