<?php

namespace Tests\Feature\Web;

use App\Enums\PaymentStatus;
use App\Enums\RoleName;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The API shapes three screens draw from (NFR-03).
 *
 * Each of these screens rendered fine while disagreeing with the endpoint behind
 * it - a filter sent under the wrong key, an author read from a key that is not
 * there, an error bag that is actually a list. A rendering test cannot catch
 * that, because the page never runs its JS in one. So what is pinned here is the
 * contract each fix was written against, plus the one line of the view that now
 * reads it: if the endpoint ever changes shape, these fail rather than the
 * screen quietly going blank again.
 *
 * Nothing here depends on the clock.
 */
class UiApiContractTest extends TestCase
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

    /**
     * A payment cannot exist without its sale, customer, lead and product
     * (BR-PAY-03), so the whole chain is built rather than faked.
     */
    private function payment(PaymentStatus $status, string $method): Payment
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
            'reference' => 'S-UI-'.$opportunity->id,
            'amount' => 1000,
            'sold_at' => now(),
        ]);

        return Payment::factory()->create([
            'sale_id' => $sale->id,
            'customer_id' => $customer->id,
            'lead_id' => $lead->id,
            'product_id' => Product::factory()->create()->id,
            'status' => $status->value,
            'method' => $method,
        ]);
    }

    // -----------------------------------------------------------------------
    // 1. Payments list filters (FR-PAY-04)
    // -----------------------------------------------------------------------

    #[Test]
    public function the_payments_list_reads_its_filters_only_from_the_filter_parameter(): void
    {
        $accounts = $this->user(RoleName::Accounts);
        $paid = $this->payment(PaymentStatus::Paid, 'cash');
        $this->payment(PaymentStatus::Pending, 'upi');

        $filtered = $this->actingAs($accounts, 'sanctum')
            ->getJson('/api/v1/payments?filter[status]=paid')
            ->assertOk()
            ->json('data.items');

        $this->assertSame([$paid->id], array_column($filtered, 'id'));

        $byMethod = $this->actingAs($accounts, 'sanctum')
            ->getJson('/api/v1/payments?filter[method]=cash')
            ->assertOk()
            ->json('data.items');

        $this->assertSame([$paid->id], array_column($byMethod, 'id'));
    }

    #[Test]
    public function a_filter_sent_flat_is_not_a_filter_at_all(): void
    {
        // This is exactly what the screen used to send, and why both selects
        // silently did nothing: ?status=paid is not read, so every row came
        // back regardless of what was chosen.
        $accounts = $this->user(RoleName::Accounts);
        $this->payment(PaymentStatus::Paid, 'cash');
        $this->payment(PaymentStatus::Pending, 'upi');

        $this->actingAs($accounts, 'sanctum')
            ->getJson('/api/v1/payments?status=paid&method=cash')
            ->assertOk()
            ->assertJsonCount(2, 'data.items');
    }

    #[Test]
    public function an_unsupported_filter_field_is_refused_rather_than_dropped(): void
    {
        // The reason a blank select has to be omitted entirely instead of sent
        // empty: this endpoint answers 422 rather than ignoring a filter it
        // does not recognise (API_DOCUMENTATION §4).
        $this->actingAs($this->user(RoleName::Accounts), 'sanctum')
            ->getJson('/api/v1/payments?filter[nonsense]=x')
            ->assertStatus(422);
    }

    #[Test]
    public function the_payments_screen_sends_its_selects_under_filter(): void
    {
        $html = $this->actingAs($this->user(RoleName::Accounts))
            ->get('/payments')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('filter.status', $html);
        $this->assertStringContainsString('filter.method', $html);
    }

    // -----------------------------------------------------------------------
    // 2. Note authorship (FR-LEAD-03)
    // -----------------------------------------------------------------------

    #[Test]
    public function the_notes_endpoint_names_the_writer_author_and_not_user(): void
    {
        $manager = $this->user(RoleName::Manager);
        $lead = Lead::factory()->create();

        $lead->notes()->create([
            'user_id' => $manager->id,
            'body' => 'Quoted the annual plan.',
        ]);

        $note = $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/leads/'.$lead->id.'/notes')
            ->assertOk()
            ->json('data.items.0');

        // A nested {id, name}, under `author`. Reading `user` here is what made
        // every note on the page claim the system wrote it.
        $this->assertSame($manager->id, $note['author']['id']);
        $this->assertSame($manager->name, $note['author']['name']);
        $this->assertArrayNotHasKey('user', $note);
    }

    #[Test]
    public function the_notes_tab_reads_the_author_key(): void
    {
        $manager = $this->user(RoleName::Manager);

        $this->actingAs($manager)
            ->get('/leads/'.Lead::factory()->create()->id)
            ->assertOk()
            ->assertSee('note.author', false)
            ->assertDontSee('note.user', false);
    }

    // -----------------------------------------------------------------------
    // 3. Validation error shape (NFR-03)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_rejected_campaign_returns_a_flat_list_of_field_errors_not_a_bag(): void
    {
        $manager = $this->user(RoleName::Manager);

        $errors = $this->actingAs($manager, 'sanctum')
            ->postJson('/api/v1/campaigns', [
                'name' => '',
                'channel' => 'sms',
                'scheduled_at' => 'not-a-date',
            ])
            ->assertStatus(422)
            ->json('errors');

        // A list of {field, code, message} - NOT {name: ["..."]}. The builder
        // treated it as the latter, so no field was ever highlighted.
        $this->assertTrue(array_is_list($errors), 'errors must be a flat list, not a field-keyed bag.');

        foreach ($errors as $error) {
            $this->assertArrayHasKey('field', $error);
            $this->assertArrayHasKey('code', $error);
            $this->assertArrayHasKey('message', $error);
        }

        $fields = array_column($errors, 'field');

        $this->assertContains('name', $fields);
        $this->assertContains('scheduled_at', $fields);
    }

    #[Test]
    public function the_campaign_builder_paints_errors_from_the_flat_shape(): void
    {
        $html = $this->actingAs($this->user(RoleName::Manager))
            ->get('/campaigns/create')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('error.field', $html);
        $this->assertStringContainsString('error.message', $html);

        // The bag reading that never fired.
        $this->assertStringNotContainsString('Object.keys(failures)', $html);
    }
}
