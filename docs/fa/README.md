# Laravel Workdays

English document: [../en/README.md](../en/README.md)

## معرفی کوتاه

برای خیلی از پروژه‌ها فقط دانستن جمعه‌ها و چند تعطیلی ثابت کافی نیست.

گاهی باید دقیقاً بدانی یک تاریخ چرا روز کاری حساب شده یا نشده، چند روز کاری تا یک موعد باقی مانده، یا یک زمان مشخص داخل ساعت کاری هست یا نه.

`zarbinco/laravel-workdays` یک پکیج لاراولی برای همین منطق‌هاست: روز کاری، تعطیلی، ساعت کاری، پروفایل‌های مختلف، تعطیلی‌های Gregorian/Jalali/Hijri، تاریخ‌های دقیق، و توضیح علت نتیجه.

اگر پروژه‌ات با موعد تحویل، مرخصی، فاکتور، پشتیبانی، رزرو یا زمان‌بندی سروکار دارد، این پکیج می‌تواند منطق تاریخ‌ها را مرتب‌تر و قابل تست‌تر کند.

## نصب

```bash
composer require zarbinco/laravel-workdays
```

## سازگاری

این نسخه برای PHP `^8.2` و Laravel 12 و 13 آماده شده است.

ماتریس CI پکیج Laravel 12 را روی PHP 8.2، 8.3 و 8.4، و Laravel 13 را روی PHP 8.3 و 8.4 تست می‌کند.

## انتشار config

برای انتشار تنظیمات پیش‌فرض:

```bash
php artisan vendor:publish --tag=workdays-config
```

یا از installer خود پکیج استفاده کن:

```bash
php artisan workdays:install
```

برای نصب preset ایران:

```bash
php artisan workdays:install --preset=iran
php artisan workdays:install --persian
```

گزینه `--persian` همان preset ایران را نصب می‌کند.

## استفاده ساده

```php
use Zarbinco\LaravelWorkdays\Facades\Workday;

Workday::profile('iran')->isBusinessDay('2026-06-24');
Workday::profile('iran')->isHoliday('2026-06-25');
Workday::profile('iran')->nextBusinessDay('2026-06-25');
Workday::profile('iran')->addBusinessDays('2026-06-24', 2);
Workday::profile('global')->diffBusinessDays('2026-06-24', '2026-06-28');
```

اگر profile پیش‌فرض در config تنظیم شده باشد، می‌توانی مستقیم از facade استفاده کنی:

```php
Workday::addBusinessDays('2026-06-24', 2);
```

متدهایی که تاریخ برمی‌گردانند، خروجی `Carbon\CarbonImmutable` دارند.

## پروفایل‌ها

پروفایل یعنی یک مجموعه تنظیمات برای یک کشور، تیم، شرکت یا هر تقویم کاری جداگانه.

نمونه ساده در `config/workdays.php`:

```php
'default_profile' => 'iran',

'profiles' => [
    'iran' => [
        'weekends' => ['Thursday', 'Friday'],
        'holidays' => [
            'gregorian' => [],
            'jalali' => [],
            'hijri' => [],
        ],
        'custom_holidays' => [],
        'extra_working_days' => [],
    ],
],
```

تنظیم `max_scan_days` جلوی جست‌وجوی بی‌پایان را می‌گیرد. اگر پروفایلی طوری تنظیم شود که هیچ روز کاری پیدا نشود، پکیج بعد از این محدوده خطای روشن می‌دهد.

## آخر هفته‌ها

آخر هفته‌ها را می‌توانی با نام انگلیسی، نام کوتاه انگلیسی، عدد ISO یا نام فارسی وارد کنی:

```php
'weekends' => ['Thursday', 'Friday'],
'weekends' => ['Saturday', 'Sunday'],
'weekends' => [6, 7],
```

نمونه‌های قابل قبول:

```php
'Monday', 'mon', 'Thursday', 'thu', 'Sunday', 'sun'
'شنبه', 'یکشنبه', 'یک‌شنبه', 'سه شنبه', 'سه‌شنبه', 'پنجشنبه', 'پنج‌شنبه', 'جمعه'
```

در داخل پکیج همه این‌ها به عدد ISO تبدیل می‌شوند؛ Monday برابر `1` و Sunday برابر `7` است.

## تعطیلی‌های Gregorian

تعطیلی‌های تکرارشونده Gregorian با کلید `MM-DD` تعریف می‌شوند و هر سال تکرار می‌شوند:

```php
'holidays' => [
    'gregorian' => [
        '01-01' => 'New Year',
        '12-25' => 'Christmas',
    ],
],
```

## تعطیلی‌های Jalali

تعطیلی‌های Jalali هم با کلید `MM-DD` تعریف می‌شوند، اما تبدیل تاریخ با Verta انجام می‌شود:

```php
'holidays' => [
    'jalali' => [
        '01-01' => 'Nowruz',
        '01-02' => 'Nowruz Holiday',
    ],
],
```

اگر می‌خواهی فقط همین منبع را چک کنی:

```php
Workday::profile('iran')->isJalaliHoliday('2026-03-21');
```

## تعطیلی‌های Hijri

تعطیلی‌های Hijri با `islamic-network/calendar` محاسبه می‌شوند:

```php
'holidays' => [
    'hijri' => [
        '01-09' => "Tasu'a",
        '01-10' => 'Ashura',
    ],
],
```

روش محاسبه Hijri در config قابل تغییر است:

```php
'hijri' => [
    'method' => 'umm_al_qura',
    'adjustment' => 0,
],
```

روش‌های پشتیبانی‌شده: `mathematical`، `umm_al_qura`، `high_judiciary` و `diyanet`.

تاریخ‌های Hijri ممکن است با روش محاسبه، کشور یا اعلام رسمی متفاوت باشند. برای تاریخ‌های قطعی، بهتر است از `custom_holidays` استفاده کنی.

## preset ایران

برای شروع سریع با تنظیمات ایران:

```bash
php artisan workdays:install --preset=iran
```

این preset شامل این موارد است:

- پروفایل `iran` با آخر هفته‌های `Thursday` و `Friday`.
- چند تعطیلی تکرارشونده Jalali مثل نوروز، روز طبیعت، پیروزی انقلاب و ملی شدن صنعت نفت.
- چند تعطیلی تکرارشونده Hijri مثل تاسوعا، عاشورا، اربعین، عید فطر، عید قربان و عید غدیر.
- پروفایل `global` با آخر هفته شنبه/یکشنبه و چند تعطیلی Gregorian.

preset ایران برای شروع سریع خوب است، اما قرار نیست نقش تقویم رسمی کامل را بازی کند. تعطیلی‌های بین‌تعطیلی، تصمیم‌های موردی دولت، و تغییرات بعدی به صورت خودکار ساخته نمی‌شوند.

برای موارد قطعی، از `custom_holidays` و `extra_working_days` استفاده کن.

## تقویم رسمی سالانه ایران

preset ایران سبک و تکرارشونده است. import تقویم رسمی سالانه اختیاری است، از فایل‌های local dataset خوانده می‌شود، و جای config معمولی را بی‌خبر عوض نمی‌کند.

تقویم رسمی سالانه به صورت پیش‌فرض فعال نیست.

پکیج دیتاست رسمی ۱۴۰۵ را bundle نمی‌کند. پکیج خودش ۱۴۰۵ را وارد دیتابیس نمی‌کند. پکیج خودش تقویم رسمی ۱۴۰۵ را جایگزین preset معمولی ایران نمی‌کند.

به بیان کوتاه، Iran 1405 کاملاً اختیاری و import-only است. برای import باید یک directory محلی بدهی که فایل‌های PHP آن با سال Jalali نام‌گذاری شده‌اند، مثلاً `1405.php`.

تنظیم پیش‌فرض رسمی ایران غیرفعال و خالی است:

```php
'iran_official' => [
    'enabled' => false,
    'year' => null,
    'profile' => null,
    'calendar_path' => null,
],
```

مسیر dataset را می‌توانی برای یک اجرای command با `--calendar-path` بدهی، یا در config با `workdays.iran_official.calendar_path` تنظیم کنی.

برای استفاده از دیتاست رسمی ۱۴۰۵ باید migrationها را منتشر و اجرا کنی:

```bash
php artisan vendor:publish --tag=workdays-migrations
php artisan migrate
```

قبل از ثبت رکوردها می‌توانی import را فقط پیش‌نمایش بگیری:

```bash
php artisan workdays:import-iran-calendar 1405 --calendar-path=/absolute/path/to/calendars/iran --dry-run
```

برای import واقعی:

```bash
php artisan workdays:import-iran-calendar 1405 --calendar-path=/absolute/path/to/calendars/iran
```

یا با profile جدا:

```bash
php artisan workdays:import-iran-calendar 1405 --profile=iran-official-1405 --calendar-path=/absolute/path/to/calendars/iran
```

این command رکوردهای دقیق Gregorian از نوع `holiday` را در جدول `workday_special_dates` می‌نویسد. اجرای دوباره‌اش رکوردهای موجود را بی‌دلیل خراب نمی‌کند؛ برای بازنویسی باید `--force` بدهی.

اگر تعطیلی اضطراری، تغییر دولتی یا اصلاح رسمی بعداً اعلام شود، پکیج خودش آنلاین به‌روزرسانی نمی‌کند. آن موارد را باید دستی به عنوان special date اضافه کنی.

## database storage

در حالت پیش‌فرض، پکیج تعطیلی‌ها را از config می‌خواند.

اگر می‌خواهی تعطیلی‌های تکرارشونده و تاریخ‌های دقیق را در دیتابیس نگه داری:

```php
'storage' => [
    'driver' => 'database',
],
```

قبل از استفاده از database storage باید migrationها را منتشر و اجرا کنی:

```bash
php artisan vendor:publish --tag=workdays-migrations
php artisan migrate
```

installer هم می‌تواند config و migrationها را آماده کند:

```bash
php artisan workdays:install --storage=database
```

این دستور migrationها را اجرا نمی‌کند.

## chain storage

در حالت `chain`، پکیج هم config را می‌خواند و هم دیتابیس را:

```php
'storage' => [
    'driver' => 'chain',
],
```

تعطیلی‌های تکرارشونده دیتابیس اگر با همان profile، calendar type، ماه و روز وجود داشته باشند، روی config اولویت دارند. تاریخ‌های دقیق تعطیلی و روز کاری جایگزین هم با هم ترکیب می‌شوند.

## migrationها

پکیج دو جدول منتشر می‌کند:

- `workday_holiday_rules`: تعطیلی‌های تکرارشونده با `profile`، `calendar_type`، `month` و `day`.
- `workday_special_dates`: تاریخ‌های دقیق Gregorian با `profile`، `date` و `type`.

```bash
php artisan vendor:publish --tag=workdays-migrations
php artisan migrate
```

نمونه ساخت تعطیلی تکرارشونده:

```php
use Zarbinco\LaravelWorkdays\Models\WorkdayHolidayRule;

WorkdayHolidayRule::create([
    'profile' => 'global',
    'calendar_type' => 'gregorian',
    'month' => 6,
    'day' => 29,
    'title' => 'Database recurring holiday',
    'is_active' => true,
]);
```

نمونه ساخت تاریخ دقیق:

```php
use Zarbinco\LaravelWorkdays\Models\WorkdaySpecialDate;

WorkdaySpecialDate::create([
    'profile' => 'global',
    'date' => '2026-06-29',
    'type' => 'holiday',
    'title' => 'Company holiday',
    'is_active' => true,
]);
```

مقدار `type` می‌تواند `holiday` یا `working_day` باشد.

## تعطیلی‌های دقیق

برای تعطیلی‌هایی که تاریخ دقیق Gregorian دارند:

```php
'custom_holidays' => [
    '2026-06-25' => 'Company holiday',
],
```

کلیدها باید دقیقاً با فرمت `Y-m-d` و تاریخ معتبر باشند. مقدارهایی مثل `2026-02-31`، `2026-13-01` یا `2026/01/01` خطا می‌دهند.

## روزهای کاری جایگزین

گاهی یک روز که معمولاً تعطیل است، باید کاری حساب شود. برای این حالت:

```php
'extra_working_days' => [
    '2026-06-26' => 'Compensation working day',
],
```

روز کاری جایگزین روی آخر هفته و تعطیلی اولویت دارد. اگر یک تاریخ هم تعطیلی باشد و هم `working_day`، نتیجه روز کاری است.

## explain / DayInfo

وقتی فقط جواب true/false کافی نیست، از `explain()` استفاده کن:

```php
$info = Workday::profile('iran')->explain('2026-06-25');

$info->isBusinessDay;
$info->isWeekend;
$info->reasons;
$info->toArray();
```

خروجی یک شیء `Zarbinco\LaravelWorkdays\Data\DayInfo` است. دلیل‌ها هم از نوع `Zarbinco\LaravelWorkdays\Data\DayReason` هستند.

این بخش برای دیباگ، نمایش علت در پنل‌های مدیریتی، یا نوشتن تست‌های دقیق خیلی کاربردی است. مثلاً می‌توانی ببینی یک روز به خاطر آخر هفته تعطیل شده، به خاطر تعطیلی Jalali، یا به خاطر `extra_working_days` دوباره کاری حساب شده است.

## ساعت کاری و نیم‌روزها

متدهای روزمحور مثل `isBusinessDay()` و `addBusinessDays()` همان رفتار قبلی را دارند. برای کار با زمان، باید در پروفایل `working_hours` تعریف کنی:

```php
'working_hours' => [
    'Saturday' => [['09:00', '17:00']],
    'Sunday' => [['09:00', '17:00']],
    'Monday' => [['09:00', '17:00']],
    'Tuesday' => [['09:00', '17:00']],
    'Wednesday' => [['09:00', '17:00']],
    'Thursday' => [['09:00', '13:00']],
    'Friday' => [],
],
```

زمان شروع شامل می‌شود و زمان پایان شامل نمی‌شود. یعنی `09:00` داخل ساعت کاری است، اما `17:00` دیگر داخل بازه نیست.

برای نیم‌روز، بازه کوتاه‌تر تعریف کن. برای وقفه ناهار یا شیفت دو تکه، چند بازه بده:

```php
'working_hours' => [
    'Monday' => [
        ['09:00', '12:00'],
        ['13:00', '17:00'],
    ],
],
```

نمونه استفاده:

```php
Workday::profile('iran')->isBusinessTime('2026-06-29 10:30');
Workday::profile('iran')->workingWindowsFor('2026-06-29');
Workday::profile('iran')->nextBusinessTime('2026-06-29 08:00');
Workday::profile('iran')->previousBusinessTime('2026-06-29 17:00');
Workday::profile('iran')->addBusinessHours('2026-06-29 16:00', 2);
Workday::profile('iran')->diffBusinessMinutes('2026-06-29 09:00', '2026-06-29 17:00');
```

محاسبه‌های ساعت کاری تعطیلی‌ها، آخر هفته‌ها، تعطیلی‌های دقیق، تعطیلی‌های import شده و روزهای کاری جایگزین را رعایت می‌کنند.

## سیاست ساعت نهایی پذیرش سفارش

متد `resolveCutoff()` زمان دریافت سفارش را با توجه به تقویم و ساعت نهایی پذیرشِ پروفایل انتخاب‌شده، به یک تاریخ کاری تخصیص می‌دهد. واجد شرایط بودن برای همان روز یعنی تاریخ محلی، روز کاری باشد و زمان دریافت، دقیقاً قبل از مهلت فعال آن روز قرار بگیرد. زمان دریافت را باید صریحاً بدهی؛ متد به‌طور خودکار از `now()` استفاده نمی‌کند.

خروجی این متد فقط تخصیص تاریخ است. بررسی موجودی، ظرفیت، تأیید پرداخت، رزرو، مدت آماده‌سازی و تعهد واقعی ارسال یا تحویل، بر عهده برنامه مصرف‌کننده است. این API ساعت باز شدن انبار، شروع پردازش، تحویل به حمل‌کننده، ارسال یا اطلاع‌رسانی را محاسبه نمی‌کند.

### فعال‌سازی در پروفایل

برای استفاده، بلوک `cutoff` را در پروفایل مربوط به فایل `config/workdays.php` اضافه کن. نمونه کامل:

```php
'default_profile' => 'warehouse',
'include_start_date' => false,
'max_scan_days' => 3660,
'storage' => ['driver' => 'config'],
'profiles' => [
    'warehouse' => [
        'weekends' => ['Saturday', 'Sunday'],
        'holidays' => [
            'gregorian' => [],
            'jalali' => [],
            'hijri' => [],
        ],
        'custom_holidays' => [],
        'extra_working_days' => [],
        'cutoff' => [
            'timezone' => 'Asia/Tehran',
            'time' => '14:00',
            'weekdays' => [
                'Thursday' => '11:00',
                'Friday' => null,
            ],
        ],
    ],
],
```

کلید `timezone` الزامی است و باید شناسه معتبر منطقه زمانی PHP، مثل `Asia/Tehran` یا `UTC` باشد؛ اختلاف ساعت عددی برای منطقه زمانی سیاست پذیرفته نمی‌شود. کلید `time` هم الزامی است و باید رشته‌ای با قالب ۲۴ ساعته و دقیق `HH:MM`، از `00:00` تا `23:59` باشد. بخش `weekdays` اختیاری است و در صورت نبودن، آرایه خالی در نظر گرفته می‌شود.

کلید روزهای هفته از قواعد `WeekdayNormalizer` پیروی می‌کند: عدد ISO از `1` تا `7` برای دوشنبه تا یکشنبه، نام کامل یا کوتاه انگلیسی، یا نام فارسی پشتیبانی‌شده. مثلاً `4`، `Thursday`، `thu` و `پنج‌شنبه` همگی پنجشنبه هستند. تعریف چند نام معادل برای یک روز در یک سیاست، خطاست.

اگر روزی در `weekdays` نیامده باشد، مقدار `time` را به ارث می‌برد. مقدار رشته‌ای، مهلت آن روز را جایگزین می‌کند. مقدار صریح `null` پذیرش آن روز را غیرفعال می‌کند؛ این روز هنگام جست‌وجوی تاریخ آینده هم کنار گذاشته می‌شود. این تنظیم نتیجه `isBusinessDay()` را تغییر نمی‌دهد و روز کاری جایگزین هم آن را لغو نمی‌کند. تنظیمات پیش‌فرض و preset ایران این قابلیت را خودکار فعال نمی‌کنند؛ مهلت از ساعت پایان کار یا پروفایل دیگر حدس زده نمی‌شود.

اعتبارسنجی این سیاست فقط هنگام فراخوانی `resolveCutoff()` انجام می‌شود. نبودن یا نامعتبر بودن تنظیمات مهلت، خطای `Zarbinco\LaravelWorkdays\Exceptions\WorkdayConfigurationException` می‌دهد. متدهای قبلی روز و ساعت کاری، `explain()` و `calculate()` با بلوک `cutoff` غایب یا نامعتبر، همان رفتار قبلی را دارند.

### استفاده و قالب زمان ورودی

```php
use Zarbinco\LaravelWorkdays\Facades\Workday;

// Uses the configured default profile.
$result = Workday::resolveCutoff('2026-06-29 13:59:59.999999');

// Uses this profile's calendar and policy.
$result = Workday::profile('warehouse')->resolveCutoff(
    '2026-06-29T10:29:59.999999Z',
);
```

ورودی متد از نوع `string|\DateTimeInterface` است. برای ورودی `DateTimeInterface`، لحظه زمانی حفظ و به منطقه زمانی سیاست تبدیل می‌شود؛ خود شیء ورودی و منطقه زمانی آن تغییر نمی‌کنند. همه اشیای تاریخ و زمان در خروجی از نوع `Carbon\CarbonImmutable` هستند.

رشته‌های ورودی باید تاریخ میلادی و یکی از ساختارهای دقیق زیر را داشته باشند:

| قالب | نمونه |
|---|---|
| `Y-m-d H:i` | `2026-06-29 13:59` |
| `Y-m-d H:i:s`، با بخش اعشاری اختیاری شامل ۱ تا ۶ رقم | `2026-06-29 13:59:59`، `2026-06-29 13:59:59.123456` |
| `Y-m-d\TH:i` یا `Y-m-d\TH:i:s`، با بخش اعشاری اختیاری ۱ تا ۶ رقمی برای ثانیه، و در پایان `Z` یا `±HH:MM` | `2026-06-29T10:29Z`، `2026-06-29T13:59:59.123456+03:30` |

اجزای تاریخ و ساعت باید با صفر ابتدایی و تعداد رقم مشخص نوشته شوند. بخش اعشاری فقط پس از ثانیه مجاز است. در اختلاف ساعت صریح، ساعت از `00` تا `23` و دقیقه از `00` تا `59` پذیرفته می‌شود. رشته ISO باید `Z` یا اختلاف ساعت داشته باشد؛ رشته بدون منطقه زمانی باید از قالب دارای فاصله بین تاریخ و ساعت استفاده کند.

رشته دارای فاصله، زمان محلی منطقه زمانی سیاست است؛ حتی اگر منطقه زمانی برنامه متفاوت باشد. رشته دارای اختلاف ساعت یا `Z` یک لحظه مشخص را تعیین می‌کند و قبل از انتخاب تاریخ محلی، روز هفته و مهلت، به منطقه زمانی سیاست تبدیل می‌شود. ثانیه و میکروثانیه حفظ می‌شوند. رشته خالی، تاریخ بدون ساعت، عبارت نسبی مثل `now`، `tomorrow` یا `next Monday`، تاریخ ناممکن، ساعت یا اختلاف ساعت نامعتبر، نام منطقه زمانی در رشته ورودی و قالب‌های دیگر، خطای `InvalidArgumentException` می‌دهند. تاریخ نامعتبر به‌طور خودکار به تاریخ دیگری تبدیل نمی‌شود.

### مرز مهلت، تقویم و انتخاب تاریخ آینده

مقایسه همیشه به شکل `receivedAt < cutoffAt` است. برای مهلت `14:00`:

| زمان محلی دریافت | واجد شرایط همان روز |
|---|---|
| `13:59:59.999999` | `true` |
| `14:00:00.000000` | `false` |
| `14:00:00.000001` | `false` |

زمان دریافت گرد نمی‌شود و گزینه‌ای برای شامل کردن خود مرز مهلت وجود ندارد. مقدار `00:00` از نظر قالب معتبر است، اما هیچ بازه‌ای برای پذیرش همان روز باقی نمی‌گذارد؛ چنین روزی برای امروز واجد شرایط نیست و در جست‌وجوی تاریخ آینده هم کنار گذاشته می‌شود.

کاری بودن تاریخ را همان موتور تقویم موجود تعیین می‌کند. آخر هفته، تعطیلی‌های تکرارشونده میلادی/جلالی/هجری، تعطیلی‌های دقیق و حالت‌های ذخیره‌سازی `config`، `database` و `chain` رفتار و اولویت قبلی خود را دارند. روز کاری جایگزین می‌تواند آخر هفته یا تعطیلی را کاری کند، اما مهلت `null` یا `00:00` را تغییر نمی‌دهد.

ساعت کاری مستقل است: سفارش قبل از باز شدن انبار یا هنگام وقفه ناهار بین دو شیفت هم می‌تواند واجد شرایط همان روز باشد. پروفایل بدون `working_hours` یا روز کاری با بازه‌های ساعت کاری خالی هم می‌تواند واجد شرایط باشد. مهلت به ساعت پایان کار محدود نمی‌شود. انتخاب ساعت مناسب با عملیات مجموعه، بر عهده پذیرنده است؛ این API از `nextBusinessTime()` یا اختلاف دقیقه‌های کاری برای تصمیم‌گیری استفاده نمی‌کند.

اگر تاریخ محلی واجد شرایط نباشد، اولین تاریخ محلیِ بعد از آن انتخاب می‌شود که روز کاری باشد و مهلت فعال با بازه پذیرش غیرخالی داشته باشد. مقدار `include_start_date` این قاعده را تغییر نمی‌دهد. تنظیم `max_scan_days` سقف کل تاریخ‌های تقویمی آینده است: امروز جداگانه بررسی می‌شود، مقدار `1` اجازه بررسی فردا را می‌دهد و تاریخ دقیقاً روی مرز محدودیت هم می‌تواند انتخاب شود. تاریخی بعد از این مرز بررسی نمی‌شود. اگر هیچ تاریخی پیدا نشود، `RuntimeException` با اطلاعات پروفایل و سقف جست‌وجو رخ می‌دهد؛ تاریخ فاقد شرایط به‌عنوان نتیجه جایگزین برگردانده نمی‌شود.

حرکت به روز بعد بر اساس روز تقویمی محلی است و مهلت هر روز با همان ساعت محلی و ثانیه و میکروثانیه صفر ساخته می‌شود. هنگام تغییر ساعت تابستانی، مهلت معمول `14:00` همچنان ساعت ۱۴ محلی است، حتی اگر آن روز ۲۳ یا ۲۵ ساعت داشته باشد؛ از جمله در تغییر ساعت مارس ۲۰۲۶ منطقه `America/New_York`. زمان محلی بدون منطقه زمانی که به دلیل جلو کشیدن ساعت وجود ندارد، با `InvalidArgumentException` رد می‌شود. مهلت تنظیم‌شده‌ای که وجود ندارد، مثلاً `02:30` در `2026-03-08` همان منطقه، خطای `WorkdayConfigurationException` می‌دهد. برای ساعت تکراری هنگام عقب کشیدن ساعت، همان رخدادی انتخاب می‌شود که PHP/Carbon انتخاب می‌کند؛ برای تعیین دقیق لحظه دریافت، ورودی دارای اختلاف ساعت صریح بده. مهلت واقع‌شده در ساعت تکراری هم از انتخاب PHP/Carbon پیروی می‌کند.

### نتیجه و تبدیل به آرایه

خروجی یک شیء تغییرناپذیر `Zarbinco\LaravelWorkdays\Data\CutoffResult` با ویژگی‌های فقط‌خواندنی زیر است:

| ویژگی | معنی |
|---|---|
| `profile` | نام پروفایل انتخاب‌شده، از نوع `string`. |
| `timezone` | منطقه زمانی سیاست، از نوع `string`. |
| `receivedAt` | لحظه دریافت در منطقه زمانی سیاست، با حفظ ثانیه و میکروثانیه، از نوع `CarbonImmutable`. |
| `cutoffAt` | مهلت اسمی در تاریخ محلی ورودی، از نوع `CarbonImmutable`؛ در روز هفته غیرفعال، `null`. |
| `effectiveBusinessDate` | شروع تاریخ کاری تخصیص‌یافته در منطقه زمانی سیاست، از نوع `CarbonImmutable`. |
| `sameDayEligible` | آیا تاریخ تخصیص‌یافته همان تاریخ محلیِ ورودی است؟ از نوع `bool`. |
| `reason` | کد ثابت و قابل پردازش برای علت تصمیم درباره امروز، از نوع `string`. |

ویژگی `cutoffAt` همیشه مربوط به تاریخ ورودی است؛ حتی وقتی مهلت آن گذشته باشد. برای روز تعطیل هم ممکن است مهلت اسمی را جهت بررسی سیاست نشان بدهد؛ وجود این مقدار، روز تعطیل را واجد شرایط نمی‌کند. ویژگی `effectiveBusinessDate` فقط تخصیص تاریخ است؛ زمان شروع آن به معنی باز شدن انبار، تحویل به حمل‌کننده یا ارسال نیست. این زمان معمولاً نیمه‌شب است؛ اگر تغییر منطقه زمانی باعث حذف نیمه‌شب شود، PHP/Carbon اولین زمان موجود در شروع آن تاریخ را انتخاب می‌کند؛ مثلاً `01:00` در `2026-09-06` منطقه `America/Santiago`. خود ساعت مهلت همچنان دقیق بررسی می‌شود و اگر وجود نداشته باشد، خطا می‌دهد.

اولویت دلیل‌ها به این ترتیب است:

| اولویت | کد | معنی |
|---|---|---|
| ۱ | `non_business_day` | تقویم موجود، تاریخ ورودی را غیرکاری می‌داند. |
| ۲ | `cutoff_disabled` | تاریخ کاری است، اما مقدار مهلت روز هفته `null` است. |
| ۳ | `before_cutoff` | روز واجد شرایط است و زمان دریافت دقیقاً قبل از مهلت قرار دارد. |
| ۴ | `cutoff_reached` | زمان دریافت به مهلت رسیده یا از آن گذشته؛ شامل مهلت `00:00` هم می‌شود. |

متد `toArray()` همه ویژگی‌ها را با کلیدهای ثابت `snake_case` برمی‌گرداند:

```php
[
    'profile' => 'warehouse',
    'timezone' => 'Asia/Tehran',
    'received_at' => '2026-06-29T13:59:59.999999+03:30',
    'cutoff_at' => '2026-06-29T14:00:00.000000+03:30',
    'effective_business_date' => '2026-06-29',
    'same_day_eligible' => true,
    'reason' => 'before_cutoff',
]
```

زمان‌ها با قالب `Y-m-d\TH:i:s.uP`، شش رقم میکروثانیه و اختلاف ساعت صریح نوشته می‌شوند؛ برای UTC هم `+00:00` درج می‌شود. قالب `effective_business_date` برابر `Y-m-d` است. مقدارهای بولی، بولی می‌مانند و `cutoff_at` غیرفعال هم همان `null` است.

### نمونه اتصال به برنامه لاراول

در سرویس برنامه، زمان ثبت‌شده دریافت سفارش را بده؛ مثلاً ویژگی مدل که از قبل به `DateTimeInterface` تبدیل شده است:

```php
use Zarbinco\LaravelWorkdays\Facades\Workday;

$decision = Workday::profile('warehouse')->resolveCutoff($order->received_at);

// These are application-owned fields, not package-provided model attributes.
$order->business_date = $decision->effectiveBusinessDate->toDateString();
$order->cutoff_reason = $decision->reason;
$order->save();
```

پیش از تعهد انجام سفارش، بررسی موجودی، ظرفیت، پرداخت، آماده‌سازی و ارسال را در برنامه خودت انجام بده. هنگام محاسبه دوباره، از همان زمان ثبت‌شده دریافت استفاده کن.

### بررسی دستی با پروفایل جداگانه

در برنامه لاراول محلی، `php artisan tinker` را باز کن و این پروفایل جداگانه را برای همان نشست تنظیم کن:

```php
use Zarbinco\LaravelWorkdays\Facades\Workday;

config([
    'workdays.storage.driver' => 'config',
    'workdays.max_scan_days' => 14,
    'workdays.profiles.cutoff-demo' => [
        'weekends' => ['Saturday', 'Sunday'],
        'holidays' => ['gregorian' => [], 'jalali' => [], 'hijri' => []],
        'custom_holidays' => ['2026-07-03' => 'Example holiday'],
        'extra_working_days' => ['2026-07-04' => 'Example extra working day'],
        'cutoff' => [
            'timezone' => 'UTC',
            'time' => '14:00',
            'weekdays' => ['Wednesday' => null, 'Thursday' => '11:00'],
        ],
    ],
]);

$calculator = Workday::profile('cutoff-demo');
$calculator->resolveCutoff('2026-06-29 13:59:59')->toArray();
```

هر زمان زیر را به همان calculator بده و این فیلدها را مقایسه کن:

| زمان دریافت (UTC) | `same_day_eligible` | `effective_business_date` | `reason` |
|---|---|---|---|
| `2026-06-29 13:59:59` | `true` | `2026-06-29` | `before_cutoff` |
| `2026-06-29 14:00:00` | `false` | `2026-06-30` | `cutoff_reached` |
| `2026-06-30 14:00:00` | `false` | `2026-07-02` | `cutoff_reached` |
| `2026-07-01 10:00:00` | `false` | `2026-07-02` | `cutoff_disabled` |
| `2026-07-02 10:59:59` | `true` | `2026-07-02` | `before_cutoff` |
| `2026-07-02 11:00:00` | `false` | `2026-07-04` | `cutoff_reached` |
| `2026-07-03 10:00:00` | `false` | `2026-07-04` | `non_business_day` |
| `2026-07-04 10:00:00` | `true` | `2026-07-04` | `before_cutoff` |

چک‌لیست: ردیف اول برای قبل از مهلت، ردیف دوم برای خود مرز مهلت، ردیف تعطیلی و ردیف شنبه کاری جایگزین را بررسی کن. همچنین `cutoff_at` چهارشنبه باید `null` باشد، مهلت اسمی روز تعطیل نباید باعث پذیرش همان روز شود و با افزودن `.999999` به زمان قبل از مهلت، میکروثانیه‌ها باید حفظ شوند. این بررسی‌ها به تنظیم ساعت کاری یا دیتاست سالانه ایران نیاز ندارند.

## Carbon macros

Carbon macros به صورت پیش‌فرض برای `Carbon\Carbon`، `Carbon\CarbonImmutable` و `Illuminate\Support\Carbon` ثبت می‌شوند.

نام‌های پیشونددار امن‌ترند:

```php
use Carbon\CarbonImmutable;

$date = CarbonImmutable::parse('2026-06-29');

$date->workdayIsBusinessDay('iran');
$date->workdayAddBusinessDays(3, 'iran');
$date->workdayExplain('iran');

$datetime = CarbonImmutable::parse('2026-06-29 10:30');

$datetime->workdayIsBusinessTime('iran');
$datetime->workdayAddBusinessHours(2, 'iran');
$datetime->workdayDiffBusinessMinutesUntil('2026-06-30 12:00', 'iran');
```

aliasهای کوتاه هم، اگر با متدهای موجود تداخل نداشته باشند، فعال هستند:

```php
$date->isBusinessDay('iran');
$date->addBusinessDays(3, 'iran');
$date->explainWorkday('iran');
```

برای کنترل این رفتار:

```php
'carbon_macros' => [
    'enabled' => true,
    'short_aliases' => true,
    'override_existing' => false,
],
```

به صورت پیش‌فرض macroهای موجود یا متدهای اصلی Carbon بازنویسی نمی‌شوند.

## Validation rules

Validation ruleها wrapper لاراولی دور API همین پکیج هستند و در Form Request، controller، Livewire یا validator دستی قابل استفاده‌اند.

```php
use Zarbinco\LaravelWorkdays\Rules\WorkdayRule;

$request->validate([
    'delivery_date' => [
        'required',
        WorkdayRule::businessDay('iran'),
    ],
    'appointment_at' => [
        'required',
        WorkdayRule::businessTime('iran'),
    ],
    'due_date' => [
        'required',
        WorkdayRule::afterBusinessDays(3, now(), 'iran'),
    ],
]);
```

factoryهای پرکاربرد:

```php
WorkdayRule::businessDay('iran');
WorkdayRule::nonWorkingDay('iran');
WorkdayRule::weekend('iran');
WorkdayRule::calendarHoliday('iran');
WorkdayRule::customHoliday('iran');
WorkdayRule::extraWorkingDay('iran');
WorkdayRule::notBusinessDay('iran');
WorkdayRule::notWeekend('iran');
WorkdayRule::notCalendarHoliday('iran');
WorkdayRule::businessTime('iran');
WorkdayRule::notBusinessTime('iran');
WorkdayRule::afterBusinessDays(3, now(), 'iran');
WorkdayRule::beforeBusinessDays(3, $deadline, 'iran');
```

ruleهای مربوط به ساعت کاری به `working_hours` نیاز دارند. اگر تنظیم نشده باشد، validation با پیام قابل فهم fail می‌شود.

پیام rule را هم می‌توانی عوض کنی:

```php
WorkdayRule::businessDay('iran')->message('Please choose a working day.');
```

## API reference

چند متد اصلی facade و profile calculator:

```php
Workday::profile('global')->isBusinessDay($date);
Workday::profile('global')->isHoliday($date);
Workday::profile('global')->isNonWorkingDay($date);
Workday::profile('global')->isWeekend($date);
Workday::profile('global')->isCalendarHoliday($date);
Workday::profile('global')->isGregorianHoliday($date);
Workday::profile('global')->isJalaliHoliday($date);
Workday::profile('global')->isHijriHoliday($date);
Workday::profile('global')->isCustomHoliday($date);
Workday::profile('global')->isExtraWorkingDay($date);
Workday::profile('global')->isBusinessTime($datetime);
Workday::profile('global')->workingWindowsFor($date);
Workday::profile('global')->nextBusinessTime($datetime);
Workday::profile('global')->previousBusinessTime($datetime);
Workday::profile('global')->addBusinessMinutes($datetime, 90);
Workday::profile('global')->addBusinessHours($datetime, 1.5);
Workday::profile('global')->diffBusinessMinutes($startDateTime, $endDateTime);
Workday::profile('global')->diffBusinessHours($startDateTime, $endDateTime);
Workday::profile('global')->addBusinessDays($date, 5);
Workday::profile('global')->subBusinessDays($date, 5);
Workday::profile('global')->nextBusinessDay($date);
Workday::profile('global')->previousBusinessDay($date);
Workday::profile('global')->diffBusinessDays($startDate, $endDate);
Workday::profile('global')->calculate($date, 5);
Workday::profile('warehouse')->resolveCutoff($receivedAt);
```

متد `isHoliday()` یعنی روز غیرکاری. این نتیجه می‌تواند به خاطر آخر هفته، تعطیلی تقویمی یا تعطیلی دقیق باشد، مگر اینکه همان تاریخ به عنوان روز کاری جایگزین تعریف شده باشد.

## Config reference

نمای کلی config:

```php
return [
    'default_profile' => 'iran',
    'include_start_date' => false,
    'max_scan_days' => 3660,
    'storage' => [
        'driver' => 'config',
    ],
    'hijri' => [
        'method' => 'umm_al_qura',
        'adjustment' => 0,
    ],
    'carbon_macros' => [
        'enabled' => true,
        'short_aliases' => true,
        'override_existing' => false,
    ],
    'iran_official' => [
        'enabled' => false,
        'year' => null,
        'profile' => null,
        'calendar_path' => null,
    ],
    'profiles' => [
        'profile-name' => [
            'weekends' => ['Saturday', 'Sunday'],
            'holidays' => [
                'gregorian' => [],
                'jalali' => [],
                'hijri' => [],
            ],
            'custom_holidays' => [],
            'extra_working_days' => [],
            'working_hours' => [
                'Monday' => [['09:00', '17:00']],
            ],
            'extra_working_day_hours' => [
                ['09:00', '17:00'],
            ],
        ],
    ],
];
```

storage driverهای پشتیبانی‌شده:

- `config`
- `database`
- `chain`

اگر کلید تعطیلی، تاریخ دقیق، نام روز هفته، شکل پروفایل یا storage driver اشتباه باشد، پکیج زود و واضح خطا می‌دهد.

اعتبارسنجی سیاست اختیاری `cutoff` جداگانه و فقط در `resolveCutoff()` انجام می‌شود؛ جزئیات در بخش «سیاست ساعت نهایی پذیرش سفارش» آمده است.

## خطایابی

### جدول‌های دیتابیس وجود ندارند

اگر `storage.driver` برابر `database` یا `chain` است، migrationها را منتشر و اجرا کن:

```bash
php artisan vendor:publish --tag=workdays-migrations
php artisan migrate
```

### storage driver نامعتبر

مقدارهای معتبر `config`، `database` و `chain` هستند.

### کلید تعطیلی نامعتبر

تعطیلی‌های تکرارشونده باید `MM-DD` باشند:

- Gregorian: `01-01`
- Jalali: `01-01`
- Hijri: `01-09`

تعطیلی‌های دقیق و روزهای کاری جایگزین باید `Y-m-d` معتبر Gregorian باشند.

### No business day found within max_scan_days

اگر در محدوده جست‌وجو هیچ روز کاری پیدا نشود، متدهایی مثل `nextBusinessDay()`، `previousBusinessDay()`، `addBusinessDays()` و `calculate()` خطا می‌دهند.

در این حالت `weekends`، تعطیلی‌ها، روزهای کاری جایگزین و مقدار `max_scan_days` را بررسی کن.

## تست

```bash
composer validate --strict
composer install
composer test
composer format:test
```

## لایسنس

این پکیج با لایسنس MIT منتشر می‌شود. متن کامل در [LICENSE](../../LICENSE) است.
