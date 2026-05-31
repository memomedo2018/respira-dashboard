from __future__ import annotations

import argparse
import json
import os
import re
import sys
import time
from pathlib import Path
from urllib import request, error

ROOT = Path(__file__).resolve().parents[1]
ARTICLES_DIR = ROOT / "data" / "blog_articles"
ENV_FILES = [ROOT / ".env", ROOT.parent / ".env"]


def load_env() -> dict[str, str]:
    values: dict[str, str] = {}
    for path in ENV_FILES:
        if not path.exists():
            continue
        for line in path.read_text(encoding="utf-8", errors="ignore").splitlines():
            if not line or line.strip().startswith("#") or "=" not in line:
                continue
            key, value = line.split("=", 1)
            values[key.strip()] = value.strip().strip("\"'")
    values.update({key: value for key, value in os.environ.items() if value is not None})
    return values


def strip_markdown(markdown: str) -> str:
    text = re.sub(r"`([^`]+)`", r"\1", markdown or "")
    text = re.sub(r"\[([^\]]+)\]\(([^)]+)\)", r"\1", text)
    text = re.sub(r"^#+\s*", "", text, flags=re.M)
    text = re.sub(r"[*_~>#-]", " ", text)
    return re.sub(r"\s+", " ", text).strip()


def word_count(markdown: str) -> int:
    return len(strip_markdown(markdown).split())


def reading_time(markdown: str) -> int:
    return max(1, round(word_count(markdown) / 180))


def load_articles(include_drafts: bool) -> list[tuple[Path, dict]]:
    articles: list[tuple[Path, dict]] = []
    for path in ARTICLES_DIR.glob("*.json"):
        article = json.loads(path.read_text(encoding="utf-8"))
        if include_drafts or article.get("status") == "published":
            articles.append((path, article))
    articles.sort(key=lambda item: item[1].get("published_at") or item[1].get("updated_at") or "")
    return articles


def openai_json(api_key: str, model: str, article: dict) -> dict:
    markdown = article.get("content_markdown", "")
    links = article.get("internal_links") or []
    link_lines = "\n".join(f"- {item.get('anchor')}: {item.get('url')}" for item in links[:10])
    prompt = f"""أعد تحرير هذا المقال كـ human editorial pass لموقع Respira Tech.

الهدف: رفع جودة القراءة وجعل النص يبدو محررًا بشريًا طبيعيًا ومفيدًا، وليس قالبًا آليًا عامًا.

قواعد مهمة:
- لا تغيّر الحقائق الطبية ولا تضف تشخيصًا أو وصف علاج.
- لا تستخدم عبارات عن "الذكاء الاصطناعي" أو "النص البشري".
- حافظ على H1 بنفس معنى العنوان، واستخدم H2/H3 بترتيب طبيعي.
- اكتب بأسلوب عربي واضح قريب من القارئ المصري والعربي، بدون عامية زائدة.
- أضف تفاصيل عملية من واقع الاستخدام المنزلي: الماسك، التسريب، الجفاف، التنظيف، التعود، التواصل مع المختص.
- احذف الحشو والجمل العامة المتكررة.
- نوّع طول الجمل والفقرات.
- اجعل كل قسم يقدم فائدة واضحة.
- حافظ على روابط داخلية طبيعية داخل السياق، واستخدم anchor text وصفي من القائمة.
- لا تستخدم anchor عام مثل "اضغط هنا" أو "المتجر" أو "تواصل معنا" فقط.
- حافظ على التنويه الطبي في النهاية.
- طول المقال النهائي بين 1500 و2200 كلمة تقريبًا.
- ارجع JSON فقط بالمفاتيح: content_markdown, excerpt, meta_description, faq.

بيانات المقال:
العنوان: {article.get('title_ar')}
التصنيف: {article.get('category')}
الوصف الحالي: {article.get('excerpt')}
التنويه الطبي: {article.get('medical_disclaimer')}

روابط داخلية متاحة:
{link_lines}

المقال الحالي:
{markdown}
"""
    payload = {
        "model": model,
        "temperature": 0.58,
        "max_tokens": 7600,
        "response_format": {"type": "json_object"},
        "messages": [
            {
                "role": "system",
                "content": (
                    "أنت محرر عربي طبي متخصص في SEO ومحتوى CPAP وBiPAP. "
                    "مهمتك تحسين النص تحريريًا للقارئ مع الالتزام بالسلامة الطبية."
                ),
            },
            {"role": "user", "content": prompt},
        ],
    }
    req = request.Request(
        "https://api.openai.com/v1/chat/completions",
        data=json.dumps(payload).encode("utf-8"),
        headers={
            "Content-Type": "application/json",
            "Authorization": f"Bearer {api_key}",
        },
        method="POST",
    )
    try:
        with request.urlopen(req, timeout=180) as response:
            raw = json.loads(response.read().decode("utf-8"))
    except error.HTTPError as exc:
        body = exc.read().decode("utf-8", errors="ignore")
        raise RuntimeError(f"OpenAI HTTP {exc.code}: {body[:800]}") from exc
    content = raw["choices"][0]["message"]["content"]
    return json.loads(content)


def apply_result(article: dict, result: dict) -> dict:
    markdown = str(result.get("content_markdown") or "").strip()
    if word_count(markdown) < 1200:
        raise ValueError(f"humanized article too short: {word_count(markdown)} words")
    updated = dict(article)
    updated["content_markdown"] = markdown
    if result.get("excerpt"):
        updated["excerpt"] = str(result["excerpt"]).strip()[:220]
    if result.get("meta_description"):
        updated["meta_description"] = str(result["meta_description"]).strip()[:160]
    if isinstance(result.get("faq"), list) and result["faq"]:
        updated["faq"] = result["faq"][:6]
    updated["reading_time"] = reading_time(markdown)
    updated["updated_at"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
    return updated


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--include-drafts", action="store_true")
    parser.add_argument("--slug", action="append", default=[])
    parser.add_argument("--limit", type=int, default=0)
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args()

    env = load_env()
    api_key = env.get("OPENAI_API_KEY")
    if not api_key:
        print("missing OPENAI_API_KEY", file=sys.stderr)
        return 1
    model = env.get("OPENAI_HUMANIZER_MODEL") or env.get("OPENAI_TEXT_MODEL") or "gpt-4.1"
    selected_slugs = set(args.slug)
    articles = [
        (path, article)
        for path, article in load_articles(args.include_drafts)
        if not selected_slugs or article.get("slug") in selected_slugs
    ]
    if args.limit:
        articles = articles[: args.limit]
    print(f"humanizing {len(articles)} article(s) with {model}")
    for index, (path, article) in enumerate(articles, 1):
        before = word_count(article.get("content_markdown", ""))
        slug = article.get("slug")
        print(f"[{index}/{len(articles)}] {slug} before_words={before}", flush=True)
        result = openai_json(api_key, model, article)
        updated = apply_result(article, result)
        after = word_count(updated.get("content_markdown", ""))
        if not args.dry_run:
            path.write_text(json.dumps(updated, ensure_ascii=False, indent=2), encoding="utf-8")
        print(f"saved={not args.dry_run} {slug} after_words={after}", flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
