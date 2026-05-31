from __future__ import annotations

import json
import os
import re
import subprocess
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
from html.parser import HTMLParser
from pathlib import Path
from typing import Any
from urllib.parse import urljoin, urlparse

import requests

try:
    from google.oauth2 import service_account
    from googleapiclient.discovery import build as google_build
except Exception:  # pragma: no cover
    service_account = None
    google_build = None


BASE_DIR = Path(__file__).resolve().parent
BLOG_DIR = BASE_DIR / "data" / "blog_articles"
SITE_FILE = BASE_DIR / "data" / "site.json"
TOPICS_FILE = BASE_DIR / "data" / "blog_topics.json"
ENV_FILE = BASE_DIR / ".env"
SEO_AUDIT_FILE = BASE_DIR / "data" / "seo_audit.json"
SEO_LOG_FILE = BASE_DIR / "data" / "seo_brain_log.json"
GSC_CREDENTIALS_FILE = BASE_DIR / "data" / "gsc-service-account.json"
BLOG_IMAGES_DIR = BASE_DIR / "assets" / "images" / "blog"
BUILD_SCRIPT = BASE_DIR / "build_content.py"
GSC_SCOPES = ["https://www.googleapis.com/auth/webmasters"]
ARTICLE_MIN_WORDS = int(os.getenv("ARTICLE_MIN_WORDS", os.getenv("BLOG_ARTICLE_MIN_WORDS", "1600")) or "1600")
ARTICLE_TARGET_WORDS = int(os.getenv("ARTICLE_TARGET_WORDS", os.getenv("BLOG_ARTICLE_TARGET_WORDS", "1800")) or "1800")

INTERNAL_LINK_STRATEGY = [
    {"url": "/services/cpap/", "anchors": ["أفضل أجهزة CPAP لعلاج انقطاع النفس أثناء النوم", "أجهزة CPAP المنزلية", "جهاز CPAP المناسب لحالتك", "خدمة أجهزة CPAP من Respira Tech"]},
    {"url": "/services/bipap/", "anchors": ["الفرق بين أجهزة CPAP و BiPAP", "أجهزة BiPAP للحالات التي تحتاج دعم تنفسي أكبر", "متى يكون جهاز BiPAP اختيارًا مناسبًا", "خدمة أجهزة BiPAP المنزلية"]},
    {"url": "/services/sleep-apnea/", "anchors": ["أعراض انقطاع النفس أثناء النوم", "تشخيص انقطاع النفس أثناء النوم", "الشخير وتوقف التنفس أثناء النوم", "متى تحتاج لتقييم اضطرابات النوم"]},
    {"url": "/services/cpap-masks/", "anchors": ["اختيار ماسك CPAP المناسب", "حل مشكلة تسريب الهواء من ماسك CPAP", "أنواع ماسكات CPAP وطرق اختيارها", "ماسكات CPAP المريحة للاستخدام اليومي"]},
    {"url": "/store/", "anchors": ["تصفح أجهزة التنفس وماسكات CPAP المتاحة", "شراء مستلزمات CPAP و BiPAP", "منتجات Respira Tech لأجهزة التنفس المنزلي", "خيارات أجهزة وماسكات التنفس المتوفرة"]},
    {"url": "/contact/", "anchors": ["استشارة Respira Tech لاختيار الجهاز المناسب", "التواصل مع مختص قبل شراء جهاز CPAP", "مساعدة في اختيار جهاز التنفس المنزلي", "طلب دعم لاختيار الماسك أو الجهاز"]},
]

AR_STOPWORDS = {
    "في", "من", "على", "إلى", "عن", "أن", "أو", "مع", "هذا", "هذه", "ذلك", "التي", "الذي",
    "كيف", "هل", "ما", "هو", "هي", "بين", "بعد", "قبل", "عند", "قد", "ثم", "كما", "إذا",
    "إليك", "حول", "لأن", "لكن", "خلال", "أثناء", "كل", "غير", "عبر", "حتى", "تم", "قد",
    "cpap", "bipap", "respira", "tech"
}


def load_env() -> dict[str, str]:
    values: dict[str, str] = {}
    if ENV_FILE.exists():
        for line in ENV_FILE.read_text(encoding="utf-8").splitlines():
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            key, value = line.split("=", 1)
            values[key.strip()] = value.strip()
    values.update({key: value for key, value in os.environ.items() if value is not None})
    return values


def load_json(path: Path, default: Any = None) -> Any:
    if not path.exists():
        return default
    with path.open("r", encoding="utf-8") as file:
        return json.load(file)


def save_json(path: Path, payload: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8") as file:
        json.dump(payload, file, ensure_ascii=False, indent=2)


def append_log(entry: dict) -> None:
    logs = load_json(SEO_LOG_FILE, [])
    logs = logs if isinstance(logs, list) else []
    logs.insert(0, entry)
    save_json(SEO_LOG_FILE, logs[:200])


def google_service_account_email() -> str:
    credentials = load_json(GSC_CREDENTIALS_FILE, {})
    if not isinstance(credentials, dict):
        return ""
    return str(credentials.get("client_email") or "")


def _gsc_service():
    if not GSC_CREDENTIALS_FILE.exists():
        raise RuntimeError("Google Search Console credentials غير مرفوعة بعد.")
    if service_account is None or google_build is None:
        raise RuntimeError("مكتبات Google Search Console غير متاحة في البيئة الحالية.")
    creds = service_account.Credentials.from_service_account_file(
        str(GSC_CREDENTIALS_FILE),
        scopes=GSC_SCOPES,
    )
    return google_build("searchconsole", "v1", credentials=creds, cache_discovery=False)


def submit_sitemap(site_url: str | None = None, sitemap_url: str | None = None) -> dict:
    env = load_env()
    site_url = (site_url or env.get("GSC_SITE_URL") or env.get("SITE_BASE_URL") or "https://respira-tech.com").rstrip("/")
    sitemap_url = sitemap_url or f"{site_url}/sitemap.xml"
    service = _gsc_service()
    service.sitemaps().submit(siteUrl=site_url, feedpath=sitemap_url).execute()
    result = {
        "submitted": True,
        "site_url": site_url,
        "sitemap_url": sitemap_url,
        "submitted_at": datetime.utcnow().isoformat(),
    }
    append_log({"type": "gsc_sitemap_submit", **result})
    return result


def run_build() -> None:
    subprocess.run(["python3", str(BUILD_SCRIPT)], cwd=BASE_DIR, check=True)


def slugify(value: str) -> str:
    value = value.strip().lower()
    value = re.sub(r"[^a-z0-9\u0600-\u06ff._-]+", "-", value)
    value = re.sub(r"-{2,}", "-", value).strip("-")
    return value or f"article-{datetime.now().strftime('%Y%m%d%H%M%S')}"


def strip_markdown(markdown: str) -> str:
    text = re.sub(r"`([^`]+)`", r"\1", markdown)
    text = re.sub(r"\[([^\]]+)\]\(([^)]+)\)", r"\1", text)
    text = re.sub(r"^#+\s*", "", text, flags=re.M)
    text = re.sub(r"^>\s*", "", text, flags=re.M)
    text = re.sub(r"[*_~]", "", text)
    return text.strip()


def estimate_reading_time(markdown: str) -> int:
    words = len(strip_markdown(markdown).split())
    return max(1, round(words / 180))


def seo_score(article: dict) -> int:
    markdown = article.get("content_markdown", "")
    checks = [
        bool(article.get("title_ar")),
        len(article.get("meta_title", "")) <= 60 and bool(article.get("meta_title")),
        len(article.get("meta_description", "")) <= 160 and bool(article.get("meta_description")),
        "# " in markdown,
        "## " in markdown,
        bool(article.get("faq")),
        bool(article.get("cta_text")),
        bool(article.get("internal_links")),
        bool(article.get("medical_disclaimer")),
        bool(article.get("category")),
        bool(article.get("slug")),
        len(strip_markdown(markdown).split()) >= ARTICLE_MIN_WORDS,
    ]
    return round(sum(100 / len(checks) for item in checks if item))


def link_score_for_text(link: dict, text: str) -> int:
    source = str(text or "").lower()
    url = link.get("url", "")
    score = 0
    if "cpap" in url and "cpap" in source:
        score += 3
    if "bipap" in url and "bipap" in source:
        score += 3
    if "sleep-apnea" in url and re.search(r"انقطاع|الشخير|النفس|النوم", source):
        score += 3
    if "cpap-masks" in url and re.search(r"ماسك|قناع|تسريب|mask", source):
        score += 3
    if "store" in url and re.search(r"شراء|سعر|منتج|متجر|اختيار", source):
        score += 1
    if "contact" in url:
        score += 1
    return score


def preferred_anchor(link: dict, topic_text: str, index: int = 0) -> str:
    options = next((item["anchors"] for item in INTERNAL_LINK_STRATEGY if item["url"] == link.get("url")), None)
    if not options:
        return str(link.get("anchor") or "")
    offset = sum(ord(char) for char in slugify(topic_text))
    return options[(offset + index) % len(options)]


def is_weak_anchor(anchor: str) -> bool:
    value = str(anchor or "").strip()
    return (
        len(value) < 13
        or re.search(r"^(صفحة|المتجر|تواصل معنا|اضغط هنا|اقرأ المزيد|خدمة العملاء)$", value, re.I) is not None
        or re.search(r"^صفحة\s+", value, re.I) is not None
    )


def ensure_internal_links(article: dict, site_data: dict, limit: int = 6) -> list[dict]:
    topic_text = f"{article.get('title_ar','')} {article.get('excerpt','')} {article.get('category','')}"
    by_url: dict[str, dict] = {}
    for item in article.get("internal_links") or []:
        url = item.get("url")
        if not url:
            continue
        core = next((link for link in site_data.get("core_links", []) if link.get("url") == url), item)
        raw_anchor = str(item.get("anchor") or "")
        anchor = raw_anchor if not is_weak_anchor(raw_anchor) else preferred_anchor(core, topic_text, len(by_url))
        by_url[url] = {"anchor": anchor, "url": url}
    core_links = sorted(site_data.get("core_links", []), key=lambda item: link_score_for_text(item, topic_text), reverse=True)
    for link in core_links:
        if link.get("url") not in by_url:
            by_url[link["url"]] = {"anchor": preferred_anchor(link, topic_text, len(by_url)), "url": link["url"]}
    return list(by_url.values())[:limit]


def list_articles() -> list[dict]:
    articles: list[dict] = []
    for path in sorted(BLOG_DIR.glob("*.json")):
        article = load_json(path, {})
        if not isinstance(article, dict):
            continue
        article["_path"] = str(path)
        article["reading_time"] = article.get("reading_time") or estimate_reading_time(article.get("content_markdown", ""))
        article["seo_score"] = seo_score(article)
        articles.append(article)
    articles.sort(key=lambda item: item.get("published_at") or item.get("created_at") or "", reverse=True)
    return articles


def load_site_data() -> dict:
    payload = load_json(SITE_FILE, {})
    return payload if isinstance(payload, dict) else {}


def save_article(article: dict) -> None:
    path = BLOG_DIR / f"{article['slug']}.json"
    save_json(path, article)


def article_url(site_data: dict, slug: str) -> str:
    return f"{site_data['site']['base_url'].rstrip('/')}/blog/{slug}/"


def extract_keywords(text: str) -> set[str]:
    tokens = re.findall(r"[\u0600-\u06ffA-Za-z0-9]{3,}", text.lower())
    return {token for token in tokens if token not in AR_STOPWORDS}


def ensure_meta_fallbacks(article: dict, site_data: dict) -> dict:
    article = dict(article)
    title = article.get("title_ar", "").strip()
    excerpt = article.get("excerpt", "").strip()
    if not article.get("meta_title"):
        article["meta_title"] = f"{title} | {site_data['site']['name']}"[:60]
    if len(article.get("meta_title", "")) > 60:
        article["meta_title"] = article["meta_title"][:60].rstrip()
    if not article.get("meta_description"):
        summary = excerpt or strip_markdown(article.get("content_markdown", ""))[:140]
        article["meta_description"] = summary[:158].rstrip(" .،") + "."
    if len(article.get("meta_description", "")) > 160:
        article["meta_description"] = article["meta_description"][:160].rstrip()
    if not article.get("excerpt"):
        article["excerpt"] = strip_markdown(article.get("content_markdown", ""))[:180].strip()
    return article


def auto_link_markdown(markdown: str, links: list[dict]) -> str:
    updated = re.sub(
        r"\[\[([^\]]+)\]\((/[^)]+)\)\]\(https://respira-tech\.com/?[^)]*\)",
        r"[\1](\2)",
        str(markdown or ""),
    )
    missing_links = []
    for link in links:
        anchor = link.get("anchor")
        url = link.get("url")
        if not anchor or not url:
            continue
        existing_link = re.search(rf"\[([^\]]+)\]\((?:https://respira-tech\.com)?{re.escape(url)}\)", updated, re.I)
        if existing_link:
            if is_weak_anchor(existing_link.group(1)):
                updated = re.sub(
                    rf"\[([^\]]+)\]\((?:https://respira-tech\.com)?{re.escape(url)}\)",
                    f"[{anchor}]({url})",
                    updated,
                    count=1,
                    flags=re.I,
                )
            continue
        pattern = re.compile(re.escape(anchor), re.I)
        if pattern.search(updated):
            updated, count = pattern.subn(f"[{anchor}]({url})", updated, count=1)
            if count:
                continue
        missing_links.append(link)
    if missing_links and "## روابط تساعدك على الخطوة التالية" not in updated:
        lines = ["", "## روابط تساعدك على الخطوة التالية", ""]
        for link in missing_links[:6]:
            lines.append(f"- [{link['anchor']}]({link['url']})")
        insertion = "\n" + "\n".join(lines) + "\n"
        cta_match = re.search(r"\n##\s+(الخلاصة|هل تحتاج|تواصل|CTA|دعوة)", updated, re.I)
        if cta_match:
            updated = f"{updated[:cta_match.start()].rstrip()}{insertion}\n{updated[cta_match.start():].lstrip()}"
        else:
            updated = f"{updated.rstrip()}{insertion}"
    return updated


def append_related_section(markdown: str, related_links: list[dict]) -> str:
    if not related_links:
        return markdown
    if "## مقالات مرتبطة" in markdown:
        return markdown
    lines = ["", "## مقالات مرتبطة", ""]
    for link in related_links[:3]:
        lines.append(f"- [{link['anchor']}]({link['url']})")
    return markdown.rstrip() + "\n" + "\n".join(lines) + "\n"


class ContentExtractor(HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.in_script = False
        self.in_style = False
        self.current_tag = ""
        self.title = ""
        self.meta_description = ""
        self.paragraphs: list[str] = []
        self.headings: list[str] = []
        self._buffer = []

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        self.current_tag = tag
        if tag in {"script", "style", "noscript"}:
            self.in_script = True
        attrs_dict = dict(attrs)
        if tag == "meta" and attrs_dict.get("name", "").lower() == "description":
            self.meta_description = attrs_dict.get("content", "") or ""

    def handle_endtag(self, tag: str) -> None:
        if tag in {"script", "style", "noscript"}:
            self.in_script = False
        if self.current_tag in {"p", "li"} and self._buffer:
            text = " ".join(self._buffer).strip()
            if len(text) > 60:
                self.paragraphs.append(text)
        if self.current_tag in {"h1", "h2", "h3"} and self._buffer:
            text = " ".join(self._buffer).strip()
            if text:
                self.headings.append(text)
        if tag == "title" and self._buffer and not self.title:
            self.title = " ".join(self._buffer).strip()
        self._buffer = []
        self.current_tag = ""

    def handle_data(self, data: str) -> None:
        if self.in_script:
            return
        cleaned = re.sub(r"\s+", " ", data).strip()
        if cleaned:
            self._buffer.append(cleaned)


def fetch_url_content(url: str) -> dict:
    response = requests.get(url, timeout=25, headers={"User-Agent": "RespiraTechBot/1.0"})
    response.raise_for_status()
    parser = ContentExtractor()
    parser.feed(response.text)
    paragraphs = parser.paragraphs[:18]
    headings = parser.headings[:10]
    return {
        "url": url,
        "title": parser.title or headings[0] if headings else url,
        "meta_description": parser.meta_description,
        "headings": headings,
        "paragraphs": paragraphs,
        "text_excerpt": "\n".join(paragraphs[:8])[:5000],
    }


def generate_openai_json(system_prompt: str, user_prompt: str, schema: dict) -> dict | None:
    env = load_env()
    api_key = env.get("OPENAI_API_KEY")
    if not api_key:
        return None
    response = requests.post(
        "https://api.openai.com/v1/chat/completions",
        timeout=120,
        headers={
            "Content-Type": "application/json",
            "Authorization": f"Bearer {api_key}",
        },
        json={
            "model": env.get("OPENAI_TEXT_MODEL", "gpt-4.1"),
            "temperature": 0.5,
            "response_format": {
                "type": "json_schema",
                "json_schema": {
                    "name": "respiratech_seo_brain_response",
                    "schema": schema,
                    "strict": True,
                },
            },
            "messages": [
                {"role": "system", "content": system_prompt},
                {"role": "user", "content": user_prompt},
            ],
        },
    )
    response.raise_for_status()
    payload = response.json()
    raw = payload["choices"][0]["message"]["content"]
    return json.loads(raw)


def generate_featured_image(slug: str, title_ar: str, category: str) -> str:
    env = load_env()
    api_key = env.get("OPENAI_API_KEY")
    if not api_key or str(env.get("GENERATE_BLOG_IMAGES", "true")).lower() == "false":
        return "/assets/images/store/respira-tech-logo.png"
    prompt = (
        f"Clean white medical website image, Arabic SEO article cover about {title_ar}, "
        f"{category}, CPAP/BiPAP respiratory therapy, modern bedroom or consultation setting, "
        "soft daylight, premium healthcare, realistic, no text, no logos, no watermark."
    )
    BLOG_IMAGES_DIR.mkdir(parents=True, exist_ok=True)
    file_name = f"{slug}.png"
    file_path = BLOG_IMAGES_DIR / file_name
    models = []
    preferred = env.get("OPENAI_IMAGE_MODEL", "dall-e-3")
    for model in [preferred, "dall-e-3", "gpt-image-1"]:
        if model and model not in models:
            models.append(model)
    last_error = None
    for model in models:
        payload = {"model": model, "prompt": prompt, "size": "1024x1024"}
        if model != "dall-e-3":
            payload["quality"] = "medium"
        response = requests.post(
            "https://api.openai.com/v1/images/generations",
            timeout=180,
            headers={
                "Content-Type": "application/json",
                "Authorization": f"Bearer {api_key}",
            },
            json=payload,
        )
        data = response.json() if response.content else {}
        if not response.ok:
            last_error = f"{model}: {data}"
            continue
        image_base64 = (data.get("data") or [{}])[0].get("b64_json")
        image_url = (data.get("data") or [{}])[0].get("url")
        if image_base64:
            import base64
            file_path.write_bytes(base64.b64decode(image_base64))
            return f"/assets/images/blog/{file_name}"
        if image_url:
            image_response = requests.get(image_url, timeout=120)
            image_response.raise_for_status()
            file_path.write_bytes(image_response.content)
            return f"/assets/images/blog/{file_name}"
    append_log({
        "type": "seo_brain_image_error",
        "created_at": datetime.utcnow().isoformat(),
        "error": last_error or "image generation failed",
        "slug": slug,
    })
    return "/assets/images/store/respira-tech-logo.png"


def build_article_from_url(source_url: str, publish: bool = False) -> dict:
    site_data = load_site_data()
    env = load_env()
    source = fetch_url_content(source_url)
    schema = {
        "type": "object",
        "additionalProperties": False,
        "required": [
            "title_ar", "slug", "meta_title", "meta_description", "excerpt",
            "category", "tags", "content_markdown", "faq", "internal_links",
            "cta_text", "cta_button_text", "medical_disclaimer"
        ],
        "properties": {
            "title_ar": {"type": "string"},
            "slug": {"type": "string"},
            "meta_title": {"type": "string"},
            "meta_description": {"type": "string"},
            "excerpt": {"type": "string"},
            "category": {"type": "string"},
            "tags": {"type": "array", "items": {"type": "string"}},
            "content_markdown": {"type": "string"},
            "faq": {
                "type": "array",
                "items": {
                    "type": "object",
                    "additionalProperties": False,
                    "required": ["question", "answer"],
                    "properties": {"question": {"type": "string"}, "answer": {"type": "string"}},
                },
            },
            "internal_links": {
                "type": "array",
                "items": {
                    "type": "object",
                    "additionalProperties": False,
                    "required": ["anchor", "url"],
                    "properties": {"anchor": {"type": "string"}, "url": {"type": "string"}},
                },
            },
            "cta_text": {"type": "string"},
            "cta_button_text": {"type": "string"},
            "medical_disclaimer": {"type": "string"},
        },
    }
    system_prompt = (
        "You are an expert Arabic SEO medical content writer for a respiratory therapy company. "
        "Read the provided source page as research only, then write an original Arabic article in your own words. "
        "Do not copy text verbatim. Make the article feel manually written by a strong Arabic editor. "
        "Keep it educational, accurate, medically responsible, SEO-friendly, and useful for Egypt/Arabic audiences."
    )
    user_prompt = f"""المصدر:
الرابط: {source['url']}
العنوان: {source['title']}
الوصف: {source['meta_description']}
العناوين: {' | '.join(source['headings'][:8])}
مقتطفات:
{source['text_excerpt']}

المطلوب:
- اكتب مقالًا عربيًا أصليًا ومهنيًا مبنيًا على فهم المصدر، وليس نسخًا منه.
- لا تقل عن {ARTICLE_MIN_WORDS} كلمة، والهدف المثالي حوالي {ARTICLE_TARGET_WORDS} كلمة.
- اجعل المقال مناسبًا للسيو وللقراءة البشرية، وبأسلوب طبيعي جدًا.
- اجعل كل قسم رئيسي غنيًا: 2 إلى 4 فقرات أو نقاط عملية عند الحاجة.
- أضف قسمًا للأخطاء الشائعة أو جدول مقارنة Markdown إذا كان مناسبًا للموضوع.
- أضف روابط داخلية طبيعية إلى: /services/cpap/ /services/bipap/ /services/sleep-apnea/ /services/cpap-masks/ /store/ /contact/
- استخدم anchor text وصفيًا طويلًا، وليس كلمات عامة مثل "اضغط هنا" أو "المتجر" أو "تواصل معنا" فقط.
- وزع الروابط داخل الفقرات في سياق منطقي.
- اختم بدعوة للتواصل عبر واتساب.
- المقال يجب أن يناسب موقع Respira Tech.
"""
    parsed = generate_openai_json(system_prompt, user_prompt, schema)
    if not parsed:
        slug = slugify(source["title"])
        now_iso = datetime.utcnow().isoformat()
        article = {
            "id": slug,
            "title_ar": source["title"],
            "slug": slug,
            "meta_title": f"{source['title']} | {site_data['site']['name']}"[:60],
            "meta_description": (source["meta_description"] or source["title"])[:160],
            "excerpt": (source["meta_description"] or source["title"])[:180],
            "category": "نصائح الاستخدام والعناية",
            "tags": ["Respira Tech", "محتوى معاد الصياغة"],
            "content_markdown": f"# {source['title']}\n\n{source['text_excerpt']}\n",
            "faq": [],
            "internal_links": site_data.get("core_links", [])[:5],
            "cta_text": "فريق Respira Tech يساعدك في فهم احتياجك واختيار الجهاز المناسب.",
            "cta_button_text": "تواصل معنا عبر واتساب",
            "medical_disclaimer": site_data["site"]["medical_disclaimer"],
        }
    else:
        article = parsed

    now_iso = datetime.utcnow().isoformat()
    slug = slugify(article.get("slug") or article.get("title_ar") or source["title"])
    article["id"] = slug
    article["slug"] = slug
    article["author"] = site_data["site"]["author"]
    article["status"] = "published" if publish else "draft"
    article["created_at"] = now_iso
    article["updated_at"] = now_iso
    article["published_at"] = now_iso if publish else None
    article["internal_links"] = ensure_internal_links(article, site_data)
    article["content_markdown"] = auto_link_markdown(article.get("content_markdown", ""), article["internal_links"])
    article["cta_button_url"] = f"https://wa.me/{env.get('WHATSAPP_NUMBER', site_data['site']['whatsapp_number'])}"
    article["medical_disclaimer"] = site_data["site"]["medical_disclaimer"]
    article["reading_time"] = estimate_reading_time(article.get("content_markdown", ""))
    article = ensure_meta_fallbacks(article, site_data)
    article["featured_image_prompt"] = (
        "Clean white medical website image, CPAP/BiPAP respiratory therapy, modern bedroom, "
        "soft daylight, premium healthcare, no text, no logos, realistic."
    )
    article["featured_image"] = generate_featured_image(slug, article["title_ar"], article.get("category", ""))
    article["seo_score"] = seo_score(article)
    save_article(article)
    run_build()
    append_log({
        "type": "seo_brain_manual_article",
        "created_at": now_iso,
        "source_url": source_url,
        "slug": slug,
        "status": article["status"],
    })
    return article


def suggest_related_article_links(article: dict, articles: list[dict], site_data: dict) -> list[dict]:
    current_slug = article.get("slug")
    current_keywords = extract_keywords(f"{article.get('title_ar','')} {article.get('excerpt','')}")
    current_category = article.get("category")
    candidates = []
    for other in articles:
        if other.get("slug") == current_slug or other.get("status") != "published":
            continue
        score = 0
        if other.get("category") == current_category:
            score += 3
        overlap = current_keywords & extract_keywords(f"{other.get('title_ar','')} {other.get('excerpt','')}")
        score += len(overlap)
        if score > 0:
            candidates.append((score, other))
    candidates.sort(key=lambda item: item[0], reverse=True)
    links = []
    for _, candidate in candidates[:3]:
        links.append({
            "anchor": candidate.get("title_ar", ""),
            "url": f"/blog/{candidate.get('slug')}/",
        })
    existing_urls = {item.get("url") for item in article.get("internal_links", [])}
    return [link for link in links if link["url"] not in existing_urls]


def refresh_article_links(auto_fix: bool = True) -> dict:
    site_data = load_site_data()
    articles = list_articles()
    updated = []
    for article in articles:
        original = json.dumps(article, ensure_ascii=False, sort_keys=True)
        article["internal_links"] = article.get("internal_links") or []
        article["internal_links"] = ensure_internal_links(article, site_data)
        new_links = suggest_related_article_links(article, articles, site_data)
        article["internal_links"].extend(new_links[:2])
        article["internal_links"] = article["internal_links"][:8]
        article["content_markdown"] = auto_link_markdown(article.get("content_markdown", ""), article["internal_links"])
        if new_links:
            article["content_markdown"] = append_related_section(article["content_markdown"], new_links)
        article = ensure_meta_fallbacks(article, site_data)
        article["reading_time"] = estimate_reading_time(article.get("content_markdown", ""))
        article["updated_at"] = datetime.utcnow().isoformat()
        article["seo_score"] = seo_score(article)
        if auto_fix and (json.dumps(article, ensure_ascii=False, sort_keys=True) != original):
            save_article(article)
            updated.append(article["slug"])
    if auto_fix and updated:
        run_build()
    append_log({
        "type": "seo_brain_refresh_links",
        "created_at": datetime.utcnow().isoformat(),
        "updated_count": len(updated),
        "updated_slugs": updated[:50],
    })
    return {"updated_count": len(updated), "updated_slugs": updated[:50]}


def page_health(url: str) -> dict:
    start = datetime.now()
    response = requests.get(url, timeout=20, headers={"User-Agent": "RespiraTechBot/1.0"})
    duration_ms = int((datetime.now() - start).total_seconds() * 1000)
    html = response.text
    title_match = re.search(r"<title>(.*?)</title>", html, re.I | re.S)
    desc_match = re.search(r'<meta\s+name="description"\s+content="([^"]*)"', html, re.I)
    canon_match = re.search(r'<link\s+rel="canonical"\s+href="([^"]*)"', html, re.I)
    h1_count = len(re.findall(r"<h1\b", html, re.I))
    return {
        "url": url,
        "status": response.status_code,
        "load_ms": duration_ms,
        "html_kb": round(len(response.content) / 1024, 1),
        "has_title": bool(title_match),
        "has_meta_description": bool(desc_match),
        "has_canonical": bool(canon_match),
        "h1_count": h1_count,
    }


def search_console_summary(site_url: str) -> dict:
    if not GSC_CREDENTIALS_FILE.exists():
        return {
            "configured": False,
            "message": "Google Search Console غير مربوط بعد. ارفع ملف service account JSON وامنح الإيميل صلاحية على الـ property.",
            "queries": [],
        }
    if service_account is None or google_build is None:
        return {
            "configured": False,
            "message": "مكتبات Google Search Console غير متاحة في البيئة الحالية.",
            "queries": [],
        }
    try:
        service = _gsc_service()
        today = datetime.now(timezone.utc).date()
        start_date = (today - timedelta(days=28)).isoformat()
        end_date = (today - timedelta(days=1)).isoformat()
        result = service.searchanalytics().query(
            siteUrl=site_url,
            body={
                "startDate": start_date,
                "endDate": end_date,
                "dimensions": ["query"],
                "rowLimit": 10,
            },
        ).execute()
        rows = result.get("rows", [])
        return {
            "configured": True,
            "message": "تم جلب أعلى الكلمات من Google Search Console.",
            "queries": [
                {
                    "query": row.get("keys", [""])[0],
                    "clicks": row.get("clicks", 0),
                    "impressions": row.get("impressions", 0),
                    "ctr": row.get("ctr", 0),
                    "position": row.get("position", 0),
                }
                for row in rows
            ],
        }
    except Exception as exc:
        return {
            "configured": False,
            "message": f"تعذر قراءة Search Console: {exc}",
            "queries": [],
        }


def audit_site() -> dict:
    env = load_env()
    site_data = load_site_data()
    base_url = env.get("SITE_BASE_URL") or site_data["site"]["base_url"]
    articles = list_articles()
    pages = [
        f"{base_url}/",
        f"{base_url}/store/",
        f"{base_url}/blog/",
        f"{base_url}/services/",
        f"{base_url}/contact/",
    ]
    page_checks = []
    for url in pages:
        try:
            page_checks.append(page_health(url))
        except Exception as exc:
            page_checks.append({
                "url": url,
                "status": 0,
                "load_ms": None,
                "html_kb": None,
                "has_title": False,
                "has_meta_description": False,
                "has_canonical": False,
                "h1_count": 0,
                "error": str(exc),
            })

    published = [item for item in articles if item.get("status") == "published"]
    drafts = [item for item in articles if item.get("status") != "published"]
    logo_images = [
        item.get("slug")
        for item in published
        if str(item.get("featured_image", "")).endswith("/respira-tech-logo.png")
    ]
    low_score = [
        {"slug": item.get("slug"), "title": item.get("title_ar"), "seo_score": item.get("seo_score", 0)}
        for item in articles if item.get("seo_score", 0) < 85
    ][:12]
    no_internal = [item.get("slug") for item in articles if not item.get("internal_links")]
    recommendations = []
    if logo_images:
        recommendations.append(f"{len(logo_images)} مقال ما زال يستخدم لوجو الموقع بدل صورة مميزة.")
    if low_score:
        recommendations.append(f"{len(low_score)} مقال يحتاج تحسين SEO score أو metadata.")
    if no_internal:
        recommendations.append(f"{len(no_internal)} مقال بدون internal links كافية.")
    page_failures = [item for item in page_checks if item.get("status") != 200 or item.get("h1_count") != 1]
    if page_failures:
        recommendations.append("بعض الصفحات الأساسية تحتاج مراجعة structure أو availability.")

    audit = {
        "created_at": datetime.utcnow().isoformat(),
        "site_base_url": base_url,
        "pages": page_checks,
        "content": {
            "articles_total": len(articles),
            "published_total": len(published),
            "draft_total": len(drafts),
            "logo_featured_images": logo_images,
            "low_score_articles": low_score,
            "missing_internal_links": no_internal[:20],
        },
        "search_console": search_console_summary(base_url),
        "recommendations": recommendations,
    }
    save_json(SEO_AUDIT_FILE, audit)
    append_log({
        "type": "seo_brain_audit",
        "created_at": audit["created_at"],
        "recommendations_count": len(recommendations),
    })
    return audit


def full_run() -> dict:
    link_result = refresh_article_links(auto_fix=True)
    audit = audit_site()
    try:
        sitemap_submission = submit_sitemap()
    except Exception as exc:
        sitemap_submission = {"submitted": False, "error": str(exc)}
    result = {
        "created_at": datetime.utcnow().isoformat(),
        "links": link_result,
        "audit_summary": {
            "recommendations": audit.get("recommendations", []),
            "logo_featured_images": len(audit.get("content", {}).get("logo_featured_images", [])),
            "low_score_articles": len(audit.get("content", {}).get("low_score_articles", [])),
        },
        "gsc_sitemap": sitemap_submission,
    }
    append_log({
        "type": "seo_brain_full_run",
        "created_at": result["created_at"],
        "updated_count": link_result["updated_count"],
        "recommendations_count": len(audit.get("recommendations", [])),
    })
    return result


def current_state() -> dict:
    env = load_env()
    return {
        "settings": {
            "seo_brain_auto": str(env.get("SEO_BRAIN_AUTO", "true")).lower() != "false",
            "seo_brain_runs_per_day": int(env.get("SEO_BRAIN_RUNS_PER_DAY", "2") or "2"),
            "gsc_site_url": env.get("GSC_SITE_URL", env.get("SITE_BASE_URL", "https://respira-tech.com")),
            "gsc_credentials_set": GSC_CREDENTIALS_FILE.exists(),
            "gsc_service_account_email": google_service_account_email(),
        },
        "audit": load_json(SEO_AUDIT_FILE, {}),
        "logs": load_json(SEO_LOG_FILE, []),
    }


if __name__ == "__main__":
    import argparse

    parser = argparse.ArgumentParser()
    sub = parser.add_subparsers(dest="command", required=True)
    sub.add_parser("audit")
    sub.add_parser("refresh-links")
    sub.add_parser("full-run")
    from_url = sub.add_parser("from-url")
    from_url.add_argument("url")
    from_url.add_argument("--publish", action="store_true")
    args = parser.parse_args()

    if args.command == "audit":
        print(json.dumps(audit_site(), ensure_ascii=False, indent=2))
    elif args.command == "refresh-links":
        print(json.dumps(refresh_article_links(auto_fix=True), ensure_ascii=False, indent=2))
    elif args.command == "full-run":
        print(json.dumps(full_run(), ensure_ascii=False, indent=2))
    elif args.command == "from-url":
        print(json.dumps(build_article_from_url(args.url, publish=args.publish), ensure_ascii=False, indent=2))
