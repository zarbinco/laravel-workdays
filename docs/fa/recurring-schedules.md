<div dir="rtl">

# برنامه‌های تکرارشونده و دوره اشتراک

[English](../en/recurring-schedules.md) · [همه راهنماها](README.md)

برای ساخت تاریخ دوره‌های اشتراک، سرویس یا صدور صورتحساب از <code dir="ltr">recurringSchedule()</code> استفاده کنید. هر دوره از مبدأ اصلی ساخته می‌شود و سپس طبق تقویم کاری جابه‌جا یا رد می‌شود. این API فقط تاریخ می‌سازد؛ اجرای کار، پرداخت و اعلان به برنامه شما تعلق دارد.

## سه تاریخ هفتگی

با [تقویم نمونه](getting-started.md) اجرا کنید:

<!-- example: recurring-schedule -->

<div dir="ltr">

```php
use Zarbinco\LaravelWorkdays\Facades\Workday;

$result = Workday::recurringSchedule('2026-11-06', [
    'profile' => 'global',
    'timezone' => 'UTC',
    'frequency' => 'weekly',
    'interval' => 1,
    'count' => 3,
    'adjustment' => 'next',
]);
foreach ($result->occurrences as $occurrence) {
    echo $occurrence->scheduledDate, PHP_EOL;
}
```

</div>

<div dir="ltr">

```text
2026-11-06
2026-11-13
2026-11-20
```

</div>

هر سه تاریخ جمعه و در این تقویم کاری هستند. اگر یک تاریخ به دوشنبه منتقل شود، جمعه دوره بعد همچنان از مبدأ اولیه حساب می‌شود؛ تغییر یک دوره باعث رانش دوره‌های بعدی نمی‌شود.

## گزینه‌ها

| گزینه | نوع و مقدار مجاز | پیش‌فرض |
|---|---|---|
| <code dir="ltr">profile</code> | نام پروفایل | الزامی |
| <code dir="ltr">timezone</code> | شناسه IANA | الزامی |
| <code dir="ltr">frequency</code> | <code dir="ltr">weekly</code> یا <code dir="ltr">monthly</code> | الزامی |
| <code dir="ltr">interval</code> | صحیح ۱ تا ۱۲، فاصله هفته یا ماه | الزامی |
| <code dir="ltr">count</code> | صحیح ۱ تا ۶۰، تعداد دوره شامل موارد ردشده | الزامی |
| <code dir="ltr">adjustment</code> | <code dir="ltr">next</code>، <code dir="ltr">previous</code>، <code dir="ltr">skip</code> | الزامی |
| <code dir="ltr">calendar</code> | <code dir="ltr">gregorian</code>؛ برای ماهانه همچنین <code dir="ltr">jalali</code> | میلادی |
| <code dir="ltr">month_anchor</code> | فقط ماهانه: <code dir="ltr">day_of_month</code> یا <code dir="ltr">end_of_month</code> | روز ماه |
| <code dir="ltr">short_month</code> | فقط ماهانه: <code dir="ltr">clamp</code> یا <code dir="ltr">skip</code> | clamp |
| <code dir="ltr">max_shift_days</code> | صحیح ۰ تا ۳۱ | ۷ |
| <code dir="ltr">collision_policy</code> | <code dir="ltr">reject</code> یا <code dir="ltr">allow</code> | reject |

رشته عددی، بولی، اعشاری، null یا کلید ناشناخته رد می‌شود. در هفتگی، تعیین صریح تقویم میلادی مجاز است؛ تقویم شمسی و هر دو کلید مخصوص ماه، حتی با مقدار پیش‌فرض، رد می‌شوند.

## مبدأ و حساب ماه شمسی

رشته مبدأ همیشه تاریخ معتبر **میلادی** با قالب دقیق <code dir="ltr">Y-m-d</code> است؛ حتی با <code dir="ltr">calendar => 'jalali'</code>. شیء تاریخ به منطقه صریح درخواست تبدیل می‌شود و تاریخ محلی آن، بدون ساعت، گرفته می‌شود. خروجی‌ها برچسب تاریخ میلادی هستند، نه timestamp نیمه‌شب UTC. تاریخ مبدأیی که در منطقه انتخابی اصلاً وجود ندارد، خطاست.

برای ماهانه، ماه مقصد و روز مطلوب از مبدأ اصلی می‌آیند. با مبدأ ۳۱ ژانویه ۲۰۲۶ و clamp، فوریه به ۲۸ کوتاه می‌شود اما مارس دوباره ۳۱ است. با skip، ماه بدون روز مورد نظر یک دوره صریح ردشده می‌سازد؛ دوره جایگزین اضافه نمی‌شود و count، تعداد موفقیت‌ها نیست.

گزینه پایان ماه می‌خواهد خود مبدأ، آخرین روز ماه در تقویم انتخابی باشد. سپس آخرین روز هر ماه مقصد انتخاب می‌شود؛ تاریخ وسط ماه خودکار به پایان ماه تبدیل نمی‌شود. سیاست ماه کوتاه در این حالت اثری ندارد.

در ماهانه شمسی، مبدأ میلادی به شمسی تبدیل می‌شود، ماه‌های شمسی جلو می‌روند و تاریخ اسمی دوباره به میلادی برمی‌گردد. شش ماه اول ۳۱ روز، پنج ماه بعد ۳۰ روز، و اسفند برحسب کبیسه شمسی ۲۹ یا ۳۰ روز دارد. محدودیت واقعی نسخه نصب‌شده Verta و تبدیل رفت‌وبرگشت معتبر همچنان برقرار است. ماه قمری پشتیبانی نمی‌شود؛ تعطیلی قمری فقط می‌تواند کاری‌بودن تاریخ حاصل را تغییر دهد. تاریخ یا تبدیل خارج از محدوده پشتیبانی، خطا می‌دهد و به تاریخ دیگری تبدیل نمی‌شود.

## تعطیلی، افق و برخورد دوره‌ها

اگر تاریخ اسمی کاری باشد، دست‌نخورده می‌ماند. next و previous برای تاریخ بسته اکیداً به جلو یا عقب می‌روند؛ skip همان تاریخ اسمی را ثبت می‌کند، اما تاریخ زمان‌بندی‌شده ندارد. تاریخ کاملاً حذف‌شده مثل یک تاریخ در دسترس‌نبوده از این سیاست پیروی می‌کند؛ حذف نیمه‌شب به‌تنهایی به معنی حذف کل تاریخ نیست.

حد جابه‌جایی فاصله برچسب تاریخ را از تاریخ اسمی می‌شمارد؛ تاریخ اسمی فاصله صفر دارد. حد ۷، مقصد دقیقاً با فاصله هفت را شامل می‌شود؛ صفر یعنی حرکت مجاز نیست. تعطیلی و تاریخ حذف‌شده هم این بودجه را مصرف می‌کنند. جست‌وجوی ناکام next یا previous خطا می‌دهد و بی‌صدا به skip تبدیل نمی‌شود. حرکت عقب می‌تواند به قبل از ماه یا حتی مبدأ اصلی برسد.

افق رو به جلو از تاریخ محلی مبدأ اصلی تا <code dir="ltr">max_scan_days</code> است. همه تاریخ‌های اسمی و مقصدهای آینده باید در آن جا بگیرند؛ برای ماه ردشده بدون روز معتبر، کل ماه مقصد، از جمله آخرین روز، باید داخل حد باشد. تعداد دوره‌ها بی‌صدا کوتاه نمی‌شود.

اگر دو دوره به یک تاریخ برسند، پیش‌فرض reject خطا می‌دهد. allow هر دو را با <code dir="ltr">collides = true</code> روی **تمام** اعضای همان تاریخ نگه می‌دارد؛ آن‌ها را یکی نمی‌کند. تاریخ مساوی مجاز، اجازه وارونگی ترتیب زمانی نیست؛ وارونگی حتی در allow رد می‌شود. ترتیب نتیجه همیشه برحسب اندیس دوره است.

## نتیجه‌ها

<code dir="ltr">RecurringScheduleResult</code> دارای <code dir="ltr">profile</code>، <code dir="ltr">timezone</code>، <code dir="ltr">calendar</code>، <code dir="ltr">frequency</code>، <code dir="ltr">interval</code>، <code dir="ltr">anchorDate</code>، <code dir="ltr">requestedCount</code> و فهرست <code dir="ltr">occurrences</code> است. برای هر دوره یک <code dir="ltr">RecurringOccurrence</code> با این فیلدها وجود دارد:

| ویژگی | معنی |
|---|---|
| <code dir="ltr">cycleIndex</code> | اندیس از صفر. |
| <code dir="ltr">nominalPeriod</code> | ماه تقویم انتخابی با قالب <code dir="ltr">YYYY-MM</code> یا تاریخ اسمی هفتگی. |
| <code dir="ltr">nominalDate</code> | تاریخ میلادی؛ برای نبود روز ماه با skip، null. |
| <code dir="ltr">scheduledDate</code> | تاریخ نهایی میلادی یا null برای مورد ردشده. |
| <code dir="ltr">status</code> | <code dir="ltr">scheduled</code>، <code dir="ltr">shifted</code> یا <code dir="ltr">skipped</code>. |
| <code dir="ltr">reason</code> | <code dir="ltr">business_day</code>، <code dir="ltr">shifted_next</code>، <code dir="ltr">shifted_previous</code>، <code dir="ltr">closed_day</code> یا <code dir="ltr">invalid_month_day</code>. |
| <code dir="ltr">shiftDays</code> | فاصله تقویمی علامت‌دار، صفر برای بدون تغییر و null برای ردشده. |
| <code dir="ltr">collides</code> | برخورد مجاز با دوره دیگر. |

هر دو نتیجه تغییرناپذیرند و <code dir="ltr">toArray()</code> با کلید snake_case دارند. تقویم و داده منبع یک بار برای عملیات ثابت می‌شوند؛ ساعت کاری و cutoff، زمان اجرای دوره‌ای ایجاد نمی‌کنند. برای اتصال اجرای واقعی، [راهنمای لاراول](laravel-integration.md) و برای خطاها [مرجع API](api-reference.md) را ببینید.

</div>
