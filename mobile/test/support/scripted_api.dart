import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// A scripted backend.
///
/// Tests drive the real [ApiClient] against this rather than against a mocked
/// client interface, so the envelope decoding, the header building and the
/// status-to-exception mapping are all genuinely exercised. A test that mocked
/// the repository instead would pass while the envelope contract was broken.
class ScriptedApi {
  final Map<String, http.Response Function(http.Request request)> _routes =
      <String, http.Response Function(http.Request)>{};

  /// Every request the client *attempted*, in order — including the ones that
  /// died at the socket while [offline].
  final List<RecordedRequest> received = <RecordedRequest>[];

  /// Only the requests that actually reached the "server" and got a response.
  ///
  /// The distinction is the whole point of the offline tests: an attempt that
  /// never landed changed nothing, so re-sending it cannot duplicate anything.
  /// "Sent exactly once" is a claim about this list.
  final List<RecordedRequest> delivered = <RecordedRequest>[];

  /// When true, every request throws as though the radio were off.
  bool offline = false;

  static const String apiRoot = 'https://crm.test/api/v1';

  void on(
    String method,
    String path,
    http.Response Function(http.Request request) responder,
  ) {
    _routes['$method $path'] = responder;
  }

  /// Convenience: reply with an envelope.
  void reply(
    String method,
    String path, {
    int status = 200,
    bool success = true,
    String message = 'OK',
    Object? data,
    List<Map<String, dynamic>> errors = const <Map<String, dynamic>>[],
  }) {
    on(method, path, (_) {
      return http.Response(
        envelopeJson(success: success, message: message, data: data, errors: errors),
        status,
        headers: const <String, String>{'content-type': 'application/json'},
      );
    });
  }

  /// Replies differently on each call, so a retry can be scripted to succeed.
  void sequence(String method, String path, List<http.Response> responses) {
    var index = 0;

    on(method, path, (_) {
      final response = responses[index.clamp(0, responses.length - 1)];
      index++;

      return response;
    });
  }

  /// How many times this request actually reached the server.
  int countOf(String method, String path) {
    return delivered.where((request) => request.method == method && request.path == path).length;
  }

  /// How many times the client tried, landed or not.
  int attemptsOf(String method, String path) {
    return received.where((request) => request.method == method && request.path == path).length;
  }

  RecordedRequest? lastOf(String method, String path) {
    final matches = delivered.where((r) => r.method == method && r.path == path);

    return matches.isEmpty ? null : matches.last;
  }

  http.Client build() {
    return MockClient((request) async {
      final path = request.url.path.replaceFirst('/api/v1', '');

      final recorded = RecordedRequest(
        method: request.method,
        path: path,
        query: request.url.queryParameters,
        headers: Map<String, String>.from(request.headers),
        body: request.body,
      );

      received.add(recorded);

      if (offline) {
        throw const SocketException('Network is unreachable');
      }

      delivered.add(recorded);

      final responder = _routes['${request.method} $path'];

      if (responder == null) {
        return http.Response(
          envelopeJson(
            success: false,
            message: 'No route scripted for ${request.method} $path',
            errors: const <Map<String, dynamic>>[
              <String, dynamic>{'code': 'test.unrouted', 'message': 'unrouted'},
            ],
          ),
          404,
          headers: const <String, String>{'content-type': 'application/json'},
        );
      }

      return responder(request);
    });
  }
}

class RecordedRequest {
  const RecordedRequest({
    required this.method,
    required this.path,
    required this.query,
    required this.headers,
    required this.body,
  });

  final String method;
  final String path;
  final Map<String, String> query;
  final Map<String, String> headers;
  final String body;

  Map<String, dynamic> get json =>
      body.isEmpty ? <String, dynamic>{} : jsonDecode(body) as Map<String, dynamic>;

  String? header(String name) {
    for (final entry in headers.entries) {
      if (entry.key.toLowerCase() == name.toLowerCase()) {
        return entry.value;
      }
    }

    return null;
  }
}

String envelopeJson({
  bool success = true,
  String message = 'OK',
  Object? data,
  List<Map<String, dynamic>> errors = const <Map<String, dynamic>>[],
}) {
  return jsonEncode(<String, dynamic>{
    'success': success,
    'message': message,
    'data': data,
    'errors': errors,
  });
}

Map<String, dynamic> listData(
  List<Map<String, dynamic>> items, {
  int currentPage = 1,
  int perPage = 25,
  int? total,
  int lastPage = 1,
}) {
  return <String, dynamic>{
    'items': items,
    'meta': <String, dynamic>{
      'current_page': currentPage,
      'per_page': perPage,
      'total': total ?? items.length,
      'last_page': lastPage,
    },
  };
}
