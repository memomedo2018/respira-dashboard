<?php
declare(strict_types=1);

function rt_words_count(string $markdown): int {
    $plain = rt_plain_text($markdown);
    $words = preg_split('/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY);
    return count($words ?: []);
}

function rt_seo_score(array $article): int {
    $markdown = (string)($article['content_markdown'] ?? '');
    $checks = [
        !empty($article['title_ar']),
        !empty($article['meta_title']) && mb_strlen((string)$article['meta_title'], 'UTF-8') <= 60,
        !empty($article['meta_description']) && mb_strlen((string)$article['meta_description'], 'UTF-8') <= 160,
        preg_match('/(^|\n)#\s+/u', $markdown) === 1,
        preg_match('/(^|\n)##\s+/u', $markdown) === 1,
        !empty($article['faq']) && is_array($article['faq']),
        !empty($article['cta_text']),
        !empty($article['internal_links']) && is_array($article['internal_links']),
        !empty($article['medical_disclaimer']),
        !empty($article['category']),
        !empty($article['slug']),
        rt_words_count($markdown) >= 900,
    ];
    $ok = count(array_filter($checks));
    return (int)round($ok * 100 / count($checks));
}

function rt_site_data(): array {
    $site = rt_load_json(rt_data_path('data/site.json'), []);
    if (!is_array($site)) $site = [];
    $site['site'] = is_array($site['site'] ?? null) ? $site['site'] : [];
    $site['site']['name'] = $site['site']['name'] ?? 'Respira Tech';
    $site['site']['author'] = $site['site']['author'] ?? 'فريق Respira Tech';
    $site['site']['whatsapp_number'] = rt_env('WHATSAPP_NUMBER', $site['site']['whatsapp_number'] ?? RESPIRATECH_DEFAULT_WHATSAPP);
    $site['site']['medical_disclaimer'] = $site['site']['medical_disclaimer'] ?? 'هذا المحتوى للتثقيف فقط ولا يغني عن استشارة الطبيب أو المختص.';
    $site['core_links'] = is_array($site['core_links'] ?? null) ? $site['core_links'] : [
        ['anchor' => 'أجهزة CPAP', 'url' => '/services/cpap/'],
        ['anchor' => 'أجهزة BiPAP', 'url' => '/services/bipap/'],
        ['anchor' => 'انقطاع النفس أثناء النوم', 'url' => '/services/sleep-apnea/'],
        ['anchor' => 'ماسكات CPAP', 'url' => '/services/cpap-masks/'],
        ['anchor' => 'المتجر', 'url' => '/store/'],
    ];
    return $site;
}

function rt_derive_category(string $topic): string {
    if (stripos($topic, 'BiPAP') !== false) return 'أجهزة BiPAP';
    if (stripos($topic, 'CPAP') !== false) return 'أجهزة CPAP';
    if (str_contains($topic, 'ماسك') || str_contains($topic, 'الماسك')) return 'ماسكات وإكسسوارات';
    if (str_contains($topic, 'أكسجين')) return 'الأكسجين والتنفس المنزلي';
    if (str_contains($topic, 'الشخير')) return 'الشخير واضطرابات النوم';
    if (str_contains($topic, 'انقطاع النفس')) return 'انقطاع النفس أثناء النوم';
    return 'نصائح الاستخدام والعناية';
}

function rt_choose_topics(int $count): array {
    $topics = rt_load_json(rt_data_path('data/blog_topics.json'), []);
    if (!is_array($topics)) $topics = [];
    $articles = rt_load_articles();
    $usedTitles = [];
    foreach ($articles as $article) {
        if (!empty($article['title_ar'])) $usedTitles[(string)$article['title_ar']] = true;
    }
    $available = array_values(array_filter($topics, fn($topic) => is_string($topic) && !isset($usedTitles[$topic])));
    if (count($available) < $count) {
        $available = array_merge($available, array_values(array_filter($topics, fn($topic) => is_string($topic) && !in_array($topic, $available, true))));
    }
    return array_slice($available, 0, $count);
}

function rt_ensure_internal_links(array $article, array $siteData): array {
    $links = [];
    foreach (($article['internal_links'] ?? []) as $link) {
        if (is_array($link) && !empty($link['anchor']) && !empty($link['url'])) $links[] = $link;
    }
    foreach (($siteData['core_links'] ?? []) as $link) {
        if (!is_array($link) || empty($link['anchor']) || empty($link['url'])) continue;
        $exists = false;
        foreach ($links as $existing) {
            if (($existing['url'] ?? '') === $link['url']) $exists = true;
        }
        if (!$exists) $links[] = $link;
        if (count($links) >= 5) break;
    }
    return array_slice($links, 0, 5);
}

function rt_auto_link_markdown(string $markdown, array $links): string {
    foreach ($links as $link) {
        $anchor = (string)($link['anchor'] ?? '');
        $url = (string)($link['url'] ?? '');
        if ($anchor === '' || $url === '' || str_contains($markdown, '](' . $url . ')')) continue;
        $pattern = '/' . preg_quote($anchor, '/') . '/u';
        $markdown = preg_replace($pattern, '[' . $anchor . '](' . $url . ')', $markdown, 1) ?? $markdown;
    }
    return $markdown;
}

function rt_fallback_article(string $topic, array $siteData): array {
    $category = rt_derive_category($topic);
    $slug = rt_slugify($topic);
    $whatsapp = $siteData['site']['whatsapp_number'];
    $disclaimer = $siteData['site']['medical_disclaimer'];
    $content = "# {$topic}\n\nهذا المقال يشرح الموضوع بلغة عربية واضحة للقارئ الذي يبحث عن فهم أفضل قبل اتخاذ قرار يتعلق بالنوم أو أجهزة الدعم التنفسي المنزلي.\n\n## مقدمة\n\nيبحث كثير من الأشخاص عن معلومات حول اضطرابات النوم أو أجهزة العلاج التنفسي المنزلي، لكن المشكلة ليست فقط في كثرة المعلومات، بل في أن جزءًا كبيرًا منها إما مبالغ فيه أو غير واضح. لذلك نقدم شرحًا منظمًا يساعدك على فهم الفكرة الأساسية ومتى يكون من المناسب طلب تقييم متخصص.\n\n## لماذا يهم هذا الموضوع؟\n\nقد تظهر المشكلة في صورة شخير مستمر، أو تعب صباحي، أو صعوبة في التكيف مع جهاز معين، أو حيرة عند المقارنة بين أكثر من خيار. فهم الموضوع يساعدك على طرح الأسئلة الصحيحة، وفهم دور أجهزة CPAP أو BiPAP أو الماسكات عندما يوصي بها الطبيب.\n\n## شرح مبسط\n\nفي كثير من الحالات، يكون الهدف هو تحسين جودة النوم وتقليل الاضطرابات التي تؤثر على التنفس أثناء الليل. وقد يعتمد ذلك على تقييم الأعراض ونتائج الفحص أو دراسة النوم، ثم اختيار الجهاز أو الماسك المناسب بناءً على توصية الطبيب وطبيعة الاستخدام اليومي.\n\n### دور الجهاز أو الماسك\n\nنوع الجهاز أو الماسك قد يؤثر مباشرة على الراحة والالتزام اليومي. لذلك من المهم فهم الاختلافات العملية بين الخيارات المختلفة، وعدم الاعتماد فقط على السعر أو الشكل الخارجي. المتجر قد يكون نقطة بداية للتعرف على الخيارات، لكن القرار النهائي يجب أن يكون مبنيًا على فهم الحالة وتوصية المختص.\n\n### أهمية المتابعة بعد الشراء\n\nالكثير يظن أن المشكلة تنتهي بمجرد شراء الجهاز، لكن المتابعة بعد الشراء ضرورية لفهم أي صعوبات في الاستخدام أو عدم راحة في الماسك أو الحاجة إلى ضبط أفضل لطريقة الاستخدام.\n\n## متى تطلب المساعدة؟\n\nإذا كانت الأعراض مستمرة، أو كان لديك تقرير طبي، أو كانت لديك صعوبة مع الجهاز أو الماسك، فمن الأفضل عدم الاعتماد على التجربة العشوائية وحدها. القرار الطبي أو الفني المناسب يحتاج إلى تقييم من الطبيب أو المختص.\n\n## الخلاصة\n\nالفهم الجيد يساعدك على اتخاذ قرار أهدأ وأكثر وعيًا، سواء كنت في مرحلة البحث الأولي أو المقارنة بين الحلول أو تحسين تجربة الاستخدام اليومية. الهدف من هذا المقال هو التثقيف وتبسيط الصورة، لا التشخيص أو وصف العلاج.\n\n## هل تحتاج مساعدة في اختيار الجهاز المناسب؟\n\nفريق Respira Tech يساعدك في فهم احتياجك واختيار جهاز CPAP أو BiPAP أو الماسك المناسب حسب حالتك وتوصية الطبيب.\n\n[تواصل معنا عبر واتساب](https://wa.me/{$whatsapp})\n\n> {$disclaimer}\n";
    return [
        'title_ar' => $topic,
        'slug' => $slug,
        'meta_title' => mb_substr($topic, 0, 58, 'UTF-8'),
        'meta_description' => mb_substr('مقال عربي مبسط من Respira Tech يشرح: ' . $topic, 0, 158, 'UTF-8'),
        'excerpt' => 'مقال عربي مبسط يشرح: ' . $topic,
        'category' => $category,
        'tags' => [$category, 'Respira Tech', 'أجهزة النوم'],
        'featured_image' => '/assets/images/store/respira-tech-logo.png',
        'featured_image_prompt' => "Clean medical website image, {$category}, {$topic}, no text, no logo.",
        'content_html' => '',
        'content_markdown' => $content,
        'faq' => [
            ['question' => "هل يساعد فهم موضوع {$topic} على اتخاذ قرار أفضل؟", 'answer' => 'نعم، لأن المعلومات الواضحة تساعد على فهم الخيارات وطرح الأسئلة المناسبة.'],
            ['question' => 'هل هذا المقال يغني عن استشارة الطبيب؟', 'answer' => $disclaimer],
        ],
        'internal_links' => array_slice($siteData['core_links'], 0, 5),
        'cta_text' => 'فريق Respira Tech يساعدك في فهم احتياجك واختيار الجهاز أو الماسك المناسب حسب حالتك وتوصية الطبيب.',
        'cta_button_text' => 'تواصل معنا عبر واتساب',
        'cta_button_url' => 'https://wa.me/' . $whatsapp,
        'author' => $siteData['site']['author'],
        'medical_disclaimer' => $disclaimer,
    ];
}

function rt_openai_article(string $topic, array $siteData): ?array {
    $apiKey = rt_env('OPENAI_API_KEY', '');
    if ($apiKey === '') return null;
    $schema = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['title_ar','slug','meta_title','meta_description','excerpt','category','tags','content_markdown','faq','internal_links','cta_text','cta_button_text','medical_disclaimer'],
        'properties' => [
            'title_ar' => ['type' => 'string'],
            'slug' => ['type' => 'string'],
            'meta_title' => ['type' => 'string'],
            'meta_description' => ['type' => 'string'],
            'excerpt' => ['type' => 'string'],
            'category' => ['type' => 'string'],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
            'content_markdown' => ['type' => 'string'],
            'faq' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['question','answer'], 'properties' => ['question' => ['type' => 'string'], 'answer' => ['type' => 'string']]]],
            'internal_links' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['anchor','url'], 'properties' => ['anchor' => ['type' => 'string'], 'url' => ['type' => 'string']]]],
            'cta_text' => ['type' => 'string'],
            'cta_button_text' => ['type' => 'string'],
            'medical_disclaimer' => ['type' => 'string'],
        ],
    ];
    $body = [
        'model' => rt_env('OPENAI_TEXT_MODEL', 'gpt-4.1'),
        'temperature' => 0.75,
        'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'respiratech_blog_article', 'schema' => $schema, 'strict' => true]],
        'messages' => [
            ['role' => 'system', 'content' => 'You are an expert Arabic SEO medical content writer for a respiratory therapy company. Write accurate, clear, responsible Arabic content. Do not diagnose or prescribe treatment. Encourage consulting a doctor or specialist.'],
            ['role' => 'user', 'content' => "اكتب مقالاً عربيًا احترافيًا عن هذا الموضوع: {$topic}\n\nالقواعد:\n- المقال لا يقل عن 1200 كلمة عربية.\n- استخدم H1 مرة واحدة فقط.\n- استخدم H2 و H3 بشكل منظم.\n- أضف FAQ و CTA نهائي.\n- استخدم 3 إلى 5 روابط داخلية طبيعية من هذه الروابط فقط: /services/cpap/ /services/bipap/ /services/sleep-apnea/ /services/cpap-masks/ /store/ /contact/\n- لا تقل علاج مضمون أو شفاء مضمون.\n- اكتب بالعربية المناسبة لمصر والمستخدم العربي العام."],
        ],
    ];
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $status < 200 || $status >= 300) {
        rt_log_generation(['type' => 'openai_text_error', 'topic' => $topic, 'created_at' => gmdate('c'), 'error' => $error ?: mb_substr((string)$raw, 0, 1000, 'UTF-8')]);
        return null;
    }
    $payload = json_decode((string)$raw, true);
    $content = $payload['choices'][0]['message']['content'] ?? '{}';
    $article = json_decode((string)$content, true);
    return is_array($article) ? $article : null;
}

function rt_log_generation(array $entry): void {
    $path = rt_data_path('data/blog_generation_log.json');
    $logs = rt_load_json($path, []);
    if (!is_array($logs)) $logs = [];
    array_unshift($logs, $entry);
    rt_save_json($path, array_slice($logs, 0, 100));
}

function rt_finalize_article(array $article, string $topic, array $siteData, ?bool $publishNow): array {
    $now = gmdate('c');
    $category = (string)($article['category'] ?? rt_derive_category($topic));
    $article['title_ar'] = (string)($article['title_ar'] ?? $topic);
    $article['slug'] = rt_slugify((string)($article['slug'] ?? $article['title_ar']));
    $article['meta_title'] = mb_substr((string)($article['meta_title'] ?? $article['title_ar']), 0, 58, 'UTF-8');
    $article['meta_description'] = mb_substr((string)($article['meta_description'] ?? 'مقال عربي من Respira Tech عن ' . $topic), 0, 158, 'UTF-8');
    $article['excerpt'] = (string)($article['excerpt'] ?? $article['meta_description']);
    $article['category'] = $category;
    $article['tags'] = is_array($article['tags'] ?? null) ? $article['tags'] : [$category, 'Respira Tech'];
    $article['featured_image'] = '/assets/images/store/respira-tech-logo.png';
    $article['featured_image_prompt'] = (string)($article['featured_image_prompt'] ?? "Clean medical website image, {$category}, {$topic}, no text, no logo.");
    $article['content_html'] = '';
    $article['internal_links'] = rt_ensure_internal_links($article, $siteData);
    $article['content_markdown'] = rt_auto_link_markdown((string)($article['content_markdown'] ?? ''), $article['internal_links']);
    $article['cta_button_text'] = (string)($article['cta_button_text'] ?? 'تواصل معنا عبر واتساب');
    $article['cta_button_url'] = 'https://wa.me/' . $siteData['site']['whatsapp_number'];
    $article['author'] = $siteData['site']['author'];
    $article['medical_disclaimer'] = $siteData['site']['medical_disclaimer'];
    $article['created_at'] = $article['created_at'] ?? $now;
    $article['updated_at'] = $now;
    if ($publishNow === true) {
        $article['status'] = 'published';
    } elseif ($publishNow === false) {
        $article['status'] = 'draft';
    } else {
        $article['status'] = rt_env('AUTO_PUBLISH_BLOGS', 'true') === 'true' ? 'published' : 'draft';
    }
    $article['published_at'] = $article['status'] === 'published' ? ($article['published_at'] ?? $now) : null;
    $article['reading_time'] = rt_reading_time($article['content_markdown']);
    $article['seo_score'] = rt_seo_score($article);
    return $article;
}

function rt_generate_blog_batch(int $count, ?bool $publishNow, bool $dryRun = false): array {
    $siteData = rt_site_data();
    $topics = rt_choose_topics($count);
    $generated = [];
    foreach ($topics as $topic) {
        $article = rt_openai_article($topic, $siteData);
        if (!$article) $article = rt_fallback_article($topic, $siteData);
        $article = rt_finalize_article($article, (string)$topic, $siteData, $publishNow);
        if (!$dryRun) {
            rt_save_json(rt_blog_article_path($article['slug']), $article);
            rt_log_generation(['type' => 'generated', 'backend' => 'php', 'topic' => $topic, 'slug' => $article['slug'], 'status' => $article['status'], 'created_at' => gmdate('c')]);
        }
        $generated[] = ['slug' => $article['slug'], 'title_ar' => $article['title_ar'], 'status' => $article['status'], 'seo_score' => $article['seo_score']];
    }
    return $generated;
}
