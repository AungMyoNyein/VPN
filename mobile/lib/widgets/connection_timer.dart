import 'dart:async';

import 'package:flutter/material.dart';

class ConnectionTimer extends StatefulWidget {
  const ConnectionTimer({super.key, required this.connectedSinceEpochMs});

  final int connectedSinceEpochMs;

  @override
  State<ConnectionTimer> createState() => _ConnectionTimerState();
}

class _ConnectionTimerState extends State<ConnectionTimer> {
  Timer? _timer;
  Duration _elapsed = Duration.zero;

  @override
  void initState() {
    super.initState();
    _tick();
    _timer = Timer.periodic(const Duration(seconds: 1), (_) => _tick());
  }

  @override
  void didUpdateWidget(ConnectionTimer oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.connectedSinceEpochMs != widget.connectedSinceEpochMs) {
      _tick();
    }
  }

  void _tick() {
    if (widget.connectedSinceEpochMs <= 0) {
      setState(() => _elapsed = Duration.zero);
      return;
    }
    final now = DateTime.now().millisecondsSinceEpoch;
    setState(() {
      _elapsed = Duration(milliseconds: now - widget.connectedSinceEpochMs);
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  String _format(Duration d) {
    final h = d.inHours;
    final m = d.inMinutes.remainder(60).toString().padLeft(2, '0');
    final s = d.inSeconds.remainder(60).toString().padLeft(2, '0');
    if (h > 0) {
      return '${h.toString().padLeft(2, '0')}:$m:$s';
    }
    return '$m:$s';
  }

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        Text(
          'Connected',
          style: Theme.of(context).textTheme.bodyMedium,
        ),
        const SizedBox(width: 12),
        Text(
          _format(_elapsed),
          style: Theme.of(context).textTheme.titleMedium?.copyWith(
                fontFeatures: const [FontFeature.tabularFigures()],
              ),
        ),
      ],
    );
  }
}
