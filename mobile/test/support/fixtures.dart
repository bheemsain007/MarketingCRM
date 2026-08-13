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

Map<String, dynamic> leadJson({
  int id = 42,
  String name = 'Ramesh Kumar',
  String phone = '+919876500001',
  String status = 'contacted',
  String statusLabel = 'Contacted',
  bool isSuppressed = false,
}) {
  return <String, dynamic>{
    'id': id,
    'name': name,
    'company': 'Kumar Traders',
    'phone': phone,
    'phone_formatted': '+91 98765 00001',
    'alt_phone': null,
    'email': 'ramesh@example.com',
    'city': 'Pune',
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
