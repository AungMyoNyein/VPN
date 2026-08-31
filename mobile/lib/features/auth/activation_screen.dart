import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import 'package:vpn_mobile/features/auth/activation_helpers.dart';
import 'package:vpn_mobile/features/home/home_screen.dart';
import 'package:vpn_mobile/state/app_auth_state.dart';

class ActivationScreen extends StatefulWidget {
  const ActivationScreen({super.key});

  static const routeName = '/activate';

  @override
  State<ActivationScreen> createState() => _ActivationScreenState();
}

class _ActivationScreenState extends State<ActivationScreen> {
  final _customerId = TextEditingController();
  final _activationKey = TextEditingController();

  ActivationFormState _formState = ActivationFormState.idle;
  String? _error;

  @override
  void initState() {
    super.initState();
    final auth = context.read<AppAuthState>();
    if (auth.errorMessage != null) {
      _error = auth.errorMessage;
    }
  }

  @override
  void dispose() {
    _customerId.dispose();
    _activationKey.dispose();
    super.dispose();
  }

  Future<void> _activate() async {
    setState(() {
      _formState = ActivationFormState.validating;
      _error = null;
    });

    final validationError = validateActivationInput(
      customerId: _customerId.text,
      activationKey: _activationKey.text,
    );

    if (validationError != null) {
      setState(() {
        _formState = ActivationFormState.error;
        _error = validationError;
      });
      return;
    }

    setState(() => _formState = ActivationFormState.activating);

    final auth = context.read<AppAuthState>();
    await auth.activate(
      customerId: normalizeCustomerId(_customerId.text),
      activationKey: normalizeActivationKey(_activationKey.text),
    );

    if (!mounted) return;

    if (auth.status == AppAuthStatus.ready) {
      setState(() => _formState = ActivationFormState.success);
      Navigator.of(context).pushReplacementNamed(HomeScreen.routeName);
      return;
    }

    setState(() {
      _formState = ActivationFormState.error;
      _error = auth.errorMessage ?? 'Something went wrong. Please try again.';
    });
  }

  bool get _loading =>
      _formState == ActivationFormState.validating ||
      _formState == ActivationFormState.activating;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const SizedBox(height: 32),
              Text(
                'Activate your VPN',
                style: Theme.of(context).textTheme.headlineSmall,
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 32),
              TextField(
                controller: _customerId,
                decoration: const InputDecoration(
                  labelText: 'Customer ID',
                  hintText: 'CUST-000125',
                ),
                textCapitalization: TextCapitalization.characters,
                enabled: !_loading,
              ),
              const SizedBox(height: 16),
              TextField(
                controller: _activationKey,
                decoration: const InputDecoration(
                  labelText: 'Activation Key',
                  hintText: 'VPN-XXXX-XXXX-XXXX',
                ),
                textCapitalization: TextCapitalization.characters,
                inputFormatters: [
                  FilteringTextInputFormatter.allow(RegExp(r'[A-Za-z0-9\-]')),
                ],
                enabled: !_loading,
              ),
              if (_error != null) ...[
                const SizedBox(height: 12),
                Text(
                  _error!,
                  style: TextStyle(color: Theme.of(context).colorScheme.error),
                ),
              ],
              const SizedBox(height: 24),
              FilledButton(
                onPressed: _loading ? null : _activate,
                child: _loading
                    ? const SizedBox(
                        height: 20,
                        width: 20,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Text('Activate VPN'),
              ),
              const Spacer(),
              TextButton(
                onPressed: _loading ? null : () {},
                child: const Text('Need help? Contact us'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
