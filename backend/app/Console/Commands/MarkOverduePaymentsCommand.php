<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentStatusHistory;
use App\Services\Notifications\NotificationService;
use Illuminate\Console\Command;

/**
 * Marks unsettled payments overdue (BR-PAY-06, FR-NOTIF-01).
 *
 * Time-derived and scheduler-owned, never set by hand - the same argument as
 * `Missed` on follow-ups. A user able to mark their own late payments as
 * anything they like makes the collections report worthless.
 *
 * Only `Pending` and `Partial` are swept. `Paid`, `Failed` and `Refund` are not
 * awaiting money, and the matrix forbids those moves anyway.
 */
class MarkOverduePaymentsCommand extends Command
{
    protected $signature = 'crm:mark-overdue-payments {--dry-run : Report without writing}';

    protected $description = 'Flag payments past their due date as overdue';

    public function handle(NotificationService $notifications): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $due = Payment::query()
            ->with(['sale.soldBy', 'customer:id,name'])
            ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Partial->value])
            ->whereNotNull('due_on')
            ->whereDate('due_on', '<', now()->toDateString())
            ->get();

        if ($dryRun) {
            $this->info(sprintf('[dry run] %d payment(s) would be flagged overdue.', $due->count()));

            return self::SUCCESS;
        }

        foreach ($due as $payment) {
            $from = $payment->status;

            $payment->forceFill(['status' => PaymentStatus::Overdue])->save();

            // Append-only, like every other status change (BR-PAY-02).
            PaymentStatusHistory::create([
                'payment_id' => $payment->id,
                'from_status' => $from->value,
                'to_status' => PaymentStatus::Overdue->value,
                'amount_at_change' => $payment->amount,
                'changed_by' => null,
                'source' => 'system',
                'reason' => 'Past due date without full settlement.',
                'created_at' => now(),
            ]);

            // BR-NOTIF-02 lists "payment overdue" as a trigger. Told to whoever
            // made the sale - they have the relationship, and an overdue
            // payment nobody is told about is one nobody chases.
            if ($owner = $payment->sale?->soldBy) {
                $notifications->notify(
                    $owner,
                    'payment_overdue',
                    'Payment overdue: '.($payment->customer?->name ?? 'customer'),
                    [
                        'body' => sprintf('%s %s was due on %s.',
                            $payment->currency,
                            $payment->amount,
                            $payment->due_on?->format('d M Y'),
                        ),
                        'reference' => $payment,
                        'action_url' => '/leads/'.$payment->lead_id,
                    ],
                );
            }
        }

        $this->info(sprintf('%d payment(s) flagged overdue.', $due->count()));

        return self::SUCCESS;
    }
}
