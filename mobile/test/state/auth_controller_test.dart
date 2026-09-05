import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';

import '../support/fixtures.dart';
import '../support/harness.dart';

void main() {
  group('AuthController.restore', () {
    test('with no stored token, goes straight to signed out', () async {
      final harness = Harness();

      await harness.dependencies.auth.restore();

      expect(harness.dependencies.auth.status.name, 'signedOut');
      expect(harness.api.received, isEmpty);

      await harness.dispose();
    });

    test('with a good token, asks the server who we are', () async {
      final harness = Harness(token: '1|stored');
      harness.api.reply('GET', '/auth/me', data: userJson());

      await harness.dependencies.auth.restore();

      expect(harness.dependencies.auth.isSignedIn, isTrue);
      expect(harness.dependencies.auth.user?.name, 'Priya Sharma');
      expect(harness.dependencies.auth.user!.can('calls.create'), isTrue);
      expect(harness.dependencies.auth.user!.can('users.manage'), isFalse);

      await harness.dispose();
    });

    test('a revoked token is discovered by asking, and clears local state', () async {
      final harness = Harness(token: '1|revoked');
      harness.api.reply('GET', '/auth/me',
          status: 401,
          success: false,
          message: 'Unauthenticated.',
          errors: <Map<String, dynamic>>[
            <String, dynamic>{'code': 'auth.unauthenticated', 'message': 'Unauthenticated.'},
          ]);

      await harness.dependencies.auth.restore();

      expect(harness.dependencies.auth.status.name, 'signedOut');
      expect(await harness.tokenStore.read(), isNull);

      await harness.dispose();
    });

    test('offline at launch keeps the user out but says why', () async {
      final harness = Harness(token: '1|stored');
      harness.api.offline = true;

      await harness.dependencies.auth.restore();

      expect(harness.dependencies.auth.status.name, 'signedOut');
      expect(harness.dependencies.auth.errorMessage, contains('No connection'));
      // The token is NOT thrown away: the server never said it was bad.
      expect(await harness.tokenStore.read(), '1|stored');

      await harness.dispose();
    });

    test('a malformed /auth/me response (200, no data) is a failure to confirm, not a crash',
        () async {
      // A response this app cannot read is not evidence the token is bad — it
      // falls into the same "shown error, token kept" bucket as offline, via
      // the generic ApiException branch rather than UnauthenticatedException.
      final harness = Harness(token: '1|stored');
      harness.api.reply('GET', '/auth/me', data: null);

      await harness.dependencies.auth.restore();

      expect(harness.dependencies.auth.status.name, 'signedOut');
      expect(harness.dependencies.auth.errorMessage, isNotNull);
      expect(await harness.tokenStore.read(), '1|stored');

      await harness.dispose();
    });
  });

  group('AuthController.signIn', () {
    test('stores the token and identifies the user', () async {
      final harness = Harness();
      harness.api.reply('POST', '/auth/login',
          message: 'Signed in successfully.',
          data: <String, dynamic>{
            'token': '1|Vo1FH1oh',
            'token_type': 'Bearer',
            'work_session_id': 1,
            'user': userJson(),
          });

      final ok = await harness.dependencies.auth.signIn(
        email: 'priya@example.com',
        password: 'secret',
      );

      expect(ok, isTrue);
      expect(harness.dependencies.auth.isSignedIn, isTrue);
      expect(await harness.tokenStore.read(), '1|Vo1FH1oh');

      await harness.dispose();
    });

    test('declares itself as the android client so a work session opens', () async {
      final harness = Harness();
      harness.api.reply('POST', '/auth/login', data: <String, dynamic>{
        'token': '1|abc',
        'user': userJson(),
      });

      await harness.dependencies.auth.signIn(email: 'priya@example.com', password: 'secret');

      final body = jsonDecode(harness.api.lastOf('POST', '/auth/login')!.body) as Map<String, dynamic>;
      expect(body['source'], 'android');
      expect(body['email'], 'priya@example.com');

      await harness.dispose();
    });

    test('bad credentials surface the server sentence and store nothing', () async {
      final harness = Harness();
      harness.api.reply('POST', '/auth/login',
          status: 401,
          success: false,
          message: 'These credentials do not match our records.',
          errors: <Map<String, dynamic>>[
            <String, dynamic>{'code': 'auth.unauthenticated', 'message': 'Invalid.'},
          ]);

      final ok = await harness.dependencies.auth.signIn(email: 'priya@example.com', password: 'wrong');

      expect(ok, isFalse);
      expect(harness.dependencies.auth.status.name, 'signedOut');
      // Not "your session expired": nobody was signed in. The message is the
      // server's own, which is deliberately identical whether or not the
      // account exists.
      expect(harness.dependencies.auth.errorMessage, 'These credentials do not match our records.');
      expect(await harness.tokenStore.read(), isNull);

      await harness.dispose();
    });

    test('a disabled account is a 403 and is reported as sent', () async {
      final harness = Harness();
      harness.api.reply('POST', '/auth/login',
          status: 403, success: false, message: 'This account has been disabled.');

      await harness.dependencies.auth.signIn(email: 'priya@example.com', password: 'secret');

      expect(harness.dependencies.auth.errorMessage, 'This account has been disabled.');

      await harness.dispose();
    });

    test('a 422 populates per-field errors', () async {
      final harness = Harness();
      harness.api.reply('POST', '/auth/login',
          status: 422,
          success: false,
          message: 'The given data was invalid.',
          errors: <Map<String, dynamic>>[
            <String, dynamic>{
              'field': 'email',
              'code': 'validation.email',
              'message': 'Enter a valid email address.',
            },
          ]);

      await harness.dependencies.auth.signIn(email: 'nope', password: 'secret');

      expect(harness.dependencies.auth.fieldErrors['email'], 'Enter a valid email address.');

      await harness.dispose();
    });

    test('throttling after five attempts is shown, not swallowed', () async {
      final harness = Harness();
      harness.api.reply('POST', '/auth/login',
          status: 429, success: false, message: 'Too many attempts. Try again in 60 seconds.');

      await harness.dependencies.auth.signIn(email: 'priya@example.com', password: 'secret');

      expect(harness.dependencies.auth.errorMessage, contains('Too many attempts'));

      await harness.dispose();
    });

    test('a 200 with no token is a malformed response, not a silent sign-in', () async {
      // `AuthRepository.login` throws `MalformedResponseException` itself here
      // — the server said success but sent nothing to authenticate future
      // requests with. Nothing must be stored.
      final harness = Harness();
      harness.api.reply('POST', '/auth/login',
          message: 'Signed in successfully.', data: <String, dynamic>{'user': userJson()});

      final ok = await harness.dependencies.auth.signIn(
        email: 'priya@example.com',
        password: 'secret',
      );

      // Not a 401, so the global `onUnauthenticated` hook never fires and
      // `status` is untouched by this failure — same as the 422 and 429 cases
      // above. `ok` and the surfaced message are what a malformed 200 changes.
      expect(ok, isFalse);
      expect(harness.dependencies.auth.isSignedIn, isFalse);
      expect(harness.dependencies.auth.errorMessage, contains('no token'));
      expect(await harness.tokenStore.read(), isNull);

      await harness.dispose();
    });

    test('a 200 with no user is a malformed response, not a silent sign-in', () async {
      final harness = Harness();
      harness.api.reply('POST', '/auth/login',
          message: 'Signed in successfully.', data: <String, dynamic>{'token': '1|abc'});

      final ok = await harness.dependencies.auth.signIn(
        email: 'priya@example.com',
        password: 'secret',
      );

      expect(ok, isFalse);
      expect(harness.dependencies.auth.isSignedIn, isFalse);
      expect(harness.dependencies.auth.errorMessage, contains('no user'));
      // The user check runs before the token is persisted, so this failure
      // leaves no token behind either — nothing is half signed-in.
      expect(await harness.tokenStore.read(), isNull);

      await harness.dispose();
    });
  });

  group('401 handling from anywhere in the app', () {
    test('a 401 on any request signs the user out with a session-expired notice', () async {
      final harness = Harness(token: '1|stored');
      harness.api.reply('GET', '/auth/me', data: userJson());
      await harness.dependencies.auth.restore();
      expect(harness.dependencies.auth.isSignedIn, isTrue);

      // The token dies mid-session; the next lead list refresh discovers it.
      harness.api.reply('GET', '/leads',
          status: 401, success: false, message: 'Unauthenticated.');

      await expectLater(harness.dependencies.leadRepository.list(), throwsA(anything));

      expect(harness.dependencies.auth.status.name, 'signedOut');
      expect(harness.dependencies.auth.errorMessage, 'Your session has expired. Please sign in again.');
      expect(await harness.tokenStore.read(), isNull);

      await harness.dispose();
    });
  });

  group('AuthController.signOut', () {
    test('revokes server-side and forgets the token', () async {
      final harness = Harness(token: '1|stored');
      harness.api.reply('GET', '/auth/me', data: userJson());
      harness.api.reply('POST', '/auth/logout', message: 'Signed out.');
      await harness.dependencies.auth.restore();

      await harness.dependencies.auth.signOut();

      expect(harness.dependencies.auth.status.name, 'signedOut');
      expect(harness.dependencies.auth.user, isNull);
      expect(await harness.tokenStore.read(), isNull);
      expect(harness.api.countOf('POST', '/auth/logout'), 1);

      await harness.dispose();
    });

    test('signs out locally even when the network call fails', () async {
      // The handset in the user's hand is the greater risk; a token we cannot
      // revoke remotely is not a reason to keep it on the device.
      final harness = Harness(token: '1|stored');
      harness.api.reply('GET', '/auth/me', data: userJson());
      await harness.dependencies.auth.restore();

      harness.api.offline = true;
      await harness.dependencies.auth.signOut();

      expect(harness.dependencies.auth.status.name, 'signedOut');
      expect(await harness.tokenStore.read(), isNull);

      await harness.dispose();
    });
  });
}
