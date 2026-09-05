import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:marketing_crm_mobile/core/offline/outbox_entry.dart';
import 'package:marketing_crm_mobile/widgets/outbox_banner.dart';

import '../support/fixtures.dart';
import '../support/harness.dart';
import '../support/pump.dart';
import '../support/scripted_api.dart';

/// Fails only the outbox's own request — a transport failure, same as a real
/// dead radio — while every other route the app needs at launch (`/auth/me`,
/// `/leads`, `/follow-ups`) stays reachable. `ScriptedApi.offline` is
/// deliberately not used here: it is all-or-nothing, and would fail sign-in
/// itself before `HomeShell` ever mounted.
void failDelivery(Harness harness) {
  harness.api.on('PATCH', '/calls/900', (_) => throw const SocketException('offline'));
}

/// The banner is `HomeShell`'s only surface for the offline queue Phase 33
/// built (`mobile/lib/core/offline/`) — a telecaller who walked out of signal
/// has no other way to see that outcomes are still waiting, or to push them by
/// hand. `Outbox` and `OutboxFlusher` are covered exhaustively at the unit
/// level; this file is the widget wired to them.
OutboxEntry queuedOutcome(String id, {bool failed = false}) {
  return OutboxEntry(
    id: id,
    kind: OutboxKind.callOutcome,
    method: 'PATCH',
    path: '/calls/900',
    body: const <String, dynamic>{'status': 'connected', 'duration_seconds': 90},
    idempotencyKey: 'idem-$id',
    queuedAt: DateTime.utc(2026, 8, 12, 11),
    label: 'Call outcome for Ramesh Kumar',
    permanentlyFailed: failed,
  );
}

/// Signs in with a stored token and opens the home shell, same shape the
/// other widget-test files use.
Future<Harness> openHomeShell(WidgetTester tester, {Harness? harness}) async {
  final h = harness ?? Harness(token: '1|stored');

  h.api
    ..reply('GET', '/auth/me', data: userJson())
    ..reply('GET', '/follow-ups', data: listData(const <Map<String, dynamic>>[]))
    ..reply('GET', '/leads', data: listData(const <Map<String, dynamic>>[]));

  await pumpApp(tester, h);

  return h;
}

void main() {
  group('OutboxBanner', () {
    testWidgets('is absent when the outbox is empty', (tester) async {
      await openHomeShell(tester);

      expect(find.byKey(OutboxBanner.bannerKey), findsNothing);
    });

    testWidgets('shows the pending count when a write is still queued', (tester) async {
      final harness = Harness(token: '1|stored');
      // HomeShell auto-flushes on launch; this proves that attempt cannot
      // silently clear an entry it could not actually deliver.
      failDelivery(harness);
      await harness.outbox.add(queuedOutcome('a'));

      await openHomeShell(tester, harness: harness);

      expect(find.byKey(OutboxBanner.bannerKey), findsOneWidget);
      expect(find.text('1 waiting to sync'), findsOneWidget);
      expect(find.byKey(OutboxBanner.retryButtonKey), findsOneWidget);
      expect(harness.outbox.pendingCount, 1);
    });

    testWidgets('shows the rejected count for a permanently-failed entry, distinctly from pending',
        (tester) async {
      final harness = Harness(token: '1|stored');
      await harness.outbox.add(queuedOutcome('a'));
      await harness.outbox.add(queuedOutcome('b', failed: true));
      // The pending one succeeds on launch's auto-flush; the failed one is
      // skipped by the flusher (`if (entry.permanentlyFailed) continue`), so it
      // is not retried into oblivion.
      harness.api.reply('PATCH', '/calls/900', data: completedCallJson());

      await openHomeShell(tester, harness: harness);

      expect(find.text('1 rejected by the CRM'), findsOneWidget);
      expect(harness.outbox.pendingCount, 0);
      expect(harness.outbox.failedCount, 1);
    });

    testWidgets('"Send now" drives a flush that delivers the queued entry', (tester) async {
      final harness = Harness(token: '1|stored');
      failDelivery(harness);
      await harness.outbox.add(queuedOutcome('a'));

      await openHomeShell(tester, harness: harness);
      // Launch's own auto-flush already tried and could not deliver it.
      expect(find.text('1 waiting to sync'), findsOneWidget);

      harness.api.reply('PATCH', '/calls/900', data: completedCallJson());

      await tester.tap(find.byKey(OutboxBanner.retryButtonKey));
      await settle(tester);

      // Two attempts total (launch's failed one, then this tap's), but the
      // queue is what matters here: the entry that could not go out at launch
      // is gone once the network is actually reachable, and the banner reflects
      // that without a restart.
      expect(harness.api.attemptsOf('PATCH', '/calls/900'), 2);
      expect(harness.outbox.isEmpty, isTrue);
      expect(find.byKey(OutboxBanner.bannerKey), findsNothing);
    });
  });
}
