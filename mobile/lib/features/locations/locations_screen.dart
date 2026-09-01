import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:vpn_mobile/core/models/vpn_location.dart';
import 'package:vpn_mobile/state/app_auth_state.dart';
import 'package:vpn_mobile/state/vpn_manager.dart';

class LocationsScreen extends StatefulWidget {
  const LocationsScreen({super.key, this.embedded = false});

  static const routeName = '/locations';

  final bool embedded;

  @override
  State<LocationsScreen> createState() => _LocationsScreenState();
}

class _LocationsScreenState extends State<LocationsScreen> {
  List<VpnLocation>? _locations;
  bool _loading = true;
  String? _error;
  String _query = '';

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
          _error = 'Failed to load locations';
          _loading = false;
        });
      }
    }
  }

  void _selectSmartLocation() {
    final vpn = context.read<VpnManager>();
    vpn.selectLocation(null);
    if (!widget.embedded) Navigator.of(context).pop();
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(content: Text('Smart Location selected')),
    );
  }

  void _selectLocation(VpnLocation location) {
    if (!location.available) return;
    final vpn = context.read<VpnManager>();
    vpn.selectLocation(location);
    if (!widget.embedded) Navigator.of(context).pop();
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text('${location.displayName} selected')),
    );
  }

  @override
  Widget build(BuildContext context) {
    final vpn = context.watch<VpnManager>();
    final selectedId = vpn.selectedLocation?.id;
    final smartSelected = vpn.preferences?.smartLocation ?? true;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Locations'),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh),
            onPressed: _loadLocations,
          ),
        ],
      ),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
            child: TextField(
              decoration: InputDecoration(
                hintText: 'Search countries',
                prefixIcon: const Icon(Icons.search),
                border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(12),
                ),
                isDense: true,
              ),
              onChanged: (v) => setState(() => _query = v.trim().toLowerCase()),
            ),
          ),
          Expanded(child: _buildBody(selectedId, smartSelected)),
        ],
      ),
    );
  }

  Widget _buildBody(int? selectedId, bool smartSelected) {
    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_error != null) {
      return Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(_error!),
            const SizedBox(height: 12),
            ElevatedButton(onPressed: _loadLocations, child: const Text('Retry')),
          ],
        ),
      );
    }

    final locations = (_locations ?? [])
        .where((l) =>
            _query.isEmpty ||
            l.displayName.toLowerCase().contains(_query) ||
            l.countryName.toLowerCase().contains(_query))
        .toList();

    return ListView(
      children: [
        ListTile(
          leading: Icon(
            Icons.auto_awesome,
            color: smartSelected ? Theme.of(context).colorScheme.primary : null,
          ),
          title: const Text('Smart Location'),
          subtitle: Text(
            context.watch<VpnManager>().recommendedServer?.name ??
                'Best available server',
          ),
          trailing: smartSelected
              ? Icon(Icons.check_circle, color: Theme.of(context).colorScheme.primary)
              : null,
          onTap: _selectSmartLocation,
        ),
        const Divider(height: 1),
        if (locations.isEmpty)
          const Padding(
            padding: EdgeInsets.all(24),
            child: Center(child: Text('No active VPN locations available.')),
          )
        else
          ...locations.map((loc) {
            final selected = !smartSelected && selectedId == loc.id;
            return ListTile(
              leading: Icon(
                Icons.public,
                color: loc.available
                    ? Theme.of(context).colorScheme.primary
                    : Theme.of(context).colorScheme.outline,
              ),
              title: Text(loc.displayName),
              subtitle: Text(
                '${loc.serversCount} server(s) • ${loc.available ? 'Available' : 'Capacity full'}',
              ),
              trailing: selected
                  ? Icon(Icons.check_circle, color: Theme.of(context).colorScheme.primary)
                  : loc.available
                      ? null
                      : const Icon(Icons.block),
              onTap: loc.available ? () => _selectLocation(loc) : null,
            );
          }),
      ],
    );
  }
}
