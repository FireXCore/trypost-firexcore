<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SocialAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // The workspace this channel belongs to.
            //
            // Exposed so an API consumer can verify tenancy itself instead of
            // trusting that this endpoint scoped correctly. The token already
            // binds the request to one workspace, but a consumer attributing a
            // brand's numbers to the wrong customer has no way to notice the
            // mistake later — so it is given the means to check.
            'workspace_id' => $this->workspace_id,
            'platform' => $this->platform?->value,
            'display_name' => $this->display_name,
            'username' => $this->username,
            'is_active' => $this->is_active,
            'status' => $this->status?->value,
        ];
    }
}
