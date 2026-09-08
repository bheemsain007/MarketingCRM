{{--
    Integrations (a curated view over Settings).

    Same shell as settings/index.blade.php - reads/writes the same
    `/api/v1/settings` endpoint, the same registry, the same permission. This
    page adds nothing the full Settings screen could not already do; it only
    picks out the credential groups that are one recognisable "provider" and
    presents each as a connect/manage card instead of a row in a long form.
    `dnc` and `delivery` are deliberately absent - both are a single shared
    webhook secret spanning several channels, not a provider a card can name -
    and stay reachable only from the full Settings screen.
--}}
@extends('layouts.app')
@section('title', 'Integrations')

@section('content')
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h5 class="mb-0">Integrations</h5>
            <div class="text-muted small">Connect the channels and providers this CRM sends through.</div>
        </div>
        <a href="{{ route('web.settings') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-sliders me-1"></i>All settings
        </a>
    </div>

    <div class="mb-3">
        <input type="search" id="integration-search" class="form-control" placeholder="Search integrations…">
    </div>

    <div id="integrations-root" class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-3">
        <div class="col-12 text-center text-muted py-5">Loading…</div>
    </div>

    {{-- One shared modal, repopulated per provider on open. --}}
    <div class="modal fade" id="integration-modal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title" id="integration-modal-title">Connect</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="integration-modal-body"></div>
                <div class="modal-footer">
                    <span class="small text-muted me-auto" id="integration-modal-status"></span>
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary btn-sm" id="integration-save">Save</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
$(function () {
    // Metadata for the groups this page turns into cards. Everything else
    // about a setting - its label, type, help text, whether it is secret -
    // still comes from the API; this is only what the registry has no reason
    // to know: an icon and a sentence for the card face.
    const CARDS = [
        { group: 'whatsapp', name: 'WhatsApp Business', icon: 'bi-whatsapp', color: '#25D366',
          description: 'Send WhatsApp messages to leads and receive their replies.' },
        { group: 'meta', name: 'Facebook Lead Ads', icon: 'bi-facebook', color: '#1877F2',
          description: 'Capture leads automatically from Facebook and Instagram Lead Ads.' },
        { group: 'sms', name: 'SMS (BhashSMS)', icon: 'bi-chat-dots', color: '#6366F1',
          description: 'Send SMS text messages to leads.' },
        { group: 'email', name: 'Email (Mailercloud)', icon: 'bi-envelope', color: '#F59E0B',
          description: 'Send transactional and marketing email to leads.' },
        { group: 'rcs', name: 'RCS Messaging', icon: 'bi-chat-square-text', color: '#0EA5E9',
          description: 'Rich messaging with read receipts and media - vendor not yet chosen.' },
        { group: 'voice', name: 'Voice / IVR', icon: 'bi-telephone', color: '#8B5CF6',
          description: 'Automated voice calls - vendor not yet chosen.' },
        { group: 'ai_calling', name: 'AI Calling (Vaaad)', icon: 'bi-robot', color: '#10B981',
          description: 'AI-assisted calling and voice automation.' },
        { group: 'payment', name: 'Payments (Razorpay)', icon: 'bi-credit-card', color: '#EF4444',
          description: 'Collect payments and generate payment links.' },
    ];

    let groupsByName = {};
    let activeGroup = null;
    const modal = new bootstrap.Modal(document.getElementById('integration-modal'));

    function load() {
        $.getJSON('/api/v1/settings')
            .done(function (response) {
                groupsByName = {};
                (response.data.groups || []).forEach(function (g) { groupsByName[g.group] = g; });
                render();
            })
            .fail(function (xhr) {
                CRM.alert(CRM.errorFrom(xhr));
                $('#integrations-root').html('<div class="col-12 text-center text-muted py-5">Could not load integrations.</div>');
            });
    }

    /** A card is "Connected" only once every credential in its group is set -
     * a partly-filled provider still refuses, the same as none at all. */
    function isConnected(group) {
        const settings = (groupsByName[group] || {}).settings || [];
        return settings.length > 0 && settings.every(function (s) { return s.is_configured; });
    }

    function render() {
        const query = ($('#integration-search').val() || '').toLowerCase().trim();
        const visible = CARDS.filter(function (card) {
            return groupsByName[card.group] && (
                !query || card.name.toLowerCase().includes(query) || card.description.toLowerCase().includes(query)
            );
        });

        if (!visible.length) {
            $('#integrations-root').html(
                '<div class="col-12 text-center text-muted py-5">'
                + (Object.keys(groupsByName).length ? 'No integrations match your search.' : 'Nothing here for your role.')
                + '</div>'
            );
            return;
        }

        $('#integrations-root').html(visible.map(function (card) {
            const connected = isConnected(card.group);

            return '<div class="col">'
                + '<div class="card h-100">'
                + '<div class="card-body d-flex flex-column">'
                + '<div class="d-flex align-items-start justify-content-between mb-2">'
                + '<div class="d-flex align-items-center justify-content-center rounded-circle"'
                + ' style="width:44px;height:44px;background:' + card.color + '1a;color:' + card.color + ';font-size:1.25rem;">'
                + '<i class="bi ' + card.icon + '"></i></div>'
                + '<span class="badge rounded-pill ' + (connected ? 'text-bg-success' : 'text-bg-light border text-muted') + '">'
                + '<i class="bi ' + (connected ? 'bi-check-circle' : 'bi-dash-circle') + ' me-1"></i>'
                + (connected ? 'Connected' : 'Not Connected') + '</span>'
                + '</div>'
                + '<div class="fw-semibold mb-1">' + CRM.escape(card.name) + '</div>'
                + '<div class="text-muted small flex-grow-1 mb-3">' + CRM.escape(card.description) + '</div>'
                + '<button type="button" class="btn btn-sm ' + (connected ? 'btn-outline-secondary' : 'btn-primary') + ' open-integration"'
                + ' data-group="' + CRM.escape(card.group) + '">'
                + '<i class="bi ' + (connected ? 'bi-gear' : 'bi-plug') + ' me-1"></i>'
                + (connected ? 'Manage' : 'Connect') + '</button>'
                + '</div></div></div>';
        }).join(''));
    }

    $('#integration-search').on('input', render);

    // --- Modal: same field-rendering rules as the full Settings screen -----

    function fieldId(key) {
        return 'int-' + key.replace(/\./g, '-');
    }

    function control(setting) {
        const id = fieldId(setting.key);
        const value = setting.value === null || setting.value === undefined ? '' : String(setting.value);

        if (setting.is_secret) {
            return '<input type="password" class="form-control form-control-sm setting-input"'
                + ' id="' + id + '" data-key="' + CRM.escape(setting.key) + '" autocomplete="new-password"'
                + ' placeholder="' + (setting.is_configured
                    ? CRM.escape(setting.hint || '••••') + ' — leave blank to keep'
                    : 'Not set') + '">';
        }

        if (setting.type === 'select') {
            return '<select class="form-select form-select-sm setting-input" id="' + id + '"'
                + ' data-key="' + CRM.escape(setting.key) + '">'
                + '<option value="">Not set</option>'
                + setting.options.map(function (option) {
                    return '<option value="' + CRM.escape(option) + '"'
                        + (option === value ? ' selected' : '') + '>' + CRM.escape(option) + '</option>';
                }).join('')
                + '</select>';
        }

        const inputType = (setting.type === 'int' || setting.type === 'float') ? 'number'
            : (setting.type === 'time' ? 'time' : 'text');
        const step = setting.type === 'float' ? ' step="any"' : '';

        return '<input type="' + inputType + '"' + step
            + ' class="form-control form-control-sm setting-input" id="' + id + '"'
            + ' data-key="' + CRM.escape(setting.key) + '" value="' + CRM.escape(value) + '">';
    }

    function openModal(group) {
        const meta = CARDS.find(function (c) { return c.group === group; });
        const settings = (groupsByName[group] || {}).settings || [];
        activeGroup = group;

        $('#integration-modal-title').text((meta ? meta.name : group) + (isConnected(group) ? ' — Manage' : ' — Connect'));
        $('#integration-modal-status').text('');
        $('#integration-modal-body').html(settings.map(function (setting) {
            return '<div class="mb-3">'
                + '<label class="form-label small mb-1" for="' + fieldId(setting.key) + '">'
                + CRM.escape(setting.label)
                + (setting.is_secret ? ' <span class="badge text-bg-light border">secret</span>' : '')
                + '</label>'
                + (setting.help ? '<div class="form-text small mt-0 mb-1">' + CRM.escape(setting.help) + '</div>' : '')
                + control(setting)
                + (setting.is_secret && setting.is_configured
                    ? '<div class="form-check mt-1">'
                      + '<input class="form-check-input clear-secret" type="checkbox"'
                      + ' data-key="' + CRM.escape(setting.key) + '" id="clr-' + fieldId(setting.key) + '">'
                      + '<label class="form-check-label small" for="clr-' + fieldId(setting.key) + '">Clear this value</label>'
                      + '</div>'
                    : '')
                + '</div>';
        }).join(''));

        $('.setting-input', '#integration-modal-body').each(function () {
            $(this).data('initial', $(this).val());
        });

        modal.show();
    }

    $('#integrations-root').on('click', '.open-integration', function () {
        openModal($(this).data('group'));
    });

    function changes() {
        const payload = {};

        $('.setting-input', '#integration-modal-body').each(function () {
            const key = $(this).data('key');
            const current = $(this).val();

            if (current !== $(this).data('initial')) {
                payload[key] = current === '' ? null : current;
            }
        });

        $('.clear-secret:checked', '#integration-modal-body').each(function () {
            payload[$(this).data('key')] = null;
        });

        return payload;
    }

    $('#integration-save').on('click', function () {
        const payload = changes();

        if (!Object.keys(payload).length) {
            modal.hide();
            return;
        }

        $('#integration-save').prop('disabled', true);
        $('#integration-modal-status').text('Saving…');

        $.ajax({
            url: '/api/v1/settings',
            method: 'PATCH',
            contentType: 'application/json',
            data: JSON.stringify({ settings: payload })
        })
            .done(function () {
                modal.hide();
                CRM.alert('Saved.', 'success');
                load();
            })
            .fail(function (xhr) {
                $('#integration-modal-status').text('');
                CRM.alert(CRM.errorFrom(xhr));
            })
            .always(function () {
                $('#integration-save').prop('disabled', false);
            });
    });

    load();
});
</script>
@endpush
