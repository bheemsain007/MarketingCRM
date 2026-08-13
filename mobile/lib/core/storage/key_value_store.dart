import 'package:shared_preferences/shared_preferences.dart';

/// A tiny string-keyed store, which is all the outbox needs.
///
/// Abstracted so the queue can be tested without a platform channel — the
/// offline path is the one part of this app that cannot be verified by hand on
/// a desk, so it has to be verifiable in a test.
abstract class KeyValueStore {
  Future<String?> read(String key);

  Future<void> write(String key, String value);

  Future<void> delete(String key);
}

class SharedPreferencesStore implements KeyValueStore {
  SharedPreferencesStore();

  SharedPreferences? _prefs;

  Future<SharedPreferences> _instance() async {
    return _prefs ??= await SharedPreferences.getInstance();
  }

  @override
  Future<String?> read(String key) async => (await _instance()).getString(key);

  @override
  Future<void> write(String key, String value) async => (await _instance()).setString(key, value);

  @override
  Future<void> delete(String key) async => (await _instance()).remove(key);
}

class InMemoryKeyValueStore implements KeyValueStore {
  InMemoryKeyValueStore([Map<String, String>? seed]) : _values = <String, String>{...?seed};

  final Map<String, String> _values;

  @override
  Future<String?> read(String key) async => _values[key];

  @override
  Future<void> write(String key, String value) async => _values[key] = value;

  @override
  Future<void> delete(String key) async => _values.remove(key);
}
