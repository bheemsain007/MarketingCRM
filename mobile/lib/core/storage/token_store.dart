import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Where the bearer token lives.
///
/// An interface rather than a concrete class so tests never touch the Android
/// Keystore, and so the one production implementation is the only place that
/// decides what "secure" means.
abstract class TokenStore {
  Future<String?> read();

  Future<void> write(String token);

  Future<void> clear();
}

/// Keystore-backed storage (`EncryptedSharedPreferences` on Android).
///
/// Not `shared_preferences`: this token is a live credential for an API holding
/// every lead's phone number, and plain preferences are world-readable on a
/// rooted device and swept up by ADB backups.
class SecureTokenStore implements TokenStore {
  SecureTokenStore({FlutterSecureStorage? storage})
      : _storage = storage ??
            const FlutterSecureStorage(
              aOptions: AndroidOptions(encryptedSharedPreferences: true),
            );

  static const String _key = 'crm.auth.token';

  final FlutterSecureStorage _storage;

  @override
  Future<String?> read() => _storage.read(key: _key);

  @override
  Future<void> write(String token) => _storage.write(key: _key, value: token);

  @override
  Future<void> clear() => _storage.delete(key: _key);
}

/// For tests and for the widget layer, which must never depend on a platform
/// channel just to know whether somebody is signed in.
class InMemoryTokenStore implements TokenStore {
  InMemoryTokenStore([this._token]);

  String? _token;

  @override
  Future<String?> read() async => _token;

  @override
  Future<void> write(String token) async => _token = token;

  @override
  Future<void> clear() async => _token = null;
}
