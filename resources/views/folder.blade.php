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

        <!-- فایل‌های ویدیو -->
        @if(count($videoFiles) > 0)
            <h2 class="section-title">
                <i class="fas fa-video me-2"></i>ویدیوها
            </h2>
            <div class="row">
                @foreach($videoFiles as $file)
                    @php
                        $fileName = basename($file);
                        $videoPath = "/storage/videos/$currentPath/$fileName";
                    @endphp
                    <div class="col-md-6 col-lg-4">
                        <div class="video-card">
                            <video class="video-player" controls preload="metadata">
                                <source src="{{ $videoPath }}" type="video/mp4">
                                مرورگر شما از پخش ویدیو پشتیبانی نمی‌کند.
                            </video>
                            <div class="video-info">
                                <div class="video-title">
                                    <i class="fas fa-play video-icon"></i>
                                    {{ $fileName }}
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
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
