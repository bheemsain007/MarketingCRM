import 'package:flutter/material.dart';

import '../models/call.dart';

/// What the telecaller reports after the handset is done.
class CallOutcomeDraft {
  const CallOutcomeDraft({
    required this.status,
    this.notes,
    this.durationSeconds,
    this.callbackAt,
  });

  final String status;
  final String? notes;
  final int? durationSeconds;
  final DateTime? callbackAt;
}

/// The outcome form.
///
/// Two things it deliberately does not do:
///
///  * It does not require a callback time for `Call Back Requested`. That
///    requirement is BR-CALL-05 and it lives in the server's FormRequest; the
///    field is always offered and the server's own `422` message ("When should
///    this lead be called back?") is what the user sees if they skip it. One
///    copy of the rule.
///  * It does not warn that `Wrong Number` will suppress the lead, or zero the
///    duration on a call that did not connect. The server does both (BR-DNC-07,
///    GLOSSARY §2.2) and a client that predicted them would eventually predict
///    wrong.
class CallOutcomeSheet extends StatefulWidget {
  const CallOutcomeSheet({required this.leadName, this.serverError, super.key});

  final String leadName;

  /// Redisplayed after a rejected submission, so the sheet reopens with the
  /// server's complaint instead of an empty form.
  final String? serverError;

  static const Key saveButtonKey = Key('outcome.save');
  static const Key durationFieldKey = Key('outcome.duration');
  static const Key notesFieldKey = Key('outcome.notes');
  static const Key errorKey = Key('outcome.error');

  static Key outcomeKey(CallOutcome outcome) => Key('outcome.${outcome.value}');

  /// Opens the sheet. Returns null when the telecaller backs out — in which
  /// case the call stays `status: null` server-side, which is a real state
  /// (*dialled, outcome not yet reported*) and is offered again from the
  /// history list.
  static Future<CallOutcomeDraft?> show(
    BuildContext context, {
    required String leadName,
    String? serverError,
  }) {
    return showModalBottomSheet<CallOutcomeDraft>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (context) => CallOutcomeSheet(leadName: leadName, serverError: serverError),
    );
  }

  @override
  State<CallOutcomeSheet> createState() => _CallOutcomeSheetState();
}

class _CallOutcomeSheetState extends State<CallOutcomeSheet> {
  CallOutcome? _selected;
  final TextEditingController _notes = TextEditingController();
  final TextEditingController _duration = TextEditingController();
  DateTime? _callbackAt;

  @override
  void dispose() {
    _notes.dispose();
    _duration.dispose();
    super.dispose();
  }

  Future<void> _pickCallback() async {
    final now = DateTime.now();
    final date = await showDatePicker(
      context: context,
      initialDate: now.add(const Duration(hours: 1)),
      firstDate: now,
      lastDate: now.add(const Duration(days: 365)),
    );

    if (date == null || !mounted) {
      return;
    }

    final time = await showTimePicker(
      context: context,
      initialTime: TimeOfDay.fromDateTime(now.add(const Duration(hours: 1))),
    );

    if (time == null || !mounted) {
      return;
    }

    setState(() {
      _callbackAt = DateTime(date.year, date.month, date.day, time.hour, time.minute);
    });
  }

  void _submit() {
    final selected = _selected;
    if (selected == null) {
      return;
    }

    Navigator.of(context).pop(CallOutcomeDraft(
      status: selected.value,
      notes: _notes.text,
      durationSeconds: int.tryParse(_duration.text.trim()),
      callbackAt: _callbackAt,
    ));
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Center(
              child: Container(
                width: 36,
                height: 4,
                decoration: BoxDecoration(
                  color: theme.colorScheme.outlineVariant,
                  borderRadius: BorderRadius.circular(2),
                ),
              ),
            ),
            const SizedBox(height: 16),
            Text('How did the call go?', style: theme.textTheme.titleMedium),
            Text(
              widget.leadName,
              style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant),
            ),
            if (widget.serverError != null) ...<Widget>[
              const SizedBox(height: 12),
              Container(
                key: CallOutcomeSheet.errorKey,
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: theme.colorScheme.errorContainer,
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Text(
                  widget.serverError!,
                  style: theme.textTheme.bodySmall
                      ?.copyWith(color: theme.colorScheme.onErrorContainer),
                ),
              ),
            ],
            const SizedBox(height: 16),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: CallOutcome.values.map((outcome) {
                return ChoiceChip(
                  key: CallOutcomeSheet.outcomeKey(outcome),
                  label: Text(outcome.label),
                  selected: _selected == outcome,
                  onSelected: (_) => setState(() => _selected = outcome),
                );
              }).toList(growable: false),
            ),
            const SizedBox(height: 16),
            TextField(
              key: CallOutcomeSheet.durationFieldKey,
              controller: _duration,
              keyboardType: TextInputType.number,
              decoration: const InputDecoration(
                labelText: 'Talk time (seconds)',
                border: OutlineInputBorder(),
                isDense: true,
              ),
            ),
            const SizedBox(height: 12),
            TextField(
              key: CallOutcomeSheet.notesFieldKey,
              controller: _notes,
              minLines: 2,
              maxLines: 4,
              decoration: const InputDecoration(
                labelText: 'Notes',
                border: OutlineInputBorder(),
              ),
            ),
            const SizedBox(height: 12),
            OutlinedButton.icon(
              onPressed: _pickCallback,
              icon: const Icon(Icons.event_outlined),
              label: Text(
                _callbackAt == null
                    ? 'Schedule a callback (optional)'
                    : 'Callback ${_formatDateTime(_callbackAt!)}',
              ),
            ),
            const SizedBox(height: 20),
            FilledButton(
              key: CallOutcomeSheet.saveButtonKey,
              onPressed: _selected == null ? null : _submit,
              child: const Text('Save outcome'),
            ),
          ],
        ),
      ),
    );
  }
}

String _formatDateTime(DateTime value) {
  String two(int n) => n.toString().padLeft(2, '0');

  return '${two(value.day)}/${two(value.month)} ${two(value.hour)}:${two(value.minute)}';
}
