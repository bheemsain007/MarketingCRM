<?php

namespace App\Services\Sales;

use App\Enums\ErrorCode;
use App\Enums\OpportunityStatus;
use App\Exceptions\ApiException;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\Sale;
use App\Services\Leads\LeadService;
use Illuminate\Support\Facades\DB;

/**
 * Recording a sale (FR-SALE-01, BR-CUST-01..04).
 *
 * This is the method that creates a Customer, and it is the only one. Everything
 * about conversion hangs off it: the lead survives and reaches `Converted`, the
 * customer links back through `origin_lead_id`, and the opportunity closes as
 * won.
 */
class SaleService
{
    public function __construct(private readonly LeadService $leads) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function record(Opportunity $opportunity, array $data, ?int $actorId = null): Sale
    {
        if ($opportunity->status->isClosed()) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                sprintf('This opportunity is already %s.', $opportunity->status->label()),
            );
        }

        $lead = $opportunity->lead;

        if ($lead === null) {
            throw new ApiException(ErrorCode::ValidationFailed, 'This opportunity has no lead.');
        }

        $amount = (float) ($data['amount'] ?? $opportunity->value);

        if ($amount <= 0) {
            // A zero-value sale is either a mistake or a giveaway being logged
            // as revenue. Both are worth stopping.
            throw new ApiException(ErrorCode::ValidationFailed, 'A sale must have a value above zero.');
        }

        return DB::transaction(function () use ($opportunity, $lead, $data, $amount, $actorId) {
            $customer = $this->resolveCustomer($lead, $opportunity, $actorId);

            $sale = Sale::create([
                'tenant_id' => config('crm.default_tenant_id'),
                'opportunity_id' => $opportunity->id,
                'customer_id' => $customer->id,
                // Kept alongside customer_id: the lead survives conversion
                // (BR-CUST-01) and attribution reads from it.
                'lead_id' => $lead->id,
                'quotation_id' => $data['quotation_id'] ?? null,
                'reference' => $this->nextReference(),
                'amount' => $amount,
                'currency' => $data['currency'] ?? $opportunity->currency,
                // Credit is fixed at the moment of sale. Reassigning the lead
                // afterwards must not move who earned it (T-24).
                'sold_by' => $data['sold_by'] ?? $lead->assigned_to ?? $actorId,
                'sold_at' => $data['sold_at'] ?? now(),
                'notes' => $data['notes'] ?? null,
                'created_by' => $actorId,
            ]);

            $opportunity->update([
                'status' => OpportunityStatus::Won->value,
                'customer_id' => $customer->id,
                'closed_at' => now(),
            ]);

            $this->leads->recordActivity(
                $lead,
                $actorId,
                'sale_recorded',
                'Sale recorded: '.$sale->reference,
                [
                    'sale_id' => $sale->id,
                    'opportunity_id' => $opportunity->id,
                    'customer_id' => $customer->id,
                    'amount' => (string) $sale->amount,
                ],
            );

            return $sale->fresh(['customer']);
        });
    }

    /** Whether this lead has a sale - what unblocks `Converted` (BR-STAT-05). */
    public function leadHasSale(Lead $lead): bool
    {
        return Sale::where('lead_id', $lead->id)->exists();
    }

    /**
     * Finds or creates the Customer (BR-CUST-01/03/04).
     *
     * The lead is NOT converted in place. It survives with its own history, and
     * the customer links back through `origin_lead_id` - which is what makes
     * "where did this customer come from?" answerable a year later.
     */
    private function resolveCustomer(Lead $lead, Opportunity $opportunity, ?int $actorId): Customer
    {
        // Already linked - repeat business under an existing customer
        // (BR-CUST-03).
        if ($opportunity->customer_id !== null) {
            return Customer::findOrFail($opportunity->customer_id);
        }

        // BR-CUST-04: deduplicated on phone, then email, before creation.
        // Without this, a second deal with the same person creates a second
        // customer and the account's history splits in two.
        $existing = Customer::query()
            ->where('phone_e164', $lead->phone_e164)
            ->when($lead->email, fn ($q) => $q->orWhere('email', $lead->email))
            ->first();

        if ($existing !== null) {
            $this->linkLead($existing, $lead);

            return $existing;
        }

        $customer = Customer::create([
            'tenant_id' => config('crm.default_tenant_id'),
            'origin_lead_id' => $lead->id,
            'name' => $lead->name,
            'company' => $lead->company,
            'phone_e164' => $lead->phone_e164,
            'email' => $lead->email,
            'city' => $lead->city,
            'state' => $lead->state,
            'country' => $lead->country ?? 'India',
            // Billing identity (BR-CUST-02) belongs to the customer and is
            // filled in when invoicing needs it - not guessed from the lead.
            'status' => 'active',
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);

        $this->linkLead($customer, $lead);

        return $customer;
    }

    private function linkLead(Customer $customer, Lead $lead): void
    {
        DB::table('customer_leads')->updateOrInsert(
            ['customer_id' => $customer->id, 'lead_id' => $lead->id],
            ['created_at' => now(), 'updated_at' => now()],
        );
    }

    private function nextReference(): string
    {
        $prefix = 'S-'.now()->format('Y').'-';

        $last = Sale::withTrashed()
            ->where('tenant_id', config('crm.default_tenant_id'))
            ->where('reference', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('reference');

        $next = $last !== null ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
