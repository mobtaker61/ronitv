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
                        @php $title = $current === '' ? ($titles[$dir['name']] ?? null) : null; @endphp
                        <td>
                            <a href="{{ route('admin.library', ['path' => $dir['path']]) }}"><i class="fas fa-folder text-warning me-2"></i>{{ $dir['name'] }}</a>
                            @if($current === '')
                                <div class="small mt-1 d-flex align-items-center gap-2">
                                    @if($title?->isMatched())
                                        @if($title->poster_path)<img src="https://image.tmdb.org/t/p/w92{{ $title->poster_path }}" alt="" width="28" height="42" class="rounded" loading="lazy">@endif
                                        <span class="text-muted">{{ $title->title }}{{ $title->year ? ' (' . $title->year . ')' : '' }} · {{ $title->media_type === 'tv' ? 'سریال' : 'فیلم' }}</span>
                                        @if($title->isManual())<span class="badge bg-success">تأیید شده</span>@else<span class="badge bg-warning text-dark" title="از روی نام پوشه حدس زده شده؛ اگر درست نیست از دکمه‌ی TMDb انتخاب کنید">حدسی</span>@endif
                                    @else
                                        <span class="badge bg-secondary">بدون اطلاعات TMDb</span>
                                    @endif
                                </div>
                            @endif
                        </td>
                        <td class="text-muted small">پوشه · {{ $dir['items'] }} مورد</td>
                        <td></td><td></td>
                        <td class="text-end">@include('admin._row-actions', ['row' => ['path' => $dir['path'], 'name' => $dir['name'], 'series' => $dir['series']], 'isDir' => true, 'tmdb' => $current === ''])</td>
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
    <div class="modal fade" id="tmdbModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content"
                 data-search-url="{{ route('admin.titles.search') }}"
                 data-assign-url="{{ route('admin.titles.assign') }}"
                 data-refresh-url="{{ route('admin.titles.refresh') }}"
                 data-reset-url="{{ route('admin.titles.reset') }}">
                <div class="modal-header">
                    <h5 class="modal-title">انتخاب عنوان از TMDb <span class="text-muted small" id="tmdbFolder" dir="ltr"></span></h5>
                    <button type="button" class="btn-close ms-0 me-auto" data-bs-dismiss="modal" aria-label="بستن"></button>
                </div>
                <div class="modal-body">
                    <form id="tmdbSearchForm" class="row g-2 mb-2">
                        <div class="col-sm-6"><input type="text" class="form-control" id="tmdbQuery" placeholder="نام فیلم/سریال" dir="ltr"></div>
                        <div class="col-6 col-sm-2"><input type="number" class="form-control" id="tmdbYear" placeholder="سال" min="1880" max="2100"></div>
                        <div class="col-6 col-sm-2">
                            <select class="form-select" id="tmdbType"><option value="">همه</option><option value="movie">فیلم</option><option value="tv">سریال</option></select>
                        </div>
                        <div class="col-sm-2 d-grid"><button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button></div>
                    </form>
                    <div id="tmdbStatus" class="small text-muted mb-2"></div>
                    <div id="tmdbResults" class="list-group mb-3"></div>

                    <div class="border-top pt-3">
                        <label class="form-label small" for="tmdbReference">پیدا نشد؟ لینک یا شناسه‌ی TMDb را مستقیم بدهید</label>
                        <div class="input-group" dir="ltr">
                            <input type="text" class="form-control" id="tmdbReference" placeholder="https://www.themoviedb.org/movie/603  یا  tv/1668">
                            <button class="btn btn-outline-primary" type="button" id="tmdbUseReference">اتصال</button>
                        </div>
                    </div>
                </div>
                <div class="modal-footer justify-content-between">
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="tmdbRefresh"><i class="fas fa-rotate me-1"></i>به‌روزرسانی اطلاعات</button>
                        <button type="button" class="btn btn-sm btn-outline-danger" id="tmdbReset"><i class="fas fa-link-slash me-1"></i>حذف اتصال</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

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
        if (!button || button.dataset.adminAction === 'tmdb') return;

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

<script>
    (function () {
        const modalEl = document.getElementById('tmdbModal');
        if (!modalEl) return;

        const box = modalEl.querySelector('.modal-content');
        const csrf = document.querySelector('meta[name="csrf-token"]').content;
        const results = document.getElementById('tmdbResults');
        const status = document.getElementById('tmdbStatus');
        let folder = null;

        const el = (tag, className, text) => {
            const node = document.createElement(tag);
            if (className) node.className = className;
            if (text !== undefined) node.textContent = text;
            return node;
        };
        const say = (text, error = false) => {
            status.textContent = text;
            status.className = 'small mb-2 ' + (error ? 'text-danger' : 'text-muted');
        };

        async function call(method, url, data) {
            const response = await fetch(method === 'GET' ? url + '?' + new URLSearchParams(data) : url, {
                method,
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest', ...(method === 'POST' ? { 'Content-Type': 'application/json' } : {}) },
                body: method === 'POST' ? JSON.stringify(data) : undefined,
            });
            const json = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(json.message || ('خطا (' + response.status + ')'));
            return json;
        }

        async function post(url, data) {
            say('در حال انجام...');
            try {
                const result = await call('POST', url, { folder, ...data });
                say(result.message);
                setTimeout(() => window.location.reload(), 900);
            } catch (error) {
                say(error.message, true);
            }
        }

        function render(list, current) {
            results.replaceChildren(...list.map((item) => {
                const isCurrent = current && current.tmdb_id === item.tmdb_id && current.type === item.type;
                const row = el('div', 'list-group-item d-flex gap-3 align-items-start' + (isCurrent ? ' active-title' : ''));

                if (item.poster_path) {
                    const img = el('img', 'rounded flex-shrink-0');
                    img.src = 'https://image.tmdb.org/t/p/w92' + item.poster_path;
                    img.width = 46; img.height = 69; img.loading = 'lazy'; img.alt = '';
                    row.append(img);
                } else {
                    row.append(el('div', 'bg-secondary rounded flex-shrink-0', ''));
                    row.lastChild.style.cssText = 'width:46px;height:69px';
                }

                const info = el('div', 'flex-grow-1');
                const head = el('div', 'd-flex flex-wrap gap-2 align-items-center');
                head.append(el('strong', null, item.title || item.original_title));
                if (item.year) head.append(el('span', 'text-muted', '(' + item.year + ')'));
                head.append(el('span', 'badge bg-' + (item.type === 'tv' ? 'info text-dark' : 'primary'), item.type === 'tv' ? 'سریال' : 'فیلم'));
                if (item.vote_average) head.append(el('span', 'badge bg-secondary', '★ ' + item.vote_average));
                if (isCurrent) head.append(el('span', 'badge bg-success', 'فعلی'));
                info.append(head);
                if (item.original_title && item.original_title !== item.title) info.append(el('div', 'small text-muted', item.original_title));
                if (item.overview) info.append(el('div', 'small', item.overview));
                info.append(el('div', 'small text-muted', 'TMDb ' + item.tmdb_id));

                const pick = el('button', 'btn btn-sm btn-outline-success flex-shrink-0', 'انتخاب');
                pick.type = 'button';
                pick.addEventListener('click', () => post(box.dataset.assignUrl, { tmdb_id: item.tmdb_id, type: item.type }));

                row.append(info, pick);
                return row;
            }));
        }

        async function search() {
            say('در حال جستجو...');
            results.replaceChildren();
            try {
                const params = { folder, q: document.getElementById('tmdbQuery').value.trim() };
                if (document.getElementById('tmdbYear').value) params.year = document.getElementById('tmdbYear').value;
                if (document.getElementById('tmdbType').value) params.type = document.getElementById('tmdbType').value;
                const data = await call('GET', box.dataset.searchUrl, params);

                document.getElementById('tmdbQuery').value = data.query;
                document.getElementById('tmdbYear').value = data.year || '';
                say(data.candidates.length ? data.candidates.length + ' نتیجه با این نام؛ درست‌ترین را انتخاب کنید.' : 'نتیجه‌ای پیدا نشد. نام یا سال را تغییر دهید.', !data.candidates.length);
                render(data.candidates, data.current);
            } catch (error) {
                say(error.message, true);
            }
        }

        document.addEventListener('click', function (event) {
            const button = event.target.closest('[data-admin-action="tmdb"]');
            if (!button) return;

            folder = button.dataset.name;
            document.getElementById('tmdbFolder').textContent = folder;
            document.getElementById('tmdbQuery').value = '';
            document.getElementById('tmdbYear').value = '';
            document.getElementById('tmdbType').value = button.dataset.kind || '';
            document.getElementById('tmdbReference').value = '';
            window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
            search();
        });

        document.getElementById('tmdbSearchForm').addEventListener('submit', (event) => { event.preventDefault(); search(); });
        document.getElementById('tmdbUseReference').addEventListener('click', () => {
            const reference = document.getElementById('tmdbReference').value.trim();
            if (reference) post(box.dataset.assignUrl, { reference });
        });
        document.getElementById('tmdbRefresh').addEventListener('click', () => post(box.dataset.refreshUrl, {}));
        document.getElementById('tmdbReset').addEventListener('click', () => {
            if (window.confirm('اتصال این پوشه به TMDb حذف شود؟')) post(box.dataset.resetUrl, {});
        });
    })();
</script>
@endpush
