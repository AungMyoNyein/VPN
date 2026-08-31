import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:vpn_mobile/core/app_config.dart';
import 'package:vpn_mobile/state/app_auth_state.dart';

class AccountScreen extends StatelessWidget {
  const AccountScreen({super.key});

  static const routeName = '/account';

  String _formatDate(DateTime? value) {
    if (value == null) return '—';
    final local = value.toLocal();
    return '${local.day.toString().padLeft(2, '0')}/'
        '${local.month.toString().padLeft(2, '0')}/'
        '${local.year}';
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AppAuthState>();
    final account = auth.account;

    return Scaffold(
      appBar: AppBar(title: const Text('Account')),
      body: ListView(
        children: [
          ListTile(
            title: const Text('Customer ID'),
            subtitle: Text(account?.customerId ?? '—'),
          ),
          ListTile(
            title: const Text('Plan'),
            subtitle: Text(account?.planName ?? '—'),
          ),
          ListTile(
            title: const Text('Status'),
            subtitle: Text(account?.status ?? '—'),
          ),
          ListTile(
            title: const Text('Expires'),
            subtitle: Text(_formatDate(account?.expiresAt)),
          ),
          ListTile(
            title: const Text('Devices'),
            subtitle: Text(
              account == null
                  ? '—'
                  : '${account.devicesUsed} / ${account.devicesLimit}',
            ),
          ),
          const ListTile(
            title: Text('App version'),
            subtitle: Text(AppConfig.appVersion),
          ),
          const ListTile(
            title: Text('Support'),
            subtitle: Text('Contact support from Settings'),
          ),
        ],
      ),
    );
  }
}
