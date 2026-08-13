import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';

/// "Is there a network right now, and tell me when that changes."
///
/// This is a *hint*, not a truth: an attached Wi-Fi with no route to the CRM
/// still reports online. That is why the outbox flushes on this signal but only
/// ever removes an entry on a real server response — the monitor decides *when*
/// to try, never *whether* the write landed.
abstract class ConnectivityMonitor {
  Future<bool> isOnline();

  /// Emits `true` when connectivity appears and `false` when it goes away.
  Stream<bool> get onChanged;

  void dispose();
}

class ConnectivityPlusMonitor implements ConnectivityMonitor {
  ConnectivityPlusMonitor({Connectivity? connectivity})
      : _connectivity = connectivity ?? Connectivity();

  final Connectivity _connectivity;

  @override
  Future<bool> isOnline() async => _hasConnection(await _connectivity.checkConnectivity());

  @override
  Stream<bool> get onChanged => _connectivity.onConnectivityChanged.map(_hasConnection);

  static bool _hasConnection(List<ConnectivityResult> results) {
    return results.any((result) => result != ConnectivityResult.none);
  }

  @override
  void dispose() {}
}

/// Drives the offline tests: connectivity is a global the test has to own.
class FakeConnectivityMonitor implements ConnectivityMonitor {
  FakeConnectivityMonitor({bool online = true}) : _online = online;

  bool _online;
  final StreamController<bool> _controller = StreamController<bool>.broadcast();

  @override
  Future<bool> isOnline() async => _online;

  @override
  Stream<bool> get onChanged => _controller.stream;

  void setOnline(bool value) {
    _online = value;
    _controller.add(value);
  }

  @override
  void dispose() {
    _controller.close();
  }
}
