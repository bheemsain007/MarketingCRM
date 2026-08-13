import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;

import 'api_envelope.dart';
import 'api_exception.dart';

/// Supplies the bearer token for the next request, or null when signed out.
typedef TokenProvider = Future<String?> Function();

/// Called exactly once per 401, before the exception is thrown.
typedef UnauthenticatedHandler = Future<void> Function();

/// The single HTTP door out of this app.
///
/// It owns two things that must never be duplicated elsewhere:
///
///  1. **The envelope.** Every response is `{success, message, data, errors}`,
///     so exactly one place decodes it and exactly one place decides what a
///     non-2xx means (API_DOCUMENTATION §2, NFR-03).
///  2. **The 401 rule.** An expired token is handled centrally, so no screen
///     can forget to sign the user out.
///
/// It knows nothing about leads, calls or follow-ups — repositories do. That is
/// what keeps the business rules on the server (NFR-05): this class cannot
/// express a rule even if someone wanted it to.
class ApiClient {
  ApiClient({
    required this.apiRoot,
    http.Client? httpClient,
    TokenProvider? tokenProvider,
    this.timeout = const Duration(seconds: 20),
  })  : _http = httpClient ?? http.Client(),
        _tokenProvider = tokenProvider ?? _noToken;

  /// Fully-qualified versioned root, e.g. `https://crm.example.com/api/v1`.
  final String apiRoot;

  final Duration timeout;

  final http.Client _http;
  final TokenProvider _tokenProvider;

  /// Set after construction by the auth layer — the client and the auth
  /// controller need each other, and a mutable hook is honester than a
  /// service-locator lookup hidden inside a method.
  UnauthenticatedHandler? onUnauthenticated;

  static Future<String?> _noToken() async => null;

  Future<ApiEnvelope> get(String path, {Map<String, dynamic>? query}) {
    return _send('GET', path, query: query);
  }

  Future<ApiEnvelope> post(String path, {Map<String, dynamic>? body, String? idempotencyKey}) {
    return _send('POST', path, body: body, idempotencyKey: idempotencyKey);
  }

  Future<ApiEnvelope> patch(String path, {Map<String, dynamic>? body, String? idempotencyKey}) {
    return _send('PATCH', path, body: body, idempotencyKey: idempotencyKey);
  }

  Future<ApiEnvelope> delete(String path, {Map<String, dynamic>? body}) {
    return _send('DELETE', path, body: body);
  }

  /// Replays a request described by data rather than by a call site — the
  /// offline outbox stores method/path/body and has no other way to send them.
  Future<ApiEnvelope> send(
    String method,
    String path, {
    Map<String, dynamic>? body,
    String? idempotencyKey,
  }) {
    return _send(method, path, body: body, idempotencyKey: idempotencyKey);
  }

  void close() => _http.close();

  Future<ApiEnvelope> _send(
    String method,
    String path, {
    Map<String, dynamic>? query,
    Map<String, dynamic>? body,
    String? idempotencyKey,
  }) async {
    final uri = _uri(path, query);
    final request = http.Request(method, uri);

    request.headers['Accept'] = 'application/json';

    final token = await _tokenProvider();
    if (token != null && token.isNotEmpty) {
      request.headers['Authorization'] = 'Bearer $token';
    }

    if (idempotencyKey != null) {
      // A repeated key returns the original result rather than acting twice
      // (API_DOCUMENTATION §8) — the outbox depends on this for retries.
      request.headers['Idempotency-Key'] = idempotencyKey;
    }

    if (body != null) {
      request.headers['Content-Type'] = 'application/json';
      request.body = jsonEncode(body);
    }

    final http.Response response;
    try {
      final streamed = await _http.send(request).timeout(timeout);
      response = await http.Response.fromStream(streamed);
    } on TimeoutException catch (error) {
      throw NetworkException(
        message: 'The CRM did not respond in time. Your work is not lost — try again.',
        cause: error,
      );
    } on SocketException catch (error) {
      throw NetworkException(cause: error);
    } on http.ClientException catch (error) {
      throw NetworkException(cause: error);
    }

    return _interpret(response);
  }

  Future<ApiEnvelope> _interpret(http.Response response) async {
    final status = response.statusCode;
    final envelope = ApiEnvelope.tryParse(response.body);

    if (status == 401) {
      // Fire the hook before throwing, so the app is already signing out by the
      // time any screen sees the exception.
      await onUnauthenticated?.call();

      throw ApiException.fromResponse(status, envelope);
    }

    if (status >= 200 && status < 300) {
      if (status == 204 || response.body.trim().isEmpty) {
        return ApiEnvelope.empty;
      }

      if (envelope == null) {
        throw MalformedResponseException(statusCode: status);
      }

      if (!envelope.success) {
        // A 2xx that says it failed is a contract violation; trusting the
        // status over the body would show a success screen for a failure.
        throw ApiException.fromResponse(status, envelope);
      }

      return envelope;
    }

    throw ApiException.fromResponse(status, envelope);
  }

  Uri _uri(String path, Map<String, dynamic>? query) {
    final uri = Uri.parse('$apiRoot$path');

    if (query == null || query.isEmpty) {
      return uri;
    }

    final params = <String, String>{};
    query.forEach((key, value) {
      if (value == null) {
        return;
      }
      params[key] = '$value';
    });

    if (params.isEmpty) {
      return uri;
    }

    return uri.replace(queryParameters: <String, String>{...uri.queryParameters, ...params});
  }
}
