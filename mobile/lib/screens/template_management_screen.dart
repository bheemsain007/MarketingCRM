import 'package:flutter/material.dart';

import '../core/di/dependencies.dart';
import '../models/outbound_message.dart';
import '../models/template.dart';

/// Browse, and — for whoever holds `templates.manage` — author message
/// templates (FR-COMM-02).
///
/// The permission split is the server's, not this screen's invention:
/// `templates.view` is held by every telecaller because they pick a template
/// every time they send, while `templates.manage` is the authority to change
/// what the organisation says in its own name to thousands of people at once
/// (`routes/api_v1.php`'s own comment on the group). The server refuses a
/// write from anyone lacking the second permission regardless of what this
/// screen draws; hiding the controls here is only so a `templates.view`-only
/// user is not offered a button that can only 403.
class TemplateManagementScreen extends StatefulWidget {
  const TemplateManagementScreen({super.key});

  static const Key listKey = Key('templates.list');
  static const Key emptyKey = Key('templates.empty');
  static const Key addButtonKey = Key('templates.add');
  static const Key showInactiveKey = Key('templates.showInactive');

  static Key tileKey(int id) => Key('templates.tile.$id');
  static Key deactivateKey(int id) => Key('templates.deactivate.$id');
  static Key restoreKey(int id) => Key('templates.restore.$id');

  @override
  State<TemplateManagementScreen> createState() => _TemplateManagementScreenState();
}

class _TemplateManagementScreenState extends State<TemplateManagementScreen> {
  late Future<List<MessageTemplate>> _future;
  bool _showInactive = false;
  bool _loaded = false;

  /// The first load runs here, not in `initState`.
  ///
  /// `AppScope.of(context)` calls `dependOnInheritedWidgetOfExactType`, which
  /// Flutter forbids before the element's first build completes —
  /// `didChangeDependencies` is the framework's own answer to "I need an
  /// inherited value before the first frame", called once after `initState`
  /// and before `build`. `_loaded` keeps the later calls (a permission or
  /// theme change re-running this hook) from re-fetching.
  @override
  void didChangeDependencies() {
    super.didChangeDependencies();

    if (!_loaded) {
      _loaded = true;
      _load();
    }
  }

  void _load() {
    final deps = AppScope.of(context);
    setState(() {
      _future = deps.templateRepository.all(includeInactive: _showInactive);
    });
  }

  Future<void> _openForm({MessageTemplate? editing}) async {
    final deps = AppScope.of(context);

    final draft = await showModalBottomSheet<TemplateDraft>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (context) => _TemplateFormSheet(editing: editing),
    );

    if (draft == null || !mounted) {
      return;
    }

    try {
      if (editing == null) {
        await deps.templateRepository.create(draft);
      } else {
        await deps.templateRepository.update(editing.id, draft);
      }
      if (mounted) {
        _load();
      }
    } catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Could not save the template: $error')),
        );
      }
    }
  }

  Future<void> _deactivate(MessageTemplate template) async {
    final deps = AppScope.of(context);

    try {
      await deps.templateRepository.deactivate(template.id);
      if (mounted) {
        _load();
      }
    } catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Could not deactivate: $error')),
        );
      }
    }
  }

  Future<void> _restore(MessageTemplate template) async {
    final deps = AppScope.of(context);

    try {
      await deps.templateRepository.restore(template.id);
      if (mounted) {
        _load();
      }
    } catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Could not restore: $error')),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final deps = AppScope.of(context);
    final canManage = deps.auth.user?.can('templates.manage') ?? false;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Templates'),
        actions: <Widget>[
          Row(
            children: <Widget>[
              const Text('Inactive', style: TextStyle(fontSize: 13)),
              Switch(
                key: TemplateManagementScreen.showInactiveKey,
                value: _showInactive,
                onChanged: (value) {
                  _showInactive = value;
                  _load();
                },
              ),
            ],
          ),
        ],
      ),
      floatingActionButton: canManage
          ? FloatingActionButton.extended(
              key: TemplateManagementScreen.addButtonKey,
              onPressed: () => _openForm(),
              icon: const Icon(Icons.add),
              label: const Text('New template'),
            )
          : null,
      body: FutureBuilder<List<MessageTemplate>>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const Center(child: CircularProgressIndicator());
          }

          if (snapshot.hasError) {
            return Center(child: Text('Could not load templates: ${snapshot.error}'));
          }

          final templates = snapshot.data ?? const <MessageTemplate>[];

          if (templates.isEmpty) {
            return Center(
              key: TemplateManagementScreen.emptyKey,
              child: Text(
                _showInactive ? 'No templates yet.' : 'No active templates yet.',
                style: Theme.of(context).textTheme.bodyMedium,
              ),
            );
          }

          return ListView.separated(
            key: TemplateManagementScreen.listKey,
            padding: const EdgeInsets.only(bottom: 96),
            itemCount: templates.length,
            separatorBuilder: (_, _) => const Divider(height: 1),
            itemBuilder: (context, index) {
              final template = templates[index];

              return ListTile(
                key: TemplateManagementScreen.tileKey(template.id),
                title: Text(template.name),
                subtitle: Text(
                  '${template.channelLabel} · ${template.body}',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
                leading: CircleAvatar(
                  backgroundColor: template.isActive
                      ? Theme.of(context).colorScheme.primaryContainer
                      : Theme.of(context).colorScheme.surfaceContainerHighest,
                  child: Icon(
                    template.isActive ? Icons.description_outlined : Icons.archive_outlined,
                    size: 18,
                  ),
                ),
                trailing: canManage
                    ? (template.isActive
                        ? IconButton(
                            key: TemplateManagementScreen.deactivateKey(template.id),
                            tooltip: 'Deactivate',
                            icon: const Icon(Icons.archive_outlined),
                            onPressed: () => _deactivate(template),
                          )
                        : IconButton(
                            key: TemplateManagementScreen.restoreKey(template.id),
                            tooltip: 'Restore',
                            icon: const Icon(Icons.unarchive_outlined),
                            onPressed: () => _restore(template),
                          ))
                    : null,
                onTap: canManage
                    ? () => _openForm(editing: template)
                    : () => _showReadOnly(context, template),
              );
            },
          );
        },
      ),
    );
  }

  void _showReadOnly(BuildContext context, MessageTemplate template) {
    showModalBottomSheet<void>(
      context: context,
      useSafeArea: true,
      builder: (context) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              Text(template.name, style: Theme.of(context).textTheme.titleMedium),
              Text(template.channelLabel, style: Theme.of(context).textTheme.bodySmall),
              const SizedBox(height: 12),
              if (template.subject != null) ...<Widget>[
                Text('Subject', style: Theme.of(context).textTheme.labelSmall),
                Text(template.subject!),
                const SizedBox(height: 8),
              ],
              Text('Message', style: Theme.of(context).textTheme.labelSmall),
              Text(template.body),
            ],
          ),
        ),
      ),
    );
  }
}

/// Create/edit form. Restricted to the three channels this app's compose flow
/// sends on — the ones a telecaller has a reason to reach for by hand.
/// WhatsApp and RCS templates that need provider approval (T-31), and other
/// channels, are an authoring task for wherever that approval workflow lives,
/// not this screen.
class _TemplateFormSheet extends StatefulWidget {
  const _TemplateFormSheet({this.editing});

  final MessageTemplate? editing;

  static const Key nameFieldKey = Key('templateForm.name');
  static const Key subjectFieldKey = Key('templateForm.subject');
  static const Key bodyFieldKey = Key('templateForm.body');
  static const Key saveButtonKey = Key('templateForm.save');
  static const Key channelSelectorKey = Key('templateForm.channel');

  @override
  State<_TemplateFormSheet> createState() => _TemplateFormSheetState();
}

class _TemplateFormSheetState extends State<_TemplateFormSheet> {
  late final TextEditingController _name;
  late final TextEditingController _subject;
  late final TextEditingController _body;
  late MessageChannel _channel;

  @override
  void initState() {
    super.initState();
    final editing = widget.editing;

    _name = TextEditingController(text: editing?.name ?? '');
    _subject = TextEditingController(text: editing?.subject ?? '');
    _body = TextEditingController(text: editing?.body ?? '');
    _channel = editing == null
        ? MessageChannel.sms
        : MessageChannel.values.firstWhere(
            (candidate) => candidate.value == editing.channel,
            orElse: () => MessageChannel.sms,
          );
  }

  @override
  void dispose() {
    _name.dispose();
    _subject.dispose();
    _body.dispose();
    super.dispose();
  }

  void _submit() {
    Navigator.of(context).pop(TemplateDraft(
      name: _name.text.trim(),
      channel: _channel.value,
      subject: _channel.carriesSubject ? _subject.text.trim() : null,
      body: _body.text,
    ));
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isEditing = widget.editing != null;

    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Text(isEditing ? 'Edit template' : 'New template', style: theme.textTheme.titleMedium),
            const SizedBox(height: 16),
            TextField(
              key: _TemplateFormSheet.nameFieldKey,
              controller: _name,
              decoration: const InputDecoration(labelText: 'Name', border: OutlineInputBorder()),
            ),
            const SizedBox(height: 12),
            // Changing the channel on an existing template is allowed by the
            // API (`UpdateTemplateRequest.channel` is `sometimes`), but doing
            // so here would silently invalidate the subject/body a WhatsApp
            // template was written for — so the channel is fixed once a
            // template exists, and only chosen when creating one.
            if (!isEditing)
              SegmentedButton<MessageChannel>(
                key: _TemplateFormSheet.channelSelectorKey,
                segments: MessageChannel.values
                    .map((channel) => ButtonSegment<MessageChannel>(
                          value: channel,
                          label: Text(channel.label),
                        ))
                    .toList(growable: false),
                selected: <MessageChannel>{_channel},
                onSelectionChanged: (selection) => setState(() => _channel = selection.first),
              )
            else
              Align(
                alignment: Alignment.centerLeft,
                child: Chip(label: Text(_channel.label)),
              ),
            const SizedBox(height: 12),
            if (_channel.carriesSubject) ...<Widget>[
              TextField(
                key: _TemplateFormSheet.subjectFieldKey,
                controller: _subject,
                decoration: const InputDecoration(labelText: 'Subject', border: OutlineInputBorder()),
              ),
              const SizedBox(height: 12),
            ],
            TextField(
              key: _TemplateFormSheet.bodyFieldKey,
              controller: _body,
              minLines: 4,
              maxLines: 10,
              decoration: const InputDecoration(
                labelText: 'Message',
                helperText: r'{{ lead_name }}, {{ company }} and other lead fields are substituted at send time.',
                helperMaxLines: 2,
                border: OutlineInputBorder(),
              ),
            ),
            const SizedBox(height: 20),
            FilledButton(
              key: _TemplateFormSheet.saveButtonKey,
              onPressed: _submit,
              child: Text(isEditing ? 'Save changes' : 'Create template'),
            ),
          ],
        ),
      ),
    );
  }
}
