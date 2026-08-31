import 'package:flutter/material.dart';
import 'dart:io' show Platform;

import 'package:provider/provider.dart';
import 'package:vpn_mobile/features/auth/activation_screen.dart';
import 'package:vpn_mobile/state/app_auth_state.dart';

class SettingsScreen extends StatelessWidget {
  const SettingsScreen({super.key});

  static const routeName = '/settings';

  Future<void> _confirmDeactivate(BuildContext context) async {
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

    if (confirmed != true || !context.mounted) return;

    final auth = context.read<AppAuthState>();
    await auth.deactivateCurrentDevice();
    if (!context.mounted) return;

    Navigator.of(context).pushNamedAndRemoveUntil(
      ActivationScreen.routeName,
      (route) => false,
    );
  }

  @override
  Widget build(BuildContext context) {
    final showSplitTunnel = Platform.isAndroid;
    final auth = context.watch<AppAuthState>();
    final deactivating = auth.status == AppAuthStatus.authenticating;

    return Scaffold(
      appBar: AppBar(title: const Text('Settings')),
      body: ListView(
        children: [
          const SwitchListTile(
            title: Text('Auto Connect'),
            value: false,
            onChanged: null,
          ),
          const SwitchListTile(
            title: Text('Connect on untrusted Wi-Fi'),
            value: false,
            onChanged: null,
          ),
          const ListTile(
            title: Text('Protocol'),
            subtitle: Text('WireGuard'),
          ),
          const SwitchListTile(
            title: Text('Kill Switch'),
            subtitle: Text('Not claimed until platform-tested'),
            value: false,
            onChanged: null,
          ),
          const ListTile(title: Text('DNS options')),
          if (showSplitTunnel)
            const SwitchListTile(
              title: Text('Split tunneling'),
              subtitle: Text('Android only'),
              value: false,
              onChanged: null,
            ),
          const ListTile(title: Text('Diagnostics')),
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
            onTap: deactivating ? null : () => _confirmDeactivate(context),
          ),
          const ListTile(
            title: Text('About'),
            subtitle: Text('Phase 2 — activation & device auth'),
          ),
        ],
      ),
    );
  }
}
