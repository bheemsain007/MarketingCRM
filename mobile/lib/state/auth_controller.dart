import 'package:flutter/foundation.dart';

import '../core/api/api_exception.dart';
import '../models/user.dart';
import '../repositories/auth_repository.dart';

enum AuthStatus {
  /// Before the stored token has been checked. The app shows a splash here
  /// rather than a login form, so a signed-in user does not see the login
  /// screen flash past on every cold start.
  checking,
  signedOut,
  signedIn,
}

/// Who is signed in, and the single place that changes.
///
/// It also owns the 401 rule. `ApiClient.onUnauthenticated` points at
/// [handleUnauthenticated], so an expired token discovered by *any* request in
/// the app — a lead list refresh, an outbox flush at 3am — ends in the same
/// place: the login screen, with the reason on it.
class AuthController extends ChangeNotifier {
  AuthController({required AuthRepository repository}) : _repository = repository;

  final AuthRepository _repository;

  AuthStatus _status = AuthStatus.checking;
  User? _user;
  bool _busy = false;
  String? _errorMessage;
  Map<String, String> _fieldErrors = const <String, String>{};

  AuthStatus get status => _status;
  User? get user => _user;
  bool get busy => _busy;
  String? get errorMessage => _errorMessage;
  Map<String, String> get fieldErrors => _fieldErrors;

  bool get isSignedIn => _status == AuthStatus.signedIn;

  /// Cold start: is the stored token still good?
  ///
  /// The question is put to the server (`GET /auth/me`) rather than answered
  /// locally. This client cannot inspect a Sanctum token, and a token revoked
  /// by an admin disabling the account looks identical to a valid one from here.
  Future<void> restore() async {
    final token = await _repository.storedToken();

    if (token == null || token.isEmpty) {
      _set(status: AuthStatus.signedOut);

      return;
    }

    try {
      final user = await _repository.me();
      _user = user;
      _set(status: AuthStatus.signedIn);
    } on UnauthenticatedException {
      // handleUnauthenticated has already run via the client hook.
      _set(status: AuthStatus.signedOut);
    } on ApiException catch (error) {
      // Offline at launch with a token we have no reason to doubt. Staying
      // signed out would be wrong — but so would trusting it silently, so the
      // reason is shown and the user can retry.
      _set(status: AuthStatus.signedOut, errorMessage: error.message);
    }
  }

  Future<bool> signIn({required String email, required String password}) async {
    _set(busy: true, errorMessage: null, fieldErrors: const <String, String>{});

    try {
      _user = await _repository.login(email: email, password: password);
      _set(status: AuthStatus.signedIn, busy: false);

      return true;
    } on ValidationException catch (error) {
      _set(busy: false, errorMessage: error.message, fieldErrors: error.fieldErrors);
    } on ApiException catch (error) {
      // Includes the 401 for bad credentials, whose message is identical
      // whether or not the account exists — this app must not add a hint that
      // turns it back into a user-enumeration oracle.
      _set(busy: false, errorMessage: error.message);
    }

    return false;
  }

  Future<void> signOut() async {
    _set(busy: true);
    await _repository.logout();
    _user = null;
    _set(status: AuthStatus.signedOut, busy: false, errorMessage: null);
  }

  /// Called by `ApiClient` on every 401, from wherever it happened.
  Future<void> handleUnauthenticated() async {
    // A 401 while already signed out is a failed sign-in attempt, not an
    // expiry. Saying "your session expired" there would be a lie, and a
    // confusing one on the very first launch.
    final wasSignedIn = _status == AuthStatus.signedIn;

    await _repository.forgetToken();
    _user = null;

    _set(
      status: AuthStatus.signedOut,
      busy: false,
      errorMessage: wasSignedIn ? 'Your session has expired. Please sign in again.' : _errorMessage,
    );
  }

  void clearError() {
    if (_errorMessage == null && _fieldErrors.isEmpty) {
      return;
    }

    _set(errorMessage: null, fieldErrors: const <String, String>{});
  }

  void _set({
    AuthStatus? status,
    bool? busy,
    Object? errorMessage = _unset,
    Map<String, String>? fieldErrors,
  }) {
    if (status != null) {
      _status = status;
    }
    if (busy != null) {
      _busy = busy;
    }
    if (!identical(errorMessage, _unset)) {
      _errorMessage = errorMessage as String?;
    }
    if (fieldErrors != null) {
      _fieldErrors = fieldErrors;
    }

    notifyListeners();
  }

  /// Sentinel: lets a caller pass `null` to clear the message and omit the
  /// argument to leave it alone. Two different intentions, one parameter.
  static const Object _unset = Object();
}
