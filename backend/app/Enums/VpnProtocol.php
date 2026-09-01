<?php

namespace App\Enums;

enum VpnProtocol: string
{
    case Wireguard = 'wireguard';
    case Vless = 'vless';

    public function peerCodePrefix(): string
    {
        return match ($this) {
            self::Wireguard => 'WG-PEER-',
            self::Vless => 'VLESS-PEER-',
        };
    }

    public function requiresIpAllocation(): bool
    {
        return $this === self::Wireguard;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
