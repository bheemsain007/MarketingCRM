<?php

namespace App\Jobs;

use App\Models\ProviderWebhookLog;
use App\Services\Meta\MetaLeadService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Creates a lead from one stored Meta leadgen notification (FR-META-01).
 *
 * Separate from the webhook because the webhook must not do this work inline
 * (SEC-WH-05): fetching from the Graph API, running duplicate detection and
 * auto-assigning takes as long as it takes, and Meta retires a subscription
 * that responds slowly.
 *
 * Takes the LOG id rather than the payload. The raw delivery is already stored
 * for audit (SEC-WH-04), so passing the id keeps one copy of the truth and lets
 * the job record its own outcome against the same row - which is what makes
 * "why is this lead not in the CRM?" answerable six weeks later.
 */
class ProcessMetaLead implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** A Graph outage or a rate limit is transient; widening gaps ride it out. */
    public array $backoff = [30, 120, 600];

    public function __construct(public readonly int $webhookLogId) {}

    public function handle(MetaLeadService $meta): void
    {
        $log = ProviderWebhookLog::find($this->webhookLogId);

        if ($log === null || $log->processed_at !== null) {
            return;
        }

        $log->increment('processing_attempts');

        try {
            $lead = $meta->import((array) $log->payload);
        } catch (Throwable $e) {
            // Rethrow so the queue retries. `failed()` writes the final outcome
            // once the attempts are exhausted - recording it here as well would
            // mark a retryable failure as final on the first try.
            throw $e;
        }

        $log->update([
            'processed_at' => now(),
            'processing_error' => null,
        ]);

        // The created/matched lead id is recorded on the log, so a delivery can
        // be traced to its lead without guessing by timestamp.
        $log->update(['payload' => array_merge((array) $log->payload, ['lead_id' => $lead->id])]);
    }

    /**
     * Attempts exhausted. The delivery must not sit unprocessed with no
     * explanation - a lead that never arrived is a paid click wasted, and
     * somebody has to be able to find out why (FR-COMM-06 in spirit).
     */
    public function failed(Throwable $e): void
    {
        ProviderWebhookLog::where('id', $this->webhookLogId)
            ->whereNull('processed_at')
            ->update([
                'processed_at' => now(),
                'processing_error' => mb_substr($e->getMessage(), 0, 1000),
            ]);
    }
}
