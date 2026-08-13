import 'api_envelope.dart';

/// A failed API call, in the shape the UI needs to react to it.
///
/// Every non-2xx becomes one of these subtypes. Callers branch on the *type*
/// for control flow (offline → queue, 401 → sign out) and show [message]
/// verbatim: the server writes the sentence, this app does not paraphrase it.
class ApiException implements Exception {
  const ApiException({
    required this.code,
    required this.message,
    this.statusCode,
    this.errors = const <ApiError>[],
    this.data,
  });

  /// Machine-readable code from the catalogue, or a client-side pseudo-code
  /// (`client.offline`, `client.malformed_response`) when no envelope arrived.
  final String code;

  /// Human-readable and safe to display — the server guarantees no stack traces
  /// or SQL reach this field.
  final String message;

  /// Null when the request never got a response at all.
  final int? statusCode;

  final List<ApiError> errors;

  /// The error envelope's `data`, which carries the useful part of several
  /// refusals: `next_opening` on calling hours, `allowed` on a bad transition.
  final Map<String, dynamic>? data;

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

  /// True when the request never reached the server, so the work is not lost —
  /// it was never attempted. This is the signal the offline outbox waits for.
  bool get isTransport => this is NetworkException;

  /// Whether retrying this exact request could ever succeed.
  ///
  /// A validation failure or a write-once conflict will fail identically
  /// forever; a transport error or a 5xx may not.
  bool get isRetryable => isTransport || (statusCode != null && statusCode! >= 500) || statusCode == 429;

  /// Builds the right subtype from a response.
  factory ApiException.fromResponse(int statusCode, ApiEnvelope? envelope, {String? fallbackMessage}) {
    final message = (envelope?.message.isNotEmpty ?? false)
        ? envelope!.message
        : fallbackMessage ?? 'The server returned an unexpected error ($statusCode).';
    final code = envelope?.code ?? _codeForStatus(statusCode);
    final errors = envelope?.errors ?? const <ApiError>[];
    final data = envelope?.dataMap;

    switch (statusCode) {
      case 401:
        return UnauthenticatedException(code: code, message: message, errors: errors, data: data);
      case 403:
        return ForbiddenException(code: code, message: message, errors: errors, data: data);
      case 404:
        return NotFoundException(code: code, message: message, errors: errors, data: data);
      case 409:
        return ConflictException(code: code, message: message, errors: errors, data: data);
      case 422:
        return ValidationException(code: code, message: message, errors: errors, data: data);
      case 429:
        return RateLimitedException(code: code, message: message, errors: errors, data: data);
    }

    if (statusCode >= 500) {
      return ServerException(code: code, message: message, statusCode: statusCode, errors: errors, data: data);
    }

    return ApiException(code: code, message: message, statusCode: statusCode, errors: errors, data: data);
  }

  static String _codeForStatus(int statusCode) {
    if (statusCode >= 500) {
      return 'server.error';
    }

    return 'client.http_$statusCode';
  }

  @override
  String toString() => '$runtimeType($code): $message';
}

/// The request never got a response: no network, DNS failure, timeout, or a
/// connection dropped mid-flight.
///
/// Deliberately distinct from a 5xx. "We could not ask" and "we asked and it
/// broke" have different safe responses, and only the first is safe to retry
/// blindly for a non-idempotent write.
class NetworkException extends ApiException {
  const NetworkException({String? message, this.cause})
      : super(
          code: 'client.offline',
          message: message ?? 'No connection to the CRM. Check your network and try again.',
        );

  final Object? cause;
}

/// A response arrived but it was not the envelope — an HTML login page, a proxy
/// error, a captive portal. Treated as a failure rather than parsed optimistically.
class MalformedResponseException extends ApiException {
  const MalformedResponseException({required super.statusCode, String? message})
      : super(
          code: 'client.malformed_response',
          message: message ?? 'The server sent a response this app could not read.',
        );
}

/// 401 — missing, invalid or expired token. Always ends in a return to login.
class UnauthenticatedException extends ApiException {
  const UnauthenticatedException({
    required super.code,
    required super.message,
    super.errors,
    super.data,
  }) : super(statusCode: 401);
}

/// 403 — authenticated but refused. This is the DNC gate (`dnc.suppressed`) and
/// the calling-hours gate (`call.outside_calling_hours`).
class ForbiddenException extends ApiException {
  const ForbiddenException({
    required super.code,
    required super.message,
    super.errors,
    super.data,
  }) : super(statusCode: 403);
}

class NotFoundException extends ApiException {
  const NotFoundException({
    required super.code,
    required super.message,
    super.errors,
    super.data,
  }) : super(statusCode: 404);
}

/// 409 — conflict. Notably a second `PATCH /calls/{id}`: outcomes are
/// write-once, so this means "already recorded", which for a retried queue
/// entry is success, not failure.
class ConflictException extends ApiException {
  const ConflictException({
    required super.code,
    required super.message,
    super.errors,
    super.data,
  }) : super(statusCode: 409);
}

/// 422 — validation. `errors[]` carries the per-field detail.
class ValidationException extends ApiException {
  const ValidationException({
    required super.code,
    required super.message,
    super.errors,
    super.data,
  }) : super(statusCode: 422);
}

class RateLimitedException extends ApiException {
  const RateLimitedException({
    required super.code,
    required super.message,
    super.errors,
    super.data,
  }) : super(statusCode: 429);
}

class ServerException extends ApiException {
  const ServerException({
    required super.code,
    required super.message,
    required super.statusCode,
    super.errors,
    super.data,
  });
}
