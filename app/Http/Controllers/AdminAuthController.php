<?php

namespace App\Http\Controllers;

use App\Http\Middleware\AdminAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AdminAuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function showLogin(Request $request)
    {
        if (AdminAuth::check($request)) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.login', ['configured' => AdminAuth::configured()]);
    }

    public function login(Request $request)
    {
        abort_unless(AdminAuth::configured(), 503, 'Admin access is not configured.');

        $request->validate(['username' => ['required', 'string'], 'password' => ['required', 'string']]);

        $key = 'admin-login:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'username' => "تلاش‌های ناموفق زیاد بود. {$seconds} ثانیه دیگر دوباره امتحان کنید.",
            ])->status(429);
        }

        // Always run the hash check so the response time does not reveal whether the username was right.
        $passwordOk = Hash::check((string) $request->input('password'), (string) config('admin.password_hash'));
        $userOk = hash_equals((string) config('admin.user'), (string) $request->input('username'));

        if (! ($passwordOk && $userOk)) {
            RateLimiter::hit($key, 300);

            throw ValidationException::withMessages(['username' => 'نام کاربری یا رمز عبور درست نیست.']);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate(); // new session id after privilege change (prevents fixation)
        $request->session()->put(AdminAuth::SESSION_KEY, true);

        return redirect()->intended(route('admin.dashboard'));
    }

    public function logout(Request $request)
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
