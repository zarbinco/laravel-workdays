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

## ارث‌بری پروفایل و انتخاب بر اساس زمینه

با پروفایل مشترک لازم نیست تقویم و ساعت کاری هر انبار، فروشنده یا حمل‌کننده را دوباره بنویسی. پروفایل فرزند می‌تواند تنظیمات مشترک را به ارث ببرد و فقط تفاوت‌های محلی را عوض کند. جدا از آن، برنامه می‌تواند با اطلاعات زمینه‌ای که خودش می‌دهد، از طریق قواعد صریح یک پروفایل تنظیم‌شده را انتخاب کند. هیچ‌کدام از این قابلیت‌ها انبار، فروشنده، حمل‌کننده، منطقه یا timezone را از مدل‌های برنامه یا سرویس خارجی پیدا نمی‌کنند.

فراخوانی موجود `Workday::profile($name)` از پروفایل ارث‌بر هم پشتیبانی می‌کند. متد جدید `Workday::resolveProfile($context)` نتیجه انتخاب تغییرناپذیر می‌دهد؛ `Workday::profileFor($context)` با همان قواعد انتخاب، `BusinessDayCalculator` فعلی را برمی‌گرداند. هر دو روی facade و `WorkdayManager` در دسترس‌اند. مسیریابی اختیاری است و تنظیمات پیش‌فرض پکیج آن را خودکار فعال نمی‌کنند.

### تنظیمات کامل برای فروشگاه اینترنتی

این نمونه جداگانه در نشست محلی `php artisan tinker`، پروفایل‌ها و قواعد مثال‌های بعد را تعریف می‌کند. در برنامه واقعی، تعریف معادل را در `config/workdays.php` بگذار. نمونه از config storage استفاده می‌کند و رکورد دیتابیس، رزرو، نوبت یا اعلان ایجاد نمی‌کند. تنظیمات قبلی نشست را برای بازگرداندن نگه دار.

```php
use Carbon\CarbonImmutable;
use Zarbinco\LaravelWorkdays\Facades\Workday;

$routingOriginalConfig = config('workdays');
$routingWeekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
$routingProfiles = [
    'route_base' => [
        'weekends' => ['Saturday', 'Sunday'],
        'holidays' => ['gregorian' => ['01-01' => 'New Year'], 'jalali' => [], 'hijri' => []],
        'custom_holidays' => [],
        'extra_working_days' => [],
        'working_hours' => array_fill_keys($routingWeekdays, [['09:00', '17:00']]),
        'cutoff' => [
            'timezone' => 'Asia/Tehran',
            'time' => '14:00',
            'weekdays' => ['Friday' => null],
        ],
    ],
    'route_regional' => [
        'extends' => 'route_base',
        'custom_holidays' => ['2026-11-04' => 'Regional closure'],
    ],
    'route_tehran' => [
        'extends' => 'route_regional',
        'working_hours' => ['thu' => [['09:00', '12:00']]],
        'special_working_hours' => ['2026-11-10' => [['10:00', '13:00']]],
        'cutoff' => ['time' => '13:00'],
    ],
    'route_isfahan' => [
        'extends' => 'route_base',
        'custom_holidays' => ['2026-11-12' => 'Local closure'],
    ],
    'route_seller' => ['extends' => 'route_base', 'weekends' => []],
    'route_carrier' => [
        'extends' => 'route_base',
        'working_hours' => array_fill_keys($routingWeekdays, [['12:00', '18:00']]),
    ],
    'route_night_base' => [
        'extends' => 'route_base',
        'allow_overnight_working_hours' => true,
        'working_hours' => [
            'Monday' => [['22:00', '06:00']],
            'Tuesday' => [], 'Wednesday' => [], 'Thursday' => [], 'Friday' => [],
        ],
    ],
    'route_night' => ['extends' => 'route_night_base'],
];

config([
    'workdays.storage.driver' => 'config',
    'workdays.max_scan_days' => 14,
    'workdays.profiles' => array_replace(config('workdays.profiles', []), $routingProfiles),
    'workdays.profile_routing' => [
        'fallback_profile' => 'route_base',
        'rules' => [
            ['id' => 'general_warehouse', 'match' => ['kind' => 'warehouse'], 'profile' => 'route_base'],
            ['id' => 'tehran_region', 'match' => ['kind' => 'warehouse', 'region' => 'tehran'], 'profile' => 'route_tehran'],
            ['id' => 'tehran_warehouse', 'match' => ['kind' => 'warehouse', 'region' => 'tehran', 'warehouse_id' => 42], 'profile' => 'route_tehran'],
            ['id' => 'seller_calendar', 'match' => ['kind' => 'seller', 'seller_id' => 7], 'profile' => 'route_seller'],
            ['id' => 'carrier_express', 'match' => ['kind' => 'carrier', 'carrier' => 'express'], 'profile' => 'route_carrier'],
        ],
    ],
]);

$warehouseContext = ['kind' => 'warehouse', 'warehouse_id' => 42, 'region' => 'tehran'];
$warehouse = Workday::resolveProfile($warehouseContext);
$warehouse->toArray();
// ['profile' => 'route_tehran', 'source' => 'rule', 'rule_id' => 'tehran_warehouse']

$selectedCalculator = Workday::profileFor($warehouseContext);
$directCalculator = Workday::profile($warehouse->profile);
$local = static fn (string $value): CarbonImmutable => CarbonImmutable::parse($value, 'Asia/Tehran');

$selectedCalculator->isHoliday($local('2026-01-01')); // true: inherited config holiday.
$selectedCalculator->workingWindowsFor($local('2026-11-05')); // Thursday 09:00–12:00.
Workday::resolveProfile(['kind' => 'seller', 'seller_id' => 7])->profile; // route_seller.
Workday::resolveProfile(['kind' => 'carrier', 'carrier' => 'express'])->profile; // route_carrier.

// The same selection API without the facade:
app(\Zarbinco\LaravelWorkdays\WorkdayManager::class)->resolveProfile($warehouseContext);
```

### ارث‌بری و قواعد دقیق ترکیب

مقدار `extends` باید رشته غیرخالی و نام یک پروفایل موجود باشد. زنجیره چندسطحی پشتیبانی می‌شود و حداکثر ۱۶ پروفایل، شامل فرزند انتخابی و ریشه، دارد. والد ناموجود، تعریف نامعتبر، ارجاع به خود، چرخه و عمق بیش از حد، با اطلاعات مفید پروفایل یا زنجیره خطا می‌دهند. فقط پروفایل انتخابی و والدهای لازم حل می‌شوند؛ پروفایل‌های نامرتبط پیشاپیش حل نمی‌شوند. تنظیمات سراسری لاراول تغییر نمی‌کند.

کلیدی که فرزند ننوشته، از والد به ارث می‌رسد. مقدار صریح طبق قواعد هر بخش در جدول زیر اعمال می‌شود؛ ادغام بازگشتی عمومی آرایه‌ها استفاده نمی‌شود.

| بخش | رفتار مقدار فرزند |
|---|---|
| `weekends` | کل فهرست جایگزین می‌شود؛ `[]` یعنی هیچ روز آخر هفته‌ای وجود ندارد. |
| `holidays` | ابتدا برحسب تقویم و بعد کلید تعطیلی ترکیب می‌شود. نگاشت خالی یک تقویم، همان تقویم را پاک می‌کند؛ `holidays => []` همه تقویم‌های تکرارشونده ارث‌رسیده را پاک می‌کند. برچسب `null` مجاز قبلی، برچسب باقی می‌ماند و دستور حذف نیست. |
| `custom_holidays`، `extra_working_days`، `special_working_hours` | برحسب تاریخ دقیق Gregorian ترکیب می‌شوند. مقدار کامل تاریخ فرزند جای والد می‌نشیند و اجزای فهرست پنجره‌ها ادغام نمی‌شوند. بخش کاملاً خالی، همه ورودی‌های ارث‌رسیده آن بخش را پاک می‌کند. تاریخ اختصاصی با `[]` همچنان یعنی بدون شروع شیفت جدید. |
| `working_hours` | برحسب روز هفته ISO نرمال‌شده ترکیب می‌شود. فرزند، کل فهرست پنجره‌های همان روز را جایگزین می‌کند؛ روز خالی پنجره‌های هفتگی آن روز را پاک می‌کند. بخش کاملاً خالی، کل برنامه هفتگی ارث‌رسیده را پاک می‌کند. ساعت جایگزین روز کاری اضافی در شرایط قبلی خودش همچنان اعمال می‌شود. |
| `extra_working_day_hours` | کل فهرست پنجره‌ها جایگزین می‌شود؛ فهرست خالی صریح هم همین حکم را دارد. |
| `seasonal_working_hours` | کل فهرست مرتب جایگزین می‌شود؛ `[]` فصل‌های ارث‌رسیده را پاک می‌کند. اعتبارسنجی فصل فاز ۰۴ حفظ می‌شود. |
| `allow_overnight_working_hours` | در صورت حذف ارث می‌رسد و در صورت وجود، مقدار بولی صریح جایگزین می‌شود. غیرفعال‌کردن در حالی که پنجره ارث‌رسیده به حالت شبانه نیاز دارد، خطاست. مقدار `false` به‌تنهایی همان مسیر قدیمی Microfix 01 را حفظ می‌کند. |
| `cutoff` | کلیدهای پشتیبانی‌شده سیاست ترکیب می‌شوند. `timezone` و `time` حذف‌شده ارث می‌رسند؛ `weekdays` برحسب روز نرمال‌شده ترکیب می‌شود و `null` صریح حفظ می‌شود. نگاشت خالی `cutoff.weekdays`، تغییرهای روز هفته ارث‌رسیده را پاک می‌کند. |
| دیگر فیلدهای موجود پروفایل | فیلد حذف‌شده ارث می‌رسد؛ مقدار صریح فرزند، کل مقدار را جایگزین می‌کند. آرایه اندیس‌دار دلخواه، بازگشتی ترکیب نمی‌شود. |

مثلاً `Monday` در والد و کلید صحیح `1` در فرزند، یک روز هستند. فرزند پنجره‌های دوشنبه را کامل جایگزین می‌کند، حتی اگر نام پذیرفته‌شده متفاوتی استفاده کرده باشد. نام‌های معادل تکراری در یک لایه رد می‌شوند؛ همین قاعده هنگام استفاده از سیاست cutoff برای نگاشت روزهای آن هم برقرار است. ساعت هفتگی خالی، تاریخ عادی را می‌بندد، اما تاریخ بازشده با روز کاری جایگزین همچنان می‌تواند از `extra_working_day_hours` استفاده کند. فقط ساعت خالی تاریخ مشخص یا روز صریح فصل، این fallback را هم می‌بندد؛ همان اولویت فاز ۰۴ حفظ می‌شود. ساعت‌ها پس از ترکیب همچنان باید اعتبارسنجی قبلی را بگذرانند. اعتبارسنجی cutoff فقط هنگام استفاده باقی می‌ماند؛ تنظیم بدشکل مهلت، وابستگی جدید فراخوانی معمول روز/ساعت کاری نمی‌شود.

ارث‌بری شامل `default_profile` سراسری، تنظیم storage یا تصمیم‌گیری درباره timezone نیست. نام اصلی فرزند انتخابی در نتیجه، توضیح تاریخ و جست‌وجوی دیتابیس حفظ می‌شود. زنجیره نمونه `route_base → route_regional → route_tehran` تعطیلی پایه، تعطیلی منطقه‌ای و ساعت دیگر روزهای هفته را به ارث می‌برد و پنجشنبه و ساعت cutoff را جایگزین می‌کند.

### تطبیق دقیق، اولویت و fallback

بخش `profile_routing` باید دقیقاً `fallback_profile` و فهرست مرتب `rules` را داشته باشد؛ این فهرست می‌تواند خالی باشد. fallback و هدف همه قواعد باید پروفایل تنظیم‌شده باشند. هر قاعده دقیقاً `id`، `match` و `profile` دارد؛ شناسه‌ها رشته‌های غیرخالی و یکتا هستند و `match` یک نگاشت انجمنی غیرخالی است.

کلیدهای match/context را برنامه تعیین می‌کند؛ باید شناسه ASCII با الگوی `[A-Za-z_][A-Za-z0-9_]*` باشند و به فیلدهای مثال محدود نیستند. مقدار باید عدد صحیح یا رشته‌ای غیر از `''` باشد. بولی، اعشاری، آرایه، شیء و `null` رد می‌شوند. زمینه خالی `[]` معتبر است و fallback را انتخاب می‌کند. همه فیلدهای ورودی اعتبارسنجی می‌شوند، حتی آن‌هایی که قاعده‌ای استفاده نمی‌کند.

قاعده فقط وقتی مطابق است که همه فیلدهای تعریف‌شده در زمینه وجود داشته باشند و دقیقاً برابر باشند. فیلد اضافی زمینه مجاز است، اما تطبیق بخشی کافی نیست. `42 !== '42'` و `'tehran' !== 'Tehran'`؛ trim، تغییر بزرگی حروف، تبدیل نوع یا آوانویسی انجام نمی‌شود و فاصله‌های رشته حفظ می‌شوند. شرط wildcard، regex، callable یا عبارت اجرایی وجود ندارد.

اگر چند قاعده مطابق باشند، قاعده با تعداد فیلد بیشتر برنده است. در تعداد برابر، ترتیب تنظیمات ملاک است و اولین قاعده برنده می‌شود. نبود تطبیق، `fallback_profile` صریح را انتخاب می‌کند، نه `default_profile`. در نمونه، هر سه قاعده انبار با زمینه کامل تهران مطابق‌اند؛ پس `tehran_warehouse` سه‌فیلدی با وجود قرارگرفتن در انتها برنده می‌شود. تغییر `warehouse_id` به رشته `'42'`، آن قاعده را نامطابق می‌کند و قاعده دوفیلدی `tehran_region` انتخاب می‌شود.

تنظیمات مسیریابی غایب یا نامعتبر فقط هنگام فراخوانی صریح API مسیریابی، `WorkdayConfigurationException` می‌دهد. زمینه ورودی نامعتبر، `InvalidArgumentException` می‌دهد. خطای زنجیره ارث‌بری از نوع `WorkdayConfigurationException` است؛ برای پروفایل انتخابی بدون ارث‌بری، نوع خطاهای اعتبارسنجی قبلی حفظ می‌شود. تنظیم مسیریابی نامرتبط و نامعتبر، API معمولی را که مسیریابی نمی‌کند خراب نمی‌کند؛ برای همه هدف‌های احتمالی هم calculator ساخته نمی‌شود.

### نتیجه انتخاب و هویت در storage

کلاس `Zarbinco\LaravelWorkdays\Data\ResolvedProfile` تغییرناپذیر است. ویژگی‌های `profile`، `source` با مقدار `rule` یا `fallback`، و `ruleId` با شناسه انتخابی یا `null` برای fallback را دارد. متد `toArray()` فقط کلیدهای ثابت `profile`، `source` و `rule_id` را می‌دهد و زمینه خام را شامل نمی‌شود. اطلاعات زمینه‌ای مورد نیاز برای ثبت رویداد را جداگانه و طبق سیاست حریم خصوصی برنامه نگه دار.

| storage driver | رفتار تقویم برای فرزند انتخابی |
|---|---|
| `config` | provider فعلی، تعطیلی تکرارشونده، تعطیلی دقیق و روز کاری جایگزین ارث‌رسیده را با نام واقعی فرزند می‌بیند. |
| `database` | رکوردها با نام فرزند انتخابی خوانده می‌شوند. تعطیلی‌هایی که فقط در دیتابیس والد هستند، **ارث نمی‌رسند**. تنظیمات پروفایل مثل آخر هفته و ساعت کاری همچنان از config ارث می‌رسند. |
| `chain` | تعطیلی config ارث‌رسیده با نام فرزند، با قواعد دیتابیس همان فرزند و طبق اولویت و گزارش منبع قبلی ترکیب می‌شود. رکوردهای دیتابیس والد اضافه نمی‌شوند. |

نام پروفایل ساختگی، مدل یا migration جدید و تغییر رفتار importer اضافه نمی‌شود. اولویت قبلی تعطیلی/provider و رفتار کش provider دیتابیس حفظ می‌شود. مسیریابی فقط یک نام انتخاب می‌کند و برای همین انتخاب، provider تعطیلی یا query دیتابیس نمی‌سازد.

### cutoff، برنامه تحویل و بازه‌های انتخابی

پس از بررسی موجودی، پرداخت، مجوز و جداسازی tenant در برنامه، تقویم انبار و حمل‌کننده را صریح انتخاب کن. نام‌های نتیجه را در ساختار موجود برنامه قرار بده؛ planner مراحلش را خودکار از روی زمینه مسیریابی نمی‌کند. پروفایل‌ها و قواعد این نمونه باید از قبل مانند نمونه بالا تنظیم شده باشند.

```php
$carrier = Workday::resolveProfile(['kind' => 'carrier', 'carrier' => 'express']);
$seller = Workday::resolveProfile(['kind' => 'seller', 'seller_id' => 7]);

$cutoffDecision = $selectedCalculator->resolveCutoff('2026-11-02 12:59:59.999999');
// Same-day eligible; inherited timezone Asia/Tehran, overridden cutoff 13:00.

$routedPlan = [
    'timezone' => 'Asia/Tehran',
    'profile_timezones' => [$warehouse->profile => 'Asia/Tehran', $carrier->profile => 'Asia/Tehran'],
    'cutoff_profile' => $warehouse->profile,
    'stages' => [
        ['id' => 'preparation', 'type' => 'work', 'profile' => $warehouse->profile, 'minutes' => 120],
        ['id' => 'handoff', 'type' => 'handoff', 'profiles' => [$warehouse->profile, $carrier->profile]],
    ],
];
$routedReadiness = Workday::planDelivery('2026-11-02 10:00', $routedPlan);
// Preparation and handoff complete at 12:00 Tehran / 08:30 UTC.

$routedSlotOptions = [
    'profile' => $warehouse->profile,
    'timezone' => 'Asia/Tehran',
    'days' => 2,
    'duration_minutes' => 120,
    'step_minutes' => 60,
    'min_notice_minutes' => 0,
    'max_slots' => 20,
];
$routedSlots = Workday::deliverySlots($routedReadiness->estimatedCompletionAt, $routedSlotOptions);
// First choice: 12:00–14:00 Tehran; no preparation or cutoff is reapplied.

$routedSpecialSlots = Workday::deliverySlots('2026-11-10 09:00', array_replace(
    $routedSlotOptions,
    ['days' => 1],
)); // Child's special date: 10:00–12:00 and 11:00–13:00 Tehran.

$inheritedNight = Workday::profile('route_night');
$inheritedNight->addBusinessMinutes($local('2026-11-02 23:00'), 180);
// November 3, 02:00 Tehran, using inherited overnight support and Monday hours.
```

برنامه عمداً در آمادگی/انتقال تمام می‌شود و تحویل نهایی به مشتری را نشان نمی‌دهد. اطلاعات cutoff و حالت شبانه ارث‌رسیده، بدون تغییر قرارداد فازهای ۰۱ تا ۰۴ رعایت می‌شود. اولویت تاریخ/فصل، ساعت خالی، ادامه شیفت، `24:00`، ترتیب شروع بازه‌ها، DST و افق محدود حفظ می‌شوند. فراخوانی جداگانه `$cutoffDecision` فقط برای نمایش است؛ وقتی `cutoff_profile` در برنامه باشد، همان برنامه تصمیم cutoff خودش را یک بار می‌گیرد.

مسیریابی، پروفایل انتخاب می‌کند نه منطقه زمانی. timezone از شناسه انبار، نام منطقه، محل سرور یا مقدار زمینه حدس زده نمی‌شود. cutoff از منطقه زمانی سیاست مؤثر خودش استفاده می‌کند. فراخوانی مستقیم ساعت کاری، منطقه زمانی شیء ورودی را نگه می‌دارد؛ برنامه تحویل و بازه‌ها همچنان تنظیمات صریح منطقه زمانی قبلی خود را می‌خواهند.

### جداسازی، خطاها و یازده بررسی دستی

برای ساخت calculator فقط زنجیره ارث‌بری انتخابی ترکیب می‌شود و تنظیم مؤثر در همان عملیات دوباره استفاده می‌شود. ارث‌بری برای هر تاریخ یا دقیقه تکرار نمی‌شود. کش سراسری فرایند یا پایدار، کش انتخاب بین زمینه‌های نامرتبط یا تغییر config سراسری وجود ندارد. عملیات/calculator تازه، تغییر تنظیمات را می‌بیند و به snapshot سراسری قدیمی وابسته نیست. مسیرهای معمول قبلی که مسیریابی نمی‌کنند، کار اضافی مسیریابی ندارند.

از نمونه جداگانه Tinker استفاده کن. همه تاریخ‌های زیر مربوط به سال ۲۰۲۶ هستند و نمونه‌های مستقیم datetime، ساعت تهران را صریح می‌دهند.

| بررسی | فراخوانی / مشاهده | نتیجه مورد انتظار |
|---|---|---|
| ۱. انبار معمولی | `Workday::resolveProfile(['kind' => 'warehouse', 'warehouse_id' => 99, 'region' => 'qom'])` | پروفایل `route_base`، منبع `rule`، شناسه `general_warehouse`. |
| ۲. قاعده دقیق تهران | `$warehouse->toArray()` را ببین | پروفایل `route_tehran`، منبع `rule`، شناسه `tehran_warehouse`؛ قاعده دقیق‌تر برنده است. |
| ۳. fallback | `Workday::resolveProfile([])->toArray()` | پروفایل `route_base`، منبع `fallback`، مقدار `rule_id => null`. |
| ۴. تعطیلی والد | `$selectedCalculator->isHoliday($local('2026-01-01'))` | `true`؛ تعطیلی config ارث‌رسیده با هویت فرزند دیده می‌شود. |
| ۵. پنجشنبه فرزند | `$selectedCalculator->workingWindowsFor($local('2026-11-05'))` | 09:00–12:00. |
| ۶. پنجشنبه فرزند دیگر | `Workday::profile('route_isfahan')->workingWindowsFor($local('2026-11-05'))` | ساعت والد 09:00–17:00؛ وضعیت پروفایل‌ها به هم نشت نمی‌کند. |
| ۷. cutoff ارث‌رسیده | `$cutoffDecision` را ببین؛ دوباره در `2026-11-02 13:00` حساب کن | timezone ارث‌رسیده و مهلت 13:00 فرزند؛ پیش از مرز مجاز و روی خود مرز نامجاز. |
| ۸. شیفت شبانه ارث‌رسیده | جمع دقیقه `route_night` بالا | سوم نوامبر، 02:00؛ سه‌شنبه بخش ورودی `00:00–06:00` دارد. |
| ۹. برنامه با انتخاب زمینه | `$routedReadiness->stages` و `estimatedCompletionAt` را ببین | نام انبار و حمل‌کننده صریح است؛ پایان در 12:00 تهران / 08:30 UTC. |
| ۱۰. بازه با انتخاب زمینه | `$routedSlots` و `$routedSpecialSlots` را ببین | اولین بازه آمادگی 12:00–14:00؛ گزینه‌های تاریخ اختصاصی 10:00–12:00 و 11:00–13:00. |
| ۱۱. اثر جانبی | وضعیت برنامه را پس از این محاسبه‌های config بررسی کن | بدون نوشتن دیتابیس، رزرو، نوبت، اعلان یا درخواست خارجی. |

تنظیمات نشست را با `config(['workdays' => $routingOriginalConfig]);` برگردان. از تقویم آزمایشی استفاده کن و ردیف تعطیلی محیط عملیاتی را تغییر نده. برنامه باید زمینه قابل اعتماد را فراهم کند، قواعد را تعریف کند، مجوز و جداسازی tenant را اعمال کند و انتخاب واقعی حمل‌کننده، ظرفیت، رزرو تراکنشی و ثبت نوبت را مدیریت کند. انتخاب تقویم حمل‌کننده به معنی انتخاب یا رزرو سرویس حمل فیزیکی نیست.

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

## ساعت کاری اختصاصی، برنامه فصلی و شیفت شبانه

پروفایل‌ها می‌توانند به‌صورت اختیاری ساعت کاری یک تاریخ مشخص، برنامه هفتگی فصلی و شیفت عبوری از نیمه‌شب داشته باشند؛ API جدیدی روی facade اضافه نمی‌شود. متدهای فعلی ساعت کاری، `planDelivery()` و `deliverySlots()` خودکار از برنامه مؤثر پروفایل استفاده می‌کنند. همه تنظیمات جدید اختیاری‌اند؛ پروفایل‌های پیش‌فرض پکیج و پروفایل‌های بدون این تنظیمات، رفتار و مسیر اجرای قبلی را حفظ می‌کنند.

### تنظیمات کامل و نمونه Tinker

این پروفایل‌های جداگانه را در نشست محلی `php artisan tinker` تعریف کن. نمونه از تقویم config استفاده می‌کند، تنظیمات قبلی را برای بازگرداندن نگه می‌دارد و هیچ رزرو، رکورد دیتابیس، اعلان یا درخواست خارجی ایجاد نمی‌کند.

```php
use Carbon\CarbonImmutable;
use Zarbinco\LaravelWorkdays\Facades\Workday;

$scheduleOriginalConfig = config('workdays');
$scheduleWeekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
$scheduleCalendar = [
    'weekends' => ['Saturday', 'Sunday'],
    'holidays' => ['gregorian' => [], 'jalali' => [], 'hijri' => []],
    'custom_holidays' => [],
    'extra_working_days' => [],
];
$scheduleProfile = array_replace($scheduleCalendar, [
    'working_hours' => array_fill_keys($scheduleWeekdays, [['09:00', '17:00']]),
    'allow_overnight_working_hours' => true,
    'special_working_hours' => [
        '2026-11-02' => [['10:00', '12:00']],
        '2026-11-03' => [],
    ],
    'seasonal_working_hours' => [
        [
            'from' => '2026-11-01',
            'through' => '2026-11-30',
            'weekdays' => [
                'Monday' => [['08:00', '20:00']],
                'Friday' => [],
            ],
        ],
    ],
]);
$nightProfile = array_replace($scheduleCalendar, [
    'allow_overnight_working_hours' => true,
    'working_hours' => [
        'Monday' => [['22:00', '06:00']],
        'Friday' => [['22:00', '06:00']],
    ],
    'special_working_hours' => ['2026-11-07' => []],
]);
$nightCarrierProfile = array_replace($scheduleCalendar, [
    'working_hours' => ['Tuesday' => [['01:00', '04:00']]],
]);

config([
    'workdays.storage.driver' => 'config',
    'workdays.max_scan_days' => 14,
    'workdays.profiles.hours-demo' => $scheduleProfile,
    'workdays.profiles.night-demo' => $nightProfile,
    'workdays.profiles.night-carrier-demo' => $nightCarrierProfile,
]);

$utc = static fn (string $value): CarbonImmutable => CarbonImmutable::parse($value, 'UTC');
$day = Workday::profile('hours-demo');
$night = Workday::profile('night-demo');

$day->workingWindowsFor($utc('2026-11-02')); // 10:00–12:00: ساعت اختصاصی تاریخ.
$night->workingWindowsFor($utc('2026-11-03')); // 00:00–06:00: ادامه شیفت دوشنبه.
$night->addBusinessMinutes($utc('2026-11-02 23:00'), 180); // ۳ نوامبر، 02:00 UTC.
$night->diffBusinessMinutes($utc('2026-11-02 23:00'), $utc('2026-11-03 03:00')); // 240.
$night->isBusinessDay($utc('2026-11-07')); // false: شنبه.
$night->isBusinessTime($utc('2026-11-07 02:00')); // true: ادامه شیفت معتبر جمعه.
```

همین کلیدهای اختیاری را در تنظیمات پروفایل برنامه خودت قرار بده. مقدار `special_working_hours` یک آرایه انجمنی از تاریخ‌های معتبر و دقیق Gregorian با قالب `Y-m-d` به آرایه پنجره‌هاست؛ کلید جلالی خودکار تبدیل نمی‌شود. مقدار `seasonal_working_hours` فهرست مرتب فصل‌هاست و هر مورد دقیقاً کلیدهای `from`، `through` و `weekdays` را دارد. ابتدا و انتها هر دو شامل می‌شوند، می‌توانند از مرز سال میلادی عبور کنند و باید `from <= through` باشد. این‌ها بازه‌های تاریخ مشخص‌اند و هر سال تکرار نمی‌شوند. هم‌پوشانی بازه‌ها رد می‌شود، حتی اگر روزهای هفته متفاوتی را تغییر بدهند.

کلیدهای روز هفته در فصل، از نام‌ها و اعداد ISO پشتیبانی‌شده توسط `WeekdayNormalizer` پیروی می‌کنند. دو نام معادل برای یک روز را نمی‌توان هم‌زمان تعریف کرد. روزی که در فصل نیامده، برنامه معمول هفتگی را به ارث می‌برد؛ مقدار صریح `[]` یعنی در آن روز هفته و در مدت فصل، شیفتی شروع نمی‌شود.

مقدار `allow_overnight_working_hours` باید واقعاً بولی باشد و پیش‌فرض آن `false` است. ساعت اختصاصی و برنامه فصلی روزانه به `true` بودن آن نیاز ندارند. با فعال‌کردنش، شیفت شبانه در برنامه هفتگی، تاریخ مشخص، فصل و `extra_working_day_hours` مجاز می‌شود. بازه تمام‌روز `['00:00', '24:00']` هم مجاز است. مقدار `24:00` فقط مرز پایان و به معنی نیمه‌شب محلی بعدی است؛ شروع نمی‌تواند `24:00` باشد. شروع و پایان یکسان، شکل نامعتبر پنجره، ساعت نامعتبر مثل `25:00` و هم‌پوشانی پنجره‌های یک برنامه تنظیم‌شده رد می‌شوند. پایان کوچک‌تر از شروع یعنی یک شیفت تا روز بعد، نه شیفت چندروزه طولانی‌تر از ۲۴ ساعت. بدون فعال‌سازی شبانه، اعتبارسنجی قبلی پایان پیش از شروع و `24:00` حفظ می‌شود.

### ترتیب انتخاب و معنی ساعت خالی

شیفت‌هایی که در یک تاریخ **شروع می‌شوند** با این ترتیب انتخاب می‌شوند:

1. ابتدا مجازبودن تاریخ طبق تقویم، با همان اولویت تعطیلی‌ها و روزهای کاری جایگزین.
2. اگر کلید ساعت اختصاصی آن تاریخ وجود دارد، همان ساعت‌ها کاملاً جای برنامه عادی و فصلی را می‌گیرند.
3. در غیر این صورت، اگر فصل فعال برای آن روز هفته مقدار صریح دارد، از آن استفاده می‌شود.
4. در غیر این صورت، برنامه هفتگی معمول انتخاب می‌شود.
5. ساعت جایگزین روز کاری اضافی، در شرایط قبلی خودش اعمال می‌شود.

تعطیلی یا آخر هفته صرفاً با تعریف ساعت اختصاصی یا فصلی، شیفت جدید نمی‌گیرد. برای بازکردن آن تاریخ از سازوکار موجود `extra_working_days` استفاده کن. تقویم همچنان از provider انتخابی config/database/chain می‌آید؛ ساعت‌های اختصاصی در تنظیمات پروفایل می‌مانند و مدل یا جدول جدیدی ندارند.

مقدار `[]` برای تاریخ مشخص یا روز صریح فصل، همه شروع‌های جدید آن تاریخ را می‌بندد و ساعت جایگزین روز کاری اضافی هم آن را باز نمی‌کند. روز حذف‌شده از فصل، ساعت هفتگی و رفتار قبلی ساعت جایگزین را به ارث می‌برد. تاریخ کاری تقویم با ساعت خالی همچنان تاریخ کاری است؛ معنی `isBusinessDay()`، `isHoliday()`، `isWeekend()` و `resolveCutoff()` تغییر نمی‌کند. ساعت بازشدن، مهلت سفارش را تغییر نمی‌دهد و مرحله روزمحور `business_days` را به محاسبه ساعت کاری تبدیل نمی‌کند.

### مالکیت شیفت شبانه و پنجره‌های مؤثر هر روز

شیفت 22:00–06:00 متعلق به **تاریخ شروع** خودش است. شیفت معتبر جمعه می‌تواند وارد شنبه شود، حتی اگر شنبه آخر هفته، تعطیلی یا دارای ساعت اختصاصی خالی باشد. پس برای شنبه ممکن است `isBusinessDay() === false` و در ساعت 02:00، نتیجه `isBusinessTime()` برابر `true` باشد. تعطیلی جمعه جلوی شروع شیفت را می‌گیرد، مگر اینکه جمعه با روز کاری جایگزین باز شده باشد. برای لغو شیفت ورودی، آن را در تاریخ شروعش تغییر بده یا حذف کن؛ بستن روز دریافت‌کننده، ادامه شیفت را قطع نمی‌کند.

متد `workingWindowsFor()` همچنان `TimeWindow`های مرتب و بدون هم‌پوشانی برای همان تاریخ محلی برمی‌گرداند. خروجی، بخش‌های مؤثر روزانه است و پیکربندی خام شیفت شبانه را برنمی‌گرداند:

| شیفت تنظیم‌شده | پنجره‌های مؤثر |
|---|---|
| دوشنبه 22:00 تا سه‌شنبه 06:00 | دوشنبه `22:00–24:00`؛ سه‌شنبه `00:00–06:00`. |
| ادامه معتبر `00:00–06:00` و شیفت جدید همان روز `05:00–09:00` | اجتماع `00:00–09:00`؛ زمان مشترک دوبار شمرده نمی‌شود. |
| ادامه `00:00–06:00` و پنجره مستقل `06:00–09:00` | دو پنجره مجاور، جدا می‌مانند. |

روز قبل با ساعت اختصاصی، فصل، برنامه هفتگی و تقویم خودش ارزیابی می‌شود. از تاریخی که اجازه شروع شیفت نداشته، ادامه ساختگی تولید نمی‌شود. بخش پایانی همان روز می‌تواند `TimeWindow('22:00', '24:00')` باشد؛ API عمومی `TimeWindow('22:00', '06:00')` برنمی‌گرداند.

بازه‌ها همچنان نیمه‌بازند؛ شروع شامل می‌شود و پایان شامل نمی‌شود. در نمونه دوشنبه‌شب، ساعت 21:59:59 دوشنبه بسته، 22:00 باز، ساعت 05:59:59 سه‌شنبه باز و 06:00 بسته است. متدهای `nextBusinessTime()` و `previousBusinessTime()` در همان محدوده جست‌وجوی قبلی، ادامه شیفت و ساعت‌های استثنا را پیدا می‌کنند. قرارداد قبلی زمان پیشین روی مرز بسته‌شدن، یعنی یک ثانیه قبل از مرز، حفظ می‌شود. جمع و اختلاف دقیقه/ساعت، زمان کاری مؤثر را بدون دوباره‌شماری از نیمه‌شب عبور می‌دهند؛ علامت اختلاف معکوس، قواعد تبدیل ساعت و رفتار مدت صفر هم حفظ می‌شوند.

### منطقه زمانی، تغییر ساعت و سازگاری

برای فراخوانی مستقیم متدهای ساعت کاری، تنظیم منطقه زمانی سراسری یا پروفایل جدیدی وجود ندارد. calculator از منطقه زمانی شیء تاریخ ورودی استفاده می‌کند؛ رشته‌ها همچنان با رفتار قبلی Carbon و timezone پیش‌فرض برنامه تفسیر می‌شوند. بهتر است مانند نمونه UTC، مقدار `DateTimeInterface` با منطقه زمانی صریح بدهی. فاز ۰۲ همچنان از `profile_timezones` تبدیل می‌کند و فاز ۰۳ گزینه صریح `timezone` می‌خواهد. این قراردادهای جداگانه تغییر نمی‌کنند.

در برنامه پیشرفته، مرز محلی روی تاریخ محلی مربوط ساخته می‌شود. مرز تنظیم‌شده ناموجود یا مبهم، خطایی با نام پروفایل، تاریخ و مرز می‌دهد؛ پنهانی جابه‌جا نمی‌شود و یکی از دو رخداد ساعت تکراری هم دلخواه انتخاب نمی‌شود. مرزهای معتبر و بدون ابهام در دو سوی تغییر اختلاف ساعت، زمان واقعاً سپری‌شده را حساب می‌کنند. شیفت معتبر 22:00–06:00 نیویورک در عبور از جلوکشیدن ساعت، ۴۲۰ دقیقه کاری دارد و در عقب‌کشیدن ساعت، ۵۴۰ دقیقه؛ به شرط آنکه تقویم، تاریخ شروع را مجاز کرده باشد.

اگر تاریخ محلی پس از حذف نیمه‌شب شروع شود، مرز مصنوعی `00:00` برای بخش روزانه از اولین لحظه واقعی همان تاریخ استفاده می‌کند؛ مثل `America/Santiago`. این استثنا فقط برای نیمه‌شب ناموجود در شروع روز است؛ نیمه‌شب مصنوعی مبهم همچنان خطایی روشن با نام پروفایل، تاریخ و مرز می‌دهد. پس در عقب‌کشیدن‌های تاریخی که نیمه‌شب تکرار می‌شود، مثل `America/Goose_Bay` در سال ۱۹۸۸، ممکن است تقسیم روزانه رد شود، حتی اگر شروع و پایان اصلی شیفت شبانه بدون ابهام باشند. تاریخ کاملاً حذف‌شده، زمان ساختگی تولید نمی‌کند و محدوده جست‌وجوی برچسب تاریخ‌ها را تمدید نمی‌کند. فاز ۰۳ سیاست سخت‌گیرانه‌تر مرز و تغییر ساعت گزینه‌ها را حفظ می‌کند: بازه‌ای با پایان روی نیمه‌شب ناموجود یا مبهم نمی‌سازد، حتی اگر بخش‌های ساعت کاری در دو طرف قابل استفاده باشند.

تنظیم نامعتبر، `WorkdayConfigurationException` می‌دهد؛ از جمله تاریخ یا فصل بدشکل، کلید ناشناخته فصل، محدوده معکوس یا هم‌پوشان، نام معادل تکراری روز هفته، شکل نامعتبر پنجره، فعال‌سازی غیربولی شبانه و مرز غیرمجاز. ارزیابی مرز محلی نامعتبر هم صریحاً با اطلاعات پروفایل، تاریخ و مرز خطا می‌دهد. پروفایل‌های فاقد هر سه کلید جدید، همان اعتبارسنجی، parsing، دقت، رفتار خواندن provider و مسیر محاسبه قبلی را نگه می‌دارند.

تعریف نرمال‌شده برنامه تا جای ممکن در همان calculator استفاده مجدد می‌شود. پیمایش تاریخ محدود است؛ خواندن دقیقه‌به‌دقیقه provider، ساخت provider داخل حلقه، کش سراسری mutable یا کش پایدار اضافه نمی‌شود. رفتار provider تعطیلی همچنان مرجع است: `DatabaseHolidayProvider` موجود، قواعد تکرارشونده و تاریخ‌های خاص خوانده‌شده برای هر پروفایل/تاریخ را در طول عمر calculator/provider در حافظه نگه می‌دارد. برای دیدن تغییر قواعد یا تاریخ‌های دیتابیس که قبلاً خوانده شده‌اند، یا تغییر تنظیمات پروفایل، calculator تازه بگیر. کش پنجره مؤثر اضافه نمی‌شود؛ مسیر پیشرفته provider تنظیمات فقط اعتبارسنجی موفق تنظیمات ثابت پروفایل را نگه می‌دارد.

### استفاده در برنامه تحویل و بازه‌های انتخابی

همان API فاز ۰۲ می‌تواند شبانه آماده‌سازی کند و پس از نیمه‌شب تحویل دهد. این برنامه در آمادگی/انتقال تمام می‌شود و به معنی تحویل فیزیکی به مشتری نیست:

```php
$overnightPlan = [
    'timezone' => 'UTC',
    'profile_timezones' => ['night-demo' => 'UTC', 'night-carrier-demo' => 'UTC'],
    'stages' => [
        ['id' => 'ready_for_handoff', 'type' => 'work', 'profile' => 'night-demo', 'minutes' => 180],
        ['id' => 'handoff', 'type' => 'handoff', 'profiles' => ['night-demo', 'night-carrier-demo']],
    ],
];
$overnightResult = Workday::planDelivery('2026-11-02T23:00:00Z', $overnightPlan);
// کار و انتقال، هر دو در ۳ نوامبر ساعت 02:00 UTC تمام می‌شوند.

$nightSlotOptions = [
    'profile' => 'night-demo',
    'timezone' => 'UTC',
    'days' => 2,
    'duration_minutes' => 120,
    'step_minutes' => 60,
    'min_notice_minutes' => 0,
    'max_slots' => 20,
];
$nightSlots = Workday::deliverySlots('2026-11-02T21:00:00Z', $nightSlotOptions);
// ۲ نوامبر: 22:00–24:00.
// ۳ نوامبر: 00:00–02:00، 01:00–03:00، 02:00–04:00، 03:00–05:00، 04:00–06:00.

$specialSlots = Workday::deliverySlots('2026-11-02T08:00:00Z', array_replace(
    $nightSlotOptions,
    ['profile' => 'hours-demo', 'days' => 1],
)); // دقیقاً 10:00–12:00.
```

مهلت سفارش فاز ۰۱ مستقل می‌ماند. مرحله work/handoff فاز ۰۲ خودکار از پنجره‌های مؤثر استفاده می‌کند، اما `business_days` همچنان برحسب تاریخ کاری است. فاز ۰۳ ساعت اختصاصی/فصلی و بخش‌های روزانه شیفت شبانه را استفاده می‌کند و مدت ثابت، ترتیب شروع هر پنجره، فاصله اضافی، خروجی UTC و سقف تاریخ و تعداد را حفظ می‌کند.

هر بازه انتخابی همچنان فقط یک پنجره روزانه را اشغال می‌کند: بازه 22:00–24:00 با نیمه‌شب معتبر مجاز است، حتی در آخرین تاریخ درخواست؛ چون زمان مثبتی از تاریخ بعد را اشغال نمی‌کند. بازه یک‌تکه 23:00–01:00 پیشنهاد نمی‌شود. ادامه صبحگاهی، ترتیب شروع خودش را از `00:00` آغاز می‌کند؛ پنجره‌های مستقل و مجاور برای ساخت بازه طولانی‌تر به هم وصل نمی‌شوند. فیلتر محافظه‌کارانه DST قبلی حفظ می‌شود. خروجی فقط انتخاب مجاز تقویمی است و ظرفیت یا رزرو را تضمین نمی‌کند.

### ده بررسی دستی

از نمونه کامل و جداگانه UTC بالا استفاده کن. همه تاریخ‌ها مربوط به سال ۲۰۲۶ هستند. برای دیدن پنجره‌ها می‌توانی از `array_map(static fn ($window) => $window->toArray(), $calculator->workingWindowsFor($date))` استفاده کنی.

| بررسی | فراخوانی / تنظیم Tinker | نتیجه مورد انتظار |
|---|---|---|
| ۱. برنامه هفتگی | `$day->workingWindowsFor($utc('2026-12-07'))` | دوشنبه معمولی 09:00–17:00. |
| ۲. تاریخ کوتاه‌تر | `$day->workingWindowsFor($utc('2026-11-02'))` | 10:00–12:00، با اولویت نسبت به ساعت دوشنبه فصل. |
| ۳. تاریخ با ساعت خالی | `$day->workingWindowsFor($utc('2026-11-03'))`؛ `$day->isBusinessDay($utc('2026-11-03'))` | پنجره جدید ندارد، اما تاریخ کاری است. اولین لحظه کاری بعد از 08:00، چهارم نوامبر ساعت 09:00 است. |
| ۴. فصل طولانی‌تر | `$day->workingWindowsFor($utc('2026-11-09'))` | دوشنبه 08:00–20:00؛ جمعه ششم نوامبر به دلیل مقدار فصلی `[]` شروع جدیدی ندارد. |
| ۵. پس از فصل | `$day->workingWindowsFor($utc('2026-12-07'))` | بازگشت به 09:00–17:00. |
| ۶. بخش‌های شبانه | پنجره‌های `$night` در دوم و سوم نوامبر و مرزهای 22:00 و 06:00 | ابتدا `22:00–24:00` و سپس `00:00–06:00`؛ شروع شامل و پایان خارج است. |
| ۷. محاسبه نیمه‌شب | فراخوانی‌های `addBusinessMinutes()` و `diffBusinessMinutes()` بالا | ۱۸۰ دقیقه به سوم نوامبر، 02:00 می‌رسد؛ اختلاف تا 03:00 برابر ۲۴۰ دقیقه است. |
| ۸. روز دریافت‌کننده بسته | `$night->isBusinessDay($utc('2026-11-07'))`؛ `$night->isBusinessTime($utc('2026-11-07 02:00'))` | ابتدا `false` و سپس `true`؛ ساعت خالی شنبه، ادامه شیفت جمعه را لغو نمی‌کند. |
| ۹. برنامه شبانه | `$overnightResult->stages` را ببین | پایان آماده‌سازی و انتقال در سوم نوامبر ساعت 02:00؛ هر دو پروفایل باز هستند. |
| ۱۰. بازه اختصاصی/شبانه | `$specialSlots->toArray()` و `$nightSlots->toArray()` را ببین | یک بازه اختصاصی 10:00–12:00 و شش بازه روزانه شبانه فهرست‌شده؛ بدون گزینه 23:00–01:00. |

تنظیمات نشست را با `config(['workdays' => $scheduleOriginalConfig]);` برگردان. این محاسبه‌ها ظرفیت رزرو نمی‌کنند و رکوردی نمی‌نویسند. داده تعطیلی محیط عملیاتی را برای آزمایش تغییر نده. فصل تکرارشونده سالانه، مدیریت برنامه کاری، همگام‌سازی تعطیلی، تعیین خودکار منطقه زمانی، رزرو و مدیریت ظرفیت، مسئولیت برنامه یا خارج از این قابلیت هستند.

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

## برنامه‌ریزی چندمرحله‌ای تحویل

متد `Workday::planDelivery($receivedAt, $plan)` تقویم‌ها و محاسبات ساعت کاری پروفایل‌های موجود را به یک برآورد مرحله‌به‌مرحله تبدیل می‌کند. مثلاً فروشگاه می‌تواند آماده‌سازی انبار، تحویل مرسوله در زمان فعالیت مشترک انبار و حمل‌کننده، مهلت حمل برحسب روز کاری، و پردازش نهایی تحویل را محاسبه کند.

خروجی، زمان پایان آخرین مرحله تعریف‌شده است. موجودی، تأیید پرداخت، ظرفیت، رزرو، انتخاب پیک و تعهد به مشتری همچنان مسئولیت فروشگاه است. فراخوانی این متد مرسوله‌ای ایجاد یا رهگیری نمی‌کند، نوبت جمع‌آوری نمی‌گیرد و با حمل‌کننده ارتباطی برقرار نمی‌کند.

این API اختیاری است و از facade و `WorkdayManager` در دسترس قرار دارد. تنظیم اجباری تازه‌ای به پروفایل‌ها اضافه نمی‌کند و به جدول، job یا وابستگی Composer جدید نیاز ندارد. آن را روی manager یا facade صدا بزن؛ یک برنامه می‌تواند چند پروفایل داشته باشد و روی calculator یک پروفایل اجرا نمی‌شود.

### نمونه کامل پروفایل‌ها و برنامه

این نمونه قطعی با منطقه زمانی UTC را می‌توان در برنامه لاراول محلی یا نشست `php artisan tinker` اجرا کرد. پروفایل‌ها را با تنظیمات فعلی `workdays` برنامه ادغام کن؛ در محیط عملیاتی، تقویم و منطقه زمانی واقعی مجموعه را قرار بده.

```php
use Zarbinco\LaravelWorkdays\Facades\Workday;

$weekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
$calendar = [
    'weekends' => ['Saturday', 'Sunday'],
    'holidays' => ['gregorian' => [], 'jalali' => [], 'hijri' => []],
    'custom_holidays' => [],
    'extra_working_days' => [],
];

$profiles = [
    'warehouse' => array_replace($calendar, [
        'working_hours' => array_fill_keys($weekdays, [
            ['09:00', '12:00'],
            ['13:00', '17:00'],
        ]),
        'cutoff' => ['timezone' => 'UTC', 'time' => '14:00'],
    ]),
    'carrier' => array_replace($calendar, [
        'working_hours' => array_fill_keys($weekdays, [['12:00', '18:00']]),
    ]),
    'last_mile' => array_replace($calendar, [
        'working_hours' => array_fill_keys($weekdays, [['09:00', '17:00']]),
    ]),
];

config([
    'workdays.storage.driver' => 'config',
    'workdays.max_scan_days' => 14,
    'workdays.profiles' => array_replace(config('workdays.profiles', []), $profiles),
]);

$plan = [
    'timezone' => 'UTC',
    'profile_timezones' => [
        'warehouse' => 'UTC',
        'carrier' => 'UTC',
        'last_mile' => 'UTC',
    ],
    'cutoff_profile' => 'warehouse',
    'stages' => [
        ['id' => 'preparation', 'type' => 'work', 'profile' => 'warehouse', 'minutes' => 120],
        ['id' => 'pickup', 'type' => 'handoff', 'profiles' => ['warehouse', 'carrier']],
        ['id' => 'transit', 'type' => 'business_days', 'profile' => 'carrier', 'days' => 2],
        ['id' => 'final_delivery', 'type' => 'work', 'profile' => 'last_mile', 'minutes' => 60],
    ],
];

$result = Workday::planDelivery('2026-06-29T13:30:00Z', $plan);
$result->estimatedCompletionAt; // 2026-07-01 16:30:00 UTC
$result->stages;
$result->cutoffResult;
$result->toArray();

// The same API is available without the facade:
app(\Zarbinco\LaravelWorkdays\WorkdayManager::class)
    ->planDelivery('2026-06-29T13:30:00Z', $plan);
```

کلیدهای `timezone`، `profile_timezones` و فهرست مرتب و غیرخالی `stages` الزامی‌اند. هر پروفایل ارجاع‌شده باید از قبل در `workdays.profiles` وجود داشته باشد و منطقه زمانی آن صریحاً در `profile_timezones` مشخص شود. از شناسه منطقه زمانی IANA مانند `Asia/Tehran`، `Asia/Dubai` یا `UTC` استفاده کن؛ اختلاف ساعت عددی، منطقه زمانی تنظیمات محسوب نمی‌شود. هر برنامه حداکثر ۳۲ مرحله دارد. شناسه مراحل باید غیرخالی و یکتا باشد؛ ثابت نگه‌داشتن شناسه‌ها، نتیجه ذخیره‌شده و خطاهای مرحله را قابل پیگیری می‌کند.

فقط کلیدهای مستند برنامه و هر نوع مرحله پذیرفته می‌شوند. کلید ناشناخته، نوع پشتیبانی‌نشده، فیلد الزامی حذف‌شده، پروفایل ناشناخته و مدت نامعتبر، `WorkdayConfigurationException` می‌دهند. مقدار `minutes` و `days` باید عدد صحیح نامنفی PHP باشد: `0` معتبر است، اما `'120'`، `120.0`، مقدار منفی و مقدار بولی رد می‌شوند. مرحله handoff به فهرستی مرتب از دقیقاً دو پروفایل متفاوت نیاز دارد.

### رفتار انواع مرحله

| نوع | کلیدهای الزامی مرحله | محاسبه |
|---|---|---|
| `work` | `id`، `type`، `profile`، `minutes` | زمان را به منطقه زمانی پروفایل می‌برد، با `nextBusinessTime()` اولین زمان کاری را پیدا می‌کند و برای مدت مثبت، `addBusinessMinutes()` را به کار می‌گیرد. حتی صفر دقیقه هم به زمان باز بودن مجموعه منتقل می‌شود. |
| `handoff` | `id`، `type`، `profiles` | اولین لحظه در زمان ورودی یا پس از آن را پیدا می‌کند که هر دو پروفایل، با منطقه زمانی خودشان، فعال باشند. شروع و پایان انتقال یک لحظه است. |
| `business_days` | `id`، `type`، `profile`، `days` | دقیقاً همین تعداد تاریخ کاریِ بعد از تاریخ فعلی را در منطقه زمانی پروفایل می‌شمارد و ساعت محلی را حفظ می‌کند. صفر روز، زمان ورودی را تغییر نمی‌دهد. ساعت کاری لازم نیست. |

مرحله‌های work و handoff از رفتار فعلی آخر هفته، تعطیلی، نیم‌روز، شیفت چندبخشی و روز کاری جایگزین استفاده می‌کنند؛ این شامل providerهای config/database/chain و ساعت جایگزین `extra_working_day_hours` هم می‌شود. نبود تنظیم ساعت کاری یا نبود ساعت قابل استفاده برای روز کاری جایگزین، خطاست. هیچ مرحله‌ای بی‌صدا کنار گذاشته نمی‌شود.

دقیقه کاری از زمان فعالیت مجموعه مصرف می‌شود و همان محاسبه فعلی با دقت دقیقه کامل را دارد. ناهار و تاریخ‌های تعطیل از دقیقه کار کم نمی‌کنند. روز کاری، برچسب تاریخ را جلو می‌برد؛ ساعت کاری مصرف نمی‌کند، زمان را به ابتدای ساعت فعالیت نمی‌برد و حرکت فیزیکی مرسوله را شبیه‌سازی نمی‌کند. تنظیم `include_start_date` قاعده شمارش تاریخ‌های اکیداً بعد از تاریخ فعلی را تغییر نمی‌دهد. نتیجه این مرحله ممکن است بیرون ساعت کاری باشد؛ مرحله work یا handoff بعدی، زمان شروع معتبر خودش را پیدا می‌کند.

بازه‌های کاری نیمه‌باز هستند: `[start, end)`. انتقال در لحظه شروع مشترک معتبر است، ولی در لحظه بسته‌شدن معتبر نیست. مرحله work می‌تواند دقیقاً هنگام بسته‌شدن تمام شود، اما مرحله بعد باید زمان شروع معتبر خودش را پیدا کند. مثلاً پایان آماده‌سازی در ساعت بسته‌شدن انبار به معنی امکان جمع‌آوری فوری نیست. جست‌وجوی handoff لحظه‌ها را در UTC مقایسه می‌کند، فقط رو به جلو می‌رود و فعال‌بودن هر دو پروفایل را در زمان پیشنهادی بررسی می‌کند؛ بازشدن یک مجموعه به‌تنهایی کافی نیست.

### ورودی، منطقه زمانی و اتصال سیاست مهلت سفارش

ورودی `$receivedAt` از نوع `string|\DateTimeInterface` است و همان قالب‌های مطلق و سخت‌گیرانه `resolveCutoff()` را می‌پذیرد: `Y-m-d H:i[:s[.microseconds]]` با فاصله برای زمان بدون منطقه زمانی، یا زمان ISO با جداکننده `T` و پسوند `Z` یا `±HH:MM`. بخش اعشاری ۱ تا ۶ رقم دارد و فقط پس از ثانیه می‌آید. ورودی دارای منطقه زمانی، لحظه واقعی خود را حفظ می‌کند؛ ورودی بدون منطقه زمانی با `timezone` برنامه تفسیر می‌شود. تاریخ بدون ساعت، عبارت نسبی، قالب نادرست، تاریخ نامعتبر و ساعت محلیِ ناموجود رد می‌شوند. زمان بدون اختلاف ساعت که در ساعت تکرارشده مبهم باشد نیز در planner رد می‌شود؛ برای انتخاب یکی از دو رخداد، اختلاف ساعت صریح بده. رفتار فعلی تجزیه ورودی در `resolveCutoff()` تغییر نمی‌کند.

planner پیش از فراخوانی API هر پروفایل، لحظه فعلی را به مقدار همان پروفایل در `profile_timezones` تبدیل می‌کند. مقایسه لحظه‌ها در UTC انجام می‌شود و منطقه زمانی پروفایل از منطقه زمانی برنامه لاراول حدس زده نمی‌شود. شیء تاریخ تغییرپذیرِ ورودی هرگز تغییر نمی‌کند؛ زمان‌های نتیجه جدید از نوع `CarbonImmutable` و در UTC هستند. ثانیه و میکروثانیه تا جایی که محاسبه فعلی ساعت کاری اجازه دهد حفظ می‌شوند.

در `business_days`، تاریخ مدنی حذف‌شده شمرده نمی‌شود. اگر ساعت محلی موردنظر در تاریخ مقصد به علت تغییر منطقه زمانی وجود نداشته باشد، مرحله به‌جای جابه‌جا کردن ساعت، صریحاً خطا می‌دهد. ساعت تکرارشده در تاریخ مقصد از انتخاب PHP/Carbon پیروی می‌کند. سیاست‌ها و تاریخ‌های گذار را متناسب با منطقه‌های زمانی عملیاتی خودت بررسی کن.

کلید `cutoff_profile` اختیاری است. با حذف آن، محاسبه از لحظه دریافت شروع می‌شود و به وجود یا اعتبار تنظیم cutoff وابسته نیست. اگر این کلید وجود داشته باشد، باید نام پروفایل مرحله اول را مشخص کند، نوع آن مرحله `work` باشد و منطقه زمانی تنظیم cutoff پروفایل دقیقاً با مقدار آن در `profile_timezones` یکسان باشد.

متد موجود `resolveCutoff()` فقط یک بار فراخوانی می‌شود. اگر تاریخ دریافت را بپذیرد، شروع برنامه همان لحظه اصلی سفارش است. در غیر این صورت، شروع برنامه ابتدای `effectiveBusinessDate` در منطقه زمانی cutoff خواهد بود. این فقط نقطه مبنای تاریخ است؛ مرحله work اول، آن را به زمان واقعی بازشدن منتقل می‌کند و نیمه‌شب به معنی شروع کار انبار نیست. cutoff برای مراحل بعد دوباره اعمال نمی‌شود. مرز انحصاری مهلت، تغییرات روز هفته، اولویت تعطیلی و بررسی بازه غیرخالی روزهای آینده همان رفتار فعلی را دارند. `CutoffResult` داخل نتیجه نیز منطقه زمانی محلی و قالب خروجی قبلی خود را حفظ می‌کند.

### یک افق جست‌وجوی سراسری

تنظیم `workdays.max_scan_days` کل برنامه را محدود می‌کند. مبنا، تاریخ زمان دریافت در منطقه زمانی برنامه است و همان تاریخ روز صفر محسوب می‌شود. مقدار `1`، تاریخ تقویمی بعدی را مجاز می‌کند؛ آخرین تاریخ مجاز هم داخل بازه است. نتیجه هر مرحله برای بررسی تاریخ، دوباره به منطقه زمانی برنامه تبدیل می‌شود. مراحل بعد سهمیه تازه‌ای نمی‌گیرند و حذف یک تاریخ مدنی، مرز برچسب تاریخ را عقب نمی‌اندازد.

مثلاً اگر تاریخ دریافت ۲۹ ژوئن و حد جست‌وجو `2` باشد، نتیجه اول ژوئیه مجاز است ولی نتیجه دوم ژوئیه خطا می‌دهد؛ حتی اگر هر مرحله به‌تنهایی بتواند زمان مناسب پیدا کند. اگر برنامه در محدوده کامل نشود، planner یک `RuntimeException` با مشخصات مرحله و پروفایل می‌دهد. برنامه موفقِ ناقص برگردانده نمی‌شود.

### نتیجه‌ها و قالب خروجی

کلاس‌های `Zarbinco\LaravelWorkdays\Data\DeliveryPlanResult` و `DeliveryStageResult` اشیای نتیجه تغییرناپذیر هستند.

| ویژگی برنامه | معنی |
|---|---|
| `timezone` | منطقه زمانی تفسیر ورودی و افق برنامه. |
| `receivedAt` | لحظه اصلی ورودی در UTC. |
| `planningStartAt` | زمان شروع پس از تصمیم اختیاری cutoff، در UTC. |
| `cutoffResult` | همان `CutoffResult` اصلی؛ با حذف cutoff برابر `null` است. |
| `stages` | آرایه مرتب از اشیای `DeliveryStageResult`. |
| `estimatedCompletionAt` | لحظه پایان آخرین مرحله در UTC. |

| ویژگی مرحله | معنی |
|---|---|
| `id`، `type` | شناسه ثابت ورودی و نوع پشتیبانی‌شده مرحله. |
| `profiles` | یک پروفایل برای work/business days و دو پروفایل برای handoff. |
| `readyAt` | زمان ورودی مرحله در UTC. |
| `startedAt` | زمان واقعی شروع کار یا انتقال؛ برای business days همان زمان ورودی است. |
| `completedAt` | زمان پایان که به مرحله بعد داده می‌شود. |

در هر مرحله رابطه `readyAt <= startedAt <= completedAt` برقرار است. شروع و پایان handoff و work صفر دقیقه‌ای برابر است. در business days صفرروزه، هر سه زمان یکسان‌اند.

متد `toArray()` از کلیدهای ثابت snake_case استفاده می‌کند. زمان‌های جدید با قالب `Y-m-d\TH:i:s.uP`، شش رقم میکروثانیه و `+00:00` نوشته می‌شوند؛ نتیجه cutoff داخل آن، خروجی محلی سیاست فاز ۰۱ را حفظ می‌کند. خروجی نمونه اول:

```php
[
    'timezone' => 'UTC',
    'received_at' => '2026-06-29T13:30:00.000000+00:00',
    'planning_start_at' => '2026-06-29T13:30:00.000000+00:00',
    'cutoff_result' => [
        'profile' => 'warehouse',
        'timezone' => 'UTC',
        'received_at' => '2026-06-29T13:30:00.000000+00:00',
        'cutoff_at' => '2026-06-29T14:00:00.000000+00:00',
        'effective_business_date' => '2026-06-29',
        'same_day_eligible' => true,
        'reason' => 'before_cutoff',
    ],
    'stages' => [
        [
            'id' => 'preparation', 'type' => 'work', 'profiles' => ['warehouse'],
            'ready_at' => '2026-06-29T13:30:00.000000+00:00',
            'started_at' => '2026-06-29T13:30:00.000000+00:00',
            'completed_at' => '2026-06-29T15:30:00.000000+00:00',
        ],
        [
            'id' => 'pickup', 'type' => 'handoff', 'profiles' => ['warehouse', 'carrier'],
            'ready_at' => '2026-06-29T15:30:00.000000+00:00',
            'started_at' => '2026-06-29T15:30:00.000000+00:00',
            'completed_at' => '2026-06-29T15:30:00.000000+00:00',
        ],
        [
            'id' => 'transit', 'type' => 'business_days', 'profiles' => ['carrier'],
            'ready_at' => '2026-06-29T15:30:00.000000+00:00',
            'started_at' => '2026-06-29T15:30:00.000000+00:00',
            'completed_at' => '2026-07-01T15:30:00.000000+00:00',
        ],
        [
            'id' => 'final_delivery', 'type' => 'work', 'profiles' => ['last_mile'],
            'ready_at' => '2026-07-01T15:30:00.000000+00:00',
            'started_at' => '2026-07-01T15:30:00.000000+00:00',
            'completed_at' => '2026-07-01T16:30:00.000000+00:00',
        ],
    ],
    'estimated_completion_at' => '2026-07-01T16:30:00.000000+00:00',
]
```

### اتصال به فروشگاه و خطاها

برنامه مناسب را در سرویس ثبت یا پردازش سفارش، پس از بررسی موجودی و پرداخت برنامه، صریحاً انتخاب کن. زمان ثبت‌شده دریافت سفارش را بده تا اجرای دوباره قابل مقایسه باشد:

```php
$estimate = Workday::planDelivery($order->received_at, $plan);

// Application-owned model fields; this package provides no shipment/order model.
$order->estimated_delivery_completion_at = $estimate->estimatedCompletionAt;
$order->delivery_calculation = $estimate->toArray();
$order->save();
```

castهای datetime و JSON مربوط را در برنامه خودت تنظیم کن. اگر بازتولید نتیجه تاریخی لازم است، نسخه سیاست و پروفایل اعمال‌شده را هم کنار نتیجه نگه دار؛ تغییر تقویم یا سیاست می‌تواند محاسبه بعدی را تغییر دهد. پیش از اعلام تعهد تحویل، شرط‌های موجودی، ظرفیت، پرداخت و رزرو را در برنامه بررسی کن.

| خطا | علت معمول |
|---|---|
| `Zarbinco\LaravelWorkdays\Exceptions\WorkdayConfigurationException` | تنظیم نامعتبر برنامه یا پروفایل، کلید یا پروفایل ناشناخته، نبود ساعت کاری، یا نبودن/ناسازگاری cutoff درخواست‌شده. خطای مرحله شامل شناسه یا اندیس آن است. |
| `InvalidArgumentException` | زمان دریافت نامعتبر یا پشتیبانی‌نشده؛ از جمله ساعت محلی ناموجود یا زمان مبهم بدون اختلاف ساعت. |
| `RuntimeException` | پایان افق سراسری، نبود زمان مشترک برای handoff، نبود زمان کاری قابل استفاده یا ساعت محلی ناموجود در مقصد business days. خطای مرحله، اطلاعات مرحله و پروفایل مرتبط را دارد. |

برای providerهای دیتابیس و chain، همان راه‌اندازی و migrationهای فعلی لازم است. برنامه‌ریزی جدول یا کش پایدار جدیدی اضافه نمی‌کند و مسیرهای API قبلی آن را فعال نمی‌کنند.

ظرفیت، رزرو، اتصال به حمل‌کننده، انتخاب خودکار پیک یا مسیر بر اساس نشانی، دسترسی لحظه‌ای و ایجاد یا رهگیری مرسوله فیزیکی همچنان مسئولیت برنامه است. پروفایل‌هایی که تنظیمات اختیاری را دارند، از ساعت اختصاصی، برنامه فصلی و بخش‌های روزانه شیفت شبانه توضیح‌داده‌شده در بالا هم استفاده می‌کنند. نتیجه فقط پایان آخرین مرحله تعریف‌شده را نشان می‌دهد؛ نام `final_delivery` به معنی اثبات رسیدن فیزیکی کالا نیست.

### چک‌لیست بررسی دستی

از نمونه UTC بالا با افق ۱۴روزه استفاده کن. همه تاریخ‌های زیر مربوط به سال ۲۰۲۶ هستند؛ لحظه‌ها و ترتیب مراحل را در UTC مقایسه کن.

| بررسی | زمان دریافت | آماده‌سازی | جمع‌آوری | پایان مهلت حمل | پردازش نهایی / برآورد |
|---|---|---|---|---|---|
| A: قبل از مهلت | ۲۹ ژوئن، 13:30 | ۲۹ ژوئن، 13:30–15:30 | ۲۹ ژوئن، 15:30 | ۱ ژوئیه، 15:30 | ۱ ژوئیه، 15:30–16:30 |
| B: دقیقاً روی مهلت | ۲۹ ژوئن، 14:00 | ۳۰ ژوئن، 09:00–11:00 | ۳۰ ژوئن، 13:00 | ۲ ژوئیه، 13:00 | ۲ ژوئیه، 13:00–14:00 |
| C: تعطیلی و آخر هفته | ۲ ژوئیه، 14:00 | ۶ ژوئیه، 09:00–11:00 | ۶ ژوئیه، 13:00 | ۸ ژوئیه، 13:00 | ۸ ژوئیه، 13:00–14:00 |

پیش از C، مقدار `['2026-07-03' => 'Example holiday']` را به `custom_holidays` هر سه پروفایل نمونه اضافه کن. در B مقدار `planningStartAt` برابر ۳۰ ژوئن ساعت 00:00 UTC است و در C برابر ۶ ژوئیه ساعت 00:00 UTC؛ هیچ‌کدام زمان شروع آماده‌سازی نیستند.

در B و C، حمل‌کننده ساعت 12:00 شروع به کار می‌کند و انبار همان لحظه برای ناهار بسته می‌شود. بازه نیمه‌باز انبار، ساعت 12:00 را شامل نمی‌شود؛ بنابراین اولین لحظه فعالیت مشترک 13:00 است و پردازش نهایی در 14:00 تمام می‌شود.

- D: برنامه‌ای فقط با مرحله handoff و بدون cutoff بساز. منطقه زمانی انبار را `Asia/Tehran` با ساعت دوشنبه تا جمعه 09:00–17:00 و منطقه زمانی حمل‌کننده را `Asia/Dubai` با ساعت دوشنبه تا جمعه 10:00–18:00 قرار بده. منطقه زمانی برنامه UTC بماند. ورودی `2026-06-29T05:45:00Z` باید زمان انتقال `2026-06-29T06:00:00.000000+00:00` بدهد.
- E: منطقه زمانی هر دو پروفایل را به UTC برگردان و ساعت دوشنبه تا جمعه انبار را 09:00–12:00 و حمل‌کننده را 13:00–17:00 بگذار. برنامه‌ای فقط با handoff باید در محدوده افق سراسری خطا بدهد.
- F: نمونه اصلی UTC را برگردان و فقط handoff را اجرا کن. ورودی ۲۹ ژوئن ساعت 13:00 همان لحظه منتقل می‌شود؛ ساعت 12:00 به دلیل ناهار انبار تا 13:00 منتظر می‌ماند؛ ساعت 17:00 به ۳۰ ژوئن ساعت 13:00 منتقل می‌شود. مرحله ۶۰دقیقه‌ای انبار از 16:00 می‌تواند در 17:00 تمام شود، اما جمع‌آوری بعد از آن هم باید تا ۳۰ ژوئن ساعت 13:00 صبر کند.
- G: تاریخ ۴ ژوئیه را برای انبار و حمل‌کننده در `extra_working_days` ثبت کن و روی هر دو، مقدار `extra_working_day_hours => [['09:00', '17:00']]` را قرار بده. بدون cutoff، برنامه شامل ۶۰ دقیقه کار انبار و سپس handoff، با دریافت در ۴ ژوئیه ساعت 08:00 UTC، در 09:00 شروع می‌شود و کار و انتقال هر دو در 10:00 تمام می‌شوند. پس از بررسی، تغییرات آزمایشی را بردار.

## بازه‌های زمانی تحویل و دریافت حضوری

متد `Workday::deliverySlots($readyAt, $options)` با استفاده از تقویم و ساعت کاری یک پروفایل، بازه‌های قابل انتخاب برای تحویل سفارش، دریافت حضوری یا تحویل کالای مرجوعی تولید می‌کند. مثلاً فروشگاه اینترنتی می‌تواند ابتدا زمان آماده‌شدن کالا را حساب کند، بازه‌های مجاز را در تسویه‌حساب نشان بدهد و سپس ظرفیت و شرایط رزرو را در برنامه خودش بررسی کند.

بازه تولیدشده فقط **از نظر تقویم و ساعت کاری مجاز است**؛ ظرفیت خالی یا امکان قطعی رزرو را تضمین نمی‌کند. انتخاب آن، سفارش، نوبت یا رزرو ایجاد نمی‌کند. پیش از ثبت رزرو، دوباره مجازبودن بازه را بررسی کن. ظرفیت، رزرو تراکنشی، جلوگیری از رزرو هم‌زمان بیش از ظرفیت، شناسه بازه، کد پیگیری رزرو و نگهداری نوبت‌ها مسئولیت برنامه مصرف‌کننده است. اتصال خودکار به API شرکت حمل‌ونقل هم وجود ندارد.

این API اختیاری است و روی facade و `WorkdayManager` در دسترس است. به سیاست cutoff، کلید اجباری جدید در پروفایل، جدول دیتابیس یا وابستگی جدید Composer نیاز ندارد. آن را روی manager یا facade فراخوانی کن؛ هر بار فقط یک پروفایل مشخص ارزیابی می‌شود. alias جدید روی profile calculator یا Carbon macro اضافه نمی‌شود.

### نمونه کامل و پروفایل آزمایشی جداگانه

این نمونه را با `php artisan tinker` در یک برنامه لاراول محلی اجرا کن. تنظیمات فقط برای همین نشست هستند، از config storage استفاده می‌کنند و رکورد تعطیلی یا رزرو نمی‌نویسند. تنظیمات قبلی را نگه دار تا بعد از بررسی برگردانی.

```php
use Zarbinco\LaravelWorkdays\Facades\Workday;

$slotOriginalConfig = config('workdays');
$slotWeekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
$slotHours = array_fill_keys($slotWeekdays, [
    ['09:00', '12:00'],
    ['13:00', '17:00'],
]);
$slotCalendar = [
    'weekends' => ['Saturday', 'Sunday'],
    'holidays' => ['gregorian' => [], 'jalali' => [], 'hijri' => []],
    'custom_holidays' => [],
    'extra_working_days' => [],
];
$slotProfile = array_replace($slotCalendar, ['working_hours' => $slotHours]);

config([
    'workdays.storage.driver' => 'config',
    'workdays.max_scan_days' => 14,
    'workdays.profiles.slots-demo' => $slotProfile,
]);

$options = [
    'profile' => 'slots-demo',
    'timezone' => 'UTC',
    'days' => 2,
    'duration_minutes' => 120,
    'step_minutes' => 60,
    'min_notice_minutes' => 0,
    'max_slots' => 10,
];

$result = Workday::deliverySlots('2026-06-29T10:30:00Z', $options);
$result->readyAt;
$result->eligibleFromAt;
$result->slots;
$result->toArray();

// همین API بدون facade:
app(\Zarbinco\LaravelWorkdays\WorkdayManager::class)
    ->deliverySlots('2026-06-29T10:30:00Z', $options);
```

خروجی هشت بازه دارد: ۲۹ ژوئن، ساعت‌های 13:00–15:00، 14:00–16:00 و 15:00–17:00؛ و ۳۰ ژوئن، ساعت‌های 09:00–11:00، 10:00–12:00، 13:00–15:00، 14:00–16:00 و 15:00–17:00. تاریخ و ساعت این نمونه برحسب UTC است.

### گزینه‌ها و اعتبارسنجی

| کلید | الزامی / پیش‌فرض | مقدار مجاز |
|---|---|---|
| `profile` | الزامی | نام غیرخالی یک پروفایل تعریف‌شده با `working_hours` معتبر. |
| `timezone` | الزامی | شناسه منطقه زمانی IANA، مثل `Asia/Tehran`، `America/New_York` یا `UTC`؛ اختلاف ساعت عددی جای منطقه زمانی پروفایل را نمی‌گیرد. |
| `days` | الزامی | عدد صحیح PHP از `1` تا `31`؛ شرط `days - 1 <= workdays.max_scan_days` هم باید برقرار باشد. |
| `duration_minutes` | الزامی | عدد صحیح PHP از `1` تا `1439`؛ طول واقعی هر بازه برحسب دقیقه سپری‌شده. |
| `step_minutes` | الزامی | عدد صحیح PHP از `1` تا `1439`؛ فاصله شروع گزینه‌ها در هر پنجره کاری. |
| `min_notice_minutes` | اختیاری؛ `0` | عدد صحیح نامنفی PHP؛ فاصله زمانی اضافی پس از آماده‌شدن. |
| `max_slots` | اختیاری؛ `50` | عدد صحیح PHP از `1` تا `200`؛ سقف تعداد بازه‌های خروجی. |

کلید یا پروفایل ناشناخته، نبود گزینه الزامی، ساعت کاری نامعتبر، منطقه زمانی نامعتبر و مقدار نامجاز، خطای `Zarbinco\LaravelWorkdays\Exceptions\WorkdayConfigurationException` می‌دهد. گزینه‌های عددی باید واقعاً عدد صحیح باشند؛ رشته عددی، اعشاری، بولی و `null` صریح پذیرفته نمی‌شوند. برای مقدار پیش‌فرض، کلید اختیاری را حذف کن. تنظیم موجود `workdays.max_scan_days` همچنان باید عدد صحیح مثبت باشد. گزینه‌های این قابلیت فقط هنگام فراخوانی همین API بررسی می‌شوند؛ calculatorهای معمولی به تنظیمات بازه تحویل نیاز ندارند.

### زمان آماده‌شدن، فاصله اضافی و تاریخ‌های جست‌وجو

مقدار `readyAt` را همیشه صریح بده؛ متد به‌جای آن `now()` نمی‌گذارد. این مقدار، زودترین لحظه آماده‌بودن عملیاتی کالا یا خدمت است که برنامه تو تعیین می‌کند. می‌تواند حاصل محاسبه برنامه، ویژگی `completedAt` یک مرحله معنادار آماده‌سازی در فاز ۰۲، یا `estimatedCompletionAt` برنامه‌ای باشد که عمداً در نقطه آمادگی تمام می‌شود. برنامه‌ای که پس از پردازش نهایی تحویل تمام می‌شود، لزوماً برنامه مناسبی برای تعیین آمادگی نیست.

نوع ورودی `string|\DateTimeInterface` است. قالب‌های رشته‌ای مطلق و دقیق، همان قالب‌های بخش مهلت پذیرش سفارش هستند: `Y-m-d H:i[:s[.microseconds]]` با فاصله، یا زمان ISO با `T` و `Z`/`±HH:MM`. بخش اعشاری ۱ تا ۶ رقم دارد و به ثانیه نیاز دارد. رشته بدون منطقه زمانی در `timezone` همین پروفایل تفسیر می‌شود؛ ورودی دارای اختلاف ساعت یا شیء تاریخ، لحظه واقعی خودش را حفظ می‌کند. رشته خالی، عبارت نسبی، ورودی فقط تاریخ، تاریخ ناممکن، اختلاف ساعت نامعتبر، ساعت محلی ناموجود و ساعت محلی مبهم بدون اختلاف ساعت، `InvalidArgumentException` می‌دهند. برای تعیین یکی از دو رخداد ساعت تکراری، اختلاف ساعت صریح یا یک لحظه از نوع `DateTimeInterface` بده. شیء mutable ورودی تغییر نمی‌کند.

رابطه `eligibleFromAt = readyAt + min_notice_minutes` روی خط زمانی UTC محاسبه می‌شود. فاصله اضافی فقط یک بار و برحسب دقیقه واقعاً سپری‌شده اعمال می‌شود؛ ساعت تعطیلی را هم در بر می‌گیرد. این مقدار دقیقه کاری نیست و آماده‌سازی را تکرار نمی‌کند. اگر آماده‌سازی باید زمان کاری مصرف کند، ابتدا آن را در فاز ۰۲ محاسبه کن. شروع بازه باید برابر یا بعد از این آستانه باشد؛ ثانیه و میکروثانیه حفظ می‌شوند و زمان به عقب گرد نمی‌شود. آمادگی در 09:00، شروع 09:00 را مجاز می‌کند؛ اما 09:00:00.000001 آن را حذف می‌کند.

برای این فاصله صحیح نامنفی، سقف قراردادی دلخواهی تعریف نشده، اما زمان حاصل باید در محدوده DateTime و عدد صحیح محیط اجرا قابل نمایش باشد. فاصله غیرقابل نمایش، `WorkdayConfigurationException` می‌دهد؛ فاصله بزرگی که قابل نمایش است ولی از تاریخ‌های درخواست عبور می‌کند، آستانه دقیق خودش را حفظ می‌کند و هیچ بازه‌ای برنمی‌گرداند.

مقدار `days` تعداد برچسب تاریخ‌های محلی است و از تاریخ `readyAt` شروع می‌شود؛ همان تاریخ هم حساب می‌شود. هفت روز یعنی روز صفر تا روز ششم، بدون توجه به آخر هفته، تعطیلی یا فاصله اضافی. جست‌وجو برای پیدا کردن هفت روز کاری تمدید نمی‌شود. اگر فاصله اضافی، آستانه را بیرون این تاریخ‌ها ببرد، فهرست خالی برمی‌گردد. تاریخی که در اثر تغییر منطقه زمانی کاملاً حذف شده، یکی از برچسب‌های محدوده را مصرف می‌کند، بازه‌ای ندارد و باعث تمدید محدوده نمی‌شود.

منطقه زمانی واقعی تقویم خدمت را بده و آن را با نگاشت `profile_timezones` در فاز ۰۲ هماهنگ نگه دار. منطقه زمانی پروفایل از timezone برنامه لاراول حدس زده نمی‌شود. مقایسه و ترتیب خروجی با لحظه‌های UTC انجام می‌شود؛ محدوده تاریخ‌ها برحسب منطقه زمانی صریح پروفایل است.

### مرزها، فاصله شروع‌ها و تقویم

هر بازه نیمه‌باز، یعنی `[start, end)` است و باید به‌طور کامل در یک پنجره کاری و یک تاریخ محلی داخل محدوده جا بگیرد. شروع در لحظه بازشدن و پایان دقیقاً در لحظه بسته‌شدن مجاز است؛ شروع در لحظه بسته‌شدن، عبور از ناهار، رفتن به پنجره بعدی یا اشغال زمان مثبتی از تاریخ محلی دوم مجاز نیست. پنجره مؤثر پروفایل فعال‌شده می‌تواند در `24:00` تمام شود و پایان دقیق بازه روی نیمه‌شب بعدی را مجاز کند. برای ساعت‌های 09:00–12:00 و 13:00–17:00، بازه‌های دوساعته 10:00–12:00 و 15:00–17:00 معتبرند؛ 11:00–13:00، 12:00–14:00 و 16:00–18:00 معتبر نیستند.

شروع گزینه‌ها از ساعت بازشدن همان پنجره، با رابطه «ساعت بازشدن به‌علاوه `n * step_minutes`» ساخته می‌شود. این ترتیب در بازشدن بعدازظهر از نو شروع می‌شود. آمادگی فقط این گزینه‌های ثابت را فیلتر می‌کند و شروع‌ها را به ثانیه دلخواه ورودی جابه‌جا نمی‌کند. مثلاً ۹۰ دقیقه فاصله بعد از 08:00، آستانه 09:30 و اولین بازه دوساعته 10:00–12:00 را می‌دهد. درخواست‌هایی با چند ثانیه اختلاف، همان زمان‌های شروع را دارند. فاصله شروع می‌تواند از طول بازه کمتر باشد؛ پس گزینه‌های خروجی ممکن است با هم هم‌پوشانی داشته باشند. این‌ها انتخاب‌های جایگزین‌اند و هنوز نوبت رزروشده نیستند.

متد از `workingWindowsFor()` و یک calculator در هر فراخوانی استفاده می‌کند. رفتار قبلی آخر هفته، تعطیلی تکرارشونده Gregorian/Jalali/Hijri، تعطیلی دقیق، providerهای config/database/chain، نیم‌روز، ساعت کاری چندبخشی و اولویت روز کاری جایگزین حفظ می‌شود. روز کاری جایگزین از ساعت همان روز هفته استفاده می‌کند و در نبود آن، سراغ `extra_working_day_hours` می‌رود؛ نبود هر دو همچنان خطای تنظیمات می‌دهد. مفهوم روزمحور `isBusinessDay()` مستقل از ساعت بازشدن باقی می‌ماند.

حداکثر `days` برچسب تاریخ بررسی می‌شود، پنجره‌های هر تاریخ موجود یک بار خوانده می‌شوند و شروع‌ها با گام تنظیم‌شده جلو می‌روند. خروجی بدون تکرار و به ترتیب زمان است و در `max_slots` متوقف می‌شود. کش سراسری یا پایدار جدیدی اضافه نمی‌شود. جست‌وجوی معتبر روی تعطیلی‌ها، روزهای بسته یا پنجره‌های کوتاه‌تر از مدت درخواستی، فهرست `slots` خالی می‌دهد.

### سیاست محافظه‌کارانه تغییرات منطقه زمانی

مرزهای گزینه ابتدا از تاریخ و ساعت محلی ساخته و پیش از تبدیل به UTC تغییرناپذیر بررسی می‌شوند. گزینه‌ای با شروع یا پایان محلی ناموجود یا مبهم کنار گذاشته می‌شود؛ ساعت ناموجود 02:30 پنهانی به 03:30 منتقل نمی‌شود. بازه‌ای که از تغییر اختلاف ساعت عبور کند هم کنار گذاشته می‌شود. گزینه‌های معتبر و بدون ابهام پیش یا پس از تغییر همچنان قابل استفاده‌اند، به شرط آنکه طول واقعی‌شان همان `duration_minutes` باشد و در پنجره کاری جا بگیرند. تاریخی که کامل حذف شده، مثل ۳۰ دسامبر ۲۰۱۱ در `Pacific/Apia`، بازه ساختگی تولید نمی‌کند.

این سیاست عمداً نزدیک تغییرات ساعت، گزینه‌های کمتری می‌دهد. برنامه شبانه فعال‌شده به شکل بخش‌های روزانه استفاده می‌شود؛ هر بازه انتخابی همچنان از نیمه‌شب عبور نمی‌کند و موتور عمومی زمان‌بندی DST هم ارائه نمی‌شود. ورودی، تقویم و دیتابیس مناطق زمانی یکسان، خروجی تکرارپذیر می‌دهند.

### اتصال به آمادگی فاز ۰۲

این نمونه محلی عمداً در پایان آماده‌سازی انبار تمام می‌شود و شامل حمل یا پردازش نهایی تحویل نیست. هر دو تقویم خدمت با ساعت تهران تفسیر می‌شوند؛ تولید بازه‌ها فاصله اضافی یا cutoff دیگری اعمال نمی‌کند.

```php
config([
    'workdays.profiles.readiness-demo' => $slotProfile,
    'workdays.profiles.pickup-demo' => $slotProfile,
]);

$readinessStagesPlan = [
    'timezone' => 'Asia/Tehran',
    'profile_timezones' => ['readiness-demo' => 'Asia/Tehran'],
    'stages' => [
        ['id' => 'ready_for_collection', 'type' => 'work', 'profile' => 'readiness-demo', 'minutes' => 120],
    ],
];

$readinessPlan = Workday::planDelivery(
    '2026-06-29 10:30',
    $readinessStagesPlan,
);
// آماده‌سازی هنگام ناهار متوقف می‌شود و در 13:30 تهران / 10:00 UTC تمام می‌شود.

$pickupSlots = Workday::deliverySlots($readinessPlan->estimatedCompletionAt, [
    'profile' => 'pickup-demo',
    'timezone' => 'Asia/Tehran',
    'days' => 7,
    'duration_minutes' => 120,
    'step_minutes' => 60,
    'min_notice_minutes' => 0,
    'max_slots' => 20,
]);
// اولین انتخاب: 14:00–16:00 تهران / 10:30–12:30 UTC.
```

هیچ بازه‌ای قبل از پایان آماده‌سازی داده‌شده شروع نمی‌شود. قرارداد خروجی `planDelivery()` و `deliverySlots()` جدا می‌ماند. اگر برنامه آمادگی صریحاً از cutoff استفاده کند، همان‌جا اعمال می‌شود؛ تولید بازه‌ها دوباره آن سیاست یا مرحله آماده‌سازی را اجرا نمی‌کند.

### نتیجه‌ها و تبدیل به آرایه

کلاس‌های `Zarbinco\LaravelWorkdays\Data\DeliverySlotsResult` و `DeliverySlot` نتیجه‌های تغییرناپذیر هستند. کلاس اول این ویژگی‌ها را دارد:

| ویژگی | کلید خروجی آرایه | معنی |
|---|---|---|
| `profile` | `profile` | پروفایل تنظیم‌شده انتخابی. |
| `timezone` | `timezone` | منطقه زمانی محلی خدمت که در نتیجه حفظ شده است. |
| `readyAt` | `ready_at` | لحظه اصلی آمادگی، از نوع UTC `CarbonImmutable`. |
| `eligibleFromAt` | `eligible_from_at` | لحظه UTC `CarbonImmutable` پس از فاصله زمانی اضافی. |
| `requestedDays` | `requested_days` | تعداد تاریخ‌های محلی درخواستی. |
| `slots` | `slots` | فهرست مرتب از `DeliverySlot`های تغییرناپذیر، یا `[]`. |

هر بازه ویژگی‌های `startsAt` و `endsAt` از نوع UTC `CarbonImmutable` دارد. متد `toArray()` مرزهای محلی را با منطقه زمانی ثبت‌شده خدمت محاسبه می‌کند. برای اولین بازه نمونه UTC:

```php
$result->slots[0]->toArray();
// [
//     'starts_at' => '2026-06-29T13:00:00.000000+00:00',
//     'ends_at' => '2026-06-29T15:00:00.000000+00:00',
//     'local_starts_at' => '2026-06-29T13:00:00.000000+00:00',
//     'local_ends_at' => '2026-06-29T15:00:00.000000+00:00',
// ]
```

همه زمان‌های آرایه با قالب `Y-m-d\TH:i:s.uP`، شش رقم میکروثانیه و اختلاف ساعت صریح نوشته می‌شوند؛ UTC هم `+00:00` دارد. فیلدهای محلی اختلاف ساعت واقعی خودشان را دارند؛ اولین `local_starts_at` نمونه تهران برابر `2026-06-29T14:00:00.000000+03:30` است. متد `toArray()` نتیجه، از کلیدهای ثابت snake_case جدول استفاده و همه بازه‌ها را به آرایه تبدیل می‌کند. خروجی خالی معتبر هم آمادگی، آستانه، پروفایل، منطقه زمانی، تعداد روز و `'slots' => []` را دارد. تغییر بعدی شیء ورودی یا config، نتیجه قبلی را تغییر نمی‌دهد.

### بررسی دستی و مسئولیت برنامه

از پروفایل جداگانه Tinker بالا استفاده کن. بین بررسی‌ها، `workdays.profiles.slots-demo` را به `$slotProfile` و `$options` را به مقدارهای اصلی برگردان. همه تاریخ‌های زیر مربوط به سال ۲۰۲۶ هستند و ساعت‌ها، جز مواردی که صریح گفته شده، UTC هستند.

| بررسی | تغییر / ورودی | نتیجه مورد انتظار |
|---|---|---|
| A: ساعت چندبخشی | نمونه اصلی؛ ۲۹ ژوئن، 10:30 | اولین انتخاب‌ها 13:00–15:00، 14:00–16:00 و 15:00–17:00؛ در مجموع هشت بازه در دو تاریخ و هیچ‌کدام از ناهار عبور نمی‌کند. |
| B: فاصله اضافی | ۲۹ ژوئن، 08:00؛ مقدار `min_notice_minutes` برابر `90` | آستانه 09:30؛ اولین انتخاب 10:00–12:00. |
| C: مرز دقیق | ۲۹ ژوئن، 09:00 و سپس 09:00:00.000001 | بازه 09:00–11:00 فقط برای ورودی اول؛ پایان 10:00–12:00 و 15:00–17:00 می‌تواند دقیقاً روی بسته‌شدن باشد، اما شروعی روی بسته‌شدن نیست. |
| D: تعطیلی و آخر هفته | مقدار `custom_holidays` برابر `['2026-07-03' => 'Test holiday']`؛ ورودی ۲ ژوئیه، 17:00؛ `days = 5` | جمعه و آخر هفته گزینه‌ای ندارند؛ اولین بازه دوشنبه ۶ ژوئیه، 09:00–11:00 است. |
| E: نیم‌روز | ساعت پنجشنبه را `[['09:00', '12:00']]` بگذار؛ ورودی ۲ ژوئیه، 08:00؛ `days = 1` | دقیقاً 09:00–11:00 و 10:00–12:00. |
| F: روز کاری جایگزین | مقدار `extra_working_days` برابر `['2026-07-04' => 'Test working day']` و `extra_working_day_hours` برابر `[['10:00', '14:00']]`؛ ورودی ۴ ژوئیه، 08:00؛ `days = 1` | بازه‌های 10:00–12:00، 11:00–13:00 و 12:00–14:00. با حذف ساعت جایگزین، همان خطای فعلی نبود ساعت کاری را می‌گیری. |
| G: پنجره کوتاه | ساعت دوشنبه را `[['09:00', '10:00']]` بگذار؛ ورودی ۲۹ ژوئن، 08:00؛ `days = 1` | فهرست `slots` خالی، بدون خطا. |

نمونه اتصال به آمادگی را هم اجرا کن و مطمئن شو شروع همه بازه‌ها برابر یا بعد از پایان برنامه است. فیلدهای UTC و محلی را ببین و بررسی کن هیچ رزرو، نوبت، نوشتن در دیتابیس یا اعلانی ایجاد نشده است. تنظیمات نشست را با `config(['workdays' => $slotOriginalConfig]);` برگردان. از config و تقویم آزمایشی استفاده کن و رکورد تعطیلی محیط عملیاتی را تغییر نده.

برنامه باید هنگام رزرو، مجازبودن بازه و ظرفیت باقی‌مانده را دوباره بررسی کند، رزرو تراکنشی و قواعد هم‌زمانی خودش را اعمال کند و درباره وعده به مشتری تصمیم بگیرد. این فاز در هر فراخوانی یک پروفایل خدمت را ارزیابی می‌کند؛ اشتراک دسترسی چند پروفایل در تمام طول بازه خارج از محدوده آن است. ساعت اختصاصی/فصلی و شیفت شبانه از طریق تنظیمات اختیاری پروفایل در دسترس‌اند؛ بازه‌ها داخل پنجره‌های مؤثر روزانه می‌مانند. رابط کاربری، route، controller، اعلان، job پس‌زمینه، مدل سفارش و مرسوله یا محل ذخیره رزرو اضافه نمی‌شود.

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
Workday::resolveProfile($context);
Workday::profileFor($context);
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
Workday::planDelivery($receivedAt, $plan);
Workday::deliverySlots($readyAt, $options);
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
