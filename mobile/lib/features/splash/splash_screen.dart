import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:vpn_mobile/features/auth/activation_screen.dart';
import 'package:vpn_mobile/features/home/home_screen.dart';
import 'package:vpn_mobile/state/app_auth_state.dart';

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
      case AppAuthStatus.needsActivation:
      case AppAuthStatus.error:
        Navigator.of(context).pushReplacementNamed(ActivationScreen.routeName);
      case AppAuthStatus.unknown:
      case AppAuthStatus.authenticating:
        break;
    }
  }

  @override
  Widget build(BuildContext context) {
    return const Scaffold(
      body: Center(child: CircularProgressIndicator()),
    );
  }
}
