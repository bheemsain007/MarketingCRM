import 'package:flutter/material.dart';

import '../core/api/api_exception.dart';
import '../core/di/dependencies.dart';
import '../models/call.dart';
import '../models/callability.dart';
import '../models/lead.dart';
import '../models/outbound_message.dart';
import '../theme/status_colors.dart';
import '../widgets/animations.dart';
import '../widgets/state_views.dart';
import 'call_outcome_sheet.dart';
import 'message_compose_sheet.dart';

/// One lead: who they are, where they are in the pipeline, every call made to
/// them, and the four ways to contact them next.
///
/// **Calling** follows ADR-B exactly:
///
///   ask the server whether this lead may be called → create the call record →
///   hand the number to the phone's own dialer → report the outcome back.
///
/// The app contributes the middle step and nothing else. It has no opinion on
/// whether the lead is suppressed, whether it is a reasonable hour where they
/// live, or whether the number is usable.
///
/// **SMS, WhatsApp and email** do not follow it, and must not. Those go through
/// `POST /leads/{lead}/messages` and never through the handset's own SMS app,
/// WhatsApp or mail client — see `MessageRepository` for why. The user never
/// leaves this app, and every message is gated and logged server-side.
///
/// Note what is asked and what is not. `GET /leads/{lead}/callability` speaks
/// only about calling: it is the endpoint's whole subject, and the two refusals
/// it returns are the DNC list and the calling-hours window. This screen
/// therefore lets it grey out the *call* button and nothing else. Inferring
/// "suppressed for calls, so suppressed for SMS too" would be this app deciding
/// a suppression-matrix question that `DncService` owns (BR-DNC-01) — so each
/// message channel asks by sending, and shows the `403` it gets back.
class LeadDetailScreen extends StatefulWidget {
  const LeadDetailScreen({required this.leadId, super.key});

  final int leadId;

  static const Key callButtonKey = Key('lead.call');
  static const Key smsButtonKey = Key('lead.sms');
  static const Key whatsappButtonKey = Key('lead.whatsapp');
  static const Key emailButtonKey = Key('lead.email');
  static const Key noEmailNoticeKey = Key('lead.noEmail');
  static const Key refusalKey = Key('lead.callRefusal');
  static const Key historyKey = Key('lead.callHistory');

  /// The button for a message channel, so a test can name one.
  static Key messageButtonKey(MessageChannel channel) {
    return switch (channel) {
      MessageChannel.sms => smsButtonKey,
      MessageChannel.whatsapp => whatsappButtonKey,
      MessageChannel.email => emailButtonKey,
    };
  }

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

  /// The send sequence, for SMS, WhatsApp and email alike.
  ///
  /// Compose → `POST /leads/{lead}/messages` → show whatever the server said.
  /// No `sms:`, no `mailto:`, no WhatsApp deep link: leaving the app to send
  /// would skip the DNC gate, the frequency caps and the message log, and the
  /// CRM would have no record the lead was contacted at all.
  ///
  /// The `422` loop is the same one the call outcome uses, and for the same
  /// reason: an empty body comes back "Provide a body or choose a template."
  /// and the honest response is to reopen the form carrying the server's
  /// sentence rather than to have predicted it on the handset. A `422` the user
  /// cannot fix — a lead whose email was deleted between opening this screen
  /// and sending — reopens too, showing why, and they back out.
  Future<void> _sendMessage(MessageChannel channel) async {
    final lead = _lead;
    if (lead == null || _working) {
      return;
    }

    final deps = AppScope.of(context);
    String? serverError;

    while (true) {
      if (!mounted) {
        return;
      }

      final draft = await MessageComposeSheet.show(
        context,
        channel: channel,
        leadId: lead.id,
        leadName: lead.name,
        serverError: serverError,
      );

      if (draft == null || !mounted) {
        // Backed out before any request was made. Nothing was sent and nothing
        // was recorded — unlike a call, there is no open record to tidy up.
        return;
      }

      setState(() => _working = true);

      try {
        final result = await deps.messageRepository.send(
          leadId: lead.id,
          channel: channel,
          body: draft.body,
          subject: draft.subject,
        );

        if (!mounted) {
          return;
        }

        // The server's sentence verbatim. It is the only thing that knows
        // whether a provider is configured for this channel, and "queued but
        // not delivered" is not a detail worth rounding off to "Sent".
        _snack(result.message);

        return;
      } on ValidationException catch (error) {
        serverError = error.message;
      } on ApiException catch (error) {
        // Includes the `403 dnc.suppressed` refusal, which carries the channel
        // and the suppression reason in its message.
        if (!mounted) {
          return;
        }

        _snack(error.message);

        return;
      } finally {
        if (mounted) {
          setState(() => _working = false);
        }
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
          FadeSlideIn(child: _LeadSummary(lead: lead)),
          const SizedBox(height: 16),
          FadeSlideIn.staggered(
            index: 1,
            child: _ContactActions(
              lead: lead,
              callability: _callability,
              busy: _working,
              onCall: _startCall,
              onSend: _sendMessage,
            ),
          ),
          const SizedBox(height: 24),
          Text('Call history', style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 8),
          FadeSlideIn.staggered(
            index: 2,
            child: _calls.isEmpty
                ? const Padding(
                    padding: EdgeInsets.symmetric(vertical: 24),
                    child: EmptyView(message: 'No calls logged for this lead yet.', icon: Icons.call_outlined),
                  )
                : Column(
                    key: LeadDetailScreen.historyKey,
                    children: _calls
                        .map((call) => _CallTile(
                              call: call,
                              onRecordOutcome: call.isPending ? () => _collectOutcome(call, lead) : null,
                            ))
                        .toList(growable: false),
                  ),
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
    final (avatarBackground, avatarForeground) = StatusColors.avatar(context, lead.name);

    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Row(
              children: <Widget>[
                Hero(
                  tag: 'lead-avatar-${lead.id}',
                  child: CircleAvatar(
                    backgroundColor: avatarBackground,
                    foregroundColor: avatarForeground,
                    child: Text(lead.name.trim().isEmpty ? '?' : lead.name.trim().substring(0, 1).toUpperCase()),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(child: Text(lead.name, style: theme.textTheme.titleLarge)),
                StatusChip(
                  label: lead.statusLabel,
                  colors: StatusColors.leadStatus(context, lead.status),
                ),
              ],
            ),
            if (lead.company != null) ...<Widget>[
              const SizedBox(height: 4),
              Text(lead.company!, style: theme.textTheme.bodyMedium),
            ],
            const SizedBox(height: 12),
            // No phone field here, by design (SEC-PII-04). The app still holds
            // the number — it has to, to hand it to the dialer — but a
            // telecaller dials with the Call button, not by reading digits, and
            // a lead book rendered as numbers is a lead book that leaves the
            // building on a photo.
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
                  StatusChip(
                    label: lead.temperature,
                    icon: Icons.thermostat_outlined,
                    colors: StatusColors.leadTemperature(context, lead.temperature),
                  ),
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

/// The four ways to contact a lead.
///
/// Call is prominent because it is the job; the three message channels sit
/// under it as equals. Email is the only one that can be missing, because it is
/// the only one whose address is not the phone number the lead was created
/// with — `OutboundMessageService::recipientFor()` uses `email` for email and
/// `phone_e164` for everything else.
class _ContactActions extends StatelessWidget {
  const _ContactActions({
    required this.lead,
    required this.callability,
    required this.busy,
    required this.onCall,
    required this.onSend,
  });

  final Lead lead;
  final Callability callability;
  final bool busy;
  final VoidCallback onCall;
  final void Function(MessageChannel channel) onSend;

  @override
  Widget build(BuildContext context) {
    // A lead with no email address cannot be emailed: the send would come back
    // `422 "This lead has no Email address on record."`. Offering the action
    // anyway would let someone write a message and lose it to a refusal that
    // was knowable before they started. This is not the app deciding a rule —
    // the presence of an address is a fact on the record, not a judgement —
    // and the server still refuses if the address disappears meanwhile.
    final canEmail = lead.email != null;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        _CallAction(callability: callability, busy: busy, onCall: onCall),
        const SizedBox(height: 12),
        Row(
          children: <Widget>[
            Expanded(
              child: _ChannelButton(
                channel: MessageChannel.sms,
                icon: Icons.sms_outlined,
                busy: busy,
                onSend: onSend,
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: _ChannelButton(
                channel: MessageChannel.whatsapp,
                icon: Icons.chat_outlined,
                busy: busy,
                onSend: onSend,
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: canEmail
                  ? _ChannelButton(
                      channel: MessageChannel.email,
                      icon: Icons.mail_outline,
                      busy: busy,
                      onSend: onSend,
                    )
                  : const _NoEmailNotice(),
            ),
          ],
        ),
      ],
    );
  }
}

/// One message channel. Never a deep link — see `MessageRepository`.
class _ChannelButton extends StatelessWidget {
  const _ChannelButton({
    required this.channel,
    required this.icon,
    required this.busy,
    required this.onSend,
  });

  final MessageChannel channel;
  final IconData icon;
  final bool busy;
  final void Function(MessageChannel channel) onSend;

  @override
  Widget build(BuildContext context) {
    final accent = StatusColors.channel(context, channel.value);

    return OutlinedButton.icon(
      key: LeadDetailScreen.messageButtonKey(channel),
      onPressed: busy ? null : () => onSend(channel),
      icon: Icon(icon, size: 18),
      label: Text(channel.label, maxLines: 1, overflow: TextOverflow.ellipsis),
      style: OutlinedButton.styleFrom(
        foregroundColor: accent,
        minimumSize: const Size.fromHeight(44),
        padding: const EdgeInsets.symmetric(horizontal: 8),
        side: BorderSide(color: accent.withValues(alpha: 0.4)),
      ),
    );
  }
}

/// Why there is no Email button, said rather than left blank.
class _NoEmailNotice extends StatelessWidget {
  const _NoEmailNotice();

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Container(
      key: LeadDetailScreen.noEmailNoticeKey,
      height: 44,
      alignment: Alignment.center,
      padding: const EdgeInsets.symmetric(horizontal: 8),
      decoration: BoxDecoration(
        border: Border.all(color: theme.colorScheme.outlineVariant),
        borderRadius: BorderRadius.circular(20),
      ),
      child: Text(
        'No email',
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
        style: theme.textTheme.labelLarge?.copyWith(color: theme.colorScheme.onSurfaceVariant),
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
    return AppSwitcher(
      child: KeyedSubtree(
        key: ValueKey<bool>(callability.callable),
        child: _buildAction(context),
      ),
    );
  }

  Widget _buildAction(BuildContext context) {
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
      icon: AppSwitcher(
        child: busy
            ? const SizedBox(
                key: ValueKey('busy'),
                height: 18,
                width: 18,
                child: CircularProgressIndicator(strokeWidth: 2),
              )
            : const Icon(Icons.call, key: ValueKey('idle')),
      ),
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
