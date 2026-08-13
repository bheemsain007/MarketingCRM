import 'json_reader.dart';

/// The signed-in user, as `UserResource` sends them.
///
/// [permissions] and [dataScope] are here for one reason only: hiding a control
/// the user cannot use. The server re-checks every request (SEC-AUTHZ-02), so
/// this app must never treat an absent permission as authorisation to skip a
/// call — only as a reason not to draw a button.
class User {
  const User({
    required this.id,
    required this.name,
    required this.email,
    required this.permissions,
    required this.roles,
    this.dataScope = 'own',
    this.isActive = true,
  });

  factory User.fromJson(Map<String, dynamic> json) {
    final rawRoles = json['roles'];
    final roles = rawRoles is List
        ? rawRoles
            .whereType<Map<String, dynamic>>()
            .map((role) => Json.string(role['label'], Json.string(role['name'])))
            .where((label) => label.isNotEmpty)
            .toList(growable: false)
        : const <String>[];

    return User(
      id: Json.integer(json['id']),
      name: Json.string(json['name']),
      email: Json.string(json['email']),
      permissions: Json.stringList(json['permissions']),
      roles: roles,
      dataScope: Json.string(json['data_scope'], 'own'),
      isActive: Json.boolean(json['is_active'], true),
    );
  }

  final int id;
  final String name;
  final String email;
  final List<String> permissions;
  final List<String> roles;
  final String dataScope;
  final bool isActive;

  bool can(String permission) => permissions.contains(permission);

  String get rolesLabel => roles.isEmpty ? 'No role assigned' : roles.join(', ');
}
