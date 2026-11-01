# 📹 سیستم مدیریت ویدیو - لاراول

## 🚀 ویژگی‌ها

- ✅ مدیریت پوشه‌های چندسطحی ویدیو
- ✅ نمایش کاور برای هر پوشه (`_cover.jpg`)
- ✅ پخش مستقیم ویدیوها
- ✅ ناوبری breadcrumb
- ✅ رابط کاربری Netflix-style زیبا
- ✅ پشتیبانی از فرمت‌های مختلف ویدیو
- ✅ **آپلود فایل با Drag & Drop**
- ✅ **ایجاد پوشه جدید**
- ✅ **جستجو در ویدیوها**
- ✅ **طراحی ریسپانسیو**

## 📁 ساختار پوشه‌ها

```
storage/app/public/videos/
├── سریال-1/
│   ├── _cover.jpg          # کاور پوشه
│   └── فصل-1/
│       └── قسمت1.mp4
├── فیلم-1/
│   ├── _cover.jpg          # کاور پوشه
│   └── فیلم.mp4
└── ...
```

## 🛠️ نصب و راه‌اندازی

### 1. تنظیم فایل‌سیستم
دیسک `videos` در `config/filesystems.php` تعریف شده است.

### 2. ایجاد Symlink
```bash
php artisan storage:link
```

### 3. اجرای سرور
```bash
php artisan serve
```

## 🔐 دسترسی مدیریت

مسیرهای `/upload`، `/create-folder` و `/download-url` با HTTP Basic محافظت می‌شوند.
در `.env` مقادیر `ADMIN_USER` و `ADMIN_PASSWORD_HASH` را تنظیم کنید (بدون آن‌ها این مسیرها خطای 503 می‌دهند):

```bash
php artisan tinker --execute="echo Hash::make('your-password');"
```

هش را داخل کوتیشن تکی در `.env` قرار دهید. سقف حجم با `MAX_UPLOAD_KB` (به کیلوبایت) تنظیم می‌شود و باید
از `upload_max_filesize` / `post_max_size` در PHP و `client_max_body_size` در nginx بیشتر نباشد.

### دانلود از لینک (صف)

دانلود از لینک توسط Job (`DownloadRemoteVideo`) در صف انجام می‌شود، پس به زمان request محدود نیست. پیشرفت در
صفحه‌ی `/upload` نمایش داده می‌شود. روی سرور باید worker اجرا باشد (`deploy/ronitv-worker.conf` برای supervisor).
در توسعه‌ی محلی با `QUEUE_CONNECTION=sync` همان لحظه اجرا می‌شود؛ با `composer dev` هم worker بالا می‌آید.

## 🎯 مسیرها

- `/` - صفحه اصلی (لیست پوشه‌های سطح اول)
- `/folder/{path?}` - نمایش محتویات پوشه‌ها
- `/upload` - صفحه آپلود فایل‌ها و ایجاد پوشه

## 📝 نحوه استفاده

### اضافه کردن ویدیو جدید:

1. پوشه جدید در `storage/app/public/videos/` ایجاد کنید
2. فایل `_cover.jpg` برای کاور پوشه اضافه کنید
3. فایل‌های ویدیو را در پوشه قرار دهید

### فرمت‌های پشتیبانی شده:
- MP4
- AVI
- MKV
- MOV
- WMV

## 🎨 ویژگی‌های رابط کاربری

- طراحی ریسپانسیو با Bootstrap 5
- انیمیشن‌های hover
- Breadcrumb برای ناوبری
- نمایش آیکون‌های مناسب
- **پشتیبانی کامل از RTL و فارسی**
- **فونت‌های زیبای فارسی (Vazir, IRANSans, Yekan)**
- **طراحی Netflix-style حرفه‌ای**
- **قابلیت تغییر تم (Light/Dark Mode)**
- **Layout حرفه‌ای با Header و Footer**
- **Theme Switcher مدرن و زیبا**

## 🔧 فایل‌های اصلی

- `app/Http/Controllers/VideoController.php` - کنترلر اصلی
- `app/Http/Controllers/UploadController.php` - کنترلر آپلود
- `resources/views/layouts/app.blade.php` - Layout اصلی
- `resources/views/index.blade.php` - صفحه اصلی
- `resources/views/folder.blade.php` - نمایش پوشه‌ها
- `resources/views/upload.blade.php` - صفحه آپلود
- `routes/web.php` - مسیرها
- `config/filesystems.php` - تنظیمات فایل‌سیستم
- `public/css/app.css` - تمام استایل‌ها (Netflix، RTL، Layout، Theme Switcher)
- `public/css/persian-fonts.css` - فونت‌های فارسی (در حال حاضر در layout لود نمی‌شود)
- `app/Http/Middleware/AdminBasicAuth.php` - احراز هویت مسیرهای مدیریت
- `app/Support/SafePath.php`, `app/Support/UrlGuard.php` - اعتبارسنجی مسیر و آدرس
- `public/js/theme-switcher.js` - JavaScript Theme Switcher
- `public/js/app.js` - JavaScript اصلی

## 🚀 توسعه آینده

- [x] ✅ فرم آپلود فایل با Drag & Drop
- [x] ✅ جستجو در ویدیوها
- [x] ✅ ایجاد پوشه جدید
- [x] ✅ قابلیت تغییر تم (Light/Dark Mode)
- [x] ✅ Layout حرفه‌ای با Header و Footer
- [ ] پخش‌کننده پیشرفته (Video.js/Plyr)
- [ ] دسته‌بندی و تگ‌گذاری
- [ ] پشتیبانی از زیرنویس
- [ ] مدیریت کاربران و دسترسی‌ها
- [ ] آمار و گزارش‌گیری
- [ ] تنظیمات شخصی‌سازی تم
- [ ] حالت شب (Auto Dark Mode)
- [ ] انیمیشن‌های پیشرفته‌تر

## 📞 پشتیبانی

برای سوالات و مشکلات، لطفاً با تیم توسعه تماس بگیرید. 
