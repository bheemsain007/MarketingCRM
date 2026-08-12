<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Services\Interest\InterestEngine;
use Illuminate\Console\Command;

/**
 * Re-derives score and temperature for leads that have gone quiet
 * (BR-SCORE-01 decay, BR-TEMP-02).
 *
 * Decay is the absence of events, so nothing triggers it on its own - without
 * this sweep a lead scored 85 six months ago still reads Hot on every list, and
 * the Hot view fills with people who stopped answering in March.
 *
 * Only leads with signals are touched. A lead nobody has ever interacted with
 * has a score of zero and recomputing it every night achieves nothing.
 */
class DecayLeadScoresCommand extends Command
{
    protected $signature = 'crm:decay-lead-scores
                            {--chunk=200 : Rows per batch}
                            {--dry-run : Report without writing}';

    protected $description = 'Recalculate lead scores and temperature, applying decay for inactivity';

    public function handle(InterestEngine $engine): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $changed = 0;
        $seen = 0;

        Lead::query()
            ->whereHas('interestSignals')
            // Chunked by id: the sweep updates the rows it is reading, and an
            // offset-paginated cursor would skip records as they move.
            ->chunkById((int) $this->option('chunk'), function ($leads) use ($engine, $dryRun, &$changed, &$seen) {
                foreach ($leads as $lead) {
                    $seen++;

                    $before = [$lead->score, $lead->temperature->value];

                    if ($dryRun) {
                        continue;
                    }

                    $after = $engine->recalculate($lead);

                    if ([$after->score, $after->temperature->value] !== $before) {
                        $changed++;
                    }
                }
            });

        $this->info(sprintf(
            '%s%d lead(s) examined, %d changed.',
            $dryRun ? '[dry run] ' : '',
            $seen,
            $changed,
        ));

        return self::SUCCESS;
    }
}
