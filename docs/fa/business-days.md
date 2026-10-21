<div dir="rtl">

# روزهای کاری، تعطیلی و توضیح نتیجه

[English](../en/business-days.md) · [همه راهنماها](README.md)

از این APIها برای سررسید فاکتور، مهلت مرخصی یا هر محاسبه‌ای استفاده کنید که به تاریخ کاری وابسته است. ابتدا [تقویم نمونه](getting-started.md) را آماده کنید. تمام رشته‌های تاریخ این بخش، میلادی و با قالب <code dir="ltr">Y-m-d</code> هستند.

## محاسبه همراه با تاریخ‌های کنارگذاشته‌شده

متد <code dir="ltr">calculate()</code> همان جابه‌جایی روز کاری را همراه با اطلاعات مسیر محاسبه برمی‌گرداند.

<!-- example: day-results -->

<div dir="ltr">

```php
use Zarbinco\LaravelWorkdays\Facades\Workday;

config()->set('workdays.definitions.profiles.global.custom_holidays', ['2026-11-10' => 'Inventory']);
config()->set('workdays.definitions.profiles.global.holidays', ['gregorian' => ['11-11' => 'Annual closure']]);
config()->set('workdays.definitions.profiles.global.extra_working_days', ['2026-11-14' => 'Extra Saturday']);

$calendar = Workday::profile('global');
echo json_encode($calendar->calculate('2026-11-06', 3)->toArray(), JSON_THROW_ON_ERROR), PHP_EOL;
echo json_encode([
    $calendar->isWeekend('2026-11-14'),
    $calendar->isCalendarHoliday('2026-11-14'),
    $calendar->isCustomHoliday('2026-11-14'),
    $calendar->isHoliday('2026-11-14'),
], JSON_THROW_ON_ERROR), PHP_EOL;
```

</div>

<div dir="ltr">

```text
{"start_date":"2026-11-06","result_date":"2026-11-13","requested_business_days":3,"calendar_days":7,"skipped_dates":["2026-11-07","2026-11-08","2026-11-10","2026-11-11"],"profile":"global"}
[true,false,false,false]
```

</div>

سه روز کاری شمرده‌شده، نهم، دوازدهم و سیزدهم نوامبر هستند. دو روز آخر هفته، تعطیلی دقیق دهم و تعطیلی تکرارشونده یازدهم در مسیر کنار گذاشته می‌شوند. <code dir="ltr">skipped_dates</code> تاریخ‌های غیرکاری بررسی‌شده در مسیر را نگه می‌دارد؛ این فهرست تقویم کامل تعطیلی‌های یک ماه یا علت تعطیلی هر تاریخ نیست.

خروجی <code dir="ltr">BusinessDayResult</code> ویژگی‌های زیر را دارد:

| ویژگی PHP | کلید <code dir="ltr">toArray()</code> | معنی |
|---|---|---|
| <code dir="ltr">startDate</code> | <code dir="ltr">start_date</code> | تاریخ شروع از نوع <code dir="ltr">CarbonImmutable</code>. |
| <code dir="ltr">resultDate</code> | <code dir="ltr">result_date</code> | تاریخ حاصل، از همان نوع. |
| <code dir="ltr">requestedBusinessDays</code> | <code dir="ltr">requested_business_days</code> | تعداد روز کاری درخواستی. |
| <code dir="ltr">calendarDays</code> | <code dir="ltr">calendar_days</code> | فاصله تقویمی شروع و نتیجه. |
| <code dir="ltr">skippedDates</code> | <code dir="ltr">skipped_dates</code> | فهرست مرتب تاریخ‌های غیرکاری کنارگذاشته‌شده. |
| <code dir="ltr">profile</code> | <code dir="ltr">profile</code> | نام پروفایل. |

تاریخ‌ها در آرایه به رشته میلادی تبدیل می‌شوند. این شیء تغییرناپذیر است؛ برای دلیل هر تاریخ از <code dir="ltr">explain()</code> استفاده کنید.

## روز غیرکاری با تعطیلی تقویمی چه فرقی دارد؟

<code dir="ltr">isHoliday()</code> و <code dir="ltr">isNonWorkingDay()</code> وضعیت نهایی غیرکاری را می‌گویند. آخر هفته، تعطیلی تکرارشونده یا تعطیلی دقیق می‌تواند تاریخ را ببندد. <code dir="ltr">extra_working_days</code> بر همه آن‌ها اولویت دارد و تاریخ را دوباره کاری می‌کند.

متدهای دسته‌بندی، وجود قاعده مربوط را می‌گویند و الزاماً با وضعیت نهایی یکی نیستند:

| متد | چه چیزی را بررسی می‌کند؟ |
|---|---|
| <code dir="ltr">isBusinessDay()</code> | وضعیت نهایی کاری، پس از اولویت روز کاری اضافه. |
| <code dir="ltr">isWeekend()</code> | عضویت روز هفته در فهرست آخر هفته. |
| <code dir="ltr">isCalendarHoliday()</code> | تعطیلی تکرارشونده میلادی، شمسی یا قمری؛ تعطیلی دقیق و آخر هفته را شامل نمی‌شود. |
| <code dir="ltr">isGregorianHoliday()</code>، <code dir="ltr">isJalaliHoliday()</code>، <code dir="ltr">isHijriHoliday()</code> | فقط تعطیلی تکرارشونده همان تقویم. |
| <code dir="ltr">isCustomHoliday()</code> | وجود تاریخ در تعطیلی‌های دقیق. |
| <code dir="ltr">isExtraWorkingDay()</code> | وجود تاریخ در روزهای کاری اضافه. |

پس روز شنبه‌ای که با روز کاری اضافه باز شده، همچنان <code dir="ltr">isWeekend() === true</code> دارد، اما <code dir="ltr">isHoliday() === false</code> است. عنوان <code dir="ltr">null</code> برای تعطیلی، قاعده را حذف نمی‌کند.

## علت نتیجه با DayInfo و DayReason

<code dir="ltr">explain($date)</code> یک <code dir="ltr">DayInfo</code> شامل <code dir="ltr">date</code>، <code dir="ltr">profile</code>، همه نشانگرهای جدول بالا و <code dir="ltr">reasons</code> برمی‌گرداند. نام نشانگر وضعیت نهایی غیرکاری <code dir="ltr">isNonWorkingDay</code> است. <code dir="ltr">toArray()</code> نام‌ها را به <code dir="ltr">snake_case</code> تبدیل می‌کند.

هر <code dir="ltr">DayReason</code> دارای <code dir="ltr">type</code>، <code dir="ltr">title</code>، <code dir="ltr">source</code>، <code dir="ltr">calendar</code>، <code dir="ltr">key</code>، <code dir="ltr">overridden</code> و <code dir="ltr">overriddenBy</code> است. مقدارهای آخر مشخص می‌کنند که روز کاری اضافه، دلیل تعطیلی را کنار زده است؛ دلیل اولیه برای نمایش و عیب‌یابی حفظ می‌شود. در آرایه، کلید آخر <code dir="ltr">overridden_by</code> است.

## حرکت و اختلاف تاریخ

- <code dir="ltr">nextBusinessDay($date)</code> و <code dir="ltr">previousBusinessDay($date)</code> به‌صورت اکید بعد یا قبل از ورودی جست‌وجو می‌کنند، حتی اگر خود ورودی کاری باشد.
- <code dir="ltr">addBusinessDays($date, $days)</code> رو به جلو و <code dir="ltr">subBusinessDays($date, $days)</code> رو به عقب حرکت می‌کنند. تعداد باید عدد صحیح نامنفی باشد؛ مقدار منفی بدهید خطا می‌گیرید. صفر همان تاریخ نرمال‌شده را می‌دهد.
- <code dir="ltr">calculate($date, $businessDays)</code> جابه‌جایی رو به جلو را با اطلاعات مسیر می‌دهد.
- <code dir="ltr">diffBusinessDays($start, $end)</code> تعداد روزهای کاری تا انتهای بازه را حساب می‌کند. با تنظیم پیش‌فرض، ابتدا خارج و انتها داخل است؛ جهت معکوس عدد منفی می‌دهد.

پیش‌فرض <code dir="ltr">definitions.include_start_date</code> برابر <code dir="ltr">false</code> است. با <code dir="ltr">true</code>، شروع کاری در جابه‌جایی مثبت و اختلاف شمرده می‌شود؛ شروع غیرکاری در مسیر <code dir="ltr">calculate()</code> به تاریخ‌های کنارگذاشته‌شده اضافه می‌شود. برای اختلاف دو تاریخ برابر، نتیجه پیش‌فرض صفر و در حالت شامل‌شدن شروع کاری یک است. این گزینه رفتار «روز بعد/قبل» یا شمارش روزهای حمل در برنامه تحویل را عوض نمی‌کند.

جابه‌جایی و جست‌وجوی روز کاری به <code dir="ltr">max_scan_days</code> محدود هستند و در نبود مقصد، <code dir="ltr">RuntimeException</code> می‌دهند. اختلاف روز، شمارش دو تاریخ داده‌شده است و جست‌وجوی مقصد جدیدی انجام نمی‌دهد؛ آن را با افق مستقل APIهای زمان و تحویل یکی نگیرید.

ورودی <code dir="ltr">DateTimeInterface</code> به تاریخ آغاز همان روز تبدیل می‌شود و شیء اصلی تغییر نمی‌کند. زمان روز در محاسبات این بخش استفاده نمی‌شود. برای دقیقه و ساعت از [ساعت کاری](working-hours.md) و برای تنظیم انواع تعطیلی از [تنظیمات](configuration.md) استفاده کنید.

نوع آرگومان‌ها و دسته‌بندی خطاها در [مرجع API](api-reference.md) آمده است.

</div>
