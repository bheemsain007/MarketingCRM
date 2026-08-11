<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\Dnc\DncService;
use App\Services\Messaging\MessageDriverManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Hands one queued message to its provider (FR-COMM-01/03/06).
 *
 * Takes an id rather than the model: a serialised model in a payload is a
 * snapshot, and this job may run minutes after it was queued. The row is
 * re-read so the send acts on current state.
 */
class SendMessage implements ShouldQueue
{
    use Queueable;

    /** Three attempts with widening gaps - a provider blip should not lose a message. */
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 600];

    public function __construct(public readonly int $messageId) {}

    public function handle(MessageDriverManager $drivers, DncService $dnc): void
    {
        $message = Message::with('lead')->find($this->messageId);

        // Deleted between queueing and running - nothing to do, and failing
        // the job would only fill the failed table.
        if ($message === null || $message->status !== 'queued') {
            return;
        }

        /*
         * BR-DNC-03: suppression is checked at DISPATCH time, not only when the
         * message was created. A lead who opts out while a campaign sits in the
         * queue must not receive what was already prepared for them - which is
         * the exact case the rule exists for.
         */
        if ($message->lead && ! $dnc->canContact($message->lead, $message->channel)) {
            $message->update([
                'status' => 'skipped',
                'skip_reason' => 'suppressed_after_queueing',
            ]);

            return;
        }

        $driver = $drivers->for($message->channel);
        $result = $driver->send($message);

        if ($result->accepted) {
            $message->update([
                'status' => 'sent',
                'provider' => $driver->name(),
                'provider_message_id' => $result->providerMessageId,
                'cost' => $result->cost,
                'sent_at' => now(),
            ]);

            return;
        }

        // Retryable and attempts remain: throw so the queue applies backoff and
        // the row stays `queued` for the next try.
        if ($result->retryable && $this->attempts() < $this->tries) {
            throw new \RuntimeException($result->failureReason ?? 'Send failed.');
        }

        $message->update([
            'status' => 'failed',
            'provider' => $driver->name(),
            'failure_reason' => $result->failureReason,
            'failed_at' => now(),
        ]);
    }

    /**
     * Last attempt exhausted. The row must not be left `queued` forever -
     * a message nobody can see the fate of is worse than one that failed
     * loudly (FR-COMM-06).
     */
    public function failed(\Throwable $e): void
    {
        Message::where('id', $this->messageId)
            ->where('status', 'queued')
            ->update([
                'status' => 'failed',
                'failure_reason' => mb_substr($e->getMessage(), 0, 255),
                'failed_at' => now(),
            ]);
    }
}
