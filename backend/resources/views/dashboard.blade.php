@extends('layouts.app')
@section('title', 'Dashboard')

@section('content')
    {{--
        Every figure here is scoped to the viewer: a telecaller's "total" is
        their own book, not the company's. Counting outside the caller's scope
        would leak the shape of the database (SEC-AUTHZ-03).
    --}}

    {{--
        "What do I need to do today?" - the question people actually open a CRM
        to ask. Each tile links straight to the screen that answers it, because
        a number you cannot act on is decoration.
    --}}
    <div class="card mb-4">
        <div class="card-body py-3">
            <div class="d-flex flex-wrap align-items-center gap-4">
                <div class="me-auto">
                    <div class="small text-muted">Your work</div>
                    <div class="fw-semibold">{{ auth()->user()->name }}</div>
                </div>

                @if ($work['follow_ups_today'] !== null)
                    <a href="{{ route('web.leads') }}" class="text-decoration-none text-body text-center">
                        <div class="h4 mb-0 {{ $work['follow_ups_today'] > 0 ? 'text-primary' : 'text-muted' }}">{{ number_format($work['follow_ups_today']) }}</div>
                        <div class="small text-muted">Follow-ups due</div>
                    </a>

                    <div class="text-center">
                        {{-- Missed is late, not void: it still needs doing, so it
                             sits next to today's work rather than in a report. --}}
                        <div class="h4 mb-0 {{ $work['follow_ups_missed'] > 0 ? 'text-warning' : 'text-muted' }}">{{ number_format($work['follow_ups_missed']) }}</div>
                        <div class="small text-muted">Missed</div>
                    </div>
                @endif

                <div class="text-center">
                    <div class="h4 mb-0 {{ $work['hot_leads'] > 0 ? 'text-danger' : 'text-muted' }}">{{ number_format($work['hot_leads']) }}</div>
                    <div class="small text-muted">Hot leads</div>
                </div>

                @isset($work['unassigned'])
                    <a href="{{ route('web.assignments') }}" class="text-decoration-none text-body text-center">
                        <div class="h4 mb-0 {{ $work['unassigned'] > 0 ? 'text-warning' : 'text-muted' }}">{{ number_format($work['unassigned']) }}</div>
                        <div class="small text-muted">Awaiting an owner</div>
                    </a>
                @endisset

                @isset($work['payments_overdue'])
                    <div class="text-center">
                        <div class="h4 mb-0 {{ $work['payments_overdue'] > 0 ? 'text-danger' : 'text-muted' }}">{{ number_format($work['payments_overdue']) }}</div>
                        <div class="small text-muted">Payments overdue</div>
                    </div>
                @endisset

                <div class="text-center">
                    <div class="h4 mb-0 {{ $work['unread_notifications'] > 0 ? 'text-primary' : 'text-muted' }}">{{ number_format($work['unread_notifications']) }}</div>
                    <div class="small text-muted">Unread</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        @foreach ([
            ['label' => 'Leads',      'value' => $counts['total'],     'tone' => 'dark'],
            ['label' => 'New',        'value' => $counts['new'],       'tone' => 'secondary'],
            ['label' => 'In progress','value' => $counts['working'],   'tone' => 'primary'],
            ['label' => 'Converted',  'value' => $counts['converted'], 'tone' => 'success'],
            ['label' => 'Closed',     'value' => $counts['closed'],    'tone' => 'danger'],
        ] as $card)
            <div class="col-6 col-md">
                <div class="card stat-card h-100">
                    <div class="card-body py-3">
                        <div class="text-muted small">{{ $card['label'] }}</div>
                        <div class="h3 text-{{ $card['tone'] }}">{{ number_format($card['value']) }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{--
        The money picture (FR-RPT-02, GLOSSARY section 2.5).

        Present only for `reports.business` holders - the controller passes null
        to everyone else. Unlike every other number on this page these figures
        are organisation-wide rather than scoped to the viewer, which is exactly
        why they are gated: a telecaller must not read company revenue off their
        landing page (SEC-AUTHZ-03, FR-RPT-03).
    --}}
    @isset($revenue)
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h2 class="h6 mb-0">Revenue this month</h2>
            <span class="small text-muted">
                {{ $revenue['period']['from'] }} to {{ $revenue['period']['to'] }}
                ({{ $revenue['period']['timezone'] }})
                &middot; <a href="{{ route('web.reports') }}">Full report</a>
            </span>
        </div>

        <div class="row g-3" id="revenue-tiles">
            @foreach ([
                ['label' => 'Collected', 'value' => $revenue['figures']['collected'], 'tone' => 'success',
                 'note' => 'Payments received this month'],
                ['label' => 'Booked',    'value' => $revenue['figures']['booked'],    'tone' => 'primary',
                 'note' => 'Deals signed this month'],
                ['label' => 'Outstanding', 'value' => $revenue['figures']['outstanding'], 'tone' => 'warning',
                 'note' => 'Owed right now'],
                ['label' => 'Overdue',   'value' => $revenue['figures']['overdue'],   'tone' => 'danger',
                 'note' => 'Past due right now'],
            ] as $tile)
                <div class="col-6 col-lg-3">
                    <div class="card stat-card h-100">
                        <div class="card-body py-3">
                            <div class="text-muted small">{{ $tile['label'] }}</div>
                            <div class="h3 text-{{ $tile['tone'] }}">₹{{ number_format((float) $tile['value']) }}</div>
                            <div class="text-muted small">{{ $tile['note'] }}</div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Said out loud, because summing booked and collected is the classic
             mistake and the tiles put them side by side. The two right-hand
             tiles are snapshots, not period figures - a debt does not belong to
             a month. --}}
        <p class="text-muted small mt-2 mb-4">
            Booked and collected are separate figures and are never added together.
            Outstanding and overdue are what is owed right now, not this month's totals.
        </p>
    @endisset

    <div class="row g-3">
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h6">Needs attention</h2>

                    <div class="d-flex justify-content-between border-bottom py-2">
                        <span class="small">Never contacted</span>
                        <strong class="{{ $untouched > 0 ? 'text-warning' : '' }}">{{ number_format($untouched) }}</strong>
                    </div>
                    {{-- The leakage metric (GLOSSARY §2.9): leads paid for and never worked. --}}
                    <p class="text-muted small mt-1 mb-3">
                        Leads with no contact attempt at all — the ones already paid for and not yet worked.
                    </p>

                    <div class="d-flex justify-content-between border-bottom py-2">
                        <span class="small">Suppressed</span>
                        <strong>{{ number_format($suppressed) }}</strong>
                    </div>
                    <p class="text-muted small mt-1 mb-0">
                        On the do-not-contact list. Excluded from every outbound channel.
                    </p>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h2 class="h6 mb-0">Latest leads</h2>
                        @permission('leads.view')
                            <a href="{{ route('web.leads') }}" class="small">View all</a>
                        @endpermission
                    </div>

                    @if ($recent->isEmpty())
                        <p class="text-muted small mb-0">No leads yet.</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                <tr class="text-muted small">
                                    <th>Name</th><th>Phone</th><th>Status</th><th>Owner</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach ($recent as $lead)
                                    <tr>
                                        <td>
                                            <a href="{{ route('web.leads.show', $lead) }}">{{ $lead->name }}</a>
                                        </td>
                                        <td class="small text-muted">{{ $lead->phone_e164 }}</td>
                                        <td>
                                            <span class="badge badge-status text-bg-secondary">
                                                {{ $lead->status->label() }}
                                            </span>
                                        </td>
                                        <td class="small text-muted">
                                            {{ $lead->assignedUser?->name ?? 'Unassigned' }}
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <p class="text-muted small mt-4 mb-0">
        Lead figures are direct counts, scoped to you. The revenue row comes from the same reporting service
        the reports screen reads, so the two cannot disagree about a figure. Conversion rates, talk time and
        attribution live on the reports screen, where every metric has exactly one agreed formula.
    </p>
@endsection
