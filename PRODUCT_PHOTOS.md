# تصاویر محصولات بدون عکس

بررسی کاتالوگ فعال `suppex.ir` در ۱۴ اکتبر ۲۰۲۶: ۲۰ محصول فعال، ۱۷ تصویر اصلی در دسترس و ۳ محصول بدون تصویر. فهرست از API زنده گرفته شد؛ محصولات نمونهٔ `catalog.js` مبنای این تغییر نیستند.

| محصول | وضعیت |
|---|---|
| Zoomad Labs ONE RAW Creatine، ۳۰۰ گرم | پنج عکس برای Mad Mango، Candy Coke، Piña Colada، Sandía Loca و Neutro آماده است |
| MUTANT AMINO، ۳۰۰ قرص / ۱۵۰ وعده | تصویر بستهٔ دارای برچسب 2000 mg و 300 tablets آماده است |
| Optimum Nutrition Micronized Creatine، ۱.۲ کیلوگرم، Blueberry Raspberry | مالک طعم را تأیید کرده؛ تصویر مطابق همین طعم و اندازه هنوز تأیید نشده است و وارد بسته نشده |

هر شش تصویر آمادهٔ نصب، **WebP با پس‌زمینهٔ شفاف و دقیقاً ۱۰۰ پیکسل فضای شفاف در بالا و پایین محصول** هستند. این فاصله در فایل اصلی اندازه‌گیری شده است؛ نسخه‌های کوچک‌تر آن را متناسب با اندازهٔ خود مقیاس می‌کنند.

## نصب روی cPanel

فایل روی کامپیوتر: `C:\Users\Asus\Desktop\UI Workout\suppex-product-photos-webp-2026-10-14.zip`.

مسیر آپلود در File Manager، نسبت به Home حساب cPanel: `public_html/suppex-product-photos-webp-2026-10-14.zip`.

مسیر استخراج، نسبت به Home حساب cPanel: `public_html/`.

1. در cPanel → File Manager → `public_html` فایل ZIP را آپلود کنید، روی آن Extract بزنید و پوشهٔ مقصد را همان `public_html` قرار دهید. مسیرهای `admin/`، `lib/`، `db/` و `assets/` مستقیم در ریشهٔ ZIP هستند.
2. وارد [صفحهٔ تصاویر محصولات](https://suppex.ir/admin/images.php) شوید. بخش «عکس‌های بررسی‌شدهٔ محصولات» دو محصول مطابق کاتالوگ را نشان می‌دهد.
3. دو ردیف آماده را انتخاب و «وصل کردن عکس‌های بررسی‌شده» را بزنید. اگر بستهٔ قبلی نصب شده، وضعیت «نسخهٔ قبلی این بسته — آمادهٔ اصلاح» نشان داده می‌شود؛ همان دکمه نسخهٔ WebP را روی عکس اصلی، گالری و طعم‌ها ثبت می‌کند.
4. صفحهٔ محصول و کارت‌ها را بررسی کنید؛ در Zoomad با تغییر طعم، عکس همان بسته نمایش داده می‌شود.

این عملیات WebPهای اصلی را در مسیر رسانهٔ تنظیم‌شدهٔ هاست قرار می‌دهد و نسخه‌های کوچک‌تر را با ابزار موجود سایت می‌سازد. جای خالی، تصویر نمونه و عکس‌های نسخهٔ قبلی همین بسته اصلاح می‌شوند. عکس‌های دیگری که مدیر انتخاب کرده و اطلاعات تجاری محصولات حفظ می‌شوند. گالری بر اساس مسیر عکس تکراری نمی‌شود و برند همراه slug بررسی می‌شود. هیچ محصول یا طعم جدیدی ساخته نمی‌شود. schema دیتابیس تغییر ندارد.

فایل‌های config، تست، رسید، مشتری، سفارش، اکسل تجاری و رسانه‌های قبلی در ZIP قرار ندارند. Push گیت به‌تنهایی این عکس‌ها را روی سایت زنده ثبت نمی‌کند؛ استخراج ZIP و اتصال از پنل لازم است.

## منابع عکس‌ها

مدل و اندازهٔ Zoomad با [صفحهٔ سازنده](https://zoomad.eu/products/one-raw-creatina) تطبیق داده شد. تصاویر سه طعم از [Supspace](https://www.supspace.fr/products/zoomad-one-raw-creatine)، تصویر Sandía Loca از [The Supplement Lab](https://thesupplementlablb.com/products/zoomad-labs-one-raw%C2%AE-creatine-300-g-flavored) و تصویر Neutro از خود سازنده است. عکس Mutant از [Mutant Thailand](https://mutantthailand.com/sites/11023/files/s/products/o_1gb6v8hai7rv1djbfje4171v0l.jpg) گرفته شد؛ نام، تعداد و برچسب آن بصری بررسی شدند.

WebPهای آمادهٔ نصب در `C:\Users\Asus\Desktop\UI Workout\assets\images\products\verified-webp-20261014\` هستند و نگاشت محصول/طعم همراه SHA-256 در `db/catalog-photos.json` است. تصویر با hash متفاوت یا فایل ناقص نصب نمی‌شود. ابزار تنها از عکس‌های محلی این manifest استفاده می‌کند؛ به دانلود خارجی از هاست وابسته نیست.

## روش آماده‌سازی و پرامپت دوربری

دوربری Mutant با ابزار داخلی `imagegen` انجام شد. فقط ماسک شفافیت آن روی پیکسل‌های عکس مرجع اعمال شد تا نوشته‌های بسته حفظ شوند. پنج PNG شفاف Zoomad و عکس دوربری‌شده با `tools/prepare-catalog-photos.php` به WebP بدون افت کیفیت تبدیل و حاشیه‌گذاری شدند. فایل‌های مرجع در `assets/images/products/verified-20261014/` نگه داشته شده‌اند؛ بستهٔ نصب تصاویر WebP را دارد.

پرامپت استفاده‌شده در ابزار داخلی:

```text
Use case: background-extraction. Edit target: the provided photograph of a MUTANT AMINO supplement bottle. Remove only the white background and the cast shadow outside the bottle, making the background truly transparent with a clean precise alpha cutout. Keep the actual photographed bottle: identical cap, silhouette, proportions, pose, red/black/yellow colors, printed graphics and label lettering. Preserve every printed label exactly, especially MUTANT, AMINO, 2000 mg, 300 TABLETS, and 150 SERVINGS. Do not redesign, retouch, redraw, add or invent any part of the product or typography. No ground shadow, glow, white halo, props, floor, gradient or checkerboard. Keep the full bottle intact, centered, and leave transparent space around it for later WebP export with exactly 100-pixel top and bottom padding. One isolated bottle only.
```

## تأیید تغییر

تست `tests/test-catalog-photos.php` فرمت WebP و ۱۰۰ ردیف شفاف در هر دو طرف، نصب دو محصول، عکس پنج طعم، ارتقای بستهٔ قبلی، خروجی API، گالری، نسخه‌های کوچک‌تر، حفظ قیمت/موجودی، حفظ عکس انتخاب‌شدهٔ مدیر، اجرای تکراری، برند نامطابق و صحت فایل‌ها را بررسی می‌کند. تست روی instance جداگانهٔ MariaDB و دیتابیس `suppex_test` اجرا می‌شود.

نتیجهٔ مجموعهٔ کامل: **۳۵۰۱ بررسی موفق، صفر خطا**. پس از نهایی‌کردن خروجی‌ها، تست ۴۸ بررسی عکس‌ها دوباره موفق بود. شفافیت، حاشیهٔ ۱۰۰ پیکسلی و صحت ۹ عضو ZIP هم مستقل بررسی شدند؛ ۷۱٬۰۵۰ پیکسل بخش برچسب Mutant دقیقاً با عکس مرجع برابرند. نصب روی سایت زنده پس از استخراج و اقدام از پنل انجام می‌شود.
