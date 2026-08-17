/// Payloads copied from the real API resources — `LeadResource`,
/// `CallResource`, `FollowUpResource`, `UserResource`.
///
/// They are verbose on purpose. A fixture trimmed to the three fields a test
/// happens to read stops being a contract check, and the field that gets
/// dropped is always the one that later changes shape.
library;

Map<String, dynamic> userJson({
  int id = 7,
  String name = 'Priya Sharma',
  List<String> permissions = const <String>[
    'leads.view',
    'calls.view',
    'calls.create',
    'follow_ups.view',
    'follow_ups.manage',
  ],
}) {
  return <String, dynamic>{
    'id': id,
    'name': name,
    'email': 'priya@example.com',
    'phone': '+919876543210',
    'timezone': 'Asia/Kolkata',
    'is_active': true,
    'team': <String, dynamic>{'id': 1, 'name': 'Inside Sales'},
    'roles': <Map<String, dynamic>>[
      <String, dynamic>{'name': 'telecaller', 'label': 'Telecaller'},
    ],
    'permissions': permissions,
    'data_scope': 'own',
    'last_login_at': '2026-08-12T09:15:00+05:30',
  };
}

/// One lead.
///
/// [email] is nullable on purpose: a lead with no email address is a real and
/// common shape (a phone-only lead from a call-in campaign), and it is the one
/// the email action has to cope with.
Map<String, dynamic> leadJson({
  int id = 42,
  String name = 'Ramesh Kumar',
  String phone = '+919876500001',
  String status = 'contacted',
  String statusLabel = 'Contacted',
  bool isSuppressed = false,
  String? email = 'ramesh@example.com',
  String? company = 'Kumar Traders',
  String? city = 'Pune',
}) {
  return <String, dynamic>{
    'id': id,
    'name': name,
    'company': company,
    'phone': phone,
    'phone_formatted': '+91 98765 00001',
    'alt_phone': null,
    'email': email,
    'city': city,
    'state': 'Maharashtra',
    'country': 'IN',
    'timezone': 'Asia/Kolkata',
    'status': status,
    'status_label': statusLabel,
    'temperature': 'warm',
    'score': 48,
    'priority': 2,
    'is_suppressed': isSuppressed,
    'is_archived': false,
    'source': <String, dynamic>{'id': 3, 'name': 'Meta Lead Ads', 'category': 'paid'},
    'assigned_to': <String, dynamic>{'id': 7, 'name': 'Priya Sharma'},
    'assigned_at': '2026-08-01T10:00:00+05:30',
    'last_contacted_at': '2026-08-10T16:30:00+05:30',
    'last_engagement_at': null,
    'created_at': '2026-07-28T11:00:00+05:30',
    'updated_at': '2026-08-10T16:30:00+05:30',
  };
}

/// A dial intent: `status` is null, which is a real state and not a gap.
Map<String, dynamic> pendingCallJson({int id = 900, int leadId = 42}) {
  return <String, dynamic>{
    'id': id,
    'lead_id': leadId,
    'direction': 'outbound',
    'status': null,
    'status_label': null,
    'is_pending': true,
    'is_connected': false,
    'started_at': '2026-08-12T11:00:00+05:30',
    'ended_at': null,
    'duration_seconds': 0,
    'notes': null,
    'dial_source': 'manual',
    'follow_up_id': null,
    'user': <String, dynamic>{'id': 7, 'name': 'Priya Sharma'},
    'created_at': '2026-08-12T11:00:00+05:30',
  };
}

Map<String, dynamic> completedCallJson({
  int id = 900,
  int leadId = 42,
  String status = 'connected',
  String statusLabel = 'Connected',
  bool isConnected = true,
  int durationSeconds = 143,
}) {
  return <String, dynamic>{
    'id': id,
    'lead_id': leadId,
    'direction': 'outbound',
    'status': status,
    'status_label': statusLabel,
    'is_pending': false,
    'is_connected': isConnected,
    'started_at': '2026-08-12T11:00:00+05:30',
    'ended_at': '2026-08-12T11:02:23+05:30',
    'duration_seconds': durationSeconds,
    'notes': 'Asked for a quotation.',
    'dial_source': 'manual',
    'follow_up_id': null,
    'user': <String, dynamic>{'id': 7, 'name': 'Priya Sharma'},
    'created_at': '2026-08-12T11:00:00+05:30',
  };
}

Map<String, dynamic> followUpJson({
  int id = 501,
  int leadId = 42,
  String status = 'open',
  String statusLabel = 'Open',
  bool isOverdue = false,
}) {
  return <String, dynamic>{
    'id': id,
    'lead_id': leadId,
    'lead': <String, dynamic>{'id': leadId, 'name': 'Ramesh Kumar'},
    'product': null,
    'channel': 'call',
    'subject': 'Send the revised quote',
    'notes': null,
    'status': status,
    'status_label': statusLabel,
    'is_overdue': isOverdue,
    'scheduled_at': '2026-08-13T10:00:00+05:30',
    'assigned_to': <String, dynamic>{'id': 7, 'name': 'Priya Sharma'},
    'completed_at': null,
    'completed_by': null,
    'outcome': null,
    'rescheduled_from_id': null,
    'reminder_sent': false,
    'created_at': '2026-08-11T09:00:00+05:30',
  };
}

/// A queued outbound message, as `MessageResource` sends it on the `202`.
Map<String, dynamic> queuedMessageJson({
  int id = 3300,
  int leadId = 42,
  String channel = 'sms',
  String channelLabel = 'SMS',
  String? subject,
  String body = 'Sharing the quotation shortly.',
  String status = 'queued',
  String? provider,
}) {
  return <String, dynamic>{
    'id': id,
    'lead_id': leadId,
    'campaign_id': null,
    'channel': channel,
    'channel_label': channelLabel,
    'direction': 'outbound',
    'recipient': channel == 'email' ? 'ramesh@example.com' : '+919876500001',
    'subject': subject,
    'body': body,
    'status': status,
    'failure_reason': null,
    'skip_reason': null,
    'provider': provider,
    'scheduled_at': null,
    'sent_at': null,
    'delivered_at': null,
    'read_at': null,
    'failed_at': null,
    'created_at': '2026-08-13T10:05:00+05:30',
  };
}

/// A saved template, as `TemplateResource` sends it.
Map<String, dynamic> templateJson({
  int id = 500,
  String name = 'Quotation follow-up',
  String code = 'QUOTATION_FOLLOWUP',
  String channel = 'sms',
  String channelLabel = 'SMS',
  String? subject,
  String body = 'Hi {{ lead_name }}, following up on the quote for {{ company }}.',
  bool isActive = true,
  bool isSendable = true,
}) {
  return <String, dynamic>{
    'id': id,
    'name': name,
    'code': code,
    'channel': channel,
    'channel_label': channelLabel,
    'subject': subject,
    'body': body,
    'variables': <String>['lead_name', 'company'],
    'media': null,
    'provider': null,
    'provider_template_id': null,
    'approval_status': 'approved',
    'rejection_reason': null,
    'is_active': isActive,
    'is_sendable': isSendable,
    'campaign_count': null,
    'message_count': null,
    'created_at': '2026-08-01T09:00:00+05:30',
    'updated_at': '2026-08-01T09:00:00+05:30',
  };
}

/// A template rendered against a real lead — `GET .../preview`'s response.
///
/// Carries `recipient` because the real API does, but nothing in this app's
/// model layer reads it (see `TemplateRender` — SEC-PII-04); it is here only
/// so the fixture matches the actual server contract.
Map<String, dynamic> templatePreviewJson({
  int templateId = 500,
  int leadId = 42,
  String channel = 'sms',
  String? subject,
  String body = 'Hi Ramesh Kumar, following up on the quote for Kumar Textiles.',
  bool isSendable = true,
  String recipient = '+919876500001',
}) {
  return <String, dynamic>{
    'template_id': templateId,
    'lead_id': leadId,
    'channel': channel,
    'subject': subject,
    'body': body,
    'recipient': recipient,
    'is_sendable': isSendable,
  };
}

Map<String, dynamic> callableJson() {
  return <String, dynamic>{
    'callable': true,
    'reason': null,
    'message': null,
    'next_opening': null,
  };
}

/// The DNC refusal (BR-CALL-01 / FR-CALL-08).
Map<String, dynamic> suppressedJson() {
  return <String, dynamic>{
    'callable': false,
    'reason': 'dnc.suppressed',
    'message': 'This lead is on the do-not-contact list and must not be called.',
    'next_opening': null,
  };
}

/// The calling-hours refusal, evaluated in the lead's own timezone (BR-CALL-04).
Map<String, dynamic> outsideHoursJson() {
  return <String, dynamic>{
    'callable': false,
    'reason': 'call.outside_calling_hours',
    'message': 'It is outside calling hours for this lead.',
    'next_opening': '2026-08-13T09:00:00+05:30',
  };
}
