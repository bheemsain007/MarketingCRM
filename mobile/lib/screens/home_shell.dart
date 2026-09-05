import 'package:flutter/material.dart';

import '../core/api/api_exception.dart';
import '../core/di/dependencies.dart';
import '../widgets/outbox_banner.dart';
import 'follow_up_screen.dart';
import 'lead_list_screen.dart';
import 'template_management_screen.dart';

/// The signed-in shell: leads, the diary, and the state of the outbox above
/// both of them.
class HomeShell extends StatefulWidget {
  const HomeShell({super.key});

  static const Key navigationKey = Key('home.navigation');
  static const Key signOutKey = Key('home.signOut');
  static const Key templatesKey = Key('home.templates');
  static const Key breakToggleKey = Key('home.breakToggle');

  @override
  State<HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends State<HomeShell> {
  int _index = 0;

  // Null until /attendance/status answers - the toggle stays hidden rather
  // than guessing, the same reason the web header's button ships hidden
  // (FR-ATT-04). Not open at all (no session) also hides it: there is
  // nothing to start a break on.
  bool? _hasOpenSession;
  bool _isOnBreak = false;
  bool _breakBusy = false;

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

      await _loadBreakStatus(deps);
    });
  }

  /// Silent on failure, like the web bell's own poll: a missing toggle costs
  /// nothing an error toast would fix, and session loss is already handled by
  /// [AppDependencies.api]'s 401 hook.
  Future<void> _loadBreakStatus(AppDependencies deps) async {
    try {
      final status = await deps.attendanceRepository.status();
      if (!mounted) {
        return;
      }

      setState(() {
        _hasOpenSession = status.hasOpenSession;
        _isOnBreak = status.isOnBreak;
      });
    } on ApiException {
      // Leave it hidden (_hasOpenSession stays null) rather than show a
      // button that would 422 on every tap.
    }
  }

  Future<void> _toggleBreak() async {
    final deps = AppScope.of(context);

    setState(() => _breakBusy = true);

    try {
      if (_isOnBreak) {
        await deps.attendanceRepository.stopBreak();
      } else {
        await deps.attendanceRepository.startBreak();
      }

      if (!mounted) {
        return;
      }

      setState(() {
        _isOnBreak = !_isOnBreak;
        _breakBusy = false;
      });
    } on ApiException catch (error) {
      if (!mounted) {
        return;
      }

      setState(() => _breakBusy = false);

      // The server's own sentence, verbatim - it already names the exact
      // reason (already on break, no open session), which a paraphrase here
      // would only blur.
      ScaffoldMessenger.of(context)
        ..hideCurrentSnackBar()
        ..showSnackBar(SnackBar(content: Text(error.message)));
    }
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
          // `templates.view`, not `templates.manage`: every sender needs to
          // browse templates to pick one when composing, which is a broader
          // audience than who may author them (TemplateManagementScreen hides
          // the write controls itself for the narrower group).
          if (user?.can('templates.view') ?? false)
            IconButton(
              key: HomeShell.templatesKey,
              tooltip: 'Templates',
              icon: const Icon(Icons.description_outlined),
              onPressed: () => Navigator.of(context).push(
                MaterialPageRoute<void>(builder: (_) => const TemplateManagementScreen()),
              ),
            ),
          if (_hasOpenSession ?? false)
            IconButton(
              key: HomeShell.breakToggleKey,
              tooltip: _isOnBreak ? 'End break' : 'Take a break',
              icon: Icon(_isOnBreak ? Icons.play_circle_outline : Icons.free_breakfast_outlined),
              onPressed: _breakBusy ? null : _toggleBreak,
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
