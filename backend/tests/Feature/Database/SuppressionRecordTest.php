<?php

namespace Tests\Feature\Database;

use App\Enums\Channel;
use App\Enums\DncReason;
use App\Models\DncEntry;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Suppression records at the persistence level (BR-DNC-02/06).
 *
 * The full per-channel enforcement matrix is exercised in
 * Tests\Unit\Enums\DncSuppressionMatrixTest; this suite covers how entries
 * behave once stored - channel scoping, deactivation, and the fact that the
 * denormalised flag on `leads` is not authoritative.
 */
class SuppressionRecordTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_entry_without_a_channel_blocks_everything(): void
    {
        $entry = DncEntry::factory()->create([
            'reason' => DncReason::DoNotContact->value,
            'channel' => null,
        ]);

        foreach (Channel::cases() as $channel) {
            $this->assertTrue($entry->blocks($channel), "must block {$channel->value}");
        }
    }

    #[Test]
    public function a_channel_specific_opt_out_blocks_only_that_channel(): void
    {
        // Someone replying STOP to an SMS has not opted out of email.
        $entry = DncEntry::factory()->forChannel(Channel::Sms)->create();

        $this->assertTrue($entry->blocks(Channel::Sms));
        $this->assertFalse($entry->blocks(Channel::Email));
        $this->assertFalse($entry->blocks(Channel::WhatsApp));
    }

    #[Test]
    public function a_wrong_number_entry_leaves_email_reachable(): void
    {
        // BR-DNC-02 - the reason most likely to be implemented as a blunt
        // global flag, which would throw away a working email channel.
        $entry = DncEntry::factory()->wrongNumber()->create();

        $this->assertTrue($entry->blocks(Channel::Call));
        $this->assertTrue($entry->blocks(Channel::Sms));
        $this->assertFalse($entry->blocks(Channel::Email));
    }

    #[Test]
    public function a_deactivated_entry_blocks_nothing(): void
    {
        // BR-DNC-06: removal deactivates rather than deletes, so the audit trail
        // of who un-suppressed a lead survives.
        $entry = DncEntry::factory()->inactive()->create([
            'reason' => DncReason::DoNotContact->value,
        ]);

        foreach (Channel::cases() as $channel) {
            $this->assertFalse($entry->blocks($channel));
        }

        $this->assertDatabaseHas('dnc_entries', ['id' => $entry->id, 'active' => false]);
    }

    #[Test]
    public function the_active_scope_excludes_removed_entries(): void
    {
        $lead = Lead::factory()->create();
        DncEntry::factory()->for($lead)->create();
        DncEntry::factory()->for($lead)->inactive()->create();

        $this->assertCount(2, $lead->dncEntries);
        $this->assertCount(1, $lead->dncEntries()->active()->get());
    }

    #[Test]
    public function a_number_can_be_suppressed_before_any_lead_exists_for_it(): void
    {
        // An inbound STOP from an unknown number must still be honoured if that
        // number is later imported as a lead.
        $entry = DncEntry::factory()->create([
            'lead_id' => null,
            'phone_e164' => '+919876500000',
            'reason' => DncReason::OptedOut->value,
            'source' => 'inbound_keyword',
        ]);

        $this->assertNull($entry->lead_id);
        $this->assertDatabaseHas('dnc_entries', ['phone_e164' => '+919876500000', 'active' => true]);
    }

    #[Test]
    public function the_suppressed_flag_on_leads_is_a_cache_not_the_source_of_truth(): void
    {
        // Documents the trap deliberately: a lead can carry an active
        // suppression record while the denormalised flag is stale. Feature code
        // must ask DncService, which reads dnc_entries - never this column.
        $lead = Lead::factory()->create(['is_suppressed' => false]);
        DncEntry::factory()->for($lead)->create(['reason' => DncReason::DoNotContact->value]);

        $this->assertFalse($lead->fresh()->is_suppressed);
        $this->assertCount(1, $lead->dncEntries()->active()->get());
    }

    #[Test]
    public function multiple_reasons_can_coexist_on_one_lead(): void
    {
        // A lead may be both wrong-number and email-bounced; together they
        // block everything, though neither does alone.
        $lead = Lead::factory()->create();

        DncEntry::factory()->for($lead)->wrongNumber()->create();
        DncEntry::factory()->for($lead)->create([
            'reason' => DncReason::BouncedEmail->value,
            'channel' => null,
        ]);

        $entries = $lead->dncEntries()->active()->get();

        $this->assertTrue($entries->contains(fn ($e) => $e->blocks(Channel::Call)));
        $this->assertTrue($entries->contains(fn ($e) => $e->blocks(Channel::Email)));
    }
}
