import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:vpn_mobile/core/app_config.dart';
import 'package:vpn_mobile/features/account/account_screen.dart';
import 'package:vpn_mobile/features/locations/locations_screen.dart';
import 'package:vpn_mobile/features/settings/settings_screen.dart';
import 'package:vpn_mobile/state/vpn_connection_state.dart';
import 'package:vpn_mobile/state/vpn_manager.dart';
import 'package:vpn_mobile/widgets/connection_control.dart';
import 'package:vpn_mobile/widgets/connection_timer.dart';

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
    final pages = [
      const _HomeBody(),
      const LocationsScreen(embedded: true),
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

class _HomeBody extends StatelessWidget {
  const _HomeBody();

  @override
  Widget build(BuildContext context) {
    final vpn = context.watch<VpnManager>();
    final theme = Theme.of(context);
    final protected = vpn.isProtected;
    final busy = vpn.isBusy;

    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const SizedBox(height: 8),
            Text(
              'VPN',
              style: theme.textTheme.titleLarge?.copyWith(
                fontWeight: FontWeight.w600,
              ),
            ),
            const Spacer(flex: 2),
            Text(
              _statusHeadline(vpn.state),
              textAlign: TextAlign.center,
              style: theme.textTheme.headlineSmall?.copyWith(
                fontWeight: FontWeight.w700,
                color: protected
                    ? theme.colorScheme.primary
                    : theme.colorScheme.onSurfaceVariant,
              ),
            ),
            const SizedBox(height: 32),
            Center(
              child: ConnectionControl(
                state: vpn.state,
                onConnect: busy ? null : () => vpn.connect(),
                onDisconnect: busy ? null : () => vpn.disconnect(),
              ),
            ),
            const SizedBox(height: 32),
            if (vpn.userMessage != null) ...[
              Text(
                vpn.userMessage!,
                textAlign: TextAlign.center,
                style: TextStyle(color: theme.colorScheme.error),
              ),
              const SizedBox(height: 16),
            ],
            FilledButton(
              onPressed: busy
                  ? null
                  : protected
                      ? () => vpn.disconnect()
                      : () => vpn.connect(),
              style: FilledButton.styleFrom(
                minimumSize: const Size.fromHeight(52),
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(16),
                ),
              ),
              child: Text(_primaryButtonLabel(vpn.state)),
            ),
            const SizedBox(height: 24),
            _LocationCard(
              smartLocation: vpn.preferences?.smartLocation ?? true,
              label: vpn.locationLabel,
              protocolLabel: vpn.selectedProtocol.label,
              onTap: () => _openLocations(context),
            ),
            if (protected) ...[
              const SizedBox(height: 16),
              ConnectionTimer(
                connectedSinceEpochMs: vpn.statistics.connectedSinceEpochMs,
              ),
            ],
            const Spacer(flex: 3),
            Text(
              'App v${AppConfig.appVersion}',
              textAlign: TextAlign.center,
              style: theme.textTheme.bodySmall,
            ),
          ],
        ),
      ),
    );
  }

  String _statusHeadline(VpnConnectionState state) {
    switch (state) {
      case VpnConnectionState.connected:
        return 'PROTECTED';
      case VpnConnectionState.reconnecting:
        return 'RECONNECTING…';
      case VpnConnectionState.connecting:
      case VpnConnectionState.preparing:
      case VpnConnectionState.authorizing:
      case VpnConnectionState.provisioning:
      case VpnConnectionState.requestingPermission:
        return 'CONNECTING…';
      case VpnConnectionState.disconnecting:
        return 'DISCONNECTING…';
      case VpnConnectionState.error:
        return 'NOT PROTECTED';
      case VpnConnectionState.disconnected:
        return 'NOT PROTECTED';
    }
  }

  String _primaryButtonLabel(VpnConnectionState state) {
    if (state.isConnectedLike) return 'DISCONNECT';
    if (state.isBusy) return 'CONNECTING…';
    return 'CONNECT';
  }

  void _openLocations(BuildContext context) {
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(content: Text('Choose a location in the Locations tab')),
    );
  }
}

class _LocationCard extends StatelessWidget {
  const _LocationCard({
    required this.smartLocation,
    required this.label,
    required this.protocolLabel,
    required this.onTap,
  });

  final bool smartLocation;
  final String label;
  final String protocolLabel;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Material(
      color: theme.colorScheme.surfaceContainerHighest.withValues(alpha: 0.5),
      borderRadius: BorderRadius.circular(16),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(16),
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Row(
            children: [
              Icon(Icons.public, color: theme.colorScheme.primary),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      smartLocation ? 'Smart Location' : 'Selected Location',
                      style: theme.textTheme.labelMedium?.copyWith(
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
                    ),
                    Text(
                      label,
                      style: theme.textTheme.titleMedium,
                    ),
                    Text(
                      protocolLabel,
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
                    ),
                  ],
                ),
              ),
              const Icon(Icons.chevron_right),
            ],
          ),
        ),
      ),
    );
  }
}
