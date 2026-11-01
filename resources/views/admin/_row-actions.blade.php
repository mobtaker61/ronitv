@php
    $isDir = $isDir ?? false;
    $isVideo = (bool) ($row['video'] ?? false);
    $viewPath = $isDir ? $row['path'] : (dirname($row['path']) === '.' ? '' : dirname($row['path']));
@endphp
<div class="btn-group btn-group-sm" role="group">
    @if($isDir || $isVideo)
        <a class="btn btn-outline-primary" title="مشاهده در سایت" href="{{ url('/folder/' . $viewPath) }}" target="_blank" rel="noopener"><i class="fas fa-eye"></i></a>
    @endif
    @if(! empty($tmdb))
        <button type="button" class="btn btn-outline-info" title="انتخاب عنوان از TMDb" data-admin-action="tmdb" data-path="{{ $row['path'] }}" data-name="{{ $row['name'] }}" data-kind="{{ ($row['series'] ?? false) ? 'tv' : 'movie' }}"><i class="fas fa-film"></i></button>
    @endif
    @if(! empty($tmdb))
        <form method="POST" action="{{ route('admin.subtitles.batch') }}" class="d-inline">
            @csrf
            <input type="hidden" name="folder" value="{{ $row['name'] }}">
            <input type="hidden" name="only_confirmed" value="0">
            <button type="submit" class="btn btn-outline-success" title="دریافت زیرنویس فارسی ویدیوهای این پوشه از SubSource" style="border-radius:0"><i class="fas fa-closed-captioning"></i></button>
        </form>
    @endif
    @foreach([['rename', 'تغییر نام', 'pen', 'secondary'], ['move', 'جابه‌جایی', 'arrows-up-down-left-right', 'secondary'], ['delete', 'حذف', 'trash', 'danger']] as [$action, $title, $icon, $color])
        <button type="button" class="btn btn-outline-{{ $color }}" title="{{ $title }}" data-admin-action="{{ $action }}" data-path="{{ $row['path'] }}" data-name="{{ $row['name'] }}" data-video="{{ $isVideo ? 1 : 0 }}" data-dir="{{ $isDir ? 1 : 0 }}"><i class="fas fa-{{ $icon }}"></i></button>
    @endforeach
</div>
