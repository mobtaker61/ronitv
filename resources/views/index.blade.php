@extends('layouts.app')

@section('title', 'سالن فروش بلیط - ' . env('APP_NAME'))

@push('styles')
<style>
    .video-grid {
        padding: 0 2rem;
    }
    .cover-image, .no-cover {
        width: 100%;
        aspect-ratio: 27/44;
        min-height: 150px;
        max-height: 260px;
        object-fit: cover;
        display: block;
        border-radius: 8px;
        background: linear-gradient(45deg, var(--netflix-red) 0%, #ff6b6b 100%);
        transition: all 0.3s ease;
    }
    .no-cover {
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 3rem;
    }
    @media (max-width: 768px) {
        .video-grid { padding: 0 1rem; }
        .cover-image, .no-cover { min-height: 100px; max-height: 180px; }
    }
</style>
@endpush

@section('content')
    <!-- Hero Section -->
    <div class="hero-section d-none">
        <div class="container">
            <h1 class="hero-title">
                <i class="fas fa-film me-3"></i>کتابخانه ویدیو
            </h1>
            <p class="hero-subtitle">مجموعه‌ای از بهترین ویدیوها و سریال‌ها</p>
        </div>
    </div>

    <div class="container">
        <!-- دکمه آپلود -->
        <div class="text-end mb-4">
            <a href="{{ route('upload.form') }}" class="btn btn-primary">
                <i class="fas fa-upload me-2"></i>آپلود ویدیو جدید
            </a>
        </div>

        <!-- فیلم‌ها -->
        @if(count($films) > 0)
            <h2 class="section-title mb-4"><i class="fas fa-clapperboard me-2"></i>فیلم‌ها</h2>
            <div class="video-grid mb-5">
                <div class="row">
                    @foreach($films as $dir)
                        @php
                            $folderName = basename($dir);
                            $coverPath = "/storage/videos/$folderName/_cover.jpg";
                            $tmdbPoster = $filmPosters[$dir] ?? null;
                        @endphp
                        <div class="col-md-3 col-lg-2 col-sm-4">
                            <div class="video-card position-relative">
                                <a href="/folder/{{ $folderName }}" class="text-decoration-none">
                                    @if($tmdbPoster)
                                        <img src="https://image.tmdb.org/t/p/w500{{ $tmdbPoster }}" alt="{{ $folderName }}" class="cover-image">
                                    @elseif(file_exists(public_path("storage/videos/$folderName/_cover.jpg")))
                                        <img src="{{ $coverPath }}" alt="{{ $folderName }}" class="cover-image">
                                    @else
                                        <div class="no-cover d-flex align-items-center justify-content-center">
                                            <i class="fas fa-film fa-2x"></i>
                                        </div>
                                    @endif
                                    <span class="type-badge film"><i class="fas fa-film"></i>فیلم</span>
                                    <div class="card-overlay">
                                        <h5 class="folder-name">{{ $folderName }}</h5>
                                    </div>
                                    <button class="play-button"><i class="fas fa-play"></i></button>
                                </a>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <!-- سریال‌ها -->
        @if(count($series) > 0)
            <h2 class="section-title mb-4"><i class="fas fa-layer-group me-2"></i>سریال‌ها</h2>
            <div class="video-grid">
                <div class="row">
                    @foreach($series as $dir)
                        @php
                            $folderName = basename($dir);
                            $coverPath = "/storage/videos/$folderName/_cover.jpg";
                            $tmdbPoster = $seriesPosters[$dir] ?? null;
                        @endphp
                        <div class="col-md-3 col-lg-2 col-sm-4">
                            <div class="video-card position-relative">
                                <a href="/folder/{{ $folderName }}" class="text-decoration-none">
                                    @if($tmdbPoster)
                                        <img src="https://image.tmdb.org/t/p/w500{{ $tmdbPoster }}" alt="{{ $folderName }}" class="cover-image">
                                    @elseif(file_exists(public_path("storage/videos/$folderName/_cover.jpg")))
                                        <img src="{{ $coverPath }}" alt="{{ $folderName }}" class="cover-image">
                                    @else
                                        <div class="no-cover d-flex align-items-center justify-content-center">
                                            <i class="fas fa-layer-group fa-2x"></i>
                                        </div>
                                    @endif
                                    @if(isset($seriesSeasonsCount[$dir]))
                                        <span class="badge bg-danger position-absolute top-0 end-0 m-2" style="z-index:21; font-size:0.95rem; border-radius:1.2rem;">{{ $seriesSeasonsCount[$dir] }} فصل</span>
                                    @endif
                                    <div class="card-overlay">
                                        <h5 class="folder-name">{{ $folderName }}</h5>
                                    </div>
                                    <button class="play-button"><i class="fas fa-play"></i></button>
                                </a>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if(count($films) == 0 && count($series) == 0)
            <div class="empty-state">
                <i class="fas fa-folder-open"></i>
                <h4>هیچ فیلم یا سریالی یافت نشد!</h4>
                <p>لطفاً پوشه‌های ویدیو را در مسیر <code>storage/app/public/videos</code> قرار دهید.</p>
            </div>
        @endif
    </div>
@endsection

@push('scripts')
<script>
    // جستجو
    document.getElementById('searchInput').addEventListener('input', function(e) {
        const searchTerm = e.target.value.toLowerCase();
        const cards = document.querySelectorAll('.video-card');
        cards.forEach(card => {
            const folderName = card.querySelector('.folder-name').textContent.toLowerCase();
            if (folderName.includes(searchTerm)) {
                card.style.display = 'block';
            } else {
                card.style.display = 'none';
            }
        });
    });
</script>
@endpush
