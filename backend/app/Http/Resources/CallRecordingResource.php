<?php

namespace App\Http\Resources;

use App\Models\CallRecording;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CallRecording
 *
 * Recording metadata (FR-REC-03).
 *
 * **`storage_disk` and `storage_path` are never serialised.** A client has no
 * use for them and publishing them turns a private disk into a map of where the
 * audio lives (BR-REC-01, SEC-FILE-02). Playback is the signed, expiring
 * `audio_url` and nothing else.
 */
class CallRecordingResource extends JsonResource
{
    public function __construct(CallRecording $resource, private readonly ?string $audioUrl = null)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'call_id' => $this->call_id,
            'lead_id' => $this->lead_id,

            'upload_status' => $this->upload_status,
            // The three "no audio" histories are different facts and are
            // reported as such: never captured (BR-REC-03), still in flight, or
            // deleted on retention (BR-REC-02). A client that showed all three
            // as "no recording" would be hiding two of them.
            'is_playable' => $this->isPlayable(),
            'is_unavailable' => $this->isUnavailable(),
            'is_purged' => $this->isPurged(),
            'failure_reason' => $this->failure_reason,

            'duration_seconds' => $this->duration_seconds,
            'file_size_bytes' => $this->file_size_bytes,
            'mime_type' => $this->mime_type,

            'device_captured_at' => $this->device_captured_at?->toIso8601String(),
            'uploaded_at' => $this->uploaded_at?->toIso8601String(),
            // Surfaced so a UI can warn before the audio disappears (BR-REC-02).
            'expires_at' => $this->expires_at?->toIso8601String(),

            // Short-lived and signed; null when there is nothing to play.
            'audio_url' => $this->audioUrl,

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
