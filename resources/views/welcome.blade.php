<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>سیستم مدیریت ویدیو</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

        <!-- Bootstrap CSS -->
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

        <!-- Font Awesome -->
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

        <!-- Styles / Scripts -->
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @else
            <style>
                :root {
                    --light-primary: #e50914;
                    --light-text: #ffffff;
                    --light-card: rgba(0, 0, 0, 0.8);
                    --light-border: rgba(255, 255, 255, 0.2);
                    --light-shadow: rgba(0, 0, 0, 0.3);
                }

                body {
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    min-height: 100vh;
                    font-family: 'Instrument Sans', sans-serif;
                }

                .main-container {
                    padding-top: 50px;
                    min-height: 100vh;
                }

                .welcome-card {
                    background: var(--light-card);
                    border-radius: 20px;
                    padding: 3rem;
                    box-shadow: 0 20px 40px var(--light-shadow);
                    border: 1px solid var(--light-border);
                    margin-bottom: 2rem;
                    backdrop-filter: blur(10px);
                }

                .welcome-title {
                    color: var(--light-text);
                    font-size: 2.5rem;
                    font-weight: bold;
                    margin-bottom: 1rem;
                    text-align: center;
                }

                .welcome-subtitle {
                    color: rgba(255, 255, 255, 0.8);
                    font-size: 1.2rem;
                    text-align: center;
                    margin-bottom: 3rem;
                }

                .feature-card {
                    background: rgba(255, 255, 255, 0.1);
                    border-radius: 15px;
                    padding: 2rem;
                    border: 1px solid var(--light-border);
                    transition: all 0.3s ease;
                    height: 100%;
                }

                .feature-card:hover {
                    transform: translateY(-5px);
                    box-shadow: 0 15px 30px rgba(0, 0, 0, 0.3);
                }

                .feature-icon {
                    font-size: 3rem;
                    color: var(--light-primary);
                    margin-bottom: 1rem;
                    text-align: center;
                }

                .feature-title {
                    color: var(--light-text);
                    font-size: 1.5rem;
                    font-weight: 600;
                    margin-bottom: 1rem;
                    text-align: center;
                }

                .feature-description {
                    color: rgba(255, 255, 255, 0.8);
                    text-align: center;
                    margin-bottom: 1.5rem;
                }

                .action-btn {
                    background: linear-gradient(45deg, var(--light-primary), #ff6b6b);
                    border: none;
                    color: white;
                    padding: 12px 30px;
                    border-radius: 25px;
                    font-weight: 600;
                    transition: all 0.3s ease;
                    box-shadow: 0 4px 15px rgba(229, 9, 20, 0.3);
                    text-decoration: none;
                    display: inline-block;
                }

                .action-btn:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 8px 25px rgba(229, 9, 20, 0.5);
                    color: white;
                }

                .upload-form {
                    background: rgba(255, 255, 255, 0.1);
                    border-radius: 15px;
                    padding: 2rem;
                    border: 1px solid var(--light-border);
                    margin-bottom: 2rem;
                }

                .form-label {
                    color: var(--light-text);
                    font-weight: 600;
                    margin-bottom: 0.5rem;
                }

                .form-control, .form-select {
                    background: rgba(255, 255, 255, 0.1);
                    border: 1px solid var(--light-border);
                    border-radius: 8px;
                    color: var(--light-text);
                    padding: 0.75rem 1rem;
                }

                .form-control:focus, .form-select:focus {
                    background: rgba(255, 255, 255, 0.15);
                    border-color: var(--light-primary);
                    box-shadow: 0 0 0 2px rgba(229, 9, 20, 0.3);
                    color: var(--light-text);
                }

                .form-control::placeholder {
                    color: rgba(255, 255, 255, 0.7);
                }

                .file-upload-area {
                    border: 2px dashed var(--light-border);
                    border-radius: 12px;
                    padding: 2rem;
                    text-align: center;
                    transition: all 0.3s ease;
                    cursor: pointer;
                    color: var(--light-text);
                }

                .file-upload-area:hover {
                    border-color: var(--light-primary);
                    background: rgba(229, 9, 20, 0.1);
                }

                .alert {
                    border-radius: 10px;
                    border: none;
                    margin-bottom: 1rem;
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

                .nav-link {
                    color: var(--light-text);
                    text-decoration: none;
                    padding: 0.5rem 1rem;
                    border-radius: 8px;
                    transition: all 0.3s ease;
                }

                .nav-link:hover {
                    background: rgba(255, 255, 255, 0.1);
                    color: var(--light-text);
                }
            </style>
        @endif
    </head>
    <body>
        <div class="container main-container">
            <!-- Navigation -->
            <nav class="navbar navbar-expand-lg navbar-dark mb-4">
                <div class="container">
                    <a class="navbar-brand fw-bold" href="/">
                        <i class="fas fa-video me-2"></i>سیستم مدیریت ویدیو
                    </a>
                    <div class="navbar-nav ms-auto">
                        <a class="nav-link" href="/">
                            <i class="fas fa-home me-1"></i>صفحه اصلی
                        </a>
                        <a class="nav-link" href="{{ route('upload.form') }}">
                            <i class="fas fa-upload me-1"></i>آپلود
                        </a>
                        <a class="nav-link" href="{{ route('videos.index') }}">
                            <i class="fas fa-play me-1"></i>مشاهده ویدیوها
                        </a>
                    </div>
                </div>
            </nav>

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

            <!-- Welcome Section -->
            <div class="welcome-card">
                <h1 class="welcome-title">
                    <i class="fas fa-video me-3"></i>خوش آمدید به سیستم مدیریت ویدیو
                </h1>
                <p class="welcome-subtitle">
                    می‌توانید فایل‌های ویدیویی خود را آپلود کنید یا از لینک اینترنتی دانلود کنید
                </p>

                <div class="row">
                    <div class="col-md-4 mb-4">
                        <div class="feature-card">
                            <div class="feature-icon">
                                <i class="fas fa-upload"></i>
                            </div>
                            <h3 class="feature-title">آپلود فایل</h3>
                            <p class="feature-description">فایل‌های ویدیویی خود را مستقیماً آپلود کنید</p>
                            <div class="text-center">
                                <a href="{{ route('upload.form') }}" class="action-btn">
                                    <i class="fas fa-cloud-upload-alt me-2"></i>آپلود فایل
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-4">
                        <div class="feature-card">
                            <div class="feature-icon">
                                <i class="fas fa-download"></i>
                            </div>
                            <h3 class="feature-title">دانلود از لینک</h3>
                            <p class="feature-description">فایل‌ها را از لینک اینترنتی دانلود کنید</p>
                            <div class="text-center">
                                <a href="{{ route('upload.form') }}" class="action-btn">
                                    <i class="fas fa-cloud-download-alt me-2"></i>دانلود از لینک
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-4">
                        <div class="feature-card">
                            <div class="feature-icon">
                                <i class="fas fa-play"></i>
                            </div>
                            <h3 class="feature-title">مشاهده ویدیوها</h3>
                            <p class="feature-description">ویدیوهای آپلود شده را مشاهده کنید</p>
                            <div class="text-center">
                                <a href="{{ route('videos.index') }}" class="action-btn">
                                    <i class="fas fa-play-circle me-2"></i>مشاهده ویدیوها
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Upload Forms -->
            <div class="row">
                <!-- فرم آپلود فایل -->
                <div class="col-md-6 mb-4">
                    <div class="upload-form">
                        <h3 class="feature-title mb-3">
                            <i class="fas fa-upload me-2"></i>آپلود فایل ویدیو
                        </h3>

                        <form action="{{ route('upload.video') }}" method="POST" enctype="multipart/form-data">
                            @csrf

                            <div class="mb-3">
                                <label for="folder" class="form-label">انتخاب پوشه:</label>
                                <select name="folder" id="folder" class="form-select" required>
                                    <option value="">پوشه را انتخاب کنید...</option>
                                    @if(isset($directories))
                                        @foreach($directories as $dir)
                                            <option value="{{ $dir }}">{{ $dir }}</option>
                                        @endforeach
                                    @endif
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="video" class="form-label">فایل ویدیو:</label>
                                <div class="file-upload-area" onclick="document.getElementById('video').click()">
                                    <i class="fas fa-video fa-2x mb-2"></i>
                                    <p class="mb-2">برای انتخاب فایل کلیک کنید</p>
                                    <small>فرمت‌های پشتیبانی شده: MP4, AVI, MKV, MOV, WMV</small>
                                </div>
                                <input type="file" name="video" id="video" class="d-none" accept="video/*" required>
                            </div>

                            <div class="text-center">
                                <button type="submit" class="action-btn">
                                    <i class="fas fa-cloud-upload-alt me-2"></i>آپلود فایل
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- فرم دانلود از لینک -->
                <div class="col-md-6 mb-4">
                    <div class="upload-form">
                        <h3 class="feature-title mb-3">
                            <i class="fas fa-download me-2"></i>دانلود از لینک اینترنتی
                        </h3>

                        <form action="{{ route('download.url') }}" method="POST">
                            @csrf

                            <div class="mb-3">
                                <label for="download_folder" class="form-label">انتخاب پوشه:</label>
                                <select name="folder" id="download_folder" class="form-select" required>
                                    <option value="">پوشه را انتخاب کنید...</option>
                                    @if(isset($directories))
                                        @foreach($directories as $dir)
                                            <option value="{{ $dir }}">{{ $dir }}</option>
                                        @endforeach
                                    @endif
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
                                <button type="submit" class="action-btn">
                                    <i class="fas fa-cloud-download-alt me-2"></i>دانلود فایل
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bootstrap JS -->
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

        <script>
            // نمایش نام فایل انتخاب شده
            document.getElementById('video').addEventListener('change', function(e) {
                if (e.target.files.length > 0) {
                    const fileName = e.target.files[0].name;
                    const uploadArea = document.querySelector('.file-upload-area');
                    uploadArea.innerHTML = `
                        <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                        <p class="mb-2">فایل انتخاب شده:</p>
                        <p class="fw-bold text-success">${fileName}</p>
                    `;
                }
            });
        </script>
    </body>
</html>
