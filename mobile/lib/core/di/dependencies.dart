import 'package:flutter/widgets.dart';

import '../../repositories/auth_repository.dart';
import '../../repositories/call_repository.dart';
import '../../repositories/follow_up_repository.dart';
import '../../repositories/lead_repository.dart';
import '../../repositories/message_repository.dart';
import '../../repositories/template_repository.dart';
import '../../state/auth_controller.dart';
import '../api/api_client.dart';
import '../app_config.dart';
import '../connectivity/connectivity_monitor.dart';
import '../offline/outbox.dart';
import '../offline/outbox_flusher.dart';
import '../storage/key_value_store.dart';
import '../storage/token_store.dart';
import '../telephony/phone_dialer.dart';

/// Everything the app is built out of, in one object.
///
/// Constructor injection rather than a global registry: a test builds this with
/// a `MockClient`, an in-memory token store and a `RecordingDialer`, and gets a
/// complete app with no platform channels anywhere in it. Nothing in this app
/// reaches for a singleton, so there is no path by which a widget test can
/// accidentally touch the real network.
class AppDependencies {
  AppDependencies({
    required this.config,
    required this.api,
    required this.tokenStore,
    required this.connectivity,
    required this.outbox,
    required this.flusher,
    required this.dialer,
    required this.authRepository,
    required this.leadRepository,
    required this.callRepository,
    required this.followUpRepository,
    required this.messageRepository,
    required this.templateRepository,
    required this.auth,
  }) {
    // The client needs the controller and the controller needs the client, so
    // one of the two edges is wired after construction. This one, because it is
    // a hook rather than a dependency.
    api.onUnauthenticated = auth.handleUnauthenticated;
  }

  /// The real thing: secure storage, shared preferences, connectivity_plus and
  /// the platform dialer.
  factory AppDependencies.production({AppConfig? config}) {
    final resolved = config ?? AppConfig.fromEnvironment();
    final tokenStore = SecureTokenStore();

    final api = ApiClient(
      apiRoot: resolved.apiRoot,
      tokenProvider: tokenStore.read,
    );

    final connectivity = ConnectivityPlusMonitor();
    final outbox = Outbox(SharedPreferencesStore());

    // One repository instance, shared: two would mean two objects able to write
    // the token, which is one more than can be reasoned about.
    final authRepository = AuthRepository(api: api, tokenStore: tokenStore);

    return AppDependencies(
      config: resolved,
      api: api,
      tokenStore: tokenStore,
      connectivity: connectivity,
      outbox: outbox,
      flusher: OutboxFlusher(outbox: outbox, api: api, connectivity: connectivity),
      dialer: const UrlLauncherDialer(),
      authRepository: authRepository,
      leadRepository: LeadRepository(api: api),
      callRepository: CallRepository(api: api, outbox: outbox),
      followUpRepository: FollowUpRepository(api: api),
      messageRepository: MessageRepository(api: api),
      templateRepository: TemplateRepository(api: api),
      auth: AuthController(repository: authRepository),
    );
  }

  final AppConfig config;
  final ApiClient api;
  final TokenStore tokenStore;
  final ConnectivityMonitor connectivity;
  final Outbox outbox;
  final OutboxFlusher flusher;
  final PhoneDialer dialer;
  final AuthRepository authRepository;
  final LeadRepository leadRepository;
  final CallRepository callRepository;
  final FollowUpRepository followUpRepository;
  final MessageRepository messageRepository;
  final TemplateRepository templateRepository;
  final AuthController auth;

  Future<void> dispose() async {
    await flusher.dispose();
    connectivity.dispose();
    auth.dispose();
    outbox.dispose();
    api.close();
  }
}

/// Hands [AppDependencies] down the widget tree.
///
/// Plain `InheritedWidget` and no state-management package: the graph is built
/// once at launch and never changes, so anything more would be ceremony around
/// a constant.
class AppScope extends InheritedWidget {
  const AppScope({required this.dependencies, required super.child, super.key});

  final AppDependencies dependencies;

  static AppDependencies of(BuildContext context) {
    final scope = context.dependOnInheritedWidgetOfExactType<AppScope>();

    assert(scope != null, 'No AppScope found. Wrap the app in an AppScope.');

    return scope!.dependencies;
  }

  @override
  bool updateShouldNotify(AppScope oldWidget) => dependencies != oldWidget.dependencies;
}
