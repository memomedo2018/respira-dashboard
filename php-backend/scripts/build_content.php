<?php
declare(strict_types=1);

function rt_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function rt_plain_text(string $markdown): string {
    $text = preg_replace('/`([^`]+)`/u', '$1', $markdown) ?? $markdown;
    $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/u', '$1', $text) ?? $text;
    $text = preg_replace('/^#+\s*/mu', '', $text) ?? $text;
    $text = preg_replace('/[*_~>#-]/u', ' ', $text) ?? $text;
    return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
}

function rt_reading_time(string $markdown): int {
    $words = preg_split('/\s+/u', rt_plain_text($markdown), -1, PREG_SPLIT_NO_EMPTY);
    return max(1, (int)ceil(count($words ?: []) / 220));
}

function rt_markdown_inline(string $text): string {
    $escaped = rt_h($text);
    $escaped = preg_replace('/\*\*([^*]+)\*\*/u', '<strong>$1</strong>', $escaped) ?? $escaped;
    $escaped = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/u', '<a href="$2">$1</a>', $escaped) ?? $escaped;
    return $escaped;
}

function rt_markdown_to_html(string $markdown): string {
    $lines = preg_split('/\R/u', $markdown) ?: [];
    $html = [];
    $inList = false;
    $paragraph = [];

    $flushParagraph = function () use (&$html, &$paragraph): void {
        if (!$paragraph) return;
        $html[] = '<p>' . rt_markdown_inline(implode(' ', $paragraph)) . '</p>';
        $paragraph = [];
    };
    $closeList = function () use (&$html, &$inList): void {
        if ($inList) {
            $html[] = '</ul>';
            $inList = false;
        }
    };

    foreach ($lines as $line) {
        $trim = trim($line);
        if ($trim === '') {
            $flushParagraph();
            $closeList();
            continue;
        }
        if (preg_match('/^(#{1,3})\s+(.+)$/u', $trim, $m)) {
            $flushParagraph();
            $closeList();
            $level = min(3, strlen($m[1]));
            $id = rt_slugify(rt_plain_text($m[2]));
            $html[] = "<h{$level} id=\"" . rt_h($id) . "\">" . rt_markdown_inline($m[2]) . "</h{$level}>";
            continue;
        }
        if (preg_match('/^[-*]\s+(.+)$/u', $trim, $m)) {
            $flushParagraph();
            if (!$inList) {
                $html[] = '<ul>';
                $inList = true;
            }
            $html[] = '<li>' . rt_markdown_inline($m[1]) . '</li>';
            continue;
        }
        if (str_starts_with($trim, '>')) {
            $flushParagraph();
            $closeList();
            $html[] = '<blockquote>' . rt_markdown_inline(ltrim(substr($trim, 1))) . '</blockquote>';
            continue;
        }
        $paragraph[] = $trim;
    }

    $flushParagraph();
    $closeList();
    return implode("\n", $html);
}

function rt_published_articles(): array {
    $articles = array_values(array_filter(rt_load_articles(), fn($item) => ($item['status'] ?? '') === 'published'));
    usort($articles, fn($a, $b) => strcmp((string)($b['published_at'] ?? $b['created_at'] ?? ''), (string)($a['published_at'] ?? $a['created_at'] ?? '')));
    foreach ($articles as &$article) {
        $article['reading_time'] = $article['reading_time'] ?? rt_reading_time((string)($article['content_markdown'] ?? ''));
        $article['featured_image'] = $article['featured_image'] ?? '/assets/images/store/respira-tech-logo.png';
        $article['author'] = $article['author'] ?? 'Respira Tech';
    }
    return $articles;
}

function rt_page_shell(string $title, string $description, string $canonical, string $body, string $type = 'website', string $image = '/assets/images/store/respira-tech-logo.png'): string {
    $base = rtrim(rt_env('SITE_BASE_URL', 'https://respira-tech.com'), '/');
    $imageUrl = str_starts_with($image, 'http') ? $image : $base . $image;
    return '<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>' . rt_h($title) . '</title>
  <meta name="description" content="' . rt_h($description) . '">
  <link rel="canonical" href="' . rt_h($canonical) . '">
  <meta property="og:locale" content="ar_EG">
  <meta property="og:type" content="' . rt_h($type) . '">
  <meta property="og:title" content="' . rt_h($title) . '">
  <meta property="og:description" content="' . rt_h($description) . '">
  <meta property="og:url" content="' . rt_h($canonical) . '">
  <meta property="og:site_name" content="Respira Tech">
  <meta property="og:image" content="' . rt_h($imageUrl) . '">
  <meta name="twitter:card" content="summary_large_image">
  <style>
    *{box-sizing:border-box}body{margin:0;font-family:Alexandria,Segoe UI,Tahoma,sans-serif;background:#f3f8fc;color:#0f172a;line-height:1.9}a{color:#0097b2;text-decoration:none}.topbar{background:rgba(255,255,255,.94);border-bottom:1px solid rgba(15,23,42,.08);position:sticky;top:0;z-index:10}.nav{max-width:1120px;margin:auto;padding:16px 20px;display:flex;justify-content:space-between;gap:16px;align-items:center}.brand{font-weight:900;color:#0097b2;font-size:24px}.links{display:flex;gap:12px;flex-wrap:wrap}.links a{color:#334155;font-weight:800}.hero{background:linear-gradient(135deg,#fff,#e8f6fc);padding:42px 20px}.hero-inner,.wrap{max-width:1120px;margin:auto}.hero h1{font-size:clamp(30px,5vw,54px);line-height:1.25;margin:0 0 14px}.hero p{max-width:860px;color:#475569;font-size:18px}.wrap{padding:32px 20px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:18px}.card,.article{background:#fff;border:1px solid rgba(15,23,42,.08);border-radius:24px;box-shadow:0 18px 42px rgba(15,23,42,.06)}.card{overflow:hidden}.card img{width:100%;aspect-ratio:16/10;object-fit:cover;background:#e2f3fb}.card-body{padding:18px}.card h2{font-size:21px;line-height:1.5;margin:0 0 10px}.meta{color:#64748b;font-size:14px;font-weight:800}.article{padding:min(5vw,44px);max-width:900px;margin:auto}.article img.featured{width:100%;max-height:420px;object-fit:cover;border-radius:22px;margin-bottom:22px;background:#e2f3fb}.article h2{font-size:28px;margin-top:34px}.article h3{font-size:22px;margin-top:26px}.article p,.article li{color:#334155}.article blockquote{border-right:4px solid #0097b2;background:#f0fbff;margin:22px 0;padding:14px 18px;border-radius:14px}.cta{margin-top:28px;background:#e9f8fc;border-radius:20px;padding:20px}.footer{padding:32px 20px;text-align:center;color:#64748b}@media(max-width:760px){.nav{align-items:flex-start;flex-direction:column}.article{padding:22px}}
  </style>
</head>
<body>
  <header class="topbar"><nav class="nav"><a class="brand" href="/">Respira Tech</a><div class="links"><a href="/store/">المتجر</a><a href="/blog/">المدونة</a><a href="/contact/">تواصل معنا</a></div></nav></header>
  ' . $body . '
  <footer class="footer">Respira Tech - دعم أجهزة CPAP و BiPAP وماسكات النوم</footer>
</body>
</html>';
}

function rt_write_file_if_needed(string $path, string $content, bool $dryRun): bool {
    if ($dryRun) return false;
    $dir = dirname($path);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    return file_put_contents($path, $content, LOCK_EX) !== false;
}

function rt_build_content(bool $dryRun = true): array {
    $baseUrl = rtrim(rt_env('SITE_BASE_URL', 'https://respira-tech.com'), '/');
    $articles = rt_published_articles();
    $written = 0;

    if (!$dryRun) {
        $officialBuilder = rt_data_path('build_content_hostinger.py');
        if (is_file($officialBuilder)) {
            $cmd = 'cd ' . escapeshellarg(rt_base_dir()) . ' && python3 ' . escapeshellarg($officialBuilder) . ' 2>&1';
            $output = [];
            $code = 0;
            exec($cmd, $output, $code);
            if ($code !== 0) {
                return [
                    'dry_run' => false,
                    'official_builder' => true,
                    'ok' => false,
                    'exit_code' => $code,
                    'output' => implode("\n", array_slice($output, -20)),
                ];
            }
            return [
                'dry_run' => false,
                'official_builder' => true,
                'ok' => true,
                'published_articles' => count($articles),
                'products' => is_array(rt_load_json(rt_data_path('data/store.json'), [])['products'] ?? null) ? count(rt_load_json(rt_data_path('data/store.json'), [])['products']) : 0,
                'output' => implode("\n", array_slice($output, -5)),
            ];
        }
    }

    $cards = '';
    foreach ($articles as $article) {
        $url = '/blog/' . rawurlencode((string)$article['slug']) . '/';
        $cards .= '<article class="card"><a href="' . rt_h($url) . '"><img src="' . rt_h((string)$article['featured_image']) . '" alt="' . rt_h((string)$article['title_ar']) . '"></a><div class="card-body"><div class="meta">' . rt_h((string)($article['category'] ?? '')) . ' · ' . rt_h((string)$article['reading_time']) . ' دقائق قراءة</div><h2><a href="' . rt_h($url) . '">' . rt_h((string)$article['title_ar']) . '</a></h2><p>' . rt_h((string)($article['excerpt'] ?? '')) . '</p></div></article>';
    }
    $blogBody = '<section class="hero"><div class="hero-inner"><h1>مدونة Respira Tech</h1><p>مقالات عربية عن أجهزة CPAP وBiPAP، واضطرابات النوم، والماسكات، والعلاج التنفسي المنزلي.</p></div></section><main class="wrap"><div class="grid">' . $cards . '</div></main>';
    if (rt_write_file_if_needed(rt_data_path('blog/index.html'), rt_page_shell('مدونة Respira Tech | مقالات عن CPAP و BiPAP واضطرابات النوم', 'مدونة Respira Tech تقدم مقالات عربية موثوقة عن أجهزة CPAP وBiPAP واضطرابات النوم.', $baseUrl . '/blog/', $blogBody), $dryRun)) $written++;

    foreach ($articles as $article) {
        $slug = (string)$article['slug'];
        $content = rt_markdown_to_html((string)($article['content_markdown'] ?? ''));
        $faq = '';
        if (!empty($article['faq']) && is_array($article['faq'])) {
            $items = '';
            foreach ($article['faq'] as $item) {
                if (!is_array($item)) continue;
                $items .= '<details><summary>' . rt_h((string)($item['question'] ?? '')) . '</summary><p>' . rt_h((string)($item['answer'] ?? '')) . '</p></details>';
            }
            if ($items !== '') $faq = '<section class="cta"><h2>الأسئلة الشائعة</h2>' . $items . '</section>';
        }
        $cta = '';
        if (!empty($article['cta_text'])) {
            $cta = '<section class="cta"><h2>هل تحتاج مساعدة؟</h2><p>' . rt_h((string)$article['cta_text']) . '</p><a href="' . rt_h((string)($article['cta_button_url'] ?? '/contact/')) . '">' . rt_h((string)($article['cta_button_text'] ?? 'تواصل معنا')) . '</a></section>';
        }
        $disclaimer = !empty($article['medical_disclaimer']) ? '<blockquote><strong>تنبيه مهم:</strong> ' . rt_h((string)$article['medical_disclaimer']) . '</blockquote>' : '';
        $body = '<section class="hero"><div class="hero-inner"><h1>' . rt_h((string)$article['title_ar']) . '</h1><p>' . rt_h((string)($article['excerpt'] ?? '')) . '</p><div class="meta">' . rt_h((string)($article['author'] ?? 'Respira Tech')) . ' · ' . rt_h((string)$article['reading_time']) . ' دقائق قراءة</div></div></section><main class="wrap"><article class="article"><img class="featured" src="' . rt_h((string)$article['featured_image']) . '" alt="' . rt_h((string)$article['title_ar']) . '">' . $content . $faq . $cta . $disclaimer . '</article></main>';
        $page = rt_page_shell((string)($article['meta_title'] ?? $article['title_ar']), (string)($article['meta_description'] ?? $article['excerpt'] ?? ''), $baseUrl . '/blog/' . rawurlencode($slug) . '/', $body, 'article', (string)$article['featured_image']);
        if (rt_write_file_if_needed(rt_data_path('blog/' . $slug . '/index.html'), $page, $dryRun)) $written++;
    }

    $store = rt_load_json(rt_data_path('data/store.json'), ['products' => [], 'categories' => [], 'config' => []]);
    if (!$dryRun) {
        rt_save_json(rt_data_path('store-data.json'), $store);
        $written++;
    }

    $urls = ['/', '/about/', '/services/', '/services/cpap/', '/services/bipap/', '/services/sleep-apnea/', '/services/cpap-masks/', '/store/', '/blog/', '/contact/', '/privacy-policy/', '/refund-policy/', '/terms/'];
    foreach ($articles as $article) $urls[] = '/blog/' . $article['slug'] . '/';
    if (!empty($store['products']) && is_array($store['products'])) {
        foreach ($store['products'] as $product) {
            if (!empty($product['slug'])) $urls[] = '/store/' . $product['slug'] . '/';
        }
    }
    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
    foreach (array_values(array_unique($urls)) as $url) {
        $xml .= "  <url><loc>" . rt_h($baseUrl . $url) . "</loc></url>\n";
    }
    $xml .= "</urlset>\n";
    if (rt_write_file_if_needed(rt_data_path('sitemap.xml'), $xml, $dryRun)) $written++;

    return [
        'dry_run' => $dryRun,
        'published_articles' => count($articles),
        'products' => is_array($store['products'] ?? null) ? count($store['products']) : 0,
        'planned_urls' => count(array_unique($urls)),
        'written_files' => $written,
    ];
}
