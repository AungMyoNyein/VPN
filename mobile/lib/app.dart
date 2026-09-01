import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:vpn_mobile/core/api_client.dart';
import 'package:vpn_mobile/core/secure_credential_store.dart';
import 'package:vpn_mobile/core/vpn_preferences.dart';
import 'package:vpn_mobile/core/wireguard_key_service.dart';
import 'package:vpn_mobile/features/splash/splash_screen.dart';
import 'package:vpn_mobile/features/home/home_screen.dart';
import 'package:vpn_mobile/features/auth/activation_screen.dart';
import 'package:vpn_mobile/features/locations/locations_screen.dart';
import 'package:vpn_mobile/features/account/account_screen.dart';
import 'package:vpn_mobile/features/settings/settings_screen.dart';
import 'package:vpn_mobile/state/app_auth_state.dart';
import 'package:vpn_mobile/state/vpn_manager.dart';

class VpnApp extends StatelessWidget {
  const VpnApp({
    super.key,
    this.apiClient,
    this.credentialStore,
    this.vpnManager,
  });

  final ApiClient? apiClient;
  final SecureCredentialStore? credentialStore;
  final VpnManager? vpnManager;

  @override
  Widget build(BuildContext context) {
    return MultiProvider(
      providers: [
        Provider<ApiClient>(
          create: (_) => apiClient ?? ApiClient(),
          dispose: (_, client) => client.close(),
        ),
        Provider<SecureCredentialStore>(
          create: (_) => credentialStore ?? FlutterSecureCredentialStore(),
        ),
        ChangeNotifierProvider<AppAuthState>(
          create: (context) => AppAuthState(
            apiClient: context.read<ApiClient>(),
            credentialStore: context.read<SecureCredentialStore>(),
          ),
        ),
        ChangeNotifierProvider<VpnManager>(
          create: (context) => vpnManager ??
              VpnManager(
                apiClient: context.read<ApiClient>(),
                keyService: WireguardKeyService(),
              ),
        ),
      ],
      child: FutureBuilder<VpnPreferences>(
        future: VpnPreferences.load(),
        builder: (context, snapshot) {
          if (!snapshot.hasData) {
            return const MaterialApp(
              home: Scaffold(
                body: Center(child: CircularProgressIndicator()),
              ),
            );
          }

          final prefs = snapshot.data!;
          WidgetsBinding.instance.addPostFrameCallback((_) {
            context.read<VpnManager>().initialize(prefs);
          });

          final themeMode = switch (prefs.themeMode) {
            'light' => ThemeMode.light,
            'dark' => ThemeMode.dark,
            _ => ThemeMode.system,
          };

          return MaterialApp(
            title: 'VPN Platform',
            debugShowCheckedModeBanner: false,
            themeMode: themeMode,
            theme: ThemeData(
              colorScheme: ColorScheme.fromSeed(
                seedColor: const Color(0xFF0D9488),
              ),
              useMaterial3: true,
            ),
            darkTheme: ThemeData(
              colorScheme: ColorScheme.fromSeed(
                seedColor: const Color(0xFF0D9488),
                brightness: Brightness.dark,
              ),
              useMaterial3: true,
            ),
            initialRoute: SplashScreen.routeName,
            routes: {
              SplashScreen.routeName: (_) => const SplashScreen(),
              ActivationScreen.routeName: (_) => const ActivationScreen(),
              HomeScreen.routeName: (_) => const HomeScreen(),
              LocationsScreen.routeName: (_) => const LocationsScreen(),
              AccountScreen.routeName: (_) => const AccountScreen(),
              SettingsScreen.routeName: (_) => const SettingsScreen(),
            },
          );
        },
      ),
    );
  }
}
