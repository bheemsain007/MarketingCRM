import 'json_reader.dart';

/// A saved message template (FR-COMM-02), as `TemplateResource` sends it.
///
/// Templates are organisation content, not lead data — there is no "my
/// templates" the way there is "my leads" — so this model carries no owner and
/// no data-scope concern. Whether the signed-in user may create or edit one is
/// a permission check on `User.can('templates.manage')`, done by the screen,
/// never inferred from anything on this class.
class MessageTemplate {
  const MessageTemplate({
    required this.id,
    required this.name,
    required this.code,
    required this.channel,
    required this.channelLabel,
    required this.body,
    required this.isActive,
    required this.isSendable,
    this.subject,
    this.approvalStatus,
    this.rejectionReason,
  });

  factory MessageTemplate.fromJson(Map<String, dynamic> json) {
    return MessageTemplate(
      id: Json.integer(json['id']),
      name: Json.string(json['name']),
      code: Json.string(json['code']),
      channel: Json.string(json['channel']),
      channelLabel: Json.string(json['channel_label'], Json.string(json['channel'])),
      subject: Json.stringOrNull(json['subject']),
      body: Json.string(json['body']),
      isActive: Json.boolean(json['is_active'], true),
      // Active AND approved — on WhatsApp/RCS that second half is the
      // provider's to give (T-31), not this app's. A template can be saved and
      // still not be offered in the picker if it is not yet sendable.
      isSendable: Json.boolean(json['is_sendable'], false),
      approvalStatus: Json.stringOrNull(json['approval_status']),
      rejectionReason: Json.stringOrNull(json['rejection_reason']),
    );
  }

  final int id;
  final String name;
  final String code;

  /// The wire channel value (`sms`, `whatsapp`, `email`, and others this app's
  /// compose flow does not offer but the management screen still lists).
  final String channel;
  final String channelLabel;

  /// Null on every channel but email — same rule `StoreMessageRequest` and
  /// `MessageChannel.carriesSubject` already enforce for a send.
  final String? subject;
  final String body;
  final bool isActive;
  final bool isSendable;
  final String? approvalStatus;
  final String? rejectionReason;
}

/// A template rendered against one real lead — `GET .../preview`'s response.
///
/// Deliberately does **not** model the response's `recipient` field: the API
/// returns the lead's actual phone number or email address there so a caller
/// can tell "no address on record" apart from a render failure, but this app
/// never puts a lead's contact address on screen (SEC-PII-04). Leaving the
/// field out of the model makes rendering it a compile error, not a review
/// comment.
class TemplateRender {
  const TemplateRender({required this.body, required this.isSendable, this.subject});

  factory TemplateRender.fromJson(Map<String, dynamic> json) {
    return TemplateRender(
      subject: Json.stringOrNull(json['subject']),
      body: Json.string(json['body']),
      isSendable: Json.boolean(json['is_sendable'], false),
    );
  }

  final String? subject;
  final String body;

  /// False means the channel has no address for this lead — an email template
  /// previewed against a lead with no email on record, most commonly. The
  /// picker still shows the rendered text; it just does not pretend a send
  /// would work.
  final bool isSendable;
}

/// What the management screen submits to create or edit a template.
///
/// A plain data holder, not a model with server-assigned fields (`id`,
/// `isSendable`, `approvalStatus`) — those come back on the response and are
/// never sent, matching `StoreTemplateRequest`'s `prohibited` rule on
/// `approval_status`.
class TemplateDraft {
  const TemplateDraft({
    required this.name,
    required this.channel,
    required this.body,
    this.subject,
    this.code,
  });

  final String name;
  final String? code;
  final String channel;
  final String? subject;
  final String body;
}
