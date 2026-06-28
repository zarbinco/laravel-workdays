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

preset ایران سبک و تکرارشونده است. تقویم رسمی سالانه برای زمانی است که برای یک سال مشخص، مثل ۱۴۰۵، به تعطیلی‌های رسمی همان سال نیاز داری.

تقویم رسمی سالانه به صورت پیش‌فرض فعال نیست.

دیتاست ۱۴۰۵ فقط داخل پکیج وجود دارد تا اگر کاربر خواست import کند. پکیج خودش ۱۴۰۵ را وارد دیتابیس نمی‌کند. پکیج خودش تقویم رسمی ۱۴۰۵ را جایگزین preset معمولی ایران نمی‌کند.

به بیان کوتاه، Iran 1405 کاملاً اختیاری و import-only است.

تنظیم پیش‌فرض رسمی ایران غیرفعال و خالی است:

```php
'iran_official' => [
    'enabled' => false,
    'year' => null,
    'profile' => null,
],
```

برای استفاده از دیتاست رسمی ۱۴۰۵ باید migrationها را منتشر و اجرا کنی:

```bash
php artisan vendor:publish --tag=workdays-migrations
php artisan migrate
```

قبل از ثبت رکوردها می‌توانی import را فقط پیش‌نمایش بگیری:

```bash
php artisan workdays:import-iran-calendar 1405 --dry-run
```

برای import واقعی:

```bash
php artisan workdays:import-iran-calendar 1405
```

یا با profile جدا:

```bash
php artisan workdays:import-iran-calendar 1405 --profile=iran-official-1405
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
