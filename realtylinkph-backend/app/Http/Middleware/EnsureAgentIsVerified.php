<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Helpers\ApiResponse;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAgentIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isVerifiedAgent()) {
            return ApiResponse::error($this->messageFor($user), [], 403);
        }

        return $next($request);
    }

    /**
     * Say what is actually true. A buyer who landed on an agent-only action was
     * being told their "agent account is not verified", which made no sense to
     * someone who never applied.
     */
    private function messageFor(?User $user): string
    {
        if ($user === null || ! $user->isAgent()) {
            return 'This is only for verified agents.';
        }

        return match ($user->agentProfile?->status) {
            'rejected' => 'Your agent application was not approved, so this is not available.',
            default    => 'Your agent application is still being reviewed. This unlocks once it is approved.',
        };
    }
}
