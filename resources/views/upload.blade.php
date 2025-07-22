@extends('layouts.app')

@section('title', 'آپلود ویدیو - ' . env('APP_NAME'))

@push('styles')
<style>
    .upload-container {
        padding-top: 100px;
        min-height: 100vh;
    }

    .upload-card {
        background: var(--light-card);
        border-radius: 15px;
        padding: 2rem;
        box-shadow: 0 10px 30px var(--light-shadow);
        border: 1px solid var(--light-border);
        margin-bottom: 2rem;
    }

    .upload-title {
        color: var(--light-text);
        font-size: 2rem;
        font-weight: bold;
        margin-bottom: 1.5rem;
        text-align: center;
    }

    .form-label {
        color: var(--light-text);
        font-weight: 600;
        margin-bottom: 0.5rem;
    }

    .form-control, .form-select {
        background: rgba(255,255,255,0.1);
        border: 1px solid var(--light-border);
        border-radius: 8px;
        color: var(--light-text);
        padding: 0.75rem 1rem;
    }

    .form-control:focus, .form-select:focus {
        background: rgba(255,255,255,0.15);
        border-color: var(--light-primary);
        box-shadow: 0 0 0 2px rgba(229,9,20,0.3);
        color: var(--light-text);
    }

    .form-control::placeholder {
        color: rgba(255,255,255,0.7);
    }

    .upload-btn {
        background: linear-gradient(45deg, var(--light-primary), #ff6b6b);
        border: none;
        color: white;
        padding: 12px 30px;
        border-radius: 25px;
        font-weight: 600;
        transition: all 0.3s ease;
        box-shadow: 0 4px 15px rgba(229,9,20,0.3);
    }

    .upload-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(229,9,20,0.5);
        color: white;
    }

    .file-upload-area {
        border: 2px dashed var(--light-border);
        border-radius: 12px;
        padding: 3rem;
        text-align: center;
        transition: all 0.3s ease;
        cursor: pointer;
    }

    .file-upload-area:hover {
        border-color: var(--light-primary);
        background: rgba(229,9,20,0.1);
    }

    .file-upload-area.dragover {
        border-color: var(--light-primary);
        background: rgba(229,9,20,0.2);
    }

    .upload-icon {
        font-size: 3rem;
        color: var(--light-primary);
        margin-bottom: 1rem;
    }

    .alert {
        border-radius: 10px;
        border: none;
    }

    .alert-success {
        background: rgba(40, 167, 69, 0.2);
        color: #28a745;
        border-right: 4px solid #28a745;
    }

    .alert-danger {
        background: rgba(220, 53, 69, 0.2);
        color: #dc3545;
        border-right: 4px solid #dc3545;
    }

    .progress-bar {
        background: var(--light-primary);
    }

    .upload-progress {
        display: none;
        margin-top: 1rem;
    }

    .folder-select {
        font-family: 'Courier New', monospace;
    }

    .folder-select option {
        font-family: 'Courier New', monospace;
        white-space: pre;
    }
</style>
@endpush

@section('content')
    <div class="container upload-container">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <!-- پیام‌های موفقیت و خطا -->
                @if(session('success'))
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle me-2"></i>{{ session('success') }}
                    </div>
                @endif

                @if(session('error'))
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i>{{ session('error') }}
                    </div>
                @endif

                <!-- فرم آپلود ویدیو -->
                <div class="upload-card">
                    <h2 class="upload-title">
                        <i class="fas fa-upload me-2"></i>آپلود فایل ویدیو
                    </h2>

                    <form action="{{ route('upload.video') }}" method="POST" enctype="multipart/form-data" id="uploadForm">
                        @csrf

                        <div class="mb-3">
                            <label for="folder" class="form-label">انتخاب پوشه:</label>
                            <select name="folder" id="folder" class="form-select folder-select" required>
                                <option value="">پوشه را انتخاب کنید...</option>
                                @foreach($allDirectories as $dir)
                                    <option value="{{ $dir['path'] }}">
                                        {{ $dir['indent'] }}{{ $dir['name'] }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="video" class="form-label">فایل ویدیو:</label>
                            <div class="file-upload-area" onclick="document.getElementById('video').click()">
                                <div class="upload-icon">
                                    <i class="fas fa-video"></i>
                                </div>
                                <p class="text-white mb-2">برای انتخاب فایل کلیک کنید یا فایل را اینجا بکشید</p>
                                <small class="text-muted">فرمت‌های پشتیبانی شده: MP4, AVI, MKV, MOV, WMV (حداکثر 2GB)</small>
                            </div>
                            <input type="file" name="video" id="video" class="d-none" accept="video/*" required>
                        </div>

                        <div class="mb-3">
                            <label for="cover" class="form-label">تصویر کاور (اختیاری):</label>
                            <input type="file" name="cover" id="cover" class="form-control" accept="image/*">
                            <small class="text-muted">فرمت‌های پشتیبانی شده: JPG, JPEG, PNG</small>
                        </div>

                        <div class="upload-progress" id="uploadProgress">
                            <div class="progress">
                                <div class="progress-bar" role="progressbar" style="width: 0%"></div>
                            </div>
                            <small class="text-white mt-1">در حال آپلود...</small>
                        </div>

                        <div class="text-center">
                            <button type="submit" class="upload-btn">
                                <i class="fas fa-cloud-upload-alt me-2"></i>آپلود ویدیو
                            </button>
                        </div>
                    </form>
                </div>

                <!-- فرم دانلود از لینک اینترنتی -->
                <div class="upload-card">
                    <h3 class="upload-title">
                        <i class="fas fa-download me-2"></i>دانلود از لینک اینترنتی
                    </h3>

                    <form action="{{ route('download.url') }}" method="POST">
                        @csrf

                        <div class="mb-3">
                            <label for="download_folder" class="form-label">انتخاب پوشه:</label>
                            <select name="folder" id="download_folder" class="form-select folder-select" required>
                                <option value="">پوشه را انتخاب کنید...</option>
                                @foreach($allDirectories as $dir)
                                    <option value="{{ $dir['path'] }}">
                                        {{ $dir['indent'] }}{{ $dir['name'] }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="url" class="form-label">لینک فایل:</label>
                            <input type="url" name="url" id="url" class="form-control" placeholder="https://example.com/video.mp4" required>
                            <small class="text-muted">لینک مستقیم فایل ویدیو را وارد کنید</small>
                        </div>

                        <div class="mb-3">
                            <label for="filename" class="form-label">نام فایل (اختیاری):</label>
                            <input type="text" name="filename" id="filename" class="form-control" placeholder="نام دلخواه فایل">
                            <small class="text-muted">اگر خالی باشد، نام فایل از لینک استخراج می‌شود</small>
                        </div>

                        <div class="text-center">
                            <button type="submit" class="upload-btn">
                                <i class="fas fa-cloud-download-alt me-2"></i>دانلود فایل
                            </button>
                        </div>
                    </form>
                </div>

                <!-- فرم ایجاد پوشه جدید -->
                <div class="upload-card">
                    <h3 class="upload-title">
                        <i class="fas fa-folder-plus me-2"></i>ایجاد پوشه جدید
                    </h3>

                    <form action="{{ route('create.folder') }}" method="POST">
                        @csrf

                        <div class="mb-3">
                            <label for="folder_name" class="form-label">نام پوشه:</label>
                            <input type="text" name="folder_name" id="folder_name" class="form-control" required>
                        </div>

                        <div class="mb-3">
                            <label for="parent_folder" class="form-label">پوشه والد (اختیاری):</label>
                            <select name="parent_folder" id="parent_folder" class="form-select folder-select">
                                <option value="">پوشه اصلی</option>
                                @foreach($allDirectories as $dir)
                                    <option value="{{ $dir['path'] }}">
                                        {{ $dir['indent'] }}{{ $dir['name'] }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="text-center">
                            <button type="submit" class="upload-btn">
                                <i class="fas fa-plus me-2"></i>ایجاد پوشه
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    // Drag & Drop برای آپلود فایل
    const uploadArea = document.querySelector('.file-upload-area');
    const fileInput = document.getElementById('video');

    uploadArea.addEventListener('dragover', (e) => {
        e.preventDefault();
        uploadArea.classList.add('dragover');
    });

    uploadArea.addEventListener('dragleave', () => {
        uploadArea.classList.remove('dragover');
    });

    uploadArea.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadArea.classList.remove('dragover');
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            fileInput.files = files;
            updateFileName(files[0].name);
        }
    });

    fileInput.addEventListener('change', (e) => {
        if (e.target.files.length > 0) {
            updateFileName(e.target.files[0].name);
        }
    });

    function updateFileName(fileName) {
        const uploadArea = document.querySelector('.file-upload-area');
        uploadArea.innerHTML = `
            <div class="upload-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <p class="text-white mb-2">فایل انتخاب شده:</p>
            <p class="text-success fw-bold">${fileName}</p>
        `;
    }

    // نمایش پیشرفت آپلود
    document.getElementById('uploadForm').addEventListener('submit', function() {
        document.getElementById('uploadProgress').style.display = 'block';
        const progressBar = document.querySelector('.progress-bar');
        let progress = 0;

        const interval = setInterval(() => {
            progress += Math.random() * 30;
            if (progress > 90) progress = 90;
            progressBar.style.width = progress + '%';
        }, 500);
    });
</script>
@endpush
