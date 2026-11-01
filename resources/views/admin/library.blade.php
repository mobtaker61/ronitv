@extends('layouts.app')

@section('title', 'مدیریت کتابخانه - ' . config('app.name'))

@php
    $fmt = function (int $bytes): string {
        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $i => $unit) {
            if ($bytes < 1024 ** ($i + 1) || $unit === 'TB') {
                return rtrim(rtrim(number_format($bytes / 1024 ** $i, $i ? 1 : 0), '0'), '.') . ' ' . $unit;
            }
        }
    };
    $parts = $current === '' ? [] : explode('/', $current);
    $crumb = '';
@endphp

@section('content')
<div class="container" style="padding-top: 20px;">
    @include('admin._nav')

    @if(session('success'))<div class="alert alert-success py-2">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger py-2">{{ session('error') }}</div>@endif

    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('admin.library') }}">کتابخانه</a></li>
            @foreach($parts as $i => $part)
                @php $crumb .= ($i ? '/' : '') . $part; @endphp
                <li class="breadcrumb-item {{ $loop->last ? 'active' : '' }}">
                    @if($loop->last){{ $part }}@else<a href="{{ route('admin.library', ['path' => $crumb]) }}">{{ $part }}</a>@endif
                </li>
            @endforeach
        </ol>
    </nav>

    <div class="admin-card p-0 overflow-hidden">
        <table class="table table-hover align-middle mb-0 admin-table">
            <thead>
                <tr><th>نام</th><th style="width:130px">نوع</th><th style="width:110px">حجم</th><th style="width:130px">زیرنویس</th><th style="width:170px"></th></tr>
            </thead>
            <tbody>
                @foreach($entries['dirs'] as $dir)
                    <tr>
                        <td><a href="{{ route('admin.library', ['path' => $dir['path']]) }}"><i class="fas fa-folder text-warning me-2"></i>{{ $dir['name'] }}</a></td>
                        <td class="text-muted small">پوشه · {{ $dir['items'] }} مورد</td>
                        <td></td><td></td>
                        <td class="text-end">@include('admin._row-actions', ['row' => ['path' => $dir['path'], 'name' => $dir['name']], 'isDir' => true])</td>
                    </tr>
                @endforeach

                @foreach($entries['files'] as $file)
                    <tr>
                        <td dir="ltr" class="text-end">
                            <i class="fas {{ $file['video'] ? 'fa-film' : 'fa-file' }} text-secondary ms-2"></i>{{ $file['name'] }}
                        </td>
                        <td>
                            @if($file['kind'] === 'hls')<span class="badge bg-primary">HLS چندصدایی</span>
                            @elseif(in_array($file['kind'], ['mkv', 'avi', 'mov', 'wmv']))<span class="badge bg-warning text-dark">{{ strtoupper($file['kind']) }}</span>
                            @elseif($file['video'])<span class="badge bg-secondary">{{ strtoupper($file['kind']) }}</span>
                            @else<span class="text-muted small">فایل</span>@endif
                        </td>
                        <td class="small text-muted" dir="ltr">{{ $fmt($file['size']) }}</td>
                        <td>@foreach($file['subtitles'] as $lang)<span class="badge bg-success">{{ strtoupper($lang) }}</span> @endforeach</td>
                        <td class="text-end">@include('admin._row-actions', ['row' => $file, 'isDir' => false])</td>
                    </tr>
                @endforeach

                @if(! count($entries['dirs']) && ! count($entries['files']))
                    <tr><td colspan="5" class="text-center text-muted py-4">این پوشه خالی است.</td></tr>
                @endif
            </tbody>
        </table>
    </div>

    {{-- one dialog per action, filled in by the row buttons --}}
    <div class="modal fade" id="renameModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog"><form method="POST" action="{{ route('admin.rename') }}" class="modal-content">
            @csrf
            <input type="hidden" name="path">
            <div class="modal-header"><h5 class="modal-title">تغییر نام</h5><button type="button" class="btn-close ms-0 me-auto" data-bs-dismiss="modal" aria-label="بستن"></button></div>
            <div class="modal-body">
                <label class="form-label" for="renameName">نام جدید <small class="text-muted" id="renameHint"></small></label>
                <input type="text" id="renameName" name="name" class="form-control" dir="ltr" required maxlength="200">
                <div class="form-text" id="renameVideoNote">پسوند فایل حفظ می‌شود؛ زیرنویس‌ها و نسخه‌های هم‌نام (مثل MKV کنار MP4) هم تغییر نام می‌دهند.</div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary">ذخیره</button></div>
        </form></div>
    </div>

    <div class="modal fade" id="moveModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog"><form method="POST" action="{{ route('admin.move') }}" class="modal-content">
            @csrf
            <input type="hidden" name="path">
            <div class="modal-header"><h5 class="modal-title">جابه‌جایی <span class="text-muted small" id="moveTitle" dir="ltr"></span></h5><button type="button" class="btn-close ms-0 me-auto" data-bs-dismiss="modal" aria-label="بستن"></button></div>
            <div class="modal-body">
                <label class="form-label" for="moveDestination">پوشه‌ی مقصد</label>
                <select name="destination" id="moveDestination" class="form-select" dir="ltr">
                    <option value="">/ (ریشه‌ی کتابخانه)</option>
                    @foreach($folders as $folder)<option value="{{ $folder }}">{{ $folder }}</option>@endforeach
                </select>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary">جابه‌جا کن</button></div>
        </form></div>
    </div>

    <div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog"><form method="POST" action="{{ route('admin.delete') }}" class="modal-content">
            @csrf
            <input type="hidden" name="path">
            <div class="modal-header"><h5 class="modal-title text-danger">حذف</h5><button type="button" class="btn-close ms-0 me-auto" data-bs-dismiss="modal" aria-label="بستن"></button></div>
            <div class="modal-body">
                <p class="mb-1">این مورد برای همیشه حذف می‌شود:</p>
                <p class="fw-bold" dir="ltr" id="deleteTitle"></p>
                <p class="small text-muted mb-0" id="deleteNote"></p>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-danger">بله، حذف شود</button></div>
        </form></div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    document.addEventListener('click', function (event) {
        const button = event.target.closest('[data-admin-action]');
        if (!button) return;

        const action = button.dataset.adminAction;
        const modal = document.getElementById(action + 'Modal');
        const isVideo = button.dataset.video === '1';
        modal.querySelector('input[name="path"]').value = button.dataset.path;

        if (action === 'rename') {
            const name = button.dataset.name;
            modal.querySelector('#renameName').value = isVideo ? name.replace(/\.[^.]+$/, '') : name;
            modal.querySelector('#renameHint').textContent = isVideo ? '(بدون پسوند)' : '';
            modal.querySelector('#renameVideoNote').style.display = isVideo ? '' : 'none';
        } else if (action === 'move') {
            modal.querySelector('#moveTitle').textContent = button.dataset.name;
        } else if (action === 'delete') {
            modal.querySelector('#deleteTitle').textContent = button.dataset.name;
            modal.querySelector('#deleteNote').textContent = button.dataset.dir === '1'
                ? 'تمام فایل‌ها و پوشه‌های داخل آن هم حذف می‌شوند.'
                : (isVideo ? 'زیرنویس‌ها و فایل‌های هم‌نام (و در HLS، قطعه‌ها) هم حذف می‌شوند.' : '');
        }

        window.bootstrap.Modal.getOrCreateInstance(modal).show();
    });
</script>
@endpush
