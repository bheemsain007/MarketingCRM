import 'package:flutter_test/flutter_test.dart';
import 'package:marketing_crm_mobile/core/api/api_exception.dart';

import '../support/harness.dart';

void main() {
  group('AttendanceRepository.status', () {
    test('reports an open session that is not on a break', () async {
      final harness = Harness();
      harness.api.reply('GET', '/attendance/status', data: <String, dynamic>{
        'has_open_session': true,
        'is_on_break': false,
        'break_started_at': null,
      });

      final status = await harness.dependencies.attendanceRepository.status();

      expect(status.hasOpenSession, isTrue);
      expect(status.isOnBreak, isFalse);

      await harness.dispose();
    });

    test('reports no open session as false, not as an error', () async {
      final harness = Harness();
      harness.api.reply('GET', '/attendance/status', data: <String, dynamic>{
        'has_open_session': false,
        'is_on_break': false,
        'break_started_at': null,
      });

      final status = await harness.dependencies.attendanceRepository.status();

      expect(status.hasOpenSession, isFalse);

      await harness.dispose();
    });

    test('throws a malformed-response error rather than guessing when data is missing', () async {
      final harness = Harness();
      harness.api.reply('GET', '/attendance/status', data: null);

      expect(
        () => harness.dependencies.attendanceRepository.status(),
        throwsA(isA<MalformedResponseException>()),
      );

      await harness.dispose();
    });
  });

  group('AttendanceRepository.startBreak / stopBreak', () {
    test('starting a break posts to the start endpoint', () async {
      final harness = Harness();
      harness.api.reply('POST', '/attendance/breaks/start', message: 'Break started.');

      await harness.dependencies.attendanceRepository.startBreak();

      expect(harness.api.countOf('POST', '/attendance/breaks/start'), 1);

      await harness.dispose();
    });

    test('stopping a break posts to the stop endpoint', () async {
      final harness = Harness();
      harness.api.reply('POST', '/attendance/breaks/stop', message: 'Break ended.');

      await harness.dependencies.attendanceRepository.stopBreak();

      expect(harness.api.countOf('POST', '/attendance/breaks/stop'), 1);

      await harness.dispose();
    });

    test('starting a break twice surfaces the servers own refusal message', () async {
      final harness = Harness();
      harness.api.reply(
        'POST',
        '/attendance/breaks/start',
        status: 422,
        success: false,
        message: 'A break is already in progress.',
      );

      await expectLater(
        () => harness.dependencies.attendanceRepository.startBreak(),
        throwsA(isA<ApiException>().having(
          (error) => error.message,
          'message',
          'A break is already in progress.',
        )),
      );

      await harness.dispose();
    });
  });
}
