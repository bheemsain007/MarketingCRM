{{--
    Campaign builder (Phase 18) - FR-CAMP-01.

    The preview is the point of this screen. "12,000 leads" and "12,000 leads
    of whom 4,000 are suppressed" are different decisions, and after pressing
    send is too late to learn which one you were making.
--}}
@extends('layouts.app')
@section('title', 'New campaign')

@section('content')
    <h1 class="h5 mb-3">New campaign</h1>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label small mb-1" for="f-name">Name <span class="text-danger">*</span></label>
                        <input type="text" id="f-name" class="form-control form-control-sm" maxlength="190">
                        <div class="invalid-feedback"></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small mb-1" for="f-channel">Channel <span class="text-danger">*</span></label>
                        <select id="f-channel" class="form-select form-select-sm">
                            @foreach ($channels as $channel)
                                <option value="{{ $channel->value }}">{{ $channel->label() }}</option>
                            @endforeach
                        </select>
                        {{-- Calling is absent on purpose: it has consent,
                             calling-hours and single-assignment rules that a
                             bulk send knows nothing about (BR-CALL-02/03/04). --}}
                        <div class="form-text">Campaigns cannot dial. Use the auto dialer for calling.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small mb-1" for="f-scheduled">Send at</label>
                        <input type="datetime-local" id="f-scheduled" class="form-control form-control-sm">
                        <div class="form-text">Leave empty to start it by hand.</div>
                        <div class="invalid-feedback"></div>
                    </div>

                    <hr>

                    <h2 class="h6">Audience</h2>
                    <p class="text-muted small">
                        Leave everything empty to target every contactable lead. Suppressed leads are
                        excluded here and checked again for each message (BR-CAMP-02).
                    </p>

                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label small mb-1" for="f-status">Lead status</label>
                            <select id="f-status" class="form-select form-select-sm" multiple size="6">
                                @foreach ($leadStatuses as $status)
                                    <option value="{{ $status->value }}">{{ $status->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small mb-1" for="f-temperature">Temperature</label>
                            <select id="f-temperature" class="form-select form-select-sm" multiple size="6">
                                @foreach ($temperatures as $temperature)
                                    <option value="{{ $temperature->value }}">{{ $temperature->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                <div class="card-footer d-flex justify-content-between">
                    <a href="{{ route('web.campaigns') }}" class="btn btn-sm btn-outline-secondary">Cancel</a>
                    <div>
                        <button id="save-draft" class="btn btn-sm btn-outline-primary">Save as draft</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card">
                <div class="card-body">
                    <h2 class="h6">Who this reaches</h2>
                    <p class="text-muted small">
                        Save the draft first — the preview reads the saved audience, so what you see
                        is what the campaign would actually send to.
                    </p>
                    <div id="preview">
                        <p class="text-muted small mb-0">No draft saved yet.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
$(function () {
    let campaignId = null;

    function multi(selector) {
        const values = $(selector).val() || [];
        return values.length ? values : null;
    }

    function payload() {
        const filters = {};
        if (multi('#f-status')) filters.status = multi('#f-status');
        if (multi('#f-temperature')) filters.temperature = multi('#f-temperature');

        const body = {
            name: $('#f-name').val(),
            channel: $('#f-channel').val(),
            audience_filters: filters,
        };

        const scheduled = $('#f-scheduled').val();
        if (scheduled) body.scheduled_at = scheduled;

        return body;
    }

    $('#save-draft').on('click', function () {
        $('.is-invalid').removeClass('is-invalid');

        const method = campaignId ? 'PATCH' : 'POST';
        const url = campaignId ? '/api/v1/campaigns/' + campaignId : '/api/v1/campaigns';

        $.ajax({
            url: url, method: method, contentType: 'application/json',
            data: JSON.stringify(payload()),
        })
            .done(function (response) {
                campaignId = response.data.id;
                CRM.alert('Draft saved.', 'success');
                loadPreview();
            })
            .fail(function (xhr) {
                const failures = xhr.responseJSON && xhr.responseJSON.errors;
                if (failures) {
                    Object.keys(failures).forEach(function (field) {
                        const input = $('#f-' + field.replace('_at', '').replace('audience_filters.', ''));
                        input.addClass('is-invalid').siblings('.invalid-feedback').text(failures[field][0]);
                    });
                }
                CRM.alert(CRM.errorFrom(xhr));
            });
    });

    function loadPreview() {
        if (!campaignId) return;

        $.getJSON('/api/v1/campaigns/' + campaignId + '/preview')
            .done(function (response) {
                const p = response.data;

                $('#preview').html(''
                    + row('Leads matched', p.total, 'dark')
                    + row('Will be sent to', p.eligible, 'success')
                    + row('Suppressed', p.suppressed, p.suppressed > 0 ? 'warning' : 'muted')
                    + row('No address for this channel', p.no_contact_detail, p.no_contact_detail > 0 ? 'warning' : 'muted')
                    + '<a href="/campaigns/' + campaignId + '" class="btn btn-sm btn-primary w-100 mt-3">'
                    + 'Open campaign to start it</a>');
            })
            .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); });
    }

    function row(label, value, tone) {
        return '<div class="d-flex justify-content-between border-bottom py-2">'
            + '<span class="small">' + label + '</span>'
            + '<strong class="text-' + tone + '">' + value.toLocaleString() + '</strong></div>';
    }
});
</script>
@endpush
