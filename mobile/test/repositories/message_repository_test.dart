import 'package:flutter_test/flutter_test.dart';
import 'package:marketing_crm_mobile/core/api/api_exception.dart';
import 'package:marketing_crm_mobile/models/outbound_message.dart';

import '../support/fixtures.dart';
import '../support/harness.dart';

void main() {
  group('MessageRepository.send — everything goes through the CRM', () {
    test('a queued send carries the server sentence back unaltered', () async {
      final harness = Harness();
      harness.api.reply('POST', '/leads/42/messages',
          status: 202,
          message: 'Message queued for sending.',
          data: queuedMessageJson());

      final result = await harness.dependencies.messageRepository.send(
        leadId: 42,
        channel: MessageChannel.sms,
        body: 'Sharing the quotation shortly.',
      );

      expect(result.message, 'Message queued for sending.');
      expect(result.sent.isQueued, isTrue);
      expect(result.sent.channel, 'sms');

      await harness.dispose();
    });

    test('"no provider configured" is passed through, not rounded up to sent', () async {
      // The one sentence a telecaller must not be protected from: the message
      // is on the record and nobody received it.
      final harness = Harness();
      harness.api.reply('POST', '/leads/42/messages',
          status: 202,
          message: 'Message queued. No provider is configured for this channel yet, '
              'so it will be recorded but not delivered.',
          data: queuedMessageJson(provider: 'log'));

      final result = await harness.dependencies.messageRepository.send(
        leadId: 42,
        channel: MessageChannel.whatsapp,
        body: 'Hello',
      );

      expect(result.message, contains('No provider is configured'));
      expect(result.sent.provider, 'log');

      await harness.dispose();
    });

    test('the channel goes on the wire as the value the API enum expects', () async {
      final harness = Harness();
      harness.api.reply('POST', '/leads/42/messages', status: 202, data: queuedMessageJson());

      for (final channel in MessageChannel.values) {
        await harness.dependencies.messageRepository.send(
          leadId: 42,
          channel: channel,
          body: 'Hello',
        );

        expect(harness.api.lastOf('POST', '/leads/42/messages')!.json['channel'], channel.value);
      }

      await harness.dispose();
    });

    test('subject is sent for email and withheld from every other channel', () async {
      // `StoreMessageRequest` returns 422 on `subject` for a non-email channel,
      // so sending one anyway would turn a working SMS into a validation error.
      final harness = Harness();
      harness.api.reply('POST', '/leads/42/messages', status: 202, data: queuedMessageJson());

      await harness.dependencies.messageRepository.send(
        leadId: 42,
        channel: MessageChannel.email,
        body: 'Body',
        subject: '  Your quotation  ',
      );
      expect(harness.api.lastOf('POST', '/leads/42/messages')!.json['subject'], 'Your quotation');

      await harness.dependencies.messageRepository.send(
        leadId: 42,
        channel: MessageChannel.sms,
        body: 'Body',
        subject: 'Ignored',
      );
      expect(
        harness.api.lastOf('POST', '/leads/42/messages')!.json.containsKey('subject'),
        isFalse,
      );

      await harness.dispose();
    });

    test('an empty subject on email is omitted rather than sent blank', () async {
      final harness = Harness();
      harness.api.reply('POST', '/leads/42/messages', status: 202, data: queuedMessageJson());

      await harness.dependencies.messageRepository.send(
        leadId: 42,
        channel: MessageChannel.email,
        body: 'Body',
        subject: '   ',
      );

      expect(
        harness.api.lastOf('POST', '/leads/42/messages')!.json.containsKey('subject'),
        isFalse,
      );

      await harness.dispose();
    });

    test('a suppressed lead is a 403 with the reason, surfaced as sent', () async {
      final harness = Harness();
      harness.api.reply('POST', '/leads/42/messages',
          status: 403,
          success: false,
          message: 'This lead cannot be contacted on SMS: Customer requested.',
          errors: <Map<String, dynamic>>[
            <String, dynamic>{'code': 'dnc.suppressed', 'message': 'Suppressed.'},
          ]);

      await expectLater(
        harness.dependencies.messageRepository.send(
          leadId: 42,
          channel: MessageChannel.sms,
          body: 'Hello',
        ),
        throwsA(isA<ForbiddenException>().having(
          (e) => e.message,
          'message',
          'This lead cannot be contacted on SMS: Customer requested.',
        )),
      );

      await harness.dispose();
    });

    test('a lead with no email address is a 422 from the server', () async {
      final harness = Harness();
      harness.api.reply('POST', '/leads/42/messages',
          status: 422,
          success: false,
          message: 'This lead has no Email address on record.');

      await expectLater(
        harness.dependencies.messageRepository.send(
          leadId: 42,
          channel: MessageChannel.email,
          body: 'Hello',
        ),
        throwsA(isA<ValidationException>()),
      );

      await harness.dispose();
    });

    test('offline throws rather than queueing — an ungated send is never replayed', () async {
      // A message in the outbox is a message the DNC gate never saw. The
      // outbox exists for call OUTCOMES, which report a thing that already
      // happened; this would create one.
      final harness = Harness();
      harness.api.offline = true;

      await expectLater(
        harness.dependencies.messageRepository.send(
          leadId: 42,
          channel: MessageChannel.sms,
          body: 'Hello',
        ),
        throwsA(isA<NetworkException>()),
      );

      expect(harness.outbox.entries, isEmpty);

      await harness.dispose();
    });
  });
}
