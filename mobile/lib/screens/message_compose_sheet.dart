import 'package:flutter/material.dart';

import '../core/di/dependencies.dart';
import '../models/outbound_message.dart';
import '../models/template.dart';
import '../theme/status_colors.dart';
import '../widgets/animations.dart';

/// What the telecaller wants to say, before the server is asked whether they may.
class MessageDraft {
  const MessageDraft({required this.body, this.subject});

  final String body;

  /// Null on every channel but email. The server rejects a subject anywhere
  /// else, so the sheet does not offer the field there and this stays null.
  final String? subject;
}

/// Compose one message, on one channel, to one lead.
///
/// What this sheet deliberately does **not** do:
///
///  * It does not check whether the lead may be contacted. That is the DNC gate
///    and it lives in `DncService` (BR-DNC-01); the send comes back `403` with
///    the server's own sentence, which is what the user sees.
///  * It does not refuse an empty body. "Provide a body or choose a template."
///    is `StoreMessageRequest`'s sentence and the sheet reopens carrying it,
///    exactly as the call-outcome sheet does with a missing callback time. One
///    copy of the rule.
///  * It does not show the lead's phone number, on any channel (SEC-PII-04).
///    The server already knows which address this channel uses — it picks the
///    recipient itself in `OutboundMessageService::recipientFor()` — so there is
///    nothing here for the app to display or for the user to confirm.
///  * It does not render its own template text. "Use a template" asks the
///    server to render one against THIS lead (`GET
///    /templates/{id}/preview?lead_id=…`) and fills the fields with what comes
///    back — the exact text `OutboundMessageService` would produce, token for
///    token. A second, app-side substitution could agree with the server's
///    render and later drift from it (SEC-IN-06); asking is the only way that
///    cannot happen.
class MessageComposeSheet extends StatefulWidget {
  const MessageComposeSheet({
    required this.channel,
    required this.leadId,
    required this.leadName,
    this.serverError,
    super.key,
  });

  final MessageChannel channel;
  final int leadId;
  final String leadName;

  /// Redisplayed after a rejected send, so the sheet reopens with the server's
  /// complaint instead of an empty form.
  final String? serverError;

  static const Key subjectFieldKey = Key('compose.subject');
  static const Key bodyFieldKey = Key('compose.body');
  static const Key sendButtonKey = Key('compose.send');
  static const Key errorKey = Key('compose.error');
  static const Key templateButtonKey = Key('compose.template.open');
  static const Key templateEmptyKey = Key('compose.template.empty');
  static const Key templateErrorKey = Key('compose.template.error');

  static Key templateOptionKey(int templateId) => Key('compose.template.$templateId');

  /// Opens the sheet. Returns null when the telecaller backs out, in which case
  /// nothing was sent and nothing was recorded — no request has been made yet.
  static Future<MessageDraft?> show(
    BuildContext context, {
    required MessageChannel channel,
    required int leadId,
    required String leadName,
    String? serverError,
  }) {
    return showModalBottomSheet<MessageDraft>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (context) => MessageComposeSheet(
        channel: channel,
        leadId: leadId,
        leadName: leadName,
        serverError: serverError,
      ),
    );
  }

  @override
  State<MessageComposeSheet> createState() => _MessageComposeSheetState();
}

class _MessageComposeSheetState extends State<MessageComposeSheet> {
  final TextEditingController _subject = TextEditingController();
  final TextEditingController _body = TextEditingController();

  bool _applyingTemplate = false;

  @override
  void dispose() {
    _subject.dispose();
    _body.dispose();
    super.dispose();
  }

  void _submit() {
    Navigator.of(context).pop(MessageDraft(
      body: _body.text,
      subject: widget.channel.carriesSubject ? _subject.text : null,
    ));
  }

  /// Fetches this channel's active templates and lets the telecaller pick one.
  ///
  /// Opt-in, not fetched the moment the sheet opens: most sends are not from a
  /// template, and a screen that always paid for a templates request would
  /// make every SMS and WhatsApp send slower for a feature most messages do
  /// not use.
  Future<void> _pickTemplate() async {
    final deps = AppScope.of(context);

    List<MessageTemplate> templates;
    try {
      templates = await deps.templateRepository.forChannel(widget.channel.value);
    } catch (_) {
      // The picker is a convenience on top of a form that works without it —
      // a template list that failed to load is not a reason to block sending.
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            key: MessageComposeSheet.templateErrorKey,
            content: Text('Could not load templates. You can still type the message.'),
          ),
        );
      }
      return;
    }

    if (!mounted) {
      return;
    }

    final selected = await showModalBottomSheet<MessageTemplate>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (context) => _TemplatePickerSheet(templates: templates),
    );

    if (selected == null || !mounted) {
      return;
    }

    setState(() => _applyingTemplate = true);

    try {
      // Rendered against THIS lead — the whole reason to ask the server rather
      // than fill in `{{ lead_name }}` locally.
      final rendered = await deps.templateRepository.preview(
        templateId: selected.id,
        leadId: widget.leadId,
      );

      if (!mounted) {
        return;
      }

      _body.text = rendered.body;
      if (widget.channel.carriesSubject && rendered.subject != null) {
        _subject.text = rendered.subject!;
      }

      if (!rendered.isSendable) {
        // Filled in anyway — a telecaller may still want to see and copy the
        // text — but told, because sending will 422 on an address this
        // channel does not have for this lead.
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('This lead has no address on record for this channel yet.'),
        ));
      }
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Could not load that template.')),
        );
      }
    } finally {
      if (mounted) {
        setState(() => _applyingTemplate = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 24),
        child: FadeSlideIn(
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
            Row(
              children: <Widget>[
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: <Widget>[
                      Text('Send ${widget.channel.label}', style: theme.textTheme.titleMedium),
                      Text(
                        widget.leadName,
                        style: theme.textTheme.bodySmall
                            ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                      ),
                    ],
                  ),
                ),
                TextButton.icon(
                  key: MessageComposeSheet.templateButtonKey,
                  onPressed: _applyingTemplate ? null : _pickTemplate,
                  icon: AppSwitcher(
                    child: _applyingTemplate
                        ? const SizedBox(
                            key: ValueKey('busy'),
                            height: 16,
                            width: 16,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Icon(Icons.description_outlined, size: 18, key: ValueKey('idle')),
                  ),
                  label: const Text('Template'),
                ),
              ],
            ),
            if (widget.serverError != null) ...<Widget>[
              const SizedBox(height: 12),
              Container(
                key: MessageComposeSheet.errorKey,
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
            if (widget.channel.carriesSubject) ...<Widget>[
              TextField(
                key: MessageComposeSheet.subjectFieldKey,
                controller: _subject,
                textInputAction: TextInputAction.next,
                decoration: const InputDecoration(
                  labelText: 'Subject',
                  border: OutlineInputBorder(),
                  isDense: true,
                ),
              ),
              const SizedBox(height: 12),
            ],
            TextField(
              key: MessageComposeSheet.bodyFieldKey,
              controller: _body,
              minLines: 4,
              maxLines: 8,
              decoration: const InputDecoration(
                labelText: 'Message',
                border: OutlineInputBorder(),
              ),
            ),
            const SizedBox(height: 8),
            Text(
              'This goes out through the CRM and is recorded against the lead.',
              style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant),
            ),
            const SizedBox(height: 20),
            FilledButton.icon(
              key: MessageComposeSheet.sendButtonKey,
              onPressed: _submit,
              icon: const Icon(Icons.send_outlined),
              label: Text('Send ${widget.channel.label}'),
              style: FilledButton.styleFrom(
                backgroundColor: StatusColors.channel(context, widget.channel.value),
                foregroundColor: Colors.white,
              ),
            ),
          ],
        ),
        ),
      ),
    );
  }
}

/// The template list, nested inside the compose sheet.
///
/// A separate small widget rather than inline in `_pickTemplate`, because it
/// needs its own build method to render the empty and populated states.
class _TemplatePickerSheet extends StatelessWidget {
  const _TemplatePickerSheet({required this.templates});

  final List<MessageTemplate> templates;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Text('Choose a template', style: theme.textTheme.titleMedium),
            const SizedBox(height: 12),
            if (templates.isEmpty)
              Padding(
                key: MessageComposeSheet.templateEmptyKey,
                padding: const EdgeInsets.symmetric(vertical: 24),
                child: Text(
                  'No templates for this channel yet.',
                  style: theme.textTheme.bodyMedium
                      ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                ),
              )
            else
              Flexible(
                child: ListView.separated(
                  shrinkWrap: true,
                  itemCount: templates.length,
                  separatorBuilder: (_, _) => const Divider(height: 1),
                  itemBuilder: (context, index) {
                    final template = templates[index];

                    return FadeSlideIn.staggered(
                      index: index,
                      child: ListTile(
                        key: MessageComposeSheet.templateOptionKey(template.id),
                        title: Text(template.name),
                        subtitle: Text(
                          template.body,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                        onTap: () => Navigator.of(context).pop(template),
                      ),
                    );
                  },
                ),
              ),
          ],
        ),
      ),
    );
  }
}
