@extends('layouts.app')

@section('title', 'پخش ویدیو - Collection ' . $collectionId)

@push('styles')
<style>
    .vod-container {
        display: flex;
        gap: 20px;
        padding: 20px 0;
        min-height: calc(100vh - 200px);
    }

    .player-section {
        flex: 2;
        display: flex;
        flex-direction: column;
    }

    .player-wrapper {
        position: relative;
        width: 100%;
        padding-bottom: 56.25%; /* 16:9 Aspect Ratio */
        background: #000;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.5);
        margin-bottom: 20px;
    }

    .player-wrapper iframe {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        border: 0;
    }

    .direct-play-section {
        background: var(--light-card, #1a1a1a);
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 20px;
    }

    .direct-play-label {
        font-size: 0.9rem;
        color: var(--light-text-secondary, #999);
        margin-bottom: 10px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .direct-play-label i {
        color: var(--light-primary, #e50914);
    }

    .direct-play-url-container {
        display: flex;
        align-items: center;
        gap: 10px;
        background: rgba(0, 0, 0, 0.3);
        border-radius: 6px;
        padding: 12px;
        border: 1px solid rgba(255, 255, 255, 0.1);
    }

    .direct-play-url {
        flex: 1;
        font-family: 'Courier New', monospace;
        font-size: 0.85rem;
        color: var(--light-text, #fff);
        word-break: break-all;
        padding: 8px;
        background: transparent;
        border: none;
        outline: none;
    }

    .copy-btn {
        background: var(--light-primary, #e50914);
        color: #fff;
        border: none;
        border-radius: 6px;
        padding: 10px 15px;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.9rem;
        white-space: nowrap;
    }

    .copy-btn:hover {
        background: #ff6b6b;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(229, 9, 20, 0.4);
    }

    .copy-btn:active {
        transform: translateY(0);
    }

    .copy-btn.copied {
        background: #28a745;
    }

    .copy-btn.copied:hover {
        background: #218838;
    }

    .playlist-section {
        flex: 1;
        display: flex;
        flex-direction: column;
        background: var(--light-card, #1a1a1a);
        border-radius: 8px;
        padding: 15px;
        max-height: calc(100vh - 200px);
        overflow-y: auto;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.5);
    }

    .playlist-header {
        font-size: 1.2rem;
        font-weight: bold;
        margin-bottom: 15px;
        padding-bottom: 10px;
        border-bottom: 2px solid rgba(255, 255, 255, 0.1);
        color: var(--light-text, #fff);
    }

    .playlist-items {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .playlist-item {
        background: rgba(255, 255, 255, 0.05);
        border: 2px solid transparent;
        border-radius: 6px;
        padding: 12px;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .playlist-item:hover {
        background: rgba(255, 255, 255, 0.1);
        border-color: rgba(255, 255, 255, 0.2);
        transform: translateX(-5px);
    }

    .playlist-item.active {
        background: var(--light-primary, #e50914);
        border-color: #ff6b6b;
        box-shadow: 0 0 15px rgba(229, 9, 20, 0.5);
    }

    .playlist-item-thumbnail {
        width: 80px;
        height: 45px;
        background: #000;
        border-radius: 4px;
        flex-shrink: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #666;
        font-size: 0.8rem;
    }

    .playlist-item-thumbnail img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        border-radius: 4px;
    }

    .playlist-item-info {
        flex: 1;
        min-width: 0;
    }

    .playlist-item-title {
        font-size: 0.95rem;
        font-weight: 500;
        color: var(--light-text, #fff);
        margin-bottom: 4px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .playlist-item-meta {
        font-size: 0.75rem;
        color: var(--light-text-secondary, #999);
    }

    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: var(--light-text-secondary, #999);
    }

    .empty-state-icon {
        font-size: 4rem;
        margin-bottom: 20px;
        opacity: 0.5;
    }

    .empty-state-title {
        font-size: 1.5rem;
        margin-bottom: 10px;
        color: var(--light-text, #fff);
    }

    .empty-state-message {
        font-size: 1rem;
        color: var(--light-text-secondary, #999);
    }

    /* Scrollbar Styling */
    .playlist-section::-webkit-scrollbar {
        width: 8px;
    }

    .playlist-section::-webkit-scrollbar-track {
        background: rgba(255, 255, 255, 0.05);
        border-radius: 4px;
    }

    .playlist-section::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, 0.2);
        border-radius: 4px;
    }

    .playlist-section::-webkit-scrollbar-thumb:hover {
        background: rgba(255, 255, 255, 0.3);
    }

    /* Responsive */
    @media (max-width: 768px) {
        .vod-container {
            flex-direction: column;
            padding: 10px 0;
        }

        .player-section {
            flex: 1;
        }

        .playlist-section {
            max-height: 400px;
        }

        .direct-play-url-container {
            flex-direction: column;
        }

        .copy-btn {
            width: 100%;
            justify-content: center;
        }
    }
</style>
@endpush

@section('content')
<div class="container">
    @if(!$hasVideos || empty($playlist))
        <div class="empty-state">
            <div class="empty-state-icon">📺</div>
            <div class="empty-state-title">هیچ ویدیویی یافت نشد</div>
            <div class="empty-state-message">در این collection ویدیویی وجود ندارد.</div>
        </div>
    @else
        <div class="vod-container">
            <!-- Player Section -->
            <div class="player-section">
                <div class="player-wrapper">
                    <iframe
                        id="bunny-player"
                        src="{{ $playlist[0]['embed_url'] }}"
                        allow="accelerometer;gyroscope;autoplay;encrypted-media;picture-in-picture;"
                        allowfullscreen
                        style="width:100%;height:100%;border:0;"
                    ></iframe>
                </div>

                <!-- Direct Play URL Section -->
                <div class="direct-play-section">
                    <div class="direct-play-label">
                        <i class="fas fa-link"></i>
                        <span>لینک نمایش مستقیم ویدیو:</span>
                    </div>
                    <div class="direct-play-url-container">
                        <input
                            type="text"
                            id="directPlayUrl"
                            class="direct-play-url"
                            value="{{ $playlist[0]['direct_play_url'] }}"
                            readonly
                        >
                        <button
                            class="copy-btn"
                            onclick="copyDirectPlayUrl()"
                            id="copyBtn"
                            title="کپی لینک"
                        >
                            <i class="fas fa-copy" id="copyIcon"></i>
                            <span id="copyText">کپی</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Playlist Section -->
            <div class="playlist-section">
                <div class="playlist-header">
                    <span>📋 لیست ویدیوها</span>
                    <span style="font-size: 0.8rem; color: var(--light-text-secondary, #999); font-weight: normal;">({{ count($playlist) }} ویدیو)</span>
                </div>
                <div class="playlist-items">
                    @foreach($playlist as $index => $video)
                        <div
                            class="playlist-item {{ $index === 0 ? 'active' : '' }}"
                            data-embed="{{ $video['embed_url'] }}"
                            data-direct-play="{{ $video['direct_play_url'] }}"
                            onclick="changeVideo(this, '{{ $video['embed_url'] }}', '{{ $video['direct_play_url'] }}')"
                        >
                            <div class="playlist-item-thumbnail">
                                @if($video['thumbnail'])
                                    <img src="{{ $video['thumbnail'] }}" alt="{{ $video['title'] }}">
                                @else
                                    🎬
                                @endif
                            </div>
                            <div class="playlist-item-info">
                                <div class="playlist-item-title" title="{{ $video['title'] }}">
                                    {{ $video['title'] }}
                                </div>
                                <div class="playlist-item-meta">
                                    @if($video['duration'] > 0)
                                        {{ gmdate('H:i:s', $video['duration']) }}
                                    @endif
                                    @if($video['views'] > 0)
                                        • {{ number_format($video['views']) }} بازدید
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</div>
@endsection

@push('scripts')
<script>
    function changeVideo(element, embedUrl, directPlayUrl) {
        // حذف کلاس active از همه آیتم‌ها
        document.querySelectorAll('.playlist-item').forEach(item => {
            item.classList.remove('active');
        });

        // اضافه کردن کلاس active به آیتم کلیک شده
        element.classList.add('active');

        // تغییر src iframe
        const iframe = document.getElementById('bunny-player');
        if (iframe) {
            iframe.src = embedUrl;
        }

        // تغییر Direct Play URL
        const directPlayInput = document.getElementById('directPlayUrl');
        if (directPlayInput) {
            directPlayInput.value = directPlayUrl;
        }

        // بازگرداندن دکمه کپی به حالت اولیه
        const copyBtn = document.getElementById('copyBtn');
        const copyIcon = document.getElementById('copyIcon');
        const copyText = document.getElementById('copyText');
        if (copyBtn && copyIcon && copyText) {
            copyBtn.classList.remove('copied');
            copyIcon.className = 'fas fa-copy';
            copyText.textContent = 'کپی';
        }
    }

    function copyDirectPlayUrl() {
        const input = document.getElementById('directPlayUrl');
        const copyBtn = document.getElementById('copyBtn');
        const copyIcon = document.getElementById('copyIcon');
        const copyText = document.getElementById('copyText');

        if (!input || !copyBtn || !copyIcon || !copyText) {
            return;
        }

        // انتخاب متن
        input.select();
        input.setSelectionRange(0, 99999); // برای موبایل

        try {
            // کپی کردن به clipboard
            document.execCommand('copy');

            // تغییر ظاهر دکمه
            copyBtn.classList.add('copied');
            copyIcon.className = 'fas fa-check';
            copyText.textContent = 'کپی شد!';

            // بازگرداندن به حالت اولیه بعد از 2 ثانیه
            setTimeout(() => {
                copyBtn.classList.remove('copied');
                copyIcon.className = 'fas fa-copy';
                copyText.textContent = 'کپی';
            }, 2000);

            // نمایش پیام موفقیت (اختیاری)
            if (navigator.clipboard) {
                navigator.clipboard.writeText(input.value).then(() => {
                    console.log('URL copied to clipboard');
                });
            }
        } catch (err) {
            console.error('Failed to copy:', err);
            // در صورت خطا، از Clipboard API استفاده کن
            if (navigator.clipboard) {
                navigator.clipboard.writeText(input.value).then(() => {
                    copyBtn.classList.add('copied');
                    copyIcon.className = 'fas fa-check';
                    copyText.textContent = 'کپی شد!';
                    setTimeout(() => {
                        copyBtn.classList.remove('copied');
                        copyIcon.className = 'fas fa-copy';
                        copyText.textContent = 'کپی';
                    }, 2000);
                }).catch(err => {
                    console.error('Clipboard API failed:', err);
                    alert('خطا در کپی کردن لینک');
                });
            } else {
                alert('مرورگر شما از کپی کردن پشتیبانی نمی‌کند');
            }
        }
    }

    // اضافه کردن رویداد کلیک برای همه آیتم‌ها (برای اطمینان)
    document.addEventListener('DOMContentLoaded', function() {
        const playlistItems = document.querySelectorAll('.playlist-item');
        playlistItems.forEach(item => {
            item.addEventListener('click', function() {
                const embedUrl = this.getAttribute('data-embed');
                const directPlayUrl = this.getAttribute('data-direct-play');
                if (embedUrl && directPlayUrl) {
                    changeVideo(this, embedUrl, directPlayUrl);
                }
            });
        });
    });
</script>
@endpush
