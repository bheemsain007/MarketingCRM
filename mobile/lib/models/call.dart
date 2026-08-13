import 'json_reader.dart';

/// The eleven call outcomes of FR-CALL-01.
///
/// This list is **protocol vocabulary, not a business rule**: it is the closed
/// set of values `PATCH /calls/{id}` accepts, the same way an HTTP verb is a
/// closed set. Nothing here decides anything — which outcome suppresses a lead,
/// which one requires a callback time, which one counts as talk time all live
/// in `CallStatus` on the server, and this app learns them by being told
/// (`status_label`, `is_connected`) or by being refused (422).
enum CallOutcome {
  connected('connected', 'Connected'),
  notConnected('not_connected', 'Not Connected'),
  busy('busy', 'Busy'),
  noAnswer('no_answer', 'No Answer'),
  callRejected('call_rejected', 'Call Rejected'),
  switchedOff('switched_off', 'Switched Off'),
  notReachable('not_reachable', 'Not Reachable'),
  invalidNumber('invalid_number', 'Invalid Number'),
  callBackRequested('call_back_requested', 'Call Back Requested'),
  noResponse('no_response', 'No Response'),
  wrongNumber('wrong_number', 'Wrong Number');

  const CallOutcome(this.value, this.label);

  final String value;
  final String label;

  static CallOutcome? tryParse(String? value) {
    if (value == null) {
      return null;
    }

    for (final outcome in CallOutcome.values) {
      if (outcome.value == value) {
        return outcome;
      }
    }

    return null;
  }
}

/// A call record, as `CallResource` sends it.
///
/// `status == null` is a real state — *dialled, outcome not yet reported* — and
/// the server tells us so with `is_pending`. It is never rendered as "unknown".
class Call {
  const Call({
    required this.id,
    required this.leadId,
    this.status,
    this.statusLabel,
    this.isPending = true,
    this.isConnected = false,
    this.direction = 'outbound',
    this.startedAt,
    this.endedAt,
    this.durationSeconds = 0,
    this.notes,
    this.dialSource,
    this.userName,
    this.createdAt,
  });

  factory Call.fromJson(Map<String, dynamic> json) {
    final user = Json.map(json['user']);

    return Call(
      id: Json.integer(json['id']),
      leadId: Json.integer(json['lead_id']),
      status: Json.stringOrNull(json['status']),
      statusLabel: Json.stringOrNull(json['status_label']),
      isPending: Json.boolean(json['is_pending'], json['status'] == null),
      isConnected: Json.boolean(json['is_connected']),
      direction: Json.string(json['direction'], 'outbound'),
      startedAt: Json.dateTime(json['started_at']),
      endedAt: Json.dateTime(json['ended_at']),
      durationSeconds: Json.integer(json['duration_seconds']),
      notes: Json.stringOrNull(json['notes']),
      dialSource: Json.stringOrNull(json['dial_source']),
      userName: user == null ? null : Json.stringOrNull(user['name']),
      createdAt: Json.dateTime(json['created_at']),
    );
  }

  final int id;
  final int leadId;
  final String? status;
  final String? statusLabel;
  final bool isPending;
  final bool isConnected;
  final String direction;
  final DateTime? startedAt;
  final DateTime? endedAt;
  final int durationSeconds;
  final String? notes;
  final String? dialSource;
  final String? userName;
  final DateTime? createdAt;

  /// What to show in a history row. The server's own label wins; the fallback
  /// only covers a pending call, which has no label because it has no outcome.
  String get displayStatus => statusLabel ?? 'Awaiting outcome';

  String get durationLabel {
    if (durationSeconds <= 0) {
      return '—';
    }

    final minutes = durationSeconds ~/ 60;
    final seconds = durationSeconds % 60;

    return minutes == 0 ? '${seconds}s' : '${minutes}m ${seconds}s';
  }
}

/// What happened when this app tried to record an outcome.
///
/// The offline case is a first-class result, not an error: the outcome is
/// safely on disk and will be sent. Telling a telecaller "failed" when the work
/// is queued would make them record it twice.
class CallOutcomeResult {
  const CallOutcomeResult._({required this.queued, this.call, this.queuedLabel});

  factory CallOutcomeResult.recorded(Call call) => CallOutcomeResult._(queued: false, call: call);

  factory CallOutcomeResult.queued(String label) =>
      CallOutcomeResult._(queued: true, queuedLabel: label);

  final bool queued;
  final Call? call;
  final String? queuedLabel;

  String get message => queued
      ? 'Saved on this device. It will reach the CRM when you are back online.'
      : 'Call outcome recorded.';
}
