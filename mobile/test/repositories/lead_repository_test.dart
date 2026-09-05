import 'package:flutter_test/flutter_test.dart';
import 'package:marketing_crm_mobile/core/api/api_exception.dart';

import '../support/fixtures.dart';
import '../support/harness.dart';
import '../support/scripted_api.dart';

void main() {
  group('LeadRepository.list', () {
    test('reads items and meta out of the standard list envelope', () async {
      final harness = Harness();
      harness.api.reply('GET', '/leads',
          message: 'Leads retrieved.',
          data: listData(
            <Map<String, dynamic>>[
              leadJson(id: 1, name: 'Ramesh Kumar'),
              leadJson(id: 2, name: 'Anita Desai'),
            ],
            currentPage: 1,
            lastPage: 3,
            total: 54,
          ));

      final page = await harness.dependencies.leadRepository.list();

      expect(page.items, hasLength(2));
      expect(page.items.first.name, 'Ramesh Kumar');
      expect(page.items.first.phone, '+919876500001');
      expect(page.items.first.phoneFormatted, '+91 98765 00001');
      expect(page.items.first.assignedToName, 'Priya Sharma');
      expect(page.items.first.sourceName, 'Meta Lead Ads');
      expect(page.meta.total, 54);
      expect(page.hasMore, isTrue);

      await harness.dispose();
    });

    test('sends the search term to the server, and never filters locally', () async {
      final harness = Harness();
      harness.api.reply('GET', '/leads', data: listData(const <Map<String, dynamic>>[]));

      await harness.dependencies.leadRepository.list(page: 2, query: '  sharma  ');

      final request = harness.api.lastOf('GET', '/leads')!;
      expect(request.query['q'], 'sharma');
      expect(request.query['page'], '2');

      await harness.dispose();
    });

    test('an empty search term is omitted rather than sent as q=', () async {
      final harness = Harness();
      harness.api.reply('GET', '/leads', data: listData(const <Map<String, dynamic>>[]));

      await harness.dependencies.leadRepository.list(query: '   ');

      expect(harness.api.lastOf('GET', '/leads')!.query.containsKey('q'), isFalse);

      await harness.dispose();
    });

    test('a list response with no items array is a contract violation', () async {
      final harness = Harness();
      harness.api.reply('GET', '/leads', data: <String, dynamic>{'meta': <String, dynamic>{}});

      await expectLater(
        harness.dependencies.leadRepository.list(),
        throwsA(isA<MalformedResponseException>()),
      );

      await harness.dispose();
    });

    test("a colleague's lead is a 403 from the policy, reported as sent", () async {
      final harness = Harness();
      harness.api.reply('GET', '/leads/99',
          status: 403, success: false, message: 'You may not view this lead.');

      await expectLater(
        harness.dependencies.leadRepository.show(99),
        throwsA(isA<ForbiddenException>().having((e) => e.message, 'message', 'You may not view this lead.')),
      );

      await harness.dispose();
    });
  });

  group('LeadRepository.callability — the gate is the server, always', () {
    test('a callable lead comes back callable with no reason', () async {
      final harness = Harness();
      harness.api.reply('GET', '/leads/42/callability', data: callableJson());

      final result = await harness.dependencies.leadRepository.callability(42);

      expect(result.callable, isTrue);
      expect(result.reason, isNull);

      await harness.dispose();
    });

    test('a suppressed lead is refused with the DNC reason attached', () async {
      final harness = Harness();
      harness.api.reply('GET', '/leads/42/callability', data: suppressedJson());

      final result = await harness.dependencies.leadRepository.callability(42);

      expect(result.callable, isFalse);
      expect(result.reason, 'dnc.suppressed');
      expect(result.refusalMessage, 'This lead is on the do-not-contact list and must not be called.');

      await harness.dispose();
    });

    test('outside calling hours carries next_opening, so the attempt is deferred', () async {
      final harness = Harness();
      harness.api.reply('GET', '/leads/42/callability', data: outsideHoursJson());

      final result = await harness.dependencies.leadRepository.callability(42);

      expect(result.callable, isFalse);
      expect(result.reason, 'call.outside_calling_hours');
      expect(result.nextOpening, isNotNull);

      await harness.dispose();
    });

    test('a lead flagged is_suppressed=false is STILL refused if the gate says no', () async {
      // The denormalised flag is a list-filter cache that can lag (BR-DNC-01).
      // Nothing in this app is allowed to prefer it over the gate.
      final harness = Harness();
      harness.api.reply('GET', '/leads/42', data: leadJson(isSuppressed: false));
      harness.api.reply('GET', '/leads/42/callability', data: suppressedJson());

      final lead = await harness.dependencies.leadRepository.show(42);
      final callability = await harness.dependencies.leadRepository.callability(42);

      expect(lead.isSuppressed, isFalse);
      expect(callability.callable, isFalse);

      await harness.dispose();
    });

    test('offline fails CLOSED — no connection means no permission to dial', () async {
      final harness = Harness();
      harness.api.offline = true;

      final result = await harness.dependencies.leadRepository.callability(42);

      expect(result.callable, isFalse);
      expect(result.reason, 'client.offline');
      expect(result.refusalMessage, contains('Cannot confirm'));

      await harness.dispose();
    });

    test('a 500 also fails closed', () async {
      final harness = Harness();
      harness.api.reply('GET', '/leads/42/callability',
          status: 500, success: false, message: 'Server error.');

      final result = await harness.dependencies.leadRepository.callability(42);

      expect(result.callable, isFalse);

      await harness.dispose();
    });

    test('a malformed 200 (no data) also fails closed', () async {
      // Distinct code path from offline/5xx: no exception is thrown at all —
      // `envelope.dataMap` is simply null — so this proves the repository's own
      // `if (data == null)` branch refuses rather than crashing or defaulting
      // to callable.
      final harness = Harness();
      harness.api.reply('GET', '/leads/42/callability', data: null);

      final result = await harness.dependencies.leadRepository.callability(42);

      expect(result.callable, isFalse);

      await harness.dispose();
    });
  });

  group('LeadRepository.calls', () {
    test('renders a pending call as awaiting an outcome, not as unknown', () async {
      final harness = Harness();
      harness.api.reply('GET', '/leads/42/calls',
          data: listData(<Map<String, dynamic>>[pendingCallJson(), completedCallJson()]));

      final page = await harness.dependencies.leadRepository.calls(42);

      expect(page.items.first.isPending, isTrue);
      expect(page.items.first.status, isNull);
      expect(page.items.first.displayStatus, 'Awaiting outcome');
      expect(page.items.last.displayStatus, 'Connected');
      expect(page.items.last.durationLabel, '2m 23s');

      await harness.dispose();
    });
  });
}
