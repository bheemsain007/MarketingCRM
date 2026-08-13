import '../core/api/api_client.dart';
import '../core/api/api_exception.dart';
import '../core/api/paginated.dart';
import '../models/call.dart';
import '../models/callability.dart';
import '../models/lead.dart';

/// Reads over the lead book.
///
/// Every list here is server-scoped: a telecaller's `GET /leads` is already
/// their own book, so this class adds no "mine" filter of its own. Data scope
/// is applied before client filters server-side, so a filter can narrow the
/// view but never widen it (API_DOCUMENTATION §Leads).
class LeadRepository {
  LeadRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  /// `GET /leads` — paginated, searchable.
  ///
  /// `q` is the API's own free-text search over the resource's searchable
  /// fields. No client-side filtering happens anywhere in this app: a list
  /// filtered on the handset would show a telecaller a subset of a page and
  /// call it a result set.
  Future<Paginated<Lead>> list({int page = 1, String? query, int perPage = 25}) async {
    final trimmed = query?.trim();

    final envelope = await _api.get('/leads', query: <String, dynamic>{
      'page': page,
      'per_page': perPage,
      'include': 'assignedUser,source',
      if (trimmed != null && trimmed.isNotEmpty) 'q': trimmed,
    });

    return Paginated.fromEnvelope(envelope, Lead.fromJson);
  }

  /// `GET /leads/{id}`.
  ///
  /// Throws [NotFoundException] for a lead that does not exist and
  /// [ForbiddenException] for one belonging to a colleague — `LeadPolicy` is
  /// the second of the two authorisation layers, and the app simply reports
  /// whichever it is told.
  Future<Lead> show(int id) async {
    final envelope = await _api.get('/leads/$id', query: const <String, dynamic>{
      'include': 'assignedUser,source',
    });

    final data = envelope.dataMap;
    if (data == null) {
      throw const MalformedResponseException(statusCode: 200);
    }

    return Lead.fromJson(data);
  }

  /// `GET /leads/{id}/callability` — the gate, asked rather than guessed.
  ///
  /// A transport failure resolves to [Callability.unreachable], which refuses.
  /// Failing closed is the only defensible default: the checks behind this
  /// endpoint are the DNC list and the calling-hours window, and getting either
  /// wrong is a regulatory problem rather than a bug report.
  Future<Callability> callability(int leadId) async {
    try {
      final envelope = await _api.get('/leads/$leadId/callability');
      final data = envelope.dataMap;

      if (data == null) {
        return Callability.unreachable;
      }

      return Callability.fromJson(data);
    } on NetworkException {
      return Callability.unreachable;
    } on ServerException {
      return Callability.unreachable;
    }
  }

  /// `GET /leads/{id}/calls` — this lead's call history, newest first.
  Future<Paginated<Call>> calls(int leadId, {int page = 1, int perPage = 25}) async {
    final envelope = await _api.get('/leads/$leadId/calls', query: <String, dynamic>{
      'page': page,
      'per_page': perPage,
      'sort': '-started_at',
    });

    return Paginated.fromEnvelope(envelope, Call.fromJson);
  }
}
