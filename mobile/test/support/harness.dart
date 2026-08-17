import 'package:marketing_crm_mobile/core/api/api_client.dart';
import 'package:marketing_crm_mobile/core/app_config.dart';
import 'package:marketing_crm_mobile/core/connectivity/connectivity_monitor.dart';
import 'package:marketing_crm_mobile/core/di/dependencies.dart';
import 'package:marketing_crm_mobile/core/offline/outbox.dart';
import 'package:marketing_crm_mobile/core/offline/outbox_flusher.dart';
import 'package:marketing_crm_mobile/core/storage/key_value_store.dart';
import 'package:marketing_crm_mobile/core/storage/token_store.dart';
import 'package:marketing_crm_mobile/core/telephony/phone_dialer.dart';
import 'package:marketing_crm_mobile/repositories/auth_repository.dart';
import 'package:marketing_crm_mobile/repositories/call_repository.dart';
import 'package:marketing_crm_mobile/repositories/follow_up_repository.dart';
import 'package:marketing_crm_mobile/repositories/lead_repository.dart';
import 'package:marketing_crm_mobile/repositories/message_repository.dart';
import 'package:marketing_crm_mobile/repositories/template_repository.dart';
import 'package:marketing_crm_mobile/state/auth_controller.dart';

import 'scripted_api.dart';

/// The whole app graph, wired to fakes.
///
/// Nothing here touches a platform channel, which is what makes the offline
/// queue and the dial sequence testable at all — both are otherwise only
/// observable on hardware.
class Harness {
  Harness({String? token, bool online = true, ScriptedApi? api, PhoneDialer? dialer})
      : api = api ?? ScriptedApi(),
        tokenStore = InMemoryTokenStore(token),
        keyValueStore = InMemoryKeyValueStore(),
        connectivity = FakeConnectivityMonitor(online: online),
        dialer = dialer ?? RecordingDialer() {
    client = ApiClient(
      apiRoot: ScriptedApi.apiRoot,
      httpClient: this.api.build(),
      tokenProvider: tokenStore.read,
    );

    outbox = Outbox(keyValueStore);

    final authRepository = AuthRepository(api: client, tokenStore: tokenStore);

    dependencies = AppDependencies(
      config: const AppConfig(baseUrl: 'https://crm.test'),
      api: client,
      tokenStore: tokenStore,
      connectivity: connectivity,
      outbox: outbox,
      flusher: OutboxFlusher(outbox: outbox, api: client, connectivity: connectivity),
      dialer: this.dialer,
      authRepository: authRepository,
      leadRepository: LeadRepository(api: client),
      callRepository: CallRepository(api: client, outbox: outbox),
      followUpRepository: FollowUpRepository(api: client),
      messageRepository: MessageRepository(api: client),
      templateRepository: TemplateRepository(api: client),
      auth: AuthController(repository: authRepository),
    );
  }

  final ScriptedApi api;
  final InMemoryTokenStore tokenStore;
  final InMemoryKeyValueStore keyValueStore;
  final FakeConnectivityMonitor connectivity;
  final PhoneDialer dialer;

  late final ApiClient client;
  late final Outbox outbox;
  late final AppDependencies dependencies;

  RecordingDialer get recordingDialer => dialer as RecordingDialer;

  /// Tears the graph down. **Unit tests only — never call this from a
  /// `testWidgets` body.**
  ///
  /// A widget test runs inside `FakeAsync`, and the framework owns the tree's
  /// lifecycle: it unmounts everything after the body and then waits for the
  /// zone to go quiet. Disposing the graph from inside the body leaves that
  /// wait unsatisfiable, and the test hangs until the runner gives up -
  /// reported as "did not complete", which reads like a timeout rather than
  /// the self-inflicted deadlock it is. Six widget tests were lost to exactly
  /// that.
  ///
  /// Nothing is leaked by omitting it. The real app never calls
  /// `AppDependencies.dispose()` either - grep `lib/` - because the graph is
  /// built once at launch and lives as long as the process, which on Android
  /// ends when the OS kills it. This method exists for the unit tests, which
  /// mount no tree and so can tear down deterministically.
  Future<void> dispose() => dependencies.dispose();
}
