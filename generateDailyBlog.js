import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { execFileSync } from 'child_process';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = __dirname;
const TOPICS_FILE = path.join(ROOT, 'data', 'blog_topics.json');
const KEYWORD_PLAN_FILE = path.join(ROOT, 'data', 'blog_keyword_plan.json');
const ARTICLES_DIR = path.join(ROOT, 'data', 'blog_articles');
const SITE_FILE = path.join(ROOT, 'data', 'site.json');
const ENV_FILE = path.join(ROOT, '.env');
const LOG_FILE = path.join(ROOT, 'data', 'blog_generation_log.json');
const BLOG_IMAGES_DIR = path.join(ROOT, 'assets', 'images', 'blog');
const DEFAULT_TARGET_WORDS = 1800;
const DEFAULT_MIN_WORDS = 1600;
const FALLBACK_BLOG_IMAGES = [
  '/assets/images/blog/cpap-daily-usage-hours-guide.png',
  '/assets/images/blog/cpap-cleaning-guide.png',
  '/assets/images/blog/choose-cpap-mask-guide.png',
  '/assets/images/blog/cpap-sleep-comfort.png',
  '/assets/images/blog/cpap-mask-air-leakage-causes-solutions.png',
  '/assets/images/store/resmed-airsense-11-autoset.jpg',
  '/assets/images/store/resmed-airsense-10-autoset.jpg',
  '/assets/images/store/yuwell-auto-cpap.png'
];

const INTERNAL_LINK_STRATEGY = [
  {
    url: '/services/cpap/',
    anchors: [
      'أفضل أجهزة CPAP لعلاج انقطاع النفس أثناء النوم',
      'أجهزة CPAP المنزلية',
      'جهاز CPAP المناسب لحالتك',
      'خدمة أجهزة CPAP من Respira Tech'
    ]
  },
  {
    url: '/services/bipap/',
    anchors: [
      'الفرق بين أجهزة CPAP و BiPAP',
      'أجهزة BiPAP للحالات التي تحتاج دعم تنفسي أكبر',
      'متى يكون جهاز BiPAP اختيارًا مناسبًا',
      'خدمة أجهزة BiPAP المنزلية'
    ]
  },
  {
    url: '/services/sleep-apnea/',
    anchors: [
      'أعراض انقطاع النفس أثناء النوم',
      'تشخيص انقطاع النفس أثناء النوم',
      'الشخير وتوقف التنفس أثناء النوم',
      'متى تحتاج لتقييم اضطرابات النوم'
    ]
  },
  {
    url: '/services/cpap-masks/',
    anchors: [
      'اختيار ماسك CPAP المناسب',
      'حل مشكلة تسريب الهواء من ماسك CPAP',
      'أنواع ماسكات CPAP وطرق اختيارها',
      'ماسكات CPAP المريحة للاستخدام اليومي'
    ]
  },
  {
    url: '/store/',
    anchors: [
      'تصفح أجهزة التنفس وماسكات CPAP المتاحة',
      'شراء مستلزمات CPAP و BiPAP',
      'منتجات Respira Tech لأجهزة التنفس المنزلي',
      'خيارات أجهزة وماسكات التنفس المتوفرة'
    ]
  },
  {
    url: '/contact/',
    anchors: [
      'استشارة Respira Tech لاختيار الجهاز المناسب',
      'التواصل مع مختص قبل شراء جهاز CPAP',
      'مساعدة في اختيار جهاز التنفس المنزلي',
      'طلب دعم لاختيار الماسك أو الجهاز'
    ]
  }
];

function loadEnv() {
  const env = {};
  if (fs.existsSync(ENV_FILE)) {
    const lines = fs.readFileSync(ENV_FILE, 'utf8').split(/\r?\n/);
    for (const line of lines) {
      if (!line || line.trim().startsWith('#') || !line.includes('=')) continue;
      const [key, ...rest] = line.split('=');
      env[key.trim()] = rest.join('=').trim();
    }
  }
  return { ...env, ...process.env };
}

function readJson(file, fallback = null) {
  if (!fs.existsSync(file)) return fallback;
  return JSON.parse(fs.readFileSync(file, 'utf8'));
}

function writeJson(file, payload) {
  fs.mkdirSync(path.dirname(file), { recursive: true });
  fs.writeFileSync(file, JSON.stringify(payload, null, 2), 'utf8');
}

function appendLog(entry) {
  const current = readJson(LOG_FILE, []);
  const logs = Array.isArray(current) ? current : [];
  logs.unshift(entry);
  writeJson(LOG_FILE, logs.slice(0, 100));
}

function slugify(value) {
  return value
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9\u0600-\u06ff._-]+/g, '-')
    .replace(/-+/g, '-')
    .replace(/^-|-$/g, '') || `article-${Date.now()}`;
}

function stripMarkdown(markdown = '') {
  return markdown
    .replace(/[#>*`\-\[\]\(\)]/g, ' ')
    .split(/\s+/)
    .filter(Boolean);
}

function readingTime(markdown = '') {
  return Math.max(1, Math.round(stripMarkdown(markdown).length / 180));
}

function fallbackFeaturedImage(seed = '') {
  const hash = Array.from(String(seed || '')).reduce((sum, char) => sum + char.charCodeAt(0), 0);
  return FALLBACK_BLOG_IMAGES[hash % FALLBACK_BLOG_IMAGES.length];
}

function seoScore(article) {
  const markdown = article.content_markdown || '';
  const words = stripMarkdown(markdown).length;
  const checks = [
    !!article.title_ar,
    !!article.meta_title && article.meta_title.length <= 60,
    !!article.meta_description && article.meta_description.length <= 160,
    /(^|\n)#\s+/.test(markdown),
    /(^|\n)##\s+/.test(markdown),
    Array.isArray(article.faq) && article.faq.length > 0,
    !!article.cta_text,
    Array.isArray(article.internal_links) && article.internal_links.length > 0,
    !!article.medical_disclaimer,
    !!article.category,
    !!article.slug,
    words >= DEFAULT_MIN_WORDS
  ];
  return Math.round(checks.reduce((sum, ok) => sum + (ok ? 100 / checks.length : 0), 0));
}

function targetWords(env) {
  const configured = Number(env.ARTICLE_TARGET_WORDS || env.BLOG_ARTICLE_TARGET_WORDS || DEFAULT_TARGET_WORDS);
  return Number.isFinite(configured) ? Math.max(DEFAULT_MIN_WORDS, configured) : DEFAULT_TARGET_WORDS;
}

function minWords(env) {
  const configured = Number(env.ARTICLE_MIN_WORDS || env.BLOG_ARTICLE_MIN_WORDS || DEFAULT_MIN_WORDS);
  return Number.isFinite(configured) ? Math.max(1200, configured) : DEFAULT_MIN_WORDS;
}

function linkScoreForTopic(link, text) {
  const source = String(text || '').toLowerCase();
  let score = 0;
  if (link.url.includes('cpap') && source.includes('cpap')) score += 3;
  if (link.url.includes('bipap') && source.includes('bipap')) score += 3;
  if (link.url.includes('sleep-apnea') && /انقطاع|الشخير|النفس|النوم/.test(source)) score += 3;
  if (link.url.includes('cpap-masks') && /ماسك|قناع|تسريب|mask/.test(source)) score += 3;
  if (link.url.includes('store') && /شراء|سعر|منتج|متجر|اختيار/.test(source)) score += 1;
  if (link.url.includes('contact')) score += 1;
  return score;
}

function pickAnchor(link, topic, index = 0) {
  const strategy = INTERNAL_LINK_STRATEGY.find((item) => item.url === link.url);
  if (!strategy) return link.anchor;
  const offset = Math.abs(slugify(topic).split('').reduce((sum, char) => sum + char.charCodeAt(0), 0));
  return strategy.anchors[(offset + index) % strategy.anchors.length];
}

function isWeakAnchor(anchor = '') {
  const value = String(anchor).trim();
  return (
    value.length < 13 ||
    /^(صفحة|المتجر|تواصل معنا|اضغط هنا|اقرأ المزيد|خدمة العملاء)$/i.test(value) ||
    /^صفحة\s+/i.test(value)
  );
}

function deriveCategory(topic) {
  if (/BiPAP/i.test(topic) || topic.includes('BiPAP')) return 'أجهزة BiPAP';
  if (topic.includes('CPAP')) return 'أجهزة CPAP';
  if (topic.includes('ماسك') || topic.includes('Mask') || topic.includes('الماسك')) return 'ماسكات وإكسسوارات';
  if (topic.includes('أكسجين') || topic.includes('Oxygen')) return 'الأكسجين والتنفس المنزلي';
  if (topic.includes('الشخير')) return 'الشخير واضطرابات النوم';
  if (topic.includes('انقطاع النفس')) return 'انقطاع النفس أثناء النوم';
  return 'نصائح الاستخدام والعناية';
}

function normalizeTopicItem(item) {
  if (typeof item === 'string') {
    return {
      topic: item,
      primary_keyword: item,
      intent: 'informational',
      priority: 50,
      cluster: deriveCategory(item)
    };
  }
  return {
    topic: String(item.topic || item.title || item.primary_keyword || '').trim(),
    primary_keyword: String(item.primary_keyword || item.topic || '').trim(),
    secondary_keywords: Array.isArray(item.secondary_keywords) ? item.secondary_keywords : [],
    intent: String(item.intent || 'informational').trim(),
    priority: Number.isFinite(Number(item.priority)) ? Number(item.priority) : 50,
    cluster: String(item.cluster || '').trim()
  };
}

function topicKey(item) {
  return slugify(item.topic || item.primary_keyword || '');
}

function chooseTopics(topicItems, articles, generationLog, neededCount) {
  const usedTitles = new Set(articles.map((a) => a.title_ar));
  const usedSlugs  = new Set(articles.map((a) => a.slug).filter(Boolean));
  // Also track topics that were already attempted via generation log
  const usedTopics = new Set();
  for (const entry of (Array.isArray(generationLog) ? generationLog : [])) {
    if (Array.isArray(entry.items)) {
      for (const item of entry.items) {
        if (item.topic) usedTopics.add(item.topic);
        if (item.slug)  usedSlugs.add(item.slug);
      }
    }
  }
  const normalized = topicItems.map(normalizeTopicItem).filter((item) => item.topic);
  const deduped = [...new Map(normalized.map((item) => [topicKey(item), item])).values()]
    .sort((a, b) => b.priority - a.priority);
  const available = deduped.filter(
    (item) => !usedTitles.has(item.topic) && !usedSlugs.has(topicKey(item)) && !usedTopics.has(item.topic)
  );
  if (available.length >= neededCount) return available.slice(0, neededCount);
  // Fall back: at least avoid slug collisions
  const noSlugCollision = deduped.filter((item) => !usedSlugs.has(topicKey(item)) && !usedTopics.has(item.topic));
  const pool = noSlugCollision.length ? noSlugCollision : deduped.filter((item) => !usedTopics.has(item.topic));
  return (pool.length ? pool : deduped).slice(0, neededCount);
}

function createdTodayCount(articles, cairoDate) {
  return articles.filter((article) => {
    const source = article.created_at || article.published_at || '';
    return source.startsWith(cairoDate);
  }).length;
}

function ensureInternalLinks(article, siteData) {
  const topicText = `${article.title_ar || ''} ${article.excerpt || ''} ${article.category || ''}`;
  const existing = Array.isArray(article.internal_links) ? article.internal_links.filter((item) => item?.url) : [];
  const byUrl = new Map();
  for (const item of existing) {
    const core = siteData.core_links.find((link) => link.url === item.url);
    const anchor = !isWeakAnchor(item.anchor) ? item.anchor : pickAnchor(core || item, topicText, byUrl.size);
    byUrl.set(item.url, { anchor, url: item.url });
  }
  const rankedCore = [...siteData.core_links].sort((a, b) => linkScoreForTopic(b, topicText) - linkScoreForTopic(a, topicText));
  for (const link of rankedCore) {
    if (!byUrl.has(link.url)) {
      byUrl.set(link.url, { anchor: pickAnchor(link, topicText, byUrl.size), url: link.url });
    }
  }
  return [...byUrl.values()].slice(0, 6);
}

function autoLinkMarkdown(markdown = '', links = []) {
  let updated = String(markdown || '').replace(/\[\[([^\]]+)\]\((\/[^)]+)\)\]\(https:\/\/respira-tech\.com\/?[^)]*\)/g, '[$1]($2)');
  const missingLinks = [];
  for (const link of links) {
    if (!link?.anchor || !link?.url) continue;
    const urlPattern = link.url.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const existingLinkRegex = new RegExp(`\\[([^\\]]+)\\]\\((?:https://respira-tech\\.com)?${urlPattern}\\)`, 'i');
    const existingMatch = updated.match(existingLinkRegex);
    if (existingMatch) {
      if (isWeakAnchor(existingMatch[1])) {
        updated = updated.replace(existingLinkRegex, `[${link.anchor}](${link.url})`);
      }
      continue;
    }
    const escaped = link.anchor.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const regex = new RegExp(escaped, 'i');
    if (regex.test(updated)) {
      updated = updated.replace(regex, `[${link.anchor}](${link.url})`);
      continue;
    }
    missingLinks.push(link);
  }
  if (missingLinks.length && !updated.includes('## روابط تساعدك على الخطوة التالية')) {
    const lines = [
      '',
      '## روابط تساعدك على الخطوة التالية',
      '',
      ...missingLinks.slice(0, 6).map((link) => `- [${link.anchor}](${link.url})`)
    ];
    const insertion = `\n${lines.join('\n')}\n`;
    const ctaIndex = updated.search(/\n##\s+(الخلاصة|هل تحتاج|تواصل|CTA|دعوة)/i);
    if (ctaIndex > -1) {
      updated = `${updated.slice(0, ctaIndex).trimEnd()}${insertion}\n${updated.slice(ctaIndex).trimStart()}`;
    } else {
      updated = `${updated.trimEnd()}${insertion}`;
    }
  }
  return updated;
}

function buildImagePrompt(topic, category) {
  return `Clean white medical website image, ${category}, ${topic}, modern bedroom or consultation context, soft daylight, premium healthcare, calm blue accents, realistic, no text, no logos, no watermark.`;
}

function fallbackArticle(topic, siteData, nowIso) {
  const category = deriveCategory(topic);
  const slug = slugify(topic);
  const content_markdown = `# ${topic}

هذا المقال يشرح الموضوع بلغة عربية واضحة وطبيعية، كما لو أن مختصًا يكتبه يدويًا للقارئ العربي الذي يبحث عن فهم أفضل قبل اتخاذ قرار يتعلق بالنوم أو أجهزة الدعم التنفسي المنزلي.

## مقدمة: لماذا يحتاج القارئ إلى دليل مفصل؟

يبحث كثير من الأشخاص عن معلومات حول اضطرابات النوم أو أجهزة العلاج التنفسي المنزلي، لكن المشكلة ليست فقط في كثرة المعلومات، بل في أن جزءًا كبيرًا منها إما مبالغ فيه أو غير واضح. لهذا نحاول هنا تقديم شرح منظم ومسؤول يساعدك على فهم الفكرة الأساسية، ومتى يكون من المناسب طلب تقييم متخصص.

في الموضوعات المرتبطة بالتنفس أثناء النوم، التفاصيل الصغيرة قد تصنع فرقًا كبيرًا: نوع الأعراض، وقت ظهورها، طريقة النوم، وجود انسداد بالأنف، مستوى الراحة مع الماسك، ومدى الالتزام اليومي باستخدام الجهاز. لذلك لا يكفي أن تعرف اسم الجهاز فقط، بل تحتاج أن تفهم الصورة كاملة بطريقة عملية وهادئة.

## لماذا يهم هذا الموضوع؟

قد تظهر المشكلة في صورة شخير مستمر، أو تعب صباحي، أو صعوبة في التكيف مع جهاز معين، أو حيرة عند المقارنة بين أكثر من خيار. فهم هذا الموضوع لا يعني تشخيص الحالة، لكنه يساعدك على طرح الأسئلة الصحيحة، وفهم دور [أجهزة CPAP](/services/cpap/) أو [أجهزة BiPAP](/services/bipap/) أو [ماسكات CPAP](/services/cpap-masks/) عندما يوصي بها الطبيب.

## شرح مبسط

في كثير من الحالات، يكون الهدف هو تحسين جودة النوم وتقليل الاضطرابات التي تؤثر على التنفس أثناء الليل. وقد يعتمد ذلك على تقييم الأعراض، ونتائج الفحص أو دراسة النوم عند الحاجة، ثم اختيار الجهاز أو الماسك المناسب بناءً على توصية الطبيب وطبيعة الاستخدام اليومي في المنزل.

## كيف تفكر في القرار بطريقة صحيحة؟

قبل الشراء أو تغيير الجهاز أو الماسك، اسأل نفسك عدة أسئلة: هل المشكلة في التشخيص نفسه أم في الراحة أثناء الاستخدام؟ هل الضغط مزعج؟ هل يوجد تسريب هواء؟ هل تستيقظ بفم جاف؟ هل تستخدم الجهاز عدد ساعات كافيًا؟ الإجابة على هذه الأسئلة تساعد المختص على توجيهك بشكل أدق.

## أخطاء شائعة يجب تجنبها

- الاعتماد على تجربة شخص آخر بدون تقييم حالتك.
- شراء ماسك بناءً على الشكل فقط دون تجربة المقاس.
- إيقاف استخدام الجهاز عند أول شعور بعدم الراحة.
- تجاهل تسريب الهواء أو جفاف الأنف والفم.
- اعتبار المقالات التعليمية بديلًا عن الطبيب أو دراسة النوم.

### دور الجهاز أو الماسك

نوع الجهاز أو الماسك قد يؤثر مباشرة على الراحة والالتزام اليومي. لذلك من المهم فهم الاختلافات العملية بين الخيارات المختلفة، وعدم الاعتماد فقط على السعر أو الشكل الخارجي. في بعض الحالات يكون [المتجر](/store/) نقطة بداية جيدة للتعرف على الخيارات، لكن القرار النهائي يجب أن يكون مبنيًا على فهم الحالة وتوصية المختص.

### أهمية المتابعة بعد الشراء

الكثير يظن أن المشكلة تنتهي بمجرد شراء الجهاز، لكن الواقع أن المتابعة بعد الشراء ضرورية لفهم أي صعوبات في الاستخدام، أو عدم راحة في الماسك، أو الحاجة إلى ضبط أفضل لطريقة الاستخدام. لذلك من المفيد دائمًا وجود جهة يمكن [التواصل معها](/contact/) لفهم الخطوات العامة ومتى يلزم الرجوع إلى الطبيب.

## متى يجب طلب المساعدة؟

إذا كانت الأعراض مستمرة، أو كان لديك تقرير طبي، أو كانت لديك صعوبة مع الجهاز أو الماسك، فمن الأفضل عدم الاعتماد على التجربة العشوائية وحدها. الفهم العام مهم، لكن القرار الطبي أو الفني المناسب يحتاج إلى تقييم من الطبيب أو المختص، خاصة إذا كان الأمر متعلقًا بـ [انقطاع النفس أثناء النوم](/services/sleep-apnea/).

## الخلاصة

الفهم الجيد لهذا الموضوع يساعدك على اتخاذ قرار أهدأ وأكثر وعيًا، سواء كنت في مرحلة البحث الأولي أو المقارنة بين الحلول أو تحسين تجربة الاستخدام اليومية. الهدف من هذا المقال هو التثقيف وتبسيط الصورة، لا التشخيص أو وصف العلاج.

## هل تحتاج مساعدة في اختيار الجهاز المناسب؟

فريق Respira Tech يساعدك في فهم احتياجك واختيار جهاز CPAP أو BiPAP أو الماسك المناسب حسب حالتك وتوصية الطبيب.

[تواصل معنا عبر واتساب](https://wa.me/${siteData.site.whatsapp_number})

> ${siteData.site.medical_disclaimer}
`;

  return {
    title_ar: topic,
    slug,
    meta_title: `${topic}`.slice(0, 58),
    meta_description: `مقال عربي مبسط من Respira Tech يشرح: ${topic}`.slice(0, 158),
    excerpt: `مقال عربي مبسط يشرح: ${topic}`,
    category,
    tags: [category, 'Respira Tech', 'أجهزة النوم'],
    featured_image: fallbackFeaturedImage(slug || topic),
    featured_image_prompt: buildImagePrompt(topic, category),
    content_html: '',
    content_markdown,
    faq: [
      {
        question: `هل يساعد فهم موضوع ${topic} على اتخاذ قرار أفضل؟`,
        answer: 'نعم، لأن المعلومات الواضحة تساعد على فهم الخيارات وطرح الأسئلة المناسبة قبل شراء الجهاز أو البدء في الاستخدام.'
      },
      {
        question: 'هل هذا المقال يغني عن استشارة الطبيب؟',
        answer: siteData.site.medical_disclaimer
      }
    ],
    internal_links: siteData.core_links.slice(0, 5),
    cta_text: 'فريق Respira Tech يساعدك في فهم احتياجك واختيار جهاز CPAP أو BiPAP أو الماسك المناسب حسب حالتك وتوصية الطبيب.',
    cta_button_text: 'تواصل معنا عبر واتساب',
    cta_button_url: `https://wa.me/${siteData.site.whatsapp_number}`,
    author: siteData.site.author,
    status: 'draft',
    published_at: null,
    created_at: nowIso,
    updated_at: nowIso,
    reading_time: readingTime(content_markdown),
    seo_score: 0,
    medical_disclaimer: siteData.site.medical_disclaimer
  };
}

async function generateWithOpenAI({ topicItem, env, siteData }) {
  if (!env.OPENAI_API_KEY) return null;
  const topic = topicItem.topic;
  const primaryKeyword = topicItem.primary_keyword || topic;
  const secondaryKeywords = Array.isArray(topicItem.secondary_keywords) ? topicItem.secondary_keywords : [];
  const searchIntent = topicItem.intent || 'informational';

  const systemPrompt = 'You are an expert Arabic SEO medical content writer for a respiratory therapy company. Write accurate, clear, responsible Arabic content about sleep apnea, CPAP, BiPAP, respiratory therapy, and home breathing support. The content must be educational, trustworthy, and conversion-focused without making unsafe medical claims. Do not diagnose. Do not prescribe treatment. Encourage the reader to consult a doctor or specialist. Use simple Arabic suitable for Egypt and Arabic-speaking audiences. Write naturally so the article feels manually written by a skilled Arabic human editor, with varied sentence structure and no robotic repetition.';
  const desiredWords = targetWords(env);
  const requiredWords = minWords(env);
  const schema = {
    type: 'object',
    additionalProperties: false,
    required: ['title_ar', 'slug', 'meta_title', 'meta_description', 'excerpt', 'category', 'tags', 'content_markdown', 'faq', 'internal_links', 'cta_text', 'cta_button_text', 'medical_disclaimer'],
    properties: {
      title_ar: { type: 'string' },
      slug: { type: 'string' },
      meta_title: { type: 'string' },
      meta_description: { type: 'string' },
      excerpt: { type: 'string' },
      category: { type: 'string' },
      tags: { type: 'array', items: { type: 'string' } },
      content_markdown: { type: 'string' },
      faq: {
        type: 'array',
        items: {
          type: 'object',
          additionalProperties: false,
          required: ['question', 'answer'],
          properties: {
            question: { type: 'string' },
            answer: { type: 'string' }
          }
        }
      },
      internal_links: {
        type: 'array',
        items: {
          type: 'object',
          additionalProperties: false,
          required: ['anchor', 'url'],
          properties: {
            anchor: { type: 'string' },
            url: { type: 'string' }
          }
        }
      },
      cta_text: { type: 'string' },
      cta_button_text: { type: 'string' },
      medical_disclaimer: { type: 'string' }
    }
  };

  const response = await fetch('https://api.openai.com/v1/chat/completions', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Authorization: `Bearer ${env.OPENAI_API_KEY}`
    },
    body: JSON.stringify({
      model: env.OPENAI_TEXT_MODEL || 'gpt-4.1',
      temperature: 0.75,
      max_tokens: 6500,
      response_format: {
        type: 'json_schema',
        json_schema: {
          name: 'respiratech_blog_article',
          schema,
          strict: true
        }
      },
      messages: [
        { role: 'system', content: systemPrompt },
        {
          role: 'user',
          content: `اكتب مقالاً عربيًا احترافيًا عن هذا الموضوع: ${topic}

بيانات السيو:
- الكلمة المفتاحية الأساسية: ${primaryKeyword}
- نية البحث: ${searchIntent}
- كلمات ثانوية داعمة: ${secondaryKeywords.join('، ') || 'غير محدد'}

القواعد:
- المقال لا يقل عن ${requiredWords} كلمة عربية، والهدف المثالي حوالي ${desiredWords} كلمة.
- يجب أن يبدو كأنه مكتوب يدويًا بواسطة محرر عربي محترف، وليس نصًا آليًا.
- لا تختصر: كل H2 يجب أن يحتوي شرحًا فعليًا من 2 إلى 4 فقرات، وليس فقرة واحدة سطحية.
- استخدم H1 مرة واحدة فقط.
- استخدم H2 و H3 بشكل منظم.
- أضف مقدمة واضحة.
- أضف قسمًا للأعراض عندما يكون ذلك مناسبًا.
- أضف شرحًا عمليًا.
- أضف قسمًا للأخطاء الشائعة وقسمًا للخطوات العملية أو جدول مقارنة Markdown عندما يناسب الموضوع.
- أضف قسم: متى تطلب المساعدة؟
- أضف FAQ.
- أضف CTA نهائي.
- استخدم 4 إلى 6 روابط داخلية طبيعية من هذه الروابط فقط: /services/cpap/ /services/bipap/ /services/sleep-apnea/ /services/cpap-masks/ /store/ /contact/
- لا تستخدم أنكور عام مثل "اضغط هنا" أو "المتجر" أو "تواصل معنا" وحده. استخدم عبارات وصفية طويلة مثل "اختيار ماسك CPAP المناسب" أو "أعراض انقطاع النفس أثناء النوم".
- وزع الروابط داخل الفقرات في أماكن منطقية، وليس كلها في آخر المقال.
- لا تكرر الكلمات المفتاحية بشكل مصطنع.
- استخدم الكلمة المفتاحية الأساسية في H1 وفي المقدمة وفي Meta description بشكل طبيعي.
- ابنِ المقال حول نية البحث المحددة: لو النية شراء/مقارنة فركز على الاختيار والمعايير، ولو مشكلة فركز على السبب والحل، ولو أعراض فركز على متى يراجع الطبيب.
- لا تقل علاج مضمون أو شفاء مضمون.
- اذكر Respira Tech بشكل طبيعي 2 أو 3 مرات فقط.
- اختم بزر واتساب.
- اجعل الوصف التعريفي مناسبًا للسيو ومختصرًا.
- استخدم العربية المناسبة لمصر والمستخدم العربي العام.`
        }
      ]
    })
  });

  if (!response.ok) {
    const errorText = await response.text();
    throw new Error(`OpenAI error: ${errorText}`);
  }

  const payload = await response.json();
  const raw = payload.choices?.[0]?.message?.content || '{}';
  const parsed = JSON.parse(raw);
  const nowIso = new Date().toISOString();
  const category = parsed.category || deriveCategory(topic);
  return {
    ...parsed,
    slug: slugify(parsed.slug || parsed.title_ar || topic),
    featured_image: fallbackFeaturedImage(parsed.slug || parsed.title_ar || topic),
    featured_image_prompt: buildImagePrompt(parsed.title_ar || topic, category),
    content_html: '',
    cta_button_url: `https://wa.me/${env.WHATSAPP_NUMBER || siteData.site.whatsapp_number}`,
    author: siteData.site.author,
    created_at: nowIso,
    updated_at: nowIso,
    published_at: null,
    status: 'draft',
    medical_disclaimer: siteData.site.medical_disclaimer,
    reading_time: readingTime(parsed.content_markdown || ''),
    seo_score: 0,
    internal_links: ensureInternalLinks(parsed, siteData)
  };
}

async function generateFeaturedImage({ article, env }) {
  if (!env.OPENAI_API_KEY) return fallbackFeaturedImage(article.slug || article.title_ar);
  if (String(env.GENERATE_BLOG_IMAGES || 'true').toLowerCase() === 'false') {
    return fallbackFeaturedImage(article.slug || article.title_ar);
  }
  if (String(env.OPENAI_IMAGE_MODEL || '').toLowerCase() === 'dall-e-3') {
    return fallbackFeaturedImage(article.slug || article.title_ar);
  }

  fs.mkdirSync(BLOG_IMAGES_DIR, { recursive: true });
  const fileName = `${article.slug}.png`;
  const filePath = path.join(BLOG_IMAGES_DIR, fileName);
  const preferred = env.OPENAI_IMAGE_MODEL || 'gpt-image-1-mini';
  const modelCandidates = [preferred, 'gpt-image-1-mini', 'gpt-image-1', 'dall-e-3']
    .filter((model, index, arr) => model && arr.indexOf(model) === index);

  let lastError = null;
  for (const model of modelCandidates) {
    const requestBody = {
      model,
      prompt: article.featured_image_prompt || buildImagePrompt(article.title_ar, article.category),
      size: '1024x1024'
    };
    if (model !== 'dall-e-3') {
      requestBody.quality = 'medium';
    }

    const response = await fetch('https://api.openai.com/v1/images/generations', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Authorization: `Bearer ${env.OPENAI_API_KEY}`
      },
      body: JSON.stringify(requestBody)
    });

    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
      lastError = `OpenAI image error (${model}): ${JSON.stringify(payload)}`;
      continue;
    }

    const imageBase64 = payload?.data?.[0]?.b64_json;
    const imageUrl = payload?.data?.[0]?.url;
    if (imageBase64) {
      fs.writeFileSync(filePath, Buffer.from(imageBase64, 'base64'));
      return `/assets/images/blog/${fileName}`;
    }
    if (imageUrl) {
      const imageResponse = await fetch(imageUrl);
      if (!imageResponse.ok) {
        lastError = `OpenAI image download error (${model}): ${imageResponse.status}`;
        continue;
      }
      const imageBuffer = Buffer.from(await imageResponse.arrayBuffer());
      fs.writeFileSync(filePath, imageBuffer);
      return `/assets/images/blog/${fileName}`;
    }
    lastError = `OpenAI image error (${model}): empty image payload`;
  }

  throw new Error(lastError || 'OpenAI image error: no available image model');
}

async function buildArticle(topicItem, env, siteData) {
  const topic = topicItem.topic || String(topicItem);
  let article = null;
  let textGenerationError = null;
  try {
    article = await generateWithOpenAI({ topicItem: normalizeTopicItem(topicItem), env, siteData });
  } catch (error) {
    textGenerationError = error;
    appendLog({
      type: 'openai_text_error',
      topic,
      created_at: new Date().toISOString(),
      error: error.message
    });
  }

  if (!article) {
    const allowFallback = String(env.ALLOW_FALLBACK_ARTICLES || 'false').toLowerCase() === 'true';
    const forcePublish = String(env.FORCE_PUBLISH || '').toLowerCase();
    const wouldPublish = forcePublish === 'true' || (forcePublish !== 'false' && String(env.AUTO_PUBLISH_BLOGS).toLowerCase() === 'true');
    if (wouldPublish && !allowFallback) {
      throw new Error(`OpenAI article generation failed; refusing to publish fallback article. ${textGenerationError?.message || ''}`.trim());
    }
    article = fallbackArticle(topic, siteData, new Date().toISOString());
  }

  article.id = article.id || article.slug;
  article.internal_links = ensureInternalLinks(article, siteData);
  article.content_markdown = autoLinkMarkdown(article.content_markdown || '', article.internal_links);
  article.cta_button_text = article.cta_button_text || 'تواصل معنا عبر واتساب';
  article.cta_button_url = article.cta_button_url || `https://wa.me/${env.WHATSAPP_NUMBER || siteData.site.whatsapp_number}`;
  const forcePublish = String(env.FORCE_PUBLISH || '').toLowerCase();
  if (forcePublish === 'true') {
    article.status = 'published';
  } else if (forcePublish === 'false') {
    article.status = 'draft';
  } else {
    article.status = String(env.AUTO_PUBLISH_BLOGS).toLowerCase() === 'true' ? 'published' : 'draft';
  }
  if (article.status === 'published') {
    article.published_at = article.published_at || new Date().toISOString();
  }

  try {
    article.featured_image = await generateFeaturedImage({ article, env });
  } catch (error) {
    article.featured_image = fallbackFeaturedImage(article.slug || topic);
    appendLog({
      type: 'openai_image_error',
      topic,
      slug: article.slug,
      created_at: new Date().toISOString(),
      error: error.message
    });
  }

  article.updated_at = new Date().toISOString();
  article.reading_time = readingTime(article.content_markdown || '');
  article.seo_score = seoScore(article);
  return article;
}

async function main() {
  const env = loadEnv();
  const siteData = readJson(SITE_FILE);
  const topics = readJson(TOPICS_FILE, []);
  const keywordPlan = readJson(KEYWORD_PLAN_FILE, []);
  const topicItems = [
    ...(Array.isArray(keywordPlan) ? keywordPlan : []),
    ...(Array.isArray(topics) ? topics : [])
  ];
  const generationLog = readJson(LOG_FILE, []);
  const articleFiles = fs.existsSync(ARTICLES_DIR) ? fs.readdirSync(ARTICLES_DIR).filter((name) => name.endsWith('.json')) : [];
  const articles = articleFiles.map((name) => readJson(path.join(ARTICLES_DIR, name), {})).filter(Boolean);
  const cairoDate = new Intl.DateTimeFormat('en-CA', { timeZone: 'Africa/Cairo' }).format(new Date());
  const dailyLimit = Math.max(1, Number.parseInt(env.DAILY_BLOG_POSTS || '2', 10) || 2);
  const existingToday = createdTodayCount(articles, cairoDate);
  const forceGenerate = String(env.FORCE_GENERATE || 'false').toLowerCase() === 'true';
  const remaining = forceGenerate ? dailyLimit : Math.max(0, dailyLimit - existingToday);

  if (remaining <= 0) {
    appendLog({
      type: 'skipped',
      created_at: new Date().toISOString(),
      message: `daily limit reached for ${cairoDate}`,
      daily_limit: dailyLimit,
      existing_today: existingToday
    });
    console.log(`[generateDailyBlog] skipped: daily limit reached for ${cairoDate}`);
    return;
  }

  const selectedTopics = chooseTopics(topicItems, articles, generationLog, remaining);
  if (!selectedTopics.length) {
    appendLog({
      type: 'skipped',
      created_at: new Date().toISOString(),
      message: 'no topics available'
    });
    console.log('[generateDailyBlog] skipped: no topics available');
    return;
  }

  const generated = [];
  for (const topicItem of selectedTopics) {
    const article = await buildArticle(topicItem, env, siteData);
    const filePath = path.join(ARTICLES_DIR, `${article.slug}.json`);
    writeJson(filePath, article);
    generated.push({
      topic: topicItem.topic,
      primary_keyword: topicItem.primary_keyword,
      intent: topicItem.intent,
      slug: article.slug,
      status: article.status,
      featured_image: article.featured_image
    });
  }

  execFileSync('python3', [path.join(ROOT, 'build_content.py')], {
    cwd: ROOT,
    stdio: 'inherit'
  });

  appendLog({
    type: 'success',
    created_at: new Date().toISOString(),
    generated_count: generated.length,
    items: generated
  });
  console.log(`[generateDailyBlog] success: generated ${generated.length} article(s)`);
}

main().catch((error) => {
  appendLog({
    type: 'fatal_error',
    created_at: new Date().toISOString(),
    error: error.message
  });
  console.error('[generateDailyBlog] failed:', error);
  process.exit(1);
});
