<?php

namespace Tests\Feature\Calls;

use App\Enums\RoleName;
use App\Models\Call;
use App\Models\CallRecording;
use App\Models\Lead;
use App\Models\Role;
use App\Models\User;
use App\Services\Calls\CallRecordingService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Call recordings — the server half (Phase 11, FR-REC-02..05, BR-REC-01/02/03).
 *
 * What is worth proving here is not that a file uploads. It is that the audio
 * cannot be reached without authority or after its URL expires (BR-REC-01), that
 * a second upload cannot overwrite evidence, that a device retrying an upload it
 * is unsure of does not create a duplicate (FR-REC-02), and that retention
 * actually deletes bytes while leaving the auditable record behind (BR-REC-02).
 */
class CallRecordingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('recordings');
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

    private function callFor(User $owner): Call
    {
        return Call::factory()->for(Lead::factory())->create([
            'user_id' => $owner->id,
            'duration_seconds' => 90,
        ]);
    }

    /**
     * Real bytes, not `fake()->create()`.
     *
     * `create()` reports a size but writes an EMPTY file, so two "different"
     * uploads hash identically - and the checksum path (FR-REC-02) would then be
     * testing nothing. The content is what distinguishes one recording from
     * another here, exactly as it does for a real handset.
     */
    private function audio(string $name = 'call.m4a', string $content = 'AUDIO-ONE'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    // -----------------------------------------------------------------------
    // Upload (FR-REC-02/03)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_telecaller_can_upload_a_recording_for_their_own_call(): void
    {
        $me = $this->actingAsRole(RoleName::Telecaller);
        $call = $this->callFor($me);

        $this->postJson("/api/v1/calls/{$call->id}/recording", [
            'file' => $this->audio(),
            'duration_seconds' => 88,
        ])->assertStatus(201)
            ->assertJsonPath('data.upload_status', 'uploaded')
            ->assertJsonPath('data.duration_seconds', 88)
            // The disk path is never serialised - publishing it maps where the
            // audio lives (BR-REC-01, SEC-FILE-02).
            ->assertJsonMissingPath('data.storage_path');

        $recording = CallRecording::firstOrFail();
        $this->assertSame($call->id, $recording->call_id);
        Storage::disk('recordings')->assertExists($recording->storage_path);

        // Retention is stamped at upload time, not computed at purge time
        // (BR-REC-02).
        $this->assertNotNull($recording->expires_at);
    }

    #[Test]
    public function a_telecaller_cannot_upload_onto_a_colleagues_call(): void
    {
        $colleague = $this->user(RoleName::Telecaller);
        $call = $this->callFor($colleague);

        // Attaching audio to somebody else's call is putting evidence on a
        // record you do not own - the same gate as writing its outcome.
        $this->actingAsRole(RoleName::Telecaller);

        $this->postJson("/api/v1/calls/{$call->id}/recording", ['file' => $this->audio()])
            ->assertStatus(403);

        $this->assertDatabaseCount('call_recordings', 0);
    }

    #[Test]
    public function a_second_recording_is_refused_rather_than_replacing_the_first(): void
    {
        $me = $this->actingAsRole(RoleName::Telecaller);
        $call = $this->callFor($me);

        $this->postJson("/api/v1/calls/{$call->id}/recording", ['file' => $this->audio('first.m4a')])
            ->assertStatus(201);

        // Write-once, like the call outcome: silently replacing audio somebody
        // may already have listened to is worse than refusing. Different bytes,
        // so this is a genuine second recording rather than a retry.
        $this->postJson("/api/v1/calls/{$call->id}/recording", ['file' => $this->audio('second.m4a', 'AUDIO-TWO')])
            ->assertStatus(409);

        $this->assertDatabaseCount('call_recordings', 1);
    }

    #[Test]
    public function re_uploading_the_same_file_is_idempotent(): void
    {
        $me = $this->actingAsRole(RoleName::Telecaller);
        $call = $this->callFor($me);

        // An offline handset that is unsure whether its upload landed retries it
        // (FR-REC-02). Same bytes, so the checksum recognises it rather than
        // 409-ing a device that did nothing wrong.
        $file = $this->audio('same.m4a');

        $this->postJson("/api/v1/calls/{$call->id}/recording", ['file' => $file])->assertStatus(201);
        $this->postJson("/api/v1/calls/{$call->id}/recording", ['file' => $file])->assertStatus(201);

        $this->assertDatabaseCount('call_recordings', 1);
    }

    #[Test]
    public function a_non_audio_file_is_refused(): void
    {
        $me = $this->actingAsRole(RoleName::Telecaller);
        $call = $this->callFor($me);

        $this->postJson("/api/v1/calls/{$call->id}/recording", [
            'file' => UploadedFile::fake()->create('payload.php', 8, 'text/x-php'),
        ])->assertStatus(422)->assertJsonPath('errors.0.field', 'file');

        $this->assertDatabaseCount('call_recordings', 0);
    }

    // -----------------------------------------------------------------------
    // Access control (BR-REC-01, SEC-FILE-03/04)
    // -----------------------------------------------------------------------

    private function uploadedRecording(User $owner): CallRecording
    {
        $call = $this->callFor($owner);

        return app(CallRecordingService::class)->attach($call, $this->audio(), $owner);
    }

    #[Test]
    public function a_role_holding_recordings_listen_can_read_the_metadata(): void
    {
        $manager = $this->user(RoleName::Manager);
        $recording = $this->uploadedRecording($manager);

        $this->actingAs($manager, 'sanctum');

        $this->getJson("/api/v1/calls/{$recording->call_id}/recording")
            ->assertOk()
            ->assertJsonPath('data.is_playable', true)
            // Playback is a signed, expiring URL and nothing else (SEC-FILE-03).
            ->assertJsonPath('data.audio_url', fn ($url) => is_string($url) && str_contains($url, 'signature='));
    }

    #[Test]
    public function a_role_without_recordings_listen_cannot_read_or_play_it(): void
    {
        $manager = $this->user(RoleName::Manager);
        $recording = $this->uploadedRecording($manager);

        $url = app(CallRecordingService::class)->playbackUrl($recording);

        // A telecaller holds calls.view but NOT recordings.listen - listening to
        // recorded conversations is deliberately a supervisory power. Note this
        // diverges from BR-REC-01's "the owning telecaller and Manager+", which
        // the permission map does not grant - raised as T-66.
        $this->actingAsRole(RoleName::Telecaller);

        $this->getJson("/api/v1/calls/{$recording->call_id}/recording")->assertStatus(403);

        // Even holding a validly signed URL: the signature makes it expire, the
        // permission makes it useless to the wrong person. Both are required.
        $this->get($url)->assertStatus(403);
    }

    #[Test]
    public function an_unsigned_or_tampered_audio_url_is_refused(): void
    {
        $manager = $this->user(RoleName::Manager);
        $recording = $this->uploadedRecording($manager);

        $this->actingAs($manager, 'sanctum');

        // The whole point of signing: the bare route is not a valid address, so
        // a URL guessed or stripped of its signature buys nothing.
        $this->get("/api/v1/calls/{$recording->call_id}/recording/audio")->assertStatus(403);
    }

    #[Test]
    public function an_expired_url_no_longer_plays(): void
    {
        $manager = $this->user(RoleName::Manager);
        $recording = $this->uploadedRecording($manager);

        $url = URL::temporarySignedRoute(
            'api.v1.calls.recording.audio',
            now()->subMinute(),          // already expired
            ['call' => $recording->call_id],
        );

        $this->actingAs($manager, 'sanctum');

        // A URL copied out of a network tab or a proxy log is worthless minutes
        // later - which is what "short-lived" is for (SEC-FILE-03).
        $this->get($url)->assertStatus(403);
    }

    #[Test]
    public function an_authorised_signed_url_streams_the_audio(): void
    {
        $manager = $this->user(RoleName::Manager);
        $recording = $this->uploadedRecording($manager);

        $this->actingAs($manager, 'sanctum');

        $this->get(app(CallRecordingService::class)->playbackUrl($recording))
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    #[Test]
    public function every_recording_access_is_audited(): void
    {
        $manager = $this->user(RoleName::Manager);
        $recording = $this->uploadedRecording($manager);

        $this->actingAs($manager, 'sanctum');
        $this->getJson("/api/v1/calls/{$recording->call_id}/recording")->assertOk();

        // SEC-FILE-04, satisfied structurally: recordings.listen is an audited
        // permission, so the middleware records the access before the controller
        // runs. Nothing in the controller has to remember to log.
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $manager->id,
            'action' => 'permission_used',
            'description' => 'recordings.listen',
        ]);
    }

    // -----------------------------------------------------------------------
    // Absence is not an error (BR-REC-03)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_call_with_no_recording_reports_absence_rather_than_failing(): void
    {
        $manager = $this->user(RoleName::Manager);
        $call = $this->callFor($manager);

        $this->actingAs($manager, 'sanctum');

        // Most modern Android handsets block third-party recording (T-44), so a
        // call without audio is the expected case, not a fault.
        $this->getJson("/api/v1/calls/{$call->id}/recording")->assertStatus(404);
    }

    #[Test]
    public function a_device_that_could_not_record_is_recorded_as_unavailable(): void
    {
        $manager = $this->user(RoleName::Manager);
        $call = $this->callFor($manager);

        app(CallRecordingService::class)->markUnavailable($call, 'OS blocked capture');

        $this->actingAs($manager, 'sanctum');

        // "Never captured" is a different fact from "upload failed", and the API
        // reports which - otherwise the two are indistinguishable.
        $this->getJson("/api/v1/calls/{$call->id}/recording")
            ->assertOk()
            ->assertJsonPath('data.is_unavailable', true)
            ->assertJsonPath('data.is_playable', false)
            ->assertJsonPath('data.audio_url', null);
    }

    // -----------------------------------------------------------------------
    // Retention (BR-REC-02, FR-REC-05)
    // -----------------------------------------------------------------------

    #[Test]
    public function the_purge_deletes_expired_audio_and_leaves_an_audited_record(): void
    {
        $manager = $this->user(RoleName::Manager);
        $recording = $this->uploadedRecording($manager);
        $path = $recording->storage_path;

        $recording->forceFill(['expires_at' => now()->subDay()])->save();

        $this->artisan('crm:purge-recordings')->assertSuccessful();

        Storage::disk('recordings')->assertMissing($path);

        // The row survives its audio: "there was a recording and it was deleted
        // on this date" is the fact a retention question actually asks about.
        $recording->refresh();
        $this->assertSame('purged', $recording->upload_status);
        $this->assertNull($recording->storage_path);

        $this->assertDatabaseHas('audit_logs', ['action' => 'recording_purged']);
    }

    #[Test]
    public function the_purge_leaves_recordings_that_have_not_expired(): void
    {
        $manager = $this->user(RoleName::Manager);
        $recording = $this->uploadedRecording($manager);

        $this->artisan('crm:purge-recordings')->assertSuccessful();

        Storage::disk('recordings')->assertExists($recording->storage_path);
        $this->assertSame('uploaded', $recording->fresh()->upload_status);
    }

    #[Test]
    public function a_dry_run_reports_without_deleting(): void
    {
        $manager = $this->user(RoleName::Manager);
        $recording = $this->uploadedRecording($manager);
        $recording->forceFill(['expires_at' => now()->subDay()])->save();

        $this->artisan('crm:purge-recordings --dry-run')->assertSuccessful();

        Storage::disk('recordings')->assertExists($recording->storage_path);
        $this->assertSame('uploaded', $recording->fresh()->upload_status);
    }
}
