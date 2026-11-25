<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prevent pure parent-role users from accessing teacher/student/admin endpoints.
 * Faculty members who also have linked children keep their staff role and are unaffected.
 */
class RestrictParentRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->role === 'parent') {
            return response()->json([
                'error' => '家长账号无权访问该接口',
                'message' => '家长账号无权访问该接口',
            ], 403);
        }

        return $next($request);
    }
}
