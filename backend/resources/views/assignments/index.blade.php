{{--
    The unassigned pool (T-46, T-47) - FR-LEAD-08/10, BR-ASSIGN-01..05.

    A manager's inbox. Leads land here from manual creation and from any import
    or webhook where auto-assignment found nobody eligible; without a screen
    they accumulate silently, because the unassigned pool is invisible to the
    telecallers scoped to their own leads.

    Every action goes through /api/v1/leads/{id}/assign|unassign|auto-assign, so
    the open-lead cap, the "can this user work leads?" guard and the append-only
    assignment history all apply exactly as they do to the API.
--}}
@extends('layouts.app')
@section('title', 'Assignments')

@section('content')
    <div class="card mb-3">
        <div class="card-body py-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small mb-1" for="assignee">Assign to</label>
                    {{-- Open-lead counts are the point: "who is free?" is the
                         question being asked (BR-ASSIGN-02). --}}
                    <select id="assignee" class="form-select form-select-sm">
                        <option value="">Loading…</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small mb-1" for="assign-reason">Reason (optional)</label>
                    <input type="text" id="assign-reason" class="form-control form-control-sm" maxlength="255">
                </div>
                <div class="col-md-4 d-flex gap-2">
                    <button id="assign-selected" class="btn btn-sm btn-primary flex-fill" disabled>
                        Assign selected
                    </button>
                    <button id="auto-assign-selected" class="btn btn-sm btn-outline-secondary flex-fill" disabled>
                        Auto-assign
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                <tr class="small">
                    <th style="width:2.5rem"><input type="checkbox" id="check-all" class="form-check-input"></th>
                    <th>Name</th><th>Phone</th><th>City</th>
                    <th>Status</th><th>Source</th><th class="text-end">Created</th>
                </tr>
                </thead>
                <tbody id="pool-rows">
                <tr><td colspan="7" class="text-center text-muted py-4">Loading…</td></tr>
                </tbody>
            </table>
        </div>
        <div class="card-footer d-flex justify-content-between align-items-center">
            <span class="small text-muted" id="pool-meta"></span>
            <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-secondary" id="page-prev">Previous</button>
                <button class="btn btn-outline-secondary" id="page-next">Next</button>
            </div>
        </div>
    </div>

    <p class="text-muted small mt-3 mb-0">
        Auto-assign runs the configured strategy and leaves a lead in the pool when nobody is
        eligible — an unassigned lead is recoverable, one buried in an overloaded queue is not.
    </p>
@endsection

@push('scripts')
<script>
$(function () {
    let page = 1;

    // ------------------------------------------------------------- assignees
    function loadAssignees() {
        $.getJSON('/api/v1/leads/assignees')
            .done(function (response) {
                const people = response.data;

                if (!people.length) {
                    // Not an error - everyone is inactive or at the open-lead
                    // cap. Auto-assign stays available because it reports that
                    // state per lead.
                    $('#assignee').html('<option value="">No telecaller can take more work</option>');
                    return;
                }

                $('#assignee').html(people.map(function (person) {
                    return '<option value="' + person.id + '">'
                        + CRM.escape(person.name) + ' (' + person.open_lead_count + ' open)'
                        + '</option>';
                }).join(''));
            })
            .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); });
    }

    // ------------------------------------------------------------- the pool
    function load() {
        // The documented null operator, not an empty filter value: an empty
        // value would be a WHERE assigned_to = '' and match nothing.
        $.getJSON('/api/v1/leads', {
            page: page,
            sort: '-created_at',
            'filter[assigned_to][null]': 'true'
        })
            .done(function (response) {
                render(response.data.items, response.data.meta);
            })
            .fail(function (xhr) {
                CRM.alert(CRM.errorFrom(xhr));
                $('#pool-rows').html('<tr><td colspan="7" class="text-center text-muted py-4">Could not load the pool.</td></tr>');
            });
    }

    function render(items, meta) {
        if (!items.length) {
            $('#pool-rows').html('<tr><td colspan="7" class="text-center text-muted py-4">Nothing is waiting for an owner.</td></tr>');
        } else {
            $('#pool-rows').html(items.map(function (lead) {
                return '<tr>'
                    + '<td><input type="checkbox" class="form-check-input row-check" value="' + lead.id + '"></td>'
                    + '<td><a href="/leads/' + lead.id + '">' + CRM.escape(lead.name) + '</a>'
                    + (lead.company ? '<div class="small text-muted">' + CRM.escape(lead.company) + '</div>' : '')
                    + '</td>'
                    + '<td class="small">' + CRM.escape(lead.phone_formatted || lead.phone) + '</td>'
                    + '<td class="small">' + CRM.escape(lead.city) + '</td>'
                    + '<td><span class="badge badge-status text-bg-' + CRM.statusClass(lead.status) + '">'
                    + CRM.escape(lead.status_label) + '</span>'
                    + (lead.is_suppressed ? ' <span class="badge text-bg-danger">DNC</span>' : '')
                    + '</td>'
                    + '<td class="small">' + CRM.escape(lead.source ? lead.source.name : '—') + '</td>'
                    + '<td class="small text-end text-muted">' + lead.created_at.substring(0, 10) + '</td>'
                    + '</tr>';
            }).join(''));
        }

        $('#pool-meta').text(
            meta.total + ' lead' + (meta.total === 1 ? '' : 's') + ' awaiting an owner'
            + ' · page ' + meta.current_page + ' of ' + meta.last_page
        );
        $('#page-prev').prop('disabled', meta.current_page <= 1);
        $('#page-next').prop('disabled', meta.current_page >= meta.last_page);

        $('#check-all').prop('checked', false);
        syncButtons();
    }

    function selected() {
        return $('.row-check:checked').map(function () { return Number(this.value); }).get();
    }

    function syncButtons() {
        const any = selected().length > 0;
        $('#assign-selected, #auto-assign-selected').prop('disabled', !any);
    }

    // ---------------------------------------------------------------- assign
    /*
     * One request per lead, run in sequence.
     *
     * There is no bulk assignment endpoint, and inventing one in the browser by
     * firing N parallel requests would race the open-lead cap: each assignment
     * changes who is eligible for the next. Sequential is slower and correct.
     */
    function assignEach(ids, request) {
        const total = ids.length;
        let done = 0, failed = 0, lastError = null;

        $('#assign-selected, #auto-assign-selected').prop('disabled', true);

        function step() {
            if (!ids.length) {
                let message = done + ' of ' + total + ' assigned.';
                if (failed) message += ' ' + failed + ' could not be: ' + lastError;

                CRM.alert(message, failed ? 'warning' : 'success');
                loadAssignees();
                load();
                return;
            }

            const id = ids.shift();

            request(id)
                .done(function (response) {
                    // Auto-assign returns 200 with the lead still unassigned
                    // when nobody is eligible - a success envelope that is not
                    // an assignment, so count what actually happened.
                    if (response.data && response.data.assigned_to) {
                        done++;
                    } else {
                        failed++;
                        lastError = response.message;
                    }
                })
                .fail(function (xhr) {
                    failed++;
                    lastError = CRM.errorFrom(xhr);
                })
                .always(step);
        }

        step();
    }

    $('#assign-selected').on('click', function () {
        const userId = $('#assignee').val();
        if (!userId) {
            CRM.alert('Choose someone to assign to first.');
            return;
        }

        const payload = { user_id: userId };
        const reason = $.trim($('#assign-reason').val());
        if (reason) payload.reason = reason;

        assignEach(selected(), function (id) {
            return $.ajax({ url: '/api/v1/leads/' + id + '/assign', method: 'POST', data: payload });
        });
    });

    $('#auto-assign-selected').on('click', function () {
        assignEach(selected(), function (id) {
            return $.ajax({ url: '/api/v1/leads/' + id + '/auto-assign', method: 'POST' });
        });
    });

    // ------------------------------------------------------------ page wiring
    $('#check-all').on('change', function () {
        $('.row-check').prop('checked', this.checked);
        syncButtons();
    });
    $('#pool-rows').on('change', '.row-check', syncButtons);

    $('#page-prev').on('click', function () { if (page > 1) { page--; load(); } });
    $('#page-next').on('click', function () { page++; load(); });

    loadAssignees();
    load();
});
</script>
@endpush
