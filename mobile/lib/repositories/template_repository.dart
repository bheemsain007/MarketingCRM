import '../core/api/api_client.dart';
import '../core/api/paginated.dart';
import '../models/template.dart';

/// Message templates (FR-COMM-02): browse, pick, and — for the users who hold
/// `templates.manage` — author.
///
/// Every write here is refused by the server for anyone lacking
/// `templates.manage`, the same way every write in this app is; the point of
/// checking `User.can('templates.manage')` before drawing a control is not to
/// enforce that (the server already does), it is to not offer a button
/// guaranteed to 403.
class TemplateRepository {
  TemplateRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  /// `GET /templates?filter[channel]=X` — the active templates a compose
  /// sheet on this channel may offer.
  ///
  /// The server already hides retired templates by default (`is_active`
  /// filtering is its job, not this app's — no client-side filtering exists
  /// anywhere in this app, matching `LeadRepository.list`), so this asks for
  /// exactly the channel and takes what comes back.
  Future<List<MessageTemplate>> forChannel(String channel) async {
    final envelope = await _api.get('/templates', query: <String, dynamic>{
      'filter[channel]': channel,
      'per_page': 100,
    });

    return Paginated.fromEnvelope(envelope, MessageTemplate.fromJson).items;
  }

  /// `GET /templates` — every template, for the management screen.
  ///
  /// `includeInactive` maps straight to the API's own `with_inactive` flag
  /// (`TemplateController::index`) rather than being filtered out after the
  /// fact — the same reason `forChannel` above takes no client-side filter.
  Future<List<MessageTemplate>> all({bool includeInactive = false}) async {
    final envelope = await _api.get('/templates', query: <String, dynamic>{
      'per_page': 100,
      if (includeInactive) 'with_inactive': 1,
    });

    return Paginated.fromEnvelope(envelope, MessageTemplate.fromJson).items;
  }

  /// `GET /templates/{id}/preview?lead_id=X` — the template rendered against a
  /// real lead, using the exact renderer the send path uses
  /// (`OutboundMessageService`), so what the picker shows is what would
  /// actually go out — never a second, app-side substitution that could
  /// disagree with it.
  Future<TemplateRender> preview({required int templateId, required int leadId}) async {
    final envelope = await _api.get(
      '/templates/$templateId/preview',
      query: <String, dynamic>{'lead_id': leadId},
    );

    return TemplateRender.fromJson(envelope.dataMap ?? const <String, dynamic>{});
  }

  /// `POST /templates` — `templates.manage` only; the server refuses anyone
  /// else with a `403`.
  Future<MessageTemplate> create(TemplateDraft draft) async {
    final envelope = await _api.post('/templates', body: _bodyOf(draft));

    return MessageTemplate.fromJson(envelope.dataMap ?? const <String, dynamic>{});
  }

  /// `PATCH /templates/{id}`.
  Future<MessageTemplate> update(int id, TemplateDraft draft) async {
    final envelope = await _api.patch('/templates/$id', body: _bodyOf(draft));

    return MessageTemplate.fromJson(envelope.dataMap ?? const <String, dynamic>{});
  }

  /// `DELETE /templates/{id}` — retires it. The server deactivates rather than
  /// destroys (`TemplateService::deactivate()`): sent messages and campaigns
  /// hold `template_id` and must keep resolving the name through it.
  Future<void> deactivate(int id) {
    return _api.delete('/templates/$id');
  }

  /// `POST /templates/{id}/restore`.
  Future<MessageTemplate> restore(int id) async {
    final envelope = await _api.post('/templates/$id/restore');

    return MessageTemplate.fromJson(envelope.dataMap ?? const <String, dynamic>{});
  }

  Map<String, dynamic> _bodyOf(TemplateDraft draft) {
    return <String, dynamic>{
      'name': draft.name,
      'channel': draft.channel,
      'body': draft.body,
      if (draft.code != null && draft.code!.isNotEmpty) 'code': draft.code,
      // Sent only where the channel has one — the same
      // `carriesSubject`-gated omission `MessageRepository.send` uses, so a
      // non-email draft cannot accidentally trip `StoreTemplateRequest`'s
      // subject rule.
      if (draft.subject != null && draft.subject!.isNotEmpty) 'subject': draft.subject,
    };
  }
}
