<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', env('APP_NAME'))</title>

    <!-- CSS Files -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.rtl.min.css" integrity="sha384-Xbg45MqvDIk1e563NLpGEulpX6AvL404DP+/iCgW9eFa2BqztiwTexswJo2jLMue" crossorigin="anonymous">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="/css/app.css" rel="stylesheet">

    @stack('styles')
</head>
<body class="theme-dark" data-theme="dark">
    <!-- Header -->
    <header class="header">
        <nav class="navbar navbar-expand-lg fixed-top">
            <div class="container">
                <!-- Logo -->
                <a class="navbar-brand" href="/">
                    <i class="fas fa-play-circle me-2"></i>{{ env('APP_NAME') }}
                </a>

                <!-- Navigation Links -->
                <div class="navbar-nav me-auto"></div>

                <!-- Right Side -->
                <div class="d-flex align-items-center">
                    <!-- Theme Switcher -->
                    <div class="theme-switcher me-3">
                        <button class="theme-toggle" id="themeToggle" aria-label="تغییر تم">
                            <div class="toggle-track">
                                <div class="toggle-thumb">
                                    <i class="fas fa-sun light-icon"></i>
                                    <i class="fas fa-moon dark-icon"></i>
                                </div>
                            </div>
                        </button>
                    </div>
                    <!-- Search Box -->
                    <div class="search-container me-3 d-none">
                        <input type="text" class="search-box" placeholder="جستجو در ویدیوها..." id="searchInput">
                        <button class="btn btn-outline-light search-btn" type="button">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                    <!-- User Menu -->
                    <div class="dropdown d-none">
                        <button class="btn btn-outline-light dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user me-1"></i>کاربر
                        </button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="/upload"><i class="fas fa-upload me-2"></i>آپلود ویدیو</a></li>
                            <li><a class="dropdown-item" href="#"><i class="fas fa-cog me-2"></i>تنظیمات</a></li>
                            <li><a class="dropdown-item" href="#"><i class="fas fa-info-circle me-2"></i>درباره</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="#"><i class="fas fa-sign-out-alt me-2"></i>خروج</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </nav>
    </header>

    <!-- Main Content -->
    <main class="main-content">
        @yield('content')
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="row d-none">
                <div class="col-md-4">
                    <h5 class="footer-title">
                        <i class="fas fa-play-circle me-2"></i>{{ env('APP_NAME') }}
                    </h5>
                    <p class="footer-description">
                        پلتفرم مدیریت ویدیو با طراحی مدرن و کاربرپسند
                    </p>
                </div>
                <div class="col-md-4">
                    <h6 class="footer-section-title">دسترسی سریع</h6>
                    <ul class="footer-links">
                        <li><a href="/"><i class="fas fa-home me-1"></i>خانه</a></li>
                        <li><a href="/upload"><i class="fas fa-upload me-1"></i>آپلود</a></li>
                        <li><a href="#"><i class="fas fa-info-circle me-1"></i>درباره</a></li>
                        <li><a href="#"><i class="fas fa-question-circle me-1"></i>راهنما</a></li>
                    </ul>
                </div>
                <div class="col-md-4">
                    <h6 class="footer-section-title">تماس با ما</h6>
                    <ul class="footer-links">
                        <li><i class="fas fa-envelope me-2"></i>info@example.com</li>
                        <li><i class="fas fa-phone me-2"></i>+98 123 456 7890</li>
                        <li><i class="fas fa-map-marker-alt me-2"></i>تهران، ایران</li>
                    </ul>
                </div>
            </div>
            <hr class="footer-divider d-none">
            <div class="footer-bottom">
                <div class="row align-items-center">
                    <div class="col-md-6">
                        <p class="copyright">
                            © {{ date('Y') }} {{ env('APP_NAME') }}. تمامی حقوق محفوظ است.
                        </p>
                    </div>
                    <div class="col-md-6 text-end">
                        <div class="social-links">
                            <a href="#" class="social-link"><i class="fab fa-telegram"></i></a>
                            <a href="#" class="social-link"><i class="fab fa-instagram"></i></a>
                            <a href="#" class="social-link"><i class="fab fa-twitter"></i></a>
                            <a href="#" class="social-link"><i class="fab fa-github"></i></a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </footer>

    <!-- JavaScript Files -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/js/theme-switcher.js"></script>
    <script src="/js/app.js"></script>

    @stack('scripts')
</body>
</html>
