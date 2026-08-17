import '../core/api/api_client.dart';
import '../core/api/api_exception.dart';
import '../models/outbound_message.dart';

/// Outbound messaging, through the CRM and only through the CRM.
///
/// This class is the reason the app has no `sms:`, `mailto:` or WhatsApp deep
/// link anywhere in it. Handing a message to the phone's own SMS app, mail
/// client or WhatsApp would be quick, and it would:
///
///  * skip the DNC gate — `OutboundMessageService` asks `DncService` before the
///    message exists, and asks again inside the job at dispatch time (BR-DNC-01,
///    BR-DNC-03). A message composed on the handset is asked nothing;
///  * skip the frequency caps, which are counted off the `messages` table;
///  * leave no `Message` row and no timeline entry, so the CRM would believe the
///    lead had never been contacted — and the next campaign would contact them
///    again.
///
/// Dialling is the deliberate exception (ADR-B): the *device* places calls
/// because a carrier call cannot be placed from a server, and the server still
/// authorises it first via `POST /leads/{id}/calls`. Nothing forces the same
/// compromise on a message, so nothing gets it.
class MessageRepository {
  MessageRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  /// `POST /leads/{lead}/messages` — queue one message on one channel.
  ///
  /// Returns `202` with the message in `queued`, and the envelope's sentence
  /// carries the part the user actually needs: whether a provider exists for
  /// this channel at all. That sentence is passed straight through in
  /// [MessageSendResult.message].
  ///
  /// The refusals belong to the server and arrive as typed exceptions:
  ///
  ///  * [ForbiddenException] (`403 dnc.suppressed`) — the lead is on the
  ///    do-not-contact list for this channel. A `skipped` message is recorded
  ///    server-side at the same time, so the refusal is auditable (BR-DNC-05).
  ///  * [ValidationException] (`422`) — no address on record for the channel,
  ///    an empty body, or a `subject` on a channel that has no subject line.
  ///
  /// Deliberately **not** queued to the outbox when offline, for the same
  /// reason `CallRepository.start` is not: an unsent message was never put past
  /// the suppression gate, and replaying it later would send a message the
  /// server never agreed to. A telecaller with no signal is told so and can
  /// send it when they have one.
  Future<MessageSendResult> send({
    required int leadId,
    required MessageChannel channel,
    required String body,
    String? subject,
  }) async {
    final trimmedSubject = subject?.trim();

    final envelope = await _api.post('/leads/$leadId/messages', body: <String, dynamic>{
      'channel': channel.value,
      'body': body,

      // Sent only where the channel has one. `StoreMessageRequest` returns a
      // `422` on `subject` for anything but email — omitting it is honouring
      // the contract, not second-guessing it.
      if (channel.carriesSubject && trimmedSubject != null && trimmedSubject.isNotEmpty)
        'subject': trimmedSubject,
    });

    final data = envelope.dataMap;
    if (data == null) {
      throw const MalformedResponseException(statusCode: 202);
    }

    return MessageSendResult(
      message: envelope.message,
      sent: OutboundMessage.fromJson(data),
    );
  }
}
