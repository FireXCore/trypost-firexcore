<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;

/**
 * Engine identity: what this instance is, and which build is running.
 *
 * Upstream publishes no version anywhere a client can read, so an operator
 * console integrating this engine has two bad options: display nothing, or
 * display the version it *believes* it deployed. The second is worse — it
 * reports configuration as though it were an observation, and it keeps
 * reporting it after a partial upgrade leaves the box on a different build.
 *
 * This endpoint makes the running build observable. It is authenticated like
 * every other API route, because the build number of a private instance is
 * operational detail, not public information.
 *
 * Deliberately not workspace-specific: the answer is a property of the
 * deployment, identical for every workspace on it. It still sits behind the
 * workspace-scoped middleware so it cannot be probed without a valid token.
 */
class EngineController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'engine' => 'trypost',
            'version' => config('trypost.version'),
            'self_hosted' => (bool) config('trypost.self_hosted'),
        ]);
    }
}
