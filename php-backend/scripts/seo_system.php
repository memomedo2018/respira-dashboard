<?php
declare(strict_types=1);

function rt_seo_file(string $name): string {
    return rt_data_path('data/' . $name . '.json');
}

function rt_seo_read_list(string $name): array {
    $items = rt_load_json(rt_seo_file($name), []);
    return is_array($items) ? array_values($items) : [];
}

function rt_seo_write_list(string $name, array $items, int $limit = 1000): void {
    rt_save_json(rt_seo_file($name), array_slice(array_values($items), 0, $limit));
}

function rt_seo_prepend_unique(string $name, array $entry, array $keys, int $limit = 1000): void {
    $items = rt_seo_read_list($name);
    $filtered = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $same = true;
        foreach ($keys as $key) {
            if (($item[$key] ?? null) !== ($entry[$key] ?? null)) {
                $same = false;
                break;
            }
        }
        if (!$same) $filtered[] = $item;
    }
    array_unshift($filtered, $entry);
    rt_seo_write_list($name, $filtered, $limit);
}

function rt_seo_config(): array {
    $siteData = rt_load_json(rt_data_path('data/site.json'), []);
    $site = is_array($siteData['site'] ?? null) ? $siteData['site'] : [];
    $coreLinks = is_array($siteData['core_links'] ?? null) ? $siteData['core_links'] : [];
    return [
        'brand_name' => $site['name'] ?? 'Respira Tech',
        'brand_alternate_names' => ['RespiraTech', 'Respira Tech Egypt'],
        'base_url' => rtrim(rt_env('SITE_BASE_URL', $site['base_url'] ?? 'https://respira-tech.com'), '/'),
        'language' => 'ar',
        'product_category' => 'أجهزة CPAP و BiPAP وماسكات علاج اضطرابات النوم',
        'target_audience' => 'مرضى اضطرابات النوم ومقدمو الرعاية في مصر والعالم العربي',
        'primary_conversion_url' => '/contact/',
        'primary_conversion_label' => 'تواصل معنا عبر واتساب',
        'conversion_features' => [
            ['name' => 'استشارة اختيار جهاز CPAP أو BiPAP', 'url' => '/contact/', 'use_when' => 'القارئ يحتاج اختيار جهاز مناسب حسب تقرير الطبيب'],
            ['name' => 'متجر الماسكات والإكسسوارات', 'url' => '/store/', 'use_when' => 'القارئ يقارن بين الماسكات أو يبحث عن شراء منتج'],
            ['name' => 'صفحات الخدمات الطبية التثقيفية', 'url' => '/services/', 'use_when' => 'القارئ يحتاج فهم العلاج التنفسي المنزلي'],
        ],
        'dangerous_claims_to_avoid' => [
            'شفاء مضمون',
            'علاج مضمون',
            'بديل عن الطبيب',
            'تشخيص نهائي',
            'نتيجة مؤكدة لكل الحالات',
        ],
        'commercial_pages' => $coreLinks,
        'pillar_topics' => [
            ['slug' => 'cpap-guide', 'keyword' => 'أجهزة CPAP', 'intent' => 'informational/commercial', 'url' => '/services/cpap/'],
            ['slug' => 'bipap-guide', 'keyword' => 'أجهزة BiPAP', 'intent' => 'informational/commercial', 'url' => '/services/bipap/'],
            ['slug' => 'sleep-apnea-guide', 'keyword' => 'انقطاع النفس أثناء النوم', 'intent' => 'informational', 'url' => '/services/sleep-apnea/'],
            ['slug' => 'cpap-masks-guide', 'keyword' => 'ماسكات CPAP', 'intent' => 'commercial', 'url' => '/services/cpap-masks/'],
        ],
        'existing_paths' => array_values(array_unique(array_merge(
            ['/', '/store/', '/blog/', '/contact/', '/services/', '/services/cpap/', '/services/bipap/', '/services/sleep-apnea/', '/services/cpap-masks/'],
            array_map(fn($link) => (string)($link['url'] ?? ''), $coreLinks)
        ))),
        'automation' => [
            'daily_report_email' => rt_env('SEO_DAILY_REPORT_EMAIL', 'alihessien0@gmail.com'),
            'scheduler' => 'Hostinger cron protected endpoint',
            'avoid_lazy_cron_when_possible' => true,
        ],
        'search_console' => [
            'property_url' => rt_env('GSC_SITE_URL', rt_env('SITE_BASE_URL', $site['base_url'] ?? 'https://respira-tech.com')),
            'service_account_json_path' => rt_private_gsc_path(),
            'import_days' => 28,
        ],
        'quality_gate' => [
            'minimum_score' => 80,
            'require_internal_links' => 2,
            'require_conversion_cta' => true,
            'block_duplicate_intent' => true,
            'human_review_for_high_risk_topics' => false,
        ],
    ];
}

function rt_seo_plain(string $text): string {
    $text = preg_replace('/\[[^\]]+\]\([^)]+\)/u', ' ', $text) ?? $text;
    $text = preg_replace('/<[^>]+>/u', ' ', $text) ?? $text;
    $text = preg_replace('/[#*_>`~\\-]+/u', ' ', $text) ?? $text;
    return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
}

function rt_seo_tokens(string $text): array {
    $text = mb_strtolower(rt_seo_plain($text), 'UTF-8');
    preg_match_all('/[\p{Arabic}a-z0-9]{3,}/u', $text, $matches);
    $stop = array_flip(['هذا','هذه','التي','الذي','على','إلى','الى','من','في','عن','كيف','متى','لماذا','جهاز','أجهزة','دليل','شرح','مع','أو','وهي','وهو','the','and','for','with']);
    $tokens = [];
    foreach ($matches[0] as $token) {
        if (!isset($stop[$token])) $tokens[$token] = true;
    }
    return array_keys($tokens);
}

function rt_seo_similarity(string $left, string $right): int {
    $a = rt_seo_tokens($left);
    $b = rt_seo_tokens($right);
    if (!$a || !$b) return 0;
    $intersection = count(array_intersect($a, $b));
    $union = count(array_unique(array_merge($a, $b)));
    return $union > 0 ? (int)round(($intersection / $union) * 100) : 0;
}

function rt_seo_find_duplicate_intent(array $candidate, array $articles = []): ?array {
    $articles = $articles ?: rt_load_articles();
    $candidateText = implode(' ', [
        (string)($candidate['slug'] ?? ''),
        (string)($candidate['title_ar'] ?? $candidate['title'] ?? ''),
        (string)($candidate['primary_keyword'] ?? ''),
        (string)($candidate['search_intent'] ?? ''),
        (string)($candidate['excerpt'] ?? ''),
        (string)($candidate['cta_text'] ?? ''),
    ]);
    foreach ($articles as $article) {
        if (!is_array($article)) continue;
        if (($article['slug'] ?? '') !== '' && ($article['slug'] ?? '') === ($candidate['slug'] ?? null)) {
            return ['slug' => $article['slug'], 'score' => 100, 'reason' => 'same slug'];
        }
        $existingText = implode(' ', [
            (string)($article['slug'] ?? ''),
            (string)($article['title_ar'] ?? ''),
            (string)($article['primary_keyword'] ?? ''),
            (string)($article['search_intent'] ?? ''),
            (string)($article['excerpt'] ?? ''),
            (string)($article['cta_text'] ?? ''),
        ]);
        $score = rt_seo_similarity($candidateText, $existingText);
        if ($score >= 72) {
            return ['slug' => $article['slug'] ?? '', 'score' => $score, 'reason' => 'duplicate search intent'];
        }
    }
    return null;
}

function rt_seo_humanize_text(string $markdown): array {
    $replacements = [
        'crucial' => 'important',
        'vital' => 'important',
        'delve' => 'look at',
        'comprehensive' => 'clear',
        'robust' => 'reliable',
        'seamless' => 'simple',
        'unlock' => 'use',
        'game changer' => 'helpful change',
        'it is important to note' => 'note',
        'in conclusion' => 'الخلاصة',
        'من المهم أن نلاحظ' => 'انتبه إلى أن',
        'في الختام' => 'الخلاصة',
    ];
    $changed = [];
    foreach ($replacements as $from => $to) {
        $new = str_ireplace($from, $to, $markdown);
        if ($new !== $markdown) $changed[] = $from;
        $markdown = $new;
    }
    if (!preg_match('/لا\s+تفعل|لا\s+تعتمد|تجنب|ما\s+لا\s+يجب/u', $markdown)) {
        $markdown .= "\n\n## ما لا يجب فعله\n\nلا تعتمد على شراء جهاز أو ماسك بشكل عشوائي دون مراجعة تقرير الطبيب أو المختص، ولا تعتبر المعلومات العامة بديلًا عن التقييم الطبي.\n";
        $changed[] = 'added what-not-to-do';
    }
    if (!preg_match('/مثال|سيناريو|حالة/u', $markdown)) {
        $markdown .= "\n\n## مثال عملي\n\nإذا كان المستخدم يستيقظ مع كتمة أو جفاف فم أو تسريب هواء من الماسك، فالقرار العملي يبدأ بمراجعة المقاس ونوع الماسك ثم سؤال المختص عن ضبط الاستخدام بدل تغيير الجهاز مباشرة.\n";
        $changed[] = 'added scenario';
    }
    return ['text' => $markdown, 'changes' => $changed];
}

function rt_seo_quality_gate(array $article): array {
    $config = rt_seo_config();
    $markdown = (string)($article['content_markdown'] ?? '');
    $plain = rt_seo_plain($markdown);
    $words = preg_split('/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $warnings = [];
    $checks = [];
    $checks['title'] = !empty($article['title_ar']);
    $checks['lead_answers_intent'] = mb_strlen(mb_substr($plain, 0, 500, 'UTF-8'), 'UTF-8') > 120;
    $checks['word_count'] = count($words) >= 850;
    $checks['single_h1'] = preg_match_all('/(^|\n)#\s+/u', $markdown) === 1;
    $checks['has_h2'] = preg_match('/(^|\n)##\s+/u', $markdown) === 1;
    $checks['meta_title'] = !empty($article['meta_title']) && mb_strlen((string)$article['meta_title'], 'UTF-8') <= 65;
    $checks['meta_description'] = !empty($article['meta_description']) && mb_strlen((string)$article['meta_description'], 'UTF-8') <= 160;
    $checks['faq'] = !empty($article['faq']) && is_array($article['faq']) && count($article['faq']) >= 2;
    $checks['internal_links'] = !empty($article['internal_links']) && is_array($article['internal_links']) && count($article['internal_links']) >= (int)$config['quality_gate']['require_internal_links'];
    $checks['cta'] = !$config['quality_gate']['require_conversion_cta'] || !empty($article['cta_text']);
    $checks['medical_disclaimer'] = !empty($article['medical_disclaimer']);
    $checks['human_scenario'] = preg_match('/مثال|سيناريو|حالة|قرار عملي/u', $markdown) === 1;
    $checks['what_not_to_do'] = preg_match('/لا\s+تفعل|لا\s+تعتمد|تجنب|ما\s+لا\s+يجب/u', $markdown) === 1;

    foreach ($config['dangerous_claims_to_avoid'] as $claim) {
        if ($claim !== '' && mb_stripos($plain, $claim, 0, 'UTF-8') !== false) {
            $checks['no_dangerous_claims'] = false;
            $warnings[] = 'dangerous claim: ' . $claim;
        }
    }
    if (!isset($checks['no_dangerous_claims'])) $checks['no_dangerous_claims'] = true;

    foreach ($checks as $name => $ok) {
        if (!$ok) $warnings[] = $name;
    }
    $score = (int)round((count(array_filter($checks)) / max(1, count($checks))) * 100);
    $passed = $score >= (int)$config['quality_gate']['minimum_score'];
    return [
        'slug' => (string)($article['slug'] ?? ''),
        'title' => (string)($article['title_ar'] ?? ''),
        'score' => $score,
        'passed' => $passed,
        'status' => $passed ? 'passed' : 'needs_edits',
        'warnings' => $warnings,
        'checks' => $checks,
        'checked_at' => gmdate('c'),
    ];
}

function rt_seo_record_quality_report(array $article): array {
    $report = rt_seo_quality_gate($article);
    rt_seo_prepend_unique('seo_quality_reports', $report, ['slug'], 1000);
    if (!$report['passed']) {
        rt_seo_prepend_unique('seo_content_refresh_queue', [
            'url' => rt_seo_config()['base_url'] . '/blog/' . rawurlencode((string)$report['slug']) . '/',
            'slug' => $report['slug'],
            'reason' => 'quality_gate_failed',
            'priority' => 'high',
            'source' => 'quality_gate',
            'suggested_action' => 'راجع التحذيرات وعدل المقال قبل الاعتماد طويل المدى.',
            'details' => $report,
            'status' => 'queued',
            'created_at' => gmdate('c'),
        ], ['slug', 'reason', 'status'], 1000);
    }
    return $report;
}

function rt_seo_apply_humanizer_to_article(array $article): array {
    $result = rt_seo_humanize_text((string)($article['content_markdown'] ?? ''));
    $article['content_markdown'] = $result['text'];
    $article['humanized_at'] = gmdate('c');
    $article['humanizer_changes'] = $result['changes'];
    return $article;
}

function rt_seo_run_quality_gates(): array {
    $reports = [];
    foreach (rt_load_articles() as $article) {
        if (!is_array($article)) continue;
        $reports[] = rt_seo_record_quality_report($article);
    }
    return ['checked' => count($reports), 'failed' => count(array_filter($reports, fn($r) => empty($r['passed']))), 'reports' => $reports];
}

function rt_seo_run_humanizer(bool $write = true): array {
    $changed = [];
    foreach (rt_load_articles() as $article) {
        if (!is_array($article) || empty($article['slug'])) continue;
        $before = (string)($article['content_markdown'] ?? '');
        $updated = rt_seo_apply_humanizer_to_article($article);
        if ($before !== (string)$updated['content_markdown']) {
            $changed[] = ['slug' => $updated['slug'], 'changes' => $updated['humanizer_changes']];
            if ($write) rt_save_json(rt_blog_article_path((string)$updated['slug']), $updated);
        }
    }
    return ['changed_count' => count($changed), 'changes' => $changed];
}

function rt_seo_internal_link_optimizer(bool $write = true): array {
    $siteData = rt_load_json(rt_data_path('data/site.json'), []);
    $coreLinks = is_array($siteData['core_links'] ?? null) ? $siteData['core_links'] : [];
    $published = array_values(array_filter(rt_load_articles(), fn($a) => is_array($a) && ($a['status'] ?? '') === 'published'));
    $changes = [];
    foreach (rt_load_articles() as $article) {
        if (!is_array($article) || empty($article['slug'])) continue;
        $links = is_array($article['internal_links'] ?? null) ? $article['internal_links'] : [];
        foreach ($coreLinks as $link) {
            if (!is_array($link) || empty($link['url']) || empty($link['anchor'])) continue;
            $exists = false;
            foreach ($links as $existing) {
                if (($existing['url'] ?? '') === $link['url']) $exists = true;
            }
            if (!$exists) $links[] = $link;
            if (count($links) >= 4) break;
        }
        foreach ($published as $target) {
            if (($target['slug'] ?? '') === ($article['slug'] ?? '')) continue;
            $url = '/blog/' . $target['slug'] . '/';
            $exists = false;
            foreach ($links as $existing) {
                if (($existing['url'] ?? '') === $url) $exists = true;
            }
            if (!$exists) {
                $links[] = ['anchor' => mb_substr((string)($target['title_ar'] ?? $target['slug']), 0, 70, 'UTF-8'), 'url' => $url];
                break;
            }
        }
        if (count($links) !== count($article['internal_links'] ?? [])) {
            $article['internal_links'] = array_slice($links, 0, 5);
            $article['updated_at'] = gmdate('c');
            $changes[] = ['slug' => $article['slug'], 'links_count' => count($article['internal_links'])];
            foreach ($article['internal_links'] as $link) {
                rt_seo_prepend_unique('seo_internal_links', [
                    'source_url' => '/blog/' . $article['slug'] . '/',
                    'target_url' => (string)($link['url'] ?? ''),
                    'source_slug' => (string)$article['slug'],
                    'target_slug' => rt_slugify((string)($link['anchor'] ?? $link['url'] ?? '')),
                    'anchor_text' => (string)($link['anchor'] ?? ''),
                    'reason' => 'cluster/product linking',
                    'status' => $write ? 'inserted' : 'planned',
                    'created_at' => gmdate('c'),
                ], ['source_url', 'target_url', 'anchor_text'], 1000);
            }
            if ($write) rt_save_json(rt_blog_article_path((string)$article['slug']), $article);
        }
    }
    return ['changed_count' => count($changes), 'changes' => $changes];
}

function rt_seo_public_urls(): array {
    $base = rt_seo_config()['base_url'];
    $urls = ['/', '/store/', '/blog/', '/contact/', '/about/', '/services/', '/services/cpap/', '/services/bipap/', '/services/sleep-apnea/', '/services/cpap-masks/'];
    foreach (rt_load_articles() as $article) {
        if (is_array($article) && ($article['status'] ?? '') === 'published' && !empty($article['slug'])) $urls[] = '/blog/' . $article['slug'] . '/';
    }
    $store = rt_load_json(rt_data_path('data/store.json'), ['products' => []]);
    foreach (($store['products'] ?? []) as $product) {
        if (is_array($product) && !empty($product['slug'])) $urls[] = '/store/' . $product['slug'] . '/';
    }
    return array_values(array_unique(array_map(fn($path) => $base . $path, $urls)));
}

function rt_seo_fetch_url(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'RespiraTechSEO/1.0',
    ]);
    $html = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    return ['url' => $url, 'status' => $status, 'html' => is_string($html) ? $html : '', 'error' => $error];
}

function rt_seo_technical_audit(): array {
    $pages = [];
    $issues = [];
    foreach (rt_seo_public_urls() as $url) {
        $result = rt_seo_fetch_url($url);
        $html = $result['html'];
        $page = [
            'url' => $url,
            'status' => $result['status'],
            'has_title' => preg_match('/<title>.+?<\/title>/is', $html) === 1,
            'has_meta_description' => preg_match('/<meta[^>]+name=["\']description["\']/i', $html) === 1,
            'has_canonical' => preg_match('/<link[^>]+rel=["\']canonical["\']/i', $html) === 1,
            'h1_count' => preg_match_all('/<h1\b/i', $html),
            'has_schema' => str_contains($html, 'application/ld+json'),
        ];
        foreach (['has_title', 'has_meta_description', 'has_canonical', 'has_schema'] as $check) {
            if (!$page[$check]) $issues[] = ['type' => $check, 'severity' => 'high', 'url' => $url];
        }
        if ($page['status'] !== 200) $issues[] = ['type' => 'http_status', 'severity' => 'high', 'url' => $url, 'status' => $page['status']];
        if ($page['h1_count'] !== 1) $issues[] = ['type' => 'h1_count', 'severity' => 'medium', 'url' => $url, 'count' => $page['h1_count']];
        $pages[] = $page;
    }
    $audit = ['generated_at' => gmdate('c'), 'backend' => 'php', 'pages' => $pages, 'issues' => $issues, 'summary' => ['pages' => count($pages), 'issues' => count($issues)]];
    rt_save_json(rt_seo_file('seo_audit'), $audit);
    foreach ($issues as $issue) {
        rt_seo_prepend_unique('seo_recommendations', [
            'source' => 'technical_audit',
            'recommendation_type' => (string)$issue['type'],
            'priority' => $issue['severity'] === 'high' ? 'high' : 'normal',
            'url' => $issue['url'],
            'query_text' => null,
            'title' => 'إصلاح مشكلة SEO تقنية: ' . $issue['type'],
            'details' => $issue,
            'status' => 'open',
            'created_at' => gmdate('c'),
        ], ['source', 'recommendation_type', 'url', 'status'], 1000);
    }
    return $audit;
}

function rt_seo_gsc_credentials(): ?array {
    $path = rt_private_gsc_path();
    if (!is_file($path)) return null;
    $json = json_decode((string)file_get_contents($path), true);
    return is_array($json) ? $json : null;
}

function rt_seo_base64url(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function rt_seo_google_token(): array {
    $creds = rt_seo_gsc_credentials();
    if (!$creds || empty($creds['client_email']) || empty($creds['private_key'])) {
        return ['ok' => false, 'error' => 'gsc service account credentials not configured'];
    }
    $now = time();
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $claim = [
        'iss' => $creds['client_email'],
        'scope' => 'https://www.googleapis.com/auth/webmasters https://www.googleapis.com/auth/webmasters.readonly',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600,
    ];
    $unsigned = rt_seo_base64url(json_encode($header)) . '.' . rt_seo_base64url(json_encode($claim));
    $signature = '';
    if (!openssl_sign($unsigned, $signature, (string)$creds['private_key'], OPENSSL_ALGO_SHA256)) {
        return ['ok' => false, 'error' => 'failed to sign google jwt'];
    }
    $jwt = $unsigned . '.' . rt_seo_base64url($signature);
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    $payload = json_decode((string)$raw, true);
    if ($status < 200 || $status >= 300 || !is_array($payload) || empty($payload['access_token'])) {
        return ['ok' => false, 'error' => $error ?: ('google token http ' . $status), 'body' => is_string($raw) ? mb_substr($raw, 0, 500, 'UTF-8') : ''];
    }
    return ['ok' => true, 'access_token' => $payload['access_token']];
}

function rt_seo_google_request(string $method, string $url, ?array $body = null): array {
    $token = rt_seo_google_token();
    if (empty($token['ok'])) return $token;
    $ch = curl_init($url);
    $headers = ['Authorization: Bearer ' . $token['access_token'], 'Content-Type: application/json'];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $headers]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    } elseif ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    }
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    $payload = json_decode((string)$raw, true);
    return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'error' => $error, 'payload' => is_array($payload) ? $payload : $raw];
}

function rt_seo_gsc_import(int $days = 28): array {
    $config = rt_seo_config();
    $to = gmdate('Y-m-d', strtotime('-1 day'));
    $from = gmdate('Y-m-d', strtotime('-' . max(1, $days) . ' days'));
    $property = $config['search_console']['property_url'];
    $url = 'https://searchconsole.googleapis.com/webmasters/v3/sites/' . rawurlencode($property) . '/searchAnalytics/query';
    $response = rt_seo_google_request('POST', $url, [
        'startDate' => $from,
        'endDate' => $to,
        'dimensions' => ['query', 'page'],
        'rowLimit' => 250,
    ]);
    if (empty($response['ok'])) {
        rt_seo_prepend_unique('seo_errors', ['job' => 'gsc_import', 'error' => $response['error'] ?? 'failed', 'details' => $response, 'created_at' => gmdate('c')], ['job', 'created_at'], 200);
        return ['ok' => false, 'rows_imported' => 0, 'error' => $response['error'] ?? 'gsc import failed', 'details' => $response];
    }
    $rows = [];
    foreach (($response['payload']['rows'] ?? []) as $row) {
        $keys = $row['keys'] ?? [];
        $rows[] = [
            'query_text' => (string)($keys[0] ?? ''),
            'page_url' => (string)($keys[1] ?? ''),
            'clicks' => (int)($row['clicks'] ?? 0),
            'impressions' => (int)($row['impressions'] ?? 0),
            'ctr' => (float)($row['ctr'] ?? 0),
            'avg_position' => (float)($row['position'] ?? 0),
            'date_from' => $from,
            'date_to' => $to,
            'imported_at' => gmdate('c'),
        ];
    }
    rt_seo_write_list('seo_gsc_query_pages', $rows, 2000);
    return ['ok' => true, 'rows_imported' => count($rows), 'date_from' => $from, 'date_to' => $to];
}

function rt_seo_generate_recommendations_from_gsc(): array {
    $created = 0;
    foreach (rt_seo_read_list('seo_gsc_query_pages') as $row) {
        if (!is_array($row)) continue;
        $impressions = (int)($row['impressions'] ?? 0);
        $ctr = (float)($row['ctr'] ?? 0);
        $pos = (float)($row['avg_position'] ?? 0);
        $type = '';
        $title = '';
        $priority = 'normal';
        if ($impressions >= 50 && $ctr < 0.015) {
            $type = 'rewrite_title_meta';
            $title = 'ظهور عالي وCTR ضعيف: حسّن العنوان والوصف';
            $priority = 'high';
        } elseif ($pos >= 4 && $pos <= 10) {
            $type = 'expand_section_internal_links';
            $title = 'ترتيب قريب من الصفحة الأولى: أضف روابط داخلية وتوسيع';
        } elseif ($pos > 10 && $pos <= 30) {
            $type = 'content_refresh';
            $title = 'ترتيب 11-30: حدّث المحتوى أو أضف مقال داعم';
        }
        if ($type === '') continue;
        rt_seo_prepend_unique('seo_recommendations', [
            'source' => 'gsc',
            'recommendation_type' => $type,
            'priority' => $priority,
            'url' => (string)($row['page_url'] ?? ''),
            'query_text' => (string)($row['query_text'] ?? ''),
            'title' => $title,
            'details' => $row,
            'status' => 'open',
            'created_at' => gmdate('c'),
        ], ['source', 'recommendation_type', 'url', 'query_text', 'status'], 1000);
        $created++;
    }
    return ['created' => $created];
}

function rt_seo_submit_sitemap(): array {
    $property = rt_seo_config()['search_console']['property_url'];
    $sitemap = rt_seo_config()['base_url'] . '/sitemap.xml';
    $url = 'https://searchconsole.googleapis.com/webmasters/v3/sites/' . rawurlencode($property) . '/sitemaps/' . rawurlencode($sitemap);
    $response = rt_seo_google_request('PUT', $url);
    if (empty($response['ok'])) {
        rt_seo_prepend_unique('seo_errors', ['job' => 'submit_sitemap', 'error' => $response['error'] ?? 'failed', 'details' => $response, 'created_at' => gmdate('c')], ['job', 'created_at'], 200);
    }
    return $response + ['sitemap' => $sitemap];
}

function rt_seo_seed_indexing_queue(): int {
    $count = 0;
    foreach (rt_seo_public_urls() as $url) {
        rt_seo_prepend_unique('seo_indexing_queue', [
            'url' => $url,
            'reason' => 'canonical public URL check',
            'priority' => str_contains($url, '/blog/') ? 'normal' : 'high',
            'status' => 'queued',
            'source_action' => 'seo_brain',
            'created_at' => gmdate('c'),
        ], ['url', 'status'], 1000);
        $count++;
    }
    return $count;
}

function rt_seo_url_inspection(int $limit = 10): array {
    $queue = rt_seo_read_list('seo_indexing_queue');
    if (!$queue) rt_seo_seed_indexing_queue();
    $queue = rt_seo_read_list('seo_indexing_queue');
    $property = rt_seo_config()['search_console']['property_url'];
    $inspected = [];
    foreach ($queue as &$item) {
        if (!is_array($item) || ($item['status'] ?? '') !== 'queued') continue;
        $response = rt_seo_google_request('POST', 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect', [
            'inspectionUrl' => $item['url'],
            'siteUrl' => $property,
        ]);
        $inspection = [
            'url' => $item['url'],
            'ok' => !empty($response['ok']),
            'raw' => $response['payload'] ?? $response,
            'inspected_at' => gmdate('c'),
        ];
        if (!empty($response['ok'])) {
            $result = $response['payload']['inspectionResult']['indexStatusResult'] ?? [];
            $inspection += [
                'verdict' => $result['verdict'] ?? null,
                'coverage_state' => $result['coverageState'] ?? null,
                'indexing_state' => $result['indexingState'] ?? null,
                'robots_txt_state' => $result['robotsTxtState'] ?? null,
                'page_fetch_state' => $result['pageFetchState'] ?? null,
                'google_canonical' => $result['googleCanonical'] ?? null,
                'user_canonical' => $result['userCanonical'] ?? null,
            ];
            if (($inspection['verdict'] ?? '') === 'PASS') $item['status'] = 'resolved';
        } else {
            $item['last_error'] = $response['error'] ?? 'inspection failed';
        }
        rt_seo_prepend_unique('seo_url_inspections', $inspection, ['url'], 1000);
        $inspected[] = $inspection;
        if (count($inspected) >= $limit) break;
    }
    unset($item);
    rt_seo_write_list('seo_indexing_queue', $queue, 1000);
    return ['inspected' => count($inspected), 'items' => $inspected];
}

function rt_seo_content_refresh_queue_from_quality(): array {
    $queued = 0;
    foreach (rt_seo_read_list('seo_quality_reports') as $report) {
        if (!is_array($report) || !empty($report['passed'])) continue;
        rt_seo_prepend_unique('seo_content_refresh_queue', [
            'url' => rt_seo_config()['base_url'] . '/blog/' . rawurlencode((string)$report['slug']) . '/',
            'slug' => (string)$report['slug'],
            'reason' => 'quality warning: ' . implode(', ', array_slice($report['warnings'] ?? [], 0, 4)),
            'priority' => 'high',
            'source' => 'quality_gate',
            'suggested_action' => 'تحسين lead/CTA/FAQ/internal links حسب التحذيرات.',
            'details' => $report,
            'status' => 'queued',
            'created_at' => gmdate('c'),
        ], ['slug', 'status'], 1000);
        $queued++;
    }
    return ['queued' => $queued];
}

function rt_seo_outreach_task(): array {
    $task = [
        'opportunity_type' => 'partnership',
        'title' => 'تواصل مع طبيب/مركز نوم لمراجعة محتوى تثقيفي أو مشاركة مصدر',
        'target_url' => rt_seo_config()['base_url'] . '/blog/',
        'suggested_text' => 'اقترح مشاركة مقال تثقيفي عن CPAP أو انقطاع النفس أثناء النوم مع مركز نوم أو عيادة صدر موثوقة.',
        'priority' => 'normal',
        'status' => 'queued',
        'created_at' => gmdate('c'),
    ];
    rt_seo_prepend_unique('seo_outreach_tasks', $task, ['opportunity_type', 'target_url', 'status'], 100);
    return $task;
}

function rt_seo_daily_report(array $jobs): array {
    $report = [
        'created_at' => gmdate('c'),
        'email_to' => rt_env('SEO_DAILY_REPORT_EMAIL', 'alihessien0@gmail.com'),
        'jobs' => $jobs,
        'numbers' => [
            'gsc_rows' => count(rt_seo_read_list('seo_gsc_query_pages')),
            'open_recommendations' => count(array_filter(rt_seo_read_list('seo_recommendations'), fn($i) => is_array($i) && ($i['status'] ?? 'open') === 'open')),
            'indexing_queue' => count(array_filter(rt_seo_read_list('seo_indexing_queue'), fn($i) => is_array($i) && ($i['status'] ?? '') === 'queued')),
            'url_inspections' => count(rt_seo_read_list('seo_url_inspections')),
            'quality_warnings' => count(array_filter(rt_seo_read_list('seo_quality_reports'), fn($i) => is_array($i) && empty($i['passed']))),
            'refresh_queue' => count(array_filter(rt_seo_read_list('seo_content_refresh_queue'), fn($i) => is_array($i) && ($i['status'] ?? '') === 'queued')),
            'outreach_tasks' => count(array_filter(rt_seo_read_list('seo_outreach_tasks'), fn($i) => is_array($i) && ($i['status'] ?? '') === 'queued')),
        ],
        'human_action' => 'راجع توصيات SEO المفتوحة ومهمة outreach واحدة هذا الأسبوع.',
    ];
    rt_seo_prepend_unique('seo_daily_reports', $report, ['created_at'], 365);
    return $report;
}

function rt_seo_full_state(): array {
    return [
        'settings' => rt_seo_state()['settings'],
        'config' => rt_seo_config(),
        'audit' => rt_load_json(rt_seo_file('seo_audit'), []),
        'logs' => rt_seo_read_list('seo_brain_log'),
        'gsc_rows' => rt_seo_read_list('seo_gsc_query_pages'),
        'recommendations' => rt_seo_read_list('seo_recommendations'),
        'indexing_queue' => rt_seo_read_list('seo_indexing_queue'),
        'url_inspections' => rt_seo_read_list('seo_url_inspections'),
        'quality_reports' => rt_seo_read_list('seo_quality_reports'),
        'internal_links' => rt_seo_read_list('seo_internal_links'),
        'content_refresh_queue' => rt_seo_read_list('seo_content_refresh_queue'),
        'content_refresh_blocks' => rt_seo_read_list('seo_content_refresh_blocks'),
        'outreach_tasks' => rt_seo_read_list('seo_outreach_tasks'),
        'daily_reports' => rt_seo_read_list('seo_daily_reports'),
        'errors' => rt_seo_read_list('seo_errors'),
    ];
}

function rt_seo_run_brain(string $action, array $payload = []): array {
    $jobs = [];
    if ($action === 'audit') {
        $jobs['technical_audit'] = rt_seo_technical_audit();
        $jobs['quality_gate'] = rt_seo_run_quality_gates();
    } elseif ($action === 'refresh_links') {
        $jobs['internal_links'] = rt_seo_internal_link_optimizer(true);
        $jobs['build'] = rt_build_content(false);
    } elseif ($action === 'gsc_import') {
        $jobs['gsc_import'] = rt_seo_gsc_import((int)($payload['days'] ?? 28));
        $jobs['recommendations'] = rt_seo_generate_recommendations_from_gsc();
    } elseif ($action === 'url_inspection') {
        $jobs['indexing_seeded'] = rt_seo_seed_indexing_queue();
        $jobs['url_inspection'] = rt_seo_url_inspection((int)($payload['limit'] ?? 10));
    } elseif ($action === 'submit_sitemap') {
        $jobs['submit_sitemap'] = rt_seo_submit_sitemap();
    } elseif ($action === 'humanize') {
        $jobs['humanizer'] = rt_seo_run_humanizer(true);
        $jobs['quality_gate'] = rt_seo_run_quality_gates();
        $jobs['build'] = rt_build_content(false);
    } elseif ($action === 'quality_gate') {
        $jobs['quality_gate'] = rt_seo_run_quality_gates();
        $jobs['refresh_queue'] = rt_seo_content_refresh_queue_from_quality();
    } elseif ($action === 'daily_report') {
        $jobs['outreach_task'] = rt_seo_outreach_task();
        $jobs['daily_report'] = rt_seo_daily_report($jobs);
    } elseif ($action === 'full_run') {
        $jobs['submit_sitemap'] = rt_seo_submit_sitemap();
        $jobs['gsc_import'] = rt_seo_gsc_import((int)($payload['days'] ?? 28));
        $jobs['recommendations'] = rt_seo_generate_recommendations_from_gsc();
        $jobs['indexing_seeded'] = rt_seo_seed_indexing_queue();
        $jobs['url_inspection'] = rt_seo_url_inspection((int)($payload['limit'] ?? 10));
        $jobs['technical_audit'] = rt_seo_technical_audit();
        $jobs['internal_links'] = rt_seo_internal_link_optimizer(true);
        $jobs['humanizer'] = rt_seo_run_humanizer(true);
        $jobs['quality_gate'] = rt_seo_run_quality_gates();
        $jobs['refresh_queue'] = rt_seo_content_refresh_queue_from_quality();
        $jobs['outreach_task'] = rt_seo_outreach_task();
        $jobs['build'] = rt_build_content(false);
        $jobs['daily_report'] = rt_seo_daily_report($jobs);
    } else {
        return ['ok' => false, 'error' => 'unknown action'];
    }
    rt_seo_prepend_unique('seo_brain_log', ['type' => $action, 'created_at' => gmdate('c'), 'backend' => 'php', 'jobs' => array_keys($jobs)], ['type', 'created_at'], 500);
    return ['ok' => true, 'action' => $action, 'jobs' => $jobs, 'state' => rt_seo_full_state()];
}
