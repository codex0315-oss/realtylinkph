<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Helpers\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAgentIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isVerifiedAgent()) {
            return ApiResponse::error('Your agent account is not verified.', [], 403);
        }

        return $next($request);
    }
}
