import '../core/api/api_client.dart';
import '../core/api/api_exception.dart';

/// Whether the caller has an open work session and is on a break right now.
///
/// Nothing else answers this - a screen reopened mid-break has no other way
/// to know which label is correct without it.
class AttendanceStatus {
  const AttendanceStatus({required this.hasOpenSession, required this.isOnBreak});

  factory AttendanceStatus.fromJson(Map<String, dynamic> json) {
    return AttendanceStatus(
      hasOpenSession: json['has_open_session'] as bool? ?? false,
      isOnBreak: json['is_on_break'] as bool? ?? false,
    );
  }

  final bool hasOpenSession;
  final bool isOnBreak;
}

/// Explicit break start/stop (FR-ATT-04) - the same three endpoints the web
/// header toggle uses, so the two clients cannot disagree about the rules.
class AttendanceRepository {
  AttendanceRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  Future<AttendanceStatus> status() async {
    final envelope = await _api.get('/attendance/status');
    final data = envelope.dataMap;
    if (data == null) {
      throw const MalformedResponseException(statusCode: 200);
    }

    return AttendanceStatus.fromJson(data);
  }

  Future<void> startBreak() async {
    await _api.post('/attendance/breaks/start');
  }

  Future<void> stopBreak() async {
    await _api.post('/attendance/breaks/stop');
  }
}
