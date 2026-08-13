import 'json_reader.dart';

/// The server's answer to "may this lead be dialled right now?"
///
/// `GET /leads/{id}/callability` returns `{callable, reason, message,
/// next_opening}`. Three gates sit behind it — the DNC suppression list
/// (FR-CALL-08, BR-CALL-01), calling hours evaluated **in the lead's own
/// timezone** (BR-CALL-04), and whether there is a usable number at all.
///
/// **This app does not reproduce any of them.** It does not read
/// `lead.is_suppressed`, it does not compare the clock to an office window, it
/// does not check the phone string. It asks, and it obeys the answer. A client
/// that could form its own opinion is a client that will eventually disagree
/// with the server — and the way you find out is by ringing somebody on the
/// do-not-contact list.
class Callability {
  const Callability({
    required this.callable,
    this.reason,
    this.message,
    this.nextOpening,
  });

  factory Callability.fromJson(Map<String, dynamic> json) {
    return Callability(
      callable: Json.boolean(json['callable']),
      reason: Json.stringOrNull(json['reason']),
      message: Json.stringOrNull(json['message']),
      nextOpening: Json.dateTime(json['next_opening']),
    );
  }

  /// The only field that decides whether the dial button works.
  final bool callable;

  /// Machine-readable refusal code — `dnc.suppressed`,
  /// `call.outside_calling_hours`, `lead.archived`, `validation.failed`.
  /// Used to pick an icon, never to override [callable].
  final String? reason;

  /// The sentence to show the telecaller. Written by the server so that Web and
  /// Android refuse in the same words (NFR-05).
  final String? message;

  /// When the calling window reopens. Attempts are deferred, never dropped.
  final DateTime? nextOpening;

  /// Never invented for a lead — this is the state before an answer arrives, so
  /// the button starts disabled rather than starting enabled and being taken
  /// away.
  static const Callability unknown = Callability(
    callable: false,
    message: 'Checking whether this lead can be called…',
  );

  /// What to show when the check itself could not be made (offline, 5xx).
  ///
  /// Refusing is the only safe default: the gates are compliance rules, and
  /// "we could not ask" must never resolve to "go ahead".
  static const Callability unreachable = Callability(
    callable: false,
    reason: 'client.offline',
    message: 'Cannot confirm this lead may be called without a connection. '
        'The do-not-call and calling-hours checks are made by the CRM.',
  );

  String get refusalMessage => message ?? 'This lead cannot be called right now.';
}
