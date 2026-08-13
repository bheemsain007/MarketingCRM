import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:marketing_crm_mobile/core/api/api_client.dart';
import 'package:marketing_crm_mobile/core/api/api_exception.dart';

import '../../support/scripted_api.dart';

ApiClient clientFor(ScriptedApi api, {String? token}) {
  return ApiClient(
    apiRoot: ScriptedApi.apiRoot,
    httpClient: api.build(),
    tokenProvider: () async => token,
  );
}

void main() {
  group('ApiClient request shape', () {
    test('sends the bearer token and asks for JSON', () async {
      final api = ScriptedApi()..reply('GET', '/auth/me', data: <String, dynamic>{'id': 1});
      final client = clientFor(api, token: '1|abcdef');

      await client.get('/auth/me');

      final request = api.lastOf('GET', '/auth/me')!;
      expect(request.header('authorization'), 'Bearer 1|abcdef');
      expect(request.header('accept'), 'application/json');
    });

    test('omits the Authorization header when signed out', () async {
      final api = ScriptedApi()..reply('POST', '/auth/login', data: <String, dynamic>{});
      final client = clientFor(api);

      await client.post('/auth/login', body: <String, dynamic>{'email': 'a@b.c'});

      expect(api.lastOf('POST', '/auth/login')!.header('authorization'), isNull);
    });

    test('sends query parameters and skips nulls', () async {
      final api = ScriptedApi()..reply('GET', '/leads', data: listData(const <Map<String, dynamic>>[]));
      final client = clientFor(api);

      await client.get('/leads', query: <String, dynamic>{'page': 2, 'q': 'sharma', 'filter': null});

      final request = api.lastOf('GET', '/leads')!;
      expect(request.query['page'], '2');
      expect(request.query['q'], 'sharma');
      expect(request.query.containsKey('filter'), isFalse);
    });

    test('passes the Idempotency-Key through', () async {
      final api = ScriptedApi()..reply('PATCH', '/calls/900', data: <String, dynamic>{'id': 900});
      final client = clientFor(api);

      await client.patch('/calls/900', body: <String, dynamic>{'status': 'connected'}, idempotencyKey: 'key-1');

      expect(api.lastOf('PATCH', '/calls/900')!.header('idempotency-key'), 'key-1');
    });
  });

  group('ApiClient error mapping', () {
    test('401 throws UnauthenticatedException and fires the hook exactly once', () async {
      final api = ScriptedApi()
        ..reply('GET', '/leads',
            status: 401,
            success: false,
            message: 'Unauthenticated.',
            errors: <Map<String, dynamic>>[
              <String, dynamic>{'code': 'auth.unauthenticated', 'message': 'Unauthenticated.'},
            ]);

      var hookCalls = 0;
      final client = clientFor(api)..onUnauthenticated = () async => hookCalls++;

      await expectLater(
        client.get('/leads'),
        throwsA(isA<UnauthenticatedException>().having((e) => e.code, 'code', 'auth.unauthenticated')),
      );
      expect(hookCalls, 1);
    });

    test('403 dnc.suppressed becomes a ForbiddenException carrying the code and data', () async {
      final api = ScriptedApi()
        ..reply('POST', '/leads/42/calls',
            status: 403,
            success: false,
            message: 'This lead is on the do-not-contact list.',
            data: <String, dynamic>{'channel': 'call', 'reason': 'opted_out'},
            errors: <Map<String, dynamic>>[
              <String, dynamic>{'code': 'dnc.suppressed', 'message': 'Suppressed.'},
            ]);

      await expectLater(
        clientFor(api).post('/leads/42/calls'),
        throwsA(isA<ForbiddenException>()
            .having((e) => e.code, 'code', 'dnc.suppressed')
            .having((e) => e.message, 'message', 'This lead is on the do-not-contact list.')
            .having((e) => e.data?['channel'], 'data.channel', 'call')
            .having((e) => e.isRetryable, 'isRetryable', isFalse)),
      );
    });

    test('422 becomes a ValidationException with field errors', () async {
      final api = ScriptedApi()
        ..reply('PATCH', '/calls/900',
            status: 422,
            success: false,
            message: 'The given data was invalid.',
            errors: <Map<String, dynamic>>[
              <String, dynamic>{
                'field': 'callback_at',
                'code': 'validation.required',
                'message': 'When should this lead be called back?',
              },
            ]);

      await expectLater(
        clientFor(api).patch('/calls/900', body: <String, dynamic>{'status': 'call_back_requested'}),
        throwsA(isA<ValidationException>().having(
          (e) => e.fieldErrors['callback_at'],
          'callback_at',
          'When should this lead be called back?',
        )),
      );
    });

    test('409 becomes a ConflictException — the write-once outcome rule', () async {
      final api = ScriptedApi()
        ..reply('PATCH', '/calls/900',
            status: 409,
            success: false,
            message: 'This call already has an outcome.');

      await expectLater(
        clientFor(api).patch('/calls/900', body: <String, dynamic>{'status': 'busy'}),
        throwsA(isA<ConflictException>()),
      );
    });

    test('500 is retryable, 422 is not', () async {
      final api = ScriptedApi()
        ..reply('GET', '/leads', status: 500, success: false, message: 'Server error.')
        ..reply('GET', '/follow-ups', status: 422, success: false, message: 'Bad filter.');
      final client = clientFor(api);

      await expectLater(
        client.get('/leads'),
        throwsA(isA<ServerException>().having((e) => e.isRetryable, 'isRetryable', isTrue)),
      );
      await expectLater(
        client.get('/follow-ups'),
        throwsA(isA<ValidationException>().having((e) => e.isRetryable, 'isRetryable', isFalse)),
      );
    });

    test('a dead socket becomes a NetworkException, not a server error', () async {
      final api = ScriptedApi()..offline = true;

      await expectLater(
        clientFor(api).get('/leads'),
        throwsA(isA<NetworkException>()
            .having((e) => e.isTransport, 'isTransport', isTrue)
            .having((e) => e.statusCode, 'statusCode', isNull)),
      );
    });

    test('a 200 carrying an HTML body is a malformed response, not a success', () async {
      final api = ScriptedApi()
        ..on('GET', '/leads', (_) => http.Response('<html>hello</html>', 200));

      await expectLater(
        clientFor(api).get('/leads'),
        throwsA(isA<MalformedResponseException>()),
      );
    });

    test('a 200 whose envelope says success:false is still a failure', () async {
      // Trusting the status line over the body would render a success screen
      // for a refusal.
      final api = ScriptedApi()
        ..reply('GET', '/leads', success: false, message: 'Something went wrong.');

      await expectLater(clientFor(api).get('/leads'), throwsA(isA<ApiException>()));
    });

    test('204 with no body is a success with no data', () async {
      final api = ScriptedApi()..on('DELETE', '/dnc/1', (_) => http.Response('', 204));

      final envelope = await clientFor(api).delete('/dnc/1');

      expect(envelope.success, isTrue);
      expect(envelope.data, isNull);
    });
  });
}
