<?php

namespace Tests\Feature\FollowUps;

use App\Enums\FollowUpStatus;
use App\Enums\RoleName;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Follow-ups and reminders (Phase 21, FR-FUP-01..05, BR-FUP-01..03, BR-NOTIF-*).
 *
 * The rules worth proving are the ones that make the missed-follow-up report
 * trustworthy: one open follow-up per lead-product, reschedules that preserve
 * history rather than overwriting it, and `Missed` set by the scheduler and
 * never by a user.
 */
class FollowUpTest extends TestCase
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

    private function tomorrow(string $time = '10:00'): string
    {
        return Carbon::tomorrow()->setTimeFromTimeString($time)->toIso8601String();
    }

    // -----------------------------------------------------------------------
    // Scheduling (FR-FUP-01, BR-FUP-01)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_follow_up_defaults_to_the_leads_owner(): void
    {
        $owner = $this->user(RoleName::Telecaller);
        $manager = $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create(['assigned_to' => $owner->id]);

        $this->postJson("/api/v1/leads/{$lead->id}/follow-ups", [
            'scheduled_at' => $this->tomorrow(),
        ])->assertCreated();

        // A follow-up nobody is named on is one nobody does - and the manager
        // booking it is not the person who will make the call.
        $this->assertSame($owner->id, FollowUp::firstOrFail()->assigned_to);
        $this->assertNotSame($manager->id, FollowUp::firstOrFail()->assigned_to);
    }

    #[Test]
    public function a_second_follow_up_for_the_same_lead_product_reschedules_rather_than_duplicating(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create();

        $this->postJson("/api/v1/leads/{$lead->id}/follow-ups", [
            'scheduled_at' => $this->tomorrow('10:00'),
        ])->assertCreated();

        $this->postJson("/api/v1/leads/{$lead->id}/follow-ups", [
            'scheduled_at' => $this->tomorrow('15:00'),
        ])->assertCreated();

        // BR-FUP-01: two open reminders for the same thing means one is wrong,
        // and whichever gets actioned, the other becomes a false "missed".
        $this->assertSame(1, FollowUp::where('status', FollowUpStatus::Open->value)->count());
        $this->assertSame(2, FollowUp::count());   // the original is kept, closed
    }

    #[Test]
    public function follow_ups_for_different_products_coexist(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create();
        $a = Product::factory()->create();
        $b = Product::factory()->create();

        // The rule is one per lead-PRODUCT, not one per lead. A prospect
        // weighing two products legitimately needs two conversations.
        foreach ([$a, $b] as $product) {
            $this->postJson("/api/v1/leads/{$lead->id}/follow-ups", [
                'scheduled_at' => $this->tomorrow(),
                'product_id' => $product->id,
            ])->assertCreated();
        }

        $this->assertSame(2, FollowUp::where('status', FollowUpStatus::Open->value)->count());
    }

    #[Test]
    public function a_follow_up_cannot_be_scheduled_in_the_past(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create();

        // A reminder for a time already gone fires immediately and reads as a
        // bug to whoever receives it.
        $this->postJson("/api/v1/leads/{$lead->id}/follow-ups", [
            'scheduled_at' => Carbon::yesterday()->toIso8601String(),
        ])->assertStatus(422);
    }

    // -----------------------------------------------------------------------
    // Reschedule preserves history (BR-FUP-03, FR-FUP-04)
    // -----------------------------------------------------------------------

    #[Test]
    public function rescheduling_keeps_the_original_rather_than_overwriting_it(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create();

        $original = $this->postJson("/api/v1/leads/{$lead->id}/follow-ups", [
            'scheduled_at' => $this->tomorrow('09:00'),
        ])->json('data.id');

        $replacement = $this->postJson("/api/v1/follow-ups/{$original}/reschedule", [
            'scheduled_at' => $this->tomorrow('16:00'),
        ])->assertOk()->json('data.id');

        // Overwriting scheduled_at in place would erase the evidence that a
        // commitment was moved - which is exactly what the history is for.
        $this->assertNotSame($original, $replacement);
        $this->assertSame($original, FollowUp::find($replacement)->rescheduled_from_id);
        $this->assertNotNull(FollowUp::find($original));
        $this->assertSame(
            Carbon::tomorrow()->setTime(9, 0)->format('H:i'),
            FollowUp::find($original)->scheduled_at->format('H:i'),
        );
    }

    #[Test]
    public function updating_a_follow_up_does_not_reset_its_scheduled_time(): void
    {
        // Regression for the schema bug this phase uncovered: MySQL gives the
        // first bare `TIMESTAMP NOT NULL` column ON UPDATE CURRENT_TIMESTAMP,
        // so ANY write to the row - complete, cancel, transfer, or the
        // scheduler flagging a miss - silently reset the schedule to now.
        // Every follow-up became "due now" the first time it was touched.
        $lead = Lead::factory()->create();
        $followUp = FollowUp::factory()->for($lead)->create([
            'scheduled_at' => Carbon::tomorrow()->setTime(9, 0),
            'status' => FollowUpStatus::Open->value,
        ]);

        $followUp->update(['subject' => 'Anything at all']);

        $this->assertSame(
            Carbon::tomorrow()->setTime(9, 0)->format('Y-m-d H:i'),
            $followUp->fresh()->scheduled_at->format('Y-m-d H:i'),
        );
    }

    #[Test]
    public function a_completed_follow_up_cannot_be_rescheduled(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create();

        $id = $this->postJson("/api/v1/leads/{$lead->id}/follow-ups", [
            'scheduled_at' => $this->tomorrow(),
        ])->json('data.id');

        $this->postJson("/api/v1/follow-ups/{$id}/complete")->assertOk();

        $this->postJson("/api/v1/follow-ups/{$id}/reschedule", [
            'scheduled_at' => $this->tomorrow('18:00'),
        ])->assertStatus(422);
    }

    // -----------------------------------------------------------------------
    // Missed detection (BR-FUP-02, FR-FUP-03)
    // -----------------------------------------------------------------------

    #[Test]
    public function the_scheduler_flags_an_overdue_follow_up_as_missed(): void
    {
        $lead = Lead::factory()->create();
        $followUp = FollowUp::factory()->for($lead)->create([
            'scheduled_at' => now()->subHour(),
            'status' => FollowUpStatus::Open->value,
        ]);

        $this->artisan('crm:process-follow-ups')->assertSuccessful();

        $this->assertSame(FollowUpStatus::Missed, $followUp->fresh()->status);
    }

    #[Test]
    public function a_missed_follow_up_can_still_be_completed_late(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create();
        $followUp = FollowUp::factory()->for($lead)->create([
            'scheduled_at' => now()->subHour(),
            'status' => FollowUpStatus::Missed->value,
        ]);

        // It is late, not void. Refusing would push people to create a fresh
        // follow-up and lose the miss from the record.
        $this->postJson("/api/v1/follow-ups/{$followUp->id}/complete", [
            'outcome' => 'Called back the next morning',
        ])->assertOk();

        $this->assertSame(FollowUpStatus::Completed, $followUp->fresh()->status);
    }

    #[Test]
    public function a_user_cannot_mark_their_own_follow_up_missed(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create();
        $followUp = FollowUp::factory()->for($lead)->create(['status' => FollowUpStatus::Open->value]);

        // `Missed` is the scheduler's word, not a user's. The difference
        // between "nobody got to it" and "somebody decided not to" is the whole
        // value of the report - there is deliberately no endpoint for this.
        $this->postJson("/api/v1/follow-ups/{$followUp->id}/cancel", ['reason' => 'skip'])
            ->assertOk();

        $this->assertSame(FollowUpStatus::Cancelled, $followUp->fresh()->status);
    }

    #[Test]
    public function a_future_follow_up_is_left_alone_by_the_scheduler(): void
    {
        $followUp = FollowUp::factory()->for(Lead::factory())->create([
            'scheduled_at' => now()->addDays(2),
            'status' => FollowUpStatus::Open->value,
        ]);

        $this->artisan('crm:process-follow-ups')->assertSuccessful();

        $this->assertSame(FollowUpStatus::Open, $followUp->fresh()->status);
    }

    // -----------------------------------------------------------------------
    // Reminders (BR-NOTIF-04, FR-FUP-05)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_reminder_fires_at_the_configured_lead_time(): void
    {
        config(['crm.follow_up.reminder_lead_minutes' => 15]);

        $owner = $this->user(RoleName::Telecaller);
        $followUp = FollowUp::factory()->for(Lead::factory())->create([
            'assigned_to' => $owner->id,
            'scheduled_at' => now()->addMinutes(10),
            'status' => FollowUpStatus::Open->value,
        ]);

        $this->artisan('crm:process-follow-ups')->assertSuccessful();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->id,
            'type' => 'follow_up_due',
        ]);
        $this->assertTrue($followUp->fresh()->reminder_sent);
    }

    #[Test]
    public function a_reminder_is_not_sent_twice(): void
    {
        config(['crm.follow_up.reminder_lead_minutes' => 15]);

        $owner = $this->user(RoleName::Telecaller);
        FollowUp::factory()->for(Lead::factory())->create([
            'assigned_to' => $owner->id,
            'scheduled_at' => now()->addMinutes(5),
            'status' => FollowUpStatus::Open->value,
        ]);

        // The command runs every minute. Without the reminder_sent guard this
        // notifies the same person every minute until the follow-up is due,
        // which is how people learn to ignore notifications.
        $this->artisan('crm:process-follow-ups')->assertSuccessful();
        $this->artisan('crm:process-follow-ups')->assertSuccessful();

        $this->assertSame(1, $owner->crmNotifications()->where('type', 'follow_up_due')->count());
    }

    #[Test]
    public function a_follow_up_too_far_out_gets_no_reminder_yet(): void
    {
        config(['crm.follow_up.reminder_lead_minutes' => 15]);

        $owner = $this->user(RoleName::Telecaller);
        FollowUp::factory()->for(Lead::factory())->create([
            'assigned_to' => $owner->id,
            'scheduled_at' => now()->addHours(3),
            'status' => FollowUpStatus::Open->value,
        ]);

        $this->artisan('crm:process-follow-ups')->assertSuccessful();

        $this->assertSame(0, $owner->crmNotifications()->count());
    }

    #[Test]
    public function a_follow_up_owned_by_a_disabled_account_does_not_retry_for_ever(): void
    {
        $owner = $this->user(RoleName::Telecaller);
        $owner->forceFill(['is_active' => false])->save();

        $followUp = FollowUp::factory()->for(Lead::factory())->create([
            'assigned_to' => $owner->id,
            'scheduled_at' => now()->addMinutes(5),
            'status' => FollowUpStatus::Open->value,
        ]);

        $this->artisan('crm:process-follow-ups')->assertSuccessful();

        // Marked as reminded even though nobody could receive it - otherwise
        // the tick spends its life on a notification that can never land.
        $this->assertTrue($followUp->fresh()->reminder_sent);
        $this->assertSame(0, $owner->crmNotifications()->count());
    }

    #[Test]
    public function a_dry_run_reports_without_writing(): void
    {
        $followUp = FollowUp::factory()->for(Lead::factory())->create([
            'scheduled_at' => now()->subHour(),
            'status' => FollowUpStatus::Open->value,
        ]);

        $this->artisan('crm:process-follow-ups --dry-run')->assertSuccessful();

        $this->assertSame(FollowUpStatus::Open, $followUp->fresh()->status);
    }

    // -----------------------------------------------------------------------
    // Reassignment carries the commitment (BR-ASSIGN-04, T-14)
    // -----------------------------------------------------------------------

    #[Test]
    public function open_follow_ups_transfer_with_the_lead(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $from = $this->user(RoleName::Telecaller);
        $to = $this->user(RoleName::Telecaller);

        $lead = Lead::factory()->create(['assigned_to' => $from->id]);
        $open = FollowUp::factory()->for($lead)->create([
            'assigned_to' => $from->id,
            'status' => FollowUpStatus::Open->value,
        ]);
        $done = FollowUp::factory()->for($lead)->create([
            'assigned_to' => $from->id,
            'status' => FollowUpStatus::Completed->value,
        ]);

        $this->postJson("/api/v1/leads/{$lead->id}/assign", ['user_id' => $to->id])->assertOk();

        // T-14 answered as "yes, transfer": a follow-up left pointing at the
        // previous owner is invisible to the new one and meaningless to the old.
        $this->assertSame($to->id, $open->fresh()->assigned_to);

        // History does not move - it records who actually did the work.
        $this->assertSame($from->id, $done->fresh()->assigned_to);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $to->id,
            'type' => 'follow_up_transferred',
        ]);
    }

    // -----------------------------------------------------------------------
    // Scope and permissions
    // -----------------------------------------------------------------------

    #[Test]
    public function my_follow_up_list_shows_only_mine_and_soonest_first(): void
    {
        $me = $this->actingAsRole(RoleName::Telecaller);
        $colleague = $this->user(RoleName::Telecaller);

        FollowUp::factory()->for(Lead::factory())->create([
            'assigned_to' => $me->id, 'scheduled_at' => now()->addDays(2), 'status' => 'open',
        ]);
        FollowUp::factory()->for(Lead::factory())->create([
            'assigned_to' => $me->id, 'scheduled_at' => now()->addHour(), 'status' => 'open',
        ]);
        FollowUp::factory()->for(Lead::factory())->create([
            'assigned_to' => $colleague->id, 'scheduled_at' => now()->addHour(), 'status' => 'open',
        ]);

        $items = $this->getJson('/api/v1/follow-ups')->assertOk()->json('data.items');

        $this->assertCount(2, $items);
        // A list ordered by creation is useless to the person working it.
        $this->assertTrue($items[0]['scheduled_at'] < $items[1]['scheduled_at']);
    }

    #[Test]
    public function completed_follow_ups_are_out_of_the_working_list_by_default(): void
    {
        $me = $this->actingAsRole(RoleName::Telecaller);

        FollowUp::factory()->for(Lead::factory())->create([
            'assigned_to' => $me->id, 'status' => FollowUpStatus::Open->value,
        ]);
        FollowUp::factory()->for(Lead::factory())->create([
            'assigned_to' => $me->id, 'status' => FollowUpStatus::Completed->value,
        ]);
        FollowUp::factory()->for(Lead::factory())->create([
            'assigned_to' => $me->id, 'status' => FollowUpStatus::Missed->value,
        ]);

        // Missed stays in the list: it still needs doing.
        $this->assertCount(2, $this->getJson('/api/v1/follow-ups')->json('data.items'));
    }

    #[Test]
    public function a_telecaller_cannot_touch_a_follow_up_on_someone_elses_lead(): void
    {
        $this->actingAsRole(RoleName::Telecaller);
        $colleague = $this->user(RoleName::Telecaller);

        $lead = Lead::factory()->create(['assigned_to' => $colleague->id]);
        $followUp = FollowUp::factory()->for($lead)->create(['status' => FollowUpStatus::Open->value]);

        // Reachable exactly when its lead is, delegated to LeadPolicy rather
        // than a second scope implementation (SEC-AUTHZ-04).
        $this->postJson("/api/v1/follow-ups/{$followUp->id}/complete")->assertStatus(403);
        $this->getJson("/api/v1/leads/{$lead->id}/follow-ups")->assertStatus(403);
    }

    #[Test]
    public function a_role_without_follow_up_permissions_is_refused(): void
    {
        $this->actingAsRole(RoleName::Accounts);   // holds neither follow_ups.*
        $lead = Lead::factory()->create();

        $this->getJson('/api/v1/follow-ups')->assertStatus(403);
        $this->postJson("/api/v1/leads/{$lead->id}/follow-ups", [
            'scheduled_at' => $this->tomorrow(),
        ])->assertStatus(403);
    }
}
