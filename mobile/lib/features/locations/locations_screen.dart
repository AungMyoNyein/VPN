import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:vpn_mobile/core/models/vpn_location.dart';
import 'package:vpn_mobile/state/app_auth_state.dart';

class LocationsScreen extends StatefulWidget {
  const LocationsScreen({super.key});

  static const routeName = '/locations';

  @override
  State<LocationsScreen> createState() => _LocationsScreenState();
}

class _LocationsScreenState extends State<LocationsScreen> {
  List<VpnLocation>? _locations;
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _loadLocations();
  }

  Future<void> _loadLocations() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    try {
      final auth = context.read<AppAuthState>();
      final locations = await auth.apiClient.getLocations();
      if (mounted) {
        setState(() {
          _locations = locations;
          _loading = false;
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          _error = 'Failed to load locations: $e';
          _loading = false;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('VPN Locations'),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh),
            onPressed: _loadLocations,
          ),
        ],
      ),
      body: _buildBody(),
    );
  }

  Widget _buildBody() {
    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_error != null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(_error!, textAlign: TextAlign.center),
              const SizedBox(height: 12),
              ElevatedButton(
                onPressed: _loadLocations,
                child: const Text('Retry'),
              ),
            ],
          ),
        ),
      );
    }

    final locations = _locations ?? [];
    if (locations.isEmpty) {
      return const Center(
        child: Text('No active VPN locations available.'),
      );
    }

    return ListView.builder(
      itemCount: locations.length,
      itemBuilder: (context, index) {
        final loc = locations[index];
        return ListTile(
          leading: Icon(
            Icons.public,
            color: loc.available ? Colors.green : Colors.grey,
          ),
          title: Text(loc.displayName),
          subtitle: Text(
            '${loc.serversCount} server(s) • ${loc.available ? 'Available' : 'Capacity full'}',
          ),
          trailing: loc.available
              ? const Icon(Icons.check_circle_outline, color: Colors.green)
              : const Icon(Icons.block, color: Colors.grey),
        );
      },
    );
  }
}
