@extends('layouts.app')

@section('title', 'داشبورد مدیریت - ' . config('app.name'))

@php
    $fmt = function (int $bytes): string {
        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $i => $unit) {
            if ($bytes < 1024 ** ($i + 1) || $unit === 'TB') {
                return rtrim(rtrim(number_format($bytes / 1024 ** $i, $i ? 1 : 0), '0'), '.') . ' ' . $unit;
            }
        }
    };
    $usedPct = $disk['total'] > 0 ? round(($disk['total'] - $disk['free']) / $disk['total'] * 100) : 0;
    $statusColors = ['queued' => 'secondary', 'running' => 'primary', 'done' => 'success', 'failed' => 'danger'];
    $statusLabels = ['queued' => 'در صف', 'running' => 'در حال دانلود', 'done' => 'تمام', 'failed' => 'ناموفق'];
@endphp

@section('content')
<div class="container" style="padding-top: 20px;">
    @include('admin._nav')

    @if(session('success'))<div class="alert alert-success py-2">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger py-2">{{ session('error') }}</div>@endif

    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="admin-card h-100">
                <div class="text-muted small mb-1">فضای دیسک</div>
                <div class="fs-4" dir="ltr">{{ $fmt($disk['free']) }} <small class="text-muted">free</small></div>
                <div class="progress my-2" style="height: 8px;">
                    <div class="progress-bar {{ $usedPct >= 90 ? 'bg-danger' : ($usedPct >= 80 ? 'bg-warning' : '') }}" style="width: {{ $usedPct }}%"></div>
                </div>
                <small class="text-muted" dir="ltr">{{ $usedPct }}% of {{ $fmt($disk['total']) }}</small>
                @if($usedPct >= 90)<div class="text-danger small mt-1">فضای دیسک رو به اتمام است.</div>@endif
            </div>
        </div>
        <div class="col-md-4">
            <div class="admin-card h-100">
                <div class="text-muted small mb-1">کتابخانه</div>
                <div class="fs-4">{{ $stats['videos'] }} ویدیو در {{ $stats['titles'] }} عنوان</div>
                <div class="small text-muted mt-1" dir="ltr">{{ $fmt($stats['bytes']) }}</div>
                <div class="small mt-2">
                    <span class="badge bg-primary">HLS چندصدایی: {{ $stats['hls'] }}</span>
                    <span class="badge bg-secondary">MP4: {{ $stats['mp4'] }}</span>
                    @if(($stats['unreviewed'] ?? 0) + ($stats['unmatched'] ?? 0) > 0)
                    <div class="small mt-2">
                        <a href="{{ route('admin.library') }}" class="text-warning">
                            {{ $stats['unreviewed'] ?? 0 }} عنوان فقط حدسی وصل شده @if($stats['unmatched'] ?? 0)· {{ $stats['unmatched'] }} عنوان بدون اطلاعات @endif — بررسی کنید
                        </a>
                    </div>
                @endif
                @if($stats['waiting'])<span class="badge bg-warning text-dark">در انتظار تبدیل: {{ $stats['waiting'] }}</span>@endif
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="admin-card h-100" id="subtitleCard"
                 data-status-url="{{ route('admin.subtitles.batch.status') }}"
                 data-running="{{ ($batch['running'] ?? false) ? 1 : 0 }}">
                <div class="text-muted small mb-1">زیرنویس فارسی</div>
                <div class="fs-4">{{ $stats['noSubtitle'] }} ویدیو بدون زیرنویس</div>

                <div id="subtitleBatch" class="small mt-2" @if(! $batch) style="display:none" @endif>
                    <div class="progress mb-1" style="height: 6px;"><div class="progress-bar" id="subtitleBar" style="width: {{ $batch && $batch['total'] ? round($batch['done'] / $batch['total'] * 100) : 0 }}%"></div></div>
                    <div id="subtitleSummary" class="text-muted">
                        @if($batch)
                            {{ $batch['done'] }} از {{ $batch['total'] }} · پیدا شد: {{ $batch['stored'] }} · نبود: {{ $batch['none'] }}@if($batch['failed']) · خطا: {{ $batch['failed'] }}@endif
                            @if($batch['running']) (در حال اجرا) @elseif(! empty($batch['aborted'])) (متوقف شد) @else (تمام شد) @endif
                        @endif
                    </div>
                    <div id="subtitleError" class="text-danger">{{ $batch['error'] ?? '' }}</div>
                </div>

                @if(! $subsourceConfigured)
                    <div class="small text-warning mt-2">کلید SubSource تنظیم نشده است (<code>SUBSOURCE_API_KEY</code>).</div>
                @elseif($stats['subtitleTodoAll'] > 0)
                    <form method="POST" action="{{ route('admin.subtitles.batch') }}" class="mt-2" id="subtitleForm">
                        @csrf
                        <div class="form-check small mb-2">
                            <input class="form-check-input" type="checkbox" name="only_confirmed" id="onlyConfirmed" value="1" checked>
                            <label class="form-check-label" for="onlyConfirmed">فقط عنوان‌های تأییدشده ({{ $stats['subtitleTodoConfirmed'] }} از {{ $stats['subtitleTodoAll'] }} ویدیو)</label>
                        </div>
                        <button type="submit" class="btn btn-sm btn-success" @disabled($batch['running'] ?? false)><i class="fas fa-closed-captioning me-1"></i>دریافت زیرنویس‌ها از SubSource</button>
                        <div class="form-text">نتیجه‌ی هر ویدیو ذخیره می‌شود و دوباره جستجو نمی‌شود.</div>
                    </form>
                @else
                    <div class="small text-success mt-2">برای همه‌ی ویدیوها زیرنویس هست یا بی‌نتیجه ثبت شده.</div>
                @endif
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-6">
            <div class="admin-card h-100">
                <h6 class="mb-3"><i class="fas fa-list-check me-1"></i>صف پردازش</h6>
                @if($queue['unavailable'])<div class="alert alert-warning py-1 small">دیتابیس در دسترس نیست؛ وضعیت صف نمایش داده نمی‌شود.</div>@endif
                <div class="d-flex gap-3 mb-2 flex-wrap">
                    <div><span class="fs-5">{{ $queue['media'] }}</span> <small class="text-muted">تبدیل ویدیو</small></div>
                    <div><span class="fs-5">{{ $queue['default'] }}</span> <small class="text-muted">دانلود از لینک</small></div>
                    <div><span class="fs-5 {{ $queue['failed'] ? 'text-danger' : '' }}">{{ $queue['failed'] }}</span> <small class="text-muted">شکست‌خورده</small></div>
                </div>
                @foreach($queue['recentFailures'] as $failure)
                    <div class="small text-danger text-truncate" dir="ltr" title="{{ $failure['message'] }}">{{ $failure['at'] }} · {{ $failure['queue'] }} · {{ $failure['message'] }}</div>
                @endforeach
                @if($queue['media'] + $queue['default'] > 0)
                    <div class="small text-muted mt-2">اگر عددها کم نمی‌شوند، وضعیت worker را بررسی کنید: <code dir="ltr">supervisorctl status ronitv-worker:*</code></div>
                @endif
            </div>
        </div>
        <div class="col-md-6">
            <div class="admin-card h-100">
                <h6 class="mb-3"><i class="fas fa-cloud-arrow-down me-1"></i>دانلودهای اخیر از لینک</h6>
                @forelse($downloads as $download)
                    @php $pct = $download['total'] > 0 ? round($download['downloaded'] / $download['total'] * 100) : ($download['status'] === 'done' ? 100 : 0); @endphp
                    <div class="small mb-2">
                        <div class="d-flex justify-content-between gap-2">
                            <span class="text-truncate" dir="ltr" title="{{ $download['path'] }}">{{ $download['path'] }}</span>
                            <span class="badge bg-{{ $statusColors[$download['status']] ?? 'secondary' }}">{{ $statusLabels[$download['status']] ?? $download['status'] }}</span>
                        </div>
                        @if($download['status'] === 'running')<div class="progress mt-1" style="height: 4px;"><div class="progress-bar" style="width: {{ $pct }}%"></div></div>@endif
                        @if($download['status'] === 'failed')<div class="text-danger">{{ $download['error'] }}</div>@endif
                    </div>
                @empty
                    <div class="text-muted small">دانلودی در ۲۴ ساعت گذشته ثبت نشده است.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    (function () {
        const card = document.getElementById('subtitleCard');
        if (!card || card.dataset.running !== '1') return;

        const tick = async () => {
            try {
                const response = await fetch(card.dataset.statusUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
                const batch = await response.json();
                if (!batch) return;

                document.getElementById('subtitleBar').style.width = (batch.total ? Math.round(batch.done / batch.total * 100) : 100) + '%';
                document.getElementById('subtitleSummary').textContent =
                    batch.done + ' از ' + batch.total + ' · پیدا شد: ' + batch.stored + ' · نبود: ' + batch.none +
                    (batch.failed ? ' · خطا: ' + batch.failed : '') + (batch.running ? ' (در حال اجرا)' : (batch.aborted ? ' (متوقف شد)' : ' (تمام شد)'));
                document.getElementById('subtitleError').textContent = batch.error || '';
                if (batch.running) setTimeout(tick, 4000);
            } catch (error) {
                setTimeout(tick, 10000);
            }
        };
        setTimeout(tick, 2000);
    })();
</script>
@endpush
