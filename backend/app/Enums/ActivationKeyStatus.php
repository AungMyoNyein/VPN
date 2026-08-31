<?php

namespace App\Enums;

enum ActivationKeyStatus: string
{
    case Active = 'ACTIVE';
    case Used = 'USED';
    case Suspended = 'SUSPENDED';
    case Revoked = 'REVOKED';
    case Expired = 'EXPIRED';
}
