import 'dart:math';

/// What kind of work a queued entry represents.
///
/// Only call outcomes are queued today (Phase 33 foundation). The enum exists
/// so adding a second kind is a new case rather than a new queue.
enum OutboxKind {
  callOutcome;

  static OutboxKind fromName(String name) {
    return OutboxKind.values.firstWhere(
      (kind) => kind.name == name,
      orElse: () => OutboxKind.callOutcome,
    );
  }
}

/// One durable, replayable write.
///
/// It stores the request as data — method, path, body — rather than a closure,
/// because the process that enqueued it is usually dead by the time it is sent.
class OutboxEntry {
  const OutboxEntry({
    required this.id,
    required this.kind,
    required this.method,
    required this.path,
    required this.body,
    required this.idempotencyKey,
    required this.queuedAt,
    required this.label,
    this.attempts = 0,
    this.lastError,
    this.permanentlyFailed = false,
  });

  factory OutboxEntry.fromJson(Map<String, dynamic> json) {
    return OutboxEntry(
      id: json['id'] as String,
      kind: OutboxKind.fromName(json['kind'] as String? ?? OutboxKind.callOutcome.name),
      method: json['method'] as String? ?? 'PATCH',
      path: json['path'] as String? ?? '',
      body: Map<String, dynamic>.from(json['body'] as Map? ?? <String, dynamic>{}),
      idempotencyKey: json['idempotency_key'] as String? ?? json['id'] as String,
      queuedAt: DateTime.tryParse(json['queued_at'] as String? ?? '') ?? DateTime.now(),
      label: json['label'] as String? ?? 'Queued change',
      attempts: json['attempts'] as int? ?? 0,
      lastError: json['last_error'] as String?,
      permanentlyFailed: json['permanently_failed'] as bool? ?? false,
    );
  }

  /// Local identity, stable across restarts. Also the fallback idempotency key.
  final String id;

  final OutboxKind kind;
  final String method;
  final String path;
  final Map<String, dynamic> body;

  /// Sent as `Idempotency-Key`. Generated **once, at enqueue time**, and reused
  /// on every retry — a key regenerated per attempt would defeat the entire
  /// point, which is that an ambiguous timeout can be retried safely.
  final String idempotencyKey;

  final DateTime queuedAt;

  /// What to show the human: "Outcome for Ramesh Kumar". The queue is visible
  /// in the UI, because a silent queue is one nobody notices is stuck.
  final String label;

  final int attempts;
  final String? lastError;

  /// Set when the server refused in a way that retrying cannot fix (422, 403).
  /// The entry is kept, not deleted — losing a telecaller's call outcome
  /// silently is worse than showing them a failure they have to look at.
  final bool permanentlyFailed;

  OutboxEntry copyWith({
    int? attempts,
    String? lastError,
    bool? permanentlyFailed,
  }) {
    return OutboxEntry(
      id: id,
      kind: kind,
      method: method,
      path: path,
      body: body,
      idempotencyKey: idempotencyKey,
      queuedAt: queuedAt,
      label: label,
      attempts: attempts ?? this.attempts,
      lastError: lastError ?? this.lastError,
      permanentlyFailed: permanentlyFailed ?? this.permanentlyFailed,
    );
  }

  Map<String, dynamic> toJson() {
    return <String, dynamic>{
      'id': id,
      'kind': kind.name,
      'method': method,
      'path': path,
      'body': body,
      'idempotency_key': idempotencyKey,
      'queued_at': queuedAt.toIso8601String(),
      'label': label,
      'attempts': attempts,
      'last_error': lastError,
      'permanently_failed': permanentlyFailed,
    };
  }

  static final Random _random = Random();

  /// Time-ordered so the queue sorts naturally, with entropy so two outcomes
  /// recorded in the same millisecond cannot collide.
  static String newId() {
    final stamp = DateTime.now().microsecondsSinceEpoch.toRadixString(36);
    final noise = _random.nextInt(1 << 32).toRadixString(36).padLeft(7, '0');

    return '$stamp-$noise';
  }
}
