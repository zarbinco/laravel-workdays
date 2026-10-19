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

## برنامه‌ریزی معکوس تحویل و آخرین زمان دریافت سفارش

متد `Workday::reversePlanDelivery($desiredCompletionAt, $plan, ['lookback_days' => 14])` آخرین زمان دریافت سفارش را پیدا می‌کند که با تقویم‌ها و برنامه مستقیم فعلی، در موعد خواسته‌شده یا پیش از آن تمام شود. برنامه‌ریزی مستقیم زمان پایان سفارش دریافتی را محاسبه می‌کند؛ برنامه‌ریزی معکوس مشخص می‌کند سفارش حداکثر تا چه زمانی می‌تواند دریافت شود. هر دو API از همان برنامه فاز ۰۲، تقویم پروفایل‌ها، قواعد مراحل و افق محاسبه مستقیم استفاده می‌کنند.

این API روی facade و `WorkdayManager` اختیاری است و شیء تغییرناپذیر `Zarbinco\LaravelWorkdays\Data\ReverseDeliveryPlanResult` برمی‌گرداند. تنظیم اجباری تازه برای پروفایل، جدول، job یا وابستگی جدیدی لازم ندارد. پروفایل‌های برنامه را صریحاً انتخاب کن؛ اگر مسیریابی بر اساس context لازم است، آن را جداگانه پیش از ساخت برنامه انجام بده.

### نمونه کامل فروشگاه و Tinker

این نمونه اختصاصی با storage از نوع config را در یک نشست محلی `php artisan tinker` اجرا کن. پروفایل‌های آزمایشی، آماده‌سازی انبار، انتقال به حمل‌کننده، مهلت حمل و پردازش نهایی نمونه برنامه‌ریزی مستقیم را بازسازی می‌کنند. تنظیمات اولیه را نگه دار تا در آخر آن‌ها را برگردانی.

```php
use Zarbinco\LaravelWorkdays\Facades\Workday;

$reverseOriginalConfig = config('workdays');
$reverseWeekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
$reverseBase = [
    'weekends' => ['Saturday', 'Sunday'],
    'holidays' => ['gregorian' => [], 'jalali' => [], 'hijri' => []],
    'custom_holidays' => [],
    'extra_working_days' => [],
    'working_hours' => array_fill_keys($reverseWeekdays, [['09:00', '17:00']]),
];
$reverseProfiles = [
    'reverse_base' => $reverseBase,
    'reverse_warehouse' => [
        'extends' => 'reverse_base',
        'cutoff' => ['timezone' => 'UTC', 'time' => '14:00'],
    ],
    'reverse_split' => [
        'extends' => 'reverse_warehouse',
        'working_hours' => array_fill_keys($reverseWeekdays, [
            ['09:00', '12:00'], ['13:00', '17:00'],
        ]),
    ],
    'reverse_carrier' => [
        'extends' => 'reverse_base',
        'working_hours' => array_fill_keys($reverseWeekdays, [['12:00', '18:00']]),
    ],
    'reverse_last_mile' => ['extends' => 'reverse_base'],
    'reverse_night' => [
        'extends' => 'reverse_base',
        'allow_overnight_working_hours' => true,
        'working_hours' => [
            'Monday' => [['22:00', '06:00']],
            'Tuesday' => [], 'Wednesday' => [], 'Thursday' => [], 'Friday' => [],
        ],
    ],
    'reverse_new_york' => array_replace($reverseBase, ['weekends' => []]),
];
config([
    'workdays.storage.driver' => 'config',
    'workdays.max_scan_days' => 14,
    'workdays.profiles' => array_replace(config('workdays.profiles', []), $reverseProfiles),
]);

$reverseWork = function (string $profile, int $minutes = 120, bool $cutoff = false): array {
    $plan = [
        'timezone' => 'UTC',
        'profile_timezones' => [$profile => 'UTC'],
        'stages' => [
            ['id' => 'preparation', 'type' => 'work', 'profile' => $profile, 'minutes' => $minutes],
        ],
    ];

    if ($cutoff) {
        $plan['cutoff_profile'] = $profile;
    }

    return $plan;
};
$reversePlan = [
    'timezone' => 'UTC',
    'profile_timezones' => [
        'reverse_split' => 'UTC',
        'reverse_carrier' => 'UTC',
        'reverse_last_mile' => 'UTC',
    ],
    'cutoff_profile' => 'reverse_split',
    'stages' => [
        ['id' => 'preparation', 'type' => 'work', 'profile' => 'reverse_split', 'minutes' => 120],
        ['id' => 'pickup', 'type' => 'handoff', 'profiles' => ['reverse_split', 'reverse_carrier']],
        ['id' => 'transit', 'type' => 'business_days', 'profile' => 'reverse_carrier', 'days' => 2],
        ['id' => 'final_delivery', 'type' => 'work', 'profile' => 'reverse_last_mile', 'minutes' => 60],
    ],
];

$reverse = Workday::reversePlanDelivery(
    '2026-07-01T16:30:00Z',
    $reversePlan,
    ['lookback_days' => 14],
);
$reverse->latestOrderAt; // 2026-06-29 13:30:00 UTC
$reverse->forwardResult->stages;
$reverse->toArray();

// The same operation is available directly on the manager:
app(\Zarbinco\LaravelWorkdays\WorkdayManager::class)->reversePlanDelivery(
    '2026-07-01T16:30:00Z', $reversePlan, ['lookback_days' => 14],
);
```

در این سیاست فروشگاه، آخرین زمان دریافت و شروع آماده‌سازی هر دو ۲۹ ژوئن ساعت 13:30 UTC هستند. آماده‌سازی در 15:30 تمام می‌شود، انتقال در همان 15:30 انجام می‌شود، دو تاریخ کاری حمل‌کننده در ۱ ژوئیه ساعت 15:30 به پایان می‌رسد و پردازش نهایی دقیقاً در موعد 16:30 تمام می‌شود. نام فیلد شروع مرحله اول عمومی است؛ برنامه معتبر دیگری ممکن است با handoff یا business days شروع شود، نه آماده‌سازی انبار.

### ورودی‌ها، محدوده دقیق جست‌وجو و افق محاسبه مستقیم

امضای متد، موعد `string|\DateTimeInterface $desiredCompletionAt`، همان آرایه برنامه فاز ۰۲ و آرایه گزینه‌ها را می‌پذیرد. گزینه `lookback_days` الزامی است: عدد صحیح واقعی PHP از `1` تا `31` که از `workdays.max_scan_days` بیشتر نباشد. گزینه ناشناخته، رشته عددی، اعشاری، بولی و null رد می‌شوند. برنامه همچنان `timezone`، نگاشت صریح `profile_timezones`، فهرست مرتب ۱ تا ۳۲ مرحله، شناسه‌های یکتا و مدت‌های صحیح نامنفی می‌خواهد؛ ساختار مرحله مخصوص محاسبه معکوس وجود ندارد.

موعد را به منطقه زمانی برنامه تبدیل کن تا روز صفر مشخص شود. lookback برابر ۱۴، آن تاریخ محلی و ۱۴ برچسب تاریخ قبلی را شامل می‌شود؛ در مجموع ۱۵ برچسب. مقدار `searchStartAt` اولین لحظه واقعیِ نخستین تاریخ مجاز است و خود موعد، کران بالای شامل‌شونده است. تاریخ مدنی حذف‌شده زمان دریافت فرضی نمی‌سازد و محدوده برچسب تاریخ‌ها را گسترش نمی‌دهد. زمان دریافت خارج از این محدوده هیچ‌وقت برگردانده نمی‌شود.

محدوده جست‌وجوی گذشته، افق محاسبه مستقیم را زیاد نمی‌کند. هر نامزد باید در همان افق سراسری `workdays.max_scan_days` فاز ۰۲ هم تمام شود؛ مبدأ این افق، تاریخ دریافت همان نامزد در منطقه زمانی برنامه است. مراحل بعدی افق تازه نمی‌گیرند. وجود یک دریافت قابل قبول پیش از کران گذشته، درخواست ناممکن در محدوده را قابل قبول نمی‌کند.

ورودی‌ها همان قالب‌های مطلق و سخت‌گیرانه برنامه‌ریز مستقیم را دارند: زمان بدون اختلاف ساعت با فاصله و قالب `Y-m-d H:i[:s[.microseconds]]` در منطقه زمانی برنامه تفسیر می‌شود؛ timestamp با `T` باید `Z` یا اختلاف صریح `±HH:MM` داشته باشد. کسر ثانیه به ثانیه نیاز دارد و ۱ تا ۶ رقم می‌پذیرد. ورودی فقط تاریخ، نسبی، تاریخ نامعتبر، ساعت محلی ناموجود و زمان مبهمِ بدون اختلاف ساعت هنگام تکرار ساعت رد می‌شوند. اختلاف ساعت صریح، رخداد مورد نظر از ساعت تکراری را مشخص می‌کند. ورودی `DateTimeInterface` لحظه خود را حفظ می‌کند و تغییر نمی‌کند. زمان‌های جدید نتیجه، UTC و تغییرناپذیرند و ثانیه و میکروثانیه را مطابق حساب فعلی مراحل حفظ می‌کنند.

### قواعد مراحل، تقویم و مهلت سفارش

| مرحله | شرط معکوس مطابق رفتار فعلی محاسبه مستقیم |
|---|---|
| `work` | آخرین دریافتی را پیدا می‌کند که شروع کاری تنظیم‌شده و دقیقه‌های کاری آن به‌موقع تمام شوند. ناهار، نیمه‌روز، تعطیلی، روز کاری اضافه، ساعت اختصاصی تاریخ، برنامه فصلی و ادامه مجاز شیفت شبانه اعمال می‌شوند. صفر دقیقه هم شروع را به اولین لحظه کاری بعدی منتقل می‌کند. |
| `handoff` | هر دو پروفایل باید در یک لحظه واقعی و بر اساس منطقه زمانی مستقل خود باز باشند. شروع مشترک مجاز است؛ پایان مشترک طبق `[start, end)` مجاز نیست. هم‌پوشانی معتبر بعد از نیمه‌شب هم پشتیبانی می‌شود. |
| `business_days` | برچسب تاریخ‌های کاریِ اکیداً بعدی را در منطقه زمانی پروفایل می‌شمارد و ساعت محلی، ثانیه و میکروثانیه را در صورت معتبر بودن حفظ می‌کند. صفر روز همانی است؛ ساعت کاری و `include_start_date` این مرحله را تغییر نمی‌دهند. تاریخ حذف‌شده شمرده نمی‌شود و ساعت مقصد ناموجود بی‌صدا جابه‌جا نمی‌شود. |

مرحله کار می‌تواند دقیقاً هنگام بسته‌شدن تمام شود. آن لحظه خودبه‌خود شروع معتبر کار یا handoff بعدی نیست. حل‌کننده، حساب دقیقه کامل مسیر قدیمی و دقت زمان کاری مسیر توسعه‌یافته را حفظ می‌کند؛ کم‌کردن مدت اسمی از موعد جای اجرای مرحله مستقیم را نمی‌گیرد. به‌خصوص قاعده گردکردن تازه‌ای برای رساندن زمان دریافت به مرز دقیقه اعمال نمی‌شود.

حساب ظرفیت مسیر قدیمی، پیش از شمردن دقیقه‌های کامل از timestamp با ثانیه صحیح استفاده می‌کند. بنابراین آخرین دریافت قابل قبول آن ممکن است کسری از ثانیه را نگه دارد که برنامه توسعه‌یافته آن را مصرف می‌کند: نمونه قدیمی ناهار برای موعد 14:00، دریافت `11:00:00.999999` را می‌پذیرد، ولی همان ساعت محلی در مسیر توسعه‌یافته `11:00:00.000000` می‌دهد. با تعطیلی دوشنبه، نمونه ساده قدیمی دریافت جمعه `15:00:00.999999` را می‌پذیرد و در `17:00:00.999999` تمام می‌شود. این نتیجه‌ها همان محاسبه مستقیم فعلی، از جمله رفتار کسر ثانیه در مرز بسته‌شدن را حفظ می‌کنند؛ API معکوس آن‌ها را اصلاح یا گرد نمی‌کند.

گزینه اختیاری `cutoff_profile` دقیقاً همان معنای فازهای ۰۱ و ۰۲ را دارد: پروفایل اولین مرحله work را مشخص می‌کند و منطقه زمانی سیاست آن باید با نگاشت همان پروفایل یکی باشد. با حذف این گزینه، مسیریابی cutoff انجام نمی‌شود. مرز انحصاری، بازنویسی روز هفته، روز غیرفعال، اولویت تعطیلی و روز کاری اضافه، و بازه معتبر دریافت در تاریخ آینده همچنان اعمال می‌شوند. دریافت روی مهلت یا پس از آن می‌تواند قابل قبول باشد، اگر انتقال پردازش به تاریخ کاری واجد شرایط بعدی پایان به‌موقع بدهد. مبدأ نیمه‌شب آن تاریخ برای برنامه‌ریزی، از شروع واقعی اولین کار جداست.

با cutoff ساعت 14:00، موعد دوشنبه 17:00 می‌تواند آخرین دریافت `13:59:59.999999` بدهد؛ گردکردن رو به بالا پردازش را به تاریخ دیگری می‌فرستد. برای موعد سه‌شنبه 11:00 و ۱۲۰ دقیقه آماده‌سازی، سه‌شنبه 09:00 آخرین دریافت است و دریافت دوشنبه 14:00 هم از راه پردازش سه‌شنبه قابل قبول است. دریافت‌های پس از cutoff به‌صورت کلی رد نمی‌شوند.

### تغییر ساعت، دقت و محدودیت‌های عملی

آخرین دریافت، بزرگ‌ترین میکروثانیه قابل قبول در محدوده جست‌وجوست و خود موعد تحویل هم مجاز است. حل‌کننده بازه‌های دریافت را در مرزهای مرتبط تقویم، cutoff و منطقه زمانی تفکیک می‌کند و برای این کار، بخش ابتدایی برنامه مستقیم واقعی را با دریافت اصلی و افق مشترک اجرا می‌کند. جست‌وجوی دقیق فقط در بخش‌هایی انجام می‌شود که یکنواختی آن‌ها برقرار است و پیش از انتخاب آخرین دریافت، بخش‌های دیرتر کنار گذاشته می‌شوند. حفظ ساعت محلی در `business_days` می‌تواند هنگام تکرار ساعت باعث شود دریافت دیرتر در UTC، پایان زودتری بدهد؛ بنابراین فرض یکنواختی سراسری جست‌وجو معتبر نیست. یک محاسبه مستقیم کامل و تازه، دریافت انتخاب‌شده را تأیید می‌کند و در نتیجه نگه داشته می‌شود. بررسی فقط میکروثانیه بعدی ثابت نمی‌کند که بخش قابل قبولِ دیرتری وجود ندارد.

جست‌وجو محدود است و به‌جای پیمایش تک‌تک دقیقه‌ها یا میکروثانیه‌ها، بازه‌های تقویمی را بررسی می‌کند. تنظیم مؤثر هر پروفایل در هر عملیات یک بار محاسبه می‌شود. calculatorها با کلید پروفایل و حد واقعی پیمایش در همان عملیات نگه داشته می‌شوند و provider تعطیلی مشترک دارند؛ تفاوت منطقه زمانی پروفایل‌ها ممکن است نمونه دیگری از calculator بخواهد، بدون ساخت دوباره provider برای هر ارزیابی. API کش پایدار یا سراسری و نوشتن در دیتابیس اضافه نمی‌کند. اگر محاسبه معکوس استفاده نشود، برنامه‌ریزی مستقیم عادی تغییر نمی‌کند. هزینه اجرا همچنان به تعداد مراحل، محدوده تاریخ، پنجره‌های کاری و ناپیوستگی‌های تقویم و منطقه زمانی بستگی دارد؛ این عملیات جست‌وجوی ظرفیت یا دسترسی لحظه‌ای حمل‌کننده نیست. اگر پاسخ دقیق قابل اثبات نباشد، به‌جای برآورد تقریبی خطا داده می‌شود.

هر عملیات سقف ایمنی ۶۵٬۵۳۶ ارزیابیِ بخش ابتدایی برنامه مستقیم و ۳۲٬۷۶۸ بخش تقویمی دارد؛ رسیدن به هرکدام `RuntimeException` می‌دهد. اثبات دقیق معکوس همچنین پنجره قدیمی‌ای را رد می‌کند که حساب فعلیِ زمان سپری‌شده از نیمه‌شب، آن را از تاریخ مدنی دریافت‌کننده خارج کند؛ برنامه قدیمی با نیمه‌شب مبهم و تغییر منطقه زمانی که در محدوده بررسی از مرز تاریخ مدنی به عقب برگردد هم رد می‌شوند. پنجره قدیمی عادی مانند 00:00–04:00 در تاریخ تغییر ساعت، تا وقتی در همان تاریخ بماند پشتیبانی می‌شود. برنامه صریحِ توسعه‌یافته با ساعت محلی می‌تواند محدودیت خروج مسیر قدیمی از تاریخ را برطرف کند، به شرط رعایت همان قواعد سخت‌گیرانه مرزها؛ هیچ تقویمی بی‌صدا تبدیل یا اصلاح نمی‌شود.

### نتیجه و تبدیل به آرایه

| ویژگی | معنی |
|---|---|
| `timezone` | منطقه زمانی برنامه برای برچسب تاریخ‌های گذشته و افق مستقیم. |
| `targetCompletionAt` | موعد شامل‌شونده خواسته‌شده، UTC و تغییرناپذیر. |
| `searchStartAt` | اولین لحظه مجاز دریافت، UTC و تغییرناپذیر. |
| `feasible` | آیا دریافتی در محدوده، با قواعد مستقیم به‌موقع تمام می‌شود. |
| `latestOrderAt` | آخرین زمان قابل قبول دریافت سفارش، یا `null`. |
| `planningStartAt` | نشانگر اولیه پس از مسیریابی اختیاری cutoff، یا `null`. |
| `firstStageStartsAt` | شروع واقعی اولین مرحله تعریف‌شده، یا `null`. |
| `estimatedCompletionAt` | پایان نهایی تأییدشده با محاسبه مستقیم، یا `null`. |
| `slackMicroseconds` | اختلاف صحیح نامنفی بین موعد و پایان، بر حسب میکروثانیه، یا `null`. |
| `forwardResult` | شیء تغییرناپذیر و تأییدشده `DeliveryPlanResult`، یا `null`؛ فیلد `stages` آن خط زمانی مراحل را دارد. |

متد `toArray()` کلیدهای متناظر ثابت با قالب snake_case، بولی و عدد صحیح واقعی، و زمان‌های UTC با قالب `Y-m-d\TH:i:s.uP`، شش رقم میکروثانیه و اختلاف صریح `+00:00` می‌دهد. نتیجه مستقیم توکار از `toArray()` فعلی خودش استفاده می‌کند؛ از جمله خروجی محلی سیاست cutoff. وقتی `feasible === false` باشد، هر شش فیلد پاسخ از `latestOrderAt` تا `forwardResult` برابر null هستند؛ منطقه زمانی، موعد و کران جست‌وجو همچنان موجودند. نتیجه ناممکن، زمان دریافت جایگزینِ خارج از محدوده ندارد.

| خطا | علت معمول |
|---|---|
| `Zarbinco\LaravelWorkdays\Exceptions\WorkdayConfigurationException` | گزینه، برنامه یا پروفایل نامعتبر، کلید یا پروفایل ناشناخته، نبود ساعت کاری یا نبودن/ناسازگاری cutoff درخواست‌شده. |
| `InvalidArgumentException` | موعد نامعتبر یا پشتیبانی‌نشده؛ از جمله ساعت محلی ناموجود یا زمان مبهم بدون اختلاف ساعت. |
| `RuntimeException` | محدودیت جست‌وجو یا نمایش زمان، خطای داخلی حل‌کننده یا ناتوانی در اثبات آخرین دریافت دقیق. خطای غیرمنتظره provider منتقل می‌شود و به معنی ناممکن بودن عادی نیست. |

تقویم معتبر بدون زمان کاری قابل استفاده، نبود هم‌پوشانی handoff یا نامزدهایی که در افق مستقیم تمام نمی‌شوند می‌توانند `feasible === false` بدهند. تنظیم نامعتبر و خطای حل‌کننده هرگز به‌جای تقویم بسته نمایش داده نمی‌شوند.

### اتصال به برنامه و فازهای قبلی

storageهای config، database و chain از همان ورودی تعطیلی و اولویت فعلی استفاده می‌کنند. وراثت فاز ۰۵، آخر هفته، ساعت کاری، برنامه اختصاصی/فصلی/شبانه، cutoff و تنظیم تعطیلی مؤثر را می‌دهد؛ ردیف‌های دیتابیس همچنان به پروفایل فرزند انتخاب‌شده مربوط‌اند و خودکار از والد ارث نمی‌رسند. نام‌های صریح پروفایل در برنامه، مسیریابی context را فعال نمی‌کنند.

برنامه موعد مورد نظر checkout را انتخاب می‌کند و تصمیم می‌گیرد `latestOrderAt` هنوز قابل استفاده هست یا نه؛ ممکن است از حالا گذشته باشد. محاسبه معکوس، امکان زمانی بر اساس تقویم را مشخص می‌کند. موجودی را تضمین نمی‌کند، ظرفیت حمل‌کننده را تأیید نمی‌کند، بازه تحویل یا جمع‌آوری رزرو نمی‌کند، سفارش ثبت نمی‌کند و رسیدن فیزیکی کالا را تضمین نمی‌کند. پیش از پذیرش تعهد مشتری، قواعد موجودی، پرداخت، ظرفیت و رزرو را در برنامه خودت دوباره بررسی کن. تغییر تقویم می‌تواند محاسبه قبلی را نامعتبر کند.

برای برآورد مستقیم فعلی، `planDelivery($reverse->latestOrderAt, $reversePlan)` را دوباره اجرا کن. فاز ۰۶ می‌تواند جداگانه با همان دریافت و برنامه، تعهد مستقیم را ثبت و بررسی کند؛ نتیجه معکوس خودکار ذخیره یا به ساختار snapshot آن اضافه نمی‌شود. رفتار SLA و توقف/ادامه فاز ۰۷ جدا و بدون تغییر می‌ماند. این API سفارش را تغییر نمی‌دهد، اعلان نمی‌فرستد و پایش بعدی زمان‌بندی نمی‌کند.

### سیزده بررسی دستی محلی

نمونه بالا را ادامه بده. این فراخوانی‌ها از تاریخ ثابت و فقط پروفایل‌های اختصاصی config استفاده می‌کنند.

```php
$reverseSimplePlan = $reverseWork('reverse_base');
$reverseSimple = Workday::reversePlanDelivery(
    '2026-11-02T16:00:00Z', $reverseSimplePlan, ['lookback_days' => 14],
); // latest: November 2, 14:00 UTC
$reverseLunch = Workday::reversePlanDelivery(
    '2026-11-02T14:00:00Z', $reverseWork('reverse_split'), ['lookback_days' => 14],
); // latest: November 2, 11:00:00.999999 UTC (preserved legacy accounting)

$reverseHandoffPlan = [
    'timezone' => 'UTC',
    'profile_timezones' => ['reverse_base' => 'UTC', 'reverse_carrier' => 'UTC'],
    'stages' => [
        ['id' => 'pickup', 'type' => 'handoff', 'profiles' => ['reverse_base', 'reverse_carrier']],
    ],
];
$reverseOpening = Workday::reversePlanDelivery(
    '2026-11-02T12:00:00Z', $reverseHandoffPlan, ['lookback_days' => 14],
); // latest: 12:00 UTC
$reverseClosing = Workday::reversePlanDelivery(
    '2026-11-02T17:00:00Z', $reverseHandoffPlan, ['lookback_days' => 14],
); // latest: 16:59:59.999999 UTC
$reverseTransitPlan = [
    'timezone' => 'UTC',
    'profile_timezones' => ['reverse_carrier' => 'UTC'],
    'stages' => [
        ['id' => 'transit', 'type' => 'business_days', 'profile' => 'reverse_carrier', 'days' => 2],
    ],
];
$reverseTransit = Workday::reversePlanDelivery(
    '2026-11-04T15:30:00Z', $reverseTransitPlan, ['lookback_days' => 14],
); // latest: November 2, 15:30 UTC
$reverseCutoffPlan = $reverseWork('reverse_warehouse', 120, true);
$reverseCutoff = Workday::reversePlanDelivery(
    '2026-11-02T17:00:00Z', $reverseCutoffPlan, ['lookback_days' => 14],
); // latest: November 2, 13:59:59.999999 UTC
$reverseNextDay = Workday::reversePlanDelivery(
    '2026-11-03T11:00:00Z', $reverseCutoffPlan, ['lookback_days' => 14],
); // latest: November 3, 09:00 UTC
$reverseAfterCutoff = Workday::planDelivery('2026-11-02T14:00:00Z', $reverseCutoffPlan);
// planning start: November 3, 00:00; preparation: 09:00–11:00 UTC

config(['workdays.profiles.reverse_base.custom_holidays' => ['2026-11-02' => 'Test closure']]);
$reverseHoliday = Workday::reversePlanDelivery(
    '2026-11-02T16:00:00Z', $reverseSimplePlan, ['lookback_days' => 14],
); // latest receipt moves earlier
$reverseImpossible = Workday::reversePlanDelivery(
    '2026-11-02T16:00:00Z', $reverseSimplePlan, ['lookback_days' => 1],
); // feasible: false; all solution fields: null
config(['workdays.profiles.reverse_base.custom_holidays' => []]);
$reverseOvernight = Workday::reversePlanDelivery(
    '2026-11-03T02:00:00Z', $reverseWork('reverse_night', 180), ['lookback_days' => 14],
); // latest: November 2, 23:00 UTC

$reverseForward = Workday::planDelivery($reverse->latestOrderAt, $reversePlan);
$reverseForward->estimatedCompletionAt->lessThanOrEqualTo($reverse->targetCompletionAt); // true
$reverseForward->toArray() === $reverse->forwardResult->toArray(); // true

$reverseFoldPlan = [
    'timezone' => 'America/New_York',
    'profile_timezones' => ['reverse_new_york' => 'America/New_York'],
    'stages' => [
        ['id' => 'transit', 'type' => 'business_days', 'profile' => 'reverse_new_york', 'days' => 1],
    ],
];
$reverseFold = Workday::reversePlanDelivery(
    '2026-11-02T01:15:00-05:00', $reverseFoldPlan, ['lookback_days' => 2],
); // latest: 2026-11-01T06:15:00.000000+00:00, the later 01:15 at -05:00

config(['workdays' => $reverseOriginalConfig]); // Restore the local test configuration.
```

| بررسی | نتیجه مورد انتظار |
|---|---|
| ۱ | انبار ساده اختصاصی، دوشنبه تا جمعه ساعت 09:00–17:00 UTC باز است. |
| ۲ | برنامه `$reverseSimplePlan` دقیقاً ۱۲۰ دقیقه آماده‌سازی می‌خواهد. |
| ۳ | مقدار `$reverseSimple->targetCompletionAt` برابر ۲ نوامبر ساعت 16:00 UTC است. |
| ۴ | آخرین دریافت آن 14:00 است و پایان به‌موقع 16:00 دارد. |
| ۵ | برنامه قدیمی ناهار، برای موعد 14:00 دریافت `11:00:00.999999` می‌دهد و حساب فعلی کسر ثانیه را حفظ می‌کند. |
| ۶ | handoff در شروع مشترک 12:00 مجاز است و مرز بسته‌شدن 17:00، دریافت `16:59:59.999999` می‌دهد. |
| ۷ | دو تاریخ کاری حمل‌کننده، برای موعد چهارشنبه 15:30 دریافت دوشنبه 15:30 می‌دهد؛ هر چهار مرحله `$reverse` را هم بررسی کن. |
| ۸ | cutoff همان روز انحصاری است؛ `$reverseAfterCutoff` و `$reverseNextDay` مسیریابی معتبر روز بعد را نشان می‌دهند. |
| ۹ | تعطیلی مرتبط، نتیجه را عقب‌تر می‌برد؛ با lookback یک‌روزه، تقویم معتبر بسته نتیجه ناممکن می‌دهد. |
| ۱۰ | نتیجه شیفت شبانه دوشنبه 23:00 است و سه ساعت را بدون شمارش تکراری از نیمه‌شب عبور می‌دهد. |
| ۱۱ | برنامه‌ریز مستقیم اصلی، زمان دریافت برگشتی و برنامه بدون تغییر را می‌پذیرد. |
| ۱۲ | نتیجه کامل آن با نتیجه مستقیم توکار برابر است و بعد از موعد شامل‌شونده تمام نمی‌شود. نمونه تکرار ساعت، رخداد دیرتر را انتخاب می‌کند. |
| ۱۳ | فقط تنظیم محلی و محاسبه انجام شده است: نوشتن در دیتابیس، تغییر سفارش، رزرو، اعلان یا job زمان‌بندی‌شده ایجاد نشده است. پس از بررسی تنظیمات را برگردان. |

## نسخه تقویم و snapshot برآورد تحویل

متد `captureDeliveryPromise()` برآورد تحویل را همراه با نمای محدود و تغییرناپذیری از قواعد تقویمیِ استفاده‌شده ثبت می‌کند. متد `compareDeliveryPromise()` بعداً همان ورودی و برنامه را با قواعد فعلی محاسبه می‌کند و نتیجه اصلی را حفظ می‌کند. فروشگاه می‌تواند تعهد اولیه به مشتری را جدا از برآورد عملیاتی متغیر نگه دارد و خودش درباره اقدام مناسب تصمیم بگیرد.

این APIها روی `WorkdayManager` و facade اختیاری‌اند. خودشان snapshot ذخیره نمی‌کنند، سفارش یا مرسوله را تغییر نمی‌دهند، ظرفیت رزرو نمی‌کنند، به مشتری اعلان نمی‌فرستند و مقایسه بعدی زمان‌بندی نمی‌کنند. ذخیره‌سازی، مجوز دسترسی، یافتن سفارش‌های متأثر، تصمیم درباره تغییر تعهد و ارتباط با مشتری، مسئولیت برنامه است. خروجی فعلی `planDelivery()` و قرارداد عمومی فازهای ۰۱ تا ۰۵ تغییر نمی‌کند.

### نمونه کامل ثبت و مقایسه محلی

این نمونه جداگانه و فقط مبتنی بر config را در نشست محلی `php artisan tinker` اجرا کن. انبار تقویم، ساعت کاری و cutoff با منطقه زمانی UTC را از والد می‌گیرد. برنامه پس از یک روز کاری حمل‌کننده تمام می‌شود؛ آخرین مرحله، برآورد را تعیین می‌کند و اثبات تحویل فیزیکی نیست.

```php
use Zarbinco\LaravelWorkdays\Data\DeliveryPromiseSnapshot;
use Zarbinco\LaravelWorkdays\Facades\Workday;

$promiseOriginalConfig = config('workdays');
$promiseWeekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
$promiseProfiles = [
    'promise_base' => [
        'weekends' => ['Saturday', 'Sunday'],
        'holidays' => ['gregorian' => [], 'jalali' => [], 'hijri' => []],
        'custom_holidays' => [],
        'extra_working_days' => [],
        'working_hours' => array_fill_keys($promiseWeekdays, [['09:00', '17:00']]),
        'cutoff' => ['timezone' => 'UTC', 'time' => '14:00'],
    ],
    'promise_warehouse' => ['extends' => 'promise_base'],
    'promise_carrier' => ['extends' => 'promise_base'],
];
config([
    'workdays.storage.driver' => 'config',
    'workdays.max_scan_days' => 31,
    'workdays.profiles' => array_replace(config('workdays.profiles', []), $promiseProfiles),
]);

$deliveryPlan = [
    'timezone' => 'UTC',
    'profile_timezones' => ['promise_warehouse' => 'UTC', 'promise_carrier' => 'UTC'],
    'cutoff_profile' => 'promise_warehouse',
    'stages' => [
        ['id' => 'preparation', 'type' => 'work', 'profile' => 'promise_warehouse', 'minutes' => 120],
        ['id' => 'transit', 'type' => 'business_days', 'profile' => 'promise_carrier', 'days' => 1],
    ],
];
$snapshot = Workday::captureDeliveryPromise(
    '2026-11-02T10:00:00Z',
    $deliveryPlan,
    ['horizon_days' => 14],
);
$snapshot->calendarVersion;
$snapshot->originalResult->estimatedCompletionAt; // November 3, 12:00 UTC.

// Persist this JSON using the application's own storage when needed.
$payload = $snapshot->toArray();
$storedJson = json_encode($payload, JSON_THROW_ON_ERROR);
$restored = DeliveryPromiseSnapshot::fromArray(
    json_decode($storedJson, true, 32, JSON_THROW_ON_ERROR),
);
$unchanged = Workday::compareDeliveryPromise($restored);
// Also accepts $snapshot directly, or the serialized $payload array.
// versionChanged=false, estimateChanged=false, direction=unchanged, deltaSeconds=0.
```

ثبت snapshot ابتدا تنظیمات مؤثر پس از ارث‌بری و ورودی‌های provider تعطیلی را آماده می‌کند. هم برنامه اصلی و هم شواهد تاریخ‌ها با همان نمای ثابت در حافظه و با استفاده از planner، cutoff و موتور ساعت کاری موجود محاسبه می‌شوند. خواندن دیرتر تقویم به یک برآورد قبلی با قواعد زنده ضمیمه نمی‌شود.

برای دیدن اثر تغییر، فقط همین تنظیمات آزمایشی را عوض کن:

```php
config([
    'workdays.profiles.promise_carrier.custom_holidays' => ['2026-11-03' => 'Test holiday'],
]);
$later = Workday::compareDeliveryPromise($payload);
// Current completion: November 4, 12:00 UTC; direction=later; deltaSeconds=86400.
// $snapshot->originalResult still completes on November 3 at 12:00 UTC.

config([
    'workdays.profiles.promise_carrier.custom_holidays' => [],
    'workdays.profiles.promise_base.special_working_hours' => [
        '2026-11-02' => [['09:00', '11:00']],
    ],
]);
$changedHours = Workday::compareDeliveryPromise($payload);
// Preparation now finishes November 3 at 10:00; transit completes November 4 at 10:00.
$changedHours->changedCalendarDays;

config([
    'workdays.profiles.promise_base.working_hours' => [],
    'workdays.profiles.promise_base.special_working_hours' => [],
]);
$unresolvable = Workday::compareDeliveryPromise($payload);
// status/direction=unresolvable; current estimate, estimateChanged and deltaSeconds are null.
// The original result remains available; inspect $unresolvable->error for the constraint.
```

### افق، پروفایل‌ها و تاریخ‌های ثبت‌شده

ثبت snapshot به `['horizon_days' => N]` نیاز دارد؛ مقدار باید عدد صحیح واقعی PHP از `1` تا `31` باشد و از `workdays.max_scan_days` بیشتر نشود. کلید گزینه دیگری پشتیبانی نمی‌شود. رشته عددی، اعشاری، صفر، منفی و مقدار خارج از محدوده نامعتبر است. حداکثر هشت پروفایل متمایز ارجاع‌شده مجاز است. این محدودیت‌ها فقط برای ثبت/مقایسه هستند و پیش‌فرض‌های موجود فاز ۰۲ را عوض نمی‌کنند.

روز صفر، تاریخ لحظه دریافت در منطقه زمانی برنامه است. مقدار `horizon_days = 14` همان تاریخ و چهارده تاریخ تقویمی آینده، تا روز چهاردهم شامل مرز را مجاز می‌کند؛ این با شمارش چهارده تاریخ در فاز ۰۳ فرق دارد. همه مراحل یک افق مشترک دارند. ثبت و مقایسه افق را تمدید نمی‌کنند و برای هر مرحله از نو شروع نمی‌کنند. مقایسه از افق اصلی استفاده می‌کند و همچنان تابع سقف ایمنی تنظیم‌شده است.

شواهد برای تفاوت منطقه زمانی پروفایل‌ها و مالکیت شیفت شبانه، تاریخ‌های همسایه مشخصی را هم در بر می‌گیرد. لحظه نیمه‌شب روز صفر برنامه و لحظه نیمه‌شب انتهای انحصاری افق را به منطقه زمانی هر پروفایل تبدیل کن. محدوده مشاهده از دو برچسب تاریخ پیش از تاریخ اول تا دو برچسب پس از تاریخ دوم، با احتساب هر دو انتهاست. ورودی provider علاوه بر آن، یک تاریخ مالک شیفت پیش از شروع این محدوده را هم دارد. این حاشیه، افق مجاز برنامه را بزرگ‌تر نمی‌کند.

در نمونه‌ای که همه مناطق زمانی UTC هستند، پایان برنامه تا ۱۶ نوامبر ۲۰۲۶ مجاز است. شواهد مشاهده‌شده از ۳۱ اکتبر تا ۱۹ نوامبر شامل هر دو انتهاست؛ یعنی روز منفی ۲ تا روز ۱۷ و بیست تاریخ برای هر پروفایل. ورودی تاریخ‌دار provider، ۳۰ اکتبر را هم شامل می‌شود. افق ۳۱روزه با منطقه زمانی یکسان، ۳۷ تاریخ مشاهده‌شده برای هر پروفایل دارد. استثناهای تاریخ‌دار و فصل‌ها به محدوده provider محدود می‌شوند؛ نگاشت فعال تعطیلی سالانه تکرارشونده پروفایل‌های انتخابی حفظ می‌شود. تاریخ محلی کاملاً حذف‌شده، زمان کاری ساختگی تولید نمی‌کند.

کل محدوده Gregorian با حاشیه‌ها باید در سال‌های `0001` تا `9999` جا بگیرد؛ ثبت snapshot اختلاف ساعت تاریخی cutoff دارای ثانیه را هم رد می‌کند، چون زمان‌های cutoff داخلی اختلاف ساعت با قالب `HH:MM` دارند.

### ساختار قابل ذخیره و بررسی یکپارچگی

کلاس `Zarbinco\LaravelWorkdays\Data\DeliveryPromiseSnapshot` تغییرناپذیر است و پس از تبدیل معمول به JSON و بازخوانی، با `fromArray()` بازسازی می‌شود.

| ویژگی | کلید خروجی آرایه | معنی |
|---|---|---|
| `schemaVersion` | `schema_version` | نسخه قالب snapshot؛ در ابتدا عدد صحیح `1`. |
| `calendarVersion` | `calendar_version` | اثر انگشت محتوای تقویم با SHA-256 قطعی. |
| `receivedAt` | `received_at` | لحظه دریافت اصلی، تغییرناپذیر و در UTC. |
| `plan` | `plan` | برنامه معتبر اصلی، با نام واقعی پروفایل‌های انتخاب‌شده. |
| `horizonDays` | `horizon_days` | افق صریح اصلی برای تاریخ‌های آینده. |
| `originalResult` | `original_result` | `DeliveryPlanResult` کامل اصلی، شامل مراحل مرتب و نتیجه اختیاری cutoff. |
| `calendarEvidence` | `calendar_evidence` | تنظیم مؤثر، ورودی provider، محدوده و تصمیم‌های مشاهده‌شده هر تاریخ، به‌صورت محدود. |
| `runtimeMetadata` | `runtime_metadata` | نسخه‌های در دسترس محیط اجرا/پیاده‌سازی و اطلاعات مرتبط تقویم. |
| checksum یکپارچگی | `integrity_hash` | checksum جداگانه برای کل داده ذخیره‌شده، فراتر از نسخه تقویم. |

فیلدهای زمانی جدید در UTC، با شش رقم میکروثانیه و اختلاف ساعت صریح `+00:00` نوشته می‌شوند. قرارداد نتیجه‌های داخلی قبلی حفظ می‌شود؛ از جمله اختلاف ساعت محلی سیاست در نتیجه cutoff. برچسب تاریخ Gregorian با قالب `Y-m-d` است. شواهد، هویت پروفایل/تاریخ، تصمیم روز کاری/تعطیلی/روز کاری جایگزین، پنجره مؤثر در صورت نیاز، و علت/منبع تعطیلی را ثبت می‌کند. پروفایلی که فقط برای حمل برحسب روز کاری استفاده می‌شود، صرفاً برای ثبت شواهد به ساعت کاری نیاز ندارد.

اعتبارسنجی، بازنمایی JSON کانونی را به **۲ MiB، برابر ۲٬۰۹۷٬۱۵۲ بایت**، عمق **۳۲** و **۱۰۰٬۰۰۰ گره** محدود می‌کند. نسخه ساختار، فیلدها، نوع‌ها، تاریخ، زمان، مراحل، پروفایل‌ها، شواهد و checksumها بررسی می‌شوند. نبود فیلد الزامی، نسخه پشتیبانی‌نشده، داده بدشکل یا بیش از حد بزرگ، شیء پشتیبانی‌نشده و ناهماهنگی اثر انگشت، ورودی نامعتبرند و مقایسه موفق تغییر تقویم محسوب نمی‌شوند. مقدارهای JSON نوع عددی/بولی خود را حفظ می‌کنند؛ داده ورودی با `unserialize()` ناامن PHP بازسازی یا به‌عنوان کد اجرا نمی‌شود.

تفاوت تاریخ‌ها در مقایسه، ردیف کامل قبلی/فعلی را از دو نمای محدود تقویم حفظ می‌کند. این اطلاعات ترکیبی، سقف خروجی جداگانه **۵ MiB، برابر ۵٬۲۴۲٬۸۸۰ بایت**، عمق **۳۲** و **۲۱۰٬۰۰۰ گره** دارد. محدودیت ورودی snapshot همان مقدارهای بالاست.

ساختار و checksum در همه حالت‌ها بررسی می‌شوند. بررسی تکمیلی سازگاری، قواعد/نتیجه ثبت‌شده را وقتی دوباره اجرا می‌کند که اطلاعات محیط اجرا یکسان باشد و `max_scan_days` فعلی افق اصلی را پوشش بدهد. محیط اجرای متفاوت یا سقف ایمنی کاهش‌یافته، محیط تاریخی را بازسازی نمی‌کند؛ اصل ذخیره‌شده در دسترس می‌ماند و محاسبه فعلی نتیجه خودش را گزارش می‌کند.

بررسی دقیق مبنای تاریخ، پنجره و افق محلی به یکسان‌بودن نسخه دیتابیس مناطق زمانی نیاز دارد؛ در نسخه متفاوت، بررسی محدود ساختار/یکپارچگی حفظ می‌شود و `runtimeChanged` محاسبه با دیتابیس زمانی فعلی را مشخص می‌کند، اما بازاجرای محلی تاریخی اثبات نمی‌شود.

اثر انگشت تقویم و checksum کل داده، بررسی قطعی یکپارچگی را فراهم می‌کنند. امضا یا احراز اصالت در برابر کسی که می‌تواند هم داده و هم hashها را بازنویسی کند نیستند و انطباق با مقررات را ثابت نمی‌کنند. snapshot ذخیره‌شده را با مجوزها و سیاست ذخیره‌سازی برنامه محافظت کن. شناسه مشتری، نشانی، تلفن، شناسه سفارش و زمینه مسیریابی خودکار اضافه نمی‌شوند؛ در شناسه مراحل برنامه هم اطلاعات خصوصی قرار نده.

### معنی نسخه تقویم

نسخه تقویم اثر انگشت محتواست، نه شماره ترتیبی بازبینی دیتابیس یا زمان ثبت. JSON کانونی، کلید نگاشت‌های انجمنی را بازگشتی مرتب می‌کند، ترتیب معنادار فهرست و نوع مقدار را حفظ می‌کند، null صریح معنادار را نگه می‌دارد و تاریخ، لحظه و نام روز هفته پشتیبانی‌شده را نرمال می‌کند. پیشوند ثابت ساختار/حوزه، ورودی hash را از کاربردهای دیگر SHA-256 جدا می‌کند.

نسخه شامل تنظیمات مؤثر مرتبط پس از ارث‌بری، ورودی provider تقویم، منطقه زمانی و محدوده پروفایل، cutoff مربوط، برنامه عادی/اختصاصی/فصلی/شبانه، تنظیم محاسبه هجری و تصمیم‌های مشاهده‌شده تقویم است. تغییر ترتیب درج نگاشت تعطیلی بدون تغییر معنی، یا شناسه ردیف دیتابیس نامرتبط، آن را تغییر نمی‌دهد. زمان ثبت، شناسه تصادفی، هویت شیء، تنظیم نامرتبط و پروفایل ارجاع‌نشده در نسخه دخالت ندارند.

ویژگی‌های `versionChanged` و `estimateChanged` دو سؤال متفاوت را جواب می‌دهند. تغییر مرتبط تقویم پس از پایان برنامه می‌تواند نسخه را عوض کند، در حالی که برآورد یکسان بماند. تغییر محیط اجرا، کتابخانه تقویمی یا دیتابیس مناطق زمانی هم جداگانه گزارش می‌شود و می‌تواند بر تفسیر اثر بگذارد. نسخه‌های در دسترس PHP، کتابخانه تقویم، دیتابیس timezone و ساختار محاسبه ثبت می‌شوند؛ نبود نسخه صریح است و عددی ساخته نمی‌شود. نتیجه ذخیره‌شده مدرک بررسی است، نه تضمین بازتولید یکسان در محیط‌های اجرای متفاوت یا بازگردانی تاریخی دیتابیس.

### نتیجه مقایسه و تفاوت‌های مشاهده‌شده

کلاس `Zarbinco\LaravelWorkdays\Data\DeliveryPromiseComparison` تغییرناپذیر است. محاسبه مجدد موفق، `status = resolved` و جهت `unchanged`، `earlier` یا `later` دارد؛ ناتوانی در محاسبه با محدودیت‌های فعلی، `status = unresolvable` و جهت `unresolvable` می‌دهد.

| ویژگی / فیلد خروجی آرایه | معنی |
|---|---|
| `originalCalendarVersion` / `original_calendar_version` | اثر انگشت تقویم اصلی ذخیره‌شده. |
| `currentCalendarVersion` / `current_calendar_version` | اثر انگشت فعلی، وقتی بتوان شواهد فعلی را ساخت. |
| `versionChanged` / `version_changed` | تفاوت محتوای تقویم فعلی و اصلی، در صورت قابل مقایسه بودن. |
| `estimateChanged` / `estimate_changed` | تفاوت لحظه پایان؛ در حالت unresolvable برابر `null`. |
| `direction` / `direction` | `unchanged`، `earlier`، `later` یا `unresolvable`. |
| `originalEstimatedCompletionAt` / `original_estimated_completion_at` | لحظه پایان اصلی از نوع UTC تغییرناپذیر. |
| `currentEstimatedCompletionAt` / `current_estimated_completion_at` | پایان محاسبه مجدد در UTC تغییرناپذیر؛ در حالت unresolvable، `null`. |
| `deltaSeconds` / `delta_seconds` | پایان فعلی منهای اصلی؛ مثبت یعنی دیرتر، منفی زودتر، صفر بدون تغییر؛ اختلاف میکروثانیه می‌تواند ثانیه کسری بدهد؛ در حالت unresolvable، `null`. |
| `changedCalendarDays` / `changed_calendar_days` | تفاوت‌های محدود مشاهده‌شده، مرتب برحسب پروفایل و تاریخ، با `profile`، `date` و `changed_fields`. |
| `runtimeChanged` / `runtime_changed` | تفاوت اطلاعات ثبت‌شده محیط اجرا/الگوریتم. |
| `configurationChanged` / `configuration_changed` | نشانگر تکمیلی بولی یا null برای تنظیم مؤثر، تنظیم محاسبه هجری و ورودی مؤثر تعطیلی با منبع config. |
| `holidayInputsChanged` / `holiday_inputs_changed` | نشانگر تکمیلی بولی یا null برای تغییر ورودی مؤثر ادغام‌شده provider تعطیلی از همه منابع. |
| `databaseRulesChanged` / `database_rules_changed` | نشانگر تکمیلی بولی یا null برای تغییر ورودی مؤثر تعطیلی با منبع دیتابیس. |
| `error` / `error` | جزئیات مفید ناتوانی در محاسبه مجدد؛ در موفقیت، `null`. |

در مقایسه بدون تغییر، `version_changed = false`، `estimate_changed = false`، جهت `unchanged` و `delta_seconds = 0` است. اگر فقط نسخه تغییر کرده باشد، `version_changed = true` می‌شود و همین فیلدهای برآورد باقی می‌مانند. حذف تعطیلی مؤثر بر برنامه اصلی می‌تواند برآورد را جلو بیندازد و اختلاف ثانیه منفی بدهد. شواهد هر تاریخ، تفاوت مشاهده‌شده تقویم/پنجره/علت را نشان می‌دهد؛ ثابت نمی‌کند هر تاریخ فهرست‌شده علت جابه‌جایی بوده است. تفاوت سیاست یا محیط اجرا هم لزوماً تغییر پنجره کاری یک تاریخ نیست.

نشانگرهای ورودی، محتوای مؤثر ثبت‌شده را مقایسه می‌کنند، نه نسخه فایل config یا شمارنده بازبینی جدول دیتابیس را. غیرفعال‌کردن قاعده جایگزین دیتابیس می‌تواند قاعده config ارث‌رسیده را آشکار کند و در نتیجه هر دو نشانگر مربوط به منبع را تغییر بدهد. مقدار `holiday_inputs_changed` ورودی مؤثر ادغام‌شده همه منابع را پوشش می‌دهد. این نشانگرها تغییر مشاهده‌شده محتوا را جدا می‌کنند و ثابت نمی‌کنند کدام ویرایش علت جابه‌جایی برآورد بوده است.

مقایسه، نتیجه ذخیره‌شده، لحظه دریافت اصلی، برنامه، نام پروفایل‌های انتخابی و افق را بررسی و حفظ می‌کند. قواعد مؤثر فعلی را می‌سازد و همان planner موجود را دوباره اجرا می‌کند. قواعد مسیریابی دوباره حل نمی‌شوند و انبار/حمل‌کننده تازه‌مسیریابی‌شده جایگزین نمی‌شود. مقایسه unresolvable پایان اصلی را نگه می‌دارد و بدون ساختن برآورد فعلی، محدودیت مانع را گزارش می‌کند. ورودی ذخیره‌شده نامعتبر به‌جای مقایسه ظاهراً معتبر unresolvable، `InvalidArgumentException` می‌دهد.

### سازگاری خواندن storage، کارایی و مسئولیت‌ها

حالت config تعریف مؤثر پس از ارث‌بری را ثبت می‌کند. حالت database قواعد فعال تکرارشونده و تاریخ‌دار محدود را با نام واقعی هر پروفایل انتخابی می‌خواند؛ رکورد دیتابیس والد ارث نمی‌رسد. حالت chain داده config ارث‌رسیده را با قواعد دیتابیس فرزند طبق اولویت، عنوان و گزارش منبع قبلی ترکیب می‌کند. cutoff و ساعت کاری پیشرفته دوباره استفاده می‌شوند و موتور دوم برنامه‌ریزی یا تعطیلی ساخته نمی‌شود.

پس از آماده‌شدن ورودی provider، محاسبه اصلی و شواهد از یک نمای ثابت استفاده می‌کنند. خواندن‌های اولیه دیتابیس خودکار یک snapshot تراکنشی سازگار میان چند query نیستند؛ اگر برنامه به این سازگاری سخت‌گیرانه نیاز دارد، باید تراکنش خواندن و سطح isolation مناسب خودش را فراهم کند. پکیج رکورد تعطیلی را قفل نمی‌کند و تراکنشی که رفتار نوشتن برنامه را عوض کند شروع نمی‌کند؛ ثبت و مقایسه هم نوشتن دیتابیس ندارند.

آماده‌سازی database/chain برای هر پروفایل انتخابی، دو SELECT دسته‌ای برای قواعد تکرارشونده و استثناهای تاریخ‌دار دارد؛ مستقل از طول افق یا دقیقه‌های گزینه‌ها. ثبت فقط مبتنی بر config به query دیتابیس تعطیلی نیاز ندارد. تنظیم مؤثر و provider در همان عملیات دوباره استفاده می‌شوند، محدوده تاریخ و تعداد پروفایل محدود است و کش سراسری/پایدار یا کار اضافی در مسیرهای معمول قبلی اضافه نمی‌شود. محدودیت ۲ MiB برای پروفایل با تعریف‌های بزرگ یا عنوان علت طولانی همچنان مهم است.

اندازه JSON فشرده همین نمونه UTC مبتنی بر config روی PHP 8.3.30 / Carbon 3.13.0 به شکل زیر اندازه‌گیری شد. در حالت تک‌پروفایلی، فقط مرحله آماده‌سازی انبار و cutoff آن باقی می‌ماند. اندازه با تعریف‌ها، نام‌ها، عنوان‌ها، نتیجه و اطلاعات محیط اجرا تغییر می‌کند.

| پروفایل ارجاع‌شده | افق | تاریخ مشاهده‌شده برای هر پروفایل | بایت JSON |
|---|---|---|---|
| یک | ۱ | ۷ | ۳٬۹۶۴ |
| یک | ۱۴ | ۲۰ | ۷٬۶۷۶ |
| یک | ۳۱ | ۳۷ | ۱۲٬۵۵۶ |
| دو، مانند نمونه بالا | ۱۴ | ۲۰ | ۱۲٬۷۸۵ |

### ده بررسی دستی

فقط از نمونه جداگانه بالا استفاده کن؛ همه تاریخ‌ها مربوط به سال ۲۰۲۶ و همه ساعت‌ها UTC هستند.

| بررسی | اقدام | نتیجه مورد انتظار |
|---|---|---|
| ۱. ثبت | `$snapshot` را با افق صریح چهارده‌روزه بساز. | پایان اصلی سوم نوامبر، 12:00؛ نسخه غیرخالی SHA-256 تقویم. |
| ۲. تبدیل به JSON | `$payload` را encode کن، JSON را بازخوانی و `$restored` را بساز. | همان نسخه تقویم و نتیجه اصلی؛ فیلدهای قابل انتقال و سازگار با JSON. |
| ۳. بدون تغییر | `$unchanged->toArray()` را ببین. | resolved، هر دو نشانگر تغییر false، جهت `unchanged` و اختلاف `0`. |
| ۴. تعطیلی آزمایشی | تعطیلی سوم نوامبر حمل‌کننده را طبق نمونه در config اضافه کن. | فقط تنظیم آزمایشی فعلی تغییر می‌کند و شواهد ذخیره‌شده ثابت می‌ماند. |
| ۵. مقایسه تعطیلی | `$later` را ببین. | چهارم نوامبر، 12:00؛ `later` و `86400` ثانیه؛ فرزند/تاریخ متأثر در تفاوت‌ها دیده می‌شود. |
| ۶. حفظ اصل | `$snapshot->originalResult` و JSON ذخیره‌شده را دوباره ببین. | همچنان سوم نوامبر، 12:00 با شواهد و نسخه اصلی. |
| ۷. ساعت کوتاه‌تر | تعطیلی آزمایشی را بردار و ساعت دوم نوامبر والد را 09:00–11:00 بگذار. | تاریخ تقویم همچنان کاری است؛ آماده‌سازی تا سوم نوامبر ادامه دارد. |
| ۸. مقایسه ساعت | `$changedHours` و فهرست تفاوت تاریخ‌ها را ببین. | چهارم نوامبر، 10:00؛ تفاوت پنجره‌های کاری دیده می‌شود. |
| ۹. پروفایل ارث‌بر | نام انتخابی/شواهد مؤثر را ببین؛ پس از پاک‌کردن ساعت‌ها، `$unresolvable` را بررسی کن. | هویت فرزند حفظ شده، تنظیم ارث‌رسیده رعایت شده و نبود زمان کاری صریحاً unresolvable است. |
| ۱۰. اثر جانبی | وضعیت برنامه را پس از این فراخوانی‌های config بررسی کن. | بدون تغییر سفارش، نوشتن دیتابیس، رزرو، اعلان یا درخواست شبکه. |

تنظیمات نشست را با `config(['workdays' => $promiseOriginalConfig]);` برگردان. ذخیره یا اعلام تعهد اصلاح‌شده فقط باید با منطق صریح برنامه انجام شود. برای آزمایش، ردیف تعطیلی محیط عملیاتی را تغییر نده و این قابلیت بررسی را مدیریت ظرفیت، ثبت نوبت، اعلان خودکار یا سرویس پایش زمان‌بندی‌شده تلقی نکن.

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

## ارزیابی SLA کاری و توقف/ادامه

متد `Workday::evaluateSla($startedAt, $evaluatedAt, $options)` زمان کاری مصرف‌شده، بودجه باقی‌مانده، مهلت و وضعیت نقض SLA را محاسبه می‌کند. مثلاً فروشگاه می‌تواند برای بررسی درخواست مرجوعی مشتری چهار ساعت کاری فرصت بدهد و انتظار تأییدشده برای دریافت مدرک از مشتری را کنار بگذارد. دقیقه کاری فقط در ساعت مجاز خدمت مصرف می‌شود؛ دقیقه تقویمی، ناهار، عصر بسته، آخر هفته و تعطیلی را هم در بر می‌گیرد.

این یک محاسبه مستقل است، نه وضعیت ذخیره‌شده تیکت یا گردش کار. برنامه تعیین می‌کند SLA چه زمانی شروع شود، چه توقفی و با چه دلیلی مجاز باشد، هر توقف چه زمانی تمام شود، تیکت چه زمانی تکمیل شود و چه ارجاع یا اعلانی لازم باشد. پکیج تیکت را پایش نمی‌کند، تایمر یا یادآور نمی‌سازد، رکورد توقف ذخیره نمی‌کند و درخواست مرجوعی را تغییر نمی‌دهد.

این API اختیاری روی facade و `WorkdayManager` در دسترس است و `Zarbinco\LaravelWorkdays\Data\SlaResult` تغییرناپذیر می‌دهد. پروفایل تعریف‌شده با ساعت کاری معتبر، به تنظیم اختصاصی SLA نیاز ندارد. alias، Carbon macro، جدول، job یا وابستگی Composer جدیدی اضافه نمی‌شود.

### پروفایل جداگانه Tinker و نمونه کامل

این نمونه فقط مبتنی بر config را در نشست محلی `php artisan tinker` اجرا کن. تقویم معمول، ارث‌بر، چندبخشی و شبانه پشتیبانی را تعریف می‌کند و تنظیمات قبلی را برای بازگرداندن نگه می‌دارد. همه تاریخ‌های نمونه مربوط به سال ۲۰۲۶ هستند.

```php
use Zarbinco\LaravelWorkdays\Facades\Workday;

$slaOriginalConfig = config('workdays');
$slaWeekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
$slaBase = [
    'weekends' => ['Saturday', 'Sunday'],
    'holidays' => ['gregorian' => [], 'jalali' => [], 'hijri' => []],
    'custom_holidays' => [],
    'extra_working_days' => [],
    'working_hours' => array_fill_keys($slaWeekdays, [['09:00', '17:00']]),
];
config([
    'workdays.storage.driver' => 'config',
    'workdays.max_scan_days' => 14,
    'workdays.profiles' => array_replace(config('workdays.profiles', []), [
        'sla_support_base' => $slaBase,
        'sla_support' => [
            'extends' => 'sla_support_base',
            'working_hours' => ['Thursday' => [['09:00', '20:00']]],
        ],
        'customer_support' => ['extends' => 'sla_support'],
        'sla_split' => [
            'extends' => 'sla_support_base',
            'working_hours' => array_fill_keys($slaWeekdays, [['09:00', '12:00'], ['13:00', '17:00']]),
        ],
        'sla_night' => [
            'extends' => 'sla_support_base',
            'allow_overnight_working_hours' => true,
            'working_hours' => [
                'Monday' => [['22:00', '06:00']],
                'Tuesday' => [], 'Wednesday' => [], 'Thursday' => [],
                'Friday' => [['22:00', '06:00']],
            ],
        ],
    ]),
]);

$slaStartedAt = '2026-11-02T15:00:00Z';
$slaOptions = ['profile' => 'sla_support', 'timezone' => 'UTC', 'target_minutes' => 240];
$withoutPause = Workday::evaluateSla($slaStartedAt, '2026-11-03T10:30:00Z', $slaOptions);
// dueAt November 3, 11:00 UTC; consumed=210, remaining=30, status=active.

$closedPauses = [['started_at' => '2026-11-03T10:00:00Z', 'ended_at' => '2026-11-03T11:00:00Z']];
$withPause = Workday::evaluateSla($slaStartedAt, '2026-11-03T11:30:00Z', array_replace(
    $slaOptions,
    ['pauses' => $closedPauses],
));
// dueAt November 3, 12:00 UTC; consumed=210, paused=60, remaining=30.
$withPause->toArray();

// The same API without the facade:
app(\Zarbinco\LaravelWorkdays\WorkdayManager::class)
    ->evaluateSla($slaStartedAt, '2026-11-03T11:30:00Z', array_replace($slaOptions, ['pauses' => $closedPauses]));
```

### گزینه‌ها، زمان ورودی و خطاها

| گزینه | الزامی / پیش‌فرض | مقدار مجاز |
|---|---|---|
| `profile` | الزامی | نام غیرخالی پروفایل تعریف‌شده با ساعت کاری معتبر؛ ارث‌بری پشتیبانی می‌شود. |
| `timezone` | الزامی | منطقه زمانی صریح IANA برای تقویم خدمت، مثل `UTC` یا `Asia/Tehran`؛ از timezone سرور یا برنامه حدس زده نمی‌شود. |
| `target_minutes` | الزامی | عدد صحیح واقعی PHP از `1` تا `1,000,000`؛ بودجه دقیق زمان کاری. |
| `pauses` | اختیاری؛ `[]` | ساختار فهرست با حداکثر `1,000` آرایه توقف ورودی؛ ترتیب ارسال می‌تواند دلخواه باشد. هر آرایه دقیقاً `started_at` و `ended_at` دارد. |

گزینه ناشناخته، نبود مقدار الزامی، رشته عددی، اعشاری، بولی، null صریح گزینه، ساختار کلی یا شکل بازه نامعتبر و تنظیم پروفایل نامعتبر با `WorkdayConfigurationException` رد می‌شوند. فقط `ended_at` توقف می‌تواند null باشد. زمان نامعتبر، ترتیب زمانی نادرست بازه یا چند توقف باز، `InvalidArgumentException` می‌دهد. محاسبه محدود با مهلت مشخص که در افق تنظیم‌شده جا نشود، `RuntimeException` با اطلاعات پروفایل/سقف می‌دهد.

هر دو زمان اصلی الزامی‌اند؛ موتور به‌جای آن‌ها `now()` نمی‌گذارد. مقدار `evaluatedAt` باید برابر یا بعد از `startedAt` باشد. زمان اصلی و توقف، `string|\DateTimeInterface` و همان قالب‌های مطلق دقیق را می‌پذیرند: `Y-m-d H:i[:s[.microseconds]]` با فاصله و بدون offset، یا زمان ISO با `T` و `Z`/`±HH:MM`؛ بخش اعشاری ۱ تا ۶ رقم دارد و به ثانیه نیاز دارد. رشته بدون offset در `timezone` صریح تفسیر می‌شود. ورودی offsetدار یا شیء تاریخ، لحظه خودش را حفظ می‌کند و شیء mutable ورودی تغییر نمی‌کند. داخل محاسبه و در نتیجه، لحظه‌ها UTC تغییرناپذیرند و ثانیه/میکروثانیه حفظ می‌شود.

رشته خالی یا نسبی، ورودی فقط تاریخ، تاریخ Gregorian ناممکن، offset بدساخت، ساعت محلی ناموجود و زمان محلی مبهم بدون offset هنگام عقب‌کشیدن ساعت رد می‌شوند. برای تعیین رخداد ساعت تکراری، offset صریح یا لحظه `DateTimeInterface` بده. این قواعد SLA، قرارداد parsing APIهای قبلی را تغییر نمی‌دهند.

شروع هر توقف باید برابر یا بعد از شروع SLA و حداکثر در زمان ارزیابی باشد. پایان توقف بسته باید دقیقاً پس از شروع و حداکثر در زمان ارزیابی باشد؛ توقف برنامه‌ریزی‌شده آینده و توقف بسته با طول صفر نامعتبرند. حداکثر یک توقف می‌تواند باز بماند. سقف `1,000` پیش از نرمال‌سازی اعمال می‌شود و توقف تکراری ارسالی را هم می‌شمارد.

### پنجره کاری و قواعد توقف

متد `workingWindowsFor()` پروفایل انتخابی همچنان مرجع است. محاسبه SLA اولویت config/database/chain، تعطیلی تکرارشونده میلادی/جلالی/هجری و تعطیلی دقیق، روز کاری جایگزین، نیم‌روز، ناهار، ساعت اختصاصی/فصلی، تنظیم ارث‌رسیده و بخش شیفت شبانه را رعایت می‌کند. معنی شرط‌های روزمحور تقویم تغییر نمی‌کند. ادامه معتبر شیفت شبانه می‌تواند روی آخر هفته/تعطیلی دریافت‌کننده زمان SLA مصرف کند، حتی اگر آن تاریخ روز کاری نباشد. سیاست cutoff یا آماده‌سازی اضافی انبار خودکار اعمال نمی‌شود.

توقف نیمه‌باز `[started_at, ended_at)` است؛ شروع آن، مصرف مجاز را می‌بندد و پایانش دوباره اجازه می‌دهد. توقف‌های نامرتب، تکراری، هم‌پوشان و مجاور، یک بار به اجتماع خود نرمال می‌شوند. مثلاً 10:00–10:45 و 10:30–11:00 یک بازه 10:00–11:00 را حذف می‌کنند، نه ۷۵ دقیقه. لحظه‌های معادل با offsetهای مختلف هم همان لحظه باقی می‌مانند.

فقط اشتراک توقف با پنجره کاری در `pausedWorkingMinutes` حساب می‌شود. توقف 18:00–22:00 وقتی کار در 17:00 تمام شود، اثری ندارد. با پنجره‌های 09:00–12:00 و 13:00–17:00، توقف 11:30–13:30 فقط ۶۰ دقیقه کاری را حذف می‌کند؛ ۳۰ دقیقه پیش از ناهار و ۳۰ دقیقه پس از آن. توقفی که از آخر هفته می‌گذرد هم فقط زمان کاریِ در حالت عادی مجاز را حذف می‌کند.

مهلت، اولین لحظه‌ای است که مجموع زمان کاری مجازِ بدون توقف به بودجه برسد. محاسبه به ترتیب واقعی بازه‌های کاری انجام می‌شود و طول تقویمی توقف‌ها صرفاً به مهلت قبلی اضافه نمی‌شود. وقتی بودجه به پایان رسید، توقف دیرتر نمی‌تواند مهلت تثبیت‌شده را جابه‌جا کند یا نقض را از بین ببرد. آمار زمان کاری متوقف‌شده همچنان همه اشتراک‌های مجاز توقف تا ارزیابی، حتی پس از مهلت، را در بر می‌گیرد.

### توقف باز و ادامه واقعی

توقف با `ended_at => null` در زمان ارزیابی هنوز فعال است. بخش سپری‌شده آن تا `evaluatedAt` حذف می‌شود، اما پایان آینده‌اش معلوم نیست. اگر بودجه پیش‌تر تمام نشده باشد، `dueAt` برابر null است؛ ارزیابی به معنی ادامه خودکار SLA نیست. برنامه با بستن همان توقف در لحظه واقعی پایان و ارزیابی دوباره، آن را ادامه می‌دهد.

```php
$openPauses = [['started_at' => '2026-11-03T10:00:00Z', 'ended_at' => null]];
$pausedSla = Workday::evaluateSla($slaStartedAt, '2026-11-03T11:30:00Z', array_replace(
    $slaOptions,
    ['pauses' => $openPauses],
));
// dueAt=null; consumed=180, paused=90, remaining=60; status=paused.

$openPauses[0]['ended_at'] = '2026-11-03T11:30:00Z';
$resumedSla = Workday::evaluateSla($slaStartedAt, '2026-11-03T12:00:00Z', array_replace(
    $slaOptions,
    ['pauses' => $openPauses],
));
// Real resume at 11:30: dueAt=12:30; consumed=210, paused=90, remaining=30.

$atDeadline = Workday::evaluateSla($slaStartedAt, '2026-11-03T11:00:00.000000Z', $slaOptions);
$afterDeadline = Workday::evaluateSla($slaStartedAt, '2026-11-03T11:00:00.000001Z', $slaOptions);
// Exactly due: breached=false, remaining=0. One microsecond later: breached=true.

$latePause = Workday::evaluateSla($slaStartedAt, '2026-11-03T12:00:00Z', array_replace(
    $slaOptions,
    ['target_minutes' => 60, 'pauses' => [['started_at' => '2026-11-03T10:00:00Z', 'ended_at' => null]]],
));
// dueAt remains November 2, 16:00; paused=true, breached=true, status=breached.
```

توقفی که دقیقاً در لحظه پایان بودجه شروع شود هم مهلت معلوم را حفظ می‌کند. در نبود توقف باز، برای SLA منقضی‌نشده با فرض نبود توقف آینده جدید، زمان کاری باقی‌مانده به جلو محاسبه می‌شود. ثبت توقف واقعی بعدی می‌تواند این برآورد را تغییر بدهد. توقف باز معتبر می‌تواند بدون پیمایش بی‌پایان، مهلت نامعلوم بدهد؛ مهلت از قبل معلوم هم با توقف فعلی از دست نمی‌رود.

### مرز دقیق نقض و دقت دقیقه

وضعیت‌های ثابت با این اولویت‌اند: وقتی `evaluatedAt > dueAt`، مقدار `breached`؛ در غیر این صورت، اگر توقف باز فعال باشد `paused`؛ وگرنه `active`. وضعیت تکمیل/لغو تیکت در پکیج وجود ندارد. نشانگرهای `paused` و `breached` دو واقعیت جدا هستند و می‌توانند هر دو true باشند.

دقیقاً در `dueAt`، نقض false و دقیقه باقی‌مانده صفر است. یک میکروثانیه بعد، نقض true است؛ حتی اگر مهلت دقیقاً روی بسته‌شدن بوده و زمان کاری دیگری نگذشته باشد. مثلاً SLA با هدف ۱۲۰ دقیقه و شروع دوشنبه 15:00 در 17:00 سررسید می‌شود؛ ارزیابی 17:00:00.000001 نقض‌شده است، با اینکه دقیقه کاری اضافه هنوز صفر است.

ارزیاب روی PHP شصت‌وچهاربیتی، میکروثانیه مجاز را دقیق جمع می‌کند، توقف‌ها را اجتماع می‌گیرد و گردکردن را فقط روی جمع نهایی انجام می‌دهد:

| مدت خروجی | گردکردن / معنی |
|---|---|
| دقیقه کاری مصرف‌شده | جزء صحیح کل زمان کاری فعال تا ارزیابی، شامل زمان اضافه بر بودجه. |
| دقیقه کاری متوقف‌شده | جزء صحیح کل زمان کاری متوقف‌شده تا ارزیابی. |
| دقیقه کاری باقی‌مانده | گردکردن رو به بالای بودجه مثبت مصرف‌نشده؛ هرگز منفی نیست. |
| دقیقه کاری اضافه بر مهلت | جزء صحیح مصرف فعال مثبتِ بیش از بودجه؛ بدون زمان کاری بیشتر، صفر می‌ماند. |

هر پنجره و توقف جداگانه گرد نمی‌شود. بنابراین کسری از دقیقه می‌تواند یک دقیقه باقی‌مانده بدهد، یا نقض واقعی با صفر دقیقه کاری اضافه ایجاد کند. نقض با لحظه واقعی مهلت تعیین می‌شود، نه جمع گرد‌شده. محاسبه دقیقه/ساعت قدیمی همان دقت قبلی را حفظ می‌کند و برای این API بازنویسی نمی‌شود.

### افق مشترک و مرز منطقه زمانی

مقدار `workdays.max_scan_days` تعداد برچسب تاریخ‌های تقویمی محلی آینده نسبت به تاریخ شروع SLA، یعنی روز صفر، است. آخرین تاریخ شامل می‌شود. توقف، ادامه، پنجره بعدی و برآورد آینده، افق را از نو شروع نمی‌کنند. تاریخ محلی حذف‌شده، یک برچسب مصرف می‌کند و افق را تمدید نمی‌کند. ارزیابی بیرون تاریخ‌های محلی مجاز خطاست، حتی اگر توقف باز باشد.

مهلت مشخص باید روی یک تاریخ مجاز قرار بگیرد. پایان پنجره `24:00` همان نیمه‌شب محلی بعدی است؛ پس مهلت روی نیمه‌شب بعد از آخرین تاریخ مجاز، بیرون افق SLA است. اگر مهلت مشخص در محدوده پیدا نشود، ارزیاب به‌جای ساختن مهلت خطا می‌دهد. توقف باز معتبر با ادامه آینده نامعلوم می‌تواند `dueAt = null` بدهد، به شرط اینکه خود زمان ارزیابی داخل محدوده باشد.

مرز پنجره از تاریخ واقعی محلی پروفایل و لحظه UTC ساخته می‌شود، نه روز ثابت ۸۶٬۴۰۰ثانیه‌ای. سیاست فعلی برنامه پیشرفته برای مرز ناموجود/مبهم، نیمه‌شب حذف‌شده و تاریخ محلی حذف‌شده حفظ می‌شود. شیفت شبانه معتبر در جلوکشیدن/عقب‌کشیدن ساعت، مدت واقعاً سپری‌شده را مصرف می‌کند. منطقه زمانی صریح تقویم خدمت را هماهنگ استفاده کن؛ مسیریابی نام پروفایل انتخاب می‌کند و timezone را حدس نمی‌زند.

### نتیجه و تبدیل به آرایه

کلاس `SlaResult` مقدارهای تغییرناپذیر و فیلدهای ثابت snake_case زیر را دارد. در نمونه توقف بسته یک‌ساعته، هدف ۲۴۰، مصرف‌شده ۲۱۰، متوقف‌شده ۶۰، باقی‌مانده ۳۰، اضافه بر مهلت صفر و سررسید سه‌شنبه 12:00 UTC است.

| ویژگی | کلید خروجی آرایه | معنی |
|---|---|---|
| `profile` | `profile` | نام پروفایل تنظیم‌شده انتخابی. |
| `timezone` | `timezone` | منطقه زمانی صریح تقویم محلی. |
| `startedAt` | `started_at` | شروع اصلی، UTC `CarbonImmutable`. |
| `evaluatedAt` | `evaluated_at` | لحظه ارزیابی، UTC `CarbonImmutable`. |
| `targetMinutes` | `target_minutes` | بودجه دقیق صحیح برحسب دقیقه کاری. |
| `consumedWorkingMinutes` | `consumed_working_minutes` | جزء صحیح دقیقه کاری فعال. |
| `pausedWorkingMinutes` | `paused_working_minutes` | جزء صحیح دقیقه کاری متوقف‌شده. |
| `remainingWorkingMinutes` | `remaining_working_minutes` | باقی‌مانده نامنفی، گرد‌شده رو به بالا. |
| `overdueWorkingMinutes` | `overdue_working_minutes` | جزء صحیح اضافه‌مصرف فعال نامنفی. |
| `dueAt` | `due_at` | UTC `CarbonImmutable`؛ فقط برای مهلت واقعاً نامعلومِ توقف باز، null. |
| `paused` | `paused` | وجود توقف باز در لحظه ارزیابی. |
| `breached` | `breached` | قرارگرفتن زمان ارزیابی پس از مهلت معلوم، با مقایسه دقیق لحظه‌ها. |
| `status` | `status` | `active`، `paused` یا `breached` با اولویت بالا. |

متد `toArray()` با JSON سازگار است. زمان غیرnull با قالب `Y-m-d\TH:i:s.uP`، شش رقم میکروثانیه و `+00:00` صریح نوشته می‌شود؛ مثلاً `2026-11-03T12:00:00.000000+00:00`. نتیجه، شیء mutable ورودی را نگه نمی‌دارد و اطلاعات اختصاصی مشتری اضافه نمی‌کند. ورودی و قواعد مؤثر یکسان، نتیجه یکسان می‌دهند.

### اتصال به درخواست مرجوعی و مسئولیت برنامه

این نمونه اتصال به برنامه فرض می‌کند پروفایل نام‌برده از قبل وجود دارد. برنامه زمان ارزیابی را صریح انتخاب می‌کند؛ دادن `now()` در اینجا تصمیم خودش است و برای محاسبه تکرارپذیر می‌تواند به‌جای آن لحظه ثبت‌شده بدهد.

```php
$result = Workday::evaluateSla(
    $returnRequest->created_at,
    now(),
    [
        'profile' => 'customer_support',
        'timezone' => 'Asia/Tehran',
        'target_minutes' => 240,
        'pauses' => [['started_at' => $pauseStartedAt, 'ended_at' => $pauseEndedAt]],
    ],
);
if ($result->breached) {
    // The application decides whether to escalate or notify; this call sends nothing.
}
```

برنامه دلیل و زمان توقف مجاز را ثبت می‌کند، دسترسی را کنترل می‌کند و وضعیت تکمیل/لغو را مدیریت می‌کند. می‌تواند ابتدا با `resolveProfile($context)` پروفایل بگیرد و `$selection->profile` را بدهد؛ `evaluateSla()` زمینه را داخل خودش دوباره مسیریابی نمی‌کند. ارث‌بری و هویت دیتابیس فرزند تابع فاز ۰۵ است؛ ردیف تعطیلی دیتابیس والد خودکار ارث نمی‌رسد. اولویت providerهای config/database/chain تغییر نمی‌کند.

یک calculator/تنظیم مؤثر در هر ارزیابی دوباره استفاده می‌شود، توقف‌ها یک بار نرمال می‌شوند و پیمایش به‌جای تک‌تک دقیقه‌ها، برحسب تاریخ/پنجره محدود و مرز توقف است. پنجره تاریخ در همان عملیات استفاده مجدد می‌شود؛ provider داخل حلقه پیمایش دوباره ساخته یا برای هر بخش توقف query نمی‌شود. جست‌وجوی مهلت با پایان بودجه متوقف می‌شود، اما برای آمار مصرف/اضافه‌مصرف در صورت نیاز تا زمان ارزیابی حساب ادامه دارد. کش سراسری یا پایدار اضافه نمی‌شود و کارایی API معمول قبلی تغییر نمی‌کند.

موتور SLA فقط با فراخوانی صریح اجرا می‌شود. calculator، parser زمان، نتیجه‌های قبلی یا ساختار/hashهای snapshot فاز ۰۶ را تغییر نمی‌دهد. محاسبه دوباره از توقف‌های داده‌شده و قواعد مؤثر تقویم استفاده می‌کند؛ نگهداری نتیجه تاریخی SLA یا اعمال قواعد ارجاع، کار برنامه است.

### دوازده بررسی دستی

از نمونه جداگانه UTC بالا استفاده کن و رکورد محیط عملیاتی را تغییر نده. بین حالت‌های مختلف تقویم، تنظیم آزمایشی پروفایل را برگردان.

| بررسی | اقدام | نتیجه مورد انتظار |
|---|---|---|
| ۱. ساعت پشتیبانی | تعریف پایه/ارث‌بر را ببین. | دوشنبه تا جمعه 09:00–17:00؛ فرزند صریحاً پنجشنبه را تا 20:00 باز می‌کند. |
| ۲. شروع | `$slaStartedAt` را بده. | دوشنبه دوم نوامبر، 15:00 UTC؛ صریح است. |
| ۳. بودجه | `$slaOptions` را بده. | دقیقاً ۲۴۰ دقیقه کاری. |
| ۴. مهلت اصلی | `$withoutPause` را ببین. | سه‌شنبه سوم نوامبر، 11:00؛ در 10:30 مصرف‌شده ۲۱۰ و باقی‌مانده ۳۰. |
| ۵. توقف بسته | `$closedPauses` را اضافه کن. | سه‌شنبه 10:00–11:00؛ در ارزیابی کاملاً سپری شده است. |
| ۶. مهلت اصلاح‌شده | `$withPause->dueAt` را ببین. | سه‌شنبه 12:00؛ فقط زمان کاری مجاز متوقف‌شده اثر دارد. |
| ۷. باقی‌مانده | `$withPause` در 11:30 را ببین؛ در صورت نیاز دو توقف هم‌پوشان 10:00–10:45 و 10:30–11:00 بده. | مصرف‌شده ۲۱۰، متوقف‌شده ۶۰، باقی‌مانده ۳۰؛ هم‌پوشانی همان نتیجه را می‌دهد. |
| ۸. مرز دقیق | `$atDeadline` و `$afterDeadline` را ببین؛ نمونه بسته‌شدن با هدف ۱۲۰ دقیقه را هم تکرار کن. | خود مهلت نقض‌شده نیست؛ یک میکروثانیه بعد، حتی پس از بسته‌شدن، نقض‌شده است. |
| ۹. توقف باز | `$pausedSla` را ببین. | مهلت null؛ مصرف‌شده ۱۸۰، متوقف‌شده ۹۰، باقی‌مانده ۶۰؛ وضعیت `paused`. |
| ۱۰. ادامه واقعی | `$resumedSla` و `$latePause` را ببین. | ادامه واقعی، مهلت 12:30 می‌دهد؛ توقف دیرهنگام با وجود `paused=true`، مهلت و نقض قبلی را نگه می‌دارد. |
| ۱۱. تقویم دیگر | تعطیلی پایه `custom_holidays => ['2026-11-03' => 'Test holiday']` را اضافه و حالت بدون توقف را حساب کن؛ سپس برگردان و `sla_night` را از دوشنبه 23:00 با هدف ۱۸۰ و توقف 23:30 تا سه‌شنبه 00:30 حساب کن. | تعطیلی مهلت را به چهارشنبه 11:00 می‌برد؛ توقف شبانه سه‌شنبه 03:00 می‌دهد. هر توقف بسته باید پیش از ارزیابی تمام شده باشد. |
| ۱۲. اثر جانبی | وضعیت برنامه را پس از این محاسبه‌های config ببین. | بدون نوشتن دیتابیس، job، اعلان، رزرو یا تغییر وضعیت تیکت. |

برای پروفایل چندبخشی، بررسی کن توقف 11:30–13:30 فقط ۶۰ دقیقه کاری متوقف‌شده بدهد. تنظیمات نشست را با `config(['workdays' => $slaOriginalConfig]);` برگردان. پکیج محاسبه می‌کند و برنامه تصمیم می‌گیرد چه تغییر گردش کار، یادآور یا ارجاعی لازم است.

## برنامه تکرارشونده کاری و دوره‌های اشتراک

متد `Workday::recurringSchedule($anchorDate, $options)` برنامه تاریخ‌های کاری هفتگی یا ماهانه را تولید می‌کند. تاریخ اسمی هر نوبت مستقیماً از مبدأ اولیه و اندیس دوره با شروع از صفر محاسبه می‌شود. جابه‌جایی یک نوبت تعطیل، مبدأ دوره‌های بعدی را تغییر نمی‌دهد: روز پنجم ماه می‌تواند از ۵ نوامبر به ۶ نوامبر و مستقل از آن از ۵ دسامبر به ۷ دسامبر منتقل شود و در ژانویه دوباره به روز پنجم برگردد.

این API اختیاری روی facade و `WorkdayManager`، شیءهای تغییرناپذیر `RecurringScheduleResult` و `RecurringOccurrence` می‌دهد. خروجی، تاریخ محلی تقویم است، نه زمان اجرای کار. اشتراک، سفارش، پرداخت، رزرو موجودی، job صف، اعلان، رزرو حمل یا اجرای خودکار برنامه ایجاد نمی‌شود. تاریخ کاری زمان‌بندی‌شده، تاریخ تحویل تضمین‌شده نیست. اجرای کار و بررسی دوباره تقویم، موجودی، پرداخت و ظرفیت پیش از اقدام، مسئولیت برنامه است.

### نمونه کامل محلی و اشتراک ماهانه شمسی

این نمونه اختصاصی با storage از نوع config را در یک نشست محلی `php artisan tinker` اجرا کن. به دیتاست سالانه ایران یا تنظیم ساعت کاری نیاز ندارد. پروفایل‌های آزمایشی از رکوردهای اشتراک واقعی جدا هستند؛ تنظیمات اولیه را نگه دار تا پس از همه بررسی‌ها برگردانی.

```php
use Zarbinco\LaravelWorkdays\Facades\Workday;

$recurringOriginalConfig = config('workdays');
$recurringBase = [
    'weekends' => ['Saturday', 'Sunday'],
    'holidays' => ['gregorian' => [], 'jalali' => [], 'hijri' => []],
    'custom_holidays' => [],
    'extra_working_days' => [],
];
$recurringProfiles = [
    'recurring_warehouse' => array_replace($recurringBase, [
        'custom_holidays' => [
            '2026-11-05' => 'Monthly test holiday',
            '2026-11-13' => 'Weekly test holiday',
            '2027-01-01' => 'Year-boundary test holiday',
        ],
        'extra_working_days' => ['2026-11-07' => 'Test working Saturday'],
    ]),
    'recurring_child' => ['extends' => 'recurring_warehouse'],
    'recurring_all_days' => array_replace($recurringBase, ['weekends' => []]),
    'recurring_database_demo' => $recurringBase,
    'recurring_collision' => [
        'extends' => 'recurring_warehouse',
        'custom_holidays' => array_fill_keys([
            '2026-11-06', '2026-11-09', '2026-11-10',
            '2026-11-11', '2026-11-12', '2026-11-13',
        ], 'Collision test closure'),
        'extra_working_days' => [],
    ],
    'subscription_warehouse' => [
        'extends' => 'recurring_warehouse',
        'weekends' => ['Friday'],
    ],
];
config([
    'workdays.storage.driver' => 'config',
    'workdays.max_scan_days' => 400,
    'workdays.profiles' => array_replace(config('workdays.profiles', []), $recurringProfiles),
]);

$recurringMonthlyOptions = [
    'profile' => 'recurring_warehouse',
    'timezone' => 'UTC',
    'frequency' => 'monthly',
    'calendar' => 'gregorian',
    'interval' => 1,
    'count' => 4,
    'month_anchor' => 'day_of_month',
    'short_month' => 'clamp',
    'adjustment' => 'next',
    'max_shift_days' => 7,
    'collision_policy' => 'reject',
];
$recurringMonthly = Workday::recurringSchedule('2026-10-05', $recurringMonthlyOptions);
$recurringMonthly->toArray();

$subscriptionOptions = array_replace($recurringMonthlyOptions, [
    'profile' => 'subscription_warehouse',
    'timezone' => 'Asia/Tehran',
    'calendar' => 'jalali',
    'count' => 12,
]);
$subscriptionSchedule = Workday::recurringSchedule('2026-10-05', $subscriptionOptions);
// Gregorian anchor 2026-10-05 is Jalali 1405-07-13.
// Nominal periods advance through Jalali months, preserving day 13.
foreach ($subscriptionSchedule->occurrences as $occurrence) {
    if ($occurrence->scheduledDate === null) {
        continue;
    }

    // The application decides whether and when to create an order for this date.
    // This example deliberately performs no model writes or payment operations.
}

app(\Zarbinco\LaravelWorkdays\WorkdayManager::class)
    ->recurringSchedule('2026-10-05', $recurringMonthlyOptions);
```

نمونه ماهانه میلادی این نتیجه را می‌دهد:

| دوره | تاریخ اسمی | تاریخ زمان‌بندی‌شده |
|---|---|---|
| 0 | `2026-10-05` | `2026-10-05` |
| 1 | `2026-11-05` | `2026-11-06` |
| 2 | `2026-12-05` | `2026-12-07` |
| 3 | `2027-01-05` | `2027-01-05` |

### گزینه‌ها و تاریخ مبدأ سخت‌گیرانه

| گزینه | الزامی / پیش‌فرض | مقدارهای پذیرفته‌شده |
|---|---|---|
| `profile` | الزامی | نام پروفایل تعریف‌شده. |
| `timezone` | الزامی | منطقه زمانی صریح IANA مانند `UTC` یا `Asia/Tehran`. |
| `frequency` | الزامی | `weekly` یا `monthly`. |
| `interval` | الزامی | عدد صحیح واقعی PHP از `1` تا `12`؛ تعداد هفته یا ماهِ تقویم انتخابی در هر دوره. |
| `count` | الزامی | عدد صحیح واقعی PHP از `1` تا `60`؛ شامل دوره‌های ردشده. |
| `adjustment` | الزامی | `next`، `previous` یا `skip`. |
| `calendar` | `gregorian` | `gregorian` یا برای برنامه ماهانه، `jalali`. |
| `month_anchor` | `day_of_month` | فقط ماهانه: `day_of_month` یا `end_of_month`. |
| `short_month` | `clamp` | فقط ماهانه: `clamp` یا `skip`. |
| `max_shift_days` | `7` | عدد صحیح واقعی PHP از `0` تا `31`. |
| `collision_policy` | `reject` | `reject` یا `allow`. |

کلید ناشناخته، مقدار نامعتبر، رشته عددی، اعشاری، بولی و null صریح رد می‌شوند. برنامه هفتگی می‌تواند صریحاً `calendar => 'gregorian'` داشته باشد؛ `jalali` و گزینه‌های صریح `month_anchor` و `short_month` را رد می‌کند، حتی اگر مقدارشان با پیش‌فرض ماهانه برابر باشد. تنظیم اجباری جدیدی به پروفایل یا پکیج اضافه نمی‌شود.

مبدأ رشته‌ای باید تاریخ معتبر میلادی با قالب دقیق `Y-m-d` باشد؛ حتی وقتی تکرار ماهانه از ماه‌های شمسی استفاده می‌کند. عبارت نسبی، رشته datetime، تاریخ ناقص، جداکننده نادرست و تاریخ ناممکن رد می‌شوند. مبدأ `DateTimeInterface` لحظه خود را حفظ می‌کند، به منطقه زمانی صریح تبدیل می‌شود و تاریخ محلی میلادی آن استخراج می‌شود؛ ساعت آن کنار گذاشته می‌شود و شیء ورودی تغییر نمی‌کند. تاریخ‌های خروجی، برچسب تاریخ مدنی هستند، نه timestamp نیمه‌شب UTC.

تاریخ مدنی‌ای که در منطقه زمانی انتخاب‌شده وجود ندارد نمی‌تواند مبدأ باشد. تاریخ اسمی بعدی که در تاریخچه منطقه زمانی حذف شده، روز در دسترس محسوب نمی‌شود و از سیاست جابه‌جایی روز کاری پیروی می‌کند. پیمایش تاریخ و فاصله مثبت یا منفی، از برچسب تاریخ استفاده می‌کنند، نه بازه ثابت ۸۶٬۴۰۰ثانیه‌ای؛ تغییر ساعت باعث رانش یک‌ساعته نمی‌شود. حذف نیمه‌شب با حذف کل یک تاریخ فرق دارد: تاریخی که دیرتر شروع شده ولی وجود دارد، همچنان قابل استفاده است.

### قواعد هفتگی، ماهانه و پایان ماه

دوره هفتگی `i` از `original anchor + i × interval × 7` برچسب تاریخ میلادی به دست می‌آید. اگر ۱۳ نوامبر به ۱۶ نوامبر منتقل شود، تاریخ اسمی هفته بعد همچنان ۲۰ نوامبر است. تولید هفتگی از تاریخ جابه‌جاشده قبلی ادامه پیدا نمی‌کند.

دوره ماهانه `i`، ماه تقویمی اولیه به‌علاوه `i × interval` ماه را انتخاب می‌کند و سپس همان روز مبدأ اصلی را به کار می‌برد. برای ۳۱ ژانویه با `clamp`، تاریخ‌های سال ۲۰۲۶ برابر ۳۱ ژانویه، ۲۸ فوریه، ۳۱ مارس و ۳۰ آوریل هستند. کوتاه‌کردن روز فقط برای همان نوبت است؛ مارس روز ۲۸ فوریه را به ارث نمی‌برد.

با `short_month => 'skip'`، نبود روز اصلی یک دوره ردشده صریح با تاریخ اسمی و زمان‌بندی‌شده null و دلیل `invalid_month_day` می‌سازد. مقدار `nominalPeriod` ماه مقصد و اندیس دوره حفظ می‌شوند و ماه جایگزین تولید نمی‌شود. `count` همیشه تعداد دوره‌های درخواستی است، نه تعداد تاریخ‌های با موفقیت زمان‌بندی‌شده.

گزینه `month_anchor => 'end_of_month'` می‌خواهد مبدأ، آخرین روز ماه در تقویم انتخابی باشد. هر دوره سپس آخرین روز ماه خودش را دارد؛ ۳۱ ژانویه ۲۰۲۴ به ۲۹ فوریه، ۳۱ مارس و ۳۰ آوریل می‌رسد. `short_month` در این حالت اثری ندارد، چون هر ماه روز آخر دارد؛ تاریخ دلخواه وسط ماه رد می‌شود و بی‌صدا به مبدأ پایان ماه تبدیل نمی‌شود.

تکرار ماهانه شمسی، مبدأ عمومی میلادی را به شمسی تبدیل می‌کند، ماه‌های شمسی را جلو می‌برد و تاریخ اسمی معتبر را برای بررسی روز کاری و خروجی به میلادی برمی‌گرداند. ماه‌های ۱ تا ۶، ۳۱ روز؛ ماه‌های ۷ تا ۱۱، ۳۰ روز؛ و اسفند طبق سال کبیسه واقعی شمسی، ۲۹ یا ۳۰ روز دارند. clamp/skip، برگشت به روز اصلی، عبور اسفند به فروردین و پایان ماه از همان تقویم پیروی می‌کنند و تبدیل و بررسی رفت‌وبرگشت سخت‌گیرانه با وابستگی فعلی Verta انجام می‌شود. قواعد کبیسه میلادی، طول اسفند را تعیین نمی‌کنند. تکرار ماهانه قمری پشتیبانی نمی‌شود؛ تعطیلی‌های قمری فعلی همچنان بر کاری‌بودن تاریخ اثر دارند.

برچسب میلادی از سال‌های `0001` تا `9999` استفاده می‌کند. تاریخ تولیدشده خارج از این محدوده یا تبدیل شمسی پشتیبانی‌نشده در نسخه نصب‌شده Verta، `RuntimeException` روشن می‌دهد؛ تبدیل نامعتبر به تاریخ دیگری از برنامه تکرار تبدیل نمی‌شود.

### جابه‌جایی تعطیلی، محدودیت‌ها و تداخل

تاریخ اسمی واجد شرایط بدون تغییر می‌ماند. برای تاریخ اسمی بسته، `next` اکیداً رو به جلو و `previous` اکیداً رو به عقب به دنبال نزدیک‌ترین روز کاری می‌گردد. `skip` تاریخ اسمی را حفظ می‌کند ولی تاریخ زمان‌بندی‌شده نمی‌دهد و دلیل آن `closed_day` است. نوبت جایگزین نمی‌سازد و دوره‌های بعدی را جابه‌جا نمی‌کند.

گزینه `max_shift_days` فاصله برچسب تاریخ از تاریخ اسمی هر نوبت را می‌شمارد؛ تاریخ اسمی فاصله صفر دارد. حد هفت، مقصد با فاصله دقیقاً هفت تاریخ را شامل می‌شود؛ تاریخ هشتم اصلاً بررسی نمی‌شود. آخر هفته، تعطیلی و تاریخ کاملاً حذف‌شده هم همین بودجه را مصرف می‌کنند. صفر یعنی جابه‌جایی مجاز نیست. تمام‌شدن محدوده `next` یا `previous` به‌جای ردکردن بی‌صدا، خطای دارای اطلاعات مرتبط می‌دهد. جابه‌جایی قبلی می‌تواند از مرز ماه یا سال عبور کند و در همین حد، پیش از مبدأ اولیه قرار بگیرد.

تاریخ محلی میلادیِ مبدأ اصلی، روز صفر افق رو به جلوی فعلی `workdays.max_scan_days` است و آخرین تاریخ آن هم مجاز است. هر تاریخ اسمی معتبر و هر مقصد جابه‌جاشده آینده باید در همین یک افق جا بگیرد. برای دوره ردشده به دلیل نبود روز ماه، کل بازه معتبر ماه مقصد، از جمله روز آخر، باید در افق باشد. مقصد قبلی فقط در محدوده جابه‌جایی خودش می‌تواند پیش از مبدأ قرار بگیرد. محدودیت تعداد و جابه‌جایی حفظ می‌شود؛ API تنظیمات را افزایش نمی‌دهد و دوره‌ها را بی‌صدا کوتاه نمی‌کند.

دو دوره ردنشده می‌توانند به یک تاریخ میلادی زمان‌بندی‌شده برسند. پیش‌فرض `reject` خطا می‌دهد و هر دو اندیس و تاریخ را مشخص می‌کند. گزینه صریح `allow` هر دو دوره را نگه می‌دارد و روی **همه** نوبت‌های آن تاریخ `collides = true` می‌گذارد؛ آن‌ها را به یک سفارش تبدیل نمی‌کند. دوره ردشده نمی‌تواند تداخل داشته باشد. ترتیب نتیجه همیشه بر اساس اندیس دوره می‌ماند. وارونگی زمانی بین تاریخ‌های زمان‌بندی‌شده حتی با `allow` رد می‌شود؛ اجازه تاریخ برابر، اجازه به‌هم‌خوردن ترتیب دوره‌های اشتراک نیست.

### نتیجه‌های تغییرناپذیر و تبدیل به آرایه

کلاس‌ها در فضای نام `Zarbinco\LaravelWorkdays\Data` قرار دارند.

| ویژگی `RecurringScheduleResult` | معنی |
|---|---|
| `profile`، `timezone`، `calendar`، `frequency`، `interval` | پروفایل انتخابی و تنظیم مؤثر تکرار. |
| `anchorDate` | مبدأ محلی میلادی نرمال‌شده با قالب `Y-m-d`. |
| `requestedCount` | تعداد دوره درخواستی، شامل نوبت‌های ردشده. |
| `occurrences` | دقیقاً یک `RecurringOccurrence` تغییرناپذیر برای هر دوره، به ترتیب اندیس. |

| ویژگی `RecurringOccurrence` | معنی |
|---|---|
| `cycleIndex` | اندیس دوره اصلی با شروع از صفر. |
| `nominalPeriod` | برای ماهانه، `YYYY-MM` در تقویم انتخابی؛ برای هفتگی، تاریخ میلادی اسمی دوره. |
| `nominalDate` | تاریخ میلادی `Y-m-d`؛ فقط برای روز ناموجود ماه با سیاست skip، برابر null است. |
| `scheduledDate` | تاریخ میلادی `Y-m-d` یا null برای نوبت ردشده. |
| `status` | `scheduled`، `shifted` یا `skipped`. |
| `reason` | `business_day`، `shifted_next`، `shifted_previous`، `closed_day` یا `invalid_month_day`. |
| `shiftDays` | اختلاف تاریخ تقویمی: صفر برای بدون تغییر، مثبت برای بعدی، منفی برای قبلی و null برای ردشده. |
| `collides` | بولیِ نشان‌دهنده تداخل مجاز و صریح در یک تاریخ. |

هر دو متد `toArray()` کلیدهای ثابت snake_case، مقدارهای سازگار با JSON و عدد صحیح، بولی و null واقعی می‌دهند. تاریخ‌ها رشته `Y-m-d` می‌مانند؛ `nominal_period` در دوره ماهانه، ماه تقویم انتخابی را حفظ می‌کند. شناسه نامرتبط سفارش یا مشتری و timestamp اجرا اضافه نمی‌شود.

### تقویم فعلی، زمان عملیاتی و خطاها

واجد شرایط بودن از قواعد فعلی `isBusinessDay()` در calculator انتخابی می‌آید: آخر هفته، تعطیلی میلادی/شمسی/قمری، تعطیلی اختصاصی و روز کاری اضافه با همان اولویت قبلی. providerهای config/database/chain و تنظیم ارث‌رسیده پشتیبانی می‌شوند. ردیف دیتابیس والد خودکار به فرزند انتخابی ارث نمی‌رسد. مسیریابی پروفایل مرحله جداگانه برنامه است و در تولید تکرار خودکار اجرا نمی‌شود.

تاریخ کاری ممکن است ساعت کاری خالی داشته باشد. تکرار به ساعت نیاز ندارد، cutoff دریافت را اعمال نمی‌کند، سفارش آماده نمی‌کند، وقت ملاقات نمی‌دهد و بازه رزرو نمی‌کند. برنامه می‌تواند جداگانه زمان محلی واقعی کار را انتخاب کند و در صورت نیاز `planDelivery()` فاز ۰۲، `deliverySlots()` فاز ۰۳ یا `reversePlanDelivery()` فاز ۰۸ را فراخوانی کند. رشته فقط تاریخ، برای آن APIها timestamp مطلق نیست. قواعد SLA و توقف بدون تغییر هستند. بررسی تعهد تحویل فاز ۰۶ جدا می‌ماند؛ ساختار snapshot تکرار یا ذخیره‌سازی خودکار اضافه نمی‌شود.

| خطا | علت معمول |
|---|---|
| `Zarbinco\LaravelWorkdays\Exceptions\WorkdayConfigurationException` | گزینه، پروفایل یا منطقه زمانی نامعتبر، تنظیم تکرار پشتیبانی‌نشده، کلید ناشناخته یا مبدأیی که برای حالت پایان ماه، آخر ماه نیست. |
| `InvalidArgumentException` | مبدأ رشته‌ای بدقالب/ناممکن یا تاریخ مدنی مبدأ که در منطقه زمانی انتخاب‌شده وجود ندارد. |
| `RuntimeException` | تمام‌شدن محدوده جابه‌جایی، افق سراسری یا محدوده تبدیل پشتیبانی‌شده، تداخل ردشده یا وارونگی زمانی. خطای جابه‌جایی، پروفایل، دوره، تاریخ اسمی، جهت و حد را مشخص می‌کند. |

هر عملیات یک calculator/تنظیم مؤثر برای پروفایل انتخابی می‌گیرد و provider فعلی آن را همراه با کش کوچکِ واجد شرایط بودن تاریخ در همان فراخوانی دوباره استفاده می‌کند. هر دوره مستقیماً از مبدأ اصلی تولید می‌شود، با حداکثر ۶۰ نوبت و ۳۱ برچسب جابه‌جایی برای هر نوبت؛ پیمایش دقیقه/ثانیه، ساخت دوباره provider برای هر دوره، نوشتن در دیتابیس و کش پایدار/سراسری تازه اضافه نمی‌شود. رفتار فعلی نگهداری نتیجه در حافظه و باطل‌شدن کش provider دیتابیس تغییر نمی‌کند. این فاز پیش‌خوانی گسترده تقویم یا اجرای گروهی اشتراک اضافه نمی‌کند. تغییر تقویم می‌تواند محاسبه بعدی را عوض کند؛ برنامه در صورت نیاز تعریف و نتیجه را ذخیره کند و پیش از اجرا دوباره اعتبارسنجی کند. APIهای قبلی بدون فراخوانی این متد، کار تازه‌ای برای تکرار انجام نمی‌دهند.

### هفده بررسی دستی محلی

نمونه اصلی را ادامه بده. کد، متن خطاهای مورد انتظار را نگه می‌دارد تا بررسی‌های بعدی هم اجرا شوند.

```php
$recurringWeeklyOptions = [
    'profile' => 'recurring_warehouse', 'timezone' => 'UTC',
    'frequency' => 'weekly', 'interval' => 1, 'count' => 4,
    'adjustment' => 'next', 'max_shift_days' => 7, 'collision_policy' => 'reject',
];
$recurringWeekly = Workday::recurringSchedule('2026-11-06', $recurringWeeklyOptions);
$recurringAllDays = array_replace($recurringMonthlyOptions, ['profile' => 'recurring_all_days']);
$recurringClamp = Workday::recurringSchedule('2026-01-31', $recurringAllDays);
$recurringShortSkip = Workday::recurringSchedule('2026-01-31', array_replace($recurringAllDays, [
    'short_month' => 'skip',
]));
$recurringLeap = Workday::recurringSchedule('2024-01-31', $recurringAllDays);
$recurringMonthEnd = Workday::recurringSchedule('2024-01-31', array_replace($recurringAllDays, [
    'month_anchor' => 'end_of_month',
]));
$recurringJalaliOptions = array_replace($recurringAllDays, ['calendar' => 'jalali', 'count' => 8]);
$recurringJalali = Workday::recurringSchedule('2026-09-22', $recurringJalaliOptions);
$recurringJalaliSkip = Workday::recurringSchedule('2026-09-22', array_replace($recurringJalaliOptions, [
    'short_month' => 'skip',
])); // Gregorian anchor is Jalali 1405-06-31.
$recurringEsfandLeap = Workday::recurringSchedule('2021-03-20', array_replace($recurringAllDays, [
    'calendar' => 'jalali', 'month_anchor' => 'end_of_month', 'count' => 2,
])); // 1399-12-30, then 1400-01-31
$recurringEsfandNormal = Workday::recurringSchedule('2022-03-20', array_replace($recurringAllDays, [
    'calendar' => 'jalali', 'month_anchor' => 'end_of_month', 'count' => 2,
])); // 1400-12-29, then 1401-01-31
$recurringPrevious = Workday::recurringSchedule('2027-01-01', array_replace($recurringMonthlyOptions, [
    'count' => 1, 'adjustment' => 'previous',
])); // scheduled 2026-12-31, shiftDays = -1
$recurringClosedSkip = Workday::recurringSchedule('2026-11-06', array_replace($recurringWeeklyOptions, [
    'adjustment' => 'skip',
]));
$recurringExtra = Workday::recurringSchedule('2026-11-07', array_replace($recurringWeeklyOptions, [
    'count' => 1,
])); // extra working Saturday remains unchanged
$recurringInherited = Workday::recurringSchedule('2026-10-05', array_replace($recurringMonthlyOptions, [
    'profile' => 'recurring_child',
]));

$recurringCollisionOptions = array_replace($recurringWeeklyOptions, [
    'profile' => 'recurring_collision', 'count' => 2, 'max_shift_days' => 10,
]);
try {
    Workday::recurringSchedule('2026-11-06', $recurringCollisionOptions);
} catch (\RuntimeException $exception) {
    $recurringCollisionError = $exception->getMessage();
}
$recurringAllowed = Workday::recurringSchedule('2026-11-06', array_replace($recurringCollisionOptions, [
    'collision_policy' => 'allow',
])); // both cycles scheduled 2026-11-16, both collides = true
try {
    Workday::recurringSchedule('2026-11-05', array_replace($recurringMonthlyOptions, [
        'count' => 1, 'max_shift_days' => 0,
    ]));
} catch (\RuntimeException $exception) {
    $recurringShiftError = $exception->getMessage();
}
```

بررسی ۱۶ به migrationهای فعلی و **داده آزمایشی محلی از قبل موجود** نیاز دارد: یک ردیف فعال از نوع `holiday` برای پروفایل `recurring_database_demo` در تاریخ `2026-11-13`، بدون قاعده فعال مرتبط دیگر. از داده تولید استفاده نکن و با این چک‌لیست ردیف درج نکن. اگر نمونه جداگانه موجود نیست، پیش از بررسی اختیاری فقط‌خواندنی، آن را در راه‌اندازی تست برنامه آماده کن:

```php
$recurringPreviousDriver = config('workdays.storage.driver');
config(['workdays.storage.driver' => 'database']);
$recurringDatabaseClosed = Workday::profile('recurring_database_demo')->isBusinessDay('2026-11-13');
$recurringDatabase = Workday::recurringSchedule('2026-11-06', array_replace($recurringWeeklyOptions, [
    'profile' => 'recurring_database_demo', 'count' => 2,
])); // ordinary eligibility is false; cycle 1 moves to 2026-11-16
config(['workdays.storage.driver' => $recurringPreviousDriver]);
```

| بررسی | نتیجه مورد انتظار |
|---|---|
| ۱ | انبار اختصاصی، آخر هفته شنبه/یکشنبه و تعطیلی آزمایشی صریح دارد. |
| ۲ | مقدار `$recurringMonthly` چهار دوره ماهانه دارد. |
| ۳ | تاریخ‌های اسمی، روز پنجم هر ماه میلادیِ مبدأ اصلی را حفظ می‌کنند. |
| ۴ | ۵ نوامبر به ۶ نوامبر منتقل می‌شود. |
| ۵ | تاریخ اسمی/زمان‌بندی‌شده ژانویه به روز پنجم برمی‌گردد؛ تاریخ اسمی دسامبر همچنان ۵ دسامبر است. |
| ۶ | نوبت هفتگی ۱۳ نوامبر به ۱۶ نوامبر منتقل می‌شود؛ تاریخ اسمی بعدی همچنان ۲۰ نوامبر است. |
| ۷ | ۳۱ ژانویه به ۲۸ فوریه محدود می‌شود و در مارس به روز ۳۱ برمی‌گردد؛ skip ماه کوتاه، دوره فوریه را با تاریخ‌های null نگه می‌دارد. |
| ۸ | دنباله ۲۰۲۴ از ۲۹ فوریه استفاده می‌کند؛ حالت پایان ماه، آخر واقعی هر ماه را حفظ می‌کند. |
| ۹ | تاریخ شمسی ۱۴۰۵/۰۶/۳۱ به ۱۴۰۵/۰۷/۳۰ محدود می‌شود؛ skip آن دوره را با تاریخ‌های null نگه می‌دارد و ۱۴۰۶/۰۱ دوباره روز ۳۱ دارد. |
| ۱۰ | اسفند کبیسه ۱۳۹۹ روز ۳۰ تمام می‌شود؛ اسفند عادی ۱۴۰۰ روز ۲۹، و هر دو سپس به ۳۱ فروردین می‌رسند. |
| ۱۱ | تعطیلی اول ژانویه به ۳۱ دسامبر با جابه‌جایی `-1` منتقل می‌شود. |
| ۱۲ | رد پیش‌فرض تداخل، دوره‌های ۰ و ۱ و تاریخ ۱۶ نوامبر را مشخص می‌کند. |
| ۱۳ | اجازه صریح، هر دو نوبت ۱۶ نوامبر را با پرچم تداخل نگه می‌دارد. |
| ۱۴ | تعطیلی با حد جابه‌جایی صفر، `$recurringShiftError` می‌دهد و بی‌صدا رد نمی‌شود. |
| ۱۵ | فرزند، رفتار تعطیلی و روز کاری اضافه ارث‌رسیده را بازتولید می‌کند. |
| ۱۶ | با داده دیتابیس جداگانه، وضعیت روز کاری و تکرار درباره تعطیلی یکسان‌اند؛ فقط خواندن انجام می‌شود. |
| ۱۷ | سفارش، پرداخت، نوشتن در دیتابیس، job صف، رزرو موجودی/بازه، رزرو حمل یا اعلان ایجاد نشده است. |

نمونه صریح `skip` تاریخ اسمی بسته را حفظ می‌کند؛ نمونه روز کاری اضافه، شنبه را بدون جابه‌جایی نگه می‌دارد. علاوه بر تاریخ زمان‌بندی‌شده، `toArray()` و اندیس دوره‌ها را هم بررسی کن. پس از همه بررسی‌ها تنظیم محلی را برگردان:

```php
config(['workdays' => $recurringOriginalConfig]);
```

## محاسبات گروهی و پیش‌خوانی تعطیلی‌ها

متد `Workday::bulkCalculate($operations, $options = [])` محاسبه‌های مستقل را به ترتیب ورودی اجرا می‌کند و calculatorهای قابل استفاده، تعریف مؤثر پروفایل و providerهای تعطیلی را در همان فراخوانی به اشتراک می‌گذارد. این قابلیت روی facade و `WorkdayManager` اختیاری است. با ثابت‌بودن تنظیمات، داده تقویم، قواعد منطقه زمانی و وابستگی‌ها، هر آیتم تکمیل‌شده با API تکی متناظر برابر است؛ از جمله نوع نتیجه، میکروثانیه، اختلاف ساعت، مراحل، دلیل‌ها و خروجی فعلی آرایه.

فراخوانی معمول پروفایل همچنان calculator/provider خودش را می‌سازد و استفاده مجدد فعلی در هر محاسبه حفظ می‌شود. اشتراک گروهی با پایان فراخوانی تمام می‌شود. پکیج برآورد محاسبه می‌کند؛ سفارش ثبت نمی‌کند، پرداخت انجام نمی‌دهد، موجودی یا ظرفیت حمل رزرو نمی‌کند، اعلان نمی‌فرستد، worker اجرا نمی‌کند و رزرو ایجاد نمی‌کند. ذخیره‌سازی، اجرا، مجوز دسترسی و هر زمان‌بندی پس‌زمینه، مسئولیت برنامه است.

### نمونه کامل محلی با ده عملیات

این نمونه اختصاصی را در `php artisan tinker` محلی اجرا کن. storage آن config و تاریخ‌هایش ثابت است و در دیتابیس یا سفارش چیزی نمی‌نویسد. انبار، فرزند ارث‌برنده، تقویم تهران و تقویم شبانه عمداً قواعد کاری متفاوتی دارند.

```php
use Zarbinco\LaravelWorkdays\Facades\Workday;

$bulkOriginalConfig = config('workdays');
$bulkWeekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
$bulkBase = [
    'weekends' => ['Saturday', 'Sunday'],
    'holidays' => ['gregorian' => [], 'jalali' => [], 'hijri' => []],
    'custom_holidays' => [], 'extra_working_days' => [],
    'working_hours' => array_fill_keys($bulkWeekdays, [['09:00', '17:00']]),
    'cutoff' => ['timezone' => 'UTC', 'time' => '14:00'],
];
$bulkProfiles = [
    'bulk_base' => $bulkBase,
    'bulk_warehouse' => [
        'extends' => 'bulk_base',
        'custom_holidays' => ['2026-11-05' => 'Test holiday'],
        'extra_working_days' => ['2026-11-07' => 'Test working Saturday'],
    ],
    'bulk_child' => ['extends' => 'bulk_warehouse'],
    'bulk_carrier' => [
        'extends' => 'bulk_base',
        'working_hours' => array_fill_keys($bulkWeekdays, [['12:00', '18:00']]),
    ],
    'bulk_tehran' => ['extends' => 'bulk_base', 'cutoff' => ['timezone' => 'Asia/Tehran']],
    'bulk_night' => [
        'extends' => 'bulk_base', 'allow_overnight_working_hours' => true,
        'working_hours' => [
            'Monday' => [['22:00', '06:00']],
            'Tuesday' => [], 'Wednesday' => [], 'Thursday' => [], 'Friday' => [],
        ],
    ],
    'bulk_database_demo' => ['extends' => 'bulk_base'],
];
config([
    'workdays.storage.driver' => 'config',
    'workdays.include_start_date' => false,
    'workdays.max_scan_days' => 40,
    'workdays.profiles' => array_replace(config('workdays.profiles', []), $bulkProfiles),
]);
$bulkPlan = [
    'timezone' => 'UTC',
    'profile_timezones' => ['bulk_warehouse' => 'UTC', 'bulk_carrier' => 'UTC'],
    'cutoff_profile' => 'bulk_warehouse',
    'stages' => [
        ['id' => 'prepare', 'type' => 'work', 'profile' => 'bulk_warehouse', 'minutes' => 60],
        ['id' => 'pickup', 'type' => 'handoff', 'profiles' => ['bulk_warehouse', 'bulk_carrier']],
        ['id' => 'transit', 'type' => 'business_days', 'profile' => 'bulk_carrier', 'days' => 1],
        ['id' => 'finish', 'type' => 'work', 'profile' => 'bulk_warehouse', 'minutes' => 30],
    ],
];
$bulkSlotOptions = [
    'profile' => 'bulk_warehouse', 'timezone' => 'UTC', 'days' => 1,
    'duration_minutes' => 60, 'step_minutes' => 60, 'max_slots' => 3,
];
$bulkRecurrenceOptions = [
    'profile' => 'bulk_child', 'timezone' => 'UTC', 'frequency' => 'monthly',
    'interval' => 1, 'count' => 2, 'adjustment' => 'next',
];
$bulkSlaOptions = [
    'profile' => 'bulk_warehouse', 'timezone' => 'UTC', 'target_minutes' => 240,
    'pauses' => [['started_at' => '2026-11-02T10:00:00Z', 'ended_at' => '2026-11-02T11:00:00Z']],
];
$bulkOperations = [
    ['id' => 'day', 'operation' => 'is_business_day', 'profile' => 'bulk_warehouse', 'date' => '2026-11-05'],
    ['id' => 'windows', 'operation' => 'working_windows', 'profile' => 'bulk_night', 'date' => '2026-11-03'],
    ['id' => 'days', 'operation' => 'add_business_days', 'profile' => 'bulk_warehouse',
        'date' => new \DateTimeImmutable('2026-11-02T00:00:00Z'), 'days' => 3],
    ['id' => 'minutes', 'operation' => 'add_business_minutes', 'profile' => 'bulk_tehran',
        'datetime' => '2026-11-02T11:00:00.123456+03:30', 'minutes' => 120],
    ['id' => 'cutoff', 'operation' => 'resolve_cutoff', 'profile' => 'bulk_tehran',
        'received_at' => '2026-11-02T13:30:00+03:30'],
    ['id' => 'delivery', 'operation' => 'plan_delivery', 'received_at' => '2026-11-02T10:00:00Z', 'plan' => $bulkPlan],
    ['id' => 'slots', 'operation' => 'delivery_slots', 'ready_at' => '2026-11-02T12:00:00Z', 'options' => $bulkSlotOptions],
    ['id' => 'recurrence', 'operation' => 'recurring_schedule', 'anchor_date' => '2026-11-02', 'options' => $bulkRecurrenceOptions],
    ['id' => 'sla', 'operation' => 'evaluate_sla', 'started_at' => '2026-11-02T09:00:00Z',
        'evaluated_at' => '2026-11-02T12:00:00Z', 'options' => $bulkSlaOptions],
    ['id' => 'reverse', 'operation' => 'reverse_plan_delivery', 'desired_completion_at' => '2026-11-03T13:30:00Z',
        'plan' => $bulkPlan, 'options' => ['lookback_days' => 14]],
];
$bulkOptions = ['on_error' => 'collect', 'prefetch' => ['from' => '2026-11-01', 'through' => '2026-11-30']];
$bulkMixed = Workday::bulkCalculate($bulkOperations, $bulkOptions);
$bulkMixed->total; // 10
$bulkMixed->succeeded; // 10
$bulkMixed->failed; // 0
$bulkMixed->items[5]->result->estimatedCompletionAt; // November 3, 12:30 UTC
$bulkMixed->toArray();

app(\Zarbinco\LaravelWorkdays\WorkdayManager::class)->bulkCalculate($bulkOperations, $bulkOptions);
```

### ساختار کامل آیتم و محدودیت‌ها

هر آیتم `id` و `operation` دارد و فقط کلیدهای آرگومان مجاز همان عملیات را از جدول زیر می‌پذیرد. نوع آرگومان، تفسیر تاریخ، قواعد مدت، تنظیمات توکار الزامی و اعتبارسنجی دامنه را API اصلی تعیین می‌کند؛ محاسبه گروهی الگوریتم تقویم تازه‌ای جایگزین نمی‌کند.

| `operation` | کلیدهای آرگومان | مقدار واقعی `result` در PHP |
|---|---|---|
| `is_business_day` | `profile`، `date` | بولی. |
| `working_windows` | `profile`، `date` | آرایه‌ای از شیءهای فعلی `TimeWindow`. |
| `add_business_days` | `profile`، `date`، `days` | `CarbonImmutable`. |
| `add_business_minutes` | `profile`، `datetime`، `minutes` | `CarbonImmutable`. |
| `resolve_cutoff` | `profile`، `received_at` | `CutoffResult`. |
| `plan_delivery` | `received_at`، `plan` | `DeliveryPlanResult`. |
| `delivery_slots` | `ready_at`، `options` | `DeliverySlotsResult`. |
| `recurring_schedule` | `anchor_date`، `options` | `RecurringScheduleResult`. |
| `evaluate_sla` | `started_at`، `evaluated_at`، `options` | `SlaResult`. |
| `reverse_plan_delivery` | `desired_completion_at`، `plan`، `options` | `ReverseDeliveryPlanResult`. |

مقدار `$operations` باید فهرست اندیس‌دار غیرخالی با حداکثر ۱٬۰۰۰ آیتم باشد و هر آیتم یک آرایه کلیددار باشد. شناسه‌ها باید رشته UTF-8 غیرخالی و یکتا با حداکثر ۱۲۸ نویسه باشند؛ شناسه‌ای که فقط فاصله، tab یا خط جدید دارد رد می‌شود. در هر گروه حداکثر ۵۰ پروفایل متمایزِ ارجاع‌شده مجاز است؛ شامل پروفایل‌های داخل برنامه‌ها و گزینه‌ها. ترتیب نتیجه همان ورودی می‌ماند و آیتمی حذف یا جابه‌جا نمی‌شود. مقدار false برای روز کاری، نتیجه بولی موفق است، نه شکست آیتم.

فقط گزینه‌های سطح بالا `on_error` و `prefetch` پشتیبانی می‌شوند. پیش‌فرض `on_error` برابر `collect` است و `collect` یا `throw` می‌پذیرد. گزینه `prefetch` اختیاری است و دقیقاً `from` و `through` می‌خواهد؛ هر دو تاریخ سخت‌گیرانه میلادی `Y-m-d`، با شرط `from <= through` و حداکثر ۶۲ برچسب تاریخ شامل دو سر. برای مثال، ۱ تا ۳۰ نوامبر ۳۰ برچسب دارد. پیش‌خوانی نه `max_scan_days` را تغییر می‌دهد و نه محاسبه معتبر را به همین تاریخ‌ها محدود می‌کند.

خطای ساختار بیرونی، کل فراخوانی را **پیش از هر محاسبه** رد می‌کند: شکل نامعتبر فهرست/آیتم، شناسه مفقود/تکراری/نامعتبر، عملیات پشتیبانی‌نشده، کلید ناشناخته، فیلد متعلق به عملیات دیگر، گزینه/بازه گروهی نامعتبر یا تعداد بیش از حد آیتم/پروفایل. آرگومان عملیاتی مفقود یا با نوع نادرست، پروفایل انتخابی ناشناخته و اعتبارسنجی برنامه/گزینه توکار، خطای دامنه همان آیتم هستند و از `on_error` پیروی می‌کنند. callback دلخواه داخل payload اجرا نمی‌شود و جدول مشتری/سفارش برنامه خوانده نمی‌شود.

ساختار کپی‌شده آرگومان هر آیتم، حداکثر عمق ۳۲ و ۱۰۰٬۰۰۰ گره دارد؛ عبور از این حد، خطای همان آیتم است. ارجاع‌های تنظیمات/گزینه ورودی برای عملیات جدا می‌شوند، بدون تغییر تنظیمات سراسری Laravel.

### سیاست خطا و نتیجه‌های تغییرناپذیر

با `collect`، خطای شناخته‌شده ورودی، تنظیمات یا محاسبه محدود، به آیتم ناموفقِ مرتب تبدیل می‌شود و آیتم‌های مستقل بعدی ادامه می‌یابند. با `throw`، اولین خطای عملیات، `RuntimeException` با شناسه آیتم و عملیات می‌دهد و خطای اصلی را به‌عنوان علت قبلی نگه می‌دارد. تلاش مجدد ضمنی وجود ندارد. خطای غیرمنتظره برنامه‌نویسی و شکست جدی زیرساخت، از جمله نبود جدول، خطای اتصال/PDO/query و خطای نوع داخلی، حتی در حالت collect منتقل می‌شوند.

کلاس `Zarbinco\LaravelWorkdays\Data\BulkCalculationResult` ویژگی‌های `total`، `succeeded`، `failed`، `items` و متد `toArray()` را دارد. هر `BulkCalculationItemResult` تغییرناپذیر، `id`، `operation`، `success`، `result`، `error` و `toArray()` دارد. آیتم موفق همان نوع نتیجه قبلی را نگه می‌دارد و خطایش null است. آیتم ناموفق نتیجه null و خطایی فقط با `type` اصلی exception و `message` مفید دامنه با حداکثر ۲٬۰۴۸ نویسه UTF-8 دارد. نام پروفایل و برچسب تاریخ می‌توانند در پیام بمانند. DTO، stack trace، اطلاعات SQL/اتصال یا اطلاعات ورودِ exception دیتابیس را ضمیمه نمی‌کند؛ زنجیره علت PDO/query و خطای برنامه‌نویسی به‌جای تبدیل‌شدن به آرایه منتقل می‌شوند. اعتبارسنجی ساختار بیرونی `WorkdayConfigurationException` می‌دهد.

متد `toArray()` کلیدهای ثابت snake_case و مقدارهای سازگار با JSON می‌دهد. DTOها و پنجره‌های زمانی از `toArray()` فعلی خود استفاده می‌کنند؛ timestamp حساب Carbon با قالب `Y-m-d\TH:i:s.uP`، **اختلاف ساعت واقعی خودش** و شش رقم میکروثانیه می‌آید. محاسبه گروهی همه نتیجه‌ها را به UTC نمی‌برد و پیش از برگرداندن نتیجه PHP، نوع‌ها را به آرایه عمومی تبدیل نمی‌کند. بولی، عدد صحیح و null نوع خود را حفظ می‌کنند و رشته تاریخ تکرار همچنان فقط تاریخ می‌ماند. شناسه را برنامه می‌دهد و از رکورد مشتری/سفارش استخراج نمی‌شود.

### پیش‌خوانی، کش نتیجه خالی و تازگی داده

storage از نوع config برای تعطیلی query دیتابیس ندارد. در database، پیش‌خوانی صریح، قواعد تکرارشونده فعال پروفایل‌های انتخابی را گروهی می‌خواند و تاریخ‌های خاص فعال همان پروفایل‌ها را در محدوده دقیق و شامل‌شونده می‌گیرد. chain پیش‌خوانی دیتابیس را واگذار می‌کند و اولویت config/database، عنوان، اطلاعات منبع، رفتار ردیف غیرفعال و تفاوت تعطیلی با روز کاری اضافه را حفظ می‌کند. تنظیم ارث‌رسیده مورد نیاز محاسبه می‌شود و پروفایل نامرتبط پیشاپیش بارگذاری نمی‌شود. ردیف دیتابیس با نام واقعی فرزند انتخابی خوانده می‌شود و ردیف والد خودکار ارث نمی‌رسد.

محدوده خوانده‌شده **حاشیه اضافی ندارد**. تاریخ محلی پروفایل‌ها ممکن است با تاریخ UTC فرق کند و ادامه شیفت شبانه ممکن است تاریخ مالک قبلی را بخواهد. هر تاریخ لازم بیرون از راهنما، با خواندن تنبل فعلی provider گرفته می‌شود. تاریخ تحت پوشش که ردیف فعال ندارد، به‌عنوان نتیجه خالی کش می‌شود و query تازه برای همان تاریخ نمی‌زند. بنابراین راهنمای کوچک، محاسبه را کامل نگه می‌دارد؛ تقویم خالی فرضی یا خطای محدوده نمی‌سازد. اعتبارسنجی ردیف تاریخ خاص همچنان هنگام دسترسی انجام می‌شود تا ردیف خراب نامرتبط، محاسبه تاریخ دیگری را نامعتبر نکند.

هر گروه، یک زمینه تنظیمات جداشده از ورودی دارد. تعریف مؤثر، adapterها و provider مشترک دوباره استفاده می‌شوند؛ کلید کش calculator، پروفایل انتخابی و حد واقعی پیمایش است. حد متفاوت مرحله/منطقه زمانی، نمونه calculator جداگانه می‌گیرد. جست‌وجوی معکوسِ دارای وضعیت، برای هر آیتم برنامه‌ریز تازه دارد و وابستگی‌های قابل استفاده مشترک می‌مانند. تغییر config سراسری، کش پایدار، کش static فرایند، وابستگی Redis، تغییر جدول/ایندکس یا قفل ردیف تعطیلی اضافه نمی‌شود.

گروه بعدی زمینه تازه می‌سازد و تغییر ثبت‌شده تقویم را می‌بیند؛ حتی در worker صف یا فرایند طولانی Octane. در یک گروه، قواعد کش‌شده یا پیش‌خوانده‌شده می‌توانند کنار خواندن تنبلِ بعدی قرار بگیرند: تغییر هم‌زمان دیتابیس، **snapshot سازگار تراکنشی** نمی‌دهد. برنامه، سیاست تراکنش/سطح جداسازی لازم برای محاسبه فعلی را انتخاب می‌کند. محاسبه گروهی ابزار بررسی تاریخی نیست. متدهای `captureDeliveryPromise()` و `compareDeliveryPromise()` فاز ۰۶ در dispatcher نیستند و تضمین مستقل تقویم ثابت، ساختار و fingerprint خود را حفظ می‌کنند.

همه قواعد قبلی منطقه زمانی/DST، انحصاری‌بودن cutoff و واجد شرایط بودن تاریخ آینده، مرحله/افق تحویل، شبکه/notice بازه، ساعت اختصاصی/شبانه/`24:00`، توقف/نقض SLA، دقت معکوس و نبود رانش تکرار، همان API تکی می‌مانند. پیش‌خوانی دسترسی داده را تغییر می‌دهد، نه معنای محاسبه را. API معمول بدون فراخوانی این قابلیت، کار گروهی تازه‌ای انجام نمی‌دهد.

### بررسی محلی برابری، خطا و بار

نمونه config بالا را ادامه بده. نتیجه کامل تبدیل‌شده را مقایسه کن، نه فقط تاریخ نهایی:

```php
$bulkSerialize = function ($value) use (&$bulkSerialize) {
    if ($value instanceof \DateTimeInterface) {
        return $value->format('Y-m-d\TH:i:s.uP');
    }
    if (is_array($value)) {
        return array_map($bulkSerialize, $value);
    }

    return is_object($value) ? $value->toArray() : $value;
};
$bulkIndividual = [
    Workday::profile('bulk_warehouse')->isBusinessDay('2026-11-05'),
    Workday::profile('bulk_night')->workingWindowsFor('2026-11-03'),
    Workday::profile('bulk_warehouse')->addBusinessDays(new \DateTimeImmutable('2026-11-02T00:00:00Z'), 3),
    Workday::profile('bulk_tehran')->addBusinessMinutes('2026-11-02T11:00:00.123456+03:30', 120),
    Workday::profile('bulk_tehran')->resolveCutoff('2026-11-02T13:30:00+03:30'),
    Workday::planDelivery('2026-11-02T10:00:00Z', $bulkPlan),
    Workday::deliverySlots('2026-11-02T12:00:00Z', $bulkSlotOptions),
    Workday::recurringSchedule('2026-11-02', $bulkRecurrenceOptions),
    Workday::evaluateSla('2026-11-02T09:00:00Z', '2026-11-02T12:00:00Z', $bulkSlaOptions),
    Workday::reversePlanDelivery('2026-11-03T13:30:00Z', $bulkPlan, ['lookback_days' => 14]),
];
$bulkAllTenMatch = array_map($bulkSerialize, $bulkIndividual)
    === array_map(fn ($item) => $bulkSerialize($item->result), $bulkMixed->items); // true

$bulkHundred = [];
$bulkCalendarProfiles = ['bulk_warehouse', 'bulk_child', 'bulk_carrier', 'bulk_tehran'];
$bulkDates = ['2026-11-02', '2026-11-05', '2026-11-07'];
for ($i = 0; $i < 100; $i++) {
    $bulkHundred[] = ['id' => 'probe-'.$i, 'operation' => 'is_business_day',
        'profile' => $bulkCalendarProfiles[$i % 4], 'date' => $bulkDates[$i % 3]];
}
$bulkHundredIndividual = array_map(fn ($op) => Workday::profile($op['profile'])->isBusinessDay($op['date']), $bulkHundred);
$bulkHundredResult = Workday::bulkCalculate($bulkHundred, $bulkOptions);
$bulkHundredMatch = $bulkHundredIndividual === array_map(fn ($item) => $item->result, $bulkHundredResult->items); // true
$bulkNight = Workday::bulkCalculate([
    ['id' => 'night', 'operation' => 'add_business_minutes', 'profile' => 'bulk_night',
        'datetime' => '2026-11-02T23:00:00Z', 'minutes' => 180],
]); // November 3, 02:00 UTC
$bulkCollected = Workday::bulkCalculate([
    $bulkHundred[0],
    ['id' => 'unknown-profile', 'operation' => 'is_business_day', 'profile' => 'bulk_missing', 'date' => '2026-11-02'],
    ['id' => 'invalid-time', 'operation' => 'plan_delivery', 'received_at' => 'tomorrow', 'plan' => $bulkPlan],
    $bulkHundred[1],
]); // total=4, succeeded=2, failed=2, original order retained
try {
    Workday::bulkCalculate([
        ['id' => 'invalid-time', 'operation' => 'plan_delivery', 'received_at' => 'tomorrow', 'plan' => $bulkPlan],
    ], ['on_error' => 'throw']);
} catch (\RuntimeException $exception) {
    $bulkThrownItem = $exception->getMessage();
}
$bulkThousand = [];
for ($i = 0; $i < 1000; $i++) {
    $bulkThousand[] = array_replace($bulkHundred[$i % 100], ['id' => 'load-'.$i]);
}
$bulkStarted = hrtime(true);
$bulkThousandResult = Workday::bulkCalculate($bulkThousand, $bulkOptions);
$bulkThousandSeconds = (hrtime(true) - $bulkStarted) / 1_000_000_000;
// A local observation, not a repeatable benchmark or a production speed guarantee.
```

### بررسی اختیاری query و تازگی در دیتابیس آزمایشی دورریختنی

این بخش را فقط روی دیتابیس آزمایشی محلی دورریختنی با migrationهای فعلی اجرا کن. پروفایل `bulk_database_demo` نباید در ابتدا تعطیلی فعال در ۳ نوامبر داشته باشد. ساخت/ویرایش داده تست از محاسبه پکیج جداست؛ درج صریح زیر تغییر داده آزمایشی است، نه نوشتن توسط `bulkCalculate()`. از جدول تولید یا مشتری استفاده نکن. شمارش زیر فقط SELECT جدول‌های تعطیلی است و راه‌اندازی و ویرایش داده تست را شامل نمی‌شود.

```php
$bulkPreviousDriver = config('workdays.storage.driver');
config(['workdays.storage.driver' => 'database']);
$bulkConnection = \Illuminate\Support\Facades\DB::connection();
$bulkConnection->enableQueryLog();
$bulkDatabaseOps = array_map(fn ($op) => array_replace($op, ['profile' => 'bulk_database_demo']), $bulkHundred);
$bulkHolidaySelects = function () use ($bulkConnection): int {
    return count(array_filter($bulkConnection->getQueryLog(), fn ($query) =>
        str_starts_with(strtolower(ltrim($query['query'])), 'select')
        && (str_contains($query['query'], 'workday_holiday_rules') || str_contains($query['query'], 'workday_special_dates'))
    ));
};
$bulkConnection->flushQueryLog();
$bulkDatabaseIndividual = array_map(fn ($op) => Workday::profile($op['profile'])->isBusinessDay($op['date']), $bulkDatabaseOps);
$bulkStandaloneQueries = $bulkHolidaySelects();
$bulkConnection->flushQueryLog();
$bulkDatabaseResult = Workday::bulkCalculate($bulkDatabaseOps, $bulkOptions);
$bulkPrefetchedQueries = $bulkHolidaySelects();
$bulkDatabaseMatch = $bulkDatabaseIndividual === array_map(fn ($item) => $item->result, $bulkDatabaseResult->items); // true

$bulkFreshnessOp = [['id' => 'freshness', 'operation' => 'is_business_day',
    'profile' => 'bulk_database_demo', 'date' => '2026-11-03']];
$bulkBeforeEdit = Workday::bulkCalculate($bulkFreshnessOp, $bulkOptions); // result=true
\Zarbinco\LaravelWorkdays\Models\WorkdaySpecialDate::query()->create([
    'profile' => 'bulk_database_demo', 'date' => '2026-11-03',
    'type' => 'holiday', 'title' => 'Disposable freshness fixture', 'is_active' => true,
]); // Test setup only: retain/discard this disposable database through your test tooling.
$bulkConnection->flushQueryLog();
$bulkAfterEdit = Workday::bulkCalculate($bulkFreshnessOp, $bulkOptions); // result=false
$bulkAfterEditQueries = $bulkHolidaySelects();
$bulkConnection->disableQueryLog();
config(['workdays.storage.driver' => $bulkPreviousDriver]);
```

### پردازش بخش‌بندی‌شده فروشگاه و بنچمارک

کد زیر متعلق به برنامه است: مدل سفارش موجود و سازنده `deliveryPlan()` خود برنامه را فرض می‌کند. پکیج هیچ‌کدام را ارائه نمی‌دهد. برای هر بخش، محدوده پیش‌خوانی مناسب و محدود انتخاب کن؛ شکست هر آیتم را صریح رسیدگی کن و درباره ذخیره برآورد تصمیم بگیر. خود API سفارش را تغییر نمی‌دهد.

```php
\App\Models\Order::query()->chunkById(1000, function ($orders) {
    $operations = [];
    foreach ($orders as $order) {
        $operations[] = ['id' => 'order-'.$order->id, 'operation' => 'plan_delivery',
            'received_at' => $order->created_at, 'plan' => $order->deliveryPlan()];
    }
    $batch = Workday::bulkCalculate($operations, ['on_error' => 'collect']);
    foreach ($batch->items as $item) {
        if (! $item->success) {
            // Application logs/handles this order's item error.
            continue;
        }
        $estimatedAt = $item->result->estimatedCompletionAt;
        // Application decides whether to store this estimate.
    }
});
```

۱۰٬۰۰۰ سفارش را در ده گروه حداکثر ۱٬۰۰۰تایی یا بخش‌های کوچک‌تر پردازش کن. پس از رسیدگی، نتیجه هر گروه را آزاد کن و فهرست نامحدود نتیجه‌ها نساز. این فراخوانی‌ها محاسبه هم‌زمان هستند، نه job پایدار؛ برنامه تصمیم می‌گیرد بخش‌ها در درخواست، command یا job صف خودش اجرا شوند.

بنچمارک اختیاری `tests/Benchmarks/BulkCalculationsBenchmark.php` از CI معمول جداست. داده ثابت و یکسان SQLite را پس از راه‌اندازی/گرم‌کردن مقایسه می‌کند، نمونه‌های تکراری و میانه زمان را به کار می‌برد و تعداد پردازش‌شده/موفق/ناموفق، SELECT تعطیلی، ساخت نمونه‌ها و اوج حافظه را گزارش می‌کند. بارها شامل ۱۰۰، ۱٬۰۰۰ و ۱۰٬۰۰۰ محاسبه در بخش‌های محدود، چند پروفایل، تاریخ تکراری، تعطیلی و برنامه تحویل هستند. راه‌اندازی/درج داده حساب نمی‌شود؛ هزینه بخش‌بندی و وضعیت کش باید مشخص باشند. اندازه‌گیری SQLite، زمان تولید MySQL را ثابت نمی‌کند و سود پیش‌خوانی به پوشش پروفایل/تاریخ، تأخیر دیتابیس و الگوریتم زیرین بستگی دارد. query تنبل بیرون محدوده و اندازه محدود نتیجه همچنان مهم‌اند؛ افزایش سرعت همگانی وعده داده نمی‌شود.

```bash
php tests/Benchmarks/BulkCalculationsBenchmark.php --run
```

اسکریپت برای هر حالت/اندازه سه نمونه می‌گیرد؛ هر نمونه در فرایند PHP تازه با دیتابیس SQLite در حافظه تازه و ده عملیات گرم‌کردنِ اندازه‌گیری‌نشده اجرا می‌شود. هر دو حالت بخش‌های حداکثر ۱٬۰۰۰تایی دارند و کش provider/calculator از یک بخش یا نمونه به بعدی نمی‌ماند. زمان اندازه‌گیری‌شده شامل ساخت عملیات، بخش‌بندی و hash نتیجه کامل است و bootstrap، ساخت جدول، درج داده و گرم‌کردن را شامل نمی‌شود. اوج حافظه تخصیص‌یافته PHP پس از گرم‌کردن از نو شمرده می‌شود و هم مقدار مطلق و هم رشد نسبت به مبدأ گزارش می‌شوند. SELECT تعطیلی بدون نگه‌داشتن لاگ SQL شمرده می‌شود. تعداد ساخت نمونه در bulk ابزار شمارش دارد؛ تعداد مسیر تکی صریحاً از مسیر تحویل دوپروفایلیِ بدون تغییر استنتاج می‌شود و به‌عنوان تخصیص اندازه‌گیری‌شده ارائه نمی‌شود.

اندازه‌گیری با PHP 8.3.30، Laravel 13.18.0، Carbon 3.13.0، Verta 9.0 و SQLite 3.40.0 در حافظه انجام شد. میانه نهایی سه نمونه این بود:

| عملیات / بخش | ثانیه تکی | ثانیه گروهی | SELECT تعطیلی: تکی / گروهی | اوج تخصیص MiB: تکی / گروهی | رشد اوج MiB: تکی / گروهی |
|---|---:|---:|---:|---:|---:|
| 100 / 1 | 0.7164327 | 0.6101408 | 452 / 2 | 14 / 16 | 0 / 0 |
| 1,000 / 1 | 9.0082473 | 6.7008960 | 4,562 / 2 | 18 / 28 | 4 / 12 |
| 10,000 / 10 | 89.1847089 | 69.6629135 | 45,605 / 20 | 18 / 32 | 4 / 16 |

هر ۱۸ نمونه همه عملیات را بدون شکست تمام کردند و hash نتیجه کامل برابر بود. ساخت calculator/provider دیتابیس در حالت گروهی، به‌ترتیب `5/1`، `5/1` و `50/10` مشاهده شد؛ تعداد calculator/کل provider در حالت تکی، `200/400`، `2,000/4,000` و `20,000/40,000` از مسیر کد استنتاج شد و provider تنظیماتِ ساخته‌شده ولی استفاده‌نشده هم در جمع تکی حساب شد. این اعداد فقط اندازه‌گیری همین بار SQLite هستند. اصلاحی که بعداً فقط برای اعتبارسنجی تنظیم پروفایل null انجام شد، ورودی و محاسبه معتبر این بنچمارک را تغییر نمی‌دهد؛ گزارش بازبینی، این تفاوت نسخه اندازه‌گیری‌شده را ثبت می‌کند.

### بیست بررسی دستی محلی

| بررسی | نتیجه مورد انتظار |
|---|---|
| ۱ | پروفایل اختصاصی انبار/حمل‌کننده/تهران/فرزند/شبانه تعریف شده است. |
| ۲ | قواعد تعطیلی و روز کاری اضافه با تقویم عادی روز هفته فرق دارند. |
| ۳ | مقدار `$bulkHundred` صد آیتم آزمایشی با شناسه یکتا دارد. |
| ۴ | مقدار `$bulkHundredIndividual` از API معمول پروفایل آمده است. |
| ۵ | مقدار `$bulkHundredResult` همان آیتم‌ها را در یک گروه اجرا می‌کند. |
| ۶ | مقدارهای `$bulkHundredMatch` و `$bulkAllTenMatch` برابر true هستند؛ نوع و خروجی کامل را هم بررسی کن. |
| ۷ | بخش اختیاری دیتابیس جداگانه از storage دیتابیس تعطیلی استفاده می‌کند. |
| ۸ | مقدار `$bulkStandaloneQueries` را با `$bulkPrefetchedQueries` مقایسه کن. |
| ۹ | راهنما ۳۰ برچسب تاریخ از ۱ تا ۳۰ نوامبر را پوشش می‌دهد. |
| ۱۰ | تاریخ تکراری/خالی تحت پوشش با خواندن گروهی، query تعطیلی را بدون تغییر نتیجه کم می‌کند. |
| ۱۱ | ویرایش صریح داده تست دورریختنی، پس از گروه اول تعطیلی ۳ نوامبر را اضافه می‌کند. |
| ۱۲ | گروه تازه برای آن تاریخ false می‌دهد و کش گروه قبلی نشت نمی‌کند. |
| ۱۳ | فرزند، قواعد تعطیلی و روز کاری اضافه ارث‌رسیده را حفظ می‌کند. |
| ۱۴ | پنجره شبانه به ۳ نوامبر ادامه دارد؛ ۱۸۰ دقیقه از 23:00 در 02:00 تمام می‌شود. |
| ۱۵ | cutoff، خط زمانی تحویل، بازه، SLA، تکرار و معکوس با فراخوانی تکی برابرند. |
| ۱۶ | پروفایل/زمان نامعتبر با شناسه و عملیات همان آیتم جمع‌آوری می‌شوند. |
| ۱۷ | دو آیتم معتبر اطراف خطا همچنان موفق‌اند و ترتیب عوض نمی‌شود. |
| ۱۸ | محاسبه پکیج سفارش، پرداخت، رزرو، اعلان یا نوشتن در دیتابیس ایجاد نمی‌کند؛ ویرایش مشخص‌شده جداگانه، راه‌اندازی تست است. |
| ۱۹ | گروه ۱٬۰۰۰تایی موفق است؛ زمان/حافظه محلی را ثبت کن ولی یک نمونه را بنچمارک حساب نکن. |
| ۲۰ | در صورت نیاز، بنچمارک اختصاصی ۱۰٬۰۰۰تایی را با داده دورریختنی و بخش‌های محدود اجرا کن. |

پس از بررسی، تنظیم محلی را برگردان. دیتابیس آزمایشی اختیاری را با ابزار تست خودت کنار بگذار؛ رکورد تولید حذف نکن.

```php
config(['workdays' => $bulkOriginalConfig]);
```

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
Workday::reversePlanDelivery($desiredCompletionAt, $plan, ['lookback_days' => 14]);
Workday::captureDeliveryPromise($receivedAt, $plan, ['horizon_days' => 14]);
Workday::compareDeliveryPromise($snapshotOrPayload);
Workday::deliverySlots($readyAt, $options);
Workday::evaluateSla($startedAt, $evaluatedAt, $slaOptions);
Workday::recurringSchedule($anchorDate, $recurringOptions);
Workday::bulkCalculate($operations, $bulkOptions);
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
