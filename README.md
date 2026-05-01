# Respira Tech

موقع عربي RTL لـ `Respira Tech` يشمل:
- الصفحة الرئيسية الحالية
- صفحات `about / services / store / blog / contact / privacy-policy / terms`
- متجر وصفحات منتجات
- نظام مقالات JSON ثابت
- لوحة إدارة بسيطة للمدونة
- لوحة تحكم موحدة داخل `/داشبورد/` لإدارة المتجر والمدونة والأتمتة
- مولد مقالات يومي باستخدام OpenAI
- `sitemap.xml` و`robots.txt`

## المتطلبات

- `Python 3`
- `Node.js 18+`

## الإعداد

1. انسخ ملف البيئة:

```bash
cp .env.example .env
```

2. عدّل القيم التالية داخل `.env`:

```env
OPENAI_API_KEY=
AUTO_PUBLISH_BLOGS=false
DAILY_BLOG_POSTS=2
GENERATE_BLOG_IMAGES=true
OPENAI_TEXT_MODEL=gpt-4.1
OPENAI_IMAGE_MODEL=gpt-image-1
CRON_SECRET=
ADMIN_PASSWORD=
WHATSAPP_NUMBER=201012566955
SITE_BASE_URL=https://respira-tech.com
```

3. عدّل البيانات العامة عند الحاجة من:

`data/site.json`

## تشغيل الموقع

```bash
python3 server.py
```

المسارات الأساسية:
- `/`
- `/store/`
- `/blog/`
- `/admin/blog/`
- `/داشبورد/`

## بناء الصفحات الثابتة

```bash
python3 build_content.py
```

هذا الأمر يعيد توليد:
- صفحات المدونة
- صفحات الخدمات
- `about`
- `contact`
- `privacy-policy`
- `terms`
- `admin/blog`
- `sitemap.xml`
- `robots.txt`
- نسخة النشر الجاهزة داخل `رفع-اللايف/`

## النشر التلقائي إلى الاستضافة الأساسية

المسار الصحيح طويل المدى هو:

1. الداشبورد تحفظ التغييرات في المشروع
2. السيرفر يعمل `build`
3. التغييرات تُدفع إلى GitHub
4. GitHub Actions ترفع محتويات `رفع-اللايف/` إلى الاستضافة المشتركة

ملف الـ workflow موجود هنا:

`.github/workflows/deploy-shared-hosting.yml`

لكي يعمل، أضف هذه GitHub Secrets في الريبو:

- `SHARED_HOSTING_FTP_SERVER`
- `SHARED_HOSTING_FTP_USERNAME`
- `SHARED_HOSTING_FTP_PASSWORD`

الـ workflow يستخدم:

- `FTPS`
- المنفذ `21`
- والمسار الهدف `/public_html/`

إذا كان مزود الاستضافة عندك يستخدم مسارًا مختلفًا، عدّل قيمة `server-dir` في الـ workflow.

## توليد مقال يومي

شغّل يدويًا:

```bash
node generateDailyBlog.js
```

السلوك:
- إذا كان `AUTO_PUBLISH_BLOGS=false` يتم الحفظ كـ `draft`
- إذا كان `AUTO_PUBLISH_BLOGS=true` يتم النشر تلقائيًا
- العدد اليومي الافتراضي `مقالان` ويمكن تغييره من `.env` أو من `/داشبورد/`
- إذا كان `GENERATE_BLOG_IMAGES=true` ومعك `OPENAI_API_KEY` سيتم توليد صورة مميزة محليًا لكل مقال داخل `assets/images/blog/`
- النظام يوقف نفسه بعد الوصول للحد اليومي المحدد بتوقيت القاهرة

## Cron يومي الساعة 9 صباحًا بتوقيت القاهرة

مثال على خادم Linux:

```cron
0 9 * * * cd /path/to/respiratech && /usr/bin/node generateDailyBlog.js >> /tmp/respiratech-blog.log 2>&1
```

أو عبر endpoint:

```bash
curl -X POST https://respira-tech.com/api/cron/generate-blog \
  -H "X-Cron-Secret: YOUR_CRON_SECRET"
```

## إدارة المدونة

لوحة الإدارة:

`/admin/blog/`

المهام المتاحة:
- عرض المقالات
- تحرير العنوان والـ meta والوصف والمحتوى
- نشر/إلغاء نشر
- حذف
- توليد مقال جديد يدويًا

إذا ضبطت `ADMIN_PASSWORD` فسيتم حماية الـ APIs الخاصة بالإدارة عبر Header:

`X-Admin-Password`

## لوحة التحكم الموحدة

المسار:

`/داشبورد/`

منها يمكنك:
- إدارة منتجات المتجر الحالية
- رفع الصور الرئيسية وصور الجاليري
- ضبط `OPENAI_API_KEY`
- ضبط عدد المقالات اليومي
- تفعيل أو إيقاف النشر التلقائي
- تفعيل أو إيقاف توليد صور المقالات
- تشغيل التوليد اليدوي فورًا
- مراجعة سجل التوليد
- تحرير المقالات ونشرها أو حذفها

## تخزين المقالات

المقالات محفوظة كملفات JSON داخل:

`data/blog_articles/`

كل مقال يحتوي على:
- `title_ar`
- `slug`
- `meta_title`
- `meta_description`
- `excerpt`
- `category`
- `tags`
- `featured_image`
- `featured_image_prompt`
- `content_markdown`
- `faq`
- `internal_links`
- `cta_text`
- `cta_button_text`
- `cta_button_url`
- `author`
- `status`
- `published_at`
- `created_at`
- `updated_at`
- `reading_time`
- `seo_score`
- `medical_disclaimer`

## Google Search Console

بعد النشر على الدومين النهائي:

1. حدّث `SITE_BASE_URL` في `.env` و`data/site.json`
2. أعد تشغيل:

```bash
python3 build_content.py
```

3. أرسل:

`https://your-domain.com/sitemap.xml`

إلى Google Search Console

## ملاحظات

- المحتوى الطبي هنا تثقيفي فقط ولا يغني عن استشارة الطبيب أو المختص.
- إذا لم يتم ضبط `OPENAI_API_KEY` فسيستخدم النظام fallback ويولد المقالات بصور placeholder.
- المفتاح المطلوب هنا هو `OpenAI API key` من منصة OpenAI، وليس مجرد اشتراك ChatGPT.
