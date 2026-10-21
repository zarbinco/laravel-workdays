<div dir="rtl">

# تنظیمات، تعطیلی‌ها و پروفایل‌ها

[English](../en/configuration.md) · [همه راهنماها](README.md)

هر پروفایل، قواعد یک تقویم کاری را نگه می‌دارد. می‌توانید برای انبار، پشتیبانی و حمل‌کننده تقویم‌های جدا تعریف کنید یا تفاوت‌های آن‌ها را از یک تقویم مشترک به ارث ببرید. برای اولین محاسبه از [مثال کامل شروع کار](getting-started.md) استفاده کنید.

## یک تفاوت محلی و انتخاب پروفایل

پس از آماده‌کردن تقویم شروع کار، این مثال یک پروفایل فرزند و یک قاعده انتخاب می‌سازد:

<!-- example: profile-routing -->

<div dir="ltr">

```php
use Zarbinco\LaravelWorkdays\Facades\Workday;

config([
    'workdays.definitions.profiles.local' => [
        'extends' => 'global',
        'custom_holidays' => ['2026-11-09' => 'Local closure'],
    ],
    'workdays.definitions.profile_routing' => [
        'fallback_profile' => 'global',
        'rules' => [
            ['id' => 'local-service', 'match' => ['region' => 'local'], 'profile' => 'local'],
        ],
    ],
]);

echo Workday::resolveProfile(['region' => 'local'])->profile, PHP_EOL;
echo Workday::profileFor(['region' => 'local'])->nextBusinessDay('2026-11-06')->toDateString(), PHP_EOL;
```

</div>

<div dir="ltr">

```text
local
2026-11-10
```

</div>

فرزند، دوشنبه را تعطیل کرده است؛ پس نتیجه سه‌شنبه می‌شود. هر راهنما را با تنظیم اولیه تازه اجرا کنید تا تغییرهای نمونه قبلی روی آن اثر نگذارد.

## انتخاب منبع تعریف‌ها

مقدار <code dir="ltr">workdays.driver</code> دقیقاً یکی از <code dir="ltr">config</code> یا <code dir="ltr">database</code> است. هر کدام مالک کل سند عملیاتی شامل پروفایل‌ها، تعطیلی‌ها، ساعت کار، مهلت پذیرش و مسیریابی است. منابع با هم ترکیب نمی‌شوند و در خطای Database، پکیج به Config برنمی‌گردد.

در Config، سند زیر <code dir="ltr">workdays.definitions</code> قرار دارد. Database همان ساختار خام را با انتشار صریح ذخیره می‌کند؛ به تعریف فایل به‌عنوان لایه جایگزین مراجعه نمی‌کند. حالت‌های <code dir="ltr">chain</code> و <code dir="ltr">hybrid</code> پشتیبانی نمی‌شوند. کلید قدیمی <code dir="ltr">storage.driver</code> و کلیدهای عملیاتی در ریشه تنظیمات، خطا می‌دهند؛ آن‌ها را به ساختار فعلی منتقل کنید.

| مسیر | نوع و پیش‌فرض | کاربرد |
|---|---|---|
| <code dir="ltr">driver</code> | رشته؛ <code dir="ltr">config</code> | انتخاب تنها منبع عملیاتی. |
| <code dir="ltr">database.connection</code> | رشته غیرخالی یا <code dir="ltr">null</code>؛ پیش‌فرض <code dir="ltr">null</code> | اتصال لاراول برای Database؛ null یعنی اتصال پیش‌فرض. |
| <code dir="ltr">max_scan_days</code> | عدد صحیح مثبت؛ <code dir="ltr">3660</code> | حد ایمنی جست‌وجو؛ گزینه هر API نمی‌تواند آن را افزایش دهد. |
| <code dir="ltr">definitions.default_profile</code> | رشته غیرخالی؛ <code dir="ltr">iran</code> | پروفایل فراخوانی بدون نام صریح. |
| <code dir="ltr">definitions.include_start_date</code> | بولی؛ <code dir="ltr">false</code> | شمارش شروع کاری در حرکت و اختلاف روز. |
| <code dir="ltr">definitions.hijri</code> | <code dir="ltr">method</code> رشته و <code dir="ltr">adjustment</code> عدد صحیح | تنظیم مشترک تبدیل قمری. |
| <code dir="ltr">definitions.profile_routing</code> | نگاشت یا <code dir="ltr">null</code>؛ پیش‌فرض null | انتخاب صریح پروفایل با زمینه برنامه. |
| <code dir="ltr">definitions.profiles</code> | نگاشت نام به تعریف خام | پروفایل‌ها، با ارث‌بری اختیاری. |

تنظیم ثبت ماکرو، اتصال و مسیر فایل محلی ابزار ورود، بیرون سند عملیاتی می‌مانند. برای کلیدهای کامل راه‌اندازی، [مرجع API](api-reference.md) را ببینید. مقدار بولی باید <code dir="ltr">true</code> یا <code dir="ltr">false</code> باشد؛ رشته یا عدد به‌جای آن پذیرفته نمی‌شود.

## تعریف تعطیلی

این قطعه، شکل تنظیم یک پروفایل را نشان می‌دهد؛ آن را به‌عنوان یک ورودی کامل در <code dir="ltr">definitions.profiles</code> قرار دهید. تاریخ‌های دقیق نمونه، تقویم رسمی هیچ سازمانی نیستند.

<div dir="ltr">

```php
'office' => [
    'weekends' => ['Thursday', 'Friday'],
    'holidays' => [
        'gregorian' => ['01-01' => 'New Year'],
        'jalali' => ['01-01' => 'Nowruz'],
        'hijri' => ['01-10' => 'Ashura'],
    ],
    'custom_holidays' => ['2026-11-04' => 'Office closure'],
    'extra_working_days' => ['2026-11-06' => 'Extra opening'],
],
```

</div>

نام روز هفته می‌تواند انگلیسی، کوتاه انگلیسی، فارسی یا عدد صحیح ISO از ۱ برای دوشنبه تا ۷ برای یکشنبه باشد. نام‌های معادل تکراری در یک لایه، مانند <code dir="ltr">Monday</code> و <code dir="ltr">1</code>، خطا هستند. فهرست خالی یعنی هیچ آخر هفته‌ای تعریف نشده است.

کلید <code dir="ltr">weekends</code> پس از حل ارث‌بری باید آرایه باشد؛ اگر آخر هفته ندارید، مقدار <code dir="ltr">[]</code> را صریحاً بنویسید. حذف این کلید از پروفایل ریشه، به‌معنی فهرست خالی نیست.

کلید تعطیلی تکرارشونده، <code dir="ltr">MM-DD</code> در تقویم همان بخش است. فقط نام‌های <code dir="ltr">gregorian</code>، <code dir="ltr">jalali</code> و <code dir="ltr">hijri</code> معتبرند. تاریخ دقیقِ تعطیلی یا روز کاری اضافه باید میلادی معتبر با قالب <code dir="ltr">Y-m-d</code> باشد. عنوان هر دو نوع، رشته یا null است؛ null یعنی بدون عنوان، نه حذف قاعده. روز کاری اضافه بر تعطیلی و آخر هفته اولویت دارد.

روش‌های قمری، <code dir="ltr">mathematical</code>، <code dir="ltr">umm_al_qura</code>، <code dir="ltr">high_judiciary</code> و <code dir="ltr">diyanet</code> هستند؛ پیش‌فرض <code dir="ltr">umm_al_qura</code> با اصلاح صفر است. اصلاح غیرصفر فقط با روش ریاضی مجاز است و در روش دیگر خطا می‌دهد. محدودیت تقویم و اعلام محلی ممکن است نتیجه را با تعطیلی رسمی کشور متفاوت کند. برای تاریخ قطعی، تعطیلی دقیق وارد کنید.

ورودی عمومی روز، تاریخ‌های دقیق و مبدأ تکرار، رشته میلادی می‌گیرند. کلید شمسی تعطیلی با تبدیل Verta بررسی می‌شود؛ تکرار ماهانه هم می‌تواند از حساب ماه شمسی استفاده کند. قمری برای تعطیلی کاربرد دارد و تکرار ماهانه قمری ارائه نمی‌شود. [راهنمای تکرار](recurring-schedules.md) این تفاوت را توضیح می‌دهد.

## ارث‌بری با extends

فرزند با <code dir="ltr">extends</code> به نام والد اشاره می‌کند. زنجیره حداکثر ۱۶ پروفایل، شامل ریشه و فرزند انتخابی، دارد. والد ناموجود، چرخه، ارجاع به خود یا عمق بیشتر خطا می‌دهد. فقط پروفایل مورد نیاز و والدهایش هنگام محاسبه حل می‌شوند؛ انتشار Database همه سند را بررسی می‌کند.

کلیدی که ننویسید ارث می‌رسد؛ مقدار صریح طبق جدول اعمال می‌شود:

| بخش | قاعده ترکیب |
|---|---|
| <code dir="ltr">weekends</code> | کل فهرست جایگزین می‌شود؛ خالی، آخر هفته را پاک می‌کند. |
| <code dir="ltr">holidays</code> | برحسب تقویم و سپس کلید ترکیب می‌شود. نگاشت خالی یک تقویم همان تقویم، و بخش کاملاً خالی همه تعطیلی‌های تکرارشونده والد را پاک می‌کند. |
| <code dir="ltr">custom_holidays</code>، <code dir="ltr">extra_working_days</code>، <code dir="ltr">special_working_hours</code> | برحسب تاریخ ترکیب می‌شوند؛ مقدار تاریخ فرزند کامل جای والد را می‌گیرد. بخش خالی همه موارد ارث‌رسیده همان بخش را پاک می‌کند. |
| <code dir="ltr">working_hours</code> | روز هفته نرمال می‌شود و کل فهرست پنجره‌های آن روز جایگزین می‌شود. روز خالی همان روز و بخش خالی کل برنامه هفتگی والد را پاک می‌کند. |
| <code dir="ltr">extra_working_day_hours</code> | کل فهرست، حتی فهرست خالی، جایگزین می‌شود. |
| <code dir="ltr">seasonal_working_hours</code> | کل فهرست مرتب جایگزین می‌شود؛ خالی فصل‌های والد را پاک می‌کند. |
| <code dir="ltr">allow_overnight_working_hours</code> | مقدار بولی صریح جایگزین می‌شود؛ غیرفعال‌کردن آن با ساعت شبانه ارث‌رسیده ناسازگار است. |
| <code dir="ltr">cutoff</code> | کلیدهای سیاست ترکیب می‌شوند؛ تغییرهای روز هفته برحسب روز نرمال‌شده ترکیب می‌شوند. null صریح یک روز را غیرفعال می‌کند و نگاشت خالی روزها، تغییرهای والد را پاک می‌کند. |
| دیگر فیلدها | مقدار صریح کامل جایگزین می‌شود؛ ادغام بازگشتی دلخواه انجام نمی‌شود. |

مثلاً <code dir="ltr">custom_holidays => []</code> با ننوشتن کلید تفاوت دارد. افزودن اولین عضو به این نقشه خالی می‌تواند دوباره ادغام با والد را فعال کند؛ [محافظت ورود تقویم ایران](database.md) جلوی بازگشت ناخواسته تعطیلی‌های پاک‌شده را می‌گیرد. تنظیم مشترک قمری، پروفایل پیش‌فرض و مسیریابی جزو سند هستند و از والد پروفایل ارث نمی‌رسند.

## انتخاب پروفایل با زمینه برنامه

این قطعه شکل مسیریابی را نشان می‌دهد؛ نام‌های هدف باید در همان سند موجود باشند. پروفایل <code dir="ltr">tehran</code> را پیش از استفاده تعریف کنید.

<div dir="ltr">

```php
'profile_routing' => [
    'fallback_profile' => 'global',
    'rules' => [
        ['id' => 'warehouse', 'match' => ['kind' => 'warehouse'], 'profile' => 'global'],
        ['id' => 'local', 'match' => ['kind' => 'warehouse', 'region' => 'tehran'], 'profile' => 'tehran'],
    ],
],
```

</div>

برنامه زمینه را به <code dir="ltr">Workday::resolveProfile($context)</code> می‌دهد. نتیجه <code dir="ltr">ResolvedProfile</code> شامل <code dir="ltr">profile</code>، <code dir="ltr">source</code> و <code dir="ltr">ruleId</code> است؛ کلید آرایه آخر <code dir="ltr">rule_id</code> خواهد بود. منبع انتخاب <code dir="ltr">rule</code> یا <code dir="ltr">fallback</code> است. برای دریافت مستقیم محاسبه‌گر از <code dir="ltr">Workday::profileFor($context)</code> استفاده کنید.

کلیدهای زمینه و شرط باید شناسه ASCII با الگوی <code dir="ltr">[A-Za-z_][A-Za-z0-9_]*</code> باشند. مقدار، عدد صحیح یا رشته غیرخالی است؛ مقدارهای اعشاری، بولی، آرایه و null رد می‌شوند. تطبیق دقیق نوع هم دارد؛ <code dir="ltr">42</code> با <code dir="ltr">'42'</code> برابر نیست. قاعده‌ای با شرط‌های بیشتر اولویت دارد؛ در تساوی، قاعده زودتر در فهرست انتخاب می‌شود. زمینه خالی یا نبود تطبیق، fallback صریح را انتخاب می‌کند. اگر مسیریابی تنظیم نشده باشد، این دو متد خطا می‌دهند؛ نام پیش‌فرض پنهانی انتخاب نمی‌شود.

مسیریابی به مدل‌های برنامه، شناسه انبار، منطقه یا منطقه زمانی دسترسی خودکار ندارد. <code dir="ltr">profile($name)</code> هم مسیریابی را فعال نمی‌کند. نام پروفایل انتخابی و منطقه‌های زمانی لازم را صریح به API تحویل بدهید.

## تغییر تنظیمات هنگام اجرا

هر محاسبه با تنظیمات زمان شروع خودش انجام می‌شود. تغییرات بعدی روی محاسبه‌های جدید اثر می‌گذارند، نه محاسبه‌ای که در حال اجراست.

محاسبه‌گری که از <code dir="ltr">profile()</code> یا <code dir="ltr">profileFor()</code> نگه می‌دارید هم نمای اولیه خود را حفظ می‌کند؛ فراخوانی متد دیگری روی همان شیء، آن را تازه نمی‌کند. برای دیدن ویرایش یا انتشار بعدی، محاسبه‌گر یا عملیات تازه بگیرید. این قاعده با [نسخه قابل ذخیره برآورد](snapshots.md) متفاوت است؛ نمای عملیات به‌تنهایی سند قابل ذخیره تحویل نیست.

</div>
