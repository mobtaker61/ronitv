@extends('layouts.app')

@section('title', 'ورود مدیر - ' . config('app.name'))

@section('content')
<div class="container" style="padding-top: 40px; max-width: 460px;">
    <div class="admin-card">
        <h4 class="mb-3"><i class="fas fa-lock me-2"></i>ورود مدیر</h4>

        @unless($configured)
            <div class="alert alert-warning">حساب مدیر تنظیم نشده است (<code>ADMIN_USER</code> و <code>ADMIN_PASSWORD_HASH</code> در <code>.env</code>).</div>
        @endunless

        <form method="POST" action="{{ route('admin.login.submit') }}">
            @csrf
            <div class="mb-3">
                <label class="form-label" for="username">نام کاربری</label>
                <input type="text" id="username" name="username" value="{{ old('username') }}" class="form-control @error('username') is-invalid @enderror" autocomplete="username" autofocus required dir="ltr">
                @error('username')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label" for="password">رمز عبور</label>
                <input type="password" id="password" name="password" class="form-control" autocomplete="current-password" required dir="ltr">
            </div>
            <button type="submit" class="btn btn-primary w-100" @disabled(! $configured)>ورود</button>
        </form>
    </div>
</div>
@endsection
