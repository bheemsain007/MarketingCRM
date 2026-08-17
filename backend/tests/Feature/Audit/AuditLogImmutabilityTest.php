<?php

namespace Tests\Feature\Audit;

use App\Exceptions\ImmutableRecordException;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Audit trail immutability (SEC-AUD-01).
 *
 * The model has always CLAIMED to be append-only in its docblock, and nothing
 * enforced it: any caller could update or delete a row. A compliance trail that
 * the application can rewrite is not evidence of anything - the value of an
 * audit log is precisely that the code which is being audited cannot edit it.
 *
 * So these tests assert a structural property rather than a convention: writes
 * still work, and every path that mutates or removes a row refuses. The
 * query-builder cases matter as much as the model ones - `AuditLog::where(...)
 * ->delete()` never fires a model event, so a guard that only hooked
 * `deleting` would leave the easiest bypass open.
 */
class AuditLogImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    private function entry(): AuditLog
    {
        return AuditLog::create([
            'tenant_id' => config('crm.default_tenant_id'),
            'user_id' => User::factory()->create()->id,
            'action' => 'login',
            'description' => 'Signed in.',
            'ip_address' => '127.0.0.1',
        ]);
    }

    #[Test]
    public function an_audit_entry_can_still_be_written(): void
    {
        // The control must not break the eight services that call
        // AuditLog::create() - an immutability rule that also stops the writes
        // would silently end auditing altogether, which is worse than the bug.
        $entry = $this->entry();

        $this->assertDatabaseHas('audit_logs', ['id' => $entry->id, 'action' => 'login']);
    }

    #[Test]
    public function an_audit_entry_refuses_to_be_updated(): void
    {
        $entry = $this->entry();

        $this->expectException(ImmutableRecordException::class);

        $entry->update(['action' => 'something_less_incriminating']);
    }

    #[Test]
    public function a_refused_update_leaves_the_stored_row_untouched(): void
    {
        // Refusing loudly is only half of it: the row on disk must still say
        // what it said. A guard that threw AFTER writing would be theatre.
        $entry = $this->entry();

        try {
            $entry->update(['action' => 'something_less_incriminating']);
        } catch (ImmutableRecordException) {
            // Expected - asserted on its own above.
        }

        $this->assertDatabaseHas('audit_logs', ['id' => $entry->id, 'action' => 'login']);
    }

    #[Test]
    public function an_audit_entry_refuses_to_be_deleted(): void
    {
        $entry = $this->entry();

        $this->expectException(ImmutableRecordException::class);

        $entry->delete();
    }

    #[Test]
    public function a_refused_delete_leaves_the_row_in_place(): void
    {
        $entry = $this->entry();

        try {
            $entry->delete();
        } catch (ImmutableRecordException) {
            // Expected - asserted on its own above.
        }

        $this->assertDatabaseCount('audit_logs', 1);
    }

    #[Test]
    public function a_mass_update_through_the_query_builder_is_refused(): void
    {
        $this->entry();

        // Model events never fire for this path, which makes it the obvious way
        // round a guard hooked only to `updating` - and the way somebody
        // covering their tracks would reach for first.
        $this->expectException(ImmutableRecordException::class);

        AuditLog::query()->where('action', 'login')->update(['action' => 'noop']);
    }

    #[Test]
    public function a_mass_delete_through_the_query_builder_is_refused(): void
    {
        $this->entry();

        $this->expectException(ImmutableRecordException::class);

        AuditLog::query()->where('action', 'login')->delete();
    }

    #[Test]
    public function an_upsert_cannot_be_used_to_overwrite_an_existing_entry(): void
    {
        $entry = $this->entry();

        // `upsert` is an update wearing an insert's clothes: it would rewrite a
        // matched row without ever loading the model.
        $this->expectException(ImmutableRecordException::class);

        AuditLog::query()->upsert(
            [[
                'id' => $entry->id,
                'tenant_id' => $entry->tenant_id,
                'action' => 'noop',
                'created_at' => now(),
            ]],
            ['id'],
            ['action'],
        );
    }
}
