<?php
declare(strict_types=1);

const RESPIRATECH_DEFAULT_WHATSAPP = '201010317647';

function rt_base_dir(): string {
    return realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
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
    $envPath = rt_data_path('.env');
    if (!is_file($envPath)) return $default;
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if ($line === '' || str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$name, $raw] = explode('=', $line, 2);
        if (trim($name) === $key) return trim($raw);
    }
    return $default;
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

