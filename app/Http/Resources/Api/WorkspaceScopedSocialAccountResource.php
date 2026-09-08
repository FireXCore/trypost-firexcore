<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;

/**
 * A social account, plus the workspace it belongs to.
 *
 * Exists so an API consumer can verify tenancy itself instead of trusting that
 * a workspace-scoped endpoint scoped correctly. The token already binds the
 * request to one workspace, but a consumer that attributes one brand's numbers
 * to another has no way to notice the mistake later — so it is given the means
 * to check, and can fail closed on anything it cannot prove.
 *
 * Deliberately a SUBCLASS rather than a field on SocialAccountResource. That
 * resource is also what the MCP tool surface returns, and widening it would
 * hand every connected agent an internal identifier it has never needed — a
 * surface change nobody asked for, in a place where upstream asserts the field
 * is absent.
 */
class WorkspaceScopedSocialAccountResource extends SocialAccountResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'workspace_id' => $this->workspace_id,
        ];
    }
}
