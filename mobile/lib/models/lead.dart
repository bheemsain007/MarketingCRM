import 'json_reader.dart';

/// A lead, exactly as `LeadResource` shapes it.
///
/// Note what is *not* here: no derived status, no local notion of whether this
/// lead may be contacted. `isSuppressed` is copied through because the API
/// sends it, but it is a list-filter cache and explicitly not the authority on
/// contactability (BR-DNC-01) — `GET /leads/{id}/callability` is.
class Lead {
  const Lead({
    required this.id,
    required this.name,
    required this.phone,
    required this.phoneFormatted,
    required this.status,
    required this.statusLabel,
    this.company,
    this.email,
    this.city,
    this.state,
    this.temperature = '',
    this.score = 0,
    this.priority = 0,
    this.isSuppressed = false,
    this.isArchived = false,
    this.assignedToName,
    this.sourceName,
    this.lastContactedAt,
    this.createdAt,
    this.callsCount,
  });

  factory Lead.fromJson(Map<String, dynamic> json) {
    final assigned = Json.map(json['assigned_to']);
    final source = Json.map(json['source']);

    return Lead(
      id: Json.integer(json['id']),
      name: Json.string(json['name'], 'Unnamed lead'),
      phone: Json.string(json['phone']),
      phoneFormatted: Json.string(json['phone_formatted'], Json.string(json['phone'])),
      status: Json.string(json['status']),
      statusLabel: Json.string(json['status_label'], Json.string(json['status'])),
      company: Json.stringOrNull(json['company']),
      email: Json.stringOrNull(json['email']),
      city: Json.stringOrNull(json['city']),
      state: Json.stringOrNull(json['state']),
      temperature: Json.string(json['temperature']),
      score: Json.integer(json['score']),
      priority: Json.integer(json['priority']),
      isSuppressed: Json.boolean(json['is_suppressed']),
      isArchived: Json.boolean(json['is_archived']),
      assignedToName: assigned == null ? null : Json.stringOrNull(assigned['name']),
      sourceName: source == null ? null : Json.stringOrNull(source['name']),
      lastContactedAt: Json.dateTime(json['last_contacted_at']),
      createdAt: Json.dateTime(json['created_at']),
      callsCount: Json.integerOrNull(json['calls_count']),
    );
  }

  final int id;
  final String name;

  /// E.164 — this is what gets handed to the dialer.
  final String phone;

  /// Display form. Never dialled: the formatting is for eyes, not for `tel:`.
  final String phoneFormatted;

  final String status;
  final String statusLabel;
  final String? company;
  final String? email;
  final String? city;
  final String? state;
  final String temperature;
  final int score;
  final int priority;
  final bool isSuppressed;
  final bool isArchived;
  final String? assignedToName;
  final String? sourceName;
  final DateTime? lastContactedAt;
  final DateTime? createdAt;
  final int? callsCount;

  bool get hasPhone => phone.isNotEmpty;

  String get subtitle {
    final parts = <String>[?company, ?city];

    return parts.isEmpty ? phoneFormatted : '${parts.join(' · ')} · $phoneFormatted';
  }
}
