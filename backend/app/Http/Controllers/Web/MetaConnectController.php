<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Settings\SettingsService;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * "Continue with Facebook" for the Integrations screen (T-35).
 *
 * The user authenticates on facebook.com itself, never on this app - this
 * controller only ever sees the authorization `code` Facebook hands back, not
 * a password. What it automates is everything a person otherwise has to do by
 * hand in the Meta developer console: exchange the code for a Page access
 * token, register this app's webhook (`POST /{app-id}/subscriptions`), and
 * subscribe the chosen Page to it (`POST /{page-id}/subscribed_apps`) - the
 * same three artefacts `MetaWebhookController`/`MetaLeadService` already
 * expect, just filled in without a manual walkthrough.
 *
 * One prerequisite this cannot remove: a Facebook App (`providers.meta.app_id`
 * / `app_secret`) has to exist first, because the OAuth dialog itself needs a
 * `client_id` to redirect to. That half is still a manual step in Meta's own
 * console and stays a plain credential field on the Integrations card.
 */
class MetaConnectController extends Controller
{
    private const GRAPH_VERSION = 'v21.0';

    /** CSRF guard for the OAuth round trip - checked and consumed in callback(). */
    private const SESSION_STATE_KEY = 'meta_connect.state';

    /**
     * The Pages this Facebook user administers, each with its own Page access
     * token - fetched once in callback() and held only long enough for the
     * user to pick one in selectPage(). Never written to a log or the audit
     * trail; cleared as soon as it is used or the user leaves.
     */
    private const SESSION_PAGES_KEY = 'meta_connect.pages';

    public function __construct(private readonly SettingsService $settings) {}

    /** Step 1 of the redirect: off to Facebook's own login/consent screen. */
    public function connect(Request $request): RedirectResponse
    {
        $appId = $this->settings->get('providers.meta.app_id');

        if (! $appId) {
            return redirect()->route('web.integrations')
                ->with('meta_error', 'Save your Facebook App ID and secret first.');
        }

        $state = Str::random(40);
        $request->session()->put(self::SESSION_STATE_KEY, $state);

        $url = 'https://www.facebook.com/'.self::GRAPH_VERSION.'/dialog/oauth?'.http_build_query([
            'client_id' => $appId,
            'redirect_uri' => route('web.integrations.meta.callback'),
            'state' => $state,
            'scope' => 'pages_show_list,pages_manage_ads,pages_read_engagement,leads_retrieval',
            'response_type' => 'code',
        ]);

        return redirect()->away($url);
    }

    /**
     * Step 2: Facebook lands the browser back here with either `code` or
     * `error`. Exchanges the code for a user token, lists the Pages that
     * token can act as, and hands the picker screen its choices - nothing is
     * saved yet, because a person's own Page might not be the one they want
     * to run ads from.
     */
    public function callback(Request $request): RedirectResponse
    {
        $expectedState = $request->session()->pull(self::SESSION_STATE_KEY);

        if (! $expectedState || $request->query('state') !== $expectedState) {
            return redirect()->route('web.integrations')
                ->with('meta_error', 'The connection request could not be verified. Please try again.');
        }

        if ($request->filled('error')) {
            return redirect()->route('web.integrations')
                ->with('meta_error', 'Facebook connection was cancelled.');
        }

        $appId = $this->settings->get('providers.meta.app_id');
        $appSecret = $this->settings->get('providers.meta.app_secret');

        $tokenResponse = Http::timeout(15)->get(
            'https://graph.facebook.com/'.self::GRAPH_VERSION.'/oauth/access_token',
            [
                'client_id' => $appId,
                'client_secret' => $appSecret,
                'redirect_uri' => route('web.integrations.meta.callback'),
                'code' => $request->query('code'),
            ],
        );

        if (! $tokenResponse->successful()) {
            return redirect()->route('web.integrations')
                ->with('meta_error', 'Facebook did not accept the connection: '.$this->graphError($tokenResponse));
        }

        $userToken = $tokenResponse->json('access_token');

        // `/me/accounts` returns every Page this user administers, each
        // carrying its OWN access token - no separate exchange per Page needed.
        $pagesResponse = Http::timeout(15)->get(
            'https://graph.facebook.com/'.self::GRAPH_VERSION.'/me/accounts',
            ['access_token' => $userToken, 'fields' => 'id,name,access_token'],
        );

        if (! $pagesResponse->successful()) {
            return redirect()->route('web.integrations')
                ->with('meta_error', 'Could not list your Facebook Pages: '.$this->graphError($pagesResponse));
        }

        $pages = (array) $pagesResponse->json('data', []);

        if ($pages === []) {
            return redirect()->route('web.integrations')->with(
                'meta_error',
                'No Facebook Pages found for this account. You need to be an admin of the Page running the Lead Ads.',
            );
        }

        $request->session()->put(self::SESSION_PAGES_KEY, $pages);

        return redirect()->route('web.integrations', ['meta_pick_page' => 1]);
    }

    /** The names for the picker - never the tokens sitting alongside them in session. */
    public function pages(Request $request): JsonResponse
    {
        $pages = (array) $request->session()->get(self::SESSION_PAGES_KEY, []);

        return response()->json([
            'data' => array_map(
                fn (array $page) => ['id' => $page['id'], 'name' => $page['name']],
                $pages,
            ),
        ]);
    }

    /**
     * Step 3: the chosen Page's token is stored as this app's own
     * `providers.meta.page_access_token` - exactly the setting
     * `MetaLeadService::fetch()` already reads - and that Page is subscribed
     * to receive this app's webhook. Both Graph calls are safe to repeat: the
     * app-level subscription is idempotent, and re-subscribing an
     * already-subscribed Page is a no-op on Meta's side.
     */
    public function selectPage(Request $request): JsonResponse
    {
        $request->validate(['page_id' => 'required|string']);

        $pages = (array) $request->session()->get(self::SESSION_PAGES_KEY, []);
        $page = collect($pages)->firstWhere('id', $request->string('page_id')->toString());

        if ($page === null) {
            return response()->json([
                'message' => 'That page is no longer available - please connect again.',
            ], 422);
        }

        $appId = $this->settings->get('providers.meta.app_id');
        $appSecret = $this->settings->get('providers.meta.app_secret');

        // The webhook needs a verify token to echo back during the handshake
        // Meta performs against callback_url below. Generated here rather than
        // asked for, so this flow never needs a person to invent one.
        $verifyToken = $this->settings->get('providers.meta.verify_token');

        if (! $verifyToken) {
            $verifyToken = Str::random(32);
            $this->settings->set('providers.meta.verify_token', $verifyToken, $request->user()->id, $request);
        }

        $subscribeApp = Http::timeout(15)->asForm()->post(
            'https://graph.facebook.com/'.self::GRAPH_VERSION.'/'.$appId.'/subscriptions',
            [
                'object' => 'page',
                'callback_url' => route('api.v1.webhooks.meta'),
                'fields' => 'leadgen',
                'verify_token' => $verifyToken,
                'access_token' => $appId.'|'.$appSecret,
            ],
        );

        if (! $subscribeApp->successful()) {
            return response()->json([
                'message' => 'Could not register the webhook with Facebook: '.$this->graphError($subscribeApp),
            ], 422);
        }

        $subscribePage = Http::timeout(15)->asForm()->post(
            'https://graph.facebook.com/'.self::GRAPH_VERSION.'/'.$page['id'].'/subscribed_apps',
            ['subscribed_fields' => 'leadgen', 'access_token' => $page['access_token']],
        );

        if (! $subscribePage->successful()) {
            return response()->json([
                'message' => 'Could not subscribe "'.$page['name'].'" to lead notifications: '.$this->graphError($subscribePage),
            ], 422);
        }

        $this->settings->set(
            'providers.meta.page_access_token',
            $page['access_token'],
            $request->user()->id,
            $request,
        );

        $request->session()->forget(self::SESSION_PAGES_KEY);

        return response()->json([
            'message' => 'Connected "'.$page['name'].'" - new Facebook and Instagram leads will now arrive automatically.',
        ]);
    }

    /**
     * Removes the stored Page token only. The App ID/secret stay - they name
     * which Facebook App this is, not which Page it is currently reading.
     */
    public function disconnect(Request $request): JsonResponse
    {
        $this->settings->forget('providers.meta.page_access_token', $request->user()->id, $request);

        return response()->json(['message' => 'Disconnected.']);
    }

    private function graphError(HttpResponse $response): string
    {
        return (string) ($response->json('error.message') ?? ('HTTP '.$response->status()));
    }
}
