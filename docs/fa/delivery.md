<div dir="rtl">

# مهلت سفارش و برنامه تحویل

[English](../en/delivery.md) · [همه راهنماها](README.md)

یک برنامه تحویل، کار انبار، انتقال بین دو تقویم و حمل برحسب روز کاری را به‌ترتیب محاسبه می‌کند. از محاسبه معکوس هم می‌توانید آخرین زمان دریافت سفارش برای یک موعد را به دست آورید. این برآورد زمانی است؛ برنامه میزبان باید موجودی، پرداخت، ظرفیت و پذیرش سفارش را جدا بررسی کند.

## اولین برنامه

ابتدا [تقویم نمونه](getting-started.md) را آماده کنید. مثال زیر یک مرحله کار دارد و منطقه زمانی آن صریح است.

<!-- example: delivery-plan -->

<div dir="ltr">

```php
use Zarbinco\LaravelWorkdays\Facades\Workday;

$plan = [
    'timezone' => 'UTC',
    'profile_timezones' => ['global' => 'UTC'],
    'stages' => [
        ['id' => 'prepare', 'type' => 'work', 'profile' => 'global', 'minutes' => 1],
    ],
];
$result = Workday::planDelivery('2026-11-02T09:59:30.123456Z', $plan);
echo $result->estimatedCompletionAt->format('Y-m-d H:i:s.uP'), PHP_EOL;
```

</div>

<div dir="ltr">

```text
2026-11-02 11:00:30.123456+00:00
```

</div>

مصرف کار از پنجره اول به پنجره بعدی ادامه پیدا می‌کند و ثانیه و میکروثانیه را نگه می‌دارد. مراحل بعدی، لحظه تکمیل مرحله قبل را دریافت می‌کنند.

## ساختار برنامه و انواع مرحله

برنامه دقیقاً کلیدهای <code dir="ltr">timezone</code>، <code dir="ltr">profile_timezones</code>، <code dir="ltr">stages</code> و در صورت نیاز <code dir="ltr">cutoff_profile</code> را دارد. منطقه زمانی برنامه و هر پروفایل ارجاع‌شده باید شناسه معتبر IANA باشد. نگاشت منطقه زمانی پروفایل‌ها الزامی است؛ از منطقه زمانی سرور یا نام پروفایل حدس زده نمی‌شود.

فهرست مراحل، مرتب و دارای ۱ تا ۳۲ عضو است. هر مرحله یک <code dir="ltr">id</code> رشته‌ای غیرخالی و یکتا دارد. کلید ناشناخته یا کلید مربوط به نوع دیگر رد می‌شود.

| نوع | کلیدهای مخصوص | رفتار |
|---|---|---|
| <code dir="ltr">work</code> | <code dir="ltr">profile</code>، <code dir="ltr">minutes</code> صحیح نامنفی | ابتدا اولین زمان باز را پیدا می‌کند، سپس مدت واقعی کار را از پنجره‌ها مصرف می‌کند. صفر هم ابتدا به زمان باز می‌رود. |
| <code dir="ltr">handoff</code> | <code dir="ltr">profiles</code>، فهرست دقیقاً دو نام متفاوت | اولین لحظه‌ای را پیدا می‌کند که هر دو تقویم هم‌زمان باز باشند؛ مدت انتقال صفر است. |
| <code dir="ltr">business_days</code> | <code dir="ltr">profile</code>، <code dir="ltr">days</code> صحیح نامنفی | از تاریخ محلی بعدی، تاریخ‌های کاری را می‌شمارد و ساعت دیواری ورودی را حفظ می‌کند. صفر، لحظه را بدون حرکت نگه می‌دارد. |

برای work و handoff، ساعت کاری معتبر لازم است؛ پروفایل فقط روزمحور برای حمل برحسب روز کافی است. تعطیلی، ارث‌بری، ساعت اختصاصی و شیفت شب، از همان تقویم مؤثر می‌آیند. هیچ مرحله‌ای نام پروفایل را با مسیریابی زمینه دوباره انتخاب نمی‌کند.

مرز پنجره <code dir="ltr">[start, end)</code> است. تکمیل کار دقیقاً در ساعت بسته‌شدن مجاز است، اما handoff در همان لحظه نیاز دارد هر دو تقویم باز باشند. با ناهار <code dir="ltr">12:00–13:00</code>، تکمیل کار در ۱۲ به معنی انتقال در ۱۲ نیست؛ باید تا بازشدن مشترک بعدی صبر کرد. دو پنجره مجاور هم برای آغاز انتقال، عضویت واقعی همان لحظه را تعیین می‌کنند.

مرحله روز کاری به <code dir="ltr">include_start_date</code> وابسته نیست و ورودی را روز صفر می‌داند. تاریخ حذف‌شده در منطقه زمانی شمرده نمی‌شود. ساعت مقصدی که وجود ندارد، خطا می‌دهد؛ زمان تکراری این مرحله از انتخاب PHP/Carbon پیروی می‌کند. این رفتار را با رد ورودی ساده مبهم در parser برنامه یکی نگیرید.

## سیاست ساعت نهایی پذیرش

برای استفاده مستقل از <code dir="ltr">resolveCutoff()</code>، یک سیاست در پروفایل بگذارید. این قطعه تنظیم است و به پروفایل دلخواه افزوده می‌شود:

<div dir="ltr">

```php
'cutoff' => [
    'timezone' => 'Asia/Tehran',
    'time' => '14:00',
    'weekdays' => ['Thursday' => '11:00', 'Friday' => null],
],
```

</div>

با همان تقویم شروع کار، نمونه مستقل استفاده از سیاست:

<!-- example: cutoff -->

<div dir="ltr">

```php
use Zarbinco\LaravelWorkdays\Facades\Workday;

config()->set('workdays.definitions.profiles.global.cutoff', [
    'timezone' => 'UTC',
    'time' => '14:00',
    'weekdays' => ['Friday' => null],
]);
$decision = Workday::profile('global')->resolveCutoff('2026-11-02T14:00:00Z');
echo $decision->effectiveBusinessDate->toDateString(), PHP_EOL;
echo $decision->reason, PHP_EOL;
```

</div>

<div dir="ltr">

```text
2026-11-03
cutoff_reached
```

</div>

مهلت باید رشته دقیق <code dir="ltr">HH:MM</code> باشد. ننوشتن روز از ساعت عمومی استفاده می‌کند و null صریح، پذیرش آن روز هفته را غیرفعال می‌کند. نام‌های معادل تکراری روز هفته خطا هستند. مقایسه دقیقاً <code dir="ltr">receivedAt &lt; cutoffAt</code> است؛ خود لحظه مهلت، دیر محسوب می‌شود و زمان گرد نمی‌شود.

مهلت از ساعت کاری مستقل است: دریافت پیش از بازشدن یا در ناهار هم می‌تواند برای همان روز واجد شرایط باشد. روز کاری اضافه تعطیلی را باز می‌کند، ولی null یا <code dir="ltr">00:00</code> را به بازه پذیرش تبدیل نمی‌کند. مهلت صفر، بازه مثبت پذیرش ندارد و در انتخاب امروز و تاریخ آینده کنار گذاشته می‌شود. جست‌وجو اولین تاریخ آینده کاری با سیاست فعال و بازه پذیرش واقعی غیرخالی را می‌یابد.

برای اعمال سیاست در برنامه، <code dir="ltr">cutoff_profile</code> باید نام پروفایل **اولین مرحله work** باشد. حذف آن یعنی برنامه سیاست پذیرش را اعمال نمی‌کند. تصمیم تاریخ مؤثر فقط تاریخ را تخصیص می‌دهد؛ شروع واقعی کار در پنجره باز بعدی پیدا می‌شود.

خروجی <code dir="ltr">CutoffResult</code> دارای <code dir="ltr">profile</code>، <code dir="ltr">timezone</code>، <code dir="ltr">receivedAt</code>، <code dir="ltr">cutoffAt</code>، <code dir="ltr">effectiveBusinessDate</code>، <code dir="ltr">sameDayEligible</code> و <code dir="ltr">reason</code> است. مهلت ثبت‌شده مربوط به تاریخ دریافت است، حتی اگر تاریخ مؤثر جلو رفته باشد. دلیل به‌ترتیب اولویت، یکی از <code dir="ltr">non_business_day</code>، <code dir="ltr">cutoff_disabled</code>، <code dir="ltr">before_cutoff</code> یا <code dir="ltr">cutoff_reached</code> است. <code dir="ltr">toArray()</code> نام‌ها را به snake_case و تاریخ مؤثر را به <code dir="ltr">Y-m-d</code> تبدیل می‌کند؛ دو زمان دیگر در منطقه سیاست با میکروثانیه می‌مانند.

ساعت تنظیم‌شده ناموجود، خطای تنظیمات می‌دهد. سیاست مستقل cutoff در ساعت تکراری از انتخاب PHP/Carbon پیروی می‌کند؛ برای لحظه دریافت بدون ابهام، offset صریح بدهید. این سیاست، ساعت پنجره کاری را تغییر نمی‌دهد.

## نتیجه و افق برنامه

<code dir="ltr">DeliveryPlanResult</code> شامل <code dir="ltr">timezone</code>، <code dir="ltr">receivedAt</code>، <code dir="ltr">planningStartAt</code>، <code dir="ltr">cutoffResult</code> اختیاری، <code dir="ltr">stages</code> مرتب و <code dir="ltr">estimatedCompletionAt</code> است. هر <code dir="ltr">DeliveryStageResult</code>، <code dir="ltr">id</code>، <code dir="ltr">type</code>، <code dir="ltr">profiles</code>، <code dir="ltr">readyAt</code>، <code dir="ltr">startedAt</code> و <code dir="ltr">completedAt</code> دارد.

لحظه‌های برنامه در UTC و تغییرناپذیرند. <code dir="ltr">toArray()</code> کلید snake_case و زمان <code dir="ltr">Y-m-d\TH:i:s.uP</code> با شش رقم میکروثانیه می‌دهد. نتیجه cutoff توکار، منطقه سیاست خودش را حفظ می‌کند.

یک حد مشترک <code dir="ltr">max_scan_days</code> از تاریخ دریافت در منطقه برنامه آغاز می‌شود؛ همان تاریخ روز صفر است و آخرین تاریخ مجاز هم داخل محدوده است. مراحل بعد سهم تازه نمی‌گیرند. نتیجه هر مرحله پس از تبدیل به منطقه برنامه باید در همین محدوده باشد. اگر زمان کافی یا لحظه مشترک وجود نداشته باشد، <code dir="ltr">RuntimeException</code> با اطلاعات مرحله رخ می‌دهد؛ نتیجه موفق ناقص برنمی‌گردد.

## محاسبه معکوس

<code dir="ltr">reversePlanDelivery($desiredCompletionAt, $plan, ['lookback_days' => 14])</code> همان ساختار برنامه را می‌گیرد و آخرین دریافت ممکن را روی دقت میکروثانیه جست‌وجو می‌کند. گزینه <code dir="ltr">lookback_days</code> الزامی، صحیح از ۱ تا ۳۱ و حداکثر برابر حد ایمنی پکیج است. پنجره دریافت از آغاز تاریخ محلیِ این تعداد روز پیش از تاریخ موعد تا خود لحظه موعد، شامل دو سر، است. برای مثال، حد دو روز با موعد چهارشنبه ساعت ۱۴ از آغاز دوشنبه شروع می‌شود. اگر تاریخ آغاز کاملاً حذف شده باشد، اولین تاریخ موجود داخل محدوده مبنا می‌شود. برنامه مستقیم هر نامزد همچنان افق اصلی وابسته به دریافت خودش را دارد.

<!-- example: reverse-delivery -->

<div dir="ltr">

```php
use Zarbinco\LaravelWorkdays\Facades\Workday;

$plan = [
    'timezone' => 'UTC',
    'profile_timezones' => ['global' => 'UTC'],
    'stages' => [
        ['id' => 'prepare', 'type' => 'work', 'profile' => 'global', 'minutes' => 60],
    ],
];
$result = Workday::reversePlanDelivery('2026-11-02T12:00:00Z', $plan, ['lookback_days' => 1]);
echo $result->latestOrderAt?->format('Y-m-d H:i:s.uP'), PHP_EOL;
echo $result->estimatedCompletionAt?->format('Y-m-d H:i:s.uP'), PHP_EOL;
```

</div>

<div dir="ltr">

```text
2026-11-02 11:00:00.000000+00:00
2026-11-02 12:00:00.000000+00:00
```

</div>

در <code dir="ltr">ReverseDeliveryPlanResult</code>، فیلدهای <code dir="ltr">timezone</code>، <code dir="ltr">targetCompletionAt</code>، <code dir="ltr">searchStartAt</code> و <code dir="ltr">feasible</code> همواره موجودند. اگر نتیجه ممکن باشد، <code dir="ltr">latestOrderAt</code>، <code dir="ltr">planningStartAt</code>، <code dir="ltr">firstStageStartsAt</code>، <code dir="ltr">estimatedCompletionAt</code>، <code dir="ltr">slackMicroseconds</code> و <code dir="ltr">forwardResult</code> پاسخ دقیق را می‌دهند؛ در حالت ناممکن، هر شش فیلد null هستند. نبود هم‌پوشانی معتبر می‌تواند نتیجه ناممکن بدهد؛ تنظیم نامعتبر، خرابی منبع یا ناتوانی حل‌کننده در اثبات جواب، به نتیجه ناممکن تبدیل نمی‌شود و خطا می‌دهد.

آخرین زمان سفارش ممکن است از زمان فعلی گذشته باشد؛ تصمیم پذیرش به برنامه شما تعلق دارد. این API سفارش، رزرو یا اعلان ایجاد نمی‌کند. برای نگهداری برآورد، [نسخه ذخیره‌شده](snapshots.md) و برای گزینه‌های انتخاب مشتری، [بازه‌های تحویل](delivery-slots.md) را ببینید. قواعد ورودی و خطا در [مرجع API](api-reference.md) آمده است.

حل‌کننده معکوس علاوه بر حد روزها، سقف ایمنی ۶۵٬۵۳۶ ارزیابی پیشوند برنامه و ۳۲٬۷۶۸ بخش جست‌وجو دارد. عبور از این سقف یا ناتوانی در اثبات آخرین لحظه دقیق، خطا می‌دهد و به زمان تقریبی موفق یا پاسخ ناممکن تبدیل نمی‌شود.

</div>
