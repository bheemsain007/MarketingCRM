import 'package:flutter_test/flutter_test.dart';
import 'package:marketing_crm_mobile/core/api/api_exception.dart';

import '../support/fixtures.dart';
import '../support/harness.dart';
import '../support/scripted_api.dart';

void main() {
  group('FollowUpRepository', () {
    test('reads the diary, keeping the server-derived overdue flag', () async {
      final harness = Harness();
      harness.api.reply('GET', '/follow-ups',
          message: 'Follow-ups retrieved.',
          data: listData(<Map<String, dynamic>>[
            followUpJson(id: 501, isOverdue: true, status: 'missed', statusLabel: 'Missed'),
            followUpJson(id: 502),
          ]));

      final page = await harness.dependencies.followUpRepository.mine();

      expect(page.items, hasLength(2));
      expect(page.items.first.isOverdue, isTrue);
      expect(page.items.first.statusLabel, 'Missed');
      expect(page.items.first.leadName, 'Ramesh Kumar');
      // A missed follow-up is late, not void, and must stay actionable.
      expect(page.items.first.isOpen, isTrue);

      await harness.dispose();
    });

    test('does not send a status filter, so the server default (open + missed) applies', () async {
      final harness = Harness();
      harness.api.reply('GET', '/follow-ups', data: listData(const <Map<String, dynamic>>[]));

      await harness.dependencies.followUpRepository.mine();

      final query = harness.api.lastOf('GET', '/follow-ups')!.query;
      expect(query.keys.where((key) => key.startsWith('filter')), isEmpty);

      await harness.dispose();
    });

    test('completes with an outcome', () async {
      final harness = Harness();
      harness.api.reply('POST', '/follow-ups/501/complete',
          message: 'Follow-up completed.',
          data: followUpJson(id: 501, status: 'completed', statusLabel: 'Completed'));

      final result = await harness.dependencies.followUpRepository.complete(
        501,
        outcome: '  Quote sent.  ',
      );

      expect(result.status, 'completed');
      expect(harness.api.lastOf('POST', '/follow-ups/501/complete')!.json['outcome'], 'Quote sent.');

      await harness.dispose();
    });

    test('omits a blank outcome entirely', () async {
      final harness = Harness();
      harness.api.reply('POST', '/follow-ups/501/complete', data: followUpJson(id: 501));

      await harness.dependencies.followUpRepository.complete(501, outcome: '   ');

      expect(
        harness.api.lastOf('POST', '/follow-ups/501/complete')!.json.containsKey('outcome'),
        isFalse,
      );

      await harness.dispose();
    });

    test('an out-of-scope follow-up is a 403 from LeadPolicy', () async {
      final harness = Harness();
      harness.api.reply('POST', '/follow-ups/999/complete',
          status: 403, success: false, message: 'You may not act on this lead.');

      await expectLater(
        harness.dependencies.followUpRepository.complete(999),
        throwsA(isA<ForbiddenException>()),
      );

      await harness.dispose();
    });
  });
}
