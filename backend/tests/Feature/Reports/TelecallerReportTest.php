<?php

namespace Tests\Feature\Reports;

use App\Enums\CallStatus;
use App\Enums\LeadStatus;
use App\Enums\RoleName;
use App\Models\Call;
use App\Models\Lead;
use App\Models\Role;
use App\Models\User;
use App\Models\UserWorkSession;
use App\Services\Settings\SettingsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Telecaller performance (Phase 26, FR-RPT-01/04/06, GLOSSARY §2.2/2.3/2.6).
 *
 * These numbers get read as judgements about people, so the tests that matter
 * are the ones pinning the formulas against their plausible-looking wrong
 * versions - the average divided by attempts, the idle time that counts note-
 * writing as slacking, the rate with no denominator.
 */
class TelecallerReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['crm.attribution.model' => 'last_owner']);
    }

    private function user(RoleName $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', $role->value)->first());

        return $user->fresh();
    }

    private function report(User $viewer): array
    {
        $this->actingAs($viewer, 'sanctum');

        return $this->getJson('/api/v1/reports/telecallers')->assertOk()->json('data');
    }

    private function rowFor(array $report, User $user): ?array
    {
        foreach ($report['rows'] as $row) {
            if ($row['user']['id'] === $user->id) {
                return $row;
            }
        }

        return null;
    }

    // -----------------------------------------------------------------------
    // The formulas most likely to be written wrong
    // -----------------------------------------------------------------------

    #[Test]
    public function average_call_duration_divides_by_connected_calls_not_attempts(): void
    {
        $agent = $this->user(RoleName::Telecaller);
        $lead = Lead::factory()->create();

        // One 120-second connection and three that never connected.
        Call::factory()->create([
            'user_id' => $agent->id, 'lead_id' => $lead->id,
            'status' => CallStatus::Connected->value, 'duration_seconds' => 120,
            'started_at' => now(),
        ]);
        Call::factory()->count(3)->create([
            'user_id' => $agent->id, 'lead_id' => $lead->id,
            'status' => CallStatus::NoAnswer->value, 'duration_seconds' => 0,
            'started_at' => now(),
        ]);

        $row = $this->rowFor($this->report($this->user(RoleName::Manager)), $agent);

        /*
         * 120, not 30. Dividing by attempts silently punishes whoever was
         * handed a list of dead numbers - the agent did not make those numbers
         * unreachable (GLOSSARY §2.2).
         */
        $this->assertSame(120, $row['calls']['average_call_seconds']);
        $this->assertSame(4, $row['calls']['attempts']);
        $this->assertSame(1, $row['calls']['connected']);
    }

    #[Test]
    public function talk_time_counts_connected_calls_only(): void
    {
        $agent = $this->user(RoleName::Telecaller);

        Call::factory()->create([
            'user_id' => $agent->id, 'status' => CallStatus::Connected->value,
            'duration_seconds' => 90, 'started_at' => now(),
        ]);
        // A ringing call with a duration recorded is still not work done.
        Call::factory()->create([
            'user_id' => $agent->id, 'status' => CallStatus::NoAnswer->value,
            'duration_seconds' => 45, 'started_at' => now(),
        ]);

        $row = $this->rowFor($this->report($this->user(RoleName::Manager)), $agent);

        $this->assertSame(90, $row['calls']['talk_time_seconds']);
    }

    #[Test]
    public function connect_rate_and_contact_rate_answer_different_questions(): void
    {
        $agent = $this->user(RoleName::Telecaller);
        $lead = Lead::factory()->create();

        // Four dials to ONE lead, two of which connected.
        Call::factory()->count(2)->create([
            'user_id' => $agent->id, 'lead_id' => $lead->id,
            'status' => CallStatus::Connected->value, 'started_at' => now(),
        ]);
        Call::factory()->count(2)->create([
            'user_id' => $agent->id, 'lead_id' => $lead->id,
            'status' => CallStatus::NoAnswer->value, 'started_at' => now(),
        ]);

        $row = $this->rowFor($this->report($this->user(RoleName::Manager)), $agent);

        // Call-level: half the dials got through.
        $this->assertEquals(50.0, $row['calls']['connect_rate']['value']);
        // Lead-level: the one person was reached. Redials flatter the first
        // number and must not flatter this one.
        $this->assertEquals(100.0, $row['calls']['contact_rate']['value']);
        $this->assertSame(1, $row['calls']['unique_leads_touched']);
    }

    #[Test]
    public function idle_time_does_not_count_note_writing_as_slacking(): void
    {
        $agent = $this->user(RoleName::Telecaller);

        UserWorkSession::create([
            'user_id' => $agent->id,
            'started_at' => now()->subHours(2),
            'ended_at' => now(),
            'active_seconds' => 5400,   // 90 minutes working
            'break_seconds' => 900,     // 15 minutes break
            'source' => 'web',
        ]);

        $row = $this->rowFor($this->report($this->user(RoleName::Manager)), $agent);

        // 7200 − 5400 − 900 = 900. Idle is NOT "time not on a call": somebody
        // writing notes is working (GLOSSARY §2.3).
        $this->assertSame(7200, $row['time']['logged_in_seconds']);
        $this->assertSame(900, $row['time']['idle_seconds']);
        $this->assertSame(900, $row['time']['break_seconds']);
    }

    #[Test]
    public function every_rate_carries_its_denominator(): void
    {
        $agent = $this->user(RoleName::Telecaller);
        $row = $this->rowFor($this->report($this->user(RoleName::Manager)), $agent);

        // FR-RPT-06 made structural by the Rate object - no endpoint can return
        // a bare percentage.
        foreach ([$row['calls']['connect_rate'], $row['calls']['contact_rate'], $row['outcomes']['conversion_rate']] as $rate) {
            $this->assertArrayHasKey('denominator', $rate);
            $this->assertArrayHasKey('of', $rate);
        }
    }

    #[Test]
    public function a_rate_with_nothing_to_divide_by_is_null_not_zero(): void
    {
        $agent = $this->user(RoleName::Telecaller);
        $row = $this->rowFor($this->report($this->user(RoleName::Manager)), $agent);

        // "Made no calls" and "made calls and connected none" are different
        // facts about a person, and 0% would say the second about the first.
        $this->assertNull($row['calls']['connect_rate']['value']);
        $this->assertNull($row['calls']['average_call_seconds']);
    }

    // -----------------------------------------------------------------------
    // Attribution (GLOSSARY §2.6, T-24)
    // -----------------------------------------------------------------------

    #[Test]
    public function by_default_the_owner_at_conversion_is_credited(): void
    {
        $prospector = $this->user(RoleName::Telecaller);
        $closer = $this->user(RoleName::Telecaller);

        $lead = Lead::factory()->create([
            'status' => LeadStatus::Converted->value,
            'assigned_to' => $closer->id,
        ]);

        DB::table('lead_status_history')->insert([
            ['lead_id' => $lead->id, 'to_status' => LeadStatus::Interested->value,
                'changed_by' => $prospector->id, 'created_at' => now()->subDay()],
            ['lead_id' => $lead->id, 'to_status' => LeadStatus::Converted->value,
                'changed_by' => $closer->id, 'created_at' => now()],
        ]);

        $report = $this->report($this->user(RoleName::Manager));

        $this->assertSame('last_owner', $report['attribution']);
        $this->assertSame(1, $this->rowFor($report, $closer)['outcomes']['converted']);
        $this->assertSame(0, $this->rowFor($report, $prospector)['outcomes']['converted']);
    }

    #[Test]
    public function switching_the_model_credits_the_prospector_instead(): void
    {
        $prospector = $this->user(RoleName::Telecaller);
        $closer = $this->user(RoleName::Telecaller);

        $lead = Lead::factory()->create([
            'status' => LeadStatus::Converted->value,
            'assigned_to' => $closer->id,
        ]);

        DB::table('lead_status_history')->insert([
            ['lead_id' => $lead->id, 'to_status' => LeadStatus::Interested->value,
                'changed_by' => $prospector->id, 'created_at' => now()->subDay()],
            ['lead_id' => $lead->id, 'to_status' => LeadStatus::Converted->value,
                'changed_by' => $closer->id, 'created_at' => now()],
        ]);

        // A settings change, not a deploy - the model has to be switchable
        // because it has not been signed off (T-24).
        app(SettingsService::class)->set('crm.attribution.model', 'first_interest');

        $report = $this->report($this->user(RoleName::Manager));

        $this->assertSame('first_interest', $report['attribution']);
        $this->assertSame(1, $this->rowFor($report, $prospector)['outcomes']['converted']);
        $this->assertSame(0, $this->rowFor($report, $closer)['outcomes']['converted']);
    }

    #[Test]
    public function an_unrecognised_attribution_model_falls_back_rather_than_zeroing_everyone(): void
    {
        $agent = $this->user(RoleName::Telecaller);
        Lead::factory()->create(['status' => LeadStatus::Converted->value, 'assigned_to' => $agent->id]);

        config(['crm.attribution.model' => 'weighted_by_moon_phase']);

        // A typo in a setting must not silently zero everyone's pay.
        $this->assertSame('last_owner', $this->report($this->user(RoleName::Manager))['attribution']);
    }

    #[Test]
    public function the_report_says_which_attribution_model_produced_it(): void
    {
        $report = $this->report($this->user(RoleName::Manager));

        // "Who converted this?" has more than one defensible answer. A report
        // that does not say which one it used invites an argument nobody can
        // settle.
        $this->assertArrayHasKey('attribution', $report);
    }

    // -----------------------------------------------------------------------
    // Who may read it
    // -----------------------------------------------------------------------

    #[Test]
    public function a_telecaller_cannot_read_the_performance_report(): void
    {
        // reports.business is money; reports.telecaller is people. A telecaller
        // holds neither, and holding `reports.view` is not the same thing.
        $this->actingAs($this->user(RoleName::Telecaller), 'sanctum');

        $this->getJson('/api/v1/reports/telecallers')->assertStatus(403);
    }

    #[Test]
    public function accounts_can_read_business_reports_but_not_people_ones(): void
    {
        $this->actingAs($this->user(RoleName::Accounts), 'sanctum');

        $this->getJson('/api/v1/reports/summary')->assertOk();
        $this->getJson('/api/v1/reports/telecallers')->assertStatus(403);
    }
}
