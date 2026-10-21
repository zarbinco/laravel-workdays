<div dir="rtl">

# محاسبات گروهی

[English](../en/bulk-calculations.md) · [همه راهنماها](README.md)

برای محاسبه چند تاریخ یا برنامه با یک نمای ثابت تقویم، از <code dir="ltr">bulkCalculate()</code> استفاده کنید. هر آیتم همچنان همان API و نوع نتیجه مستقل را دارد؛ محاسبه گروهی الگوریتم یا قواعد تاریخ دیگری تعریف نمی‌کند.

## یک گروه کوچک

پس از [تنظیم تقویم نمونه](getting-started.md)، دو تاریخ را بررسی کنید:

<!-- example: bulk-calculation -->

<div dir="ltr">

```php
use Zarbinco\LaravelWorkdays\Facades\Workday;

$result = Workday::bulkCalculate([
    ['id' => 'friday', 'operation' => 'is_business_day', 'profile' => 'global', 'date' => '2026-11-06'],
    ['id' => 'saturday', 'operation' => 'is_business_day', 'profile' => 'global', 'date' => '2026-11-07'],
]);
foreach ($result->items as $item) {
    echo $item->id, ':', json_encode($item->result, JSON_THROW_ON_ERROR), PHP_EOL;
}
echo $result->succeeded, '/', $result->failed, PHP_EOL;
```

</div>

<div dir="ltr">

```text
friday:true
saturday:false
2/0
```

</div>

نتیجه false برای شنبه، محاسبه موفق است؛ با شکست آیتم تفاوت دارد. ترتیب خروجی همان ترتیب ورودی می‌ماند.

## شکل عملیات و نوع پاسخ

هر آیتم یک نگاشت با <code dir="ltr">id</code> و <code dir="ltr">operation</code> و فقط کلیدهای مجاز همان عملیات است:

| عملیات | آرگومان‌ها | نوع واقعی result در PHP |
|---|---|---|
| <code dir="ltr">is_business_day</code> | <code dir="ltr">profile</code>، <code dir="ltr">date</code> | بولی |
| <code dir="ltr">working_windows</code> | <code dir="ltr">profile</code>، <code dir="ltr">date</code> | آرایه <code dir="ltr">TimeWindow</code> |
| <code dir="ltr">add_business_days</code> | <code dir="ltr">profile</code>، <code dir="ltr">date</code>، <code dir="ltr">days</code> | <code dir="ltr">CarbonImmutable</code> |
| <code dir="ltr">add_business_minutes</code> | <code dir="ltr">profile</code>، <code dir="ltr">datetime</code>، <code dir="ltr">minutes</code> | <code dir="ltr">CarbonImmutable</code> |
| <code dir="ltr">resolve_cutoff</code> | <code dir="ltr">profile</code>، <code dir="ltr">received_at</code> | <code dir="ltr">CutoffResult</code> |
| <code dir="ltr">plan_delivery</code> | <code dir="ltr">received_at</code>، <code dir="ltr">plan</code> | <code dir="ltr">DeliveryPlanResult</code> |
| <code dir="ltr">delivery_slots</code> | <code dir="ltr">ready_at</code>، <code dir="ltr">options</code> | <code dir="ltr">DeliverySlotsResult</code> |
| <code dir="ltr">recurring_schedule</code> | <code dir="ltr">anchor_date</code>، <code dir="ltr">options</code> | <code dir="ltr">RecurringScheduleResult</code> |
| <code dir="ltr">evaluate_sla</code> | <code dir="ltr">started_at</code>، <code dir="ltr">evaluated_at</code>، <code dir="ltr">options</code> | <code dir="ltr">SlaResult</code> |
| <code dir="ltr">reverse_plan_delivery</code> | <code dir="ltr">desired_completion_at</code>، <code dir="ltr">plan</code>، <code dir="ltr">options</code> | <code dir="ltr">ReverseDeliveryPlanResult</code> |

فهرست غیرخالی با حداکثر ۱۰۰۰ آیتم پذیرفته می‌شود. شناسه رشته UTF-8 غیرخالی و یکتا با حداکثر ۱۲۸ نویسه است؛ فقط فاصله، tab یا خط جدید معتبر نیست. حداکثر ۵۰ پروفایل متمایز در یک گروه مجاز است، شامل نام‌های داخل برنامه‌ها و گزینه‌های توکار. کپی ورودی هر آیتم به عمق ۳۲ و ۱۰۰٬۰۰۰ گره محدود است.

فقط دو گزینه سطح گروه وجود دارد:

- <code dir="ltr">on_error</code>: مقدار <code dir="ltr">collect</code> پیش‌فرض یا <code dir="ltr">throw</code>.
- <code dir="ltr">prefetch</code>: نگاشت دقیق <code dir="ltr">from</code> و <code dir="ltr">through</code> با تاریخ میلادی سخت‌گیرانه؛ شروع حداکثر برابر پایان و حداکثر ۶۲ برچسب تاریخ، شامل دو سر.

بخش‌بندی، مسئولیت برنامه است؛ گزینه‌ای به نام chunk_size یا collect_errors وجود ندارد. برای حجم بیشتر، از <code dir="ltr">array_chunk($operations, 1000)</code> و فراخوانی جدا برای هر بخش استفاده کنید. هر بخش نمای تازه خود را می‌گیرد؛ اگر تعریف‌ها بین بخش‌ها تغییر کنند، نتایج لزوماً از یک نسخه نیستند.

## خطای ساختار، خطای آیتم و خطای منبع

ساختار بیرونی نامعتبر، قبل از محاسبه هر آیتم کل فراخوانی را با <code dir="ltr">WorkdayConfigurationException</code> رد می‌کند: فهرست یا آیتم بدشکل، شناسه نامعتبر یا تکراری، عملیات ناشناخته، کلید اضافی یا متعلق به عملیات دیگر، گزینه گروهی بدشکل و عبور از سقف آیتم یا پروفایل.

نبود یا نوع نادرست آرگومان عملیاتی، پروفایل انتخابی ناشناخته و اعتبارسنجی برنامه یا گزینه داخلی، خطای همان آیتم است. با collect، خطاهای شناخته‌شده ورودی، تنظیمات و جست‌وجوی محدود ثبت می‌شوند و آیتم مستقل بعدی ادامه پیدا می‌کند. با throw، اولین خطا به <code dir="ltr">RuntimeException</code> شامل شناسه و عملیات تبدیل می‌شود و علت اصلی را حفظ می‌کند. تلاش دوباره خودکار نیست.

شکست بازکردن یا خواندن منبع با <code dir="ltr">DefinitionSourceException</code>، خطای اتصال، PDO یا query و خطای غیرمنتظره برنامه‌نویسی، حتی با collect منتقل می‌شوند. این‌ها شکست عادی یک تقویم نیستند. زنجیره علت هم بررسی می‌شود؛ خطای زیرساختِ پیچیده‌شده در یک خطای دیگر نباید به نتیجه موفق گروه یا خطای قابل جمع‌آوری تبدیل شود. پیام خطای جمع‌آوری‌شده، حداکثر ۲۰۴۸ نویسه UTF-8 دارد و شامل trace یا اطلاعات اتصال نمی‌شود.

## نتیجه و پیش‌خوانی

<code dir="ltr">BulkCalculationResult</code> دارای <code dir="ltr">total</code>، <code dir="ltr">succeeded</code>، <code dir="ltr">failed</code> و <code dir="ltr">items</code> است. هر <code dir="ltr">BulkCalculationItemResult</code> تغییرناپذیر، <code dir="ltr">id</code>، <code dir="ltr">operation</code>، <code dir="ltr">success</code>، <code dir="ltr">result</code> و <code dir="ltr">error</code> دارد. در موفقیت، خطا null و نتیجه نوع اصلی جدول است؛ در شکست، نتیجه null و خطا فقط شامل <code dir="ltr">type</code> و <code dir="ltr">message</code> است.

<code dir="ltr">toArray()</code> همه نتیجه‌ها را به داده مناسب JSON تبدیل می‌کند؛ DTO از متد خودش، و Carbon از قالب <code dir="ltr">Y-m-d\TH:i:s.uP</code> با offset واقعی خودش استفاده می‌کند. نتیجه‌ها الزاماً UTC نمی‌شوند. بولی، عدد صحیح و null نوع خود را حفظ می‌کنند؛ تاریخ تکرار همچنان رشته فقط تاریخ است.

یک گروه، یک نمای منبع دارد و محاسبه‌گر و تقویم پروفایل را در همان زمینه بازاستفاده می‌کند. پیش‌خوانی، راهنمای بهینه‌سازی است؛ منبع، افق یا دامنه معتبر نتیجه را عوض نمی‌کند. Config پرس‌وجوی تعطیلی SQL ندارد. Database تعطیلی کامل پروفایل را در همان نما یک بار می‌خواند، بنابراین راهنما در آن زائد است. نتیجه داخل یا خارج بازه پیش‌خوانی باید همان نتیجه بدون راهنما باشد.

برای جزئیات هر برنامه، راهنمای همان قابلیت را ببینید: [تحویل](delivery.md)، [بازه‌ها](delivery-slots.md)، [SLA](sla.md) و [تکرار](recurring-schedules.md). ثبت یا مقایسه [نسخه برآورد](snapshots.md) جزو عملیات گروهی نیست.

دسته‌بندی کامل خطا و حدود ورودی در [مرجع API](api-reference.md) آمده است.

</div>
