import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:marketing_crm_mobile/models/outbound_message.dart';
import 'package:marketing_crm_mobile/screens/lead_detail_screen.dart';
import 'package:marketing_crm_mobile/screens/message_compose_sheet.dart';

import '../support/fixtures.dart';
import '../support/harness.dart';
import '../support/pump.dart';
import '../support/scripted_api.dart';

/// The digits of the fixture lead's number, separator-free.
///
/// Assertions compare against this rather than against a formatted string, so
/// re-formatting the number (`+91 98765 00001`, `098765-00001`, `(98765) 00001`)
/// cannot sneak it back onto the screen past the test.
const String leadPhoneDigits = '9876500001';

/// Every string the tree would actually paint.
///
/// `RichText` is what a `Text` becomes once built, so walking these covers
/// `Text`, `Text.rich`, list-tile titles and subtitles, button labels and chip
/// labels in one pass — including any widget a future change adds without
/// telling this test about it.
Iterable<String> renderedStrings(WidgetTester tester) sync* {
  for (final widget in tester.allWidgets) {
    if (widget is RichText) {
      yield widget.text.toPlainText();
    } else if (widget is EditableText) {
      yield widget.controller.text;
    }
  }
}

/// The SEC-PII-04 regression guard: the number is held, never shown.
void expectPhoneNeverRendered(WidgetTester tester) {
  for (final text in renderedStrings(tester)) {
    final digits = text.replaceAll(RegExp('[^0-9]'), '');

    expect(
      digits.contains(leadPhoneDigits),
      isFalse,
      reason: 'The lead phone number is rendered in: "$text"',
    );
  }
}

/// Signs in with a stored token and opens the lead detail for lead 42.
Future<Harness> openLeadDetail(
  WidgetTester tester, {
  Map<String, dynamic>? lead,
  Map<String, dynamic>? callability,
}) async {
  final leadPayload = lead ?? leadJson();
  final harness = Harness(token: '1|stored');

  harness.api
    ..reply('GET', '/auth/me', data: userJson())
    ..reply('GET', '/follow-ups', data: listData(const <Map<String, dynamic>>[]))
    ..reply('GET', '/leads', data: listData(<Map<String, dynamic>>[leadPayload]))
    ..reply('GET', '/leads/42', data: leadPayload)
    ..reply('GET', '/leads/42/callability', data: callability ?? callableJson())
    ..reply('GET', '/leads/42/calls', data: listData(const <Map<String, dynamic>>[]));

  await pumpApp(tester, harness);

  await tester.tap(find.text(leadPayload['name'] as String));
  await settle(tester);

  return harness;
}

/// Composes and sends on [channel], returning once the snack bar is up.
Future<void> sendOn(WidgetTester tester, MessageChannel channel, {String body = 'Hello'}) async {
  await tester.tap(find.byKey(LeadDetailScreen.messageButtonKey(channel)));
  await settle(tester);

  await tester.enterText(find.byKey(MessageComposeSheet.bodyFieldKey), body);
  await tester.tap(find.byKey(MessageComposeSheet.sendButtonKey));
  await settle(tester);
}

void main() {
  group('the four contact actions', () {
    testWidgets('Call, SMS, WhatsApp and Email all appear on a contactable lead',
        (tester) async {
      await openLeadDetail(tester);

      expect(find.byKey(LeadDetailScreen.callButtonKey), findsOneWidget);
      expect(find.byKey(LeadDetailScreen.smsButtonKey), findsOneWidget);
      expect(find.byKey(LeadDetailScreen.whatsappButtonKey), findsOneWidget);
      expect(find.byKey(LeadDetailScreen.emailButtonKey), findsOneWidget);
      expect(find.byKey(LeadDetailScreen.noEmailNoticeKey), findsNothing);
    });

    testWidgets('a lead with no email address is offered no Email action',
        (tester) async {
      await openLeadDetail(tester, lead: leadJson(email: null));

      expect(find.byKey(LeadDetailScreen.emailButtonKey), findsNothing);
      expect(find.byKey(LeadDetailScreen.noEmailNoticeKey), findsOneWidget);

      // The other three are unaffected — they address the phone, not the inbox.
      expect(find.byKey(LeadDetailScreen.callButtonKey), findsOneWidget);
      expect(find.byKey(LeadDetailScreen.smsButtonKey), findsOneWidget);
      expect(find.byKey(LeadDetailScreen.whatsappButtonKey), findsOneWidget);
    });

    testWidgets('only email offers a subject line', (tester) async {
      final harness = await openLeadDetail(tester);
      harness.api.reply('POST', '/leads/42/messages', status: 202, data: queuedMessageJson());

      await tester.tap(find.byKey(LeadDetailScreen.emailButtonKey));
      await settle(tester);
      expect(find.byKey(MessageComposeSheet.subjectFieldKey), findsOneWidget);

      await tester.tap(find.byKey(MessageComposeSheet.sendButtonKey));
      await settle(tester);

      await tester.tap(find.byKey(LeadDetailScreen.smsButtonKey));
      await settle(tester);
      expect(find.byKey(MessageComposeSheet.subjectFieldKey), findsNothing);
    });
  });

  group('sending goes through the CRM', () {
    testWidgets('a successful send shows the queued confirmation from the server',
        (tester) async {
      final harness = await openLeadDetail(tester);
      harness.api.reply('POST', '/leads/42/messages',
          status: 202,
          message: 'Message queued for sending.',
          data: queuedMessageJson());

      await sendOn(tester, MessageChannel.sms, body: 'Sharing the quotation shortly.');

      expect(find.text('Message queued for sending.'), findsOneWidget);

      final request = harness.api.lastOf('POST', '/leads/42/messages')!;
      expect(request.json['channel'], 'sms');
      expect(request.json['body'], 'Sharing the quotation shortly.');
    });

    testWidgets('an unconfigured provider is admitted, not dressed up as delivery',
        (tester) async {
      final harness = await openLeadDetail(tester);
      harness.api.reply('POST', '/leads/42/messages',
          status: 202,
          message: 'Message queued. No provider is configured for this channel yet, '
              'so it will be recorded but not delivered.',
          data: queuedMessageJson(channel: 'whatsapp', channelLabel: 'WhatsApp', provider: 'log'));

      await sendOn(tester, MessageChannel.whatsapp);

      expect(find.textContaining('No provider is configured'), findsOneWidget);
      expect(find.textContaining('not delivered'), findsOneWidget);
    });

    testWidgets('a rejected body reopens the sheet carrying the server sentence',
        (tester) async {
      final harness = await openLeadDetail(tester);
      harness.api.reply('POST', '/leads/42/messages',
          status: 422,
          success: false,
          message: 'The given data was invalid.',
          errors: <Map<String, dynamic>>[
            <String, dynamic>{
              'code': 'validation.failed',
              'field': 'body',
              'message': 'Provide a body or choose a template.',
            },
          ]);

      await sendOn(tester, MessageChannel.sms, body: '');

      expect(find.byKey(MessageComposeSheet.errorKey), findsOneWidget);
      expect(find.text('The given data was invalid.'), findsOneWidget);
      expect(find.byKey(MessageComposeSheet.bodyFieldKey), findsOneWidget);
    });
  });

  group('a suppressed lead is refused on every channel', () {
    testWidgets('the call button is replaced by the gate\'s own sentence', (tester) async {
      await openLeadDetail(tester, callability: suppressedJson());

      expect(find.byKey(LeadDetailScreen.callButtonKey), findsNothing);
      expect(find.byKey(LeadDetailScreen.refusalKey), findsOneWidget);
      expect(
        find.text('This lead is on the do-not-contact list and must not be called.'),
        findsOneWidget,
      );
    });

    testWidgets('SMS, WhatsApp and email each show the 403 the server returned',
        (tester) async {
      // The message channels are NOT greyed out by the callability endpoint:
      // that endpoint answers a question about calling, and inferring a
      // messaging verdict from it would be this app owning BR-DNC-01. They ask
      // by sending, and report what comes back — per channel, with the
      // channel's own name in the sentence.
      final harness = await openLeadDetail(tester, callability: suppressedJson());

      for (final channel in MessageChannel.values) {
        harness.api.reply('POST', '/leads/42/messages',
            status: 403,
            success: false,
            message: 'This lead cannot be contacted on ${channel.label}: '
                'Customer requested.',
            errors: <Map<String, dynamic>>[
              <String, dynamic>{'code': 'dnc.suppressed', 'message': 'Suppressed.'},
            ]);

        await sendOn(tester, channel);

        expect(
          find.text('This lead cannot be contacted on ${channel.label}: Customer requested.'),
          findsOneWidget,
          reason: 'the ${channel.label} refusal was not shown verbatim',
        );

        // Refused means refused: nothing opened, nothing was left half-sent.
        expect(find.byKey(MessageComposeSheet.sendButtonKey), findsNothing);
      }
    });
  });

  group('SEC-PII-04 — the number is held, never shown', () {
    testWidgets('the lead detail renders the number nowhere', (tester) async {
      await openLeadDetail(tester);

      // Sanity: we are looking at the right screen, and it has plenty of text.
      expect(find.byType(LeadDetailScreen), findsOneWidget);
      expect(find.text('Ramesh Kumar'), findsWidgets);

      expectPhoneNeverRendered(tester);
    });

    testWidgets('the lead list renders the number nowhere, even with no company or city',
        (tester) async {
      // The old subtitle fell back to the phone number when there was nothing
      // else to show. That fallback is the exact regression this guards.
      final harness = Harness(token: '1|stored');
      harness.api
        ..reply('GET', '/auth/me', data: userJson())
        ..reply('GET', '/follow-ups', data: listData(const <Map<String, dynamic>>[]))
        ..reply('GET', '/leads',
            data: listData(<Map<String, dynamic>>[
              leadJson(),
              leadJson(id: 43, name: 'Anita Desai', company: null, city: null),
            ]));

      await pumpApp(tester, harness);

      expect(find.text('Ramesh Kumar'), findsOneWidget);
      expect(find.text('Anita Desai'), findsOneWidget);

      // Nothing left to say about her but who owns her — and it is said,
      // rather than the row carrying a blank line. Scoped to her own tile,
      // because the signed-in user's name is also on screen in the app bar.
      expect(
        find.descendant(
          of: find.widgetWithText(ListTile, 'Anita Desai'),
          matching: find.text('Priya Sharma'),
        ),
        findsOneWidget,
      );

      expectPhoneNeverRendered(tester);
    });

    testWidgets('a queued send does not leak the recipient back onto the screen',
        (tester) async {
      // `MessageResource` sends `recipient`, which for SMS is the E.164 number.
      // Echoing the response into a confirmation would put it back on screen.
      final harness = await openLeadDetail(tester);
      harness.api.reply('POST', '/leads/42/messages',
          status: 202,
          message: 'Message queued for sending.',
          data: queuedMessageJson());

      await sendOn(tester, MessageChannel.sms);

      expect(find.text('Message queued for sending.'), findsOneWidget);
      expectPhoneNeverRendered(tester);
    });
  });
}
