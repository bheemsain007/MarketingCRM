<?php

namespace Tests\Feature\FollowUps;

use App\Enums\Channel;
use App\Enums\DncReason;
use App\Enums\RoleName;
use App\Models\DncEntry;
use App\Models\Lead;
use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Internal notifications (FR-NOTIF-01..03, BR-NOTIF-01/03).
 *
 * The rule most worth a test is the one that sounds like a bug: notifications
 * are NEVER DNC-filtered. Suppression protects leads from being contacted;
 * these target staff, and routing them through the gate would silently stop
 * colleagues hearing about their own work.
 */
class NotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function user(RoleName $role = RoleName::Telecaller): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', $role->value)->first());

        return $user->fresh();
    }

    #[Test]
    public function a_notification_about_a_suppressed_lead_still_reaches_the_user(): void
    {
        $telecaller = $this->user();
        $lead = Lead::factory()->create(['assigned_to' => $telecaller->id]);
        DncEntry::factory()->for($lead)->reason(DncReason::DoNotContact)->create();

        app(NotificationService::class)->notify(
            $telecaller,
            'follow_up_due',
            'Follow-up due: '.$lead->name,
            ['reference' => $lead],
        );

        // BR-NOTIF-01. The lead may not be contacted; the telecaller must still
        // be told about their own workload.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $telecaller->id,
            'type' => 'follow_up_due',
        ]);
    }

    #[Test]
    public function an_in_app_notification_is_written_as_sent_immediately(): void
    {
        $user = $this->user();

        $notification = app(NotificationService::class)->notify($user, 'test', 'Title');

        // Writing the row IS the delivery for in-app - there is no external
        // dependency that could fail, which is why BR-NOTIF-03 makes it the
        // channel that must always survive.
        $this->assertSame('sent', $notification->status);
        $this->assertNotNull($notification->sent_at);
        $this->assertSame('in_app', $notification->channel);
    }

    #[Test]
    public function the_unread_count_is_per_user_and_accurate(): void
    {
        $me = $this->user();
        $colleague = $this->user();
        $service = app(NotificationService::class);

        $service->notify($me, 'a', 'One');
        $service->notify($me, 'b', 'Two');
        $service->notify($colleague, 'c', 'Theirs');

        $this->actingAs($me, 'sanctum');

        $this->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 2);
    }

    #[Test]
    public function marking_one_read_reduces_the_count(): void
    {
        $me = $this->user();
        $notification = app(NotificationService::class)->notify($me, 'a', 'One');

        $this->actingAs($me, 'sanctum');

        $this->postJson("/api/v1/notifications/{$notification->id}/read")->assertOk();

        $this->assertNotNull($notification->fresh()->read_at);
        $this->getJson('/api/v1/notifications/unread-count')->assertJsonPath('data.unread_count', 0);
    }

    #[Test]
    public function re_reading_does_not_move_the_first_read_timestamp(): void
    {
        $me = $this->user();
        $notification = app(NotificationService::class)->notify($me, 'a', 'One');

        $this->actingAs($me, 'sanctum');
        $this->postJson("/api/v1/notifications/{$notification->id}/read")->assertOk();
        $firstRead = $notification->fresh()->read_at;

        $this->travel(5)->minutes();
        $this->postJson("/api/v1/notifications/{$notification->id}/read")->assertOk();

        // "When did they first see this?" has to stay answerable.
        $this->assertEquals($firstRead, $notification->fresh()->read_at);
    }

    #[Test]
    public function a_notification_belonging_to_someone_else_is_a_404_not_a_403(): void
    {
        $colleague = $this->user();
        $notification = app(NotificationService::class)->notify($colleague, 'a', 'Theirs');

        $this->actingAs($this->user(), 'sanctum');

        // Sequential ids, one row per user - the most trivially enumerable
        // resource in the system. A 403 would confirm it exists.
        $this->postJson("/api/v1/notifications/{$notification->id}/read")->assertStatus(404);

        $this->assertNull($notification->fresh()->read_at);
    }

    #[Test]
    public function mark_all_read_only_touches_my_own(): void
    {
        $me = $this->user();
        $colleague = $this->user();
        $service = app(NotificationService::class);

        $service->notify($me, 'a', 'Mine');
        $service->notify($me, 'b', 'Mine too');
        $theirs = $service->notify($colleague, 'c', 'Theirs');

        $this->actingAs($me, 'sanctum');
        $this->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.marked', 2);

        $this->assertNull($theirs->fresh()->read_at);
    }

    #[Test]
    public function the_list_carries_the_unread_count_so_a_client_needs_one_call(): void
    {
        $me = $this->user();
        app(NotificationService::class)->notify($me, 'a', 'One');

        $this->actingAs($me, 'sanctum');

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data.meta.unread_count', 1)
            ->assertJsonCount(1, 'data.items');
    }

    #[Test]
    public function an_assignment_notifies_the_new_owner(): void
    {
        $admin = $this->user(RoleName::Admin);
        $telecaller = $this->user();
        $lead = Lead::factory()->create();

        $this->actingAs($admin, 'sanctum');
        $this->postJson("/api/v1/leads/{$lead->id}/assign", ['user_id' => $telecaller->id])->assertOk();

        // FR-NOTIF-01: "lead assigned to me" is one of the required triggers.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $telecaller->id,
            'type' => 'lead_assigned',
        ]);
    }

    #[Test]
    public function notifications_are_never_written_as_messages(): void
    {
        $me = $this->user();
        app(NotificationService::class)->notify($me, 'follow_up_due', 'Due');

        // BR-NOTIF-01: `notifications` targets CRM users and is unrelated to
        // `messages`, which targets leads and IS DNC-gated. Conflating them
        // would put staff alerts through the suppression list.
        $this->assertDatabaseCount('messages', 0);
        $this->assertSame(1, Notification::count());
    }
}
