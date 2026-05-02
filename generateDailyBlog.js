import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { execFileSync } from 'child_process';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = __dirname;
const TOPICS_FILE = path.join(ROOT, 'data', 'blog_topics.json');
const ARTICLES_DIR = path.join(ROOT, 'data', 'blog_articles');
const SITE_FILE = path.join(ROOT, 'data', 'site.json');
const ENV_FILE = path.join(ROOT, '.env');
const LOG_FILE = path.join(ROOT, 'data', 'blog_generation_log.json');
const BLOG_IMAGES_DIR = path.join(ROOT, 'assets', 'images', 'blog');

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
    words >= 1200
  ];
  return Math.round(checks.reduce((sum, ok) => sum + (ok ? 100 / checks.length : 0), 0));
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

function chooseTopics(topics, articles, neededCount) {
  const usedTitles = new Set(articles.map((a) => a.title_ar));
  const usedSlugs  = new Set(articles.map((a) => a.slug).filter(Boolean));
  const available = topics.filter(
    (topic) => !usedTitles.has(topic) && !usedSlugs.has(slugify(topic))
  );
  if (available.length >= neededCount) return available.slice(0, neededCount);
  // All topics used — fall back but still avoid slug collisions
  const noSlugCollision = topics.filter((t) => !usedSlugs.has(slugify(t)));
  const pool = noSlugCollision.length ? noSlugCollision : topics;
  return pool.slice(0, neededCount);
}

function createdTodayCount(articles, cairoDate) {
  return articles.filter((article) => {
    const source = article.created_at || article.published_at || '';
    return source.startsWith(cairoDate);
  }).length;
}

function ensureInternalLinks(article, siteData) {
  const links = Array.isArray(article.internal_links) ? article.internal_links.filter((item) => item?.anchor && item?.url) : [];
  if (links.length >= 3) return links.slice(0, 5);
  const additions = siteData.core_links.filter((link) => !links.some((item) => item.url === link.url));
  return [...links, ...additions].slice(0, 5);
}

function autoLinkMarkdown(markdown = '', links = []) {
  let updated = String(markdown || '');
  for (const link of links) {
    if (!link?.anchor || !link?.url) continue;
    if (updated.includes(`](${link.url})`)) continue;
    const escaped = link.anchor.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const regex = new RegExp(escaped, 'i');
    updated = updated.replace(regex, `[${link.anchor}](${link.url})`);
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

## مقدمة

يبحث كثير من الأشخاص عن معلومات حول اضطرابات النوم أو أجهزة العلاج التنفسي المنزلي، لكن المشكلة ليست فقط في كثرة المعلومات، بل في أن جزءًا كبيرًا منها إما مبالغ فيه أو غير واضح. لهذا نحاول هنا تقديم شرح منظم ومسؤول يساعدك على فهم الفكرة الأساسية، ومتى يكون من المناسب طلب تقييم متخصص.

## لماذا يهم هذا الموضوع؟

قد تظهر المشكلة في صورة شخير مستمر، أو تعب صباحي، أو صعوبة في التكيف مع جهاز معين، أو حيرة عند المقارنة بين أكثر من خيار. فهم هذا الموضوع لا يعني تشخيص الحالة، لكنه يساعدك على طرح الأسئلة الصحيحة، وفهم دور [أجهزة CPAP](/services/cpap/) أو [أجهزة BiPAP](/services/bipap/) أو [ماسكات CPAP](/services/cpap-masks/) عندما يوصي بها الطبيب.

## شرح مبسط

في كثير من الحالات، يكون الهدف هو تحسين جودة النوم وتقليل الاضطرابات التي تؤثر على التنفس أثناء الليل. وقد يعتمد ذلك على تقييم الأعراض، ونتائج الفحص أو دراسة النوم عند الحاجة، ثم اختيار الجهاز أو الماسك المناسب بناءً على توصية الطبيب وطبيعة الاستخدام اليومي في المنزل.

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
    featured_image: '/assets/images/store/respira-tech-logo.png',
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

async function generateWithOpenAI({ topic, env, siteData }) {
  if (!env.OPENAI_API_KEY) return null;

  const systemPrompt = 'You are an expert Arabic SEO medical content writer for a respiratory therapy company. Write accurate, clear, responsible Arabic content about sleep apnea, CPAP, BiPAP, respiratory therapy, and home breathing support. The content must be educational, trustworthy, and conversion-focused without making unsafe medical claims. Do not diagnose. Do not prescribe treatment. Encourage the reader to consult a doctor or specialist. Use simple Arabic suitable for Egypt and Arabic-speaking audiences. Write naturally so the article feels manually written by a skilled Arabic human editor, with varied sentence structure and no robotic repetition.';
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

القواعد:
- المقال لا يقل عن 1200 كلمة عربية.
- يجب أن يبدو كأنه مكتوب يدويًا بواسطة محرر عربي محترف، وليس نصًا آليًا.
- استخدم H1 مرة واحدة فقط.
- استخدم H2 و H3 بشكل منظم.
- أضف مقدمة واضحة.
- أضف قسمًا للأعراض عندما يكون ذلك مناسبًا.
- أضف شرحًا عمليًا.
- أضف قسم: متى تطلب المساعدة؟
- أضف FAQ.
- أضف CTA نهائي.
- استخدم 3 إلى 5 روابط داخلية طبيعية من هذه الروابط فقط: /services/cpap/ /services/bipap/ /services/sleep-apnea/ /services/cpap-masks/ /store/ /contact/
- لا تكرر الكلمات المفتاحية بشكل مصطنع.
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
    featured_image: '/assets/images/store/respira-tech-logo.png',
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
  if (!env.OPENAI_API_KEY) return '/assets/images/store/respira-tech-logo.png';
  if (String(env.GENERATE_BLOG_IMAGES || 'true').toLowerCase() === 'false') {
    return '/assets/images/store/respira-tech-logo.png';
  }

  fs.mkdirSync(BLOG_IMAGES_DIR, { recursive: true });
  const fileName = `${article.slug}.png`;
  const filePath = path.join(BLOG_IMAGES_DIR, fileName);
  const preferred = env.OPENAI_IMAGE_MODEL || 'gpt-image-1';
  const modelCandidates = [preferred, 'dall-e-3'].filter((model, index, arr) => model && arr.indexOf(model) === index);

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

async function buildArticle(topic, env, siteData) {
  let article = null;
  try {
    article = await generateWithOpenAI({ topic, env, siteData });
  } catch (error) {
    appendLog({
      type: 'openai_text_error',
      topic,
      created_at: new Date().toISOString(),
      error: error.message
    });
  }

  if (!article) {
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
    article.featured_image = '/assets/images/store/respira-tech-logo.png';
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

  const selectedTopics = chooseTopics(topics, articles, remaining);
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
  for (const topic of selectedTopics) {
    const article = await buildArticle(topic, env, siteData);
    const filePath = path.join(ARTICLES_DIR, `${article.slug}.json`);
    writeJson(filePath, article);
    generated.push({
      topic,
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
