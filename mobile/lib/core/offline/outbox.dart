import 'dart:convert';

import 'package:flutter/foundation.dart';

import '../storage/key_value_store.dart';
import 'outbox_entry.dart';

/// The durable queue of writes that have not reached the server yet.
///
/// It is a `ChangeNotifier` because the count is on screen: a telecaller who
/// walked out of signal needs to see that three outcomes are still waiting,
/// not discover it a day later.
///
/// **Ordering is FIFO and preserved.** Call outcomes are evidence, and
/// evidence that arrives out of order is evidence somebody has to reconcile.
class Outbox extends ChangeNotifier {
  Outbox(this._store);

  static const String storageKey = 'crm.outbox.v1';

  final KeyValueStore _store;

  List<OutboxEntry> _entries = <OutboxEntry>[];
  bool _loaded = false;

  /// Oldest first.
  List<OutboxEntry> get entries => List<OutboxEntry>.unmodifiable(_entries);

  /// What the badge shows: work still expected to succeed.
  int get pendingCount => _entries.where((entry) => !entry.permanentlyFailed).length;

  int get failedCount => _entries.where((entry) => entry.permanentlyFailed).length;

  bool get isEmpty => _entries.isEmpty;

  /// Reads the queue back off disk. Safe to call more than once; it only reads
  /// once, so a second caller cannot wipe entries added since.
  Future<void> load() async {
    if (_loaded) {
      return;
    }

    _loaded = true;
    final raw = await _store.read(storageKey);

    if (raw == null || raw.trim().isEmpty) {
      return;
    }

    try {
      final decoded = jsonDecode(raw);
      if (decoded is! List) {
        return;
      }

      _entries = decoded
          .whereType<Map<String, dynamic>>()
          .map(OutboxEntry.fromJson)
          .toList();
    } on FormatException {
      // A corrupt queue file must not brick the app on launch. Dropping it
      // loses at most the unsent outcomes it already could not represent.
      await _store.delete(storageKey);
      _entries = <OutboxEntry>[];
    }

    notifyListeners();
  }

  Future<OutboxEntry> add(OutboxEntry entry) async {
    await load();

    _entries = <OutboxEntry>[..._entries, entry];
    await _persist();
    notifyListeners();

    return entry;
  }

  Future<void> remove(String id) async {
    final next = _entries.where((entry) => entry.id != id).toList();

    if (next.length == _entries.length) {
      return;
    }

    _entries = next;
    await _persist();
    notifyListeners();
  }

  Future<void> replace(OutboxEntry entry) async {
    final index = _entries.indexWhere((candidate) => candidate.id == entry.id);

    if (index < 0) {
      return;
    }

    final next = <OutboxEntry>[..._entries];
    next[index] = entry;
    _entries = next;
    await _persist();
    notifyListeners();
  }

  /// Discards a permanently-failed entry, which is a deliberate human act.
  Future<void> discard(String id) => remove(id);

  Future<void> clear() async {
    _entries = <OutboxEntry>[];
    await _store.delete(storageKey);
    notifyListeners();
  }

  Future<void> _persist() async {
    final payload = jsonEncode(_entries.map((entry) => entry.toJson()).toList());

    await _store.write(storageKey, payload);
  }
}
