import 'package:flutter/material.dart';

/// Full-panel failure state.
///
/// The message is always the server's own. This app never rewrites a refusal
/// into friendlier words: "outside calling hours in this lead's timezone" is
/// the sentence the telecaller needs, and a generic "something went wrong"
/// would cost them a support call to find out the same thing.
class ErrorView extends StatelessWidget {
  const ErrorView({required this.message, this.onRetry, super.key});

  final String message;
  final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Icon(Icons.cloud_off_outlined, size: 48, color: theme.colorScheme.outline),
            const SizedBox(height: 16),
            Text(message, textAlign: TextAlign.center, style: theme.textTheme.bodyMedium),
            if (onRetry != null) ...<Widget>[
              const SizedBox(height: 16),
              OutlinedButton.icon(
                onPressed: onRetry,
                icon: const Icon(Icons.refresh),
                label: const Text('Try again'),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class EmptyView extends StatelessWidget {
  const EmptyView({required this.message, this.icon = Icons.inbox_outlined, super.key});

  final String message;
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Icon(icon, size: 48, color: theme.colorScheme.outline),
            const SizedBox(height: 16),
            Text(
              message,
              textAlign: TextAlign.center,
              style: theme.textTheme.bodyMedium?.copyWith(color: theme.colorScheme.onSurfaceVariant),
            ),
          ],
        ),
      ),
    );
  }
}

/// A status pill. Always fed a label the server produced (`status_label`), not
/// one derived from the raw enum value here.
class StatusChip extends StatelessWidget {
  const StatusChip({required this.label, this.icon, this.tone = ChipTone.neutral, super.key});

  final String label;
  final IconData? icon;
  final ChipTone tone;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final (Color background, Color foreground) = switch (tone) {
      ChipTone.neutral => (theme.colorScheme.surfaceContainerHighest, theme.colorScheme.onSurfaceVariant),
      ChipTone.positive => (theme.colorScheme.primaryContainer, theme.colorScheme.onPrimaryContainer),
      ChipTone.warning => (theme.colorScheme.tertiaryContainer, theme.colorScheme.onTertiaryContainer),
      ChipTone.danger => (theme.colorScheme.errorContainer, theme.colorScheme.onErrorContainer),
    };

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(color: background, borderRadius: BorderRadius.circular(999)),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          if (icon != null) ...<Widget>[
            Icon(icon, size: 14, color: foreground),
            const SizedBox(width: 4),
          ],
          Text(
            label,
            style: theme.textTheme.labelMedium?.copyWith(color: foreground),
          ),
        ],
      ),
    );
  }
}

enum ChipTone { neutral, positive, warning, danger }
