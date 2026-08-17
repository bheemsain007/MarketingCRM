import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:marketing_crm_mobile/models/outbound_message.dart';
import 'package:marketing_crm_mobile/screens/home_shell.dart';
import 'package:marketing_crm_mobile/screens/lead_detail_screen.dart';
import 'package:marketing_crm_mobile/screens/message_compose_sheet.dart';
import 'package:marketing_crm_mobile/screens/template_management_screen.dart';

import '../support/fixtures.dart';
import '../support/harness.dart';
import '../support/pump.dart';
import '../support/scripted_api.dart';

/// Signs in with a stored token and opens the lead detail for lead 42 — the
/// same shape `lead_contact_actions_test.dart` uses, kept local rather than
/// imported across test files.
Future<Harness> openLeadDetail(WidgetTester tester, {List<String>? permissions}) async {
  final harness = Harness(token: '1|stored');

  harness.api
    ..reply('GET', '/auth/me', data: userJson(permissions: permissions ?? const <String>[
      'leads.view', 'calls.view', 'calls.create', 'messages.send',
      'templates.view', 'follow_ups.view',
    ]))
    ..reply('GET', '/follow-ups', data: listData(const <Map<String, dynamic>>[]))
    ..reply('GET', '/leads', data: listData(<Map<String, dynamic>>[leadJson()]))
    ..reply('GET', '/leads/42', data: leadJson())
    ..reply('GET', '/leads/42/callability', data: callableJson())
    ..reply('GET', '/leads/42/calls', data: listData(const <Map<String, dynamic>>[]));

  await pumpApp(tester, harness);

  await tester.tap(find.text('Ramesh Kumar'));
  await settle(tester);

  return harness;
}

Future<Harness> openHomeShell(WidgetTester tester, {List<String>? permissions}) async {
  final harness = Harness(token: '1|stored');

  harness.api
    ..reply('GET', '/auth/me', data: userJson(permissions: permissions ?? const <String>[]))
    ..reply('GET', '/follow-ups', data: listData(const <Map<String, dynamic>>[]))
    ..reply('GET', '/leads', data: listData(<Map<String, dynamic>>[leadJson()]));

  await pumpApp(tester, harness);

  return harness;
}

void main() {
  group('the template picker inside compose', () {
    testWidgets('choosing a template fills the fields with the RENDERED text, not the raw tokens',
        (tester) async {
      final harness = await openLeadDetail(tester);
      harness.api
        ..reply('GET', '/templates', data: listData(<Map<String, dynamic>>[templateJson()]))
        ..reply(
          'GET',
          '/templates/500/preview',
          data: templatePreviewJson(
            body: 'Hi Ramesh Kumar, following up on the quote for Kumar Textiles.',
          ),
        );

      await tester.tap(find.byKey(LeadDetailScreen.messageButtonKey(MessageChannel.sms)));
      await settle(tester);

      await tester.tap(find.byKey(MessageComposeSheet.templateButtonKey));
      await settle(tester);

      expect(find.text('Quotation follow-up'), findsOneWidget);

      await tester.tap(find.byKey(MessageComposeSheet.templateOptionKey(500)));
      await settle(tester);

      // The rendered sentence, not the template's own token text — proof the
      // sheet used the preview endpoint rather than substituting locally.
      final bodyField = tester.widget<TextField>(find.byKey(MessageComposeSheet.bodyFieldKey));
      expect(bodyField.controller!.text, 'Hi Ramesh Kumar, following up on the quote for Kumar Textiles.');
      expect(bodyField.controller!.text.contains('{{'), isFalse);

      // The preview was asked for THIS lead specifically.
      final previewRequest = harness.api.lastOf('GET', '/templates/500/preview');
      expect(previewRequest?.query['lead_id'], '42');
    });

    testWidgets('an email template fills the subject too', (tester) async {
      final harness = await openLeadDetail(tester);
      harness.api
        ..reply(
          'GET',
          '/templates',
          data: listData(<Map<String, dynamic>>[
            templateJson(
              id: 501,
              channel: 'email',
              channelLabel: 'Email',
              subject: 'Your {{ company }} quotation',
            ),
          ]),
        )
        ..reply(
          'GET',
          '/templates/501/preview',
          data: templatePreviewJson(
            templateId: 501,
            channel: 'email',
            subject: 'Your Kumar Textiles quotation',
            body: 'Please find the quotation attached.',
          ),
        );

      await tester.tap(find.byKey(LeadDetailScreen.messageButtonKey(MessageChannel.email)));
      await settle(tester);

      await tester.tap(find.byKey(MessageComposeSheet.templateButtonKey));
      await settle(tester);
      await tester.tap(find.byKey(MessageComposeSheet.templateOptionKey(501)));
      await settle(tester);

      final subjectField = tester.widget<TextField>(find.byKey(MessageComposeSheet.subjectFieldKey));
      expect(subjectField.controller!.text, 'Your Kumar Textiles quotation');
    });

    testWidgets('no templates for the channel shows an empty message, not an error',
        (tester) async {
      final harness = await openLeadDetail(tester);
      harness.api.reply('GET', '/templates', data: listData(const <Map<String, dynamic>>[]));

      await tester.tap(find.byKey(LeadDetailScreen.messageButtonKey(MessageChannel.whatsapp)));
      await settle(tester);
      await tester.tap(find.byKey(MessageComposeSheet.templateButtonKey));
      await settle(tester);

      expect(find.byKey(MessageComposeSheet.templateEmptyKey), findsOneWidget);
    });

    testWidgets('a template list that fails to load does not block composing by hand',
        (tester) async {
      await openLeadDetail(tester);
      // No /templates route scripted at all — ScriptedApi answers 404, which
      // the sheet must absorb rather than crash on.

      await tester.tap(find.byKey(LeadDetailScreen.messageButtonKey(MessageChannel.sms)));
      await settle(tester);
      await tester.tap(find.byKey(MessageComposeSheet.templateButtonKey));
      await settle(tester);

      // The form is still usable — the picker's failure did not tear down the
      // sheet underneath it.
      expect(find.byKey(MessageComposeSheet.bodyFieldKey), findsOneWidget);
      await tester.enterText(find.byKey(MessageComposeSheet.bodyFieldKey), 'Typed by hand.');
      expect(
        tester.widget<TextField>(find.byKey(MessageComposeSheet.bodyFieldKey)).controller!.text,
        'Typed by hand.',
      );
    });

    testWidgets('a template with no address for this lead is filled in but flagged',
        (tester) async {
      final harness = await openLeadDetail(tester);
      harness.api
        ..reply(
          'GET',
          '/templates',
          data: listData(<Map<String, dynamic>>[
            templateJson(id: 502, channel: 'email', channelLabel: 'Email'),
          ]),
        )
        ..reply(
          'GET',
          '/templates/502/preview',
          data: templatePreviewJson(templateId: 502, channel: 'email', isSendable: false),
        );

      await tester.tap(find.byKey(LeadDetailScreen.messageButtonKey(MessageChannel.email)));
      await settle(tester);
      await tester.tap(find.byKey(MessageComposeSheet.templateButtonKey));
      await settle(tester);
      await tester.tap(find.byKey(MessageComposeSheet.templateOptionKey(502)));
      await settle(tester);

      // Filled in regardless — the telecaller may still want to read or copy
      // it — but told, because sending it will 422.
      final bodyField = tester.widget<TextField>(find.byKey(MessageComposeSheet.bodyFieldKey));
      expect(bodyField.controller!.text, isNotEmpty);
      expect(find.text('This lead has no address on record for this channel yet.'), findsOneWidget);
    });
  });

  group('the templates entry point', () {
    testWidgets('is offered to a user holding templates.view', (tester) async {
      await openHomeShell(tester, permissions: const <String>['leads.view', 'templates.view']);

      expect(find.byKey(HomeShell.templatesKey), findsOneWidget);
    });

    testWidgets('is hidden from a user without it', (tester) async {
      await openHomeShell(tester, permissions: const <String>['leads.view']);

      expect(find.byKey(HomeShell.templatesKey), findsNothing);
    });
  });

  group('the template management screen', () {
    /// Stubs `GET /templates` **before** navigating, because the screen fetches
    /// on `initState` — a reply registered after the tap that opens it arrives
    /// too late for the request the screen already made.
    Future<Harness> openManagement(
      WidgetTester tester, {
      required List<String> permissions,
      List<Map<String, dynamic>> initialTemplates = const <Map<String, dynamic>>[],
    }) async {
      final harness = await openHomeShell(
        tester,
        permissions: <String>['leads.view', 'templates.view', ...permissions],
      );

      harness.api.reply('GET', '/templates', data: listData(initialTemplates));

      await tester.tap(find.byKey(HomeShell.templatesKey));
      await settle(tester);

      return harness;
    }

    testWidgets('lists templates with their channel', (tester) async {
      await openManagement(
        tester,
        permissions: const <String>[],
        initialTemplates: <Map<String, dynamic>>[
          templateJson(id: 1, name: 'Diwali offer', channel: 'sms', channelLabel: 'SMS'),
          templateJson(id: 2, name: 'Welcome email', channel: 'email', channelLabel: 'Email'),
        ],
      );

      expect(find.text('Diwali offer'), findsOneWidget);
      expect(find.text('Welcome email'), findsOneWidget);
    });

    testWidgets('a templates.view-only user gets no write controls', (tester) async {
      await openManagement(
        tester,
        permissions: const <String>[],
        initialTemplates: <Map<String, dynamic>>[templateJson(id: 1)],
      );

      expect(find.byKey(TemplateManagementScreen.addButtonKey), findsNothing);
      expect(find.byKey(TemplateManagementScreen.deactivateKey(1)), findsNothing);
    });

    testWidgets('a templates.manage user gets create and deactivate controls', (tester) async {
      await openManagement(
        tester,
        permissions: const <String>['templates.manage'],
        initialTemplates: <Map<String, dynamic>>[templateJson(id: 1)],
      );

      expect(find.byKey(TemplateManagementScreen.addButtonKey), findsOneWidget);
      expect(find.byKey(TemplateManagementScreen.deactivateKey(1)), findsOneWidget);
    });

    testWidgets('creating a template posts the form and refreshes the list', (tester) async {
      final harness =
          await openManagement(tester, permissions: const <String>['templates.manage']);

      harness.api.reply('POST', '/templates', status: 201, data: templateJson(id: 9, name: 'New offer'));

      await tester.tap(find.byKey(TemplateManagementScreen.addButtonKey));
      await settle(tester);

      await tester.enterText(find.byKey(const Key('templateForm.name')), 'New offer');
      await tester.enterText(find.byKey(const Key('templateForm.body')), 'Hello {{ lead_name }}');

      // Re-stub the list so the post-create refresh shows the new row.
      harness.api.reply(
        'GET',
        '/templates',
        data: listData(<Map<String, dynamic>>[templateJson(id: 9, name: 'New offer')]),
      );

      await tester.tap(find.byKey(const Key('templateForm.save')));
      await settle(tester);

      final created = harness.api.lastOf('POST', '/templates');
      expect(created, isNotNull);
      expect(find.text('New offer'), findsWidgets);
    });

    testWidgets('deactivating removes it from the default (active-only) view', (tester) async {
      final harness = await openManagement(
        tester,
        permissions: const <String>['templates.manage'],
        initialTemplates: <Map<String, dynamic>>[templateJson(id: 1, name: 'Retiring soon')],
      );

      expect(find.text('Retiring soon'), findsOneWidget);

      harness.api
        ..reply('DELETE', '/templates/1', message: 'Template deactivated.')
        ..reply('GET', '/templates', data: listData(const <Map<String, dynamic>>[]));

      await tester.tap(find.byKey(TemplateManagementScreen.deactivateKey(1)));
      await settle(tester);

      expect(harness.api.lastOf('DELETE', '/templates/1'), isNotNull);
      expect(find.text('Retiring soon'), findsNothing);
    });

    testWidgets('the inactive switch asks the server for inactive templates too', (tester) async {
      final harness = await openManagement(tester, permissions: const <String>[]);

      harness.api.reply(
        'GET',
        '/templates',
        data: listData(<Map<String, dynamic>>[templateJson(id: 1, isActive: false)]),
      );

      await tester.tap(find.byKey(TemplateManagementScreen.showInactiveKey));
      await settle(tester);

      final request = harness.api.lastOf('GET', '/templates');
      expect(request?.query['with_inactive'], '1');
    });
  });
}
