import 'dart:convert';

/// One entry from the envelope's `errors[]` array.
///
/// `code` is the stable machine-readable value from the server's error
/// catalogue (`dnc.suppressed`, `call.outside_calling_hours`, …). It is the
/// only part of an error this client is ever allowed to branch on; `message` is
/// for the human.
class ApiError {
  const ApiError({required this.code, required this.message, this.field});

  factory ApiError.fromJson(Map<String, dynamic> json) {
    return ApiError(
      code: _stringOr(json['code'], 'server.error'),
      message: _stringOr(json['message'], ''),
      field: json['field'] as String?,
    );
  }

  final String code;
  final String message;
  final String? field;

  static String _stringOr(Object? value, String fallback) {
    return value is String && value.isNotEmpty ? value : fallback;
  }

  @override
  String toString() => field == null ? '$code: $message' : '$field ($code): $message';
}

/// The `{success, message, data, errors}` envelope that **every** endpoint
/// returns, success and failure alike (API_DOCUMENTATION §2).
///
/// Parsing it in one place is what lets the rest of the app treat a response as
/// either a payload or a typed exception, and never as a status code plus a
/// guess.
class ApiEnvelope {
  const ApiEnvelope({
    required this.success,
    required this.message,
    required this.data,
    required this.errors,
  });

  final bool success;
  final String message;

  /// `object | null` per the contract. Kept as `Object?` rather than forced to
  /// a map, so a server that ever returns a scalar does not become a crash in
  /// the parser — [dataMap] is where the map is asserted.
  final Object? data;

  final List<ApiError> errors;

  static const ApiEnvelope empty = ApiEnvelope(
    success: true,
    message: '',
    data: null,
    errors: <ApiError>[],
  );

  Map<String, dynamic>? get dataMap => data is Map<String, dynamic> ? data as Map<String, dynamic> : null;

  /// The first machine-readable code, or null on a success envelope.
  String? get code => errors.isEmpty ? null : errors.first.code;

  /// Field errors keyed by field name, for painting a form.
  Map<String, String> get fieldErrors {
    final result = <String, String>{};

    for (final error in errors) {
      final field = error.field;
      if (field != null && !result.containsKey(field)) {
        result[field] = error.message;
      }
    }

    return result;
  }

  /// Decodes a response body.
  ///
  /// Returns `null` when the body is not the envelope at all — an HTML error
  /// page from a reverse proxy, a captive-portal interception, an empty 204.
  /// The caller decides whether that is fatal, because for a 204 it is not.
  static ApiEnvelope? tryParse(String body) {
    if (body.trim().isEmpty) {
      return null;
    }

    final Object? decoded;
    try {
      decoded = jsonDecode(body);
    } on FormatException {
      return null;
    }

    if (decoded is! Map<String, dynamic>) {
      return null;
    }

    // `success` is the field that distinguishes the envelope from any other
    // JSON object a misconfigured host might return.
    if (decoded['success'] is! bool) {
      return null;
    }

    return ApiEnvelope(
      success: decoded['success'] as bool,
      message: decoded['message'] is String ? decoded['message'] as String : '',
      data: decoded['data'],
      errors: _parseErrors(decoded['errors']),
    );
  }

  static List<ApiError> _parseErrors(Object? raw) {
    if (raw is! List) {
      return const <ApiError>[];
    }

    return raw
        .whereType<Map<String, dynamic>>()
        .map(ApiError.fromJson)
        .toList(growable: false);
  }
}
