import 'package:flutter/material.dart';

import '../core/api/api_exception.dart';
import '../core/di/dependencies.dart';
import '../models/call.dart';
import '../models/callability.dart';
import '../models/lead.dart';
import '../widgets/state_views.dart';
import 'call_outcome_sheet.dart';

/// One lead: who they are, where they are in the pipeline, every call made to
/// them, and the button that starts the next one.
///
/// The calling sequence lives here and follows ADR-B exactly:
///
///   ask the server whether this lead may be called → create the call record →
///   hand the number to the phone's own dialer → report the outcome back.
///
/// The app contributes the middle step and nothing else. It has no opinion on
/// whether the lead is suppressed, whether it is a reasonable hour where they
/// live, or whether the number is usable.
class LeadDetailScreen extends StatefulWidget {
  const LeadDetailScreen({required this.leadId, super.key});

  final int leadId;

  static const Key callButtonKey = Key('lead.call');
  static const Key refusalKey = Key('lead.callRefusal');
  static const Key historyKey = Key('lead.callHistory');

  @override
  State<LeadDetailScreen> createState() => _LeadDetailScreenState();
}

class _LeadDetailScreenState extends State<LeadDetailScreen> {
  Lead? _lead;
  Callability _callability = Callability.unknown;
  List<Call> _calls = const <Call>[];
  bool _loading = true;
  bool _working = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Future<void> _load() async {
    final deps = AppScope.of(context);

    if (mounted) {
      setState(() {
        _loading = true;
        _error = null;
      });
    }

    try {
      final lead = await deps.leadRepository.show(widget.leadId);

      // Callability and history are both non-fatal to the page: a lead whose
      // history failed to load is still worth showing.
      final callability = await deps.leadRepository.callability(widget.leadId);
      final calls = await _loadCalls(deps);

      if (!mounted) {
        return;
      }

      setState(() {
        _lead = lead;
        _callability = callability;
        _calls = calls;
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

  Future<List<Call>> _loadCalls(AppDependencies deps) async {
    try {
      final page = await deps.leadRepository.calls(widget.leadId);

      return page.items;
    } on ApiException {
      return const <Call>[];
    }
  }

  /// The dial sequence.
  Future<void> _startCall() async {
    final lead = _lead;
    if (lead == null || _working) {
      return;
    }

    final deps = AppScope.of(context);
    setState(() => _working = true);

    try {
      // `POST /leads/{id}/calls` runs the three gates again server-side. The
      // callability check we already made was only so the button could be greyed
      // out with a reason; THIS is the check that protects the lead.
      final call = await deps.callRepository.start(lead.id);

      final dialled = await deps.dialer.dial(lead.phone);

      if (!mounted) {
        return;
      }

      if (!dialled) {
        _snack('This device has no dialer, so the call could not be started. '
            'The call record is open — you can still record the outcome.');
      }

      await _collectOutcome(call, lead);
    } on ApiException catch (error) {
      if (!mounted) {
        return;
      }

      // A 403 here is the DNC gate or the calling-hours gate having changed its
      // mind between the check and the dial. Re-ask, so the button now shows
      // the same refusal the dial just hit.
      _snack(error.message);
      final refreshed = await deps.leadRepository.callability(lead.id);

      if (mounted) {
        setState(() => _callability = refreshed);
      }
    } finally {
      if (mounted) {
        setState(() => _working = false);
      }
    }
  }

  /// Asks for the outcome, and keeps asking while the server rejects it.
  ///
  /// The loop exists because the server owns the validation: a
  /// `call_back_requested` with no callback time comes back `422`, and the
  /// right response is to reopen the form carrying the server's sentence — not
  /// to have predicted it on the handset.
  Future<void> _collectOutcome(Call call, Lead lead) async {
    final deps = AppScope.of(context);
    String? serverError;

    while (true) {
      if (!mounted) {
        return;
      }

      final draft = await CallOutcomeSheet.show(
        context,
        leadName: lead.name,
        serverError: serverError,
      );

      if (draft == null || !mounted) {
        // Backed out. The call stays `status: null` server-side and shows in
        // the history as awaiting an outcome, where it can be finished later.
        await _load();

        return;
      }

      try {
        final result = await deps.callRepository.recordOutcome(
          callId: call.id,
          status: draft.status,
          leadName: lead.name,
          notes: draft.notes,
          durationSeconds: draft.durationSeconds,
          callbackAt: draft.callbackAt,
        );

        if (!mounted) {
          return;
        }

        _snack(result.message);
        await _load();

        return;
      } on ValidationException catch (error) {
        serverError = error.message;
      } on ConflictException catch (error) {
        // Outcomes are write-once. Somebody — or a queued retry — already
        // reported this one.
        if (!mounted) {
          return;
        }

        _snack(error.message);
        await _load();

        return;
      } on ApiException catch (error) {
        if (!mounted) {
          return;
        }

        _snack(error.message);

        return;
      }
    }
  }

  void _snack(String message) {
    ScaffoldMessenger.of(context)
      ..hideCurrentSnackBar()
      ..showSnackBar(SnackBar(content: Text(message)));
  }

  @override
  Widget build(BuildContext context) {
    final lead = _lead;

    return Scaffold(
      appBar: AppBar(title: Text(lead?.name ?? 'Lead')),
      body: _buildBody(context, lead),
    );
  }

  Widget _buildBody(BuildContext context, Lead? lead) {
    if (_loading && lead == null) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_error != null && lead == null) {
      return ErrorView(message: _error!, onRetry: _load);
    }

    if (lead == null) {
      return const EmptyView(message: 'This lead is no longer available.');
    }

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
        children: <Widget>[
          _LeadSummary(lead: lead),
          const SizedBox(height: 16),
          _CallAction(
            callability: _callability,
            busy: _working,
            onCall: _startCall,
          ),
          const SizedBox(height: 24),
          Text('Call history', style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 8),
          if (_calls.isEmpty)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 24),
              child: EmptyView(message: 'No calls logged for this lead yet.', icon: Icons.call_outlined),
            )
          else
            Column(
              key: LeadDetailScreen.historyKey,
              children: _calls
                  .map((call) => _CallTile(
                        call: call,
                        onRecordOutcome: call.isPending ? () => _collectOutcome(call, lead) : null,
                      ))
                  .toList(growable: false),
            ),
        ],
      ),
    );
  }
}

class _LeadSummary extends StatelessWidget {
  const _LeadSummary({required this.lead});

  final Lead lead;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Row(
              children: <Widget>[
                Expanded(child: Text(lead.name, style: theme.textTheme.titleLarge)),
                StatusChip(label: lead.statusLabel, tone: ChipTone.positive),
              ],
            ),
            if (lead.company != null) ...<Widget>[
              const SizedBox(height: 4),
              Text(lead.company!, style: theme.textTheme.bodyMedium),
            ],
            const SizedBox(height: 12),
            _Field(icon: Icons.phone_outlined, value: lead.phoneFormatted),
            if (lead.email != null) _Field(icon: Icons.mail_outline, value: lead.email!),
            if (lead.city != null || lead.state != null)
              _Field(
                icon: Icons.place_outlined,
                value: <String>[
                  if (lead.city != null) lead.city!,
                  if (lead.state != null) lead.state!,
                ].join(', '),
              ),
            if (lead.assignedToName != null)
              _Field(icon: Icons.person_outline, value: lead.assignedToName!),
            if (lead.sourceName != null)
              _Field(icon: Icons.route_outlined, value: lead.sourceName!),
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: <Widget>[
                if (lead.temperature.isNotEmpty)
                  StatusChip(label: lead.temperature, icon: Icons.thermostat_outlined),
                StatusChip(label: 'Score ${lead.score}', icon: Icons.trending_up),
                // Rendered because the API sends it, and labelled as a flag
                // rather than as a verdict — the callability endpoint is the
                // authority on whether this lead may be contacted.
                if (lead.isSuppressed)
                  const StatusChip(
                    label: 'On the suppression list',
                    icon: Icons.block,
                    tone: ChipTone.danger,
                  ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _Field extends StatelessWidget {
  const _Field({required this.icon, required this.value});

  final IconData icon;
  final String value;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(
        children: <Widget>[
          Icon(icon, size: 16, color: theme.colorScheme.onSurfaceVariant),
          const SizedBox(width: 8),
          Expanded(child: Text(value, style: theme.textTheme.bodyMedium)),
        ],
      ),
    );
  }
}

/// The call button, and — when the server says no — the reason instead of it.
class _CallAction extends StatelessWidget {
  const _CallAction({required this.callability, required this.busy, required this.onCall});

  final Callability callability;
  final bool busy;
  final VoidCallback onCall;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (!callability.callable) {
      return Container(
        key: LeadDetailScreen.refusalKey,
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: theme.colorScheme.errorContainer,
          borderRadius: BorderRadius.circular(12),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Icon(Icons.phone_disabled_outlined, color: theme.colorScheme.onErrorContainer),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  Text(
                    callability.refusalMessage,
                    style: theme.textTheme.bodyMedium
                        ?.copyWith(color: theme.colorScheme.onErrorContainer),
                  ),
                  if (callability.nextOpening != null) ...<Widget>[
                    const SizedBox(height: 4),
                    Text(
                      'Calling reopens ${_formatDateTime(callability.nextOpening!)}',
                      style: theme.textTheme.bodySmall
                          ?.copyWith(color: theme.colorScheme.onErrorContainer),
                    ),
                  ],
                ],
              ),
            ),
          ],
        ),
      );
    }

    return FilledButton.icon(
      key: LeadDetailScreen.callButtonKey,
      onPressed: busy ? null : onCall,
      icon: busy
          ? const SizedBox(height: 18, width: 18, child: CircularProgressIndicator(strokeWidth: 2))
          : const Icon(Icons.call),
      label: Text(busy ? 'Starting call…' : 'Call this lead'),
      style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(48)),
    );
  }
}

class _CallTile extends StatelessWidget {
  const _CallTile({required this.call, this.onRecordOutcome});

  final Call call;
  final VoidCallback? onRecordOutcome;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final when = call.startedAt ?? call.createdAt;

    return ListTile(
      contentPadding: EdgeInsets.zero,
      leading: Icon(
        call.isPending
            ? Icons.pending_outlined
            : (call.isConnected ? Icons.call_received : Icons.call_missed_outgoing),
        color: call.isPending
            ? theme.colorScheme.tertiary
            : (call.isConnected ? theme.colorScheme.primary : theme.colorScheme.outline),
      ),
      title: Text(call.displayStatus),
      subtitle: Text(<String>[
        if (when != null) _formatDateTime(when),
        if (call.durationSeconds > 0) call.durationLabel,
        if (call.userName != null) call.userName!,
      ].join(' · ')),
      trailing: onRecordOutcome == null
          ? null
          : TextButton(onPressed: onRecordOutcome, child: const Text('Report')),
      isThreeLine: call.notes != null,
    );
  }
}

String _formatDateTime(DateTime value) {
  String two(int n) => n.toString().padLeft(2, '0');

  return '${two(value.day)}/${two(value.month)}/${value.year} ${two(value.hour)}:${two(value.minute)}';
}
