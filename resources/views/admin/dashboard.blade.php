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
            <div class="admin-card h-100">
                <div class="text-muted small mb-1">زیرنویس فارسی</div>
                <div class="fs-4">{{ $stats['noSubtitle'] }} ویدیو بدون زیرنویس</div>
                <div class="small text-muted mt-1">برای دریافت: دکمه‌ی CC کنار هر قسمت، یا <code dir="ltr">php artisan subtitles:fetch --all</code></div>
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
