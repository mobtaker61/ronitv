@extends('layouts.app')

@section('title', 'صف پردازش - ' . config('app.name'))

@section('content')
<div class="container" style="padding-top: 20px;">
    @include('admin._nav')

    @if(session('success'))<div class="alert alert-success py-2">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger py-2">{{ session('error') }}</div>@endif

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h4 class="mb-0">صف پردازش</h4>
        <form method="POST" action="{{ route('admin.queue.requeue') }}" class="d-flex align-items-center gap-3 flex-wrap"
              onsubmit="return confirm('همه‌ی ویدیوهای تبدیل‌نشده که در صف نیستند صف شوند؟')">
            @csrf
            <div class="form-check small mb-0">
                <input class="form-check-input" type="checkbox" name="delete_original" id="deleteOriginal" value="1" checked>
                <label class="form-check-label" for="deleteOriginal">بعد از تأیید، فایل اصلی (MKV) پاک شود</label>
            </div>
            <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-film me-1"></i>صف‌کردن ویدیوهای تبدیل‌نشده</button>
        </form>
    </div>

    <div id="queueLive" data-url="{{ route('admin.queue') }}?partial=1">
        @include('admin._queue-body')
    </div>

    <div class="small admin-hint mt-3"><i class="fas fa-rotate me-1"></i>این صفحه هر ۶ ثانیه خودکار به‌روز می‌شود.</div>
</div>
@endsection

@push('scripts')
<script>
    (function () {
        const live = document.getElementById('queueLive');
        let failures = 0;

        async function refresh() {
            if (document.hidden) return;

            try {
                const response = await fetch(live.dataset.url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }, redirect: 'manual' });
                if (!response.ok) { if (++failures > 3) clearInterval(timer); return; }
                failures = 0;
                live.innerHTML = await response.text();   // our own server-rendered, escaped markup
            } catch (error) {
                if (++failures > 3) clearInterval(timer);
            }
        }

        const timer = setInterval(refresh, 6000);
    })();
</script>
@endpush
