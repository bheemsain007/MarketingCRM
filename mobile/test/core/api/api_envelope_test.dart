import 'package:flutter_test/flutter_test.dart';
import 'package:marketing_crm_mobile/core/api/api_envelope.dart';

import '../../support/scripted_api.dart';

void main() {
  group('ApiEnvelope', () {
    test('reads a success envelope', () {
      final envelope = ApiEnvelope.tryParse(envelopeJson(
        message: 'Leads retrieved.',
        data: <String, dynamic>{'items': <Object>[], 'meta': <String, dynamic>{}},
      ));

      expect(envelope, isNotNull);
      expect(envelope!.success, isTrue);
      expect(envelope.message, 'Leads retrieved.');
      expect(envelope.dataMap, isNotNull);
      expect(envelope.errors, isEmpty);
      expect(envelope.code, isNull);
    });

    test('reads an error envelope and exposes the machine-readable code', () {
      final envelope = ApiEnvelope.tryParse(envelopeJson(
        success: false,
        message: 'This lead is on the do-not-contact list.',
        errors: <Map<String, dynamic>>[
          <String, dynamic>{'code': 'dnc.suppressed', 'message': 'Suppressed.'},
        ],
        data: <String, dynamic>{'channel': 'call'},
      ));

      expect(envelope!.success, isFalse);
      expect(envelope.code, 'dnc.suppressed');
      expect(envelope.dataMap?['channel'], 'call');
    });

    test('keys field errors by field so a form can paint them', () {
      final envelope = ApiEnvelope.tryParse(envelopeJson(
        success: false,
        message: 'The given data was invalid.',
        errors: <Map<String, dynamic>>[
          <String, dynamic>{
            'field': 'callback_at',
            'code': 'validation.required',
            'message': 'When should this lead be called back?',
          },
          <String, dynamic>{
            'field': 'status',
            'code': 'validation.in',
            'message': 'That is not a call outcome.',
          },
        ],
      ));

      expect(envelope!.fieldErrors, <String, String>{
        'callback_at': 'When should this lead be called back?',
        'status': 'That is not a call outcome.',
      });
    });

    test('data may be null — a message-only success is still an envelope', () {
      final envelope = ApiEnvelope.tryParse(envelopeJson(message: 'Signed out.'));

      expect(envelope!.success, isTrue);
      expect(envelope.data, isNull);
      expect(envelope.dataMap, isNull);
    });

    test('refuses to parse an HTML error page as an envelope', () {
      expect(ApiEnvelope.tryParse('<html><body>502 Bad Gateway</body></html>'), isNull);
    });

    test('refuses JSON that is not the envelope', () {
      // A bare payload with no `success` key is the shape a misconfigured
      // gateway returns. Accepting it would let a proxy's JSON be rendered as
      // lead data.
      expect(ApiEnvelope.tryParse('{"items": []}'), isNull);
      expect(ApiEnvelope.tryParse('[]'), isNull);
      expect(ApiEnvelope.tryParse(''), isNull);
    });

    test('survives an errors array containing rubbish', () {
      final envelope = ApiEnvelope.tryParse(
        '{"success": false, "message": "Nope", "data": null, "errors": ["oops", 3]}',
      );

      expect(envelope, isNotNull);
      expect(envelope!.errors, isEmpty);
    });
  });
}
