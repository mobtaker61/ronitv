@extends('layouts.app')

@section('title', 'محتویات پوشه - ' . ($currentPath ?: 'ریشه') . ' - ' . config('app.name'))

@push('styles')
<style>
    /* استایل‌های اضافی خاص این صفحه */
    body {
        padding-top: 80px;
    }

    .section-title {
        border-right: 4px solid var(--netflix-red);
        padding-right: 1rem;
        border-left: none;
        padding-left: 0;
    }

    @media (max-width: 768px) {
        .page-title {
            font-size: 2rem;
        }

        .folder-card, .video-card {
            margin-bottom: 1rem;
        }
    }

    .tmdb-header {
        display: flex;
        gap: 1rem;
        align-items: flex-start;
        padding: 0.75rem;
        border-radius: 10px;
        background: rgba(255,255,255,0.05);
        border: 1px solid rgba(255,255,255,0.1);
    }
    [data-theme="light"] .tmdb-header {
        background: rgba(0,0,0,0.03);
        border-color: rgba(0,0,0,0.1);
    }
    .tmdb-poster {
        width: 80px;
        height: 120px;
        object-fit: cover;
        border-radius: 6px;
        flex-shrink: 0;
    }
    .tmdb-body {
        min-width: 0;
        flex: 1;
    }
    .tmdb-overview {
        font-size: 0.9rem;
        display: -webkit-box;
        -webkit-line-clamp: 3;
        line-clamp: 3;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
    @media (max-width: 576px) {
        .tmdb-poster { width: 60px; height: 90px; }
    }

    .file-name {
        flex: 1;
        text-align: right;
        direction: ltr;
        font-family: monospace;
        font-size: 1rem;
        overflow-x: auto;
    }
    .file-icons {
        min-width: 80px;
        display: flex;
        gap: 0.5rem;
        justify-content: flex-end;
    }
    .list-group-item {
        align-items: center !important;
        font-size: 1rem;
    }
</style>
@endpush

@section('content')
    <div class="container">
        <!-- Breadcrumb -->
        <nav aria-label="breadcrumb" class="mb-3">
            <ol class="breadcrumb">
                <li class="breadcrumb-item">
                    <a href="/">خانه</a>
                </li>
                @if($currentPath)
                    @php
                        $pathParts = explode('/', $currentPath);
                        $currentPathBuilder = '';
                    @endphp
                    @foreach($pathParts as $index => $part)
                        @php
                            $currentPathBuilder .= ($index > 0 ? '/' : '') . $part;
                        @endphp
                        <li class="breadcrumb-item {{ $index == count($pathParts) - 1 ? 'active' : '' }}">
                            @if($index == count($pathParts) - 1)
                                {{ $part }}
                            @else
                                <a href="/folder/{{ $currentPathBuilder }}">{{ $part }}</a>
                            @endif
                        </li>
                    @endforeach
                @endif
            </ol>
        </nav>
        <!-- اطلاعات TMDb (سریال و فیلم) -->
        @if(isset($tmdbInfo) && $tmdbInfo)
            @php
                $tmdbYear = substr($tmdbInfo['release_date'] ?? $tmdbInfo['first_air_date'] ?? '', 0, 4);
                $tmdbRuntime = $tmdbInfo['runtime'] ?? ($tmdbInfo['episode_run_time'][0] ?? null);
                $tmdbGenres = array_slice(array_column($tmdbInfo['genres'] ?? [], 'name'), 0, 3);
            @endphp
            <div class="tmdb-header mb-3">
                @if(!empty($tmdbInfo['poster_path']))
                    <img src="https://image.tmdb.org/t/p/w185{{ $tmdbInfo['poster_path'] }}" alt="پوستر" class="tmdb-poster">
                @endif
                <div class="tmdb-body">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                        <h4 class="mb-0">{{ $tmdbInfo['title'] ?? $tmdbInfo['name'] ?? '' }}</h4>
                        @if(!empty($tmdbInfo['vote_average']))
                            <span class="badge bg-primary">★ {{ number_format($tmdbInfo['vote_average'], 1) }}</span>
                        @endif
                        @if($tmdbYear)
                            <span class="badge bg-secondary">{{ $tmdbYear }}</span>
                        @endif
                        @if($tmdbRuntime)
                            <span class="badge bg-secondary">{{ $tmdbRuntime }} دقیقه</span>
                        @endif
                        @foreach($tmdbGenres as $genre)
                            <span class="badge border">{{ $genre }}</span>
                        @endforeach
                    </div>
                    <p class="tmdb-overview mb-1" title="{{ $tmdbInfo['overview'] ?? '' }}">{{ $tmdbInfo['overview'] ?: 'خلاصه‌ای موجود نیست.' }}</p>
                    @if(!empty($tmdbInfo['credits']['cast']))
                        <div class="small text-truncate">
                            <strong>بازیگران:</strong>
                            {{ implode('، ', array_column(array_slice($tmdbInfo['credits']['cast'], 0, 5), 'name')) }}
                        </div>
                    @endif
                </div>
            </div>
        @endif

        @if(session('success'))
            <div class="alert alert-success py-2">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="alert alert-danger py-2">{{ session('error') }}</div>
        @endif

        @if(count($seasonVideos) > 0 || count($videoFiles) > 0)
            @php
                $watchData = [
                    'admin' => $isAdmin,
                    'seasons' => count($seasonVideos) > 0
                        ? collect($seasonVideos)->map(fn ($videos, $name) => ['name' => $name, 'videos' => $videos])->values()->all()
                        : [['name' => null, 'videos' => $videoFiles]],
                ];
            @endphp
            <script type="application/json" id="watchData">{!! json_encode($watchData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>

            <div class="row flex-row-reverse" id="watch">
                <div class="col-md-5 order-md-1 mb-4">
                    <ul class="nav nav-tabs mb-3 justify-content-end" id="seasonTabs" role="tablist"></ul>
                    <ul class="list-group" id="episodeList"></ul>
                </div>
                <div class="col-md-7 order-md-2 mb-4">
                    <div class="card bg-black">
                        <div class="card-body p-2">
                            <video id="mainPlayer" class="video-js vjs-big-play-centered vjs-fluid" controls preload="metadata" playsinline></video>
                        </div>
                    </div>
                </div>
            </div>

            @if($isAdmin)
                <div class="modal fade" id="subtitleModal" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">زیرنویس: <span id="subtitleModalTitle" dir="ltr"></span></h5>
                                <button type="button" class="btn-close ms-0 me-auto" data-bs-dismiss="modal" aria-label="بستن"></button>
                            </div>
                            <div class="modal-body">
                                <input type="hidden" id="subtitleDeleteUrl" value="{{ route('subtitle.destroy') }}">
                                <div id="subtitleExisting" class="mb-3"></div>
                                <form method="POST" action="{{ route('subtitle.store') }}" enctype="multipart/form-data">
                                    @csrf
                                    <input type="hidden" name="video" id="subtitleVideo">
                                    <div class="mb-2">
                                        <label class="form-label" for="subtitleLang">زبان</label>
                                        <select name="lang" id="subtitleLang" class="form-select" required>
                                            @foreach($languages as $code => $label)
                                                <option value="{{ $code }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label" for="subtitleFile">فایل زیرنویس (SRT یا VTT، حداکثر ۲ مگابایت)</label>
                                        <input type="file" name="subtitle" id="subtitleFile" class="form-control" accept=".srt,.vtt" required>
                                    </div>
                                    <button type="submit" class="btn btn-primary"><i class="fas fa-upload me-1"></i>افزودن زیرنویس</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            @push('scripts')
                @vite(['resources/js/player.js'])
            @endpush
        @endif

        @if(count($subdirs) == 0 && count($videoFiles) == 0)
            <div class="empty-state">
                <i class="fas fa-folder-open"></i>
                <h4>این پوشه خالی است!</h4>
                <p>هیچ فایل یا پوشه‌ای در این مسیر یافت نشد.</p>
            </div>
        @endif

        <!-- دکمه بازگشت -->
        @if($currentPath)
            <div class="text-center mt-5">
                <a href="{{ url()->previous() }}" class="back-btn">
                    <i class="fas fa-arrow-left"></i>
                    بازگشت
                </a>
            </div>
        @endif
    </div>
@endsection
