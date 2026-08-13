import '../core/api/api_client.dart';
import '../core/api/api_exception.dart';
import '../core/api/paginated.dart';
import '../models/follow_up.dart';

/// The telecaller's own diary.
class FollowUpRepository {
  FollowUpRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  /// `GET /follow-ups` — mine, soonest first.
  ///
  /// The default set is deliberately not requested with a status filter. The
  /// server defaults to open **and missed**, because a missed follow-up is late
  /// rather than void, and a client that asked only for `open` would quietly
  /// hide every commitment somebody had already dropped.
  Future<Paginated<FollowUp>> mine({int page = 1, int perPage = 25}) async {
    final envelope = await _api.get('/follow-ups', query: <String, dynamic>{
      'page': page,
      'per_page': perPage,
      'include': 'lead,product',
    });

    return Paginated.fromEnvelope(envelope, FollowUp.fromJson);
  }

  /// `POST /follow-ups/{id}/complete`.
  ///
  /// Allowed on a missed follow-up too — the server decides that, and this app
  /// offers the action on everything the list gave it rather than deciding for
  /// itself which rows are still actionable.
  Future<FollowUp> complete(int id, {String? outcome}) async {
    final trimmed = outcome?.trim();

    final envelope = await _api.post('/follow-ups/$id/complete', body: <String, dynamic>{
      if (trimmed != null && trimmed.isNotEmpty) 'outcome': trimmed,
    });

    final data = envelope.dataMap;
    if (data == null) {
      throw const MalformedResponseException(statusCode: 200);
    }

    return FollowUp.fromJson(data);
  }
}
