<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the management area with a real login: the session carries the flag set by AdminAuthController.
 * The single admin account comes from config/admin.php (ADMIN_USER / ADMIN_PASSWORD_HASH); no database user is needed.
 */
class AdminAuth
{
    public const SESSION_KEY = 'admin_auth';

    public function handle(Request $request, Closure $next): Response
    {
        // Fail closed when the admin account has not been configured.
        if (! self::configured()) {
            abort(503, 'Admin access is not configured.');
        }

        if (! self::check($request)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            // Only a plain page view is worth returning to after login; a lost POST is not replayed.
            if ($request->isMethod('GET')) {
                $request->session()->put('url.intended', $request->fullUrl());
            }

            return redirect()->route('admin.login');
        }

        return $next($request);
    }

    public static function configured(): bool
    {
        return (string) config('admin.user') !== '' && (string) config('admin.password_hash') !== '';
    }

    /**
     * Whether this request belongs to a logged-in admin. Also used to show management controls on public pages.
     */
    public static function check(Request $request): bool
    {
        return self::configured()
            && $request->hasSession()
            && $request->session()->get(self::SESSION_KEY) === true;
    }
}
