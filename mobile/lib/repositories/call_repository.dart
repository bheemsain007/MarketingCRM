import '../core/api/api_client.dart';
import '../core/api/api_exception.dart';
import '../core/offline/outbox.dart';
import '../core/offline/outbox_entry.dart';
import '../models/call.dart';

/// The write half of ADR-B.
///
/// The sequence for one dial is fixed and this class is the only place it
/// exists:
///
///   1. the caller has already asked `GET /leads/{id}/callability` and been
///      told yes — this class never dials, so it never bypasses that;
///   2. [start] creates the call record and the dial intent (`202`, outcome
///      still `null`);
///   3. the *device* dials, through `PhoneDialer`;
///   4. [recordOutcome] reports what happened (`PATCH /calls/{id}`), and if the
///      handset has no signal by then, the outcome goes to the outbox instead
///      of being lost.
class CallRepository {
  CallRepository({required ApiClient api, required Outbox outbox})
      : _api = api,
        _outbox = outbox;

  final ApiClient _api;
  final Outbox _outbox;

  /// `POST /leads/{id}/calls` with no status — a dial intent.
  ///
  /// Returns `202`; the call comes back with `status: null`, which is a real
  /// state (*dialled, outcome not yet reported*) and not a missing value.
  ///
  /// The server runs all three gates again here even though callability was
  /// just asked, so a lead suppressed in the seconds between the two calls is
  /// still refused with [ForbiddenException]. That re-check is the actual
  /// protection; callability only exists so the refusal can be shown before the
  /// telecaller commits to it.
  ///
  /// This is deliberately **not** queued when offline. A dial intent that was
  /// never authorised by the server is a call that skipped the DNC gate.
  Future<Call> start(int leadId, {String dialSource = 'manual'}) async {
    final envelope = await _api.post('/leads/$leadId/calls', body: <String, dynamic>{
      'dial_source': dialSource,
    });

    final data = envelope.dataMap;
    if (data == null) {
      throw const MalformedResponseException(statusCode: 202);
    }

    return Call.fromJson(data);
  }

  /// `PATCH /calls/{id}` — what happened.
  ///
  /// Outcomes are write-once server-side, so a second attempt is a `409`. That
  /// is exactly what makes this safe to queue and retry.
  ///
  /// Nothing here validates the combination of fields. `call_back_requested`
  /// without a `callback_at` is a `422` from the server with the server's own
  /// sentence — reproducing that requirement on the handset would be a second
  /// copy of BR-CALL-05 to keep in step (NFR-05).
  Future<CallOutcomeResult> recordOutcome({
    required int callId,
    required String status,
    required String leadName,
    String? notes,
    int? durationSeconds,
    DateTime? endedAt,
    DateTime? callbackAt,
  }) async {
    // Stamped now, not at send time. If this sits in the queue for two hours,
    // the server must still record when the call actually ended.
    final body = <String, dynamic>{
      'status': status,
      'ended_at': (endedAt ?? DateTime.now()).toUtc().toIso8601String(),
      if (notes != null && notes.trim().isNotEmpty) 'notes': notes.trim(),
      'duration_seconds': ?durationSeconds,
      if (callbackAt != null) 'callback_at': callbackAt.toUtc().toIso8601String(),
    };

    final path = '/calls/$callId';
    final idempotencyKey = OutboxEntry.newId();

    try {
      final envelope = await _api.patch(path, body: body, idempotencyKey: idempotencyKey);
      final data = envelope.dataMap;

      if (data == null) {
        throw const MalformedResponseException(statusCode: 200);
      }

      return CallOutcomeResult.recorded(Call.fromJson(data));
    } on NetworkException {
      // The request never reached the server, so nothing was recorded and
      // replaying it cannot duplicate anything. This is the only exception that
      // may be queued — a 422 would be queued forever, and a 409 already means
      // the outcome is on the record.
      await _outbox.add(OutboxEntry(
        id: idempotencyKey,
        kind: OutboxKind.callOutcome,
        method: 'PATCH',
        path: path,
        body: body,
        idempotencyKey: idempotencyKey,
        queuedAt: DateTime.now(),
        label: 'Call outcome for $leadName',
      ));

      return CallOutcomeResult.queued('Call outcome for $leadName');
    }
  }
}
