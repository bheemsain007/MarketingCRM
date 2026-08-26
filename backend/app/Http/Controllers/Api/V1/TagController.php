<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tags\StoreTagRequest;
use App\Http\Requests\Tags\UpdateTagRequest;
use App\Http\Resources\TagResource;
use App\Models\Tag;
use App\Services\Tags\TagService;
use App\Support\ApiResponse;
use App\Support\QueryOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The tag vocabulary (FR-LEAD-03, BR-INT-02).
 *
 * Thin: HTTP translation only, rules live in TagService (ARCHITECTURE §2).
 *
 * There is no `show` and no `restore`. A tag is a name and a colour, so the
 * list already carries everything a single read would; and `tags` has no
 * `deleted_at` column, so a deletion has nothing to restore from.
 */
class TagController extends Controller
{
    public function __construct(private readonly TagService $tags) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Tag::class);

        $options = new QueryOptions(
            $request,
            allowedFilters: ['is_system'],
            allowedSorts: ['name', 'slug', 'is_system', 'created_at'],
            allowedIncludes: [],
        );

        // withCount, not with: the screen needs to say how many leads a tag is
        // on before offering to delete it, and loading the leads themselves to
        // count them would be an unbounded read per row.
        $query = Tag::query()->withCount('leads');

        // System tags last by default. They are the ones nobody can act on, so
        // the editable vocabulary is what the page should open on.
        if (! $request->filled('sort')) {
            $query->orderBy('is_system')->orderBy('name');
        }

        $tags = $options->applyTo($query)->paginate($options->perPage());

        return ApiResponse::paginated(TagResource::collection($tags), 'Tags retrieved.');
    }

    public function store(StoreTagRequest $request): JsonResponse
    {
        $this->authorize('create', Tag::class);

        $tag = $this->tags->create($request->validated());

        return ApiResponse::created(
            new TagResource($tag->loadCount('leads')),
            'Tag created.',
        );
    }

    public function update(UpdateTagRequest $request, Tag $tag): JsonResponse
    {
        // The system-tag refusal runs first so it can say WHY. The policy
        // states the same rule and is the authority, but its 403 carries the
        // generic "you do not have permission" message - misleading for a
        // caller who holds settings.manage and is simply touching a tag that
        // the Interest Engine owns. Route middleware has already refused
        // anybody without the permission, so this cannot leak anything.
        $this->tags->guardEditable($tag);
        $this->authorize('update', $tag);

        $tag = $this->tags->update($tag, $request->validated());

        return ApiResponse::success(
            new TagResource($tag->loadCount('leads')),
            'Tag updated.',
        );
    }

    public function destroy(Tag $tag): JsonResponse
    {
        $this->tags->guardEditable($tag);
        $this->authorize('delete', $tag);

        $affected = $this->tags->delete($tag);

        // The cost of the deletion is reported, not hidden: lead_tag cascades,
        // so the label really is gone from those leads.
        return ApiResponse::success(message: $affected === 0
            ? 'Tag deleted.'
            : sprintf('Tag deleted and removed from %d lead%s.', $affected, $affected === 1 ? '' : 's'));
    }
}
