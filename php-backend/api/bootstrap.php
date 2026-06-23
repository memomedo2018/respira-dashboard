<?php
declare(strict_types=1);

const RESPIRATECH_DEFAULT_WHATSAPP = '201010317647';

function rt_base_dir(): string {
    $env = getenv('RESPIRATECH_BASE_DIR');
    if ($env && is_dir($env)) return rtrim((string)$env, '/');

    $candidates = [
        __DIR__ . '/..',
        __DIR__ . '/../..',
    ];
    foreach ($candidates as $candidate) {
        $resolved = realpath($candidate);
        if ($resolved && is_file($resolved . '/data/store.json')) {
            return $resolved;
        }
    }

    return realpath(__DIR__ . '/..') ?: dirname(__DIR__);
}

function rt_data_path(string $relative): string {
    return rt_base_dir() . '/' . ltrim($relative, '/');
}

function rt_json_response($payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Admin-Password, X-Cron-Secret, Authorization');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function rt_read_json_body(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw !== false ? $raw : '', true);
    if (!is_array($data)) {
        rt_json_response(['error' => 'invalid JSON payload'], 400);
    }
    return $data;
}

function rt_load_json(string $path, $default) {
    if (!is_file($path)) return $default;
    $data = json_decode((string)file_get_contents($path), true);
    return $data === null ? $default : $data;
}

function rt_save_json(string $path, $data): void {
    $dir = dirname($path);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false || file_put_contents($path, $encoded . "\n", LOCK_EX) === false) {
        rt_json_response(['error' => 'failed to save JSON'], 500);
    }
}

function rt_env(string $key, string $default = ''): string {
    $value = getenv($key);
    if ($value !== false && $value !== '') return (string)$value;
    $envPaths = [
        rt_private_env_path(),
        dirname(rt_base_dir(), 2) . '/respiratech_private/.env',
        rt_data_path('.env'),
    ];
    foreach ($envPaths as $envPath) {
        if (!is_file($envPath)) continue;
        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if ($line === '' || str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
            [$name, $raw] = explode('=', $line, 2);
            if (trim($name) === $key) return trim($raw);
        }
    }
    return $default;
}

function rt_private_env_path(): string {
    return dirname(rt_base_dir(), 3) . '/respiratech_private/.env';
}

function rt_private_gsc_path(): string {
    return dirname(rt_base_dir(), 3) . '/respiratech_private/gsc-service-account.json';
}

function rt_save_env_updates(array $updates): void {
    $path = rt_private_env_path();
    $dir = dirname($path);
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    $current = [];
    if (is_file($path)) {
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if ($line === '' || str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
            [$name, $raw] = explode('=', $line, 2);
            $current[trim($name)] = trim($raw);
        }
    }
    foreach ($updates as $key => $value) {
        if ($value === null) continue;
        $current[$key] = (string)$value;
    }
    $lines = [];
    foreach ($current as $key => $value) {
        $lines[] = $key . '=' . $value;
    }
    file_put_contents($path, implode("\n", $lines) . "\n", LOCK_EX);
    @chmod($path, 0600);
}

function rt_admin_authorized(): bool {
    $expected = rt_env('ADMIN_PASSWORD', '');
    if ($expected === '') return true;
    $provided = $_SERVER['HTTP_X_ADMIN_PASSWORD'] ?? '';
    return $provided === $expected || rawurldecode($provided) === $expected;
}

function rt_require_admin(): void {
    if (!rt_admin_authorized()) {
        rt_json_response(['error' => 'unauthorized'], 401);
    }
}

function rt_slugify(string $value): string {
    $value = trim(mb_strtolower($value, 'UTF-8'));
    $value = preg_replace('/[^\p{Arabic}a-z0-9._-]+/u', '-', $value) ?? '';
    $value = preg_replace('/-+/u', '-', $value) ?? '';
    return trim($value, '-');
}

function rt_blog_article_path(string $slug): string {
    return rt_data_path('data/blog_articles/' . rt_slugify($slug) . '.json');
}

function rt_load_articles(): array {
    $dir = rt_data_path('data/blog_articles');
    if (!is_dir($dir)) return [];
    $articles = [];
    foreach (glob($dir . '/*.json') ?: [] as $file) {
        $item = rt_load_json($file, null);
        if (is_array($item)) $articles[] = $item;
    }
    usort($articles, fn($a, $b) => strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? '')));
    return $articles;
}

function rt_append_activity(string $action, array $details = []): void {
    $path = rt_data_path('data/dashboard_activity_log.json');
    $items = rt_load_json($path, []);
    if (!is_array($items)) $items = [];
    array_unshift($items, [
        'action' => $action,
        'created_at' => gmdate('c'),
        'details' => $details,
    ]);
    rt_save_json($path, array_slice($items, 0, 200));
}

function rt_activity_logs(): array {
    $items = rt_load_json(rt_data_path('data/dashboard_activity_log.json'), []);
    return is_array($items) ? $items : [];
}

function rt_blog_logs(): array {
    $items = rt_load_json(rt_data_path('data/blog_generation_log.json'), []);
    return is_array($items) ? $items : [];
}

function rt_seo_state(): array {
    $audit = rt_load_json(rt_data_path('data/seo_audit.json'), []);
    $logs = rt_load_json(rt_data_path('data/seo_brain_log.json'), []);
    $readList = function (string $name): array {
        $items = rt_load_json(rt_data_path('data/' . $name . '.json'), []);
        return is_array($items) ? array_values($items) : [];
    };
    return [
        'settings' => [
            'auto' => rt_env('SEO_BRAIN_AUTO', 'true') !== 'false',
            'runs_per_day' => (int)rt_env('SEO_BRAIN_RUNS_PER_DAY', '2'),
            'gsc_site_url' => rt_env('GSC_SITE_URL', rt_env('SITE_BASE_URL', 'https://respira-tech.com')),
            'gsc_credentials_set' => is_file(rt_data_path('data/gsc-service-account.json')) || is_file(rt_private_gsc_path()) || is_file(dirname(rt_base_dir(), 2) . '/respiratech_private/gsc-service-account.json'),
        ],
        'audit' => is_array($audit) ? $audit : [],
        'logs' => is_array($logs) ? $logs : [],
        'gsc_rows' => $readList('seo_gsc_query_pages'),
        'recommendations' => $readList('seo_recommendations'),
        'indexing_queue' => $readList('seo_indexing_queue'),
        'url_inspections' => $readList('seo_url_inspections'),
        'quality_reports' => $readList('seo_quality_reports'),
        'internal_links' => $readList('seo_internal_links'),
        'content_refresh_queue' => $readList('seo_content_refresh_queue'),
        'outreach_tasks' => $readList('seo_outreach_tasks'),
        'daily_reports' => $readList('seo_daily_reports'),
        'errors' => $readList('seo_errors'),
    ];
}

function rt_dashboard_config(): array {
    $siteData = rt_load_json(rt_data_path('data/site.json'), []);
    $site = is_array($siteData['site'] ?? null) ? $siteData['site'] : [];
    $store = rt_load_json(rt_data_path('data/store.json'), ['config' => []]);
    $storeConfig = is_array($store['config'] ?? null) ? $store['config'] : [];
    return [
        'settings' => [
            'openai_api_key_set' => rt_env('OPENAI_API_KEY', '') !== '',
            'openai_api_key_masked' => rt_env('OPENAI_API_KEY', '') !== '' ? '********' : '',
            'auto_publish_blogs' => rt_env('AUTO_PUBLISH_BLOGS', 'true') === 'true',
            'daily_blog_posts' => (int)rt_env('DAILY_BLOG_POSTS', '2'),
            'generate_blog_images' => rt_env('GENERATE_BLOG_IMAGES', 'true') !== 'false',
            'openai_text_model' => rt_env('OPENAI_TEXT_MODEL', 'gpt-4.1'),
            'openai_image_model' => rt_env('OPENAI_IMAGE_MODEL', 'dall-e-3'),
            'whatsapp_number' => rt_env('WHATSAPP_NUMBER', $site['whatsapp_number'] ?? $storeConfig['whatsapp_phone'] ?? RESPIRATECH_DEFAULT_WHATSAPP),
            'site_base_url' => rt_env('SITE_BASE_URL', $site['base_url'] ?? 'https://respira-tech.com'),
            'admin_password_set' => rt_env('ADMIN_PASSWORD', '') !== '',
            'cron_secret_set' => rt_env('CRON_SECRET', '') !== '',
            'blog_publish_time' => '09:00 Africa/Cairo',
            'seo_brain_auto' => rt_env('SEO_BRAIN_AUTO', 'true') !== 'false',
            'seo_brain_runs_per_day' => (int)rt_env('SEO_BRAIN_RUNS_PER_DAY', '2'),
            'gsc_site_url' => rt_env('GSC_SITE_URL', rt_env('SITE_BASE_URL', 'https://respira-tech.com')),
            'gsc_credentials_set' => is_file(rt_data_path('data/gsc-service-account.json')) || is_file(rt_private_gsc_path()) || is_file(dirname(rt_base_dir(), 2) . '/respiratech_private/gsc-service-account.json'),
            'seo_daily_report_email' => rt_env('SEO_DAILY_REPORT_EMAIL', 'alihessien0@gmail.com'),
            'auto_push_changes' => false,
            'github_repo' => rt_env('GITHUB_REPO', ''),
            'github_branch' => rt_env('GITHUB_BRANCH', 'main'),
            'github_sync_configured' => rt_env('GITHUB_TOKEN', '') !== '' && rt_env('GITHUB_REPO', '') !== '',
            'ftp_server' => '',
            'ftp_username' => '',
            'ftp_password_set' => false,
            'ftp_remote_dir' => 'domains/respira-tech.com/public_html',
            'ftp_deploy_configured' => false,
            'php_backend' => true,
        ],
        'logs' => rt_blog_logs(),
        'articles' => rt_load_articles(),
        'seo_brain' => rt_seo_state(),
        'activity_logs' => rt_activity_logs(),
    ];
}
