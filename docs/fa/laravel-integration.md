<div dir="rtl">

# اتصال به لاراول، Carbon و اعتبارسنجی

[English](../en/laravel-integration.md) · [همه راهنماها](README.md)

Facade و محاسبه‌گر را در سرویس برنامه استفاده کنید؛ برای اعتبارسنجی فرم، قواعد آماده و برای شیءهای Carbon، ماکروها در دسترس‌اند. ابتدا [نصب و تنظیم تقویم](getting-started.md) را انجام دهید.

## یک فراخوانی از Carbon

نمونه زیر به پروفایل صریح مثال شروع کار و فعال‌بودن ماکروها نیاز دارد:

<!-- example: carbon-macro -->

<div dir="ltr">

```php
use Carbon\CarbonImmutable;

$date = CarbonImmutable::parse('2026-11-06T00:00:00Z');
echo $date->workdayNextBusinessDay('global')->toDateString(), PHP_EOL;
echo $date->toDateString(), PHP_EOL;
```

</div>

<div dir="ltr">

```text
2026-11-09
2026-11-06
```

</div>

ماکرو روی <code dir="ltr">Carbon</code> و <code dir="ltr">CarbonImmutable</code> ثبت می‌شود. نتیجه تاریخ، نوع قابل تغییر یا تغییرناپذیر گیرنده را حفظ می‌کند، اما خود گیرنده تغییر نمی‌کند. نسخه‌های دارای پیشوند، احتمال تداخل نام کمتری دارند.

## نام‌های ماکرو و تنظیم ثبت

| گروه | نام‌ها |
|---|---|
| دسته‌بندی روز | <code dir="ltr">workdayIsBusinessDay</code>، <code dir="ltr">workdayIsNonWorkingDay</code>، <code dir="ltr">workdayIsHoliday</code>، <code dir="ltr">workdayIsWeekend</code>، <code dir="ltr">workdayIsCalendarHoliday</code>، <code dir="ltr">workdayIsExtraWorkingDay</code> |
| حرکت روز | <code dir="ltr">workdayNextBusinessDay</code>، <code dir="ltr">workdayPreviousBusinessDay</code>، <code dir="ltr">workdayAddBusinessDays</code>، <code dir="ltr">workdaySubBusinessDays</code>، <code dir="ltr">workdayDiffBusinessDaysUntil</code>، <code dir="ltr">workdayExplain</code> |
| زمان | <code dir="ltr">workdayIsBusinessTime</code>، <code dir="ltr">workdayWorkingWindows</code>، <code dir="ltr">workdayNextBusinessTime</code>، <code dir="ltr">workdayPreviousBusinessTime</code>، <code dir="ltr">workdayAddBusinessMinutes</code>، <code dir="ltr">workdayAddBusinessHours</code>، <code dir="ltr">workdayDiffBusinessMinutesUntil</code>، <code dir="ltr">workdayDiffBusinessHoursUntil</code> |

پارامتر اختیاری نام پروفایل در انتهای آرگومان‌های ماکرو می‌آید. بدون آن، پروفایل پیش‌فرض استفاده می‌شود. تنظیم <code dir="ltr">carbon_macros.enabled</code> پیش‌فرض true ثبت را کنترل می‌کند. <code dir="ltr">short_aliases</code> پیش‌فرض true، نام‌های کوتاه مانند <code dir="ltr">addBusinessDays()</code> و <code dir="ltr">explainWorkday()</code> را هم ثبت می‌کند.

برای جلوگیری از اشتباه با متدهای Carbon، نام کوتاه تعطیلی <code dir="ltr">isWorkdayHoliday()</code>، آخر هفته <code dir="ltr">isWorkdayWeekend()</code> و پنجره‌ها <code dir="ltr">workingWindowsForWorkday()</code> است. بقیه نام‌های کوتاه از نام API اصلی و برای اختلاف از پسوند <code dir="ltr">Until</code> استفاده می‌کنند. <code dir="ltr">override_existing</code> پیش‌فرض false، ماکروی موجود را حفظ می‌کند. متد بومی Carbon حتی با true بازنویسی نمی‌شود؛ برای کنترل روشن، نام دارای پیشوند را انتخاب کنید.

## قواعد اعتبارسنجی

برای اجرای مستقل یک قاعده، تقویم شروع کار را تازه تنظیم کنید:

<!-- example: validation-rule -->

<div dir="ltr">

```php
use Illuminate\Support\Facades\Validator;
use Zarbinco\LaravelWorkdays\Rules\WorkdayRule;

$validator = Validator::make(
    ['delivery_date' => '2026-11-09'],
    ['delivery_date' => ['required', WorkdayRule::businessDay('global')]],
);
echo json_encode($validator->passes(), JSON_THROW_ON_ERROR), PHP_EOL;
```

</div>

<div dir="ltr">

```text
true
```

</div>


این نمونه، طرح اتصال به فرم است؛ متغیر request از برنامه لاراول شما می‌آید و پیش‌نیاز تقویم همچنان برقرار است:

<div dir="ltr">

```php
use Zarbinco\LaravelWorkdays\Rules\WorkdayRule;

$request->validate([
    'delivery_date' => ['required', WorkdayRule::businessDay('global')],
    'appointment_at' => ['required', WorkdayRule::businessTime('global')],
    'due_date' => ['required', WorkdayRule::afterBusinessDays(3, '2026-11-02', 'global')],
]);
```

</div>

Factoryهای <code dir="ltr">WorkdayRule</code> برای روز عبارت‌اند از <code dir="ltr">businessDay()</code>، <code dir="ltr">nonWorkingDay()</code>، <code dir="ltr">weekend()</code>، <code dir="ltr">calendarHoliday()</code>، <code dir="ltr">customHoliday()</code>، <code dir="ltr">extraWorkingDay()</code>، <code dir="ltr">notBusinessDay()</code>، <code dir="ltr">notWeekend()</code> و <code dir="ltr">notCalendarHoliday()</code>. دو قاعده زمان <code dir="ltr">businessTime()</code> و <code dir="ltr">notBusinessTime()</code> هستند و ساعت کاری معتبر می‌خواهند.

دو قاعده نسبی <code dir="ltr">afterBusinessDays($days, $from = null, $profile = null)</code> و <code dir="ltr">beforeBusinessDays($days, $from = null, $profile = null)</code>، تاریخ را با مقصد جابه‌جایی روز کاری مقایسه می‌کنند و خود مقصد را هم می‌پذیرند. حذف from از تاریخ امروز در زمان اعتبارسنجی استفاده می‌کند؛ در تست و عملیات قابل تکرار، آن را صریح بدهید. تعداد باید نامنفی باشد. با زنجیره <code dir="ltr">-&gt;message('Please choose a working day.')</code> می‌توانید پیام را عوض کنید.

ورودی نامعتبر یا نبود ساعت لازم، پیام ناموفق اعتبارسنجی می‌دهد. خطای منبع <code dir="ltr">DefinitionSourceException</code> منتقل می‌شود؛ خرابی زیرساخت نباید به خطای کاربر فرم تبدیل شود. اعتبارسنجی روز، ظرفیت، موجودی یا رزرو را بررسی نمی‌کند.

## انتخاب تقویم در سرویس برنامه

با <code dir="ltr">Workday::profile($name)</code> محاسبه‌گر بگیرید یا <code dir="ltr">WorkdayManager</code> را از container دریافت کنید. <code dir="ltr">resolveProfile($context)</code> نام و دلیل انتخاب و <code dir="ltr">profileFor($context)</code> محاسبه‌گر همان انتخاب را می‌دهد. زمینه مجاز را خود برنامه از درخواست احرازشده بسازد؛ پکیج مدل یا tenant را پیدا نمی‌کند.

محاسبه‌گر نگه‌داشته‌شده با انتشار جدید خودکار تازه نمی‌شود. در worker طولانی‌عمر، برای عملیات جدیدی که باید تقویم تازه ببیند، محاسبه‌گر تازه درخواست کنید. برای یک مجموعه مرتبط، [محاسبات گروهی](bulk-calculations.md) یک نمای ثابت مشترک می‌دهد.

اجرای دوره‌ای و اعلان SLA یا اشتراک را با صف و زمان‌بند برنامه پیاده کنید. پکیج [تاریخ دوره‌ها](recurring-schedules.md) و [نتیجه SLA](sla.md) را محاسبه می‌کند؛ سابقه اجرا، سیاست تلاش دوباره و جلوگیری از اجرای تکراری با برنامه است.

برای امضای متدهای اصلی، گزینه‌های راه‌اندازی و تفاوت خطای ورودی و منبع، [مرجع API](api-reference.md) را ببینید.

</div>
