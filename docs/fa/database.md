<div dir="rtl">

# نگهداری و انتشار تقویم در پایگاه‌داده

[English](../en/database.md) · [همه راهنماها](README.md)

اگر مدیر برنامه باید تقویم را بدون ویرایش فایل منتشر کند، منبع Database را انتخاب کنید. این منبع فقط تعطیلی‌ها را ذخیره نمی‌کند؛ کل سند عملیاتی، از جمله تنظیم مشترک، مسیریابی، پروفایل خام، ارث‌بری، ساعت کار و مهلت پذیرش را نگه می‌دارد.

انتشار، **کل سند منطقی را جایگزین می‌کند** و هر نسخه پس از انتشار تغییرناپذیر است. نام پروفایلی را حذف کنید، ممکن است والد یا مقصد مسیریابی دیگری نامعتبر شود؛ انتشار پیش از فعال‌کردن نسخه، همه این روابط را اعتبارسنجی می‌کند.

## مسیر کامل راه‌اندازی

1. اتصال اختصاصی مورد نظر را در تنظیمات اتصال‌های لاراول آماده کنید و از سطح دسترسی و مقصد آن مطمئن شوید. در مثال‌های اجرایی این راهنما فقط پایگاه‌داده آزمایشی مستقل استفاده می‌شود.

2. تنظیمات و migration پکیج را منتشر کنید:

<div dir="ltr">

```bash
php artisan workdays:install --driver=database
```

</div>

این فرمان migration را اجرا، تعریف‌ها را منتقل یا driver زمان اجرا را عوض نمی‌کند. فایل تنظیمات موجود هم بدون گزینه صریح بازنویسی نمی‌شود. برچسب انتشار schema، <code dir="ltr">workdays-migrations</code> است.

3. مقدار <code dir="ltr">workdays.database.connection</code> را به نام اتصال آماده‌شده تنظیم کنید. برای بررسی مسیر فایل منتشرشده و فرمان مناسب migration برنامه، از <code dir="ltr">php artisan migrate --help</code> استفاده کنید؛ migration تعریف‌ها را فقط روی همان اتصال انتخابی اجرا کنید. جداول لازم <code dir="ltr">workday_definition_state</code>، <code dir="ltr">workday_definition_revisions</code> و <code dir="ltr">workday_definition_profiles</code> هستند. جدول قدیمی تعطیلی به‌تنهایی این منبع را آماده نمی‌کند.

الگوی فرمان زیر را با نام اتصال و مسیر **واقعی فایل منتشرشده** جایگزین کنید؛ لاراول ممکن است timestamp نام فایل را هنگام انتشار تغییر دهد:

<div dir="ltr">

```bash
php artisan migrate --database=<connection> --path=<published-migration-path>
```

</div>

4. یک سند کامل معتبر را **صریحاً** با شرط <code dir="ltr">expectedRevisionId: null</code> منتشر کنید یا با ابزار JSON زیر وارد کنید. null فقط برای منبع بدون نسخه فعال است و مجوز بازنویسی نسخه موجود نیست.

5. بعد از آماده‌شدن schema و انتشار موفق، <code dir="ltr">workdays.driver</code> را در تنظیمات برنامه <code dir="ltr">database</code> کنید. تنظیمات cacheشده لاراول باید از مسیر استقرار عادی برنامه تازه شوند. هیچ انتقال یا fallback خودکاری وجود ندارد.

## مثال مستقل انتشار و محاسبه

مثال زیر برای نشست آزمایشی لاراول، یک اتصال تازه SQLite در حافظه می‌سازد، schema همان اتصال را آماده می‌کند و در پایان تنظیمات را برمی‌گرداند. به پایگاه‌داده پیش‌فرض برنامه وصل نمی‌شود و پیش‌نیاز تقویم Config ندارد.

<!-- example: database-publication -->

<div dir="ltr">

```php
use Illuminate\Support\Facades\DB;
use Zarbinco\LaravelWorkdays\Configuration\DatabaseDefinitionRepository;
use Zarbinco\LaravelWorkdays\Exceptions\DefinitionConflictException;
use Zarbinco\LaravelWorkdays\Facades\Workday;

$connection = 'workdays_documentation';
if (config()->has('database.connections.'.$connection)) {
    throw new LogicException('Choose an unused example connection name.');
}
$originalWorkdays = config('workdays');
$originalConnections = config('database.connections');
$definitions = [
    'default_profile' => 'global',
    'include_start_date' => false,
    'hijri' => ['method' => 'mathematical', 'adjustment' => 0],
    'profile_routing' => null,
    'profiles' => ['global' => [
        'weekends' => ['Saturday', 'Sunday'],
        'holidays' => [], 'custom_holidays' => [], 'extra_working_days' => [],
    ]],
];

try {
    config([
        'database.connections.'.$connection => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ],
        'workdays.database.connection' => $connection,
    ]);
    $packageRoot = dirname((new ReflectionClass(DatabaseDefinitionRepository::class))->getFileName(), 3);
    $migration = require $packageRoot.'/database/migrations/2026_10_20_000001_create_workday_definitions_tables.php';
    $migration->up();

    $repository = app(DatabaseDefinitionRepository::class);
    $first = $repository->publish($definitions, expectedRevisionId: null);
    $noop = $repository->publish($definitions, expectedRevisionId: $first->revisionId);
    $definitions['profiles']['global']['custom_holidays']['2026-11-09'] = 'Inventory';
    $edit = $repository->publish($definitions, expectedRevisionId: $first->revisionId);
    echo json_encode([
        $first->revisionId, $first->changed,
        $noop->revisionId, $noop->changed,
        $edit->revisionId, $edit->changed,
    ], JSON_THROW_ON_ERROR), PHP_EOL;
    try {
        $repository->publish($definitions, expectedRevisionId: $first->revisionId);
    } catch (DefinitionConflictException) {
        echo 'stale revision rejected', PHP_EOL;
    }
    config(['workdays.driver' => 'database']);
    echo Workday::profile('global')->nextBusinessDay('2026-11-06')->toDateString(), PHP_EOL;
} finally {
    DB::purge($connection);
    config(['database.connections' => $originalConnections, 'workdays' => $originalWorkdays]);
}
```

</div>

<div dir="ltr">

```text
[1,true,1,false,2,true]
stale revision rejected
2026-11-10
```

</div>

در خط اول، انتشار اولیه نسخه ۱ را می‌سازد؛ انتشار یکسان با همان شرط نسخه، نسخه ۱ و changed برابر false می‌دهد؛ تغییر تعطیلی نسخه ۲ می‌سازد. سپس شرط قدیمی نسخه ۱ رد می‌شود. محاسبه جدید، دوشنبه تعطیل‌شده را کنار می‌گذارد و سه‌شنبه را می‌دهد. در برنامه واقعی، schema و انتشار را در مسیر مدیریتی مشخص انجام دهید؛ ساخت schema در درخواست عادی یا آغاز هر محاسبه لازم نیست.

## ویرایش با شماره نسخه مورد انتظار

کلاس <code dir="ltr">Zarbinco\LaravelWorkdays\Configuration\DatabaseDefinitionRepository</code> API مدیریتی است:

| متد | نتیجه و کاربرد |
|---|---|
| <code dir="ltr">validate(array $definitions)</code> | اعتبارسنجی سند بدون اتصال به دیتابیس؛ نتیجه <code dir="ltr">DefinitionDocument</code>. |
| <code dir="ltr">current()</code> | نسخه کامل فعلی یا null پیش از انتشار اولیه. |
| <code dir="ltr">revision(int $revisionId)</code> | نسخه تغییرناپذیر با شناسه مثبت؛ نسخه ناموجود خطاست. |
| <code dir="ltr">publish(array $definitions, ?int $expectedRevisionId, array $metadata = [])</code> | انتشار اتمی، فقط در صورت برابر بودن نسخه فعال با شرط درخواست. |

ابتدا <code dir="ltr">current()</code> را بخوانید، <code dir="ltr">definitions</code> آن را ویرایش کنید و همان <code dir="ltr">revisionId</code> را به انتشار بدهید. تغییر هم‌زمان دیگر، <code dir="ltr">DefinitionConflictException</code> ایجاد می‌کند. آخرین سند را بخوانید و تعارض را آگاهانه حل کنید؛ تلاش دوباره با داده قدیمی، ویرایش درست دیگران را جایگزین می‌کند.

انتشار سند هم‌ارز با نسخه فعلی می‌تواند بی‌تغییر باشد و نسخه تازه نسازد. <code dir="ltr">DefinitionPublication</code> دارای <code dir="ltr">revisionId</code>، <code dir="ltr">changed</code> و <code dir="ltr">contentHash</code> است. <code dir="ltr">DefinitionRevision</code> علاوه بر شناسه و hash، <code dir="ltr">definitions</code> خام و <code dir="ltr">metadata</code> را می‌دهد. متادیتای اختیاری از <code dir="ltr">actor</code>، <code dir="ltr">reason</code> و <code dir="ltr">import</code> پشتیبانی می‌کند؛ در هویت محتوای تقویم دخالت ندارد. متن actor حداکثر ۲۵۵ و reason حداکثر ۱۰۰۰ بایت، و کل metadata حداکثر ۱۶ KiB است.

هر محاسبه با تنظیمات زمان شروع خودش انجام می‌شود. تغییرات بعدی روی محاسبه‌های جدید اثر می‌گذارند، نه محاسبه‌ای که در حال اجراست.

محاسبه‌گر نگه‌داشته‌شده هم نسخه اولیه خود را حفظ می‌کند. برای دیدن انتشار جدید، دوباره <code dir="ltr">profile()</code> بگیرید. تغییر اشاره‌گر فعال یا نام اتصال، نمای جاری را وسط محاسبه عوض نمی‌کند. Config به اتصال این منبع وابسته نیست؛ Database هم پروفایل مفقود را از Config نمی‌گیرد. خرابی اتصال، schema یا محتوای ذخیره‌شده با <code dir="ltr">DefinitionSourceException</code> منتقل می‌شود.

انتشار در یک تراکنش، کل ردیف‌های نسخه و پروفایل را درج و اشاره‌گر فعال را اتمی عوض می‌کند. خواندن از اتصال اصلی انجام می‌شود؛ بارگذاری دیرتر والد هم همان اتصال و نسخه را دارد. اگر برنامه تراکنش بیرونی باز کرده باشد، مشاهده و commit نهایی با خود برنامه است؛ پکیج تراکنش بیرونی را commit یا rollback نمی‌کند. برای بازگرداندن محتوای تاریخی، سند آن را با شرط نسخه فعال فعلی به‌عنوان انتشار جدید ثبت کنید.

هر انتشار تغییریافته یک مجموعه کامل پروفایل خام ذخیره می‌کند؛ حذف خودکار نسخه، پاک‌سازی تاریخچه یا بازنشانی اشاره‌گر ارائه نمی‌شود. مجوز مدیریت، پشتیبان، نگهداری تاریخچه و حجم ذخیره‌سازی با برنامه است. ویرایش مستقیم SQL مسیر پشتیبانی‌شده‌ای نیست و می‌تواند بررسی یکپارچگی را ناموفق کند.

## انتقال صریح با JSON

برای خروج از Config به فایل تازه و سپس بررسی انتقال اولیه:

<div dir="ltr">

```bash
php artisan workdays:definitions:export storage/app/workdays.json --source=config
php artisan workdays:definitions:import storage/app/workdays.json --initialize --dry-run
php artisan workdays:definitions:import storage/app/workdays.json --initialize
```

</div>

ورود به اتصال Database تنظیم‌شده می‌نویسد، ولی driver را عوض نمی‌کند. نسخه فعال موجود را با <code dir="ltr">--expected-revision=&lt;id&gt;</code> جایگزین کنید؛ این گزینه و <code dir="ltr">--initialize</code> دقیقاً یکی باید باشد، حتی با dry-run. خروج از Database به <code dir="ltr">--source=database</code> و برای نسخه تاریخی به <code dir="ltr">--revision=&lt;id&gt;</code> نیاز دارد. گزینه <code dir="ltr">--force</code> خروجی فقط اجازه بازنویسی **فایل مقصد** موجود را می‌دهد؛ گزینه‌ای برای دورزدن تعارض انتشار نیست.

فرمت نسخه‌دار JSON حداکثر ۴ MiB است. داده واردشده اجرا نمی‌شود، ارث‌بری و پاک‌سازی‌های صریح را خام حفظ می‌کند و به پروفایل‌های تخت‌شده تبدیل نمی‌شود. فایل را پیش از انتشار بازبینی کنید. dry-run اعتبار سند و شرط نسخه را بررسی می‌کند و چیزی نمی‌نویسد؛ نوشتن بعدی همچنان باید شرط نسخه را بگذراند.

پوشش JSON دقیقاً <code dir="ltr">format_version: 1</code> و <code dir="ltr">definitions</code> دارد و تنظیم اتصال، اطلاعات محرمانه و حد جست‌وجوی راه‌اندازی را در خود نمی‌برد. حد سند، ۴٬۱۹۴٬۳۰۴ بایت همراه پوشش، عمق ۳۲، ۱۰۰٬۰۰۰ گره و ۱ تا ۱۰٬۰۰۰ پروفایل است؛ نام پروفایل UTF-8 غیرخالی، بدون NUL و حداکثر ۲۵۵ بایت است. برای انتقال برعکس، <code dir="ltr">DefinitionDocument::fromJson()</code> را بخوانید و definitions خام آن را آگاهانه در کلید definitions فایل بگذارید؛ انتخاب Config را خودتان انجام دهید. hash محتوا یکپارچگی است و احراز اصالت یا تشخیص هویت منتشرکننده نیست.

## داده محلی تقویم سالانه ایران

پکیج داده کامل رسمی سالانه را همراه ندارد و چیزی از اینترنت دریافت یا خودکار وارد نمی‌کند. داده مورد اعتماد را در مسیر محلی خود نگه دارید. فایل سالانه PHP می‌تواند توسط خواننده محلی اجرا شود؛ فقط داده‌ای را بدهید که به محتوای آن اعتماد دارید. این با ورود تعریف‌های JSON، که کد اجرا نمی‌کند، متفاوت است.

فرمان زیر در حالت Config فقط خود داده سال ۱۴۰۵ را بررسی می‌کند و نه اتصال، تعارض یا نسخه Database را:

<div dir="ltr">

```bash
php artisan workdays:import-iran-calendar 1405 --calendar-path=/absolute/path/to/calendars/iran --dry-run
```

</div>

در Database مقداردهی‌شده، ابتدا پیش‌نمایش نسخه فعلی را بگیرید، سپس با شناسه گزارش‌شده بنویسید. فرمان‌های زیر الگو هستند؛ مسیر داده و شناسه داخل نشانه‌ها را با مقدار واقعی بازبینی‌شده جایگزین کنید:

<div dir="ltr">

```bash
php artisan workdays:import-iran-calendar 1405 --profile=iran --calendar-path=/absolute/path/to/calendars/iran --dry-run
php artisan workdays:import-iran-calendar 1405 --profile=iran --calendar-path=/absolute/path/to/calendars/iran --expected-revision=<revision-id>
```

</div>

فرمان، سال و منبع داده، تاریخ واقعی شمسی و میلادی و تطابقشان، تکراری‌ها و عنوان‌ها را بررسی می‌کند. پیش‌نمایش Database تعداد درج، تغییر، مورد بدون تغییر و تعارض را نشان می‌دهد. نوشتن در Config رد می‌شود. پروفایل مقصد باید موجود باشد؛ تعریف فایل به‌طور ضمنی منتقل نمی‌شود.

فایل داده باید آرایه با year صحیح از ۱ تا ۳۱۷۷، <code dir="ltr">country = IR</code>، <code dir="ltr">calendar = jalali</code>، مشخصات منبع name، نشانی مطلق HTTP(S) در url، تاریخ دریافت میلادی retrieved_at و فهرست holidays بدهد. هر تعطیلی، date واقعی شمسی و gregorian_date متناظر و عنوان معتبر می‌خواهد؛ تاریخ باید در سال فایل باشد و تاریخ تکراری رد می‌شود. حجم بازنمایی JSON داده حداکثر ۴ MiB است. پیش از ساخت فایل، [قرارداد خواننده](../../src/Calendars/IranOfficialCalendar.php) را بررسی کنید.

تعطیلی مؤثر ارث‌رسیده هم در تعارض حساب می‌شود. <code dir="ltr">--force</code> فقط مقدار متعارض تعطیلی را با اجازه صریح جایگزین می‌کند؛ نسخه قدیمی، داده نامعتبر، مشکل منبع یا اعتبارسنجی انتشار را دور نمی‌زند. ورود یکسان نسخه تازه نمی‌سازد. تغییر واردشده متعلق به پروفایل خام انتخابی است؛ قواعد پروفایل‌های دیگر، اولویت روز کاری اضافه و ساعت‌ها حفظ می‌شوند.

اگر فرزند <code dir="ltr">custom_holidays => []</code> داشته باشد، افزودن اولین تاریخ ممکن است طبق قواعد ارث‌بری تعطیلی‌های پاک‌شده والد را برگرداند. فرمان پیش از موفقیت پیش‌نمایش یا انتشار، نقشه مؤثر پیشنهادی را با نقشه قبلی به‌علاوه فقط درج‌ها و تغییرهای برنامه‌ریزی‌شده مقایسه می‌کند. بازگشت هر تاریخ یا مقدار ناخواسته، خطا می‌دهد؛ حتی force آن را مجاز نمی‌کند. موارد داده که بدون تغییر رد شده‌اند، مجوز بازگشت تاریخ والد نیستند. سیاست ارث‌بری مقصد را اصلاح یا مقصد مناسب‌تری انتخاب کنید؛ خطا هیچ نسخه‌ای را تغییر نمی‌دهد.

برای جزئیات منبع و عمر محاسبه [تنظیمات](configuration.md)، و برای همه گزینه‌های CLI و دسته‌بندی خطاها [مرجع API](api-reference.md) را ببینید.

</div>
