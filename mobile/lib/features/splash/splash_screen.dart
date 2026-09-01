import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:vpn_mobile/core/vpn_preferences.dart';
import 'package:vpn_mobile/features/auth/activation_screen.dart';
import 'package:vpn_mobile/features/home/home_screen.dart';
import 'package:vpn_mobile/state/app_auth_state.dart';
import 'package:vpn_mobile/state/vpn_manager.dart';

class SplashScreen extends StatefulWidget {
  const SplashScreen({super.key});

  static const routeName = '/';

  @override
  State<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends State<SplashScreen> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _bootstrap());
  }

  Future<void> _bootstrap() async {
    final auth = context.read<AppAuthState>();
    await auth.bootstrap();
    if (!mounted) return;

    switch (auth.status) {
      case AppAuthStatus.ready:
        Navigator.of(context).pushReplacementNamed(HomeScreen.routeName);
        await _maybeConnectOnLaunch();
      case AppAuthStatus.needsActivation:
      case AppAuthStatus.error:
        Navigator.of(context).pushReplacementNamed(ActivationScreen.routeName);
      case AppAuthStatus.unknown:
      case AppAuthStatus.authenticating:
        break;
    }
  }

  Future<void> _maybeConnectOnLaunch() async {
    final prefs = await VpnPreferences.load();
    if (!prefs.connectOnLaunch || !mounted) return;
    final vpn = context.read<VpnManager>();
    if (!vpn.isProtected && !vpn.isBusy) {
      await vpn.connect();
    }
  }

  @override
  Widget build(BuildContext context) {
    return const Scaffold(
      body: Center(child: CircularProgressIndicator()),
    );
  }
}
