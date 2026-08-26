{{--
    Message template authoring (FR-COMM-02, T-31).

    Templates are what the organisation says in its own name to thousands of
    people at once, so the two permissions are drawn differently: `templates.view`
    opens the page and the preview, `templates.manage` draws every authoring
    control. The gate is the route middleware either way (SEC-AUTHZ-02) - hiding
    a button only stops somebody reaching for an action that would 403.

    Three API rules the screen follows rather than fights, all of them verified
    against TemplateService: only email carries a subject, approval is never set
    by hand, and a retire is a deactivation because sent messages still resolve
    their template by id.
--}}
@extends('layouts.app')
@section('title', 'Templates')

@section('content')
    @if ($canManage)
        <div class="d-flex justify-content-end mb-3">
            <button id="t-new" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg me-1"></i>New template
            </button>
        </div>
    @endif

    <div class="card mb-3">
        <div class="card-body py-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small mb-1" for="f-q">Search</label>
                    <input type="search" id="f-q" class="form-control form-control-sm"
                           placeholder="Name or code">
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1" for="f-channel">Channel</label>
                    <select id="f-channel" class="form-select form-select-sm">
                        <option value="">Any</option>
                        {{-- Voice channels place a call rather than sending anything and
                             can never hold a template, so they are not offered here. --}}
                        @foreach ($channels as $channel)
                            @unless ($channel->isVoiceCall())
                                <option value="{{ $channel->value }}">{{ $channel->label() }}</option>
                            @endunless
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <div class="form-check form-switch mb-1">
                        <input class="form-check-input" type="checkbox" id="f-retired">
                        <label class="form-check-label small" for="f-retired">Show retired</label>
                    </div>
                    <div class="form-text small mb-0">Retired templates are out of every picker.</div>
                </div>
                <div class="col-md-2 d-grid">
                    <button id="f-apply" class="btn btn-sm btn-primary">Apply</button>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr class="small">
                    <th>Template</th><th>Channel</th><th>Approval</th>
                    <th>Used by</th><th>State</th><th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody id="template-rows">
                <tr><td colspan="6" class="text-center text-muted py-4">Loading…</td></tr>
                </tbody>
            </table>
        </div>
        <div class="card-footer d-flex justify-content-between align-items-center">
            <span class="small text-muted" id="template-meta"></span>
            <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-secondary" id="page-prev">Previous</button>
                <button class="btn btn-outline-secondary" id="page-next">Next</button>
            </div>
        </div>
    </div>

    {{-- Preview (FR-COMM-02) ------------------------------------------------
         Rendered by the send path's own renderer on the server, so what shows
         here is what the recipient gets, token for token. --}}
    <div class="modal fade" id="pv-modal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h6">Preview against a lead</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted" id="pv-template"></p>

                    <div class="row g-2 align-items-end">
                        <div class="col-md-7">
                            <label class="form-label small mb-1" for="pv-q">Lead</label>
                            <input type="search" id="pv-q" class="form-control form-control-sm"
                                   placeholder="Lead name, or a lead ID">
                        </div>
                        <div class="col-md-5 d-grid">
                            <button class="btn btn-sm btn-outline-secondary" id="pv-find">Find leads</button>
                        </div>
                        <div class="col-12">
                            <select id="pv-lead" class="form-select form-select-sm mt-1">
                                <option value="">Search for a lead first</option>
                            </select>
                        </div>
                        <div class="col-12 d-grid">
                            <button class="btn btn-sm btn-primary mt-1" id="pv-run">Render</button>
                        </div>
                    </div>

                    <div id="pv-unreachable" class="alert alert-warning py-2 px-3 small mt-3 d-none"></div>

                    <div id="pv-result" class="border rounded p-3 mt-3 bg-light d-none">
                        <div class="small text-muted mb-2" id="pv-flags"></div>
                        <div class="fw-semibold small mb-2 d-none" id="pv-subject"></div>
                        <pre class="mb-0 small" id="pv-body" style="white-space: pre-wrap;"></pre>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    @if ($canManage)
        {{-- Author / edit ---------------------------------------------------- --}}
        <div class="modal fade" id="t-modal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title h6" id="t-modal-title">New template</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        {{-- Approval is derived from the channel and never sent, so the
                             consequence of a channel choice is spelled out here rather
                             than discovered when a send stops working (T-31). --}}
                        <div id="t-approval-note" class="alert alert-info py-2 px-3 small d-none"></div>

                        <div class="row g-2">
                            <div class="col-md-7">
                                <label class="form-label small mb-1" for="t-name">Name</label>
                                <input type="text" id="t-name" class="form-control form-control-sm" maxlength="150">
                                <div class="invalid-feedback"></div>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label small mb-1" for="t-code">Code</label>
                                <input type="text" id="t-code" class="form-control form-control-sm" maxlength="100"
                                       placeholder="DIWALI_OFFER">
                                <div class="form-text small">Leave blank and one is derived from the name.</div>
                                <div class="invalid-feedback"></div>
                            </div>

                            <div class="col-md-5">
                                <label class="form-label small mb-1" for="t-channel">Channel</label>
                                <select id="t-channel" class="form-select form-select-sm">
                                    @foreach ($channels as $channel)
                                        @unless ($channel->isVoiceCall())
                                            <option value="{{ $channel->value }}">{{ $channel->label() }}</option>
                                        @endunless
                                    @endforeach
                                </select>
                                <div class="invalid-feedback"></div>
                            </div>
                            <div class="col-md-7 d-none" id="t-subject-wrap">
                                <label class="form-label small mb-1" for="t-subject">Subject</label>
                                <input type="text" id="t-subject" class="form-control form-control-sm" maxlength="255">
                                <div class="invalid-feedback"></div>
                            </div>

                            <div class="col-12">
                                <label class="form-label small mb-1" for="t-body">Body</label>
                                <textarea id="t-body" class="form-control form-control-sm" rows="6"></textarea>
                                <div class="form-text small">
                                    Substituted at send time: @{{ lead_name }}, @{{ lead_company }},
                                    @{{ lead_city }}, @{{ organisation }}. Anything else is delivered literally.
                                </div>
                                <div class="invalid-feedback"></div>
                            </div>

                            <div class="col-12">
                                <label class="form-label small mb-1" for="t-variables">Variables</label>
                                <input type="text" id="t-variables" class="form-control form-control-sm"
                                       placeholder="lead_name, lead_city">
                                <div class="form-text small">
                                    Comma separated, lowercase. Declares what this template expects.
                                </div>
                                <div class="invalid-feedback"></div>
                            </div>

                            <div class="col-md-5">
                                <label class="form-label small mb-1" for="t-provider">Provider</label>
                                <input type="text" id="t-provider" class="form-control form-control-sm" maxlength="50">
                                <div class="invalid-feedback"></div>
                            </div>
                            <div class="col-md-7">
                                <label class="form-label small mb-1" for="t-provider_template_id">Provider template ID</label>
                                <input type="text" id="t-provider_template_id" class="form-control form-control-sm"
                                       maxlength="190">
                                <div class="form-text small">The id the provider registered this text under.</div>
                                <div class="invalid-feedback"></div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-sm btn-primary" id="t-save">Save template</button>
                    </div>
                </div>
            </div>
        </div>

        {{-- Retire ------------------------------------------------------------ --}}
        <div class="modal fade" id="retire-modal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title h6">Retire this template</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="small mb-2" id="retire-name"></p>
                        <p class="small text-muted mb-0">
                            It leaves every picker and stops new campaigns using it. Nothing already
                            sent changes, and it can be restored.
                        </p>
                        <p class="small text-muted mb-0 mt-2 d-none" id="retire-usage"></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-sm btn-danger" id="retire-confirm">Retire</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
@endsection

@push('scripts')
<script>
$(function () {
    let page = 1;
    let previewing = null;
    let rows = [];               // the current page, so an edit needs no second fetch

    // Drives the per-row buttons, which JS draws rather than Blade.
    const canManage = @json($canManage);
    const pvModal = new bootstrap.Modal(document.getElementById('pv-modal'));

    // ------------------------------------------------------------------- list
    function load() {
        const params = { page: page };

        const q = $('#f-q').val();
        if (q) params.q = q;

        // The API rejects unknown or blank filter fields with a 422 rather than
        // ignoring them, so a filter is sent only once it has a value.
        const channel = $('#f-channel').val();
        if (channel) params['filter[channel]'] = channel;

        // Retired rows are hidden by default; asking for them is the only way to
        // find one in order to restore it.
        if ($('#f-retired').is(':checked')) params.with_inactive = 1;

        $.getJSON('/api/v1/templates', params)
            .done(function (response) {
                rows = response.data.items;
                render(rows, response.data.meta);
            })
            .fail(function (xhr) {
                CRM.alert(CRM.errorFrom(xhr));
                $('#template-rows').html('<tr><td colspan="6" class="text-center text-muted py-4">Could not load templates.</td></tr>');
            });
    }

    function render(items, meta) {
        if (!items.length) {
            $('#template-rows').html('<tr><td colspan="6" class="text-center text-muted py-4">No templates match.</td></tr>');
        } else {
            $('#template-rows').html(items.map(function (t) {
                return '<tr>'
                    + '<td>' + CRM.escape(t.name)
                    + '<div class="small text-muted font-monospace">' + CRM.escape(t.code) + '</div></td>'
                    + '<td class="small">' + CRM.escape(t.channel_label) + '</td>'
                    + '<td>' + approvalCell(t) + '</td>'
                    + '<td class="small text-muted">' + usageCell(t) + '</td>'
                    + '<td>' + (t.is_active
                        ? '<span class="badge text-bg-success">Active</span>'
                        : '<span class="badge text-bg-secondary">Retired</span>') + '</td>'
                    + '<td class="text-end">' + actionsCell(t) + '</td>'
                    + '</tr>';
            }).join(''));
        }

        $('#template-meta').text(
            meta.total + ' template' + (meta.total === 1 ? '' : 's')
            + ' · page ' + meta.current_page + ' of ' + meta.last_page
        );
        $('#page-prev').prop('disabled', meta.current_page <= 1);
        $('#page-next').prop('disabled', meta.current_page >= meta.last_page);
    }

    /*
     * Saved and sendable are different states, and an author who cannot see
     * which one they are looking at will keep wondering why a template that
     * "exists" never goes out (T-31).
     */
    function approvalCell(t) {
        const tone = ({
            approved: 'success', pending: 'warning', rejected: 'danger', draft: 'secondary'
        })[t.approval_status] || 'secondary';

        let html = '<span class="badge text-bg-' + tone + '">' + CRM.escape(t.approval_status) + '</span>';

        if (t.is_active && !t.is_sendable) {
            html += '<div class="small text-muted">Cannot be sent yet</div>';
        }
        if (t.rejection_reason) {
            html += '<div class="small text-danger">' + CRM.escape(t.rejection_reason) + '</div>';
        }

        return html;
    }

    function usageCell(t) {
        const campaigns = t.campaign_count || 0;
        const messages = t.message_count || 0;

        if (!campaigns && !messages) return '—';

        return campaigns + ' campaign' + (campaigns === 1 ? '' : 's')
            + '<div>' + messages + ' message' + (messages === 1 ? '' : 's') + '</div>';
    }

    function actionsCell(t) {
        let html = '<button class="btn btn-sm btn-outline-secondary preview" data-id="' + t.id + '">Preview</button>';

        if (!canManage) return html;

        html += ' <button class="btn btn-sm btn-outline-secondary edit" data-id="' + t.id + '">Edit</button>';
        html += t.is_active
            ? ' <button class="btn btn-sm btn-outline-danger retire" data-id="' + t.id + '">Retire</button>'
            : ' <button class="btn btn-sm btn-outline-success restore" data-id="' + t.id + '">Restore</button>';

        return html;
    }

    function rowFor(id) {
        return rows.filter(function (t) { return t.id === id; })[0];
    }

    // ---------------------------------------------------------------- preview
    $('#template-rows').on('click', '.preview', function () {
        previewing = $(this).data('id');
        const t = rowFor(previewing);

        $('#pv-template').text(t ? t.name + ' · ' + t.channel_label : '');
        $('#pv-q').val('');
        $('#pv-lead').html('<option value="">Search for a lead first</option>');
        $('#pv-result, #pv-subject').addClass('d-none');
        $('#pv-unreachable').addClass('d-none');

        pvModal.show();
    });

    // A bare number is taken as a lead ID, so an operator who already has one
    // does not have to search their way back to it.
    $('#pv-find').on('click', function () {
        const term = ($('#pv-q').val() || '').trim();

        if (/^\d+$/.test(term)) {
            $('#pv-lead').html('<option value="' + term + '">Lead #' + CRM.escape(term) + '</option>');
            return;
        }

        if (!term) {
            CRM.alert('Type a lead name or a lead ID to search.');
            return;
        }

        $.getJSON('/api/v1/leads', { q: term, per_page: 25 })
            .done(function (response) {
                const items = response.data.items;

                if (!items.length) {
                    $('#pv-lead').html('<option value="">No leads match</option>');
                    return;
                }

                // Name, city and id only. A preview must never turn this screen
                // into a directory of contact addresses (SEC-PII-04).
                $('#pv-lead').html(items.map(function (lead) {
                    return '<option value="' + lead.id + '">#' + lead.id + ' · '
                        + CRM.escape(lead.name) + (lead.city ? ' — ' + CRM.escape(lead.city) : '')
                        + '</option>';
                }).join(''));
            })
            .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); });
    });

    $('#pv-run').on('click', function () {
        const leadId = $('#pv-lead').val();

        if (!leadId) {
            CRM.alert('Pick a lead to render against.');
            return;
        }

        $('#pv-run').prop('disabled', true);

        $.getJSON('/api/v1/templates/' + previewing + '/preview', { lead_id: leadId })
            .done(function (response) {
                const preview = response.data;

                $('#pv-result').removeClass('d-none');
                $('#pv-body').text(preview.body === null ? '' : preview.body);

                $('#pv-subject')
                    .toggleClass('d-none', !preview.subject)
                    .text('Subject: ' + (preview.subject || ''));

                $('#pv-flags').html(preview.is_sendable
                    ? '<span class="badge text-bg-success">Sendable</span>'
                    : '<span class="badge text-bg-warning">Saved, not sendable</span>');

                /*
                 * Whether the lead is reachable on this channel, never the
                 * address itself - the author needs to know a send would fail,
                 * not to read the customer's email off a template screen.
                 */
                $('#pv-unreachable')
                    .toggleClass('d-none', !!preview.recipient)
                    .text('This lead has no ' + (preview.channel === 'email' ? 'email address' : 'phone number')
                        + ' on record, so a send on this channel would fail.');
            })
            .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); })
            .always(function () { $('#pv-run').prop('disabled', false); });
    });

    // ------------------------------------------------------------------ write
    {{-- Gated in Blade rather than at runtime: a reader who cannot author one
         is sent none of this, so there is no authoring code on their page to
         read for hints about what the write endpoints accept. --}}
    @if ($canManage)
        let editing = null;      // template id being edited, null while creating
        let retiring = null;

        const tModal = new bootstrap.Modal(document.getElementById('t-modal'));
        const retireModal = new bootstrap.Modal(document.getElementById('retire-modal'));

        // Mirrors TemplateService::PROVIDER_APPROVED_CHANNELS. A hint for the
        // author only - the server decides the approval state, and nothing here
        // ever sends one.
        const PROVIDER_APPROVED = ['whatsapp', 'rcs'];

        // Only email carries a subject, and moving a template off email drops
        // the one it had - so the field appears and disappears with the channel
        // rather than accepting text the save would discard.
        $('#t-channel').on('change', function () {
            const channel = $(this).val();

            $('#t-subject-wrap').toggleClass('d-none', channel !== 'email');
            renderApprovalNote(channel);
        });

        function renderApprovalNote(channel) {
            const note = $('#t-approval-note');

            if (PROVIDER_APPROVED.indexOf(channel) === -1) {
                note.addClass('d-none').removeClass('alert-warning').addClass('alert-info');
                return;
            }

            const current = editing ? rowFor(editing) : null;
            const wasApproved = current && current.approval_status === 'approved';

            note.removeClass('d-none')
                .toggleClass('alert-warning', !!wasApproved)
                .toggleClass('alert-info', !wasApproved)
                .text(wasApproved
                    ? 'This template is approved by the provider. Saving a change to its text returns it '
                      + 'to draft, and it cannot be sent again until the provider approves the new wording.'
                    : 'WhatsApp and RCS templates are approved by the provider, never here. This one stays '
                      + 'in draft (or pending, if you give it a provider template ID) until that comes back.');
        }

        function clearErrors() {
            $('#t-modal .is-invalid').removeClass('is-invalid');
            $('#t-modal .invalid-feedback').text('');
        }

        function fillForm(t) {
            $('#t-name').val(t ? t.name : '');
            $('#t-code').val(t ? t.code : '');
            $('#t-channel').val(t ? t.channel : 'email');
            $('#t-subject').val(t ? (t.subject || '') : '');
            $('#t-body').val(t ? t.body : '');
            $('#t-variables').val(t && t.variables ? t.variables.join(', ') : '');
            $('#t-provider').val(t ? (t.provider || '') : '');
            $('#t-provider_template_id').val(t ? (t.provider_template_id || '') : '');
            $('#t-channel').trigger('change');
        }

        $('#t-new').on('click', function () {
            editing = null;
            clearErrors();
            fillForm(null);
            $('#t-modal-title').text('New template');
            tModal.show();
        });

        $('#template-rows').on('click', '.edit', function () {
            editing = $(this).data('id');
            clearErrors();
            fillForm(rowFor(editing));
            $('#t-modal-title').text('Edit template');
            tModal.show();
        });

        $('#t-save').on('click', function () {
            clearErrors();

            const channel = $('#t-channel').val();
            const code = ($('#t-code').val() || '').trim();
            const variables = ($('#t-variables').val() || '')
                .split(',').map(function (v) { return v.trim(); }).filter(Boolean);

            // approval_status is never sent: the API refuses it outright, because
            // approval is derived from the channel rather than dictated (T-31).
            const payload = {
                name: $('#t-name').val(),
                channel: channel,
                body: $('#t-body').val(),
                variables: variables.length ? variables : null,
                provider: $('#t-provider').val() || null,
                provider_template_id: $('#t-provider_template_id').val() || null
            };

            // Blank means "derive one for me" on create and "leave it alone" on
            // update; the update rules have no nullable code to send.
            if (code) payload.code = code;

            if (channel === 'email') payload.subject = $('#t-subject').val() || null;

            $('#t-save').prop('disabled', true);

            $.ajax({
                url: editing ? '/api/v1/templates/' + editing : '/api/v1/templates',
                method: editing ? 'PATCH' : 'POST',
                contentType: 'application/json',
                data: JSON.stringify(payload)
            })
                .done(function (response) {
                    tModal.hide();
                    CRM.alert(response.message, 'success');
                    load();
                })
                .fail(function (xhr) { showErrors(xhr); })
                .always(function () { $('#t-save').prop('disabled', false); });
        });

        function showErrors(xhr) {
            const errors = (xhr.responseJSON || {}).errors || [];

            // A duplicate code comes back as a 409 with no field on it, but the
            // code box is the only place the operator can act on it.
            if (xhr.status === 409) {
                $('#t-code').addClass('is-invalid')
                    .closest('div').find('.invalid-feedback').text(CRM.errorFrom(xhr));
                return;
            }

            let unattached = [];

            errors.forEach(function (error) {
                // `variables.0` is a failure on the variables input as far as
                // this form is concerned.
                const field = (error.field || '').split('.')[0];
                const input = field ? $('#t-' + field) : $();

                if (input.length) {
                    input.addClass('is-invalid');
                    input.closest('div').find('.invalid-feedback').text(error.message);
                } else {
                    unattached.push(error.message);
                }
            });

            if (!errors.length || unattached.length) {
                CRM.alert(unattached.length ? unattached.join(' ') : CRM.errorFrom(xhr));
            }
        }

        // -------------------------------------------------------- retire / restore
        $('#template-rows').on('click', '.retire', function () {
            retiring = $(this).data('id');
            const t = rowFor(retiring);

            $('#retire-name').text(t ? t.name + ' (' + t.code + ')' : '');

            const used = t && ((t.campaign_count || 0) + (t.message_count || 0)) > 0;
            $('#retire-usage')
                .toggleClass('d-none', !used)
                .text(used
                    ? 'It is referenced by ' + (t.campaign_count || 0) + ' campaign(s) and '
                      + (t.message_count || 0) + ' sent message(s), all of which keep reading it.'
                    : '');

            retireModal.show();
        });

        $('#retire-confirm').on('click', function () {
            $('#retire-confirm').prop('disabled', true);

            $.ajax({ url: '/api/v1/templates/' + retiring, method: 'DELETE' })
                .done(function (response) {
                    retireModal.hide();
                    CRM.alert(response.message, 'success');
                    load();
                })
                .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); })
                .always(function () { $('#retire-confirm').prop('disabled', false); });
        });

        $('#template-rows').on('click', '.restore', function () {
            const button = $(this).prop('disabled', true);

            $.ajax({ url: '/api/v1/templates/' + button.data('id') + '/restore', method: 'POST' })
                .done(function (response) { CRM.alert(response.message, 'success'); load(); })
                .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); })
                .always(function () { button.prop('disabled', false); });
        });
    @endif

    $('#f-apply').on('click', function () { page = 1; load(); });
    $('#f-retired').on('change', function () { page = 1; load(); });
    $('#f-q').on('keypress', function (e) { if (e.which === 13) { page = 1; load(); } });
    $('#page-prev').on('click', function () { if (page > 1) { page--; load(); } });
    $('#page-next').on('click', function () { page++; load(); });

    load();
});
</script>
@endpush
