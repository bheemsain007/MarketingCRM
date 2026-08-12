<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Calls\StoreCallRecordingRequest;
use App\Http\Resources\CallRecordingResource;
use App\Models\Call;
use App\Services\Calls\CallRecordingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Call recordings — the server half (Phase 11, FR-REC-02..05, BR-REC-01/02/03).
 *
 * ADR-B-agnostic: this is the storage, playback and retention side, which every
 * dialling mechanism needs identically. The device half - actually capturing
 * audio on an Android handset - is Phases 31/32 and is still blocked on T-44.
 *
 * **Access is audited without any code here.** `recordings.listen` is in
 * `Permission::isAudited()`, so `EnsurePermission` writes the access record
 * before either read action runs (SEC-FILE-04). Logging again in the controller
 * would double-count it.
 */
class CallRecordingController extends Controller
{
    public function __construct(private readonly CallRecordingService $recordings) {}

    /**
     * Receives a recording for a call (FR-REC-02/03).
     *
     * Gated as the call's OUTCOME is: only the telecaller who made the call, or
     * a full-scope user cleaning up after a device. Somebody else attaching
     * audio to a colleague's call would be putting evidence on a record they do
     * not own.
     */
    public function store(StoreCallRecordingRequest $request, Call $call): JsonResponse
    {
        $this->authorize('recordOutcome', $call);

        $recording = $this->recordings->attach(
            $call,
            $request->file('file'),
            $request->user(),
            $request->validated(),
        );

        return ApiResponse::created(
            new CallRecordingResource($recording, $this->recordings->playbackUrl($recording)),
            'Recording uploaded.',
        );
    }

    /**
     * Recording metadata, including a short-lived signed playback URL.
     *
     * A call with no recording is a 404 rather than an error state: on most
     * modern Android handsets recording is blocked outright, and the call still
     * logged normally (BR-REC-03, T-44). Absence is expected, not a fault.
     */
    public function show(Request $request, Call $call): JsonResponse
    {
        $this->authorize('view', $call);

        $recording = $call->recording;

        if ($recording === null) {
            throw new ApiException(ErrorCode::NotFound, 'This call has no recording.');
        }

        return ApiResponse::success(
            new CallRecordingResource($recording, $this->recordings->playbackUrl($recording)),
            'Recording retrieved.',
        );
    }

    /**
     * Streams the audio (BR-REC-01, SEC-FILE-02/03).
     *
     * The route is signed AND authenticated, deliberately. The signature makes
     * the URL expire, so one copied out of a browser's network tab or a proxy
     * log is worthless minutes later; the auth and policy make it worthless to
     * anyone else even before then. Neither alone gives what BR-REC-01 asks for.
     *
     * Streamed rather than read into memory: a shared-hosting PHP process has a
     * memory_limit an hour of audio can exceed (T-03).
     */
    public function audio(Request $request, Call $call): StreamedResponse
    {
        $this->authorize('view', $call);

        $recording = $call->recording;

        if ($recording === null || ! $recording->isPlayable()) {
            // Purged on retention, never captured, or still uploading - all
            // "no bytes to serve", and the metadata endpoint says which.
            abort(404, 'This call has no playable recording.');
        }

        return Storage::disk($recording->storage_disk)->download(
            $recording->storage_path,
            sprintf('call-%d-recording.%s', $call->id, pathinfo($recording->storage_path, PATHINFO_EXTENSION) ?: 'audio'),
        );
    }
}
