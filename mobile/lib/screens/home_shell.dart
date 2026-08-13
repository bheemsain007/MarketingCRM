import 'package:flutter/material.dart';

import '../core/di/dependencies.dart';
import '../widgets/outbox_banner.dart';
import 'follow_up_screen.dart';
import 'lead_list_screen.dart';

/// The signed-in shell: leads, the diary, and the state of the outbox above
/// both of them.
class HomeShell extends StatefulWidget {
  const HomeShell({super.key});

  static const Key navigationKey = Key('home.navigation');
  static const Key signOutKey = Key('home.signOut');

  @override
  State<HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends State<HomeShell> {
  int _index = 0;

  @override
  void initState() {
    super.initState();

    WidgetsBinding.instance.addPostFrameCallback((_) async {
      if (!mounted) {
        return;
      }

      final deps = AppScope.of(context);

      // Anything queued from a previous session is sent as soon as this one
      // starts — the app being reopened is itself evidence of a working device,
      // and often of a working network.
      await deps.outbox.load();
      deps.flusher.start();
      await deps.flusher.flush();
    });
  }

  @override
  Widget build(BuildContext context) {
    final deps = AppScope.of(context);
    final user = deps.auth.user;

    return Scaffold(
      appBar: AppBar(
        title: Text(_index == 0 ? 'Leads' : 'Follow-ups'),
        actions: <Widget>[
          if (user != null)
            Padding(
              padding: const EdgeInsets.only(right: 4),
              child: Center(
                child: Text(
                  user.name,
                  style: Theme.of(context).textTheme.bodySmall,
                ),
              ),
            ),
          IconButton(
            key: HomeShell.signOutKey,
            tooltip: 'Sign out',
            icon: const Icon(Icons.logout),
            onPressed: () => deps.auth.signOut(),
          ),
        ],
      ),
      body: Column(
        children: <Widget>[
          const OutboxBanner(),
          Expanded(
            child: IndexedStack(
              index: _index,
              children: const <Widget>[
                LeadListScreen(),
                FollowUpScreen(),
              ],
            ),
          ),
        ],
      ),
      bottomNavigationBar: NavigationBar(
        key: HomeShell.navigationKey,
        selectedIndex: _index,
        onDestinationSelected: (value) => setState(() => _index = value),
        destinations: const <Widget>[
          NavigationDestination(icon: Icon(Icons.people_outline), label: 'Leads'),
          NavigationDestination(icon: Icon(Icons.event_note_outlined), label: 'Follow-ups'),
        ],
      ),
    );
  }
}
