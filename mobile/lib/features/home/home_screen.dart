import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:vpn_mobile/core/app_config.dart';
import 'package:vpn_mobile/core/models/vpn_provision_result.dart';
import 'package:vpn_mobile/core/wireguard_key_service.dart';
import 'package:vpn_mobile/features/account/account_screen.dart';
import 'package:vpn_mobile/features/locations/locations_screen.dart';
import 'package:vpn_mobile/features/settings/settings_screen.dart';
import 'package:vpn_mobile/state/app_auth_state.dart';

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  static const routeName = '/home';

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  int _tab = 0;

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AppAuthState>();
    final account = auth.account;

    final pages = [
      _HomeBody(
        planName: account?.planName ?? 'Loading…',
        expiresAt: account?.expiresAt,
        status: account?.status ?? 'UNKNOWN',
      ),
      const LocationsScreen(),
      const AccountScreen(),
      const SettingsScreen(),
    ];

    return Scaffold(
      body: pages[_tab],
      bottomNavigationBar: NavigationBar(
        selectedIndex: _tab,
        onDestinationSelected: (i) => setState(() => _tab = i),
        destinations: const [
          NavigationDestination(icon: Icon(Icons.shield_outlined), label: 'Home'),
          NavigationDestination(icon: Icon(Icons.public), label: 'Locations'),
          NavigationDestination(icon: Icon(Icons.person_outline), label: 'Account'),
          NavigationDestination(icon: Icon(Icons.settings_outlined), label: 'Settings'),
        ],
      ),
    );
  }
}

class _HomeBody extends StatefulWidget {
  const _HomeBody({
    required this.planName,
    required this.expiresAt,
    required this.status,
  });

  final String planName;
  final DateTime? expiresAt;
  final String status;

  @override
  State<_HomeBody> createState() => _HomeBodyState();
}

class _HomeBodyState extends State<_HomeBody> {
  final _keyService = WireguardKeyService();
  VpnProvisionResult? _provisionResult;
  bool _provisioning = false;
  String? _errorMessage;

  String get _expiryLabel {
    if (widget.expiresAt == null) return 'No expiry date';
    final local = widget.expiresAt!.toLocal();
    return '${local.day.toString().padLeft(2, '0')} '
        '${_monthName(local.month)} ${local.year}';
  }

  static String _monthName(int month) {
    const names = [
      'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
      'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
    ];
    return names[month - 1];
  }

  Future<void> _prepareVpn() async {
    setState(() {
      _provisioning = true;
      _errorMessage = null;
    });

    try {
      final auth = context.read<AppAuthState>();
      final clientPublicKey = await _keyService.getOrCreatePublicKey();
      final result = await auth.apiClient.provisionVpn(
        clientPublicKey: clientPublicKey,
      );

      if (mounted) {
        setState(() {
          _provisionResult = result;
          _provisioning = false;
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          _errorMessage = 'Provisioning failed: $e';
          _provisioning = false;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final result = _provisionResult;

    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          children: [
            const SizedBox(height: 24),
            Text(
              result != null
                  ? 'VPN Configuration Ready'
                  : 'VPN Access Ready',
              style: Theme.of(context).textTheme.headlineSmall,
            ),
            const SizedBox(height: 24),
            Icon(
              result != null
                  ? Icons.vpn_key_outlined
                  : Icons.verified_user_outlined,
              size: 80,
              color: Theme.of(context).colorScheme.primary,
            ),
            const SizedBox(height: 12),
            Text('${widget.planName} · ${widget.status}'),
            const SizedBox(height: 8),
            Text(
              'Expires $_expiryLabel',
              style: Theme.of(context).textTheme.bodyMedium,
            ),
            if (_errorMessage != null) ...[
              const SizedBox(height: 12),
              Text(
                _errorMessage!,
                style: const TextStyle(color: Colors.red),
                textAlign: TextAlign.center,
              ),
            ],
            const SizedBox(height: 24),
            if (result == null)
              FilledButton.icon(
                onPressed: _provisioning ? null : _prepareVpn,
                icon: _provisioning
                    ? const SizedBox(
                        width: 16,
                        height: 16,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Icon(Icons.tune),
                label: Text(_provisioning
                    ? 'Preparing Configuration…'
                    : 'Prepare VPN Configuration'),
              )
            else ...[
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        result.server.location,
                        style: Theme.of(context).textTheme.titleMedium,
                      ),
                      const SizedBox(height: 4),
                      Text(
                        'Server: ${result.server.name} (${result.server.endpoint})',
                        style: Theme.of(context).textTheme.bodyMedium,
                      ),
                      const SizedBox(height: 4),
                      Text(
                        'Assigned IP: ${result.address}',
                        style: Theme.of(context).textTheme.bodyMedium,
                      ),
                      const SizedBox(height: 8),
                      Text(
                        'Your VPN access is prepared.',
                        style: TextStyle(
                          color: Theme.of(context).colorScheme.primary,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 12),
              const Text(
                'System tunnel connection arriving in Phase 4',
                style: TextStyle(fontSize: 12, color: Colors.grey),
              ),
            ],
            const Spacer(),
            Text(
              'App v${AppConfig.appVersion}',
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ],
        ),
      ),
    );
  }
}
