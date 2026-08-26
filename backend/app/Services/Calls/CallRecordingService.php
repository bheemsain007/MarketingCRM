<?php

namespace App\Services\Calls;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\AuditLog;
use App\Models\Call;
use App\Models\CallRecording;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Call recordings — the server half (Phase 11, FR-REC-02..05, BR-REC-01/02/03).
 *
 * **ADR-B-agnostic, like the Phase 9 and 10 server halves.** Whatever ends up
 * capturing the audio - the Android client (Phases 31/32), a provider, or an
 * operator uploading a file by hand - the server needs the same four things:
 * somewhere private to put it, a record linking it to the call, an authorised
 * way to play it back, and a way to delete it when its retention runs out. None
 * of that changes with how ADR-B lands, which is why it can be built while T-44
 * is still open. The device half is NOT here and is still blocked.
 *
 * The audio never becomes publicly addressable. It lands on a private disk with
 * no `url`, and playback is a short-lived signed route that streams the bytes
 * through an authorised, audited request (BR-REC-01, SEC-FILE-02/03/04).
 */
class CallRecordingService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Accepts an uploaded recording for a call (FR-REC-02/03).
     *
     * Write-once, like the call outcome it belongs to: a second upload is
     * refused rather than silently replacing evidence somebody may already have
     * listened to. The unique index on `call_id` is the real guarantee - this
     * check only turns the race into a clean 409.
     *
     * A repeat upload of the SAME file is not an error though: an offline
     * handset that is unsure whether its upload landed retries it (FR-REC-02),
     * and the checksum tells us it is the same bytes, so the existing row is
     * returned unchanged.
     *
     * @param  array<string, mixed>  $meta  duration_seconds, device_captured_at
     *
     * @throws ApiException when a different recording already exists
     */
    public function attach(Call $call, UploadedFile $file, ?User $actor = null, array $meta = []): CallRecording
    {
        $checksum = hash_file('sha256', $file->getRealPath()) ?: null;
        $existing = CallRecording::where('call_id', $call->id)->first();

        if ($existing !== null && $existing->storage_path !== null) {
            // Idempotent retry of the same bytes (FR-REC-02).
            if ($checksum !== null && $existing->checksum === $checksum) {
                return $existing;
            }

            throw new ApiException(
                ErrorCode::Conflict,
                'This call already has a recording.',
            );
        }

        $disk = (string) config('crm.recordings.disk', 'recordings');

        // Foldered by call id rather than dumped flat: a shared-hosting
        // filesystem with a hundred thousand files in one directory is slow to
        // even list (T-03).
        $path = $file->store(sprintf('calls/%d', $call->id), $disk);

        if ($path === false) {
            $this->notifyUploadFailed($call, $actor);

            throw new ApiException(
                ErrorCode::ServerError,
                'The recording could not be stored.',
            );
        }

        return DB::transaction(function () use ($call, $file, $actor, $meta, $checksum, $disk, $path, $existing) {
            $attributes = [
                'call_id' => $call->id,
                'lead_id' => $call->lead_id,
                // Whose call it was, not who uploaded it - the device uploads as
                // the telecaller, but a full-scope user may upload on their behalf.
                'user_id' => $call->user_id ?? $actor?->id,
                'storage_disk' => $disk,
                'storage_path' => $path,
                'file_size_bytes' => $file->getSize(),
                'mime_type' => $file->getClientMimeType(),
                'checksum' => $checksum,
                'duration_seconds' => isset($meta['duration_seconds'])
                    ? max(0, (int) $meta['duration_seconds'])
                    // Falls back to the call's own duration: the audio is of
                    // that call, so its length is a reasonable default.
                    : ($call->duration_seconds ?: null),
                'device_captured_at' => $meta['device_captured_at'] ?? null,
                'upload_status' => 'uploaded',
                'failure_reason' => null,
                'uploaded_at' => now(),
                'expires_at' => $this->expiryDate(),
            ];

            if ($existing !== null) {
                // A row the device created as `pending` before it had the file.
                $existing->forceFill($attributes)->save();

                return $existing->fresh();
            }

            return CallRecording::create($attributes);
        });
    }

    /**
     * Tells the telecaller whose call it was that their recording did not
     * make it to storage (FR-NOTIF-01, BR-NOTIF-02) - without this, a call
     * with no evidence is discovered only when someone later goes looking
     * for a recording that never arrived.
     *
     * Same owner resolution `attach()` uses when writing the row: the call's
     * own `user_id`, falling back to whoever uploaded on their behalf.
     */
    private function notifyUploadFailed(Call $call, ?User $actor): void
    {
        $userId = $call->user_id ?? $actor?->id;
        $user = $userId !== null ? User::find($userId) : null;

        if ($user === null) {
            return;
        }

        $this->notifications->notify(
            $user,
            'recording_upload_failed',
            'Recording upload failed for call #'.$call->id,
            ['reference' => $call],
        );
    }

    /**
     * Records that no recording will arrive for this call (BR-REC-03).
     *
     * Android 10+ blocks third-party call recording on most handsets (T-44).
     * When that happens the call still logged normally and the absence is a
     * fact worth storing, not an error: without this row, "was this call
     * recorded?" is indistinguishable from "did the upload fail?".
     */
    public function markUnavailable(Call $call, string $reason): CallRecording
    {
        return CallRecording::updateOrCreate(
            ['call_id' => $call->id],
            [
                'lead_id' => $call->lead_id,
                'user_id' => $call->user_id,
                'upload_status' => 'unavailable',
                'failure_reason' => mb_substr($reason, 0, 255),
                'storage_path' => null,
            ],
        );
    }

    /**
     * A short-lived URL for playback (SEC-FILE-03).
     *
     * Signed AND authenticated: the signature stops the URL being useful after
     * it leaks or expires, and the route's own auth stops it being useful to a
     * stranger who obtains it before then. Neither alone is enough.
     */
    public function playbackUrl(CallRecording $recording): ?string
    {
        if (! $recording->isPlayable()) {
            return null;
        }

        return URL::temporarySignedRoute(
            'api.v1.calls.recording.audio',
            now()->addMinutes((int) config('crm.recordings.signed_url_minutes', 10)),
            ['call' => $recording->call_id],
        );
    }

    /**
     * Deletes recordings past their retention and audits each one (BR-REC-02,
     * FR-REC-05, SEC-PII-05).
     *
     * The audio goes; the ROW stays, with its path nulled and its status set to
     * `purged`. "There was a recording and it was deleted on this date" is a
     * different and auditable fact from "there was never a recording" - and
     * destroying the row would destroy exactly the evidence a retention
     * question needs.
     *
     * @return int how many were purged
     */
    public function purgeExpired(): int
    {
        $purged = 0;

        CallRecording::expired()
            ->whereNotNull('storage_path')
            ->chunkById(100, function ($recordings) use (&$purged) {
                foreach ($recordings as $recording) {
                    $this->purge($recording);
                    $purged++;
                }
            });

        return $purged;
    }

    private function purge(CallRecording $recording): void
    {
        $this->deleteAudio(
            $recording,
            null,
            'recording_purged',
            sprintf('Recording for call #%d purged on retention.', $recording->call_id),
        );
    }

    /**
     * Deletes a recording's audio because somebody asked (FR-REC-05, SEC-PII-05).
     *
     * Separate from the retention sweep only in WHO deleted it and WHY: the
     * bytes go the same way and the row survives the same way. Gated on
     * `recordings.delete`, which is a different permission from listening -
     * being allowed to hear a recording is not being allowed to destroy it.
     */
    public function deleteManually(CallRecording $recording, ?User $actor = null): void
    {
        $this->deleteAudio(
            $recording,
            $actor?->id,
            'recording_deleted',
            sprintf('Recording for call #%d deleted on request.', $recording->call_id),
        );
    }

    /**
     * Removes the bytes and leaves the row, audited.
     *
     * Shared by the retention sweep and a deliberate deletion so there is one
     * definition of what "deleted" means to a recording - the audit action is
     * what tells the two apart afterwards.
     */
    private function deleteAudio(CallRecording $recording, ?int $actorId, string $action, string $description): void
    {
        $disk = Storage::disk($recording->storage_disk);

        if ($recording->storage_path !== null && $disk->exists($recording->storage_path)) {
            $disk->delete($recording->storage_path);
        }

        DB::transaction(function () use ($recording, $actorId, $action, $description) {
            $recording->forceFill([
                'storage_path' => null,
                'upload_status' => 'purged',
                'failure_reason' => null,
            ])->save();

            // Audited because the requirement says so, and because a deletion
            // nobody can account for is worse than no deletion.
            AuditLog::create([
                'user_id' => $actorId,
                'action' => $action,
                'description' => $description,
                'new_values' => [
                    'call_recording_id' => $recording->id,
                    'call_id' => $recording->call_id,
                    'expired_at' => $recording->expires_at?->toIso8601String(),
                ],
            ]);
        });
    }

    /**
     * When a recording uploaded now should be deleted (BR-REC-02).
     *
     * Stored rather than computed at purge time, so changing the retention
     * later does not silently re-date recordings captured under the old policy.
     * A retention of 0 or less is read as "keep indefinitely" - which
     * BR-REC-02 says is not the default, and it is not: the default is 365 days
     * (T-37 still owes the real per-data-class answer).
     */
    private function expiryDate(): ?Carbon
    {
        $days = (int) config('crm.recordings.retention_days', 365);

        return $days > 0 ? now()->addDays($days) : null;
    }
}
