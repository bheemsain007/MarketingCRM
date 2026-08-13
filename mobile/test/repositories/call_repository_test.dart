import 'package:flutter_test/flutter_test.dart';
import 'package:marketing_crm_mobile/core/api/api_exception.dart';

import '../support/fixtures.dart';
import '../support/harness.dart';

void main() {
  group('CallRepository.start — the dial intent', () {
    test('creates a call record with no outcome yet', () async {
      final harness = Harness();
      harness.api.reply('POST', '/leads/42/calls',
          status: 202,
          message: 'Call started. Report the outcome when it ends.',
          data: pendingCallJson());

      final call = await harness.dependencies.callRepository.start(42);

      expect(call.id, 900);
      expect(call.status, isNull);
      expect(call.isPending, isTrue);
      expect(harness.api.lastOf('POST', '/leads/42/calls')!.json['dial_source'], 'manual');

      await harness.dispose();
    });

    test('a suppressed lead is refused at the dial, not just at the check', () async {
      final harness = Harness();
      harness.api.reply('POST', '/leads/42/calls',
          status: 403,
          success: false,
          message: 'This lead is on the do-not-contact list and must not be called.',
          errors: <Map<String, dynamic>>[
            <String, dynamic>{'code': 'dnc.suppressed', 'message': 'Suppressed.'},
          ]);

      await expectLater(
        harness.dependencies.callRepository.start(42),
        throwsA(isA<ForbiddenException>().having((e) => e.code, 'code', 'dnc.suppressed')),
      );

      await harness.dispose();
    });

    test('a dial intent is NEVER queued offline — it would skip the DNC gate', () async {
      final harness = Harness();
      harness.api.offline = true;

      await expectLater(
        harness.dependencies.callRepository.start(42),
        throwsA(isA<NetworkException>()),
      );
      expect(harness.outbox.isEmpty, isTrue);

      await harness.dispose();
    });
  });

  group('CallRepository.recordOutcome — online', () {
    test('reports the outcome and returns the updated call', () async {
      final harness = Harness();
      harness.api.reply('PATCH', '/calls/900', data: completedCallJson());

      final result = await harness.dependencies.callRepository.recordOutcome(
        callId: 900,
        status: 'connected',
        leadName: 'Ramesh Kumar',
        notes: '  Asked for a quotation.  ',
        durationSeconds: 143,
      );

      expect(result.queued, isFalse);
      expect(result.call?.status, 'connected');

      final body = harness.api.lastOf('PATCH', '/calls/900')!.json;
      expect(body['status'], 'connected');
      expect(body['notes'], 'Asked for a quotation.');
      expect(body['duration_seconds'], 143);
      expect(body['ended_at'], isA<String>());
      expect(body.containsKey('callback_at'), isFalse);

      await harness.dispose();
    });

    test('omits empty optional fields rather than sending nulls', () async {
      final harness = Harness();
      harness.api.reply('PATCH', '/calls/900', data: completedCallJson());

      await harness.dependencies.callRepository.recordOutcome(
        callId: 900,
        status: 'busy',
        leadName: 'Ramesh Kumar',
        notes: '   ',
      );

      final body = harness.api.lastOf('PATCH', '/calls/900')!.json;
      expect(body.containsKey('notes'), isFalse);
      expect(body.containsKey('duration_seconds'), isFalse);

      await harness.dispose();
    });

    test('sends a callback time when one was given', () async {
      final harness = Harness();
      harness.api.reply('PATCH', '/calls/900', data: completedCallJson());

      await harness.dependencies.callRepository.recordOutcome(
        callId: 900,
        status: 'call_back_requested',
        leadName: 'Ramesh Kumar',
        callbackAt: DateTime.utc(2026, 8, 14, 5, 30),
      );

      expect(
        harness.api.lastOf('PATCH', '/calls/900')!.json['callback_at'],
        '2026-08-14T05:30:00.000Z',
      );

      await harness.dispose();
    });

    test('a missing callback time is the SERVER\'s 422, not a local rule', () async {
      // BR-CALL-05 lives in the backend FormRequest. The client sends what it
      // was given and reports the refusal.
      final harness = Harness();
      harness.api.reply('PATCH', '/calls/900',
          status: 422,
          success: false,
          message: 'When should this lead be called back?',
          errors: <Map<String, dynamic>>[
            <String, dynamic>{
              'field': 'callback_at',
              'code': 'validation.required',
              'message': 'When should this lead be called back?',
            },
          ]);

      await expectLater(
        harness.dependencies.callRepository.recordOutcome(
          callId: 900,
          status: 'call_back_requested',
          leadName: 'Ramesh Kumar',
        ),
        throwsA(isA<ValidationException>()),
      );
      // A rejected write is NOT queued: replaying it gets the same refusal.
      expect(harness.outbox.isEmpty, isTrue);

      await harness.dispose();
    });

    test('a second report is a 409 and is surfaced, not queued', () async {
      final harness = Harness();
      harness.api.reply('PATCH', '/calls/900',
          status: 409, success: false, message: 'This call already has an outcome.');

      await expectLater(
        harness.dependencies.callRepository.recordOutcome(
          callId: 900,
          status: 'connected',
          leadName: 'Ramesh Kumar',
        ),
        throwsA(isA<ConflictException>()),
      );
      expect(harness.outbox.isEmpty, isTrue);

      await harness.dispose();
    });
  });

  group('CallRepository.recordOutcome — offline', () {
    test('queues the outcome instead of losing it, and says so', () async {
      final harness = Harness();
      harness.api.offline = true;

      final result = await harness.dependencies.callRepository.recordOutcome(
        callId: 900,
        status: 'connected',
        leadName: 'Ramesh Kumar',
        durationSeconds: 143,
      );

      expect(result.queued, isTrue);
      expect(result.message, contains('back online'));
      expect(harness.outbox.pendingCount, 1);

      final entry = harness.outbox.entries.single;
      expect(entry.method, 'PATCH');
      expect(entry.path, '/calls/900');
      expect(entry.body['status'], 'connected');
      expect(entry.body['duration_seconds'], 143);
      expect(entry.label, 'Call outcome for Ramesh Kumar');

      await harness.dispose();
    });

    test('the queued ended_at is when the call ended, not when it is finally sent', () async {
      final harness = Harness();
      harness.api.offline = true;
      final endedAt = DateTime.utc(2026, 8, 12, 5, 32, 23);

      await harness.dependencies.callRepository.recordOutcome(
        callId: 900,
        status: 'connected',
        leadName: 'Ramesh Kumar',
        endedAt: endedAt,
      );

      expect(harness.outbox.entries.single.body['ended_at'], '2026-08-12T05:32:23.000Z');

      await harness.dispose();
    });

    test('end to end: offline write, reconnect, sent exactly once', () async {
      final harness = Harness(online: false);
      harness.api.offline = true;

      await harness.dependencies.callRepository.recordOutcome(
        callId: 900,
        status: 'connected',
        leadName: 'Ramesh Kumar',
        durationSeconds: 143,
      );
      expect(harness.outbox.pendingCount, 1);
      expect(harness.api.countOf('PATCH', '/calls/900'), 0);

      // Back in signal.
      harness.api.offline = false;
      harness.api.reply('PATCH', '/calls/900', data: completedCallJson());
      harness.dependencies.flusher.start();
      harness.connectivity.setOnline(true);
      await Future<void>.delayed(Duration.zero);
      await Future<void>.delayed(Duration.zero);

      expect(harness.api.countOf('PATCH', '/calls/900'), 1);
      expect(harness.outbox.isEmpty, isTrue);

      // A later flush must not resend anything.
      await harness.dependencies.flusher.flush();
      expect(harness.api.countOf('PATCH', '/calls/900'), 1);

      await harness.dispose();
    });

    test('three offline outcomes drain in the order they were recorded', () async {
      final harness = Harness();
      harness.api.offline = true;

      for (final id in <int>[901, 902, 903]) {
        await harness.dependencies.callRepository.recordOutcome(
          callId: id,
          status: 'no_answer',
          leadName: 'Lead $id',
        );
      }

      harness.api.offline = false;
      for (final id in <int>[901, 902, 903]) {
        harness.api.reply('PATCH', '/calls/$id', data: completedCallJson(id: id));
      }

      final report = await harness.dependencies.flusher.flush();

      expect(report.sent, 3);
      expect(
        harness.api.delivered
            .where((r) => r.method == 'PATCH')
            .map((r) => r.path)
            .toList(),
        <String>['/calls/901', '/calls/902', '/calls/903'],
      );

      await harness.dispose();
    });
  });
}
