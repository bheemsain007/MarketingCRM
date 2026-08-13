import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:marketing_crm_mobile/screens/home_shell.dart';
import 'package:marketing_crm_mobile/screens/lead_list_screen.dart';
import 'package:marketing_crm_mobile/screens/login_screen.dart';

import '../support/fixtures.dart';
import '../support/harness.dart';
import '../support/pump.dart';
import '../support/scripted_api.dart';

void main() {
  testWidgets('with no stored token the app opens on the login screen', (tester) async {
    await pumpApp(tester, Harness());

    expect(find.byType(LoginScreen), findsOneWidget);
    expect(find.byType(HomeShell), findsNothing);
  });

  testWidgets('a valid sign-in lands on the lead list', (tester) async {
    final harness = Harness();
    harness.api
      ..reply('POST', '/auth/login', data: <String, dynamic>{
        'token': '1|Vo1FH1oh',
        'token_type': 'Bearer',
        'work_session_id': 1,
        'user': userJson(),
      })
      ..reply('GET', '/leads', data: listData(<Map<String, dynamic>>[leadJson()]))
      ..reply('GET', '/follow-ups', data: listData(const <Map<String, dynamic>>[]));

    await pumpApp(tester, harness);

    await tester.enterText(find.byKey(LoginScreen.emailFieldKey), 'priya@example.com');
    await tester.enterText(find.byKey(LoginScreen.passwordFieldKey), 'secret');
    await tester.tap(find.byKey(LoginScreen.submitButtonKey));
    await settle(tester);

    expect(find.byType(HomeShell), findsOneWidget);
    expect(find.byType(LeadListScreen), findsOneWidget);
    expect(find.text('Ramesh Kumar'), findsOneWidget);
    expect(await harness.tokenStore.read(), '1|Vo1FH1oh');

  });

  testWidgets('bad credentials show the server message and stay on login', (tester) async {
    final harness = Harness();
    harness.api.reply('POST', '/auth/login',
        status: 401,
        success: false,
        message: 'These credentials do not match our records.',
        errors: <Map<String, dynamic>>[
          <String, dynamic>{'code': 'auth.unauthenticated', 'message': 'Invalid.'},
        ]);

    await pumpApp(tester, harness);

    await tester.enterText(find.byKey(LoginScreen.emailFieldKey), 'priya@example.com');
    await tester.enterText(find.byKey(LoginScreen.passwordFieldKey), 'wrong');
    await tester.tap(find.byKey(LoginScreen.submitButtonKey));
    await settle(tester);

    expect(find.byType(LoginScreen), findsOneWidget);
    expect(find.byKey(LoginScreen.errorKey), findsOneWidget);
    expect(find.text('These credentials do not match our records.'), findsOneWidget);

  });

  testWidgets('empty fields are caught before a request is made', (tester) async {
    final harness = await pumpApp(tester, Harness());

    await tester.tap(find.byKey(LoginScreen.submitButtonKey));
    await settle(tester);

    expect(find.text('Enter your email.'), findsOneWidget);
    expect(find.text('Enter your password.'), findsOneWidget);
    expect(harness.api.received, isEmpty);

  });

  testWidgets('a stored token restores the session without showing login', (tester) async {
    final harness = Harness(token: '1|stored');
    harness.api
      ..reply('GET', '/auth/me', data: userJson())
      ..reply('GET', '/leads', data: listData(<Map<String, dynamic>>[leadJson()]))
      ..reply('GET', '/follow-ups', data: listData(const <Map<String, dynamic>>[]));

    await pumpApp(tester, harness);

    expect(find.byType(HomeShell), findsOneWidget);
    expect(find.byType(LoginScreen), findsNothing);

  });

  testWidgets('a 401 during the session returns to login with the reason', (tester) async {
    final harness = Harness(token: '1|stored');
    harness.api
      ..reply('GET', '/auth/me', data: userJson())
      ..reply('GET', '/leads', data: listData(<Map<String, dynamic>>[leadJson()]))
      ..reply('GET', '/follow-ups', data: listData(const <Map<String, dynamic>>[]));

    await pumpApp(tester, harness);
    expect(find.byType(HomeShell), findsOneWidget);

    // The token dies. The next pull-to-refresh discovers it.
    harness.api.reply('GET', '/leads',
        status: 401,
        success: false,
        message: 'Unauthenticated.',
        errors: <Map<String, dynamic>>[
          <String, dynamic>{'code': 'auth.unauthenticated', 'message': 'Unauthenticated.'},
        ]);

    await tester.fling(find.byType(ListView).first, const Offset(0, 400), 1000);
    await settle(tester, frames: 20);

    expect(find.byType(LoginScreen), findsOneWidget);
    expect(find.text('Your session has expired. Please sign in again.'), findsOneWidget);
    expect(await harness.tokenStore.read(), isNull);

  });

  testWidgets('signing out clears the token and returns to login', (tester) async {
    final harness = Harness(token: '1|stored');
    harness.api
      ..reply('GET', '/auth/me', data: userJson())
      ..reply('GET', '/leads', data: listData(const <Map<String, dynamic>>[]))
      ..reply('GET', '/follow-ups', data: listData(const <Map<String, dynamic>>[]))
      ..reply('POST', '/auth/logout', message: 'Signed out.');

    await pumpApp(tester, harness);

    await tester.tap(find.byKey(HomeShell.signOutKey));
    await settle(tester);

    expect(find.byType(LoginScreen), findsOneWidget);
    expect(await harness.tokenStore.read(), isNull);

  });
}
