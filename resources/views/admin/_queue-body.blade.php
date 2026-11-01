@php
    $duration = function (?int $seconds): string {
        if ($seconds === null) {
            return '';
        }
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        return $h ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    };
    $queueNames = ['media' => 'تبدیل ویدیو', 'default' => 'دانلود و زیرنویس'];
    $totalRunning = array_sum(array_column($q['queues'], 'running'));
@endphp

@if($q['worker']['stalled'])
    <div class="alert alert-danger">
        <strong>worker متوقف به نظر می‌رسد.</strong> {{ $q['readyNow'] }} کار آماده منتظر است ولی
        @if($q['worker']['secondsAgo'] !== null) {{ intdiv($q['worker']['secondsAgo'], 60) }} دقیقه است فعالیتی دیده نشده. @else از زمان ری‌استارت سرور هیچ فعالیتی دیده نشده. @endif
        <div class="small mt-1">روی سرور بررسی کنید: <code dir="ltr">sudo supervisorctl status ronitv-worker:*</code> و در صورت نیاز <code dir="ltr">sudo supervisorctl restart ronitv-worker:*</code></div>
    </div>
@endif

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3"><div class="admin-card h-100"><div class="text-muted small">در حال اجرا</div><div class="fs-3">{{ $totalRunning }}</div></div></div>
    <div class="col-6 col-md-3"><div class="admin-card h-100"><div class="text-muted small">منتظر</div><div class="fs-3">{{ $q['waitingTotal'] }}</div><div class="small text-muted">{{ $q['readyNow'] }} آماده · {{ $q['waitingTotal'] - $q['readyNow'] }} زمان‌بندی‌شده</div></div></div>
    <div class="col-6 col-md-3"><div class="admin-card h-100"><div class="text-muted small">شکست‌خورده</div><div class="fs-3 {{ $q['failedTotal'] ? 'text-danger' : '' }}">{{ $q['failedTotal'] }}</div></div></div>
    <div class="col-6 col-md-3"><div class="admin-card h-100">
        <div class="text-muted small">worker</div>
        @if($totalRunning)<div class="fs-5 text-success">مشغول کار</div>
        @elseif($q['worker']['stalled'])<div class="fs-5 text-danger">متوقف؟</div>
        @elseif($q['worker']['secondsAgo'] !== null)<div class="fs-5 text-success">فعال</div><div class="small text-muted">آخرین فعالیت {{ $q['worker']['secondsAgo'] }} ثانیه پیش</div>
        @else<div class="fs-5 text-muted">فعالیتی ثبت نشده</div>@endif
    </div></div>
</div>

<div class="admin-card mb-3">
    <h6 class="mb-3"><i class="fas fa-gears me-1"></i>در حال اجرا</h6>
    @forelse($q['running'] as $job)
        <div class="d-flex flex-wrap justify-content-between gap-2 py-2 border-bottom">
            <div>
                <span class="badge bg-primary">{{ $job['title'] }}</span>
                <span dir="ltr" class="ms-2">{{ $job['label'] }}</span>
                @if($job['detail'])<span class="small text-muted ms-2">{{ $job['detail'] }}</span>@endif
            </div>
            <div class="text-muted small" dir="ltr">{{ $duration($job['runningFor']) }} · تلاش {{ $job['attempts'] }}</div>
        </div>
    @empty
        <div class="text-muted small">کاری در حال اجرا نیست.</div>
    @endforelse
</div>

<div class="admin-card mb-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="mb-0"><i class="fas fa-hourglass-half me-1"></i>منتظر ({{ $q['waitingTotal'] }})</h6>
        <div class="d-flex gap-2">
            @foreach($q['queues'] as $name => $info)
                @if($info['waiting'] > 0)
                    <form method="POST" action="{{ route('admin.queue.cancel') }}" onsubmit="return confirm('همه‌ی {{ $info['waiting'] }} کار منتظر «{{ $queueNames[$name] ?? $name }}» لغو شود؟ کار در حال اجرا ادامه می‌دهد.')">
                        @csrf<input type="hidden" name="queue" value="{{ $name }}">
                        <button type="submit" class="btn btn-sm btn-outline-secondary">لغو منتظرهای «{{ $queueNames[$name] ?? $name }}» ({{ $info['waiting'] }})</button>
                    </form>
                @endif
            @endforeach
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 admin-table">
            <tbody>
                @forelse($q['waiting'] as $job)
                    <tr>
                        <td style="width:130px"><span class="badge bg-secondary">{{ $job['title'] }}</span></td>
                        <td dir="ltr" class="text-end">{{ $job['label'] }} @if($job['detail'])<span class="small text-muted">· {{ $job['detail'] }}</span>@endif</td>
                        <td class="small text-muted" style="width:150px">
                            @if($job['scheduledIn'])در {{ $duration($job['scheduledIn']) }}@else آماده@endif
                            @if($job['attempts'])· تلاش {{ $job['attempts'] }}@endif
                        </td>
                        <td style="width:70px" class="text-end">
                            <form method="POST" action="{{ route('admin.queue.cancel') }}">@csrf<input type="hidden" name="id" value="{{ $job['id'] }}">
                                <button type="submit" class="btn btn-sm btn-outline-danger py-0" title="لغو این کار"><i class="fas fa-xmark"></i></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td class="text-muted small">صف خالی است.</td></tr>
                @endforelse
                @if($q['waitingTotal'] > count($q['waiting']))
                    <tr><td colspan="4" class="text-muted small">و {{ $q['waitingTotal'] - count($q['waiting']) }} کار دیگر...</td></tr>
                @endif
            </tbody>
        </table>
    </div>
</div>

<div class="admin-card">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="mb-0"><i class="fas fa-triangle-exclamation me-1"></i>شکست‌خورده ({{ $q['failedTotal'] }})</h6>
        @if($q['failedTotal'])
            <div class="d-flex gap-2">
                <form method="POST" action="{{ route('admin.queue.retry') }}" onsubmit="return confirm('همه‌ی شکست‌خورده‌ها دوباره در صف قرار بگیرند؟')">@csrf
                    <button type="submit" class="btn btn-sm btn-outline-primary"><i class="fas fa-rotate me-1"></i>تلاش دوباره‌ی همه</button>
                </form>
                <form method="POST" action="{{ route('admin.queue.forget') }}" onsubmit="return confirm('فهرست شکست‌ها پاک شود؟ (به فایل‌ها کاری ندارد)')">@csrf
                    <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="fas fa-broom me-1"></i>پاک‌کردن فهرست</button>
                </form>
            </div>
        @endif
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 admin-table">
            <tbody>
                @forelse($q['failed'] as $job)
                    <tr>
                        <td style="width:130px"><span class="badge bg-danger">{{ $job['title'] }}</span><div class="small text-muted" dir="ltr">{{ $job['failedAt'] }}</div></td>
                        <td>
                            <div dir="ltr" class="text-end">{{ $job['label'] }}</div>
                            <div class="small text-danger" dir="ltr" style="text-align:right">{{ $job['error'] }}</div>
                            @if(! $job['retryable'])<div class="small text-muted">فایل مبدأ ناقص است؛ تلاش دوباره بی‌فایده است. فایل را دوباره بارگذاری کنید.</div>@endif
                        </td>
                        <td style="width:140px" class="text-end">
                            <div class="btn-group btn-group-sm">
                                @if($job['retryable'])
                                    <form method="POST" action="{{ route('admin.queue.retry') }}">@csrf<input type="hidden" name="uuid" value="{{ $job['uuid'] }}"><button type="submit" class="btn btn-outline-primary" title="تلاش دوباره"><i class="fas fa-rotate"></i></button></form>
                                @endif
                                <form method="POST" action="{{ route('admin.queue.forget') }}">@csrf<input type="hidden" name="uuid" value="{{ $job['uuid'] }}"><button type="submit" class="btn btn-outline-secondary" title="حذف از فهرست"><i class="fas fa-trash"></i></button></form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td class="text-muted small">شکستی ثبت نشده است.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
