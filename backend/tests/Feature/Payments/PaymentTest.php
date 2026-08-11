<?php

namespace Tests\Feature\Payments;

use App\Enums\LeadStatus;
use App\Enums\PaymentStatus;
use App\Enums\RoleName;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\Payments\PaymentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Payments (Phase 23, FR-PAY-01..05, BR-PAY-01..06).
 *
 * Includes the mandatory payment-status suite (TESTING section 4.3): the full
 * transition matrix, orphan rejection, partials summing, a derived balance, the
 * `Converted` precondition, and overdue being scheduler-owned.
 */
class PaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
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

    /** A sale of the given value, through the real pipeline. */
    private function saleWorth(float $amount, ?Lead $lead = null, int $productCount = 1): Sale
    {
        $lead ??= Lead::factory()->create(['status' => LeadStatus::Negotiation->value]);

        $products = [];
        for ($i = 0; $i < $productCount; $i++) {
            $products[] = ['product_id' => Product::factory()->create([
                'base_price' => $amount / $productCount,
            ])->id];
        }

        $opportunityId = $this->postJson("/api/v1/leads/{$lead->id}/opportunities", [
            'title' => 'Deal',
            'products' => $products,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/opportunities/{$opportunityId}/sale")->assertCreated();

        return Sale::where('opportunity_id', $opportunityId)->firstOrFail();
    }

    // -----------------------------------------------------------------------
    // No orphan payments (BR-PAY-03, FR-PAY-03)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_payment_takes_its_links_from_the_sale_not_the_caller(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $sale = $this->saleWorth(1000);

        $this->postJson("/api/v1/sales/{$sale->id}/payments", [
            'amount' => 1000,
            // A request that could name its own customer is one that can attach
            // a payment to the wrong account.
            'customer_id' => 9999,
            'lead_id' => 9999,
        ])->assertCreated();

        $payment = Payment::firstOrFail();
        $this->assertSame($sale->customer_id, $payment->customer_id);
        $this->assertSame($sale->lead_id, $payment->lead_id);
        $this->assertNotNull($payment->product_id);
    }

    #[Test]
    public function a_multi_product_sale_requires_the_payment_to_name_a_product(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $sale = $this->saleWorth(2000, productCount: 2);

        // Revenue by product (FR-PAY-04) is exact rather than apportioned, so
        // an instalment against a bundle has to say which product it is for.
        $this->postJson("/api/v1/sales/{$sale->id}/payments", ['amount' => 500])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.field', 'product_id');

        $productId = $sale->opportunity->products()->first()->product_id;

        $this->postJson("/api/v1/sales/{$sale->id}/payments", [
            'amount' => 500,
            'product_id' => $productId,
        ])->assertCreated();
    }

    #[Test]
    public function a_payment_cannot_name_a_product_that_was_not_sold(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $sale = $this->saleWorth(1000);
        $unrelated = Product::factory()->create();

        // Otherwise revenue reports against something the customer never bought.
        $this->postJson("/api/v1/sales/{$sale->id}/payments", [
            'amount' => 100,
            'product_id' => $unrelated->id,
        ])->assertStatus(422);
    }

    #[Test]
    public function a_zero_payment_is_refused(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $sale = $this->saleWorth(1000);

        $this->postJson("/api/v1/sales/{$sale->id}/payments", ['amount' => 0])->assertStatus(422);
    }

    // -----------------------------------------------------------------------
    // Partials and the derived balance (BR-PAY-04, FR-PAY-05)
    // -----------------------------------------------------------------------

    #[Test]
    public function successive_partials_sum_and_the_balance_is_derived(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $sale = $this->saleWorth(1000);

        $first = $this->postJson("/api/v1/sales/{$sale->id}/payments", ['amount' => 400])
            ->assertCreated();
        $this->assertSame('partial', $first->json('data.status'));
        // assertEquals, not assertSame: a whole float serialises to a JSON int.
        $this->assertEquals(600, $first->json('data.balance'));

        $second = $this->postJson("/api/v1/sales/{$sale->id}/payments", ['amount' => 350])
            ->assertCreated();
        $this->assertEquals(250, $second->json('data.balance'));

        // Settling the remainder is Paid, not Partial.
        $third = $this->postJson("/api/v1/sales/{$sale->id}/payments", ['amount' => 250])
            ->assertCreated();
        $this->assertSame('paid', $third->json('data.status'));
        $this->assertEquals(0, $third->json('data.balance'));

        $this->assertSame(1000.0, app(PaymentService::class)->collectedFor($sale->fresh()));
    }

    #[Test]
    public function a_failed_payment_does_not_count_toward_the_balance(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $sale = $this->saleWorth(1000);

        // Future-dated so it opens as Pending. The matrix has no Partial ->
        // Failed edge, and rightly so: a partial payment is money that already
        // arrived, and money that arrived cannot later fail.
        $id = $this->postJson("/api/v1/sales/{$sale->id}/payments", [
            'amount' => 400,
            'due_on' => now()->addWeek()->toDateString(),
        ])->json('data.id');

        $this->patchJson("/api/v1/payments/{$id}", [
            'status' => 'failed',
            'reason' => 'Bounced cheque',
        ])->assertOk();

        // BR-PAY-04: non-failed, non-refunded only. A failed payment that
        // reduced the balance would show a debt as settled.
        $this->assertSame(1000.0, app(PaymentService::class)->balanceFor($sale->fresh()));
    }

    #[Test]
    public function a_refund_restores_the_balance(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $sale = $this->saleWorth(1000);
        $id = $this->postJson("/api/v1/sales/{$sale->id}/payments", ['amount' => 1000])->json('data.id');

        $this->assertSame(0.0, app(PaymentService::class)->balanceFor($sale->fresh()));

        $this->patchJson("/api/v1/payments/{$id}", [
            'status' => 'refund',
            'reason' => 'Customer cancelled',
        ])->assertOk();

        $this->assertSame(1000.0, app(PaymentService::class)->balanceFor($sale->fresh()));
    }

    #[Test]
    public function the_balance_is_never_a_stored_column(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $sale = $this->saleWorth(1000);
        $this->postJson("/api/v1/sales/{$sale->id}/payments", ['amount' => 400]);

        // A stored balance is a second source of truth that drifts the first
        // time two instalments land in the same second.
        $this->assertFalse(\Schema::hasColumn('payments', 'balance'));
        $this->assertFalse(\Schema::hasColumn('sales', 'balance'));
    }

    // -----------------------------------------------------------------------
    // The transition matrix (BR-PAY-02) - mandatory coverage
    // -----------------------------------------------------------------------

    #[Test]
    public function every_allowed_transition_is_accepted_and_every_other_is_refused(): void
    {
        $this->actingAsRole(RoleName::Admin);

        foreach (PaymentStatus::cases() as $from) {
            foreach (PaymentStatus::cases() as $to) {
                if ($from === $to && $from !== PaymentStatus::Partial) {
                    continue;   // Partial -> Partial is the instalment case.
                }

                $sale = $this->saleWorth(1000);
                $payment = Payment::factory()->for($sale)->create([
                    'customer_id' => $sale->customer_id,
                    'lead_id' => $sale->lead_id,
                    'product_id' => $sale->opportunity->products()->first()->product_id,
                    'status' => $from->value,
                ]);

                $allowed = $from->canTransitionTo($to);

                // Overdue and Refund have their own gates, tested separately.
                if (in_array($to, [PaymentStatus::Overdue, PaymentStatus::Refund], true)) {
                    continue;
                }

                $response = $this->patchJson("/api/v1/payments/{$payment->id}", ['status' => $to->value]);

                $response->assertStatus($allowed ? 200 : 422);
            }
        }
    }

    #[Test]
    public function refund_is_terminal(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $sale = $this->saleWorth(1000);
        $id = $this->postJson("/api/v1/sales/{$sale->id}/payments", ['amount' => 1000])->json('data.id');

        $this->patchJson("/api/v1/payments/{$id}", ['status' => 'refund', 'reason' => 'Cancelled'])->assertOk();

        foreach (['pending', 'partial', 'paid', 'failed'] as $target) {
            $this->patchJson("/api/v1/payments/{$id}", ['status' => $target])->assertStatus(422);
        }
    }

    #[Test]
    public function a_refund_requires_a_reason_and_the_refund_permission(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $sale = $this->saleWorth(1000);
        $id = $this->postJson("/api/v1/sales/{$sale->id}/payments", ['amount' => 1000])->json('data.id');

        // Money going back out is the highest-value action here.
        $this->patchJson("/api/v1/payments/{$id}", ['status' => 'refund'])->assertStatus(422);

        // Manager holds payments.view only - not manage or refund.
        $this->actingAsRole(RoleName::Manager);
        $this->patchJson("/api/v1/payments/{$id}", ['status' => 'refund', 'reason' => 'Nope'])
            ->assertStatus(403);
    }

    #[Test]
    public function every_status_change_is_recorded_append_only(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $sale = $this->saleWorth(1000);
        $id = $this->postJson("/api/v1/sales/{$sale->id}/payments", ['amount' => 400])->json('data.id');

        $this->patchJson("/api/v1/payments/{$id}", ['status' => 'paid'])->assertOk();

        // A disputed refund six months later is unanswerable if the only
        // record is the current value of a column.
        $this->assertSame(2, \DB::table('payment_status_history')->where('payment_id', $id)->count());
        $this->assertDatabaseHas('payment_status_history', [
            'payment_id' => $id,
            'from_status' => 'partial',
            'to_status' => 'paid',
        ]);
    }

    // -----------------------------------------------------------------------
    // Overdue is time-derived (BR-PAY-06)
    // -----------------------------------------------------------------------

    #[Test]
    public function overdue_cannot_be_set_by_hand(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $sale = $this->saleWorth(1000);
        $id = $this->postJson("/api/v1/sales/{$sale->id}/payments", [
            'amount' => 400,
        ])->json('data.id');

        // Allowing it would let someone backdate a collections report.
        $this->patchJson("/api/v1/payments/{$id}", ['status' => 'overdue'])->assertStatus(422);
    }

    #[Test]
    public function the_scheduler_flags_a_payment_past_its_due_date(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $sale = $this->saleWorth(1000);

        $id = $this->postJson("/api/v1/sales/{$sale->id}/payments", [
            'amount' => 400,
            'due_on' => now()->addDays(3)->toDateString(),
        ])->json('data.id');

        // Future-dated, so it is a commitment rather than a receipt.
        $this->assertSame(PaymentStatus::Pending, Payment::find($id)->status);

        $this->travel(5)->days();
        $this->artisan('crm:mark-overdue-payments')->assertSuccessful();

        $this->assertSame(PaymentStatus::Overdue, Payment::find($id)->fresh()->status);
        $this->assertDatabaseHas('payment_status_history', [
            'payment_id' => $id,
            'to_status' => 'overdue',
            'source' => 'system',
        ]);
    }

    #[Test]
    public function a_settled_payment_is_not_swept_as_overdue(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $sale = $this->saleWorth(1000);
        $id = $this->postJson("/api/v1/sales/{$sale->id}/payments", [
            'amount' => 1000,
            'due_on' => now()->subDays(10)->toDateString(),
        ])->json('data.id');

        $this->artisan('crm:mark-overdue-payments')->assertSuccessful();

        // Paid is not awaiting money, and the matrix forbids the move anyway.
        $this->assertSame(PaymentStatus::Paid, Payment::find($id)->fresh()->status);
    }

    #[Test]
    public function an_overdue_payment_notifies_whoever_made_the_sale(): void
    {
        $seller = $this->user(RoleName::Telecaller);
        $this->actingAsRole(RoleName::Admin);

        $lead = Lead::factory()->create(['assigned_to' => $seller->id]);
        $sale = $this->saleWorth(1000, $lead);

        $this->postJson("/api/v1/sales/{$sale->id}/payments", [
            'amount' => 400,
            'due_on' => now()->subDay()->toDateString(),
        ]);

        $this->artisan('crm:mark-overdue-payments')->assertSuccessful();

        // BR-NOTIF-02 lists payment overdue. An overdue payment nobody is told
        // about is one nobody chases.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $seller->id,
            'type' => 'payment_overdue',
        ]);
    }

    // -----------------------------------------------------------------------
    // Conversion requires money (BR-PAY-05) - closes T-57
    // -----------------------------------------------------------------------

    #[Test]
    public function a_sale_without_a_payment_no_longer_converts_a_lead(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create(['status' => LeadStatus::Negotiation->value]);
        $this->saleWorth(1000, $lead);

        // Phase 22 allowed this; Phase 23 closes it. A sale alone is a promise.
        $this->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => 'converted'])
            ->assertStatus(422)
            ->assertJsonPath('data.requires', 'payment');

        $this->assertSame(LeadStatus::Negotiation, $lead->fresh()->status);
    }

    #[Test]
    public function a_partial_payment_is_enough_to_convert(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create(['status' => LeadStatus::Negotiation->value]);
        $sale = $this->saleWorth(1000, $lead);

        $this->postJson("/api/v1/sales/{$sale->id}/payments", ['amount' => 100])->assertCreated();

        // BR-PAY-05 says Partial OR Paid - a deposit is a real commitment.
        $this->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => 'converted'])->assertOk();

        $this->assertSame(LeadStatus::Converted, $lead->fresh()->status);
    }

    #[Test]
    public function money_that_did_not_stay_received_does_not_qualify_for_conversion(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create(['status' => LeadStatus::Negotiation->value]);
        $sale = $this->saleWorth(1000, $lead);

        $id = $this->postJson("/api/v1/sales/{$sale->id}/payments", ['amount' => 1000])->json('data.id');

        // Refunded rather than failed: the matrix only allows Paid -> Refund,
        // because a payment that was received cannot retroactively fail.
        $this->patchJson("/api/v1/payments/{$id}", [
            'status' => 'refund',
            'reason' => 'Customer cancelled',
        ])->assertOk();

        // Neither Failed nor Refund counts as collected, so neither converts.
        $this->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => 'converted'])
            ->assertStatus(422);
    }

    #[Test]
    public function a_pending_instalment_alone_does_not_convert(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create(['status' => LeadStatus::Negotiation->value]);
        $sale = $this->saleWorth(1000, $lead);

        // Scheduled, not received. BR-PAY-05 wants Partial or Paid.
        $this->postJson("/api/v1/sales/{$sale->id}/payments", [
            'amount' => 500,
            'due_on' => now()->addMonth()->toDateString(),
        ])->assertCreated();

        $this->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => 'converted'])
            ->assertStatus(422)
            ->assertJsonPath('data.requires', 'payment');
    }

    // -----------------------------------------------------------------------
    // Scope and permissions
    // -----------------------------------------------------------------------

    #[Test]
    public function payments_are_scoped_through_the_lead(): void
    {
        $colleague = $this->user(RoleName::Telecaller);
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create(['assigned_to' => $colleague->id]);
        $sale = $this->saleWorth(1000, $lead);
        $this->postJson("/api/v1/sales/{$sale->id}/payments", ['amount' => 500]);

        // Manager, not Telecaller: a telecaller holds no payments permission at
        // all, so it would be refused by the gate before scope was ever
        // consulted - which would prove nothing about scoping. A Manager holds
        // payments.view and is Team-scoped, and this lead's owner has no team.
        $this->actingAsRole(RoleName::Manager);

        $this->getJson('/api/v1/payments')->assertOk()->assertJsonCount(0, 'data.items');
        $this->getJson("/api/v1/sales/{$sale->id}/payments")->assertStatus(403);
    }

    #[Test]
    public function a_telecaller_cannot_see_or_record_a_payment_at_all(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $sale = $this->saleWorth(1000);

        // Telecaller holds no payments permission - refused by the gate, before
        // scope is consulted.
        $this->actingAsRole(RoleName::Telecaller);

        $this->getJson('/api/v1/payments')->assertStatus(403);
        $this->postJson("/api/v1/sales/{$sale->id}/payments", ['amount' => 100])->assertStatus(403);
    }
}
