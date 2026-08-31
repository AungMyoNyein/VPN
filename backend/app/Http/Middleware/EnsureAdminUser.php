<?php

namespace App\Http\Middleware;

use App\Models\AdminUser;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminUser
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof AdminUser) {
            return ApiResponse::error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        if (! $user->isActive()) {
            return ApiResponse::error('FORBIDDEN', 'Admin account is disabled.', 403);
        }

        return $next($request);
    }
}
