@extends('layouts.app')

@section('title', 'سالن فروش بلیط - ' . env('APP_NAME'))

@push('styles')
<style>
    .video-grid {
        padding: 0 2rem;
    }
    @media (max-width: 768px) {
        .hero-title { font-size: 2.5rem; }
        .video-grid { padding: 0 1rem; }
        .search-box { width: 100%; max-width: 300px; }
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
        <!-- فیلم‌ها -->
        @if(count($films) > 0)
            <h2 class="section-title mb-4"><i class="fas fa-clapperboard me-2"></i>فیلم‌ها</h2>
            <div class="video-grid mb-5">
                <div class="row">
                    @foreach($films as $dir)
                        @php
                            $folderName = basename($dir);
                            $coverPath = "/storage/videos/$folderName/_cover.jpg";
                        @endphp
                        <div class="col-md-4 col-lg-3 col-sm-6">
                            <div class="video-card position-relative">
                                <a href="/folder/{{ $folderName }}" class="text-decoration-none">
                                    @if(file_exists(public_path("storage/videos/$folderName/_cover.jpg")))
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
                        @endphp
                        <div class="col-md-4 col-lg-3 col-sm-6">
                            <div class="video-card position-relative">
                                <a href="/folder/{{ $folderName }}" class="text-decoration-none">
                                    @if(file_exists(public_path("storage/videos/$folderName/_cover.jpg")))
                                        <img src="{{ $coverPath }}" alt="{{ $folderName }}" class="cover-image">
                                    @else
                                        <div class="no-cover d-flex align-items-center justify-content-center">
                                            <i class="fas fa-layer-group fa-2x"></i>
                                        </div>
                                    @endif
                                    <span class="type-badge series"><i class="fas fa-layer-group"></i>سریال</span>
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
