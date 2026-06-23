<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    rt_json_response(['ok' => true], 204);
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
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
    rt_json_response([
        'admin_password_set' => rt_env('ADMIN_PASSWORD', '') !== '',
        'whatsapp_number' => rt_env('WHATSAPP_NUMBER', RESPIRATECH_DEFAULT_WHATSAPP),
        'site_base_url' => rt_env('SITE_BASE_URL', 'https://respira-tech.com'),
        'auto_publish_blogs' => rt_env('AUTO_PUBLISH_BLOGS', 'true') === 'true',
        'daily_blog_posts' => (int)rt_env('DAILY_BLOG_POSTS', '2'),
        'generate_blog_images' => rt_env('GENERATE_BLOG_IMAGES', 'true') !== 'false',
        'seo_daily_report_email' => rt_env('SEO_DAILY_REPORT_EMAIL', 'alihessien0@gmail.com'),
        'php_backend' => true,
    ]);
}

if ($method === 'POST' && $path === '/api/store/save') {
    rt_require_admin();
    $payload = rt_read_json_body();
    $existing = rt_load_json(rt_data_path('data/store.json'), []);
    $existingCount = is_array($existing['products'] ?? null) ? count($existing['products']) : 0;
    $incomingCount = is_array($payload['products'] ?? null) ? count($payload['products']) : 0;
    if ($existingCount > 0 && $incomingCount === 0 && empty($payload['confirm_clear'])) {
        rt_json_response(['error' => 'refusing to save 0 products over existing data; pass confirm_clear:true to override'], 409);
    }
    rt_save_json(rt_data_path('data/store.json'), $payload);
    rt_append_activity('store_save', ['products_count' => $incomingCount]);
    rt_json_response(['ok' => true, 'sync' => ['mode' => 'php-local']]);
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
    $payload = rt_read_json_body();
    $slug = rt_slugify((string)($payload['slug'] ?? $payload['title_ar'] ?? ''));
    if ($slug === '') rt_json_response(['error' => 'missing slug'], 400);
    $payload['slug'] = $slug;
    $payload['updated_at'] = gmdate('c');
    $payload['status'] = $payload['status'] ?? 'draft';
    rt_save_json(rt_blog_article_path($slug), $payload);
    rt_append_activity('article_save', ['slug' => $slug, 'status' => $payload['status']]);
    rt_json_response(['ok' => true, 'article' => $payload, 'sync' => ['mode' => 'php-local']]);
}

if ($method === 'POST' && $path === '/api/blog/toggle-status') {
    rt_require_admin();
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
    rt_json_response(['ok' => true, 'article' => $article, 'sync' => ['mode' => 'php-local']]);
}

if ($method === 'POST' && $path === '/api/blog/delete') {
    rt_require_admin();
    $payload = rt_read_json_body();
    $slug = rt_slugify((string)($payload['slug'] ?? ''));
    $pathToArticle = rt_blog_article_path($slug);
    if (!is_file($pathToArticle)) rt_json_response(['error' => 'article not found'], 404);
    unlink($pathToArticle);
    rt_append_activity('article_delete', ['slug' => $slug]);
    rt_json_response(['ok' => true, 'sync' => ['mode' => 'php-local']]);
}

rt_json_response([
    'error' => 'endpoint not migrated to PHP yet',
    'path' => $path,
    'method' => $method,
], 501);

