@extends('layouts.app')
@section('title', 'Auto dialer')

@section('content')
{{--
    The whole screen is three states: no run open, a run with a lead in hand,
    and a run that has finished. Everything is driven through
    /api/v1/dialer/*, so the browser and a future Flutter dialer share one
    implementation of the skip rules and the single-assignment guarantee.
--}}

{{-- ------------------------------------------------------------------ --}}
{{-- No run open                                                        --}}
{{-- ------------------------------------------------------------------ --}}
<div id="pane-idle" class="d-none">
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-body">
                    <h2 class="h6">Start a dialling run</h2>
                    <p class="text-muted small">
                        The queue is built from your own leads, highest priority first, with
                        never-contacted leads ahead of the rest. Converted and closed leads are left out.
                    </p>

                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label small mb-1" for="q-status">Status</label>
                            <select id="q-status" class="form-select form-select-sm">
                                <option value="">Any</option>
                                @foreach ($statuses as $status)
                                    @if (! in_array($status->value, ['converted', 'lost', 'not_interested']))
                                        <option value="{{ $status->value }}">{{ $status->label() }}</option>
                                    @endif
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small mb-1" for="q-city">City</label>
                            <input type="text" id="q-city" class="form-control form-control-sm" maxlength="100">
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <button id="start-run" class="btn btn-sm btn-primary w-100">Start dialling</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ------------------------------------------------------------------ --}}
{{-- Run in progress                                                    --}}
{{-- ------------------------------------------------------------------ --}}
<div id="pane-run" class="d-none">
    <div class="card mb-3">
        <div class="card-body py-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div>
                    <span class="badge" id="run-state"></span>
                    <span class="small text-muted ms-2" id="run-totals"></span>
                </div>
                <div class="btn-group btn-group-sm">
                    <button id="btn-pause" class="btn btn-outline-secondary">Pause</button>
                    <button id="btn-resume" class="btn btn-outline-secondary d-none">Resume</button>
                    <button id="btn-stop" class="btn btn-outline-danger">Stop</button>
                </div>
            </div>
            <div class="progress" style="height: 6px;">
                <div class="progress-bar" id="run-progress" style="width: 0%"></div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card" id="lead-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h2 class="h5 mb-1" id="lead-name">—</h2>
                            <div class="text-muted small" id="lead-company"></div>
                        </div>
                        <span class="badge" id="lead-status"></span>
                    </div>

                    {{-- The number is the point of the screen. Under ADR-B the
                         handset dials it, so it is a tel: link rather than a
                         button that pretends the browser can place a call. --}}
                    <div class="my-3">
                        <a class="h4 text-decoration-none" id="lead-phone-link" href="#">—</a>
                        <div class="small text-muted" id="lead-location"></div>
                    </div>

                    <div class="border-top pt-3">
                        <div class="row g-2 align-items-end">
                            <div class="col-md-5">
                                <label class="form-label small mb-1" for="outcome">Outcome</label>
                                <select id="outcome" class="form-select form-select-sm">
                                    <option value="">How did it go?</option>
                                    @foreach ($callStatuses as $case)
                                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4 d-none" id="callback-wrap">
                                <label class="form-label small mb-1" for="callback-at">Call back at</label>
                                <input type="datetime-local" id="callback-at" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-3 d-grid">
                                <button id="save-outcome" class="btn btn-sm btn-success">Save outcome</button>
                            </div>
                            <div class="col-12">
                                <input type="text" id="outcome-notes" class="form-control form-control-sm mt-1"
                                       maxlength="5000" placeholder="Notes (optional)">
                            </div>
                        </div>

                        <button id="btn-next" class="btn btn-primary w-100 mt-3">Next lead →</button>
                        <div class="form-text">
                            Moving on closes this call. Save the outcome first, or it is recorded as dialled with no result.
                        </div>
                    </div>
                </div>
            </div>

            <div class="card d-none" id="finished-card">
                <div class="card-body text-center py-4">
                    <h2 class="h6">The queue is finished</h2>
                    <p class="text-muted small mb-3">Everything in this run has been called or skipped.</p>
                    <button id="btn-stop-finished" class="btn btn-sm btn-outline-secondary">Close the run</button>
                </div>
            </div>
        </div>

        {{-- Skipped feed: FR-CALL-07 made visible. Eleven numbers skipped for
             cooldown is a very different run from eleven suppressed. --}}
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h6">Skipped</h2>
                    <p class="text-muted small">
                        Leads the dialer passed over, and why. Nothing is skipped silently.
                    </p>
                    <div id="skip-feed"><p class="text-muted small mb-0">Nothing skipped yet.</p></div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    let session = null;
    let currentCallId = null;
    let outcomeSaved = false;
    const skips = [];

    // ------------------------------------------------------------- rendering
    function renderSession(data) {
        session = data;

        if (!session || !session.is_active) {
            $('#pane-run').addClass('d-none');
            $('#pane-idle').removeClass('d-none');
            return;
        }

        $('#pane-idle').addClass('d-none');
        $('#pane-run').removeClass('d-none');

        const tone = session.state === 'paused' ? 'warning' : 'success';
        $('#run-state').attr('class', 'badge text-bg-' + tone).text(session.state_label);

        $('#run-totals').text(
            session.totals.dialled + ' called · '
            + session.totals.skipped + ' skipped · '
            + session.totals.remaining + ' left'
        );
        $('#run-progress').css('width', session.progress + '%');

        const paused = session.state === 'paused';
        $('#btn-pause').toggleClass('d-none', paused);
        $('#btn-resume').toggleClass('d-none', !paused);
        $('#btn-next').prop('disabled', paused);
    }

    function renderLead(lead, call) {
        currentCallId = call ? call.id : null;
        outcomeSaved = false;

        $('#lead-card').removeClass('d-none');
        $('#finished-card').addClass('d-none');

        $('#lead-name').text(lead.name);
        $('#lead-company').text(lead.company || '');
        $('#lead-location').text([lead.city, lead.state].filter(Boolean).join(', '));
        $('#lead-status')
            .attr('class', 'badge text-bg-' + CRM.statusClass(lead.status))
            .text(lead.status_label);

        // tel: so the handset dials — the browser is the system of record,
        // not the phone (ADR-B).
        $('#lead-phone-link')
            .text(lead.phone_formatted || lead.phone)
            .attr('href', 'tel:' + lead.phone);

        $('#outcome, #outcome-notes, #callback-at').val('');
        $('#callback-wrap').addClass('d-none');
    }

    function renderSkips(newSkips) {
        newSkips.forEach(function (skip) { skips.unshift(skip); });

        if (!skips.length) return;

        $('#skip-feed').html(skips.slice(0, 40).map(function (skip) {
            return '<div class="border-bottom py-2 small">'
                + '<div>' + CRM.escape(skip.lead_name) + '</div>'
                + '<span class="badge text-bg-' + (skip.is_temporary ? 'secondary' : 'danger') + '">'
                + CRM.escape(skip.reason_label) + '</span>'
                + '</div>';
        }).join(''));
    }

    function showFinished() {
        $('#lead-card').addClass('d-none');
        $('#finished-card').removeClass('d-none');
    }

    // -------------------------------------------------------------- actions
    function loadCurrent() {
        $.getJSON('/api/v1/dialer/current').done(function (response) {
            renderSession(response.data);
        });
    }

    $('#start-run').on('click', function () {
        const payload = {};
        if ($('#q-status').val()) payload.status = $('#q-status').val();
        if ($('#q-city').val()) payload.city = $('#q-city').val();

        $.post('/api/v1/dialer/sessions', payload)
            .done(function (response) {
                renderSession(response.data);
                fetchNext();
            })
            .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); });
    });

    function fetchNext() {
        if (!session) return;

        $.post('/api/v1/dialer/sessions/' + session.id + '/next')
            .done(function (response) {
                renderSession(response.data.session);
                renderSkips(response.data.skipped || []);

                if (!response.data.lead) {
                    showFinished();
                    return;
                }

                renderLead(response.data.lead, response.data.call);
            })
            .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); });
    }

    $('#outcome').on('change', function () {
        $('#callback-wrap').toggleClass('d-none', $(this).val() !== 'call_back_requested');
    });

    $('#save-outcome').on('click', function () {
        if (!currentCallId) return;

        const status = $('#outcome').val();
        if (!status) return CRM.alert('Choose an outcome first.', 'warning');

        const payload = { status: status };
        if ($('#outcome-notes').val()) payload.notes = $('#outcome-notes').val();
        if ($('#callback-at').val()) payload.callback_at = $('#callback-at').val();

        $.ajax({ url: '/api/v1/calls/' + currentCallId, method: 'PATCH', data: payload })
            .done(function () {
                outcomeSaved = true;
                CRM.alert('Outcome saved.', 'success');
            })
            .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); });
    });

    $('#btn-next').on('click', function () {
        // Outcomes are write-once, so an unsaved one is lost for good when the
        // run moves on. Worth one confirmation rather than a silent gap in
        // somebody's call history.
        if (currentCallId && !outcomeSaved
            && ! confirm('This call has no outcome recorded. Move on anyway?')) {
            return;
        }

        fetchNext();
    });

    $('#btn-pause').on('click', function () {
        $.post('/api/v1/dialer/sessions/' + session.id + '/pause')
            .done(function (response) { renderSession(response.data); })
            .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); });
    });

    $('#btn-resume').on('click', function () {
        $.post('/api/v1/dialer/sessions/' + session.id + '/resume')
            .done(function (response) { renderSession(response.data); })
            .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); });
    });

    $('#btn-stop, #btn-stop-finished').on('click', function () {
        $.post('/api/v1/dialer/sessions/' + session.id + '/stop')
            .done(function () {
                session = null;
                currentCallId = null;
                skips.length = 0;
                $('#skip-feed').html('<p class="text-muted small mb-0">Nothing skipped yet.</p>');
                renderSession(null);
            })
            .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); });
    });

    // A run open from an earlier page load is picked back up here — the queue
    // is a table, not a browser variable (FR-CALL-06).
    loadCurrent();
});
</script>
@endpush
