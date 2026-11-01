<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stateless HTTP Basic guard for the management routes (upload, download,
 * create folder). Credentials come from config/admin.php; the app has no
 * users table in production, so this deliberately avoids the database.
 */
class AdminBasicAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        // Fail closed when the admin account has not been configured.
        if (! self::configured()) {
            abort(503, 'Admin access is not configured.');
        }

        if (! self::passes($request)) {
            return response('Unauthorized', 401, ['WWW-Authenticate' => 'Basic realm="RoniTV Admin", charset="UTF-8"']);
        }

        return $next($request);
    }

    public static function configured(): bool
    {
        return (string) config('admin.user') !== '' && (string) config('admin.password_hash') !== '';
    }

    /**
     * Whether the request carries valid admin credentials, without challenging the browser.
     * Used to show management controls on public pages.
     */
    public static function passes(Request $request): bool
    {
        if (! self::configured()) {
            return false;
        }

        return hash_equals((string) config('admin.user'), (string) $request->getUser())
            && Hash::check((string) $request->getPassword(), (string) config('admin.password_hash'));
    }
}
