/// Build-time configuration.
///
/// The base URL is a `--dart-define`, not a setting inside the app: a
/// telecaller must not be able to point a CRM client holding real lead data at
/// an arbitrary host, and a build that hard-codes staging is the one that ships.
///
///     flutter run --dart-define=CRM_BASE_URL=http://10.0.2.2:8000
class AppConfig {
  const AppConfig({required this.baseUrl});

  /// `10.0.2.2` is the host machine as seen from the Android emulator, which
  /// makes the default useful for the only environment we can actually run in.
  static const String _defaultBaseUrl = 'http://10.0.2.2:8000';

  factory AppConfig.fromEnvironment() {
    const raw = String.fromEnvironment('CRM_BASE_URL', defaultValue: _defaultBaseUrl);

    return AppConfig(baseUrl: normaliseBaseUrl(raw));
  }

  /// Scheme + host + optional port, with any trailing slash removed.
  ///
  /// The path prefix (`/api/v1`) belongs to the client, not to configuration —
  /// otherwise half the deployments carry it and half do not, and every request
  /// path has to cope with both.
  static String normaliseBaseUrl(String raw) {
    var value = raw.trim();

    while (value.endsWith('/')) {
      value = value.substring(0, value.length - 1);
    }

    return value;
  }

  final String baseUrl;

  /// Every request in this app goes through the versioned prefix.
  String get apiRoot => '$baseUrl/api/v1';

  /// The device identifies itself so the server can tell an app session from a
  /// browser one (it opens a work session either way — FR-ATT-01).
  static const String loginSource = 'android';
}
