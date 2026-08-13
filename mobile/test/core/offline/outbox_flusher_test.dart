import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:marketing_crm_mobile/core/offline/outbox_entry.dart';

import '../../support/fixtures.dart';
import '../../support/harness.dart';
import '../../support/scripted_api.dart';

OutboxEntry queued(String id) {
  return OutboxEntry(
    id: id,
    kind: OutboxKind.callOutcome,
    method: 'PATCH',
    path: '/calls/900',
    body: <String, dynamic>{'status': 'connected', 'duration_seconds': 143},
    idempotencyKey: 'idem-$id',
    queuedAt: DateTime.utc(2026, 8, 12, 11),
    label: 'Call outcome for Ramesh Kumar',
  );
}

void main() {
  group('OutboxFlusher', () {
    test('sends a queued outcome and clears it', () async {
      final harness = Harness();
      harness.api.reply('PATCH', '/calls/900', data: completedCallJson());
      await harness.outbox.add(queued('a'));

      final report = await harness.dependencies.flusher.flush();

      expect(report.sent, 1);
      expect(report.remaining, 0);
      expect(harness.outbox.isEmpty, isTrue);
      expect(harness.api.countOf('PATCH', '/calls/900'), 1);

      await harness.dispose();
    });

    test('replays the SAME Idempotency-Key it was queued with', () async {
      // A key regenerated per attempt would defeat the whole mechanism: the
      // server could not tell a retry from a second call.
      final harness = Harness();
      harness.api.reply('PATCH', '/calls/900', data: completedCallJson());
      await harness.outbox.add(queued('a'));

      await harness.dependencies.flusher.flush();

      expect(harness.api.lastOf('PATCH', '/calls/900')!.header('idempotency-key'), 'idem-a');

      await harness.dispose();
    });

    test('a 409 counts as delivered — the outcome is already on the record', () async {
      // Write-once: this is what a duplicate delivery looks like from the
      // client side, and treating it as a failure would leave the entry
      // retrying forever.
      final harness = Harness();
      harness.api.reply('PATCH', '/calls/900',
          status: 409, success: false, message: 'This call already has an outcome.');
      await harness.outbox.add(queued('a'));

      final report = await harness.dependencies.flusher.flush();

      expect(report.alreadyApplied, 1);
      expect(report.sent, 0);
      expect(harness.outbox.isEmpty, isTrue);

      await harness.dispose();
    });

    test('still offline: keeps the entry, counts the attempt, sends nothing twice', () async {
      final harness = Harness(online: false);
      harness.api.offline = true;
      await harness.outbox.add(queued('a'));

      final report = await harness.dependencies.flusher.flush();

      expect(report.stoppedBecauseOffline, isTrue);
      expect(report.sent, 0);
      expect(harness.outbox.pendingCount, 1);
      expect(harness.outbox.entries.single.attempts, 1);
      expect(harness.outbox.entries.single.permanentlyFailed, isFalse);

      // Now the network comes back.
      harness.api.offline = false;
      harness.api.reply('PATCH', '/calls/900', data: completedCallJson());

      final second = await harness.dependencies.flusher.flush();

      expect(second.sent, 1);
      expect(harness.outbox.isEmpty, isTrue);
      // Tried twice, landed once. That is the guarantee that matters.
      expect(harness.api.attemptsOf('PATCH', '/calls/900'), 2);
      expect(harness.api.countOf('PATCH', '/calls/900'), 1);

      await harness.dispose();
    });

    test('stops at the first undeliverable entry rather than reordering the queue', () async {
      final harness = Harness();
      harness.api.offline = true;
      await harness.outbox.add(queued('a'));
      await harness.outbox.add(queued('b'));
      await harness.outbox.add(queued('c'));

      await harness.dependencies.flusher.flush();

      // One attempt, not three: the drain halted instead of burning through
      // every entry against a network that is plainly down.
      expect(harness.api.attemptsOf('PATCH', '/calls/900'), 1);
      expect(harness.outbox.entries.map((e) => e.attempts).toList(), <int>[1, 0, 0]);
      expect(harness.outbox.entries.map((e) => e.id).toList(), <String>['a', 'b', 'c']);

      await harness.dispose();
    });

    test('a 422 is kept and flagged, not retried forever and not silently dropped', () async {
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
      await harness.outbox.add(queued('a'));

      final first = await harness.dependencies.flusher.flush();

      expect(first.failedPermanently, 1);
      expect(harness.outbox.failedCount, 1);
      expect(harness.outbox.entries.single.lastError, 'When should this lead be called back?');

      // A second flush must not touch it again — the identical request would
      // get the identical refusal.
      final second = await harness.dependencies.flusher.flush();

      expect(second.total, 0);
      expect(harness.api.countOf('PATCH', '/calls/900'), 1);

      await harness.dispose();
    });

    test('a 401 defers rather than discarding — the work outlives the session', () async {
      final harness = Harness();
      harness.api.reply('PATCH', '/calls/900',
          status: 401, success: false, message: 'Unauthenticated.');
      await harness.outbox.add(queued('a'));

      final report = await harness.dependencies.flusher.flush();

      expect(report.stoppedBecauseOffline, isTrue);
      expect(harness.outbox.pendingCount, 1);
      expect(harness.outbox.entries.single.permanentlyFailed, isFalse);

      await harness.dispose();
    });

    test('two concurrent flushes send each entry exactly once', () async {
      // The realistic trigger: connectivity returns at the same moment the
      // telecaller taps "Send now".
      final harness = Harness();
      harness.api.reply('PATCH', '/calls/900', data: completedCallJson());
      await harness.outbox.add(queued('a'));

      final results = await Future.wait(<Future<Object>>[
        harness.dependencies.flusher.flush(),
        harness.dependencies.flusher.flush(),
      ]);

      expect(harness.api.countOf('PATCH', '/calls/900'), 1);
      expect(harness.outbox.isEmpty, isTrue);
      // One of the two was turned away by the single-flight latch.
      expect(results.length, 2);

      await harness.dispose();
    });

    test('flushes when connectivity is restored', () async {
      final harness = Harness(online: false);
      harness.api.reply('PATCH', '/calls/900', data: completedCallJson());
      await harness.outbox.add(queued('a'));

      harness.dependencies.flusher.start();
      harness.connectivity.setOnline(true);

      // Let the listener's async flush complete.
      await Future<void>.delayed(Duration.zero);
      await Future<void>.delayed(Duration.zero);

      expect(harness.api.countOf('PATCH', '/calls/900'), 1);
      expect(harness.outbox.isEmpty, isTrue);

      await harness.dispose();
    });

    test('a 500 is retryable — kept, then delivered on the next attempt', () async {
      final harness = Harness();
      harness.api.sequence('PATCH', '/calls/900', <http.Response>[
        http.Response(
          envelopeJson(success: false, message: 'Server error.'),
          500,
          headers: const <String, String>{'content-type': 'application/json'},
        ),
        http.Response(
          envelopeJson(data: completedCallJson()),
          200,
          headers: const <String, String>{'content-type': 'application/json'},
        ),
      ]);
      await harness.outbox.add(queued('a'));

      final first = await harness.dependencies.flusher.flush();
      expect(first.sent, 0);
      expect(harness.outbox.pendingCount, 1);

      final second = await harness.dependencies.flusher.flush();
      expect(second.sent, 1);
      expect(harness.outbox.isEmpty, isTrue);

      await harness.dispose();
    });
  });
}
