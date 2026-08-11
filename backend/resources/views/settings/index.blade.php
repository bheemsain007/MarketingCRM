{{--
    Settings and provider credentials (SEC-CFG-01/04, SEC-AUD-02).

    A pure shell: every field, its type, and whether this user may see it at all
    comes from /api/v1/settings. The registry (`App\Support\SettingsRegistry`)
    is the single definition of what is settable, so this page cannot offer a
    field the API would reject.

    Two rules the markup exists to enforce:
      - A secret value is never rendered. The API does not return it; the input
        starts blank and blank means "leave alone" (SEC-CFG-05).
      - Only changed fields are submitted, so opening the page and pressing Save
        does not rewrite every setting - and does not overwrite a stored secret
        with an empty string.
--}}
@extends('layouts.app')
@section('title', 'Settings')

@section('content')
    <div class="alert alert-light border small">
        Values shown are what is <strong>in effect</strong> — a stored override if there is one,
        otherwise the default from configuration. Clearing a field restores the default rather
        than emptying the setting.
    </div>

    <div id="settings-root">
        <div class="text-center text-muted py-5">Loading…</div>
    </div>

    <div class="d-flex gap-2 mt-3 d-none" id="save-bar">
        <button id="save" class="btn btn-sm btn-primary">Save changes</button>
        <button id="reset" class="btn btn-sm btn-outline-secondary">Discard</button>
        <span class="align-self-center small text-muted" id="dirty-count"></span>
    </div>
@endsection

@push('scripts')
<script>
$(function () {
    let groups = [];

    function load() {
        $.getJSON('/api/v1/settings')
            .done(function (response) {
                groups = response.data.groups;
                render();
            })
            .fail(function (xhr) {
                CRM.alert(CRM.errorFrom(xhr));
                $('#settings-root').html('<div class="text-center text-muted py-5">Could not load settings.</div>');
            });
    }

    function fieldId(key) {
        // Dots are not valid in a plain jQuery id selector without escaping;
        // a data attribute lookup avoids the whole problem.
        return 'set-' + key.replace(/\./g, '-');
    }

    function control(setting) {
        const id = fieldId(setting.key);
        const value = setting.value === null || setting.value === undefined ? '' : String(setting.value);

        if (setting.is_secret) {
            // Never pre-filled. The placeholder says whether one is stored.
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

    function render() {
        if (!groups.length) {
            $('#settings-root').html('<div class="text-center text-muted py-5">Nothing here for your role.</div>');
            return;
        }

        $('#settings-root').html(groups.map(function (group) {
            return '<div class="card mb-3">'
                + '<div class="card-header py-2"><span class="small fw-semibold">'
                + CRM.escape(group.label) + '</span></div>'
                + '<div class="card-body">'
                + group.settings.map(function (setting) {
                    return '<div class="row g-2 align-items-start mb-3">'
                        + '<div class="col-md-5">'
                        + '<label class="form-label small mb-0" for="' + fieldId(setting.key) + '">'
                        + CRM.escape(setting.label)
                        + (setting.is_secret ? ' <span class="badge text-bg-light border">secret</span>' : '')
                        + '</label>'
                        + (setting.help ? '<div class="form-text small mt-0">' + CRM.escape(setting.help) + '</div>' : '')
                        + '<div class="form-text small text-muted"><code>' + CRM.escape(setting.key) + '</code></div>'
                        + '</div>'
                        + '<div class="col-md-5">' + control(setting) + '</div>'
                        + '<div class="col-md-2 small pt-1">'
                        + (setting.is_configured
                            ? '<span class="text-success">Configured</span>'
                            : '<span class="text-muted">Not set</span>')
                        + (setting.is_secret && setting.is_configured
                            ? '<div class="form-check mt-1">'
                              + '<input class="form-check-input clear-secret" type="checkbox"'
                              + ' data-key="' + CRM.escape(setting.key) + '" id="clr-' + fieldId(setting.key) + '">'
                              + '<label class="form-check-label small" for="clr-' + fieldId(setting.key) + '">Clear</label>'
                              + '</div>'
                            : '')
                        + '</div>'
                        + '</div>';
                }).join('')
                + '</div></div>';
        }).join(''));

        // Snapshot for dirty-tracking. Secrets start blank, so any typed value
        // is by definition a change.
        $('.setting-input').each(function () {
            $(this).data('initial', $(this).val());
        });

        $('#save-bar').removeClass('d-none');
        syncDirty();
    }

    /** Only what the user actually touched. */
    function changes() {
        const payload = {};

        $('.setting-input').each(function () {
            const key = $(this).data('key');
            const current = $(this).val();

            if (current !== $(this).data('initial')) {
                // An emptied non-secret clears the override; a blank secret is
                // "leave alone" and never reaches here, because blank is its
                // initial value.
                payload[key] = current === '' ? null : current;
            }
        });

        $('.clear-secret:checked').each(function () {
            payload[$(this).data('key')] = null;
        });

        return payload;
    }

    function syncDirty() {
        const count = Object.keys(changes()).length;
        $('#dirty-count').text(count ? count + ' unsaved change' + (count === 1 ? '' : 's') : '');
        $('#save').prop('disabled', count === 0);
    }

    $('#settings-root').on('input change', '.setting-input, .clear-secret', syncDirty);

    $('#save').on('click', function () {
        const payload = changes();
        if (!Object.keys(payload).length) return;

        $('#save').prop('disabled', true);

        $.ajax({
            url: '/api/v1/settings',
            method: 'PATCH',
            contentType: 'application/json',
            data: JSON.stringify({ settings: payload })
        })
            .done(function (response) {
                CRM.alert(response.message, 'success');
                load();   // Re-read: a cleared override falls back to its default.
            })
            .fail(function (xhr) {
                CRM.alert(CRM.errorFrom(xhr));
                $('#save').prop('disabled', false);
            });
    });

    $('#reset').on('click', function () { load(); });

    load();
});
</script>
@endpush
