<?php

namespace App\Enums;

enum PeerStatus: string
{
    case Pending = 'PENDING';
    case Active = 'ACTIVE';
    case Error = 'ERROR';
    case Revoking = 'REVOKING';
    case Revoked = 'REVOKED';

    public function isActiveState(): bool
    {
        return in_array($this, [self::Pending, self::Active, self::Revoking], true);
    }
}
