<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Services\Social\AccountAnalyticsResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class AnalyticsController extends Controller
{
    public function index(Request $request): Response
    {
        $workspace = $request->user()->currentWorkspace;

        $this->authorize('view', $workspace);

        $accounts = $workspace->socialAccounts()
            ->where('is_active', true)
            ->whereIn('platform', AccountAnalyticsResolver::SUPPORTED_PLATFORMS)
            ->get()
            ->map(fn (SocialAccount $account) => [
                'id' => $account->id,
                'platform' => $account->platform->value,
                'username' => $account->username,
                'display_label' => $account->display_label,
                'avatar_url' => $account->avatar_url,
            ]);

        return Inertia::render('analytics/Index', [
            'accounts' => $accounts,
        ]);
    }

    public function show(Request $request, SocialAccount $account): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace;

        if ($account->workspace_id !== $workspace->id) {
            abort(HttpResponse::HTTP_FORBIDDEN);
        }

        $since = $request->has('since') ? Carbon::parse($request->input('since')) : null;
        $until = $request->has('until') ? Carbon::parse($request->input('until')) : null;

        $metrics = $this->metricsFor($account, $since, $until);

        return response()->json(['metrics' => $metrics]);
    }

    /**
     * @return array<int, array{label: string, value: int|string}>
     */
    private function metricsFor(SocialAccount $account, ?Carbon $since, ?Carbon $until): array
    {
        return app(AccountAnalyticsResolver::class)->forAccount($account, $since, $until);
    }
}
