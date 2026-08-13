import 'json_reader.dart';

/// A follow-up, as `FollowUpResource` sends it.
///
/// `is_overdue` is derived **by the server** and copied through untouched. The
/// temptation to compute it here from `scheduled_at` is exactly the kind of
/// duplication NFR-05 forbids: the server evaluates it against its own clock
/// and its own definition of open, and two clocks disagreeing about whether a
/// commitment is late is a support ticket nobody can close.
class FollowUp {
  const FollowUp({
    required this.id,
    required this.leadId,
    required this.status,
    required this.statusLabel,
    required this.scheduledAt,
    this.leadName,
    this.productName,
    this.channel = '',
    this.subject,
    this.notes,
    this.isOverdue = false,
    this.completedAt,
    this.outcome,
  });

  factory FollowUp.fromJson(Map<String, dynamic> json) {
    final lead = Json.map(json['lead']);
    final product = Json.map(json['product']);

    return FollowUp(
      id: Json.integer(json['id']),
      leadId: Json.integer(json['lead_id']),
      status: Json.string(json['status']),
      statusLabel: Json.string(json['status_label'], Json.string(json['status'])),
      scheduledAt: Json.dateTime(json['scheduled_at']),
      leadName: lead == null ? null : Json.stringOrNull(lead['name']),
      productName: product == null ? null : Json.stringOrNull(product['name']),
      channel: Json.string(json['channel']),
      subject: Json.stringOrNull(json['subject']),
      notes: Json.stringOrNull(json['notes']),
      isOverdue: Json.boolean(json['is_overdue']),
      completedAt: Json.dateTime(json['completed_at']),
      outcome: Json.stringOrNull(json['outcome']),
    );
  }

  final int id;
  final int leadId;
  final String status;
  final String statusLabel;
  final DateTime? scheduledAt;
  final String? leadName;
  final String? productName;
  final String channel;
  final String? subject;
  final String? notes;
  final bool isOverdue;
  final DateTime? completedAt;
  final String? outcome;

  bool get isOpen => status == 'open' || status == 'missed';

  String get title => subject ?? leadName ?? 'Follow-up #$id';
}
