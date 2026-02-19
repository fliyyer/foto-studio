<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminRoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        $allowedRoles = empty($roles) ? ['admin'] : $roles;

        if (! $user || ! in_array($user->role, $allowedRoles, true)) {
            $rolesLabel = implode(', ', $allowedRoles);

            return response()->json([
                'message' => "Forbidden. Allowed roles: {$rolesLabel}.",
            ], 403);
        }

        return $next($request);
    }
}
