<div dir="rtl">

# نصب و اولین محاسبه

[English](../en/getting-started.md) · [همه راهنماها](README.md)

برای پیدا کردن روز کاری بعد، کافی است آخر هفته و تعطیلی‌های یک پروفایل را تعریف کنید. قابلیت‌های زمانی به ساعت کاری هم نیاز دارند؛ محاسبات روزمحور چنین الزامی ندارند.

## پیش‌نیازها و نصب

پکیج به PHP با محدودیت <code dir="ltr">^8.2</code> و Laravel 12 یا 13 نیاز دارد. Laravel 13 به PHP 8.3 یا بالاتر نیاز دارد. محدودیت‌های وابستگی، Carbon با نسخه <code dir="ltr">^2.72|^3.0</code>، Verta با نسخه <code dir="ltr">^9.0</code> و کتابخانه تقویم اسلامی با نسخه <code dir="ltr">^1.21</code> را می‌پذیرند. این راهنما کد فعلی مخزن را پوشش می‌دهد؛ [تاریخچه انتشار](../../CHANGELOG.md) هنوز نسخه <code dir="ltr">1.0.0</code> را منتشرنشده معرفی می‌کند.

در برنامه لاراول نصب کنید:

<div dir="ltr">

```bash
composer require zarbinco/laravel-workdays
php artisan workdays:install
```

</div>

ثبت سرویس از طریق شناسایی خودکار پکیج انجام می‌شود. نصب‌کننده فایل تنظیمات را منتشر می‌کند؛ فایل موجود را بدون گزینه صریح بازنویسی نمی‌کند. دستور معادل برای تنظیم پیش‌فرض:

<div dir="ltr">

```bash
php artisan vendor:publish --tag=workdays-config
```

</div>

## یک تقویم و یک نتیجه

نمونه کامل زیر را در نشست آزمایشی <code dir="ltr">php artisan tinker</code> اجرا کنید. تنظیمات همان نشست را عوض می‌کند؛ تعریف معادل را برای برنامه خود در <code dir="ltr">config/workdays.php</code> قرار دهید. این تقویم، شنبه و یکشنبه تعطیل است و روزهای دوشنبه تا جمعه دو پنجره کاری دارد.

<!-- example: config-quick-start -->

<div dir="ltr">

```php
use Zarbinco\LaravelWorkdays\Facades\Workday;

config([
    'workdays.driver' => 'config',
    'workdays.max_scan_days' => 3660,
    'workdays.definitions' => [
        'default_profile' => 'global',
        'include_start_date' => false,
        'hijri' => ['method' => 'mathematical', 'adjustment' => 0],
        'profile_routing' => null,
        'profiles' => [
            'global' => [
                'weekends' => ['Saturday', 'Sunday'],
                'holidays' => [],
                'custom_holidays' => [],
                'extra_working_days' => [],
                'working_hours' => array_fill_keys(
                    ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
                    [['09:00', '10:00'], ['11:00', '12:00']],
                ),
            ],
        ],
    ],
]);

echo Workday::profile('global')->nextBusinessDay('2026-11-06')->toDateString(), PHP_EOL;
```

</div>

<div dir="ltr">

```text
2026-11-09
```

</div>

پروفایل <code dir="ltr">global</code> در این مثال صریحاً ساخته می‌شود؛ نتیجه به تنظیمات سفارشی برنامه میزبان وابسته نیست. تاریخ ورودی جمعه است و نزدیک‌ترین روز کاری پس از آن دوشنبه خواهد بود. خروجی تاریخ از نوع <code dir="ltr">CarbonImmutable</code> است.

مثال‌های کوتاه دیگر راهنماها، مگر آنکه خلافش گفته شده باشد، به همین تنظیم اولیه نیاز دارند. آن‌ها را در همین نشست یا با همین تعریف‌ها اجرا کنید. تغییرات موقت نشست را در برنامه اصلی جایگزین تنظیمات مورد نظر خود کنید.

## تقویم ایران

برای انتشار تنظیم آغازین ایران:

<div dir="ltr">

```bash
php artisan workdays:install --preset=iran
```

</div>

گزینه <code dir="ltr">--persian</code> نام جایگزین همین انتخاب است. هر دو تنظیم همراه پکیج، آخر هفته پروفایل <code dir="ltr">iran</code> را پنجشنبه و جمعه قرار می‌دهند. تنظیم ایران چند تعطیلی تکرارشونده شمسی و قمری هم دارد. این انتخاب‌ها را با برنامه کاری سازمان خود تطبیق دهید.

داده کامل و رسمی یک سال، از جمله تعطیلی‌های موردی یا بین‌تعطیلی، همراه پکیج دریافت، وارد یا فعال نمی‌شود. نمونه تعطیلی‌ها مرجع رسمی سالانه نیست. برای تاریخ‌های قطعی از تعطیلی دقیق استفاده کنید یا [داده محلی تقویم ایران را وارد کنید](database.md).

## انتخاب مسیر بعدی

- برای تشخیص تعطیلی و توضیح علت: [روزهای کاری](business-days.md).
- برای تغییر تقویم و چند پروفایل: [تنظیمات](configuration.md).
- برای ساعت و محاسبه دقیقه: [ساعت کاری](working-hours.md).
- برای ذخیره تعریف‌ها خارج از فایل تنظیمات: [راه‌اندازی مستقل پایگاه‌داده](database.md).

</div>
