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
                <div class="modal-footer" id="integration-modal-footer">
                    <span class="small text-muted me-auto" id="integration-modal-status"></span>
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary btn-sm" id="integration-save">Save</button>
                </div>
            </div>
        </div>
    </div>

    {{-- Facebook's own Page picker - shown after the OAuth redirect comes back. --}}
    <div class="modal fade" id="meta-pages-modal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title">Choose your Facebook Page</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted">This is the Page whose Lead Ads forms will send new leads to this CRM.</p>
                    <div id="meta-pages-list" class="list-group"></div>
                </div>
            </div>
        </div>
    </div>

    {{-- Bridges Laravel's session flash (set by a server redirect, invisible to
         a fetch/AJAX call) into something the script below can read. --}}
    <script>
        window.__metaConnect = {
            pickPage: {{ request()->boolean('meta_pick_page') ? 'true' : 'false' }},
            error: @json(session('meta_error')),
        };
    </script>
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

    function fieldsMarkup(settings) {
        return settings.map(function (setting) {
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
        }).join('');
    }

    /** App ID + secret are the only two Facebook itself requires before an
     * OAuth redirect can even be built (the dialog URL needs a client_id). */
    function metaAppConfigured() {
        const settings = (groupsByName.meta || {}).settings || [];
        return settings.filter(function (s) {
            return s.key === 'providers.meta.app_id' || s.key === 'providers.meta.app_secret';
        }).every(function (s) { return s.is_configured; });
    }

    const META_HELP =
        '<div class="alert alert-light border small mb-3">'
        + '<strong>Where to find these:</strong>'
        + '<ol class="mb-0 ps-3 mt-1">'
        + '<li><a href="https://developers.facebook.com" target="_blank" rel="noopener">developers.facebook.com</a> → '
        + 'My Apps → Create App → choose <strong>Business</strong>.</li>'
        + '<li>Open the app → Settings → Basic. Copy the <strong>App ID</strong> and <strong>App Secret</strong>.</li>'
        + '<li>Paste them here and continue - webhook setup, choosing your Page and the access token are all automatic '
        + 'from that point.</li>'
        + '</ol>'
        + '<div class="mt-1">Testing on a Page you administer works immediately. Meta may ask for App Review before '
        + 'this works for a Page you do not administer.</div>'
        + '</div>';

    function openMetaModal() {
        const settings = (groupsByName.meta || {}).settings || [];
        const appFields = settings.filter(function (s) { return s.key.match(/app_id|app_secret$/); });
        const connected = isConnected('meta');

        $('#integration-modal-title').text('Facebook Lead Ads — ' + (connected ? 'Manage' : 'Connect'));
        $('#integration-modal-status').text('');

        if (!metaAppConfigured()) {
            // Step 1 of 2: nothing to redirect to until Facebook's own App
            // exists. "Save & Continue" saves these two fields, then - once
            // the reload confirms they stuck - immediately starts the OAuth
            // redirect, so this never feels like two separate button presses.
            $('#integration-modal-body').html(
                META_HELP + fieldsMarkup(appFields)
            );
            $('.setting-input', '#integration-modal-body').each(function () { $(this).data('initial', $(this).val()); });
            $('#integration-save').show().text('Save & Continue');
            $('#integration-save').data('mode', 'meta-step1');
        } else {
            // Step 2 of 2, or already connected: the one real action is the
            // Facebook redirect. Editing the Page token by hand stays offered,
            // one click further, for a Page OAuth cannot reach (e.g. one this
            // account was only just made an admin of and Facebook has not
            // caught up on) or when reconnecting without changing Page.
            const pageToken = settings.find(function (s) { return s.key === 'providers.meta.page_access_token'; });

            $('#integration-modal-body').html(
                '<p class="small">'
                + (connected
                    ? 'Connected. Reconnecting lets you pick a different Page, or refresh the access token.'
                    : 'Your Facebook App is saved. Continue to sign in with Facebook and choose the Page to capture leads from.')
                + '</p>'
                + '<a href="' + '{{ route('web.integrations.meta.connect') }}' + '" class="btn btn-primary w-100 mb-2">'
                + '<i class="bi bi-facebook me-1"></i>Continue with Facebook</a>'
                + (connected
                    ? '<button type="button" class="btn btn-outline-danger w-100 mb-3" id="meta-disconnect">Disconnect</button>'
                    : '')
                + '<details><summary class="small text-muted">Enter the Page access token manually instead</summary>'
                + '<div class="mt-2">' + fieldsMarkup(pageToken ? [pageToken] : []) + '</div></details>'
            );
            $('.setting-input', '#integration-modal-body').each(function () { $(this).data('initial', $(this).val()); });
            $('#integration-save').show().text('Save').data('mode', 'default');
        }

        modal.show();
    }

    function openModal(group) {
        activeGroup = group;

        if (group === 'meta') {
            openMetaModal();
            return;
        }

        const cardMeta = CARDS.find(function (c) { return c.group === group; });
        const settings = (groupsByName[group] || {}).settings || [];

        $('#integration-modal-title').text((cardMeta ? cardMeta.name : group) + (isConnected(group) ? ' — Manage' : ' — Connect'));
        $('#integration-modal-status').text('');
        $('#integration-modal-body').html(fieldsMarkup(settings));
        $('#integration-save').show().text('Save').data('mode', 'default');

        $('.setting-input', '#integration-modal-body').each(function () {
            $(this).data('initial', $(this).val());
        });

        modal.show();
    }

    $('#integrations-root').on('click', '.open-integration', function () {
        openModal($(this).data('group'));
    });

    $('#integration-modal-body').on('click', '#meta-disconnect', function () {
        $(this).prop('disabled', true);

        $.post('{{ route('web.integrations.meta.disconnect') }}')
            .done(function (response) {
                modal.hide();
                CRM.alert(response.message, 'success');
                load();
            })
            .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); })
            .always(function () { $('#meta-disconnect').prop('disabled', false); });
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
        const mode = $(this).data('mode');
        const payload = changes();

        if (!Object.keys(payload).length) {
            // Step 1 with nothing changed (App ID/secret already both typed on
            // a previous attempt that failed to save) still has to be able to
            // move on to the redirect.
            if (mode === 'meta-step1') {
                window.location = '{{ route('web.integrations.meta.connect') }}';
            } else {
                modal.hide();
            }
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
                if (mode === 'meta-step1') {
                    // Straight into the Facebook redirect - the two-field save
                    // above and "Continue with Facebook" read as one action.
                    window.location = '{{ route('web.integrations.meta.connect') }}';
                    return;
                }

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

    // --- Facebook OAuth: the Page picker Facebook's redirect lands on -------

    const pagesModal = new bootstrap.Modal(document.getElementById('meta-pages-modal'));

    function openPagePicker() {
        $('#meta-pages-list').html('<div class="text-center text-muted py-3">Loading your Pages…</div>');
        pagesModal.show();

        $.getJSON('{{ route('web.integrations.meta.pages') }}')
            .done(function (response) {
                const pages = response.data || [];

                if (!pages.length) {
                    $('#meta-pages-list').html('<div class="text-muted small py-2">No Pages found. Reconnect from the Facebook Lead Ads card to try again.</div>');
                    return;
                }

                $('#meta-pages-list').html(pages.map(function (page) {
                    return '<button type="button" class="list-group-item list-group-item-action select-meta-page" data-id="'
                        + CRM.escape(page.id) + '">' + CRM.escape(page.name) + '</button>';
                }).join(''));
            })
            .fail(function (xhr) {
                pagesModal.hide();
                CRM.alert(CRM.errorFrom(xhr));
            });
    }

    $('#meta-pages-list').on('click', '.select-meta-page', function () {
        const button = $(this);
        button.prop('disabled', true).siblings().prop('disabled', true);

        $.post('{{ route('web.integrations.meta.select-page') }}', { page_id: button.data('id') })
            .done(function (response) {
                pagesModal.hide();
                CRM.alert(response.message, 'success');
                load();
            })
            .fail(function (xhr) {
                CRM.alert(CRM.errorFrom(xhr));
                button.prop('disabled', false).siblings().prop('disabled', false);
            });
    });

    if (window.__metaConnect && window.__metaConnect.error) {
        CRM.alert(window.__metaConnect.error, 'danger');
    }

    load();

    if (window.__metaConnect && window.__metaConnect.pickPage) {
        openPagePicker();
    }
});
</script>
@endpush
