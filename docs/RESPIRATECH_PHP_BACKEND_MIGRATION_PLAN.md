# RespiraTech PHP Backend Migration Plan

## Goal

Move RespiraTech from the split setup:

- Hostinger: public static site and dashboard shell
- Railway: Python API, OpenAI, Search Console, generation, deploy actions

to the AquaShelter-style setup:

- Hostinger: public site
- Hostinger PHP: dashboard API, admin auth, JSON/data writes, uploads, cron scripts, SEO automation

Railway should become unnecessary after the final cutover.

## Current Python API Surface

These endpoints currently live in `server.py`:

- `GET /api/health`
- `GET /api/store`
- `GET /api/blog`
- `GET /api/dashboard/config`
- `GET /api/blog/logs`
- `GET /api/seo/brain`
- `GET /api/blog/test-env`
- `POST /api/store/save`
- `POST /api/upload`
- `POST /api/blog/save`
- `POST /api/blog/toggle-status`
- `POST /api/blog/delete`
- `POST /api/blog/generate`
- `POST /api/seo/gsc/upload`
- `POST /api/seo/brain`
- `POST /api/dashboard/config`
- `POST /api/build`
- `POST /api/cron/generate-blog`
- `POST /api/cron/seo-brain`
- `POST /api/deploy`

## Migration Phases

### Phase 1: PHP JSON API compatibility

Create a PHP router under `/api/` that reads and writes the same files used today:

- `data/store.json`
- `data/site.json`
- `data/blog_articles/*.json`
- `data/blog_generation_log.json`
- `data/seo_brain_log.json`
- `data/dashboard_activity_log.json`
- `assets/images/store/*`

This phase removes CORS and Railway for normal dashboard reads/writes, product edits, image uploads, article review, publish/unpublish, and deletes.

### Phase 2: PHP build/deploy replacement

Replace Python `build_content.py` with a PHP-compatible builder or a direct PHP page rendering model.

Preferred Hostinger approach:

- Keep generated static pages for speed.
- Add PHP builder scripts in `scripts/`.
- Run build after JSON writes.
- No FTP deployment needed because the API writes directly inside the same Hostinger docroot.

### Phase 3: OpenAI generation in PHP

Port these jobs:

- Topic/article generation
- Article-from-URL generation
- Blog image generation or image prompt fallback
- Publish-now flow

Required files:

- `lib/openai_client.php`
- `scripts/generate_blog.php`
- `scripts/generate_article_from_url.php`

Secrets should be stored outside public web paths where possible, or in environment variables.

### Phase 4: SEO Brain in PHP

Reuse the AquaShelter pattern:

- `lib/seo_brain.php`
- `scripts/seo_audit.php`
- `scripts/seo_content_refresh.php`
- `scripts/seo_quality_gate.php`
- `scripts/seo_submit_sitemap.php`
- `scripts/seo_url_inspection.php`

RespiraTech can keep JSON storage initially. If the SEO dataset grows, move SEO tables to MySQL like AquaShelter.

### Phase 5: Google Search Console in PHP

Use the existing service account, but do not place the JSON in public git or public web paths.

Options:

- Store JSON at `/home/u598338404/respiratech_private/gsc-service-account.json`
- Or store credentials in Hostinger environment/config if available

Port:

- Search Analytics import
- URL Inspection API
- Sitemap submission

### Phase 6: Cron on Hostinger

Create Hostinger cron jobs:

- `php /home/u598338404/domains/respira-tech.com/public_html/scripts/generate_blog.php`
- `php /home/u598338404/domains/respira-tech.com/public_html/scripts/seo_brain.php`
- `php /home/u598338404/domains/respira-tech.com/public_html/scripts/daily_report.php`

Frequency:

- Blog generation: daily or twice daily
- SEO Brain: 2 runs/day
- Email report: daily

### Phase 7: Dashboard cutover

Change `assets/js/dashboard-app.js`:

```js
const API_BASE_URL = '';
```

Then deploy:

- `/api/`
- `/lib/`
- `/scripts/`
- dashboard assets

Finally remove Railway variables and deployment dependency.

## Current Article Review Location

Until cutover, article review is already in:

`https://respira-tech.com/داشبورد/`

Open tab:

`المقالات`

Workflow:

1. Click `تحديث القائمة`.
2. Click `فتح` on an article.
3. Edit title, slug, meta title, meta description, excerpt, category, tags, and Markdown content.
4. Use `حفظ`.
5. Use `نشر` or `إلغاء نشر`.
6. If published, use `معاينة` to open the live article.

## Cutover Rule

Do not point the live dashboard to PHP until all dashboard write actions pass on Hostinger:

- Save store
- Upload product image
- Save article
- Publish/unpublish article
- Delete article
- Generate and publish article
- Upload GSC credentials
- Run SEO audit
- Run article-from-URL generation

