<?php

namespace Tests\Feature\Payments;

use App\Enums\LeadStatus;
use App\Enums\PaymentStatus;
use App\Enums\RoleName;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\PaymentLink;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\Payments\PaymentService;
use App\Services\Settings\SettingsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Payment links and gateway collection (Phase 23, FR-PAY-02, T-34/T-59).
 *
 * Two properties matter more than anything else here and both are money
 * properties rather than plumbing ones:
 *
 * - **Unkeyed refuses.** Unlike the message channels there is no log-driver
 *   fallback, because a fallback payment link is a customer paying into
 *   nowhere. No credential means a 503 naming the missing field (SEC-CFG-04).
 * - **A link is not a receipt.** Generating one leaves the payment Pending, so
 *   it cannot settle a balance (BR-PAY-04) or satisfy the conversion
 *   precondition (BR-PAY-05). Only a signed callback moves the ledger.
 */
class PaymentLinkTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'razorpay-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        // Nothing in this suite may reach the real Razorpay. A stray request
        // would also silently prove the unkeyed refusal wrong.
        Http::preventStrayRequests();
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
    private function saleWorth(float $amount, ?Lead $lead = null): Sale
    {
        $lead ??= Lead::factory()->create(['status' => LeadStatus::Negotiation->value]);

        $opportunityId = $this->postJson("/api/v1/leads/{$lead->id}/opportunities", [
            'title' => 'Deal',
            'products' => [['product_id' => Product::factory()->create(['base_price' => $amount])->id]],
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/opportunities/{$opportunityId}/sale")->assertCreated();

        return Sale::where('opportunity_id', $opportunityId)->firstOrFail();
    }

    private function configureGateway(bool $withKeys = true, bool $withSecret = true): void
    {
        $settings = app(SettingsService::class);

        if ($withKeys) {
            $settings->set('providers.payment.key_id', 'rzp_test_key');
            $settings->set('providers.payment.key_secret', 'rzp_test_secret');
        }

        if ($withSecret) {
            $settings->set('providers.payment.webhook_secret', self::WEBHOOK_SECRET);
        }
    }

    /** Razorpay's own response shape for a created payment link. */
    private function fakeRazorpay(string $linkId = 'plink_test_1'): void
    {
        Http::fake(['api.razorpay.com/*' => Http::response([
            'id' => $linkId,
            'short_url' => 'https://rzp.io/i/abc123',
            'status' => 'created',
            'amount' => 100000,
        ], 200)]);
    }

    /**
     * Posts a webhook signed the way Razorpay signs one: hex HMAC-SHA256 over
     * the RAW body. Computed over the same JSON the client will send, because a
     * digest over a re-encoded payload is a digest over different bytes.
     *
     * @param  array<string, mixed>  $body
     */
    private function postWebhook(array $body, bool $signed = true, ?string $eventId = 'evt_1')
    {
        $raw = json_encode($body);

        $headers = array_filter([
            'X-Razorpay-Signature' => $signed
                ? hash_hmac('sha256', $raw, self::WEBHOOK_SECRET)
                : null,
            'X-Razorpay-Event-Id' => $eventId,
        ]);

        return $this->withHeaders($headers)->postJson('/api/v1/webhooks/payment', $body);
    }

    /** @return array<string, mixed> */
    private function paidPayload(string $linkId = 'plink_test_1', string $paymentId = 'pay_test_1', int $paise = 100000): array
    {
        return [
            'entity' => 'event',
            'event' => 'payment_link.paid',
            'payload' => [
                'payment_link' => ['entity' => ['id' => $linkId, 'status' => 'paid', 'amount' => $paise]],
                'payment' => ['entity' => ['id' => $paymentId, 'amount' => $paise, 'status' => 'captured']],
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Unkeyed refuses - no fallback for money (SEC-CFG-04)
    // -----------------------------------------------------------------------

    #[Test]
    public function an_unkeyed_gateway_refuses_the_link_and_names_the_missing_credential(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $sale = $this->saleWorth(1000);

        $response = $this->postJson("/api/v1/sales/{$sale->id}/payment-link", ['amount' => 1000])
            ->assertStatus(503);

        // Naming the field is the difference between an operator fixing it in
        // Settings and an operator opening a support ticket.
        $this->assertStringContainsString('providers.payment.key_id', $response->json('message'));

        // The refusal happens BEFORE anything is written: no phantom Pending
        // instalment, no link row, and preventStrayRequests proves no gateway
        // request was attempted.
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_links', 0);
    }

    #[Test]
    public function a_gateway_with_no_driver_refuses_rather_than_falling_back_to_razorpay(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $this->configureGateway();
        app(SettingsService::class)->set('providers.payment.gateway', 'stripe');
        $sale = $this->saleWorth(1000);

        // Collecting through a provider the operator did not choose is a
        // reconciliation problem nobody would think to look for.
        $this->postJson("/api/v1/sales/{$sale->id}/payment-link", ['amount' => 1000])
            ->assertStatus(503)
            ->assertJsonPath('message', fn ($m) => str_contains((string) $m, 'not supported'));

        $this->assertDatabaseCount('payments', 0);
    }

    // -----------------------------------------------------------------------
    // Keyed happy path
    // -----------------------------------------------------------------------

    #[Test]
    public function a_configured_gateway_issues_a_link_against_a_pending_payment(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $this->configureGateway();
        $this->fakeRazorpay();
        $sale = $this->saleWorth(1000);

        $response = $this->postJson("/api/v1/sales/{$sale->id}/payment-link", ['amount' => 1000])
            ->assertCreated()
            ->assertJsonPath('data.url', 'https://rzp.io/i/abc123')
            ->assertJsonPath('data.gateway', 'razorpay')
            ->assertJsonPath('data.payment.status', 'pending');

        $this->assertDatabaseHas('payment_links', [
            'gateway' => 'razorpay',
            'gateway_link_id' => 'plink_test_1',
            'status' => 'created',
        ]);

        // The gateway is quoted in paise; a factor-of-100 error here is a
        // hundredfold charge, not a rounding bug.
        Http::assertSent(fn ($request) => $request['amount'] === 100000
            && $request['currency'] === 'INR'
            && $request['reference_id'] === $response->json('data.payment.reference'));
    }

    #[Test]
    public function omitting_the_amount_bills_the_outstanding_balance(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $this->configureGateway();
        $this->fakeRazorpay();
        $sale = $this->saleWorth(1000);

        $this->postJson("/api/v1/sales/{$sale->id}/payments", ['amount' => 400])->assertCreated();

        $this->postJson("/api/v1/sales/{$sale->id}/payment-link")->assertCreated();

        // Derived from what is still owed (BR-PAY-04), never a stored figure -
        // 600 of paise, after the 400 already collected.
        Http::assertSent(fn ($request) => $request['amount'] === 60000);
    }

    #[Test]
    public function a_settled_sale_has_no_link_to_issue(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $this->configureGateway();
        $sale = $this->saleWorth(1000);
        $this->postJson("/api/v1/sales/{$sale->id}/payments", ['amount' => 1000])->assertCreated();

        // Asking a customer for zero is not a request anybody meant to make.
        $this->postJson("/api/v1/sales/{$sale->id}/payment-link")->assertStatus(422);
    }

    #[Test]
    public function issuing_a_link_needs_payments_manage(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $this->configureGateway();
        $sale = $this->saleWorth(1000);

        // Manager holds payments.view only. Asking a customer for money is a
        // sales action, gated like recording one by hand (SEC-AUTHZ-06).
        $this->actingAsRole(RoleName::Manager);
        $this->postJson("/api/v1/sales/{$sale->id}/payment-link", ['amount' => 100])->assertStatus(403);

        $this->actingAsRole(RoleName::Telecaller);
        $this->postJson("/api/v1/sales/{$sale->id}/payment-link", ['amount' => 100])->assertStatus(403);
    }

    #[Test]
    public function a_gateway_that_refuses_leaves_no_phantom_payment_behind(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $this->configureGateway();
        $sale = $this->saleWorth(1000);

        Http::fake(['api.razorpay.com/*' => Http::response([
            'error' => ['description' => 'amount exceeds maximum'],
        ], 400)]);

        $this->postJson("/api/v1/sales/{$sale->id}/payment-link", ['amount' => 1000])
            ->assertStatus(502)
            ->assertJsonPath('message', fn ($m) => str_contains((string) $m, 'amount exceeds maximum'));

        // Rolled back. A committed Pending instalment for a link that was never
        // created shows a sale expecting money it will never receive.
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_links', 0);
    }

    // -----------------------------------------------------------------------
    // A link is not a receipt (BR-PAY-04/05) - obstacle (a)
    // -----------------------------------------------------------------------

    #[Test]
    public function generating_a_link_for_the_full_amount_does_not_convert_the_lead(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $this->configureGateway();
        $this->fakeRazorpay();
        $lead = Lead::factory()->create(['status' => LeadStatus::Negotiation->value]);
        $sale = $this->saleWorth(1000, $lead);

        $this->postJson("/api/v1/sales/{$sale->id}/payment-link", ['amount' => 1000])->assertCreated();

        /*
         * The trap this test exists for: the ordinary opening-status derivation
         * would see an amount that settles the sale and open the row as Paid -
         * so merely PRESSING THE BUTTON would satisfy BR-PAY-05 and let the
         * lead be converted before the customer had opened the link.
         */
        $this->assertSame(PaymentStatus::Pending, Payment::firstOrFail()->status);
        $this->assertSame(1000.0, app(PaymentService::class)->balanceFor($sale->fresh()));

        $this->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => 'converted'])
            ->assertStatus(422)
            ->assertJsonPath('data.requires', 'payment');

        $this->assertSame(LeadStatus::Negotiation, $lead->fresh()->status);
    }

    // -----------------------------------------------------------------------
    // Collection webhook (SEC-WH-01/03)
    // -----------------------------------------------------------------------

    /** Issues a link and returns its payment. */
    private function issueLink(float $amount = 1000, ?Lead $lead = null): Payment
    {
        $this->configureGateway();
        $this->fakeRazorpay();
        $sale = $this->saleWorth($amount, $lead);

        $this->postJson("/api/v1/sales/{$sale->id}/payment-link", ['amount' => $amount])->assertCreated();

        return Payment::firstOrFail();
    }

    #[Test]
    public function a_signed_paid_callback_collects_the_payment(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create(['status' => LeadStatus::Negotiation->value]);
        $payment = $this->issueLink(1000, $lead);

        $this->postWebhook($this->paidPayload())
            ->assertOk()
            ->assertJsonPath('message', 'Processed.');

        $payment->refresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        // The gateway's own id lands in the unique pair - the column that stops
        // the same money being recorded twice.
        $this->assertSame('pay_test_1', $payment->gateway_payment_id);
        $this->assertSame('razorpay', $payment->gateway);

        $this->assertDatabaseHas('payment_links', ['gateway_link_id' => 'plink_test_1', 'status' => 'paid']);
        // Attributed to the gateway, not to whoever happened to issue the link.
        $this->assertDatabaseHas('payment_status_history', [
            'payment_id' => $payment->id,
            'to_status' => 'paid',
            'source' => 'gateway',
            'changed_by' => null,
        ]);

        // Only now does BR-PAY-05 let the lead convert.
        $this->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => 'converted'])->assertOk();
    }

    #[Test]
    public function a_part_payment_link_settles_only_part_of_the_sale(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $this->configureGateway();
        $this->fakeRazorpay();
        $sale = $this->saleWorth(1000);

        $this->postJson("/api/v1/sales/{$sale->id}/payment-link", ['amount' => 400])->assertCreated();

        $this->postWebhook($this->paidPayload(paise: 40000))->assertOk();

        // Partial, not Paid: the status is recomputed against the balance at the
        // moment the money arrived, so a deposit link cannot report a sale
        // settled (BR-PAY-04).
        $this->assertSame(PaymentStatus::Partial, Payment::firstOrFail()->status);
        $this->assertSame(600.0, app(PaymentService::class)->balanceFor($sale->fresh()));
    }

    #[Test]
    public function an_unsigned_callback_is_rejected_and_changes_nothing(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $payment = $this->issueLink();

        $this->postWebhook($this->paidPayload(), signed: false)->assertStatus(401);

        // A forged "paid" that landed would settle any sale in the system by
        // POSTing to a public URL.
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertDatabaseHas('provider_webhook_logs', [
            'provider' => 'razorpay',
            'signature_valid' => false,
            'processing_error' => 'Invalid or missing signature.',
        ]);
    }

    #[Test]
    public function a_callback_signed_with_the_wrong_secret_is_rejected(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $payment = $this->issueLink();
        $body = $this->paidPayload();

        // A valid-looking digest computed with a secret that is not ours - the
        // case a naive "is a signature header present" check would let through.
        $this->withHeaders([
            'X-Razorpay-Signature' => hash_hmac('sha256', json_encode($body), 'not-our-secret'),
            'X-Razorpay-Event-Id' => 'evt_forged',
        ])->postJson('/api/v1/webhooks/payment', $body)->assertStatus(401);

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    #[Test]
    public function an_unconfigured_webhook_secret_rejects_everything(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $this->configureGateway(withSecret: false);
        $this->fakeRazorpay();
        $sale = $this->saleWorth(1000);
        $this->postJson("/api/v1/sales/{$sale->id}/payment-link", ['amount' => 1000])->assertCreated();

        // Fails closed: an install that has API keys but no signing secret can
        // issue links and still not be told they were paid, which is a
        // reconciliation delay rather than an open write endpoint.
        $this->postWebhook($this->paidPayload())->assertStatus(401);

        $this->assertSame(PaymentStatus::Pending, Payment::firstOrFail()->status);
    }

    #[Test]
    public function a_redelivered_callback_collects_the_money_only_once(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $payment = $this->issueLink();

        $this->postWebhook($this->paidPayload())->assertOk()->assertJsonPath('message', 'Processed.');

        // Same event id: absorbed by the unique (provider, provider_event_id)
        // index before any handler runs.
        $this->postWebhook($this->paidPayload())
            ->assertOk()
            ->assertJsonPath('message', 'Already processed.');

        // A different event id carrying the same fact - the case the index
        // cannot catch. The ledger check behind it must still hold.
        $this->postWebhook($this->paidPayload(), eventId: 'evt_2')
            ->assertOk()
            ->assertJsonPath('message', 'No action.');

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        // One collection, not three: exactly one Pending -> Paid row.
        $this->assertSame(1, \DB::table('payment_status_history')
            ->where('payment_id', $payment->id)
            ->where('to_status', 'paid')
            ->count());
        $this->assertSame(1000.0, app(PaymentService::class)->collectedFor($payment->sale));
    }

    #[Test]
    public function a_failed_attempt_marks_the_payment_failed_without_touching_the_balance(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $payment = $this->issueLink();

        $this->postWebhook([
            'event' => 'payment.failed',
            'payload' => ['payment' => ['entity' => [
                'id' => 'pay_failed_1',
                'amount' => 100000,
                'error_description' => 'Card declined by issuer',
                // Our own id, planted in the link's notes at creation - the only
                // handle an event about a payment we have never seen gives us.
                'notes' => ['payment_link_id' => (string) $payment->id],
            ]]],
        ])->assertOk()->assertJsonPath('message', 'Processed.');

        $payment->refresh();
        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertSame('Card declined by issuer', $payment->failure_reason);
        // A failed payment must not reduce what is owed (BR-PAY-04).
        $this->assertSame(1000.0, app(PaymentService::class)->balanceFor($payment->sale));
    }

    #[Test]
    public function a_failure_arriving_after_the_money_does_not_undo_the_collection(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $payment = $this->issueLink();

        $this->postWebhook($this->paidPayload())->assertOk();

        // A customer whose second card was declined after the first one worked
        // has still paid. Gateways do not guarantee event order.
        $this->postWebhook([
            'event' => 'payment.failed',
            'payload' => ['payment' => ['entity' => [
                'id' => 'pay_failed_2',
                'notes' => ['payment_link_id' => (string) $payment->id],
            ]]],
        ], eventId: 'evt_late')->assertOk()->assertJsonPath('message', 'No action.');

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
    }

    #[Test]
    public function a_gateway_refund_reverses_the_payment_with_a_reason(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $payment = $this->issueLink();

        $this->postWebhook($this->paidPayload())->assertOk();

        // A refund names only the payment it reverses, never the link, so this
        // exercises the second resolution route.
        $this->postWebhook([
            'event' => 'refund.processed',
            'payload' => ['refund' => ['entity' => [
                'id' => 're_1',
                'payment_id' => 'pay_test_1',
                'amount' => 100000,
                'notes' => ['reason' => 'Customer cancelled'],
            ]]],
        ], eventId: 'evt_refund')->assertOk()->assertJsonPath('message', 'Processed.');

        $this->assertSame(PaymentStatus::Refund, $payment->fresh()->status);
        // A refund made in the gateway dashboard lands with the same audit
        // trail as one made here (SEC-AUD-02).
        $this->assertDatabaseHas('payment_status_history', [
            'payment_id' => $payment->id,
            'to_status' => 'refund',
            'source' => 'gateway',
            'reason' => 'Customer cancelled',
        ]);
        $this->assertSame(1000.0, app(PaymentService::class)->balanceFor($payment->sale));
    }

    #[Test]
    public function an_expired_link_closes_without_failing_the_payment(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $payment = $this->issueLink();

        $this->postWebhook([
            'event' => 'payment_link.expired',
            'payload' => ['payment_link' => ['entity' => ['id' => 'plink_test_1', 'status' => 'expired']]],
        ])->assertOk();

        // An expired invitation is not a failed payment - nothing was attempted
        // and nothing was lost, so the instalment stays Pending for the
        // BR-PAY-06 sweep and the operator can issue a fresh link.
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertDatabaseHas('payment_links', ['gateway_link_id' => 'plink_test_1', 'status' => 'expired']);
    }

    #[Test]
    public function an_event_for_a_link_we_never_issued_is_logged_and_ignored(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $payment = $this->issueLink();

        $this->postWebhook($this->paidPayload(linkId: 'plink_someone_else', paymentId: 'pay_other'))
            ->assertOk()
            ->assertJsonPath('message', 'No action.');

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        // Kept in full so a human can reconcile it - which is recoverable,
        // whereas applying money to the wrong payment is not.
        $this->assertDatabaseHas('provider_webhook_logs', [
            'provider' => 'razorpay',
            'signature_valid' => true,
            'processing_error' => 'No matching payment link.',
        ]);
    }

    #[Test]
    public function an_event_the_ledger_does_not_act_on_is_acknowledged_not_guessed_at(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $payment = $this->issueLink();

        $this->postWebhook([
            'event' => 'payment_link.partially_paid',
            'payload' => ['payment_link' => ['entity' => ['id' => 'plink_test_1']]],
        ])->assertOk()->assertJsonPath('message', 'No action.');

        // Recorded as unhandled rather than interpreted. A misread gateway event
        // rewrites the ledger, and BR-PAY-02 is not a place to improvise.
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertDatabaseHas('provider_webhook_logs', ['processing_error' => 'Unhandled event type.']);
    }

    #[Test]
    public function the_link_row_never_becomes_a_second_definition_of_collected(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $payment = $this->issueLink();

        $this->postWebhook($this->paidPayload())->assertOk();

        // `payment_links.status` describes the invitation; `payments.status` is
        // the ledger. Reporting must read the second one (GLOSSARY 2.5), so the
        // link table carries no money columns anything else would sum.
        $this->assertSame(PaymentLink::STATUS_PAID, PaymentLink::firstOrFail()->status);
        $this->assertSame(1, Payment::query()->collected()->count());
        $this->assertSame($payment->id, Payment::query()->collected()->first()->id);
    }
}
