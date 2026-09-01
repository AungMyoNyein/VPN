import 'package:flutter_test/flutter_test.dart';
import 'package:vpn_mobile/state/vpn_connection_controller.dart';
import 'package:vpn_mobile/state/vpn_connection_state.dart';

void main() {
  test('allows happy-path connect transitions with permission step', () {
    final c = VpnConnectionController();
    c.transitionTo(VpnConnectionState.preparing);
    c.transitionTo(VpnConnectionState.authorizing);
    c.transitionTo(VpnConnectionState.provisioning);
    c.transitionTo(VpnConnectionState.requestingPermission);
    c.transitionTo(VpnConnectionState.connecting);
    c.transitionTo(VpnConnectionState.connected);
    expect(c.state, VpnConnectionState.connected);
  });

  test('rejects illegal jump to connected', () {
    final c = VpnConnectionController();
    expect(
      () => c.transitionTo(VpnConnectionState.connected),
      throwsStateError,
    );
  });
}
