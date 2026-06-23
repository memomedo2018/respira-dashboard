<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    rt_json_response(['ok' => true], 204);
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (str_starts_with($path, '/php-api/')) {
    $path = '/api/' . ltrim(substr($path, strlen('/php-api/')), '/');
} elseif ($path === '/php-api') {
    $path = '/api/health';
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET' && $path === '/api/health') {
    rt_json_response(['ok' => true, 'backend' => 'php']);
}

if ($method === 'GET' && $path === '/api/store') {
    $store = rt_load_json(rt_data_path('data/store.json'), ['products' => [], 'categories' => [], 'config' => []]);
    if (is_array($store)) {
        if (!isset($store['config']) || !is_array($store['config'])) $store['config'] = [];
        $store['config']['whatsapp_phone'] = rt_env('WHATSAPP_NUMBER', $store['config']['whatsapp_phone'] ?? RESPIRATECH_DEFAULT_WHATSAPP);
    }
    rt_json_response($store);
}

if ($method === 'GET' && $path === '/api/blog') {
    rt_require_admin();
    $slug = isset($_GET['slug']) ? rt_slugify((string)$_GET['slug']) : '';
    if ($slug !== '') {
        $article = rt_load_json(rt_blog_article_path($slug), null);
        if (!is_array($article)) rt_json_response(['error' => 'article not found'], 404);
        rt_json_response(['article' => $article]);
    }
    rt_json_response(['articles' => rt_load_articles()]);
}

if ($method === 'GET' && $path === '/api/blog/logs') {
    rt_require_admin();
    rt_json_response(['logs' => rt_load_json(rt_data_path('data/blog_generation_log.json'), [])]);
}

if ($method === 'GET' && $path === '/api/dashboard/config') {
    rt_require_admin();
    rt_json_response(rt_dashboard_config());
}

if ($method === 'GET' && $path === '/api/seo/brain') {
    rt_require_admin();
    require_once rt_data_path('php-backend/scripts/seo_system.php');
    rt_json_response(rt_seo_full_state());
}

if ($method === 'POST' && $path === '/api/store/save') {
    rt_require_admin();
    require_once rt_data_path('php-backend/scripts/build_content.php');
    $payload = rt_read_json_body();
    $existing = rt_load_json(rt_data_path('data/store.json'), []);
    $existingCount = is_array($existing['products'] ?? null) ? count($existing['products']) : 0;
    $incomingCount = is_array($payload['products'] ?? null) ? count($payload['products']) : 0;
    if ($existingCount > 0 && $incomingCount === 0 && empty($payload['confirm_clear'])) {
        rt_json_response(['error' => 'refusing to save 0 products over existing data; pass confirm_clear:true to override'], 409);
    }
    rt_save_json(rt_data_path('data/store.json'), $payload);
    rt_append_activity('store_save', ['products_count' => $incomingCount]);
    $build = rt_build_content(false);
    rt_json_response(['ok' => true, 'sync' => ['mode' => 'php-local'], 'build' => $build]);
}

if ($method === 'POST' && $path === '/api/dashboard/config') {
    rt_require_admin();
    $payload = rt_read_json_body();
    $updates = [
        'AUTO_PUBLISH_BLOGS' => !empty($payload['auto_publish_blogs']) ? 'true' : 'false',
        'DAILY_BLOG_POSTS' => (string)($payload['daily_blog_posts'] ?? 2),
        'GENERATE_BLOG_IMAGES' => array_key_exists('generate_blog_images', $payload) && !$payload['generate_blog_images'] ? 'false' : 'true',
        'OPENAI_TEXT_MODEL' => trim((string)($payload['openai_text_model'] ?? 'gpt-4.1')),
        'OPENAI_IMAGE_MODEL' => trim((string)($payload['openai_image_model'] ?? 'dall-e-3')),
        'WHATSAPP_NUMBER' => trim((string)($payload['whatsapp_number'] ?? RESPIRATECH_DEFAULT_WHATSAPP)),
        'SITE_BASE_URL' => trim((string)($payload['site_base_url'] ?? 'https://respira-tech.com')),
        'SEO_BRAIN_AUTO' => array_key_exists('seo_brain_auto', $payload) && !$payload['seo_brain_auto'] ? 'false' : 'true',
        'SEO_BRAIN_RUNS_PER_DAY' => (string)($payload['seo_brain_runs_per_day'] ?? 2),
        'GSC_SITE_URL' => trim((string)($payload['gsc_site_url'] ?? $payload['site_base_url'] ?? 'https://respira-tech.com')),
        'SEO_DAILY_REPORT_EMAIL' => trim((string)($payload['seo_daily_report_email'] ?? 'alihessien0@gmail.com')),
    ];
    foreach (['openai_api_key' => 'OPENAI_API_KEY', 'admin_password' => 'ADMIN_PASSWORD', 'cron_secret' => 'CRON_SECRET', 'github_token' => 'GITHUB_TOKEN', 'github_repo' => 'GITHUB_REPO', 'github_branch' => 'GITHUB_BRANCH'] as $field => $envKey) {
        $value = trim((string)($payload[$field] ?? ''));
        if ($value !== '' && $value !== '********') $updates[$envKey] = $value;
    }
    rt_save_env_updates($updates);

    $siteData = rt_load_json(rt_data_path('data/site.json'), []);
    if (is_array($siteData)) {
        if (!isset($siteData['site']) || !is_array($siteData['site'])) $siteData['site'] = [];
        $siteData['site']['whatsapp_number'] = $updates['WHATSAPP_NUMBER'];
        $siteData['site']['base_url'] = $updates['SITE_BASE_URL'];
        rt_save_json(rt_data_path('data/site.json'), $siteData);
    }
    $store = rt_load_json(rt_data_path('data/store.json'), ['config' => [], 'products' => [], 'categories' => []]);
    if (is_array($store)) {
        if (!isset($store['config']) || !is_array($store['config'])) $store['config'] = [];
        $store['config']['whatsapp_phone'] = $updates['WHATSAPP_NUMBER'];
        rt_save_json(rt_data_path('data/store.json'), $store);
    }
    require_once rt_data_path('php-backend/scripts/build_content.php');
    $build = rt_build_content(false);
    rt_append_activity('dashboard_settings_update', ['backend' => 'php']);
    rt_json_response(['ok' => true, 'config' => rt_dashboard_config(), 'sync' => ['mode' => 'php-local'], 'build' => $build]);
}

if ($method === 'POST' && $path === '/api/upload') {
    rt_require_admin();
    $payload = rt_read_json_body();
    $files = $payload['files'] ?? [];
    if (!is_array($files) || count($files) === 0) rt_json_response(['error' => 'no files provided'], 400);
    $targetDir = rt_data_path('assets/images/store');
    if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);
    $saved = [];
    foreach ($files as $item) {
        if (!is_array($item)) continue;
        $filename = (string)($item['filename'] ?? '');
        $content = (string)($item['content'] ?? '');
        if ($filename === '' || !str_starts_with($content, 'data:') || !str_contains($content, ',')) continue;
        [, $encoded] = explode(',', $content, 2);
        $binary = base64_decode($encoded, true);
        if ($binary === false) continue;
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION) ?: 'bin');
        $stem = rt_slugify(pathinfo($filename, PATHINFO_FILENAME));
        $safe = $stem . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)) . '.' . $ext;
        file_put_contents($targetDir . '/' . $safe, $binary, LOCK_EX);
        $saved[] = ['filename' => $safe, 'url' => '/assets/images/store/' . $safe];
    }
    rt_json_response(['files' => $saved]);
}

if ($method === 'POST' && $path === '/api/blog/save') {
    rt_require_admin();
    require_once rt_data_path('php-backend/scripts/build_content.php');
    $payload = rt_read_json_body();
    $slug = rt_slugify((string)($payload['slug'] ?? $payload['title_ar'] ?? ''));
    if ($slug === '') rt_json_response(['error' => 'missing slug'], 400);
    $payload['slug'] = $slug;
    $payload['updated_at'] = gmdate('c');
    $payload['status'] = $payload['status'] ?? 'draft';
    rt_save_json(rt_blog_article_path($slug), $payload);
    rt_append_activity('article_save', ['slug' => $slug, 'status' => $payload['status']]);
    $build = rt_build_content(false);
    rt_json_response(['ok' => true, 'article' => $payload, 'sync' => ['mode' => 'php-local'], 'build' => $build]);
}

if ($method === 'POST' && $path === '/api/blog/toggle-status') {
    rt_require_admin();
    require_once rt_data_path('php-backend/scripts/build_content.php');
    $payload = rt_read_json_body();
    $slug = rt_slugify((string)($payload['slug'] ?? ''));
    $pathToArticle = rt_blog_article_path($slug);
    $article = rt_load_json($pathToArticle, null);
    if (!is_array($article)) rt_json_response(['error' => 'article not found'], 404);
    $article['status'] = ($article['status'] ?? 'draft') === 'published' ? 'draft' : 'published';
    $article['updated_at'] = gmdate('c');
    if ($article['status'] === 'published' && empty($article['published_at'])) $article['published_at'] = $article['updated_at'];
    rt_save_json($pathToArticle, $article);
    rt_append_activity('article_toggle_status', ['slug' => $slug, 'status' => $article['status']]);
    $build = rt_build_content(false);
    rt_json_response(['ok' => true, 'article' => $article, 'sync' => ['mode' => 'php-local'], 'build' => $build]);
}

if ($method === 'POST' && $path === '/api/blog/delete') {
    rt_require_admin();
    require_once rt_data_path('php-backend/scripts/build_content.php');
    $payload = rt_read_json_body();
    $slug = rt_slugify((string)($payload['slug'] ?? ''));
    $pathToArticle = rt_blog_article_path($slug);
    if (!is_file($pathToArticle)) rt_json_response(['error' => 'article not found'], 404);
    unlink($pathToArticle);
    rt_append_activity('article_delete', ['slug' => $slug]);
    $build = rt_build_content(false);
    rt_json_response(['ok' => true, 'sync' => ['mode' => 'php-local'], 'build' => $build]);
}

if ($method === 'POST' && $path === '/api/build') {
    rt_require_admin();
    require_once rt_data_path('php-backend/scripts/build_content.php');
    $payload = rt_read_json_body();
    $dryRun = !empty($payload['dry_run']);
    $result = rt_build_content($dryRun);
    rt_append_activity('php_build', ['dry_run' => $dryRun, 'result' => $result]);
    rt_json_response(['ok' => true, 'result' => $result]);
}

if ($method === 'POST' && $path === '/api/blog/generate') {
    rt_require_admin();
    require_once rt_data_path('php-backend/scripts/generate_blog.php');
    require_once rt_data_path('php-backend/scripts/build_content.php');
    $payload = rt_read_json_body();
    $count = max(1, min(5, (int)($payload['count'] ?? 1)));
    $publishNow = array_key_exists('publish_now', $payload) ? (bool)$payload['publish_now'] : null;
    $dryRun = !empty($payload['dry_run']);
    $shouldBuild = array_key_exists('build', $payload) ? !empty($payload['build']) : !$dryRun;
    $generated = rt_generate_blog_batch($count, $publishNow, $dryRun);
    $build = (count($generated) > 0 && $shouldBuild) ? rt_build_content($dryRun) : ['skipped' => true, 'reason' => $shouldBuild ? 'no generated articles' : 'build not requested'];
    rt_append_activity('php_blog_generate_batch', ['count' => $count, 'publish_now' => $publishNow, 'generated' => $generated, 'build' => $build]);
    rt_json_response(['ok' => true, 'started' => false, 'count' => $count, 'publish_now' => $publishNow, 'dry_run' => $dryRun, 'generated' => $generated, 'sync' => ['mode' => 'php-local'], 'build' => $build]);
}

if ($method === 'POST' && $path === '/api/cron/generate-blog') {
    if (!rt_cron_authorized()) {
        rt_json_response(['error' => 'unauthorized'], 401);
    }
    require_once rt_data_path('php-backend/scripts/generate_blog.php');
    require_once rt_data_path('php-backend/scripts/build_content.php');
    $payload = rt_read_optional_json_body();
    $requestedCount = $payload['count'] ?? rt_env('DAILY_BLOG_POSTS', '1');
    $count = max(1, min(5, (int)$requestedCount ?: 1));
    $dryRun = !empty($payload['dry_run']);
    $generated = rt_generate_blog_batch($count, true, $dryRun);
    $build = (count($generated) > 0 && !$dryRun) ? rt_build_content(false) : ['skipped' => true, 'reason' => $dryRun ? 'dry run' : 'no generated articles'];
    rt_append_activity('cron_generate_blog', ['count' => $count, 'generated' => $generated, 'build' => $build]);
    rt_json_response(['ok' => true, 'started' => false, 'count' => $count, 'publish_now' => true, 'dry_run' => $dryRun, 'generated' => $generated, 'sync' => ['mode' => 'php-local'], 'build' => $build]);
}

if ($method === 'POST' && $path === '/api/seo/gsc/upload') {
    rt_require_admin();
    $payload = rt_read_json_body();
    $content = trim((string)($payload['content'] ?? ''));
    $decoded = json_decode($content, true);
    if ($content === '' || !is_array($decoded)) rt_json_response(['error' => 'invalid JSON'], 400);
    $private = rt_private_gsc_path();
    if (!is_dir(dirname($private))) mkdir(dirname($private), 0700, true);
    file_put_contents($private, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    @chmod($private, 0600);
    rt_append_activity('gsc_credentials_upload', ['backend' => 'php']);
    rt_json_response(['ok' => true]);
}

if ($method === 'POST' && $path === '/api/seo/brain') {
    rt_require_admin();
    $payload = rt_read_json_body();
    $action = trim((string)($payload['action'] ?? ''));
    require_once rt_data_path('php-backend/scripts/build_content.php');
    require_once rt_data_path('php-backend/scripts/seo_system.php');
    if ($action === 'from_url') {
        require_once rt_data_path('php-backend/scripts/generate_blog.php');
        $url = trim((string)($payload['url'] ?? ''));
        $topic = $url !== '' ? 'مراجعة مصدر: ' . parse_url($url, PHP_URL_HOST) : 'مقال جديد من مصدر خارجي';
        $siteData = rt_site_data();
        $article = rt_finalize_article(rt_fallback_article($topic, $siteData), $topic, $siteData, !empty($payload['publish_now']));
        $article = rt_seo_apply_humanizer_to_article($article);
        $duplicate = rt_seo_find_duplicate_intent($article);
        if ($duplicate) {
            rt_json_response(['ok' => false, 'error' => 'duplicate intent', 'duplicate' => $duplicate, 'state' => rt_seo_full_state()], 409);
        }
        $quality = rt_seo_record_quality_report($article);
        if (!$quality['passed']) $article['status'] = 'needs_edits';
        rt_save_json(rt_blog_article_path($article['slug']), $article);
        $build = rt_build_content(false);
        rt_json_response(['ok' => true, 'result' => ['slug' => $article['slug'], 'quality' => $quality], 'state' => rt_seo_full_state(), 'sync' => ['mode' => 'php-local'], 'build' => $build]);
    }
    $result = rt_seo_run_brain($action, $payload);
    if (empty($result['ok'])) rt_json_response($result, 400);
    rt_json_response($result + ['sync' => ['mode' => 'php-local']]);
}

if ($method === 'POST' && ($path === '/api/seo/cron' || $path === '/api/cron/seo-brain')) {
    if (!rt_cron_authorized()) {
        rt_json_response(['error' => 'unauthorized'], 401);
    }
    require_once rt_data_path('php-backend/scripts/build_content.php');
    require_once rt_data_path('php-backend/scripts/seo_system.php');
    $payload = rt_read_optional_json_body();
    $result = rt_seo_run_brain((string)($payload['action'] ?? 'full_run'), $payload);
    rt_append_activity('cron_seo_brain', ['result' => $result]);
    rt_json_response($result + ['sync' => ['mode' => 'php-local']]);
}

rt_json_response([
    'error' => 'endpoint not migrated to PHP yet',
    'path' => $path,
    'method' => $method,
], 501);
