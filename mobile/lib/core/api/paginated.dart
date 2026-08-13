import 'api_envelope.dart';
import 'api_exception.dart';

/// `data.meta` on every list endpoint (API_DOCUMENTATION §4).
class PageMeta {
  const PageMeta({
    required this.currentPage,
    required this.perPage,
    required this.total,
    required this.lastPage,
  });

  factory PageMeta.fromJson(Map<String, dynamic> json) {
    return PageMeta(
      currentPage: _int(json['current_page'], 1),
      perPage: _int(json['per_page'], 25),
      total: _int(json['total'], 0),
      lastPage: _int(json['last_page'], 1),
    );
  }

  final int currentPage;
  final int perPage;
  final int total;
  final int lastPage;

  bool get hasMore => currentPage < lastPage;

  static const PageMeta empty = PageMeta(currentPage: 1, perPage: 25, total: 0, lastPage: 1);

  static int _int(Object? value, int fallback) {
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
}

/// A page of a list endpoint: `data.items` plus `data.meta`.
class Paginated<T> {
  const Paginated({required this.items, required this.meta});

  final List<T> items;
  final PageMeta meta;

  bool get hasMore => meta.hasMore;

  /// Reads the standard list shape out of an envelope.
  ///
  /// Every list in the API looks like this, so no repository re-derives it —
  /// and a response that does not look like this is a contract violation the
  /// client should notice rather than silently render as empty.
  static Paginated<T> fromEnvelope<T>(
    ApiEnvelope envelope,
    T Function(Map<String, dynamic> json) parse,
  ) {
    final data = envelope.dataMap;
    final rawItems = data?['items'];

    if (rawItems is! List) {
      throw const MalformedResponseException(
        statusCode: 200,
        message: 'The server returned a list without any items array.',
      );
    }

    final items = rawItems
        .whereType<Map<String, dynamic>>()
        .map(parse)
        .toList(growable: false);

    final rawMeta = data?['meta'];

    return Paginated<T>(
      items: items,
      meta: rawMeta is Map<String, dynamic> ? PageMeta.fromJson(rawMeta) : PageMeta.empty,
    );
  }
}
