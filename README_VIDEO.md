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
- `public/css/netflix-style.css` - استایل‌های Netflix
- `public/css/rtl-fixes.css` - بهبودهای RTL
- `public/css/persian-fonts.css` - فونت‌های فارسی
- `public/css/theme-switcher.css` - استایل‌های Theme Switcher
- `public/css/layout.css` - استایل‌های Layout
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
