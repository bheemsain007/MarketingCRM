import 'package:flutter_test/flutter_test.dart';
import 'package:marketing_crm_mobile/screens/home_shell.dart';

import '../support/fixtures.dart';
import '../support/harness.dart';
import '../support/pump.dart';
import '../support/scripted_api.dart';

/// Signs in with a stored token and opens the home shell, same shape the
/// other widget-test files use (see outbox_banner_test.dart).
Future<Harness> openHomeShell(
  WidgetTester tester, {
  Harness? harness,
  bool hasOpenSession = true,
  bool isOnBreak = false,
}) async {
  final h = harness ?? Harness(token: '1|stored');

  h.api
    ..reply('GET', '/auth/me', data: userJson())
    ..reply('GET', '/follow-ups', data: listData(const <Map<String, dynamic>>[]))
    ..reply('GET', '/leads', data: listData(const <Map<String, dynamic>>[]))
    ..reply('GET', '/attendance/status', data: <String, dynamic>{
      'has_open_session': hasOpenSession,
      'is_on_break': isOnBreak,
      'break_started_at': null,
    });

  await pumpApp(tester, h);
  await settle(tester);

  return h;
}

void main() {
  group('the break toggle (FR-ATT-04)', () {
    testWidgets('is absent with no open session - nothing to start a break on', (tester) async {
      await openHomeShell(tester, hasOpenSession: false);

      expect(find.byKey(HomeShell.breakToggleKey), findsNothing);
    });

    testWidgets('offers "Take a break" for an open session that is not on break', (tester) async {
      await openHomeShell(tester);

      expect(find.byKey(HomeShell.breakToggleKey), findsOneWidget);
      expect(find.byTooltip('Take a break'), findsOneWidget);
    });

    testWidgets('offers "End break" when the status endpoint reports one already running',
        (tester) async {
      await openHomeShell(tester, isOnBreak: true);

      expect(find.byTooltip('End break'), findsOneWidget);
    });

    testWidgets('tapping it starts a break and flips the label without a restart', (tester) async {
      final harness = await openHomeShell(tester);
      harness.api.reply('POST', '/attendance/breaks/start', message: 'Break started.');

      await tester.tap(find.byKey(HomeShell.breakToggleKey));
      await settle(tester);

      expect(harness.api.countOf('POST', '/attendance/breaks/start'), 1);
      expect(find.byTooltip('End break'), findsOneWidget);
    });

    testWidgets('tapping it while on break stops the break and flips the label back',
        (tester) async {
      final harness = await openHomeShell(tester, isOnBreak: true);
      harness.api.reply('POST', '/attendance/breaks/stop', message: 'Break ended.');

      await tester.tap(find.byKey(HomeShell.breakToggleKey));
      await settle(tester);

      expect(harness.api.countOf('POST', '/attendance/breaks/stop'), 1);
      expect(find.byTooltip('Take a break'), findsOneWidget);
    });

    testWidgets('a refusal shows the servers own sentence and leaves the label unchanged',
        (tester) async {
      final harness = await openHomeShell(tester);
      harness.api.reply(
        'POST',
        '/attendance/breaks/start',
        status: 422,
        success: false,
        message: 'A break is already in progress.',
      );

      await tester.tap(find.byKey(HomeShell.breakToggleKey));
      await settle(tester);

      expect(find.text('A break is already in progress.'), findsOneWidget);
      // Still "Take a break" - the failed attempt did not flip the label.
      expect(find.byTooltip('Take a break'), findsOneWidget);
    });
  });
}
