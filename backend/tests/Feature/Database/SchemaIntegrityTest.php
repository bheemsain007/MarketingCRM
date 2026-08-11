<?php

namespace Tests\Feature\Database;

use App\Models\Lead;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Schema-level guarantees (DATABASE_SCHEMA.md).
 *
 * These assert that the DATABASE enforces the rules, not just the application.
 * Application checks can be bypassed by a race condition, a queued job, or a
 * future developer using a different code path; a unique index cannot.
 */
class SchemaIntegrityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_expected_table_exists(): void
    {
        $tables = [
            'users', 'products', 'lead_sources', 'tags',
            'leads', 'lead_products', 'lead_tag', 'lead_notes',
            'lead_status_history', 'lead_assignments', 'lead_activities',
            'dnc_entries', 'calls', 'call_recordings', 'follow_ups',
            'templates', 'campaigns', 'messages', 'campaign_recipients',
            'provider_webhook_logs', 'user_work_sessions', 'user_activity_pings',
            'notifications', 'customers', 'customer_leads', 'audit_logs',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }
    }

    #[Test]
    public function duplicate_phone_numbers_are_rejected_by_the_database(): void
    {
        // BR-DUP-01/02: enforced by a unique index, so a race between two
        // concurrent imports cannot slip a duplicate through.
        Lead::factory()->create(['phone_e164' => '+919876543210']);

        $this->expectException(QueryException::class);

        Lead::factory()->create(['phone_e164' => '+919876543210']);
    }

    #[Test]
    public function the_same_product_cannot_be_attached_to_a_lead_twice(): void
    {
        $lead = Lead::factory()->create();
        $product = Product::factory()->create();

        $lead->leadProducts()->create(['product_id' => $product->id]);

        $this->expectException(QueryException::class);

        $lead->leadProducts()->create(['product_id' => $product->id]);
    }

    #[Test]
    public function a_lead_can_hold_several_products_with_independent_state(): void
    {
        // BR-PROD-01 - the multi-product requirement from the brief.
        $lead = Lead::factory()->create();
        [$news, $epaper, $posting] = Product::factory()->count(3)->create();

        $lead->leadProducts()->createMany([
            ['product_id' => $news->id, 'interest_status' => 'interested', 'temperature' => 'warm'],
            ['product_id' => $epaper->id, 'interest_status' => 'new', 'temperature' => 'cold'],
            ['product_id' => $posting->id, 'interest_status' => 'proposal', 'temperature' => 'hot'],
        ]);

        $this->assertCount(3, $lead->leadProducts);

        // Changing one product must not disturb the others.
        $lead->leadProducts()->where('product_id', $news->id)
            ->update(['interest_status' => 'not_interested']);

        $this->assertSame(
            'new',
            $lead->leadProducts()->where('product_id', $epaper->id)->first()->interest_status->value
        );
        $this->assertSame(
            'proposal',
            $lead->leadProducts()->where('product_id', $posting->id)->first()->interest_status->value
        );
    }

    #[Test]
    public function archiving_a_lead_soft_deletes_and_hides_it_from_default_queries(): void
    {
        // FR-LEAD-01: archived leads leave the working set but remain restorable.
        $lead = Lead::factory()->create();

        $lead->delete();

        $this->assertSoftDeleted('leads', ['id' => $lead->id]);
        $this->assertNull(Lead::find($lead->id));
        $this->assertNotNull(Lead::withTrashed()->find($lead->id));
    }

    #[Test]
    public function tenant_id_is_present_on_core_tables_for_future_multi_tenancy(): void
    {
        // ADR-C: reserved now so Phase 36 does not require migrating every table.
        $tables = [
            'users', 'products', 'leads', 'dnc_entries', 'calls',
            'follow_ups', 'campaigns', 'messages', 'customers',
            'notifications', 'audit_logs', 'user_work_sessions',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(
                Schema::hasColumn($table, 'tenant_id'),
                "Table {$table} is missing the reserved tenant_id column"
            );
        }
    }

    #[Test]
    public function tenant_id_defaults_to_zero_and_is_never_null(): void
    {
        // Regression guard for a bug caught in Phase 2.
        //
        // tenant_id was originally nullable. Because NULL != NULL in SQL, every
        // composite UNIQUE index that includes it - leads(phone), products(code),
        // tags(slug), templates(code), lead_sources(code) - was completely inert
        // while looking correct in the schema. Duplicate leads would have been
        // accepted in production.
        //
        // If a future migration makes tenant_id nullable again, this fails.
        //
        // fresh() is required: the default is applied by the DATABASE, and an
        // in-memory Eloquent instance does not know about column defaults. Only
        // re-reading the row proves the default actually landed.
        $lead = Lead::factory()->create()->fresh();

        $this->assertNotNull($lead->tenant_id);
        $this->assertSame(0, (int) $lead->tenant_id);

        $product = Product::factory()->create()->fresh();
        $this->assertSame(0, (int) $product->tenant_id);
    }

    #[Test]
    public function duplicate_product_codes_are_rejected(): void
    {
        // Same NULL-in-unique-index trap as leads; asserted separately so a
        // regression on one table cannot hide behind another passing.
        Product::factory()->create(['code' => 'NEWS_PORTAL']);

        $this->expectException(QueryException::class);

        Product::factory()->create(['code' => 'NEWS_PORTAL']);
    }

    #[Test]
    public function business_critical_lead_fields_are_not_mass_assignable(): void
    {
        // SEC-IN-06: status/score/suppression/ownership change only through
        // their services. A crafted request must not be able to set them.
        $lead = new Lead;

        foreach (['status', 'temperature', 'score', 'is_suppressed', 'assigned_to'] as $field) {
            $this->assertFalse(
                $lead->isFillable($field),
                "{$field} must not be mass assignable"
            );
        }
    }

    #[Test]
    public function deleting_a_lead_cascades_to_its_child_records(): void
    {
        $lead = Lead::factory()->create();
        $user = User::factory()->create();

        $lead->notes()->create(['user_id' => $user->id, 'body' => 'Called, interested']);
        $noteId = $lead->notes()->first()->id;

        // Force delete bypasses the soft delete to exercise the FK cascade.
        $lead->forceDelete();

        $this->assertDatabaseMissing('lead_notes', ['id' => $noteId]);
    }

    #[Test]
    public function a_product_with_lead_interest_cannot_be_hard_deleted(): void
    {
        // RESTRICT on the FK: deleting a product would orphan interest history
        // and silently corrupt product-performance reporting.
        $lead = Lead::factory()->create();
        $product = Product::factory()->create();
        $lead->leadProducts()->create(['product_id' => $product->id]);

        $this->expectException(QueryException::class);

        $product->forceDelete();
    }
}
