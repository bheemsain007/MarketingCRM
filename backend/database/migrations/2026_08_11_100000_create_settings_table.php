<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Runtime settings and provider credentials (SEC-CFG-01/04, SEC-AUD-02).
 *
 * An OVERRIDE layer, not a replacement for config. `SettingsService::get()`
 * reads this table first and falls back to `config()`, so `.env` remains the
 * source of defaults and an empty table changes nothing. That keeps SEC-CFG-01
 * ("credentials in .env -> config, referenced via config()") true for the
 * default path while giving SEC-CFG-04 ("rotation without code changes") a
 * mechanism - which matters because the production target is Hostinger shared
 * hosting, where editing .env needs SSH that the lower plan tiers do not
 * provide (T-12, DEPLOYMENT §3A).
 *
 * `value` is ALWAYS encrypted, not only for secrets. Conditional encryption
 * means one forgotten `is_secret` flag writes an API key to the database in
 * clear text, and the cost of encrypting an integer is nothing. Nothing is ever
 * queried by value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(0)->index();

            // Dotted, and identical to the config key it overrides -
            // 'crm.assignment.open_lead_cap' overrides config('crm.assignment.open_lead_cap').
            $table->string('key', 191);

            // Encrypted ciphertext, so a text column rather than the source
            // type. Nullable: a null row means "explicitly cleared", which is
            // distinct from no row at all ("use the config default").
            $table->text('value')->nullable();

            // Drives redaction, not encryption - the value is encrypted either
            // way. A secret is never returned by the API (SEC-CFG-05).
            $table->boolean('is_secret')->default(false);

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One row per key per tenant. Composite with tenant_id because
            // tenant_id is NOT NULL DEFAULT 0 (ADR-C) - a nullable column here
            // would silently disable this index.
            $table->unique(['tenant_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
