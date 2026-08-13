import '../core/api/api_client.dart';
import '../core/api/api_exception.dart';
import '../core/app_config.dart';
import '../core/storage/token_store.dart';
import '../models/user.dart';

/// Sign in, identify, sign out.
///
/// The token never leaves this class and [TokenStore]: no screen holds it, no
/// repository passes it around, and [ApiClient] pulls it through a callback at
/// send time so a sign-out takes effect on the very next request.
class AuthRepository {
  AuthRepository({required ApiClient api, required TokenStore tokenStore})
      : _api = api,
        _tokenStore = tokenStore;

  final ApiClient _api;
  final TokenStore _tokenStore;

  Future<String?> storedToken() => _tokenStore.read();

  /// `POST /auth/login`.
  ///
  /// `source: android` is not decoration — login opens a work session
  /// (FR-ATT-01) and the server records which client it came from.
  ///
  /// Throws [UnauthenticatedException] on bad credentials (with the same
  /// message whether or not the account exists — no enumeration oracle),
  /// [ForbiddenException] on a disabled account, [RateLimitedException] after
  /// five attempts a minute.
  Future<User> login({required String email, required String password}) async {
    final envelope = await _api.post('/auth/login', body: <String, dynamic>{
      'email': email,
      'password': password,
      'source': AppConfig.loginSource,
    });

    final data = envelope.dataMap;
    final token = data?['token'];

    if (token is! String || token.isEmpty) {
      throw const MalformedResponseException(
        statusCode: 200,
        message: 'Sign-in succeeded but the server sent no token.',
      );
    }

    final rawUser = data?['user'];
    if (rawUser is! Map<String, dynamic>) {
      throw const MalformedResponseException(
        statusCode: 200,
        message: 'Sign-in succeeded but the server sent no user.',
      );
    }

    // Written before the user is returned: a caller that renders the signed-in
    // shell must never be able to make a request that has no token yet.
    await _tokenStore.write(token);

    return User.fromJson(rawUser);
  }

  /// `GET /auth/me` — the boot call. Answers "is my stored token still good?"
  /// with the server's opinion rather than the token's expiry date, which this
  /// client cannot read anyway.
  Future<User> me() async {
    final envelope = await _api.get('/auth/me');
    final data = envelope.dataMap;

    if (data == null) {
      throw const MalformedResponseException(statusCode: 200);
    }

    return User.fromJson(data);
  }

  /// Revokes **this** token server-side, then forgets it locally.
  ///
  /// The local clear happens whatever the server said. A network failure during
  /// sign-out must still sign the user out of the handset in their hand — the
  /// token they are leaving behind is the greater risk.
  Future<void> logout() async {
    try {
      await _api.post('/auth/logout');
    } on ApiException {
      // Deliberately swallowed; see above.
    } finally {
      await _tokenStore.clear();
    }
  }

  /// Drops the local token without calling the server. Used when the server has
  /// already told us the token is dead (401) — calling logout with a dead token
  /// would only produce a second 401.
  Future<void> forgetToken() => _tokenStore.clear();
}
