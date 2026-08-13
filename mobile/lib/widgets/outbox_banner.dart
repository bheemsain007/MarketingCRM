import 'package:flutter/material.dart';

import '../core/di/dependencies.dart';

/// Shows what has not reached the CRM yet.
///
/// A queue nobody can see is a queue that silently stops. A telecaller who
/// wrote up four calls in a basement needs to know those four are still on the
/// handset — and needs a way to push them by hand rather than waiting for the
/// connectivity event that may not come until they close the app.
class OutboxBanner extends StatelessWidget {
  const OutboxBanner({super.key});

  static const Key retryButtonKey = Key('outbox.retry');
  static const Key bannerKey = Key('outbox.banner');

  @override
  Widget build(BuildContext context) {
    final deps = AppScope.of(context);
    final theme = Theme.of(context);

    return ListenableBuilder(
      listenable: deps.outbox,
      builder: (context, _) {
        final pending = deps.outbox.pendingCount;
        final failed = deps.outbox.failedCount;

        if (pending == 0 && failed == 0) {
          return const SizedBox.shrink();
        }

        final isProblem = failed > 0;
        final background = isProblem ? theme.colorScheme.errorContainer : theme.colorScheme.secondaryContainer;
        final foreground = isProblem ? theme.colorScheme.onErrorContainer : theme.colorScheme.onSecondaryContainer;

        return Material(
          key: bannerKey,
          color: background,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 8, 8),
            child: Row(
              children: <Widget>[
                Icon(isProblem ? Icons.warning_amber_outlined : Icons.cloud_upload_outlined,
                    size: 20, color: foreground),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    _describe(pending, failed),
                    style: theme.textTheme.bodySmall?.copyWith(color: foreground),
                  ),
                ),
                TextButton(
                  key: retryButtonKey,
                  onPressed: () => deps.flusher.flush(),
                  child: Text('Send now', style: TextStyle(color: foreground)),
                ),
              ],
            ),
          ),
        );
      },
    );
  }

  static String _describe(int pending, int failed) {
    final parts = <String>[
      if (pending > 0) '$pending waiting to sync',
      if (failed > 0) '$failed rejected by the CRM',
    ];

    return parts.join(' · ');
  }
}
