{{--
    Lead create / edit form (T-46, FR-LEAD-01).

    One view serves both, because the fields are the same ones and a second copy
    would drift. What differs is the API call and which sections are offered:

      Create - POST /api/v1/leads, and the request accepts product interest,
               tags and an opening note alongside the lead itself.
      Edit   - PATCH /api/v1/leads/{id}, which deliberately accepts NEITHER
               those nor status, temperature, score, suppression or ownership.
               Each of those moves through its own endpoint (SEC-IN-06), so
               offering them here would be offering a control that 422s.

    Like every other page in the Web CRM this is a shell: the write goes to the
    same API the Flutter app will use, so duplicate detection, phone
    normalisation and the timeline entry cannot be skipped by the browser path
    (ADR-A).
--}}
@extends('layouts.app')
@section('title', $lead ? 'Edit ' . $lead->name : 'New lead')

@section('content')
<div class="row">
    <div class="col-xl-9">
        {{-- Shown instead of the form when a creator cannot see their own new
             lead - see the redirect note in the script below. --}}
        <div id="created-panel" class="card d-none">
            <div class="card-body text-center py-5">
                <i class="bi bi-check-circle text-success" style="font-size:2rem"></i>
                <h2 class="h5 mt-3">Lead created</h2>
                <p class="text-muted small mb-4">
                    It has gone into the unassigned pool. You will see it in your list once a
                    manager assigns it to you.
                </p>
                <a href="{{ route('web.leads.create') }}" class="btn btn-sm btn-primary me-2">Add another</a>
                <a href="{{ route('web.leads') }}" class="btn btn-sm btn-outline-secondary">Back to leads</a>
            </div>
        </div>

        <form id="lead-form" novalidate>
            <div class="card mb-3">
                <div class="card-header py-2"><span class="small fw-semibold">Contact</span></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small mb-1" for="f-name">Name <span class="text-danger">*</span></label>
                            <input type="text" id="f-name" class="form-control form-control-sm" maxlength="150"
                                   value="{{ $lead?->name }}" required>
                            <div class="invalid-feedback"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small mb-1" for="f-company">Company</label>
                            <input type="text" id="f-company" class="form-control form-control-sm" maxlength="150"
                                   value="{{ $lead?->company }}">
                            <div class="invalid-feedback"></div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small mb-1" for="f-phone">Phone <span class="text-danger">*</span></label>
                            <input type="tel" id="f-phone" class="form-control form-control-sm" maxlength="30"
                                   value="{{ $lead?->phone_e164 }}" required>
                            {{-- The server normalises to E.164 and rejects what it
                                 cannot parse; this hint just stops the obvious
                                 mistakes before a round trip. --}}
                            <div class="form-text small">10-digit mobile, or full international form.</div>
                            <div class="invalid-feedback"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small mb-1" for="f-alt_phone">Alternate phone</label>
                            <input type="tel" id="f-alt_phone" class="form-control form-control-sm" maxlength="30"
                                   value="{{ $lead?->alt_phone_e164 }}">
                            <div class="invalid-feedback"></div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small mb-1" for="f-email">Email</label>
                            <input type="email" id="f-email" class="form-control form-control-sm" maxlength="190"
                                   value="{{ $lead?->email }}">
                            <div class="invalid-feedback"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small mb-1" for="f-lead_source_id">Source</label>
                            <select id="f-lead_source_id" class="form-select form-select-sm">
                                <option value="">—</option>
                                @foreach ($sources as $source)
                                    <option value="{{ $source->id }}" @selected($lead?->lead_source_id === $source->id)>
                                        {{ $source->name }}
                                    </option>
                                @endforeach
                            </select>
                            <div class="invalid-feedback"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header py-2"><span class="small fw-semibold">Location</span></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label small mb-1" for="f-city">City</label>
                            <input type="text" id="f-city" class="form-control form-control-sm" maxlength="100"
                                   value="{{ $lead?->city }}">
                            <div class="invalid-feedback"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small mb-1" for="f-state">State</label>
                            <input type="text" id="f-state" class="form-control form-control-sm" maxlength="100"
                                   value="{{ $lead?->state }}">
                            <div class="invalid-feedback"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small mb-1" for="f-country">Country</label>
                            <input type="text" id="f-country" class="form-control form-control-sm" maxlength="100"
                                   value="{{ $lead?->country }}">
                            <div class="invalid-feedback"></div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small mb-1" for="f-timezone">Timezone</label>
                            <select id="f-timezone" class="form-select form-select-sm">
                                <option value="">Organisation default ({{ $defaultTimezone }})</option>
                                @foreach ($timezones as $tz)
                                    <option value="{{ $tz }}" @selected($lead?->timezone === $tz)>{{ $tz }}</option>
                                @endforeach
                            </select>
                            {{-- Not cosmetic: calling hours are enforced in the
                                 LEAD's timezone (BR-CALL-04), so a wrong value
                                 here blocks or permits dialling at the wrong
                                 time of day. --}}
                            <div class="form-text small">Calling hours are enforced in the lead's local time.</div>
                            <div class="invalid-feedback"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small mb-1" for="f-priority">Priority</label>
                            <input type="number" id="f-priority" class="form-control form-control-sm" min="0" max="255"
                                   value="{{ $lead?->priority }}">
                            <div class="form-text small">Higher dials sooner. Leave blank for the default.</div>
                            <div class="invalid-feedback"></div>
                        </div>
                    </div>
                </div>
            </div>

            @unless ($lead)
                {{-- Create only. On an existing lead these live on the detail
                     page, where per-product interest carries its own state and
                     notes are append-only. --}}
                <div class="card mb-3">
                    <div class="card-header py-2"><span class="small fw-semibold">Interest</span></div>
                    <div class="card-body">
                        <label class="form-label small mb-1">Products</label>
                        @if ($products->isEmpty())
                            <div class="text-muted small mb-3">No active products.</div>
                        @else
                            <div class="row g-1 mb-3">
                                @foreach ($products as $product)
                                    <div class="col-md-4">
                                        <div class="form-check">
                                            <input class="form-check-input f-product" type="checkbox"
                                                   value="{{ $product->id }}" id="p-{{ $product->id }}">
                                            <label class="form-check-label small" for="p-{{ $product->id }}">
                                                {{ $product->name }}
                                            </label>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @if ($tags->isNotEmpty())
                            <label class="form-label small mb-1">Tags</label>
                            <div class="row g-1 mb-3">
                                @foreach ($tags as $tag)
                                    <div class="col-md-4">
                                        <div class="form-check">
                                            <input class="form-check-input f-tag" type="checkbox"
                                                   value="{{ $tag->id }}" id="t-{{ $tag->id }}">
                                            <label class="form-check-label small" for="t-{{ $tag->id }}">{{ $tag->name }}</label>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        <label class="form-label small mb-1" for="f-note">Opening note</label>
                        <textarea id="f-note" class="form-control form-control-sm" rows="3" maxlength="5000"
                                  placeholder="Where this lead came from, what they asked for…"></textarea>
                        <div class="invalid-feedback"></div>
                    </div>
                </div>
            @endunless

            <div class="d-flex gap-2">
                <button type="submit" id="save" class="btn btn-sm btn-primary">
                    {{ $lead ? 'Save changes' : 'Create lead' }}
                </button>
                <a class="btn btn-sm btn-outline-secondary"
                   href="{{ $lead ? route('web.leads.show', $lead) : route('web.leads') }}">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    const leadId = @json($lead?->id);
    const isEdit = leadId !== null;
    const viewableAfterCreate = @json($viewableAfterCreate);

    /*
     * How a blank field is sent depends on the column behind it, because
     * "left empty" and "cleared" are different intentions and only some
     * columns can express the second.
     */
    const REQUIRED = ['name', 'phone'];

    // Nullable columns: blank means "remove this", sent as an explicit null.
    const CLEARABLE = ['company', 'alt_phone', 'email', 'city', 'state', 'timezone', 'lead_source_id'];

    // NOT NULL with a database default (country 'India', priority 0). A null
    // here is a SQL error, so blank means "leave it alone" instead.
    const OMIT_IF_BLANK = ['country', 'priority'];

    function clearErrors() {
        $('#lead-form .is-invalid').removeClass('is-invalid');
        $('#lead-form .invalid-feedback').text('');
        $('#crm-alert').addClass('d-none');
    }

    /**
     * Paints the standard error envelope onto the form.
     *
     * Anything with a `field` we recognise lands under that input; everything
     * else goes to the page banner, so a rule violation is never swallowed just
     * because it has no field to attach to.
     */
    function showErrors(xhr) {
        const body = xhr.responseJSON || {};
        const errors = body.errors || [];
        let unattached = [];

        // A duplicate is not a typo - the person already exists and somebody may
        // already own the relationship (BR-DUP-02, BR-ASSIGN-05). Point at them
        // rather than just refusing. Handled first and alone, so it does not
        // also appear in the page banner as an unattached error.
        if (errors.length && errors[0].code === 'lead.duplicate') {
            const existing = body.data || {};
            let text = body.message;
            if (existing.existing_lead_name) {
                text += ' It is ' + existing.existing_lead_name + '.';
            }

            $('#f-phone').addClass('is-invalid')
                .closest('div').find('.invalid-feedback').html(
                    CRM.escape(text)
                    + (existing.existing_lead_id && !existing.archived
                        ? ' <a href="/leads/' + existing.existing_lead_id + '">Open that lead</a>'
                        : '')
                );

            return;
        }

        errors.forEach(function (error) {
            const input = error.field ? $('#f-' + error.field) : $();
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

    function value(field) {
        return $.trim(String($('#f-' + field).val() ?? ''));
    }

    function payload() {
        const data = {};

        REQUIRED.forEach(function (field) {
            data[field] = value(field);
        });

        CLEARABLE.forEach(function (field) {
            const current = value(field);
            // On create there is nothing to clear, so a blank is simply absent
            // and the column keeps its default.
            if (current !== '') data[field] = current;
            else if (isEdit) data[field] = null;
        });

        OMIT_IF_BLANK.forEach(function (field) {
            const current = value(field);
            if (current !== '') data[field] = current;
        });

        if (!isEdit) {
            const products = $('.f-product:checked').map(function () { return Number(this.value); }).get();
            if (products.length) data.product_ids = products;

            const tags = $('.f-tag:checked').map(function () { return Number(this.value); }).get();
            if (tags.length) data.tag_ids = tags;

            const note = $.trim($('#f-note').val());
            if (note !== '') data.note = note;
        }

        return data;
    }

    $('#lead-form').on('submit', function (event) {
        event.preventDefault();
        clearErrors();
        $('#save').prop('disabled', true);

        $.ajax({
            url: isEdit ? '/api/v1/leads/' + leadId : '/api/v1/leads',
            method: isEdit ? 'PATCH' : 'POST',
            contentType: 'application/json',
            data: JSON.stringify(payload())
        })
            .done(function (response) {
                if (isEdit) {
                    window.location = '/leads/' + leadId;
                    return;
                }

                // A manually created lead is unassigned, and a telecaller is
                // scoped to their own leads - so sending the creator to the
                // detail page would land them on a 403 for the record they just
                // made. Whether manual creation should self-assign is T-47; until
                // that is decided, say what happened instead of redirecting.
                if (viewableAfterCreate) {
                    window.location = '/leads/' + response.data.id;
                } else {
                    $('#lead-form').addClass('d-none');
                    $('#created-panel').removeClass('d-none');
                }
            })
            .fail(function (xhr) {
                showErrors(xhr);
                $('#save').prop('disabled', false);
            });
    });
});
</script>
@endpush
