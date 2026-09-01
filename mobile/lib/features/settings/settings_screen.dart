import 'package:flutter/material.dart';
import 'dart:io' show Platform;

import 'package:provider/provider.dart';
import 'package:vpn_mobile/core/native_vpn_service.dart';
import 'package:vpn_mobile/core/vpn_preferences.dart';
import 'package:vpn_mobile/features/auth/activation_screen.dart';
import 'package:vpn_mobile/features/settings/diagnostics_screen.dart';
import 'package:vpn_mobile/state/app_auth_state.dart';
import 'package:vpn_mobile/core/models/vpn_protocol.dart';
import 'package:vpn_mobile/state/vpn_manager.dart';

class SettingsScreen extends StatefulWidget {
  const SettingsScreen({super.key});

  static const routeName = '/settings';

  @override
  State<SettingsScreen> createState() => _SettingsScreenState();
}

class _SettingsScreenState extends State<SettingsScreen> {
  VpnPreferences? _prefs;

  @override
  void initState() {
    super.initState();
    VpnPreferences.load().then((p) {
      if (mounted) setState(() => _prefs = p);
    });
  }

  Future<void> _confirmDeactivate() async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Deactivate this device?'),
        content: const Text(
          'You will need your Customer ID and Activation Key to use this device again.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Deactivate'),
          ),
        ],
      ),
    );

    if (confirmed != true || !mounted) return;

    final auth = context.read<AppAuthState>();
    final vpn = context.read<VpnManager>();
    await vpn.disconnect();
    await auth.deactivateCurrentDevice();
    if (!mounted) return;

    Navigator.of(context).pushNamedAndRemoveUntil(
      ActivationScreen.routeName,
      (route) => false,
    );
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AppAuthState>();
    final vpn = context.watch<VpnManager>();
    final prefs = _prefs ?? vpn.preferences;
    final deactivating = auth.status == AppAuthStatus.authenticating;

    return Scaffold(
      appBar: AppBar(title: const Text('Settings')),
      body: prefs == null
          ? const Center(child: CircularProgressIndicator())
          : ListView(
              children: [
                SwitchListTile(
                  title: const Text('Auto Connect'),
                  subtitle: const Text('Reconnect after device reboot when enabled'),
                  value: prefs.autoConnect,
                  onChanged: (v) async {
                    setState(() => prefs.autoConnect = v);
                    if (Platform.isAndroid) {
                      final native = NativeVpnService();
                      await native.setPreference('auto_connect_enabled', v);
                    }
                  },
                ),
                SwitchListTile(
                  title: const Text('Connect on App Launch'),
                  value: prefs.connectOnLaunch,
                  onChanged: (v) => setState(() => prefs.connectOnLaunch = v),
                ),
                // RFC1918 bypass not fully wired in native tunnel — hidden until Phase 5+.
                ListTile(
                  title: const Text('Protocol'),
                  subtitle: Text(vpn.selectedProtocol.value),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => _pickProtocol(vpn),
                ),
                ListTile(
                  title: const Text('Kill Switch / Always-on VPN'),
                  subtitle: const Text(
                    'Configure in Android Settings → Network → VPN → gear icon',
                  ),
                  trailing: const Icon(Icons.open_in_new),
                  onTap: () {
                    showDialog<void>(
                      context: context,
                      builder: (context) => AlertDialog(
                        title: const Text('Always-on VPN'),
                        content: const Text(
                          'Open Android system VPN settings for this app to enable '
                          'Always-on VPN and "Block connections without VPN". '
                          'These are controlled by the operating system.',
                        ),
                        actions: [
                          TextButton(
                            onPressed: () => Navigator.pop(context),
                            child: const Text('OK'),
                          ),
                        ],
                      ),
                    );
                  },
                ),
                ListTile(
                  title: const Text('Theme'),
                  subtitle: Text(prefs.themeMode),
                  onTap: () => _pickTheme(prefs),
                ),
                ListTile(
                  title: const Text('Diagnostics'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => Navigator.of(context).push(
                    MaterialPageRoute<void>(
                      builder: (_) => const DiagnosticsScreen(),
                    ),
                  ),
                ),
                ListTile(
                  title: const Text('Deactivate this device'),
                  subtitle: const Text('Remove access from this phone or tablet'),
                  trailing: deactivating
                      ? const SizedBox(
                          width: 24,
                          height: 24,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : const Icon(Icons.logout),
                  onTap: deactivating ? null : _confirmDeactivate,
                ),
                const ListTile(
                  title: Text('About'),
                  subtitle: Text('Phase 5 — Android WireGuard VPN'),
                ),
              ],
            ),
    );
  }

  Future<void> _pickProtocol(VpnManager vpn) async {
    final choice = await showModalBottomSheet<VpnProtocol>(
      context: context,
      builder: (context) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ListTile(
              title: const Text('WireGuard'),
              subtitle: const Text('Native Android tunnel (recommended)'),
              onTap: () => Navigator.pop(context, VpnProtocol.wireguard),
            ),
            ListTile(
              title: const Text('VLESS'),
              subtitle: const Text('Provision profile + share URL (native tunnel pending)'),
              onTap: () => Navigator.pop(context, VpnProtocol.vless),
            ),
          ],
        ),
      ),
    );
    if (choice != null) {
      vpn.setProtocol(choice);
      if (mounted) setState(() {});
    }
  }

  Future<void> _pickTheme(VpnPreferences prefs) async {
    final choice = await showModalBottomSheet<String>(
      context: context,
      builder: (context) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ListTile(
              title: const Text('System'),
              onTap: () => Navigator.pop(context, 'system'),
            ),
            ListTile(
              title: const Text('Light'),
              onTap: () => Navigator.pop(context, 'light'),
            ),
            ListTile(
              title: const Text('Dark'),
              onTap: () => Navigator.pop(context, 'dark'),
            ),
          ],
        ),
      ),
    );
    if (choice != null) setState(() => prefs.themeMode = choice);
  }
}
