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

  /// E.164 — this is what gets handed to the dialer, and the only reason the
  /// app holds a number at all.
  ///
  /// It is **never rendered** (SEC-PII-04). The lead book is the asset; a screen
  /// full of numbers is that asset photographable off a handset, and a telecaller
  /// does not need to read a number to dial it. `tel:` carries the number to the
  /// OS without a human ever seeing it inside this app. What the native dialer
  /// then shows is the operating system's business, not ours.
  final String phone;

  /// The server's display form of [phone].
  ///
  /// Kept because `LeadResource` sends it and this model mirrors the resource,
  /// but deliberately unused: see [phone]. If you are about to put this in a
  /// widget, that is the thing SEC-PII-04 asks you not to do.
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

  /// The line under the name in the lead list.
  ///
  /// Never the phone number (SEC-PII-04). The fallbacks exist so a lead
  /// carrying neither a company nor a city still gets a useful line rather
  /// than a blank one — who owns it, and failing that where it sits in the
  /// pipeline. Falling back to the number here was the exact regression this
  /// getter exists to rule out.
  String get subtitle {
    final parts = <String>[?company, ?city];

    if (parts.isNotEmpty) {
      return parts.join(' · ');
    }

    if (assignedToName != null) {
      return assignedToName!;
    }

    return statusLabel;
  }
}
