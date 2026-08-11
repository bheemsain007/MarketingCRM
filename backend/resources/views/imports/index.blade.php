@extends('layouts.app')
@section('title', 'Lead imports')

@section('content')
<div class="row g-3">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-body">
                <h2 class="h6">Upload a file</h2>
                <p class="text-muted small">
                    CSV only — in Excel choose <em>File → Save As → CSV</em>. Columns like
                    <code>Full Name</code>, <code>Mobile No.</code> and <code>Email</code> are recognised
                    automatically. A phone number and a name are the only required columns.
                </p>

                <form id="import-form">
                    <div class="mb-3">
                        <input type="file" class="form-control form-control-sm" id="import-file"
                               name="file" accept=".csv,.txt,.tsv" required>
                    </div>

                    @permission('leads.assign')
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="auto-assign" value="1">
                        <label class="form-check-label small" for="auto-assign">
                            Assign automatically to available telecallers
                        </label>
                    </div>
                    @endpermission

                    <button class="btn btn-sm btn-primary" type="submit">Upload and queue</button>
                </form>

                <div id="upload-progress" class="mt-3 d-none">
                    <div class="progress" style="height: 6px;">
                        <div class="progress-bar progress-bar-striped progress-bar-animated"
                             id="progress-bar" style="width: 0%"></div>
                    </div>
                    <div class="small text-muted mt-1" id="progress-text"></div>
                </div>
            </div>
        </div>

        <p class="text-muted small mt-2 mb-0">
            The upload is queued, not processed on the spot — a large file keeps going after you close this page.
            Duplicates are reported, never imported twice.
        </p>
    </div>

    <div class="col-lg-7">
        <div class="card">
            <div class="card-body">
                <h2 class="h6">Recent imports</h2>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="small text-muted">
                        <tr><th>File</th><th>Status</th><th class="text-end">Imported</th>
                            <th class="text-end">Duplicate</th><th class="text-end">Invalid</th><th></th></tr>
                        </thead>
                        <tbody id="import-rows">
                        <tr><td colspan="6" class="text-center text-muted py-4">Loading…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card mt-3 d-none" id="report-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h6 mb-0">Rows that did not import</h2>
                    <button class="btn-close" id="close-report" aria-label="Close"></button>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="small text-muted"><tr><th>Row</th><th>Outcome</th><th>Reason</th></tr></thead>
                        <tbody id="report-rows"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    let pollTimer = null;

    function loadImports() {
        $.getJSON('/api/v1/leads/imports').done(function (response) {
            const items = response.data.items;

            if (!items.length) {
                $('#import-rows').html('<tr><td colspan="6" class="text-center text-muted py-4">No imports yet.</td></tr>');
                return;
            }

            $('#import-rows').html(items.map(function (row) {
                const tone = { completed: 'success', completed_with_errors: 'warning',
                               failed: 'danger', processing: 'info', pending: 'secondary' }[row.status] || 'secondary';

                return '<tr>'
                    + '<td class="small">' + CRM.escape(row.filename) + '</td>'
                    + '<td><span class="badge text-bg-' + tone + '">' + CRM.escape(row.status_label) + '</span>'
                    + (row.is_finished ? '' : ' <span class="small text-muted">' + row.progress + '%</span>')
                    + '</td>'
                    + '<td class="text-end small">' + row.totals.imported + '</td>'
                    + '<td class="text-end small">' + row.totals.duplicates + '</td>'
                    + '<td class="text-end small">' + row.totals.invalid + '</td>'
                    + '<td class="text-end">'
                    + ((row.totals.duplicates + row.totals.invalid) > 0
                        ? '<button class="btn btn-sm btn-outline-secondary view-report" data-id="' + row.id + '">Report</button>'
                        : '')
                    + '</td></tr>';
            }).join(''));

            // Keep polling only while something is actually running.
            const running = items.some(function (row) { return !row.is_finished; });
            clearTimeout(pollTimer);
            if (running) pollTimer = setTimeout(loadImports, 3000);
        });
    }

    $('#import-form').on('submit', function (event) {
        event.preventDefault();

        const file = $('#import-file')[0].files[0];
        if (!file) return;

        const form = new FormData();
        form.append('file', file);
        if ($('#auto-assign').is(':checked')) form.append('auto_assign', '1');

        $('#upload-progress').removeClass('d-none');
        $('#progress-bar').css('width', '0%');
        $('#progress-text').text('Uploading…');

        $.ajax({
            url: '/api/v1/leads/import',
            method: 'POST',
            data: form,
            processData: false,
            contentType: false
        })
            .done(function (response) {
                // 202: accepted, not finished. The row work happens on the queue.
                $('#progress-bar').css('width', '100%');
                $('#progress-text').text(
                    'Queued — ' + response.data.totals.rows + ' rows. Processing continues in the background.'
                );
                $('#import-file').val('');
                CRM.alert(response.message, 'success');
                loadImports();
            })
            .fail(function (xhr) {
                $('#upload-progress').addClass('d-none');

                // A 422 with detected_header means the columns could not be
                // matched — say which ones we found rather than just refusing.
                const detected = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.detected_header;
                CRM.alert(CRM.errorFrom(xhr) + (detected ? ' Columns found: ' + detected.join(', ') + '.' : ''));
            });
    });

    $('#import-rows').on('click', '.view-report', function () {
        $.getJSON('/api/v1/leads/imports/' + $(this).data('id') + '/rows', { per_page: 100 })
            .done(function (response) {
                $('#report-rows').html(response.data.items
                    .filter(function (row) { return row.status !== 'imported'; })
                    .map(function (row) {
                        return '<tr>'
                            + '<td class="small">' + row.row_number + '</td>'
                            + '<td><span class="badge text-bg-'
                            + (row.status === 'duplicate' ? 'info' : 'danger') + '">'
                            + CRM.escape(row.status_label) + '</span></td>'
                            + '<td class="small text-muted">' + CRM.escape(row.message || '') + '</td>'
                            + '</tr>';
                    }).join(''));

                $('#report-card').removeClass('d-none');
            });
    });

    $('#close-report').on('click', function () { $('#report-card').addClass('d-none'); });

    loadImports();
});
</script>
@endpush
