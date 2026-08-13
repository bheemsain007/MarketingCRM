/// Defensive readers for API payloads.
///
/// The server is well-behaved, but a client that throws on one unexpected null
/// shows a telecaller a red screen instead of their lead list. These coerce and
/// carry on for presentational fields — never for identity or for anything a
/// decision is made on.
class Json {
  const Json._();

  static String string(Object? value, [String fallback = '']) {
    if (value is String) {
      return value;
    }
    if (value == null) {
      return fallback;
    }

    return '$value';
  }

  static String? stringOrNull(Object? value) {
    if (value is String && value.isNotEmpty) {
      return value;
    }

    return null;
  }

  static int integer(Object? value, [int fallback = 0]) {
    if (value is int) {
      return value;
    }
    if (value is num) {
      return value.toInt();
    }
    if (value is String) {
      return int.tryParse(value) ?? fallback;
    }

    return fallback;
  }

  static int? integerOrNull(Object? value) {
    if (value is int) {
      return value;
    }
    if (value is num) {
      return value.toInt();
    }
    if (value is String) {
      return int.tryParse(value);
    }

    return null;
  }

  static bool boolean(Object? value, [bool fallback = false]) {
    if (value is bool) {
      return value;
    }
    if (value is num) {
      return value != 0;
    }
    if (value is String) {
      return value == 'true' || value == '1';
    }

    return fallback;
  }

  /// ISO-8601 in, local `DateTime` out. The API always sends an offset, so
  /// `.toLocal()` is meaningful rather than a guess.
  static DateTime? dateTime(Object? value) {
    if (value is! String || value.isEmpty) {
      return null;
    }

    return DateTime.tryParse(value)?.toLocal();
  }

  static Map<String, dynamic>? map(Object? value) {
    return value is Map<String, dynamic> ? value : null;
  }

  static List<String> stringList(Object? value) {
    if (value is! List) {
      return const <String>[];
    }

    return value.whereType<String>().toList(growable: false);
  }
}
