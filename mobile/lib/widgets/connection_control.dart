import 'package:flutter/material.dart';
import 'package:vpn_mobile/state/vpn_connection_state.dart';

class ConnectionControl extends StatelessWidget {
  const ConnectionControl({
    super.key,
    required this.state,
    this.onConnect,
    this.onDisconnect,
  });

  final VpnConnectionState state;
  final VoidCallback? onConnect;
  final VoidCallback? onDisconnect;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final protected = state.isConnectedLike;
    final busy = state.isBusy;
    final color = protected
        ? theme.colorScheme.primary
        : theme.colorScheme.outline;

    return Semantics(
      button: true,
      enabled: !busy,
      label: protected ? 'Disconnect VPN' : 'Connect VPN',
      child: GestureDetector(
        onTap: protected ? onDisconnect : onConnect,
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 300),
          width: 160,
          height: 160,
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            border: Border.all(color: color, width: 4),
            color: protected
                ? theme.colorScheme.primary.withValues(alpha: 0.12)
                : theme.colorScheme.surface,
          ),
          child: Center(
            child: busy
                ? SizedBox(
                    width: 48,
                    height: 48,
                    child: CircularProgressIndicator(
                      strokeWidth: 3,
                      color: theme.colorScheme.primary,
                    ),
                  )
                : Icon(
                    protected ? Icons.shield : Icons.shield_outlined,
                    size: 64,
                    color: color,
                  ),
          ),
        ),
      ),
    );
  }
}
