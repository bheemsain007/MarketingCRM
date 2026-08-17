<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Templates\PreviewTemplateRequest;
use App\Http\Requests\Templates\StoreTemplateRequest;
use App\Http\Requests\Templates\UpdateTemplateRequest;
use App\Http\Resources\TemplateResource;
use App\Models\Lead;
use App\Models\Template;
use App\Services\Messaging\OutboundMessageService;
use App\Services\Templates\TemplateService;
use App\Support\ApiResponse;
use App\Support\QueryOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Message templates (FR-COMM-02).
 *
 * Thin: HTTP translation only, the rules live in TemplateService
 * (ARCHITECTURE §2). Templates are organisation-wide content rather than lead
 * data, so the permission middleware is the whole gate on these routes - there
 * is no "someone else's template" to scope. The one exception is `preview`,
 * which reaches through to a lead and therefore runs LeadPolicy.
 */
class TemplateController extends Controller
{
    public function __construct(
        private readonly TemplateService $templates,
        private readonly OutboundMessageService $outbound,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $options = new QueryOptions(
            $request,
            allowedFilters: ['channel', 'is_active', 'approval_status', 'provider'],
            allowedSorts: ['name', 'code', 'channel', 'created_at', 'updated_at'],
            allowedIncludes: [],
        );

        $query = Template::query()->withCount(['campaigns', 'messages']);

        // Name or code, because an operator looking for a template knows one or
        // the other and rarely both.
        if ($search = $request->query('q')) {
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        /*
         * Retired templates are hidden by default so they stop appearing in
         * pickers, which is the entire effect of retiring one.
         *
         * An explicit `filter[is_active]` still wins - without that exemption
         * `filter[is_active]=0`, the only way to find a retired template in
         * order to restore it, would return nothing.
         */
        if (! $request->boolean('with_inactive') && ! $request->has('filter.is_active')) {
            $query->where('is_active', true);
        }

        // Grouped by channel then alphabetical: the shape a template picker
        // wants, since the caller has already chosen a channel by then.
        if (! $request->filled('sort')) {
            $query->orderBy('channel')->orderBy('name');
        }

        return ApiResponse::paginated(
            TemplateResource::collection($options->applyTo($query)->paginate($options->perPage())),
            'Templates retrieved.',
        );
    }

    public function show(Template $template): JsonResponse
    {
        $template->loadCount(['campaigns', 'messages']);

        return ApiResponse::success(new TemplateResource($template));
    }

    public function store(StoreTemplateRequest $request): JsonResponse
    {
        $template = $this->templates->create($request->validated(), $request->user()->id);

        return ApiResponse::created(new TemplateResource($template), 'Template created.');
    }

    public function update(UpdateTemplateRequest $request, Template $template): JsonResponse
    {
        $template = $this->templates->update($template, $request->validated(), $request->user()->id);

        return ApiResponse::success(new TemplateResource($template), 'Template updated.');
    }

    /**
     * Retires rather than deletes - sent messages and campaigns point at this
     * row and must keep resolving it. See TemplateService::deactivate().
     */
    public function destroy(Template $template): JsonResponse
    {
        $this->templates->deactivate($template);

        return ApiResponse::success(message: 'Template deactivated.');
    }

    public function restore(int $id): JsonResponse
    {
        // withTrashed() so a template soft-deleted outside this API - by a data
        // migration, or an older path - is still reachable to be recovered.
        $template = Template::withTrashed()->findOrFail($id);

        return ApiResponse::success(
            new TemplateResource($this->templates->restore($template)),
            'Template restored.',
        );
    }

    /**
     * Renders the template against a real lead (FR-COMM-02).
     *
     * The whole value is that it uses the send path's own renderer rather than a
     * second one: what the preview shows is what `OutboundMessageService` would
     * produce, token for token, including the fact that `{{ 2+2 }}` stays
     * `{{ 2+2 }}`. A preview with its own rendering logic is worse than none,
     * because it can agree with the operator's expectation and disagree with the
     * thing that actually sends (SEC-IN-06).
     */
    public function preview(PreviewTemplateRequest $request, Template $template): JsonResponse
    {
        $lead = Lead::findOrFail($request->integer('lead_id'));

        /*
         * The lead, not the template, decides who may see this. A render pulls
         * the lead's name, company and city into the response, so previewing
         * against a colleague's lead is a PII read - and `templates.view` is
         * deliberately broad enough that every telecaller holds it
         * (SEC-AUTHZ-04).
         */
        $this->authorize('view', $lead);

        $rendered = $this->outbound->render($lead, [], $template);

        return ApiResponse::success([
            'template_id' => $template->id,
            'lead_id' => $lead->id,
            'channel' => $template->channel->value,
            'subject' => $rendered['subject'],
            'body' => $rendered['body'],
            // The address this channel would actually use. Surfacing it here
            // means "this lead has no email on record" is visible before the
            // send is attempted rather than as a 422 after it.
            'recipient' => $this->outbound->recipientFor($lead, $template->channel),
            'is_sendable' => $template->isSendable(),
        ], 'Template preview rendered.');
    }
}
