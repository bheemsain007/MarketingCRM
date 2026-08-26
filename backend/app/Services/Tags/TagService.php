<?php

namespace App\Services\Tags;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The tag vocabulary (FR-LEAD-03, BR-INT-02).
 *
 * Tags are organisation reference data: a small shared list that lead records
 * point at. Two rules shape everything here.
 *
 * 1. The slug is DERIVED, never supplied. It is what `(tenant_id, slug)` is
 *    unique on and what the Interest Engine matches against, so letting a
 *    caller set it independently of the name would allow two tags that look
 *    identical in every list in the CRM.
 * 2. System tags are untouchable. See guardEditable().
 */
class TagService
{
    /** @param array<string, mixed> $data */
    public function create(array $data): Tag
    {
        $name = (string) $data['name'];
        $slug = $this->slugFor($name);

        $this->guardUniqueSlug($slug);

        $attributes = [
            'tenant_id' => config('crm.default_tenant_id'),
            'name' => $name,
            'slug' => $slug,
            // Never from the request. A caller-set is_system would be a way to
            // mint a tag nobody could afterwards remove.
            'is_system' => false,
        ];

        // `color` is NOT NULL with a database default, so an omitted colour has
        // to stay omitted rather than being written as an explicit null.
        if (! empty($data['color'])) {
            $attributes['color'] = (string) $data['color'];
        }

        // refresh(): the colour may have come from the column default, which
        // Eloquent does not know about - without this the create response
        // reports a null colour for a row that has one.
        return Tag::create($attributes)->refresh();
    }

    /** @param array<string, mixed> $data */
    public function update(Tag $tag, array $data): Tag
    {
        $this->guardEditable($tag);

        $attributes = [];

        if (isset($data['name']) && $data['name'] !== $tag->name) {
            $slug = $this->slugFor((string) $data['name']);

            // Checked before the write so a rename onto an existing label is a
            // clean 409 with the offending tag named, not a raw index violation.
            $this->guardUniqueSlug($slug, $tag->id);

            $attributes['name'] = (string) $data['name'];
            $attributes['slug'] = $slug;
        }

        if (! empty($data['color'])) {
            $attributes['color'] = (string) $data['color'];
        }

        if ($attributes !== []) {
            $tag->update($attributes);
        }

        return $tag->fresh();
    }

    /**
     * Removes a tag from the vocabulary, and returns how many leads lost the
     * label.
     *
     * This is a real delete - the only destructive operation in this module -
     * because `tags` carries no `deleted_at` column and `lead_tag` cascades, so
     * there is no archive to fall back on the way Products and Templates have
     * one. The count is returned rather than discarded precisely because of
     * that: the caller is told what the deletion cost, and the screen states it
     * before asking for confirmation.
     *
     * @return int leads that lost the label
     */
    public function delete(Tag $tag): int
    {
        $this->guardEditable($tag);

        return DB::transaction(function () use ($tag) {
            $affected = $tag->leads()->count();

            // Detached explicitly rather than left to the foreign key's cascade,
            // so the pivot rows and the tag go in one transaction.
            $tag->leads()->detach();
            $tag->delete();

            return $affected;
        });
    }

    /**
     * Refuses any write to a system tag.
     *
     * TagPolicy states the same rule - it is the authority, and it is what any
     * future caller gets for free. This exists because the policy's refusal
     * renders as the generic "you do not have permission" message, which is
     * actively misleading here: the person almost certainly HAS the permission,
     * and the tag is what is off limits. Controllers call this first so the
     * refusal names the reason.
     */
    public function guardEditable(Tag $tag): void
    {
        if (! $tag->is_system) {
            return;
        }

        throw new ApiException(
            ErrorCode::Forbidden,
            "\"{$tag->name}\" is applied automatically and cannot be renamed or deleted.",
            context: ['tag_id' => $tag->id, 'is_system' => true],
        );
    }

    private function slugFor(string $name): string
    {
        $slug = Str::slug($name);

        /*
         * Str::slug() strips everything non-ASCII, so a name written entirely
         * in a non-Latin script slugs to an empty string - and the second such
         * tag would then collide with the first on the unique index for a
         * reason nobody could see from the two names. Keep the name itself,
         * lower-cased and de-spaced, as a stable handle.
         */
        if ($slug === '') {
            $slug = Str::limit(Str::lower((string) preg_replace('/\s+/u', '-', $name)), 60, '');
        }

        return $slug;
    }

    private function guardUniqueSlug(string $slug, ?int $ignoreId = null): void
    {
        $existing = Tag::query()
            ->where('tenant_id', config('crm.default_tenant_id'))
            ->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->first();

        if ($existing === null) {
            return;
        }

        throw new ApiException(
            ErrorCode::Conflict,
            $existing->is_system
                ? "\"{$existing->name}\" already exists and is applied automatically, so it cannot be replaced."
                : "A tag named \"{$existing->name}\" already exists.",
            context: ['existing_tag_id' => $existing->id, 'is_system' => $existing->is_system],
        );
    }
}
