<div dir="rtl">

# مرجع API، ورودی‌ها و خطاها

[English](../en/api-reference.md) · [همه راهنماها](README.md)

این صفحه برای پیدا کردن امضا و قرارداد مشترک است. برای مثال کامل، از [شروع کار](getting-started.md) یا راهنمای قابلیت مربوط وارد شوید. Facade در فضای نام <code dir="ltr">Zarbinco\LaravelWorkdays\Facades\Workday</code> و manager در <code dir="ltr">Zarbinco\LaravelWorkdays\WorkdayManager</code> قرار دارد.

## متدهای عمومی

متدهای روز و زمان هم روی manager با پروفایل پیش‌فرض و هم روی <code dir="ltr">BusinessDayCalculator</code> برگشتی از profile موجودند. عملیات برنامه‌ریزی، مقایسه، SLA، تکرار و گروهی روی manager و Facade هستند.

| متد و آرگومان | نوع نتیجه | راهنمای قرارداد |
|---|---|---|
| <code dir="ltr">profile(string $profile)</code>، <code dir="ltr">profileFor(array $context)</code> | <code dir="ltr">BusinessDayCalculator</code> | [تنظیمات](configuration.md) |
| <code dir="ltr">resolveProfile(array $context)</code> | <code dir="ltr">ResolvedProfile</code> | [تنظیمات](configuration.md) |
| <code dir="ltr">explain($date)</code> | <code dir="ltr">DayInfo</code> | [روزهای کاری](business-days.md) |
| <code dir="ltr">isBusinessDay/isHoliday/isNonWorkingDay/isWeekend/isCalendarHoliday/isGregorianHoliday/isJalaliHoliday/isHijriHoliday/isCustomHoliday/isExtraWorkingDay($date)</code> | بولی، یک متد جدا برای هر نام | [روزهای کاری](business-days.md) |
| <code dir="ltr">nextBusinessDay($date)</code>، <code dir="ltr">previousBusinessDay($date)</code> | <code dir="ltr">CarbonImmutable</code> | [روزهای کاری](business-days.md) |
| <code dir="ltr">addBusinessDays($date, int $days)</code>، <code dir="ltr">subBusinessDays($date, int $days)</code> | <code dir="ltr">CarbonImmutable</code> | [روزهای کاری](business-days.md) |
| <code dir="ltr">diffBusinessDays($start, $end)</code> | عدد صحیح علامت‌دار | [روزهای کاری](business-days.md) |
| <code dir="ltr">calculate($date, int $businessDays)</code> | <code dir="ltr">BusinessDayResult</code> | [روزهای کاری](business-days.md) |
| <code dir="ltr">isBusinessTime($datetime)</code> | بولی | [ساعت کاری](working-hours.md) |
| <code dir="ltr">workingWindowsFor($date)</code> | آرایه <code dir="ltr">TimeWindow</code> | [ساعت کاری](working-hours.md) |
| <code dir="ltr">nextBusinessTime($datetime)</code>، <code dir="ltr">previousBusinessTime($datetime)</code> | <code dir="ltr">CarbonImmutable</code> | [ساعت کاری](working-hours.md) |
| <code dir="ltr">addBusinessMinutes($datetime, int $minutes)</code>، <code dir="ltr">addBusinessHours($datetime, int&#124;float $hours)</code> | <code dir="ltr">CarbonImmutable</code> | [ساعت کاری](working-hours.md) |
| <code dir="ltr">diffBusinessMinutes($start, $end)</code>، <code dir="ltr">diffBusinessHours($start, $end)</code> | عدد صحیح دقیقه، اعشاری ساعت | [ساعت کاری](working-hours.md) |
| <code dir="ltr">resolveCutoff($receivedAt)</code> | <code dir="ltr">CutoffResult</code> | [تحویل](delivery.md) |
| <code dir="ltr">planDelivery($receivedAt, array $plan)</code> | <code dir="ltr">DeliveryPlanResult</code> | [تحویل](delivery.md) |
| <code dir="ltr">reversePlanDelivery($targetAt, array $plan, array $options)</code> | <code dir="ltr">ReverseDeliveryPlanResult</code> | [تحویل](delivery.md) |
| <code dir="ltr">deliverySlots($readyAt, array $options)</code> | <code dir="ltr">DeliverySlotsResult</code> | [بازه‌ها](delivery-slots.md) |
| <code dir="ltr">evaluateSla($startedAt, $evaluatedAt, array $options)</code> | <code dir="ltr">SlaResult</code> | [SLA](sla.md) |
| <code dir="ltr">recurringSchedule($anchorDate, array $options)</code> | <code dir="ltr">RecurringScheduleResult</code> | [تکرار](recurring-schedules.md) |
| <code dir="ltr">captureDeliveryPromise($receivedAt, array $plan, array $options)</code> | <code dir="ltr">DeliveryPromiseSnapshot</code> | [نسخه برآورد](snapshots.md) |
| <code dir="ltr">compareDeliveryPromise(DeliveryPromiseSnapshot&#124;array $snapshot)</code> | <code dir="ltr">DeliveryPromiseComparison</code> | [نسخه برآورد](snapshots.md) |
| <code dir="ltr">bulkCalculate(array $operations, array $options = [])</code> | <code dir="ltr">BulkCalculationResult</code> | [محاسبه گروهی](bulk-calculations.md) |

پارامترهای تاریخ و زمان جدول، از نوع <code dir="ltr">string|DateTimeInterface</code> هستند. نتیجه‌های داده در فضای نام <code dir="ltr">Zarbinco\LaravelWorkdays\Data</code> هستند، به‌جز <code dir="ltr">BusinessDayResult</code> در <code dir="ltr">Calculator</code>. جزئیات همه فیلدها و گزینه‌ها در راهنمای همان سطر آمده است؛ نتیجه‌های داده متد <code dir="ltr">toArray()</code> با کلید snake_case دارند.

## تاریخ، زمان و تقویم

API روزمحور رشته میلادی معتبر <code dir="ltr">Y-m-d</code> یا تاریخ محلی شیء ورودی را می‌گیرد و به شروع روز نرمال می‌کند. عبارت نسبی و تاریخ ناممکن پذیرفته نیست. تکرار هم رشته دقیق میلادی می‌گیرد؛ انتخاب jalali فقط حساب ماه را تغییر می‌دهد، نه قالب ورودی عمومی یا تاریخ خروجی. تعطیلی تکرارشونده با <code dir="ltr">MM-DD</code> در تقویم بخش خودش تعریف می‌شود.

API هسته زمان، رشته‌ای که Carbon می‌تواند تجزیه کند یا شیء تاریخ می‌گیرد؛ رشته بدون منطقه با تنظیم پیش‌فرض برنامه و شیء با منطقه خودش تفسیر می‌شود. قرارداد آن را با parser سخت‌گیرانه APIهای پیشرفته یکی نگیرید. برای رفتار قابل تکرار، <code dir="ltr">CarbonImmutable</code> با تاریخ مطلق و منطقه صریح بدهید.

ورودی زمانی cutoff، تحویل، معکوس، بازه و SLA باید مطلق باشد: <code dir="ltr">Y-m-d H:i[:s[.microseconds]]</code> با فاصله و بدون offset، یا ISO با <code dir="ltr">T</code> و <code dir="ltr">Z</code> یا <code dir="ltr">±HH:MM</code>. بخش اعشاری ۱ تا ۶ رقم دارد و به ثانیه نیاز دارد. ورودی بدون offset در منطقه صریح API تفسیر می‌شود؛ ورودی offsetدار یا شیء تاریخ، لحظه واقعی را حفظ می‌کند. تاریخ تنها، عبارت نسبی، offset بدشکل و ساعت محلی ناموجود رد می‌شوند. بیشتر APIهای پیشرفته ساعت ساده مبهم را هم رد می‌کنند؛ برای انتخاب رخداد تکراری offset صریح بدهید. cutoff مستقل و ساعت مقصد مرحله business_days استثنای انتخاب PHP/Carbon دارند؛ [تحویل](delivery.md) جزئیات را دارد.

نمونه قالب داده، برای خواندن ساختار است و برنامه مستقل نیست:

<div dir="ltr">

```json
{
  "profile": "global",
  "date": "2026-11-02",
  "is_business_day": true
}
```

</div>

زمان‌ها در آرایه نتیجه‌های پیشرفته معمولاً UTC با قالب <code dir="ltr">Y-m-d\TH:i:s.uP</code> هستند. cutoff نمایش محلی سیاست را نگه می‌دارد؛ bulk offset واقعی نتیجه را حفظ می‌کند؛ بازه علاوه بر UTC، <code dir="ltr">local_starts_at</code> و <code dir="ltr">local_ends_at</code> دارد. تاریخ تکرار رشته مدنی است. ورودی قابل تغییر تغییر نمی‌کند.

## تنظیمات راه‌اندازی و فرمان‌ها

تنظیم‌های <code dir="ltr">carbon_macros</code> شامل enabled و short_aliases با پیش‌فرض true و override_existing با پیش‌فرض false هستند. <code dir="ltr">iran_official</code> شامل enabled با پیش‌فرض false و year، profile و calendar_path با پیش‌فرض null است. مسیر، داده محلی مورد اعتماد را مشخص می‌کند؛ dataset را دریافت یا خودکار فعال نمی‌کند. تنظیم منبع و سند در [تنظیمات](configuration.md) آمده است.

| فرمان | گزینه‌های معتبر |
|---|---|
| <code dir="ltr">workdays:install</code> | <code dir="ltr">--preset=iran</code>، <code dir="ltr">--persian</code>، <code dir="ltr">--force</code> برای فایل موجود، <code dir="ltr">--driver=config&#124;database</code> با پیش‌فرض config |
| <code dir="ltr">workdays:definitions:export path</code> | <code dir="ltr">--source=config&#124;database</code> الزامی؛ <code dir="ltr">--revision=id</code> فقط Database؛ <code dir="ltr">--force</code> فقط بازنویسی فایل مقصد |
| <code dir="ltr">workdays:definitions:import path</code> | دقیقاً یکی از <code dir="ltr">--initialize</code> یا <code dir="ltr">--expected-revision=id</code>؛ <code dir="ltr">--dry-run</code> اختیاری |
| <code dir="ltr">workdays:import-iran-calendar year</code> | <code dir="ltr">--profile=iran</code> پیش‌فرض؛ <code dir="ltr">--calendar-path=path</code>، <code dir="ltr">--dry-run</code>، <code dir="ltr">--expected-revision=id</code> برای نوشتن، <code dir="ltr">--force</code> فقط تعطیلی متعارض |

برچسب‌های انتشار <code dir="ltr">workdays-config</code>، <code dir="ltr">workdays-config-iran</code> و <code dir="ltr">workdays-migrations</code> هستند. نصب، migration یا انتشار اولیه را اجرا نمی‌کند و فایل محیط را تغییر نمی‌دهد. نمونه عملی و محدودیت force در [راهنمای Database](database.md) است.

## دسته‌بندی خطا

| نوع | معنی و اقدام |
|---|---|
| <code dir="ltr">InvalidArgumentException</code> | تاریخ، زمان، مدت یا ترتیب ورودی نامعتبر؛ برخی اعتبارسنجی‌های هسته تنظیمات نیز از این نوع‌اند. ورودی و پیام را بررسی کنید. |
| <code dir="ltr">WorkdayConfigurationException</code> | شکل گزینه، برنامه، ساعت، routing یا قابلیت درخواست‌شده نامعتبر؛ زیرکلاس InvalidArgumentException است. |
| <code dir="ltr">DefinitionConflictException</code> | شرط نسخه انتشار قدیمی است؛ نسخه تازه را بخوانید و تعارض را حل کنید. |
| <code dir="ltr">DefinitionSourceException</code> | منبع باز یا خوانده نمی‌شود، schema آماده نیست یا داده ذخیره‌شده ناسازگار است؛ به fallback یا نتیجه بسته تبدیل نکنید. |
| <code dir="ltr">WorkingTimeSearchException</code> یا <code dir="ltr">RuntimeException</code> | افق، ظرفیت زمانی یا محدوده قابل نمایش کافی نیست؛ API پیشرفته ممکن است اطلاعات مرحله را به خطا بیفزاید. |

خطای غیرمنتظره برنامه‌نویسی و زیرساخت منتقل می‌شود. شکست ورودی snapshot با تغییر عادی تقویم و نتیجه معکوس ناممکن تفاوت دارد. برای اقدام عملی [رفع اشکال](troubleshooting.md) را بخوانید.

</div>
