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
        $user = (string) config('admin.user');
        $hash = (string) config('admin.password_hash');

        // Fail closed when the admin account has not been configured.
        if ($user === '' || $hash === '') {
            abort(503, 'Admin access is not configured.');
        }

        $given = (string) $request->getUser();
        $password = (string) $request->getPassword();

        $valid = hash_equals($user, $given) && Hash::check($password, $hash);

        if (! $valid) {
            return response('Unauthorized', 401, ['WWW-Authenticate' => 'Basic realm="RoniTV Admin", charset="UTF-8"']);
        }

        return $next($request);
    }
}
