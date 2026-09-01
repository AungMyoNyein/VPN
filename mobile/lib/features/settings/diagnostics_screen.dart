import 'dart:io' show Platform;

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import 'package:vpn_mobile/core/app_config.dart';
import 'package:vpn_mobile/core/models/vpn_protocol.dart';
import 'package:vpn_mobile/state/app_auth_state.dart';
import 'package:vpn_mobile/state/vpn_manager.dart';

class DiagnosticsScreen extends StatefulWidget {
  const DiagnosticsScreen({super.key});

  @override
  State<DiagnosticsScreen> createState() => _DiagnosticsScreenState();
}

class _DiagnosticsScreenState extends State<DiagnosticsScreen> {
  Map<String, dynamic>? _vpnStatus;
  String? _loadError;

  @override
  void initState() {
    super.initState();
    _loadStatus();
  }

  Future<void> _loadStatus() async {
    try {
      final vpn = context.read<VpnManager>();
      final status = await vpn.fetchVpnStatus();
      if (mounted) setState(() => _vpnStatus = status);
    } catch (e) {
      if (mounted) setState(() => _loadError = 'Could not load VPN status');
    }
  }

  Future<void> _exportDiagnostics() async {
    final vpn = context.read<VpnManager>();
    final auth = context.read<AppAuthState>();
    final stats = vpn.statistics;
    final provision = vpn.lastProvision;

    final lines = <String>[
      'VPN Diagnostics Export',
      'App Version: ${AppConfig.appVersion}',
      'Platform: ${Platform.operatingSystem} ${Platform.operatingSystemVersion}',
      'Connection State: ${vpn.state.name}',
      'Protocol: ${vpn.selectedProtocol.label}',
      'Location: ${vpn.locationLabel}',
      'Subscription: ${auth.account?.status ?? 'unknown'}',
      'Tunnel RX: ${stats.rxBytes} bytes',
      'Tunnel TX: ${stats.txBytes} bytes',
      'Connected Since: ${stats.connectedSinceEpochMs}',
      if (provision != null) 'Peer ID: ${provision.peerId}',
      if (provision != null) 'Endpoint: ${provision.server.endpoint}',
      if (provision?.isVless ?? false)
        'UUID: ${provision!.vless?.redactedUuid ?? provision.uuid}',
      if (vpn.errorCode != null) 'Error Code: ${vpn.errorCode}',
    ];

    await Clipboard.setData(ClipboardData(text: lines.join('\n')));
    if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Diagnostics copied (secrets redacted)')),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final vpn = context.watch<VpnManager>();
    final auth = context.watch<AppAuthState>();
    final stats = vpn.statistics;
    final peer = _vpnStatus?['peer'] as Map<String, dynamic>?;
    final provision = vpn.lastProvision;
    final protocol = vpn.selectedProtocol;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Diagnostics'),
        actions: [
          IconButton(
            icon: const Icon(Icons.copy),
            tooltip: 'Export',
            onPressed: _exportDiagnostics,
          ),
        ],
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          _Section(
            title: 'Device Authorization',
            rows: [
              _row('Status', auth.isReady ? 'Authorized' : 'Not authorized'),
              _row('Customer', auth.account?.customerId ?? '—'),
            ],
          ),
          _Section(
            title: 'Subscription',
            rows: [
              _row('Plan', auth.account?.planName ?? '—'),
              _row('Status', auth.account?.status ?? '—'),
            ],
          ),
          _Section(
            title: 'Tunnel',
            rows: [
              _row('Protocol', protocol.label),
              _row('State', vpn.state.name),
              _row('Selected Location', vpn.locationLabel),
              _row(
                'Tunnel',
                vpn.isProtected ? 'Active' : 'Inactive',
              ),
              _row('RX Bytes', '${stats.rxBytes}'),
              _row('TX Bytes', '${stats.txBytes}'),
            ],
          ),
          _Section(
            title: 'Provisioning',
            rows: [
              _row('Active Peer', peer?['peer_code']?.toString() ?? '—'),
              _row('Peer Status', peer?['status']?.toString() ?? '—'),
              _row('Server', provision?.server.endpoint ?? '—'),
              if (provision?.isVless ?? false) ...[
                _row('TLS', provision?.server.security?.toUpperCase() ?? 'Enabled'),
                _row(
                  'UUID',
                  provision?.vless?.redactedUuid ??
                      _redactUuid(provision?.uuid),
                ),
              ],
              if (provision?.isWireguard ?? false)
                _row(
                  'Assigned IP',
                  provision?.address ?? peer?['assigned_ip']?.toString() ?? '—',
                ),
            ],
          ),
          _Section(
            title: 'App',
            rows: [
              _row('Version', AppConfig.appVersion),
              _row(
                'Engine',
                Platform.isAndroid
                    ? (protocol == VpnProtocol.vless
                        ? 'sing-box libbox 1.11.4'
                        : 'WireGuard GoBackend')
                    : 'N/A',
              ),
              if (_loadError != null) _row('Status API', _loadError!),
              if (vpn.errorCode != null) _row('Last Error', vpn.errorCode!),
            ],
          ),
          if (kDebugMode && (provision?.shareUrl.isNotEmpty ?? false))
            _Section(
              title: 'Developer',
              rows: [
                _row('Share URL', _redactShareUrl(provision!.shareUrl)),
              ],
            ),
        ],
      ),
    );
  }

  String _redactUuid(String? uuid) {
    if (uuid == null || uuid.isEmpty) return '—';
    if (uuid.length < 4) return '********';
    return '********-****-****-****-********${uuid.substring(uuid.length - 4)}';
  }

  String _redactShareUrl(String url) {
    if (url.length <= 24) return '[REDACTED]';
    return '${url.substring(0, 12)}…[REDACTED]';
  }

  MapEntry<String, String> _row(String k, String v) => MapEntry(k, v);
}

class _Section extends StatelessWidget {
  const _Section({required this.title, required this.rows});

  final String title;
  final List<MapEntry<String, String>> rows;

  @override
  Widget build(BuildContext context) {
    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(title, style: Theme.of(context).textTheme.titleMedium),
            const SizedBox(height: 8),
            ...rows.map(
              (e) => Padding(
                padding: const EdgeInsets.symmetric(vertical: 4),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    SizedBox(
                      width: 140,
                      child: Text(
                        e.key,
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                    ),
                    Expanded(child: Text(e.value)),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
