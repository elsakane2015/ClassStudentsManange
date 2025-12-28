<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\Response;

class CheckInstalled
{
    /**
     * Handle an incoming request.
     * Redirect to install page if not installed.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $isInstalled = File::exists(storage_path('installed'));
        $isInstallRoute = $request->is('install') || $request->is('install/*') || $request->is('api/install/*');

        // If not installed and not accessing install page, redirect to install
        if (!$isInstalled && !$isInstallRoute) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'System not installed', 'redirect' => '/install'], 503);
            }
            return redirect('/install');
        }

        // If installed and trying to access install page, redirect to home
        if ($isInstalled && $isInstallRoute) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'System already installed'], 400);
            }
            return redirect('/');
        }

        return $next($request);
    }
}
