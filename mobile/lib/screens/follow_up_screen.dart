import 'package:flutter/material.dart';

import '../core/api/api_exception.dart';
import '../core/di/dependencies.dart';
import '../models/follow_up.dart';
import '../theme/status_colors.dart';
import '../widgets/animations.dart';
import '../widgets/state_views.dart';
import 'lead_detail_screen.dart';

/// `GET /follow-ups` — the caller's own diary, soonest first, with completion.
class FollowUpScreen extends StatefulWidget {
  const FollowUpScreen({super.key});

  static const Key listKey = Key('followUps.list');

  static Key completeKey(int id) => Key('followUps.complete.$id');

  @override
  State<FollowUpScreen> createState() => _FollowUpScreenState();
}

class _FollowUpScreenState extends State<FollowUpScreen> {
  List<FollowUp> _items = const <FollowUp>[];
  bool _loading = true;
  String? _error;
  int? _completing;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Future<void> _load() async {
    final repository = AppScope.of(context).followUpRepository;

    if (mounted) {
      setState(() {
        _loading = true;
        _error = null;
      });
    }

    try {
      final page = await repository.mine();

      if (!mounted) {
        return;
      }

      setState(() {
        _items = page.items;
        _loading = false;
      });
    } on ApiException catch (error) {
      if (!mounted) {
        return;
      }

      setState(() {
        _error = error.message;
        _loading = false;
      });
    }
  }

  Future<void> _complete(FollowUp followUp) async {
    final repository = AppScope.of(context).followUpRepository;
    final outcome = await _askForOutcome(followUp);

    if (outcome == null || !mounted) {
      return;
    }

    setState(() => _completing = followUp.id);

    try {
      await repository.complete(followUp.id, outcome: outcome.isEmpty ? null : outcome);

      if (!mounted) {
        return;
      }

      ScaffoldMessenger.of(context)
        ..hideCurrentSnackBar()
        ..showSnackBar(const SnackBar(content: Text('Follow-up completed.')));

      await _load();
    } on ApiException catch (error) {
      if (!mounted) {
        return;
      }

      ScaffoldMessenger.of(context)
        ..hideCurrentSnackBar()
        ..showSnackBar(SnackBar(content: Text(error.message)));
    } finally {
      if (mounted) {
        setState(() => _completing = null);
      }
    }
  }

  Future<String?> _askForOutcome(FollowUp followUp) {
    final controller = TextEditingController();

    return showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Complete follow-up'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Text(followUp.title),
            const SizedBox(height: 12),
            TextField(
              controller: controller,
              minLines: 2,
              maxLines: 4,
              decoration: const InputDecoration(
                labelText: 'What happened? (optional)',
                border: OutlineInputBorder(),
              ),
            ),
          ],
        ),
        actions: <Widget>[
          TextButton(onPressed: () => Navigator.of(context).pop(), child: const Text('Cancel')),
          FilledButton(
            onPressed: () => Navigator.of(context).pop(controller.text.trim()),
            child: const Text('Complete'),
          ),
        ],
      ),
    ).whenComplete(controller.dispose);
  }

  @override
  Widget build(BuildContext context) {
    if (_loading && _items.isEmpty) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_error != null && _items.isEmpty) {
      return ErrorView(message: _error!, onRetry: _load);
    }

    if (_items.isEmpty) {
      return RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          children: <Widget>[
            SizedBox(height: MediaQuery.sizeOf(context).height * 0.2),
            const EmptyView(
              message: 'Nothing in your diary. Well played.',
              icon: Icons.event_available_outlined,
            ),
          ],
        ),
      );
    }

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView.separated(
        key: FollowUpScreen.listKey,
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(12, 4, 12, 12),
        itemCount: _items.length,
        separatorBuilder: (_, _) => const SizedBox(height: 8),
        itemBuilder: (context, index) => FadeSlideIn.staggered(
          index: index,
          child: _FollowUpTile(
            followUp: _items[index],
            busy: _completing == _items[index].id,
            onComplete: () => _complete(_items[index]),
          ),
        ),
      ),
    );
  }
}

class _FollowUpTile extends StatelessWidget {
  const _FollowUpTile({required this.followUp, required this.busy, required this.onComplete});

  final FollowUp followUp;
  final bool busy;
  final VoidCallback onComplete;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Card(
      child: PressableScale(
        child: ListTile(
          title: Text(followUp.leadName ?? followUp.title),
          subtitle: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              if (followUp.subject != null) Text(followUp.subject!),
              const SizedBox(height: 4),
              Row(
                children: <Widget>[
                  // `is_overdue` comes from the server, which evaluated it
                  // against its own clock. This app renders the flag; it
                  // never derives it.
                  StatusChip(
                    label: followUp.isOverdue ? 'Overdue' : followUp.statusLabel,
                    colors: followUp.isOverdue
                        ? (Theme.of(context).colorScheme.errorContainer, Theme.of(context).colorScheme.onErrorContainer)
                        : StatusColors.followUpStatus(context, followUp.status),
                  ),
                  const SizedBox(width: 8),
                  if (followUp.scheduledAt != null)
                    Text(
                      _formatDateTime(followUp.scheduledAt!),
                      style: theme.textTheme.bodySmall,
                    ),
                ],
              ),
            ],
          ),
          isThreeLine: true,
          onTap: () => Navigator.of(context).push(
            MaterialPageRoute<void>(builder: (_) => LeadDetailScreen(leadId: followUp.leadId)),
          ),
          trailing: AppSwitcher(
            child: busy
                ? const SizedBox(
                    key: ValueKey('busy'),
                    height: 20,
                    width: 20,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : IconButton(
                    key: FollowUpScreen.completeKey(followUp.id),
                    tooltip: 'Complete',
                    icon: const Icon(Icons.check_circle_outline, key: ValueKey('idle')),
                    onPressed: onComplete,
                  ),
          ),
        ),
      ),
    );
  }
}

String _formatDateTime(DateTime value) {
  String two(int n) => n.toString().padLeft(2, '0');

  return '${two(value.day)}/${two(value.month)} ${two(value.hour)}:${two(value.minute)}';
}
