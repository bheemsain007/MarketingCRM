import 'json_reader.dart';

/// The channels this app can send a message on.
///
/// **Protocol vocabulary, not a business rule** — the same standing as
/// `CallOutcome`. These are three of the seven values `POST
/// /leads/{lead}/messages` accepts for `channel`; the app offers the three a
/// telecaller has a reason to reach for by hand.
///
/// Nothing here decides anything. Whether a lead may be contacted on a channel
/// is `DncService`'s answer (BR-DNC-01), whether a provider exists is
/// `MessageDriverManager`'s, and both arrive as a response rather than as a
/// prediction made on the handset.
enum MessageChannel {
  sms('sms', 'SMS', false),
  whatsapp('whatsapp', 'WhatsApp', false),

  /// The one channel that carries a subject. The server rejects `subject` on
  /// any other channel with a `422`, which is why this is a property of the
  /// channel and not a field the compose sheet always shows.
  email('email', 'Email', true);

  const MessageChannel(this.value, this.label, this.carriesSubject);

  /// The wire value for `channel`.
  final String value;

  final String label;

  final bool carriesSubject;
}

/// A message record, as `MessageResource` sends it.
///
/// Note [provider]: `"log"` means no driver was configured and nothing actually
/// left the building. The server says so in its own sentence too, and this app
/// repeats that sentence rather than reporting "sent".
class OutboundMessage {
  const OutboundMessage({
    required this.id,
    required this.leadId,
    required this.channel,
    required this.channelLabel,
    required this.status,
    this.subject,
    this.body,
    this.failureReason,
    this.skipReason,
    this.provider,
    this.createdAt,
  });

  factory OutboundMessage.fromJson(Map<String, dynamic> json) {
    return OutboundMessage(
      id: Json.integer(json['id']),
      leadId: Json.integer(json['lead_id']),
      channel: Json.string(json['channel']),
      channelLabel: Json.string(json['channel_label'], Json.string(json['channel'])),
      status: Json.string(json['status']),
      subject: Json.stringOrNull(json['subject']),
      body: Json.stringOrNull(json['body']),
      failureReason: Json.stringOrNull(json['failure_reason']),
      skipReason: Json.stringOrNull(json['skip_reason']),
      provider: Json.stringOrNull(json['provider']),
      createdAt: Json.dateTime(json['created_at']),
    );
  }

  final int id;
  final int leadId;
  final String channel;
  final String channelLabel;

  /// `queued` on the 202 this app gets. `sent`, `failed` and `skipped` are
  /// later states it learns about only by reading the message history.
  final String status;

  final String? subject;
  final String? body;
  final String? failureReason;

  /// Populated on a `skipped` message — a suppressed send is recorded with a
  /// reason rather than vanishing (BR-DNC-05).
  final String? skipReason;

  final String? provider;
  final DateTime? createdAt;

  bool get isQueued => status == 'queued';
}

/// The outcome of one send attempt.
///
/// [message] is the server's own sentence and the only thing shown to the user.
/// It is load-bearing: the server distinguishes "Message queued for sending."
/// from "Message queued. No provider is configured for this channel yet, so it
/// will be recorded but not delivered." and a telecaller needs to know which of
/// those two just happened. Substituting a cheerful "Sent!" here would be the
/// app lying about a delivery it cannot observe.
class MessageSendResult {
  const MessageSendResult({required this.message, required this.sent});

  final String message;
  final OutboundMessage sent;
}
