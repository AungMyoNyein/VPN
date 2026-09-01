<?php

return [
    'control_plane' => [
        'base_url' => env('CONTROL_PLANE_BASE_URL', 'http://127.0.0.1:8081'),
        'service_token' => env('CONTROL_PLANE_SERVICE_TOKEN', 'dev-only-change-me'),
        'timeout_seconds' => (int) env('CONTROL_PLANE_TIMEOUT_SECONDS', 5),
    ],

    'dns' => [
        '1.1.1.1',
        '1.0.0.1',
    ],

    'allowed_ips' => [
        '0.0.0.0/0',
    ],

    'persistent_keepalive' => 25,
    'mtu' => 1420,

  'protocols' => [
        'wireguard',
        'vless',
    ],

    'vless' => [
        'default_port' => (int) env('VPN_VLESS_PORT', 443),
        'mtu' => (int) env('VPN_VLESS_MTU', 1400),
        'security' => env('VPN_VLESS_SECURITY', 'tls'),
        'flow' => env('VPN_VLESS_FLOW') ?: null,
        'alpn' => env('VPN_VLESS_ALPN', 'h2,http/1.1'),
        'fingerprint' => env('VPN_VLESS_FINGERPRINT', 'chrome'),
    ],

    'reconciliation' => [
        'max_attempts' => (int) env('VPN_RECONCILIATION_MAX_ATTEMPTS', 5),
        'batch_size' => (int) env('VPN_RECONCILIATION_BATCH_SIZE', 50),
    ],
];
