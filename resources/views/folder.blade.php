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
                            <span class="badge border text-body-secondary">{{ $genre }}</span>
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

        @if(count($seasonVideos) > 0)
            <!-- نمایش تب فصل‌ها و لیست ویدیوها با AJAX -->
            <div class="row flex-row-reverse">
                <div class="col-md-5 order-md-1 mb-4">
                    <ul class="nav nav-tabs mb-3 justify-content-end" id="seasonTabs" role="tablist">
                        @foreach(array_keys($seasonVideos) as $i => $season)
                            <li class="nav-item" role="presentation">
                                <button class="nav-link{{ $i==0 ? ' active' : '' }}" id="tab-{{ $season }}" data-season="{{ $season }}" data-series="{{ $currentPath }}" type="button" role="tab">{{ $season }}</button>
                            </li>
                        @endforeach
                    </ul>
                    <ul class="list-group" id="seasonVideoList">
                        @foreach($seasonVideos[array_keys($seasonVideos)[0]] as $file)
                            @php $fileName = basename($file); @endphp
                            <li class="list-group-item d-flex justify-content-between align-items-center flex-row-reverse">
                                <span class="file-icons">
                                    <a href="{{ asset('storage/videos/' . $currentPath . '/' . array_keys($seasonVideos)[0] . '/' . $fileName) }}" class="btn btn-sm btn-outline-success ms-2" download title="دانلود"><i class="fas fa-download"></i></a>
                                    <button class="btn btn-sm btn-outline-primary play-btn" data-src="{{ asset('storage/videos/' . $currentPath . '/' . array_keys($seasonVideos)[0] . '/' . $fileName) }}" title="تماشا"><i class="fas fa-play"></i></button>
                                </span>
                                <span class="file-name">{{ $fileName }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="col-md-7 order-md-2 mb-4">
                    <div class="card bg-black">
                        <div class="card-body">
                            <div class="ratio ratio-16x9">
                                <video id="mainPlayer" class="w-100 rounded bg-black" controls>
                                    <source src="" type="video/mp4">
                                    مرورگر شما از پخش ویدیو پشتیبانی نمی‌کند.
                                </video>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const player = document.getElementById('mainPlayer');
                    function bindPlayBtns() {
                        document.querySelectorAll('.play-btn').forEach(btn => {
                            btn.addEventListener('click', function() {
                                const src = this.getAttribute('data-src');
                                player.querySelector('source').setAttribute('src', src);
                                player.load();
                                player.play();
                            });
                        });
                    }
                    bindPlayBtns();
                    document.querySelectorAll('#seasonTabs .nav-link').forEach(tab => {
                        tab.addEventListener('click', function() {
                            document.querySelectorAll('#seasonTabs .nav-link').forEach(t => t.classList.remove('active'));
                            this.classList.add('active');
                            const season = this.getAttribute('data-season');
                            const series = this.getAttribute('data-series');
                            fetch(`/ajax/season-videos/${series}/${season}`)
                                .then(res => res.json())
                                .then(files => {
                                    const list = document.getElementById('seasonVideoList');
                                    list.innerHTML = '';
                                    files.forEach(file => {
                                        const li = document.createElement('li');
                                        li.className = 'list-group-item d-flex justify-content-between align-items-center flex-row-reverse';
                                        li.innerHTML = `<span class='file-icons'>
                                            <a href='${file.url}' class='btn btn-sm btn-outline-success ms-2' download title='دانلود'><i class='fas fa-download'></i></a>
                                            <button class='btn btn-sm btn-outline-primary play-btn' data-src='${file.url}' title='تماشا'><i class='fas fa-play'></i></button>
                                            </span>
                                            <span class='file-name'>${file.name}</span>`;
                                        list.appendChild(li);
                                    });
                                    bindPlayBtns();
                                });
                        });
                    });
                });
            </script>
        @else
            <!-- حالت فیلم یا پوشه بدون فصل -->
            <div class="row flex-row-reverse">
                <div class="col-md-5 order-md-1 mb-4">
                    <ul class="list-group">
                        @foreach($videoFiles as $file)
                            @php $fileName = basename($file); @endphp
                            <li class="list-group-item d-flex justify-content-between align-items-center flex-row-reverse">
                                <span class="file-icons">
                                    <a href="{{ asset('storage/videos/' . $currentPath . '/' . $fileName) }}" class="btn btn-sm btn-outline-success ms-2" download title="دانلود"><i class="fas fa-download"></i></a>
                                    <button class="btn btn-sm btn-outline-primary play-btn" data-src="{{ asset('storage/videos/' . $currentPath . '/' . $fileName) }}" title="تماشا"><i class="fas fa-play"></i></button>
                                </span>
                                <span class="file-name">{{ $fileName }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="col-md-7 order-md-2 mb-4">
                    <div class="card bg-black">
                        <div class="card-body">
                            <div class="ratio ratio-16x9">
                                <video id="mainPlayer" class="w-100 rounded bg-black" controls>
                                    <source src="" type="video/mp4">
                                    مرورگر شما از پخش ویدیو پشتیبانی نمی‌کند.
                                </video>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const player = document.getElementById('mainPlayer');
                    document.querySelectorAll('.play-btn').forEach(btn => {
                        btn.addEventListener('click', function() {
                            const src = this.getAttribute('data-src');
                            player.querySelector('source').setAttribute('src', src);
                            player.load();
                            player.play();
                        });
                    });
                });
            </script>
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
