<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\SocialAccount;
use App\Services\Social\AccountAnalyticsResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read-only account-level analytics for API consumers.
 *
 * The web UI has had this since v1.0.0 (App\Http\Controllers\App\AnalyticsController);
 * the REST API exposed only post-level metrics, so an external reporting tool
 * could see what was published but never the follower, reach or impression
 * figures behind it.
 *
 * Deliberately narrow: one account, one window, no writes. Tenancy is the
 * workspace bound to the access token by LoadWorkspaceFromToken — the account
 * is re-checked against it here rather than trusted from the route binding.
 */
class AccountAnalyticsController extends Controller
{
    private const MAX_WINDOW_DAYS = 400;

    public function show(Request $request, SocialAccount $account, AccountAnalyticsResolver $resolver): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace;

        // Fail closed on tenancy. Route-model binding resolves by primary key
        // across every workspace on the instance, so without this check a token
        // scoped to workspace A would read workspace B's analytics by id.
        // 404, not 403: the existence of another tenant's account id is itself
        // information, and the rest of this API answers the same way.
        if ($account->workspace_id !== $workspace->id) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $validated = $request->validate([
            'since' => ['nullable', 'date'],
            'until' => ['nullable', 'date', 'after_or_equal:since'],
        ]);

        $since = isset($validated['since']) ? Carbon::parse($validated['since'])->startOfDay() : null;
        $until = isset($validated['until']) ? Carbon::parse($validated['until'])->endOfDay() : null;

        // An unbounded window is a request to walk a platform's entire history
        // on our rate limit. The services apply their own per-platform caps as
        // well; this one exists so an obvious mistake is rejected rather than
        // absorbed.
        if ($since && $until && $since->diffInDays($until) > self::MAX_WINDOW_DAYS) {
            return response()->json([
                'message' => 'Requested window exceeds '.self::MAX_WINDOW_DAYS.' days.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Unsupported is its own answer. Returning an empty metric list would
        // be indistinguishable from "this account had no activity", and a
        // consumer would record a real zero for a figure nobody measured.
        if (! $account->platform || ! AccountAnalyticsResolver::supports($account->platform)) {
            return response()->json([
                'account_id' => $account->id,
                'platform' => $account->platform?->value,
                'supported' => false,
                'reason' => 'platform_not_supported',
                'since' => $since?->toIso8601String(),
                'until' => $until?->toIso8601String(),
                'metrics' => [],
            ]);
        }

        return response()->json([
            'account_id' => $account->id,
            'platform' => $account->platform->value,
            'supported' => true,
            'reason' => null,
            'since' => $since?->toIso8601String(),
            'until' => $until?->toIso8601String(),
            'metrics' => $resolver->forAccountKeyed($account, $since, $until),
        ]);
    }
}
