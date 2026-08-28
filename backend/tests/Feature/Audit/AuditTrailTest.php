<?php

namespace Tests\Feature\Audit;

use App\Enums\DncReason;
use App\Enums\LeadStatus;
use App\Enums\PaymentStatus;
use App\Enums\RoleName;
use App\Enums\StatusSource;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\DncEntry;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\OpportunityProduct;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\Leads\LeadStatusService;
use App\Services\Payments\PaymentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The audit trail as a control rather than a table (SEC-AUD-02, SEC-AUD-04).
 *
 * Two halves, and the trail is worthless without either. Writing: SEC-AUD-02
 * names lead status changes, DNC add/remove and payment status changes among
 * the MINIMUM audited events, and none of the three wrote an entry - the only
 * automatic path is the permission middleware, which records the PERMISSION
 * exercised, so anything gated by an unaudited permission (or by no route at
 * all, as refunds are) left nothing behind. Reading: `audit.view` was granted
 * to Admin and Super Admin and read by no endpoint and no page anywhere.
 *
 * The last test here is the one that matters most: the reader must stay a
 * reader. An audit endpoint that can also write would undo SEC-AUD-01.
 */
class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        // The date-range filter below asks the API for "today", which is only a
        // stable question with the clock held still.
        Carbon::setTestNow('2026-08-26 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(RoleName $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', $role->value)->first());

        return $user->fresh();
    }

    private function actingAsRole(RoleName $role): User
    {
        $user = $this->user($role);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    /**
     * A real Paid payment with all four BR-PAY-03 parents, since
     * `PaymentFactory` deliberately has no defaults for them - the point being
     * tested is the audit write on transition, not the pipeline that gets a
     * payment to exist, so the shortest legal path is built directly rather
     * than through the sale API.
     */
    private function paidPayment(): Payment
    {
        $lead = Lead::factory()->create();
        $opportunity = Opportunity::factory()->for($lead)->create();
        $customer = Customer::create([
            'tenant_id' => 0,
            'origin_lead_id' => $lead->id,
            'name' => $lead->name,
            'phone_e164' => $lead->phone_e164,
        ]);
        $sale = Sale::create([
            'tenant_id' => 0,
            'opportunity_id' => $opportunity->id,
            'customer_id' => $customer->id,
            'lead_id' => $lead->id,
            'reference' => 'S-AUDIT-'.$opportunity->id,
            'amount' => 1000,
            'sold_at' => Carbon::now(),
        ]);

        return Payment::factory()->create([
            'sale_id' => $sale->id,
            'customer_id' => $customer->id,
            'lead_id' => $lead->id,
            'product_id' => Product::factory()->create()->id,
            'status' => PaymentStatus::Paid->value,
        ]);
    }

    /** A lead with an open opportunity carrying a product and a value (BR-SALE-01). */
    private function withProposableOpportunity(Lead $lead): Lead
    {
        $opportunity = Opportunity::factory()->create([
            'lead_id' => $lead->id,
            'value' => 5000,
        ]);

        OpportunityProduct::create([
            'opportunity_id' => $opportunity->id,
            'product_id' => Product::factory()->create()->id,
            'quantity' => 1,
            'unit_price' => 5000,
            'line_total' => 5000,
        ]);

        return $lead;
    }

    // -----------------------------------------------------------------------
    // Writing: the events SEC-AUD-02 names
    // -----------------------------------------------------------------------

    #[Test]
    public function a_lead_status_change_writes_an_audit_entry_naming_the_actor(): void
    {
        $actor = $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create(['status' => LeadStatus::New->value]);

        $this->patchJson("/api/v1/leads/{$lead->id}/status", [
            'status' => LeadStatus::Contacted->value,
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $actor->id,
            'action' => 'status_changed',
            'auditable_type' => Lead::class,
            'auditable_id' => $lead->id,
        ]);

        // The before/after is the part a compliance question actually uses.
        $entry = AuditLog::where('action', 'status_changed')->firstOrFail();

        $this->assertSame(['status' => 'new'], $entry->old_values);
        $this->assertSame('contacted', $entry->new_values['status']);
    }

    #[Test]
    public function a_system_status_change_is_audited_with_no_actor(): void
    {
        // A null actor is a fact about the event, not missing data: crediting
        // whoever happened to trigger it would corrupt attribution.
        $lead = Lead::factory()->create(['status' => LeadStatus::New->value]);

        app(LeadStatusService::class)->change(
            $lead,
            LeadStatus::Contacted,
            actor: null,
            source: StatusSource::System,
        );

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'status_changed',
            'auditable_id' => $lead->id,
            'user_id' => null,
        ]);
    }

    #[Test]
    public function adding_a_lead_to_the_do_not_contact_list_writes_an_audit_entry(): void
    {
        // The gap the middleware could never close: `dnc.create` is held by
        // every telecaller and is not an audited permission, so suppression was
        // being written with nothing recording who wrote it.
        $actor = $this->actingAsRole(RoleName::Manager);
        $lead = Lead::factory()->create();

        $this->postJson('/api/v1/dnc', [
            'lead_id' => $lead->id,
            'reason' => DncReason::DoNotContact->value,
        ])->assertStatus(201);

        $entry = DncEntry::where('lead_id', $lead->id)->firstOrFail();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $actor->id,
            'action' => 'dnc_added',
            'auditable_type' => DncEntry::class,
            'auditable_id' => $entry->id,
        ]);
    }

    #[Test]
    public function marking_a_lead_not_interested_audits_both_the_status_and_the_suppression(): void
    {
        // BR-DNC-07 writes suppression as a side effect of the status change.
        // Both events are in SEC-AUD-02's minimum list, and they are separate
        // facts - the trail has to show the suppression, not just the status.
        $actor = $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create(['status' => LeadStatus::New->value]);

        $this->patchJson("/api/v1/leads/{$lead->id}/status", [
            'status' => LeadStatus::NotInterested->value,
            'reason' => 'Asked not to be called again.',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $actor->id,
            'action' => 'status_changed',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $actor->id,
            'action' => 'dnc_added',
        ]);
    }

    #[Test]
    public function lifting_a_suppression_records_which_entry_was_lifted(): void
    {
        /*
         * `dnc.remove` IS an audited permission, so the middleware already
         * wrote a row - but that row names the permission and the path, not
         * which person stopped being protected. Only the second entry can
         * answer a compliance question.
         */
        $actor = $this->actingAsRole(RoleName::Manager);
        $entry = DncEntry::factory()->create(['lead_id' => Lead::factory()]);

        $this->deleteJson("/api/v1/dnc/{$entry->id}", [
            'reason' => 'They called back and asked to be contacted again.',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $actor->id,
            'action' => 'dnc_removed',
            'auditable_type' => DncEntry::class,
            'auditable_id' => $entry->id,
        ]);
    }

    // -----------------------------------------------------------------------
    // Reading: GET /api/v1/audit-logs
    // -----------------------------------------------------------------------

    #[Test]
    public function an_admin_can_read_the_audit_trail(): void
    {
        $this->actingAsRole(RoleName::Admin);
        AuditLog::create(['action' => 'login', 'description' => 'Signed in.']);

        $this->getJson('/api/v1/audit-logs')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.items.0.action', 'login')
            ->assertJsonPath('data.meta.total', 1);
    }

    #[Test]
    public function a_role_without_audit_view_is_refused(): void
    {
        // Manager holds nearly everything operational and still may not read
        // the trail: it records what managers did (SEC-AUD-04).
        $this->actingAsRole(RoleName::Manager);
        AuditLog::create(['action' => 'login']);

        $this->getJson('/api/v1/audit-logs')->assertForbidden();
    }

    #[Test]
    public function a_telecaller_is_refused(): void
    {
        $this->actingAsRole(RoleName::Telecaller);

        $this->getJson('/api/v1/audit-logs')->assertForbidden();
    }

    #[Test]
    public function the_trail_can_be_narrowed_to_one_actor_one_action_and_one_subject(): void
    {
        $reader = $this->actingAsRole(RoleName::Admin);
        $other = $this->user(RoleName::Manager);

        AuditLog::create([
            'user_id' => $reader->id,
            'action' => 'status_changed',
            'auditable_type' => Lead::class,
            'auditable_id' => 7,
        ]);
        AuditLog::create(['user_id' => $other->id, 'action' => 'status_changed']);
        AuditLog::create(['user_id' => $reader->id, 'action' => 'login']);

        $this->getJson('/api/v1/audit-logs?filter[user_id]='.$reader->id.'&filter[action]=status_changed')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.actor.name', $reader->name);

        $this->getJson('/api/v1/audit-logs?filter[auditable_type]='.urlencode(Lead::class).'&filter[auditable_id]=7')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1);
    }

    #[Test]
    public function the_trail_can_be_narrowed_to_a_date_range(): void
    {
        $this->actingAsRole(RoleName::Admin);

        AuditLog::create(['action' => 'login', 'created_at' => Carbon::parse('2026-08-20 09:00:00')]);
        AuditLog::create(['action' => 'logout', 'created_at' => Carbon::now()]);

        $this->getJson('/api/v1/audit-logs?filter[created_at][between]='
                .urlencode('2026-08-26 00:00:00,2026-08-26 23:59:59'))
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.action', 'logout');
    }

    #[Test]
    public function an_unknown_filter_field_is_refused_rather_than_ignored(): void
    {
        // A silently dropped filter on an audit search shows the reader more
        // than they asked for and hides the fact.
        $this->actingAsRole(RoleName::Admin);

        $this->getJson('/api/v1/audit-logs?filter[password]=x')->assertStatus(422);
    }

    #[Test]
    public function the_audit_endpoint_cannot_be_used_to_change_anything(): void
    {
        /*
         * The point of the whole feature. `audit_logs` is append-only
         * (SEC-AUD-01) and a read endpoint is the only thing that may be built
         * on top of it - so the route carries one verb and there is no write
         * path to reach, whatever the caller's role.
         */
        $this->actingAsRole(RoleName::SuperAdmin);
        $entry = AuditLog::create(['action' => 'login', 'description' => 'Signed in.']);

        $this->postJson('/api/v1/audit-logs', ['action' => 'noop'])->assertStatus(405);
        $this->patchJson('/api/v1/audit-logs', ['action' => 'noop'])->assertStatus(405);
        $this->deleteJson('/api/v1/audit-logs')->assertStatus(405);

        // Per-entry paths do not exist at all.
        $this->patchJson("/api/v1/audit-logs/{$entry->id}", ['action' => 'noop'])->assertStatus(404);
        $this->deleteJson("/api/v1/audit-logs/{$entry->id}")->assertStatus(404);

        $this->assertDatabaseHas('audit_logs', ['id' => $entry->id, 'action' => 'login']);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    // -----------------------------------------------------------------------
    // BR-SALE-01 - Proposal requires an opportunity
    // -----------------------------------------------------------------------

    #[Test]
    public function a_lead_cannot_reach_proposal_without_an_opportunity(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create(['status' => LeadStatus::Interested->value]);

        $this->patchJson("/api/v1/leads/{$lead->id}/status", [
            'status' => LeadStatus::Proposal->value,
        ])->assertStatus(422);

        $this->assertSame(LeadStatus::Interested, $lead->fresh()->status);
    }

    #[Test]
    public function an_opportunity_with_no_value_does_not_satisfy_the_rule(): void
    {
        // BR-SALE-01 asks for products AND a value. An opportunity opened as a
        // placeholder is not a costed proposal.
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create(['status' => LeadStatus::Interested->value]);
        Opportunity::factory()->create(['lead_id' => $lead->id, 'value' => 0]);

        $this->patchJson("/api/v1/leads/{$lead->id}/status", [
            'status' => LeadStatus::Proposal->value,
        ])->assertStatus(422);
    }

    #[Test]
    public function a_lead_with_a_costed_opportunity_reaches_proposal(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = $this->withProposableOpportunity(
            Lead::factory()->create(['status' => LeadStatus::Interested->value]),
        );

        $this->patchJson("/api/v1/leads/{$lead->id}/status", [
            'status' => LeadStatus::Proposal->value,
        ])->assertOk();

        $this->assertSame(LeadStatus::Proposal, $lead->fresh()->status);
    }

    #[Test]
    public function proposal_is_not_offered_as_an_available_transition_without_an_opportunity(): void
    {
        // The screen must not draw a button whose only outcome is a 422.
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create(['status' => LeadStatus::Interested->value]);

        $this->getJson("/api/v1/leads/{$lead->id}/transitions")
            ->assertOk()
            ->assertJsonMissing(['status' => LeadStatus::Proposal->value]);
    }

    // -----------------------------------------------------------------------
    // Payments - the refund gap
    // -----------------------------------------------------------------------

    #[Test]
    public function a_payment_status_change_is_audited_even_though_no_route_carries_the_refund_permission(): void
    {
        /*
         * `Permission::PaymentsRefund` sits in `isAudited()`, which looks like
         * coverage and is not: that list is read only by the route-level
         * permission middleware, and no route passes `payments.refund` -
         * PaymentController checks it in its own body because one endpoint
         * serves every transition. So the audited permission never fired.
         */
        $payment = $this->paidPayment();
        $actor = $this->user(RoleName::Accounts);

        app(PaymentService::class)->transition(
            $payment,
            PaymentStatus::Refund,
            $actor->id,
            'Customer cancelled after paying.',
        );

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $actor->id,
            'action' => 'payment_status_changed',
            'auditable_type' => Payment::class,
            'auditable_id' => $payment->id,
        ]);
    }
}
