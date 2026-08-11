<?php

namespace App\Services\Products;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Product management (PROJECT_REQUIREMENTS §1.1).
 *
 * Products are referenced by lead interest, opportunities and campaigns, so the
 * rules here are mostly about not destroying history: a product that has ever
 * been sold or shown interest in cannot be erased, only deactivated.
 */
class ProductService
{
    /** @param array<string, mixed> $data */
    public function create(array $data, ?int $actorId = null): Product
    {
        $data['tenant_id'] = config('crm.default_tenant_id');
        $data['created_by'] = $actorId;
        $data['updated_by'] = $actorId;

        $this->guardUniqueCode($data['code']);

        return Product::create($data);
    }

    /** @param array<string, mixed> $data */
    public function update(Product $product, array $data, ?int $actorId = null): Product
    {
        if (isset($data['code']) && $data['code'] !== $product->code) {
            $this->guardUniqueCode($data['code'], $product->id);
        }

        $data['updated_by'] = $actorId;

        $product->update($data);

        return $product->fresh();
    }

    /**
     * Archives (soft deletes) a product.
     *
     * Never a hard delete. `lead_products` holds a RESTRICT foreign key, so
     * erasing a product would either fail or orphan interest history and
     * silently corrupt product-performance reporting. Archiving keeps every
     * historical record intact while removing the product from new selections.
     */
    public function archive(Product $product): void
    {
        DB::transaction(function () use ($product) {
            // Deactivate as well as archive so any code that filters on
            // is_active - campaign targeting, dropdowns - excludes it too.
            $product->update(['is_active' => false]);
            $product->delete();
        });
    }

    public function restore(Product $product): Product
    {
        $product->restore();
        $product->update(['is_active' => true]);

        return $product->fresh();
    }

    /**
     * Uniqueness is enforced by a DB index too; this check exists to return a
     * clean 409 with context rather than a raw constraint violation.
     */
    private function guardUniqueCode(string $code, ?int $ignoreId = null): void
    {
        $exists = Product::withTrashed()
            ->where('tenant_id', config('crm.default_tenant_id'))
            ->where('code', $code)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->first();

        if ($exists) {
            throw new ApiException(
                ErrorCode::Conflict,
                $exists->trashed()
                    ? "An archived product already uses the code \"{$code}\". Restore it instead of creating a duplicate."
                    : "A product with the code \"{$code}\" already exists.",
                context: ['existing_product_id' => $exists->id, 'archived' => $exists->trashed()],
            );
        }
    }
}
