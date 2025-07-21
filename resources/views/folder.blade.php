@extends('layouts.app')

@section('title', 'محتویات پوشه - ' . ($currentPath ?: 'ریشه') . ' - ' . env('APP_NAME'))

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
</style>
@endpush

@section('content')
    <div class="container">
        <!-- اطلاعات TMDb -->
        @if(isset($tmdbInfo) && $tmdbInfo)
            <div class="row mb-4 align-items-center">
                <div class="col-md-3">
                    @if(isset($tmdbInfo['poster_path']))
                        <img src="https://image.tmdb.org/t/p/w500{{ $tmdbInfo['poster_path'] }}" alt="پوستر" class="img-fluid rounded shadow">
                    @endif
                </div>
                <div class="col-md-9">
                    <h2 class="mb-2">{{ $tmdbInfo['title'] ?? $tmdbInfo['name'] ?? '' }}</h2>
                    <div class="mb-2">
                        <span class="badge bg-primary fs-6">امتیاز: {{ $tmdbInfo['vote_average'] ?? '-' }}</span>
                        @if(isset($tmdbInfo['release_date']) || isset($tmdbInfo['first_air_date']))
                            <span class="badge bg-secondary fs-6">سال: {{ isset($tmdbInfo['release_date']) ? substr($tmdbInfo['release_date'],0,4) : (isset($tmdbInfo['first_air_date']) ? substr($tmdbInfo['first_air_date'],0,4) : '-') }}</span>
                        @endif
                    </div>
                    <p class="mb-2">{{ $tmdbInfo['overview'] ?? 'خلاصه‌ای موجود نیست.' }}</p>
                    @if(isset($tmdbInfo['credits']['cast']))
                        <div class="mb-2">
                            <strong>بازیگران:</strong>
                            @foreach(array_slice($tmdbInfo['credits']['cast'],0,5) as $actor)
                                <span class="badge bg-light text-dark me-1">{{ $actor['name'] }}</span>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        @endif
        <!-- Breadcrumb -->
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item">
                    <a href="/"><i class="fas fa-home me-1"></i>خانه</a>
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

        <h1 class="page-title">
            <i class="fas fa-folder-open me-3"></i>{{ $currentPath ?: 'پوشه اصلی' }}
        </h1>

        <!-- زیرپوشه‌ها -->
        @if(count($subdirs) > 0)
            <h2 class="section-title">
                <i class="fas fa-folder me-2"></i>پوشه‌ها
            </h2>
            <div class="row">
                @foreach($subdirs as $subdir)
                    @php $sub = basename($subdir); @endphp
                    <div class="col-md-4 col-lg-3 col-sm-6">
                        <div class="folder-card">
                            <a href="/folder/{{ $currentPath }}/{{ $sub }}" class="folder-link">
                                <i class="fas fa-folder folder-icon"></i>
                                <span>{{ $sub }}</span>
                            </a>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        @if(count($seasonVideos) > 0)
            <!-- نمایش تب فصل‌ها و لیست ویدیوها -->
            <div class="row">
                <div class="col-md-5 order-md-2 mb-4">
                    <ul class="nav nav-tabs mb-3" id="seasonTabs" role="tablist">
                        @foreach(array_keys($seasonVideos) as $i => $season)
                            <li class="nav-item" role="presentation">
                                <button class="nav-link{{ $i==0 ? ' active' : '' }}" id="tab-{{ $season }}" data-bs-toggle="tab" data-bs-target="#season-{{ $season }}" type="button" role="tab" aria-controls="season-{{ $season }}" aria-selected="{{ $i==0 ? 'true' : 'false' }}">{{ $season }}</button>
                            </li>
                        @endforeach
                    </ul>
                    <div class="tab-content" id="seasonTabsContent">
                        @foreach($seasonVideos as $season => $files)
                            <div class="tab-pane fade{{ $loop->first ? ' show active' : '' }}" id="season-{{ $season }}" role="tabpanel">
                                <ul class="list-group">
                                    @foreach($files as $file)
                                        @php $fileName = basename($file); @endphp
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <span>{{ $fileName }}</span>
                                            <span>
                                                <a href="{{ asset('storage/videos/' . $currentPath . '/' . $season . '/' . $fileName) }}" class="btn btn-sm btn-outline-success me-2" download title="دانلود"><i class="fas fa-download"></i></a>
                                                <button class="btn btn-sm btn-outline-primary play-btn" data-src="{{ asset('storage/videos/' . $currentPath . '/' . $season . '/' . $fileName) }}" title="تماشا"><i class="fas fa-play"></i></button>
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="col-md-7 order-md-1 mb-4">
                    <div class="card">
                        <div class="card-body">
                            <video id="mainPlayer" class="w-100 rounded" controls poster="{{ isset($tmdbInfo['poster_path']) ? 'https://image.tmdb.org/t/p/w500'.$tmdbInfo['poster_path'] : '' }}">
                                <source src="" type="video/mp4">
                                مرورگر شما از پخش ویدیو پشتیبانی نمی‌کند.
                            </video>
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
        @else
            <!-- حالت فیلم یا پوشه بدون فصل -->
            <div class="row">
                <div class="col-md-5 order-md-2 mb-4">
                    <ul class="list-group">
                        @foreach($videoFiles as $file)
                            @php $fileName = basename($file); @endphp
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span>{{ $fileName }}</span>
                                <span>
                                    <a href="{{ asset('storage/videos/' . $currentPath . '/' . $fileName) }}" class="btn btn-sm btn-outline-success me-2" download title="دانلود"><i class="fas fa-download"></i></a>
                                    <button class="btn btn-sm btn-outline-primary play-btn" data-src="{{ asset('storage/videos/' . $currentPath . '/' . $fileName) }}" title="تماشا"><i class="fas fa-play"></i></button>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="col-md-7 order-md-1 mb-4">
                    <div class="card">
                        <div class="card-body">
                            <video id="mainPlayer" class="w-100 rounded" controls poster="{{ isset($tmdbInfo['poster_path']) ? 'https://image.tmdb.org/t/p/w500'.$tmdbInfo['poster_path'] : '' }}">
                                <source src="" type="video/mp4">
                                مرورگر شما از پخش ویدیو پشتیبانی نمی‌کند.
                            </video>
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
