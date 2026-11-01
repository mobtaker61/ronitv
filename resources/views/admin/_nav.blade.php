<ul class="nav nav-pills admin-nav mb-4 justify-content-end gap-1">
    <li class="nav-item"><a class="nav-link {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}" href="{{ route('admin.dashboard') }}"><i class="fas fa-gauge me-1"></i>داشبورد</a></li>
    <li class="nav-item"><a class="nav-link {{ request()->routeIs('admin.library') ? 'active' : '' }}" href="{{ route('admin.library') }}"><i class="fas fa-folder-tree me-1"></i>کتابخانه</a></li>
    <li class="nav-item"><a class="nav-link {{ request()->routeIs('admin.queue') ? 'active' : '' }}" href="{{ route('admin.queue') }}"><i class="fas fa-list-check me-1"></i>صف</a></li>
    <li class="nav-item"><a class="nav-link {{ request()->routeIs('upload.form') ? 'active' : '' }}" href="{{ route('upload.form') }}"><i class="fas fa-upload me-1"></i>آپلود و دانلود</a></li>
    <li class="nav-item">
        <form method="POST" action="{{ route('admin.logout') }}">
            @csrf
            <button type="submit" class="nav-link border-0 bg-transparent"><i class="fas fa-right-from-bracket me-1"></i>خروج</button>
        </form>
    </li>
</ul>
