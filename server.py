from __future__ import annotations

import base64
import json
import os
import re
import shutil
import subprocess
import requests
from datetime import datetime
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, quote, unquote, urlparse

import seo_brain

BASE_DIR = Path(__file__).resolve().parent
STORE_FILE = BASE_DIR / "data" / "store.json"
SITE_FILE = BASE_DIR / "data" / "site.json"
BLOG_DIR = BASE_DIR / "data" / "blog_articles"
BLOG_LOG_FILE = BASE_DIR / "data" / "blog_generation_log.json"
IMAGES_DIR = BASE_DIR / "assets" / "images" / "store"
BLOG_IMAGES_DIR = BASE_DIR / "assets" / "images" / "blog"
ENV_FILE = BASE_DIR / ".env"
BUILD_SCRIPT = BASE_DIR / "build_content.py"
BLOG_GENERATOR = BASE_DIR / "generateDailyBlog.js"
GSC_CREDENTIALS_FILE = BASE_DIR / "data" / "gsc-service-account.json"
SEO_AUDIT_FILE = BASE_DIR / "data" / "seo_audit.json"
SEO_BRAIN_LOG_FILE = BASE_DIR / "data" / "seo_brain_log.json"
ACTIVITY_LOG_FILE = BASE_DIR / "data" / "dashboard_activity_log.json"
PRODUCT_SCHEMA_FILE = BASE_DIR / "data" / "product-schema.json"
AI_CATALOG_FILE = BASE_DIR / "data" / "ai-catalog.json"
SITEMAP_FILE = BASE_DIR / "sitemap.xml"
ROBOTS_FILE = BASE_DIR / "robots.txt"
LLMS_FILE = BASE_DIR / "llms.txt"
SYNC_PATHS = [
    STORE_FILE,
    SITE_FILE,
    BLOG_DIR,
    BLOG_LOG_FILE,
    SEO_AUDIT_FILE,
    SEO_BRAIN_LOG_FILE,
    PRODUCT_SCHEMA_FILE,
    AI_CATALOG_FILE,
    BASE_DIR / "blog",
    BASE_DIR / "services",
    BASE_DIR / "store",
    BASE_DIR / "about",
    BASE_DIR / "contact",
    BASE_DIR / "privacy-policy",
    BASE_DIR / "refund-policy",
    BASE_DIR / "terms",
    BASE_DIR / "admin" / "blog" / "index.html",
    SITEMAP_FILE,
    ROBOTS_FILE,
    LLMS_FILE,
]


def slugify(value: str) -> str:
    value = value.strip().lower()
    value = re.sub(r"[^a-z0-9\u0600-\u06ff._-]+", "-", value)
    value = re.sub(r"-{2,}", "-", value).strip("-")
    return value or f"item-{datetime.now().strftime('%Y%m%d%H%M%S')}"


def load_json(path: Path, default: dict | list | None = None) -> dict | list:
    if not path.exists():
        return default if default is not None else {}
    with path.open("r", encoding="utf-8") as file:
        return json.load(file)


def save_json(path: Path, payload: dict | list) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8") as file:
        json.dump(payload, file, ensure_ascii=False, indent=2)


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


def save_env(updates: dict[str, str]) -> None:
    current = {}
    if ENV_FILE.exists():
        for line in ENV_FILE.read_text(encoding="utf-8").splitlines():
            if not line.strip() or line.strip().startswith("#") or "=" not in line:
                continue
            key, value = line.split("=", 1)
            current[key.strip()] = value.strip()
    current.update({key: value for key, value in updates.items() if value is not None})
    lines = [f"{key}={value}" for key, value in sorted(current.items())]
    ENV_FILE.write_text("\n".join(lines) + "\n", encoding="utf-8")


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
        len(strip_markdown(markdown).split()) >= 1200,
    ]
    return round(sum(100 / len(checks) for item in checks if item))


def blog_file_for_slug(slug: str) -> Path:
    return BLOG_DIR / f"{slug}.json"


def generated_blog_dir(slug: str) -> Path:
    return BASE_DIR / "blog" / slug


def load_articles() -> list[dict]:
    BLOG_DIR.mkdir(parents=True, exist_ok=True)
    articles: list[dict] = []
    for path in sorted(BLOG_DIR.glob("*.json")):
        article = load_json(path, {})
        if not isinstance(article, dict):
            continue
        article["source_file"] = str(path.relative_to(BASE_DIR))
        article["reading_time"] = article.get("reading_time") or estimate_reading_time(article.get("content_markdown", ""))
        article["seo_score"] = seo_score(article)
        articles.append(article)
    articles.sort(key=lambda item: item.get("published_at") or item.get("created_at") or "", reverse=True)
    return articles


def run_build() -> None:
    subprocess.run(["python3", str(BUILD_SCRIPT)], cwd=BASE_DIR, check=True)


def run_blog_generator(extra_env: dict[str, str] | None = None) -> None:
    env = os.environ.copy()
    if extra_env:
        env.update(extra_env)
    subprocess.run(["node", str(BLOG_GENERATOR)], cwd=BASE_DIR, env=env, check=True)


def read_blog_logs(limit: int = 20) -> list[dict]:
    logs = load_json(BLOG_LOG_FILE, [])
    if not isinstance(logs, list):
        return []
    return logs[:limit]


def read_activity_logs(limit: int = 100) -> list[dict]:
    logs = load_json(ACTIVITY_LOG_FILE, [])
    if not isinstance(logs, list):
        return []
    return logs[:limit]


def append_activity_log(action: str, status: str = "success", **details) -> dict:
    logs = load_json(ACTIVITY_LOG_FILE, [])
    if not isinstance(logs, list):
        logs = []
    entry = {
        "action": action,
        "status": status,
        "created_at": datetime.utcnow().isoformat(),
    }
    for key, value in details.items():
        if value in (None, "", [], {}):
            continue
        entry[key] = value
    logs.insert(0, entry)
    save_json(ACTIVITY_LOG_FILE, logs[:200])
    return entry


def repo_relative(path: Path) -> str:
    return str(path.relative_to(BASE_DIR))


def git_sync_enabled(env: dict[str, str]) -> bool:
    return (
        str(env.get("AUTO_PUSH_CHANGES", "true")).lower() != "false"
        and bool(env.get("GITHUB_TOKEN"))
        and bool(env.get("GITHUB_REPO"))
    )


def collect_sync_targets(paths: list[Path]) -> tuple[dict[str, Path], set[str], set[str]]:
    files: dict[str, Path] = {}
    managed_dirs: set[str] = set()
    managed_files: set[str] = set()

    for path in paths:
        relative = repo_relative(path)
        if path.exists():
            if path.is_file():
                files[relative] = path
                managed_files.add(relative)
                continue
            if path.is_dir():
                managed_dirs.add(f"{relative.rstrip('/')}/")
                for child in path.rglob("*"):
                    if child.is_file():
                        files[repo_relative(child)] = child
                continue

        # Missing explicit files are still managed so they can be deleted remotely.
        managed_files.add(relative)

    return files, managed_dirs, managed_files


def github_api_request(method: str, url: str, token: str, payload: dict | None = None) -> requests.Response:
    headers = {
        "Authorization": f"Bearer {token}",
        "Accept": "application/vnd.github+json",
        "X-GitHub-Api-Version": "2022-11-28",
    }
    response = requests.request(method, url, headers=headers, json=payload, timeout=60)
    return response


def github_get_branch_tree(repo: str, branch: str, token: str) -> dict[str, str]:
    branch_url = f"https://api.github.com/repos/{repo}/branches/{quote(branch, safe='')}"
    branch_response = github_api_request("GET", branch_url, token)
    if branch_response.status_code >= 400:
        raise RuntimeError(f"GitHub branch lookup failed: {branch_response.text}")

    branch_data = branch_response.json()
    tree_sha = (((branch_data.get("commit") or {}).get("commit") or {}).get("tree") or {}).get("sha")
    if not tree_sha:
        return {}

    tree_url = f"https://api.github.com/repos/{repo}/git/trees/{tree_sha}?recursive=1"
    tree_response = github_api_request("GET", tree_url, token)
    if tree_response.status_code >= 400:
        raise RuntimeError(f"GitHub tree lookup failed: {tree_response.text}")

    tree = {}
    for item in (tree_response.json().get("tree") or []):
        if item.get("type") == "blob" and item.get("path") and item.get("sha"):
            tree[item["path"]] = item["sha"]
    return tree


def github_get_file(repo: str, branch: str, relative: str, token: str) -> dict | None:
    url = f"https://api.github.com/repos/{repo}/contents/{quote(relative, safe='/')}?ref={quote(branch, safe='')}"
    response = github_api_request("GET", url, token)
    if response.status_code == 404:
        return None
    if response.status_code >= 400:
        raise RuntimeError(f"GitHub file lookup failed for {relative}: {response.text}")

    payload = response.json()
    encoded = payload.get("content") or ""
    decoded = base64.b64decode(encoded) if encoded else b""
    return {
        "sha": payload.get("sha"),
        "content": decoded,
    }


def github_put_file(
    repo: str,
    branch: str,
    relative: str,
    local_path: Path,
    token: str,
    author_name: str,
    author_email: str,
    commit_message: str,
) -> str:
    existing = github_get_file(repo, branch, relative, token)
    local_bytes = local_path.read_bytes()
    if existing and existing.get("content") == local_bytes:
        return "unchanged"

    payload = {
        "message": f"{commit_message}: {relative}",
        "content": base64.b64encode(local_bytes).decode("ascii"),
        "branch": branch,
        "committer": {"name": author_name, "email": author_email},
    }
    if existing and existing.get("sha"):
        payload["sha"] = existing["sha"]

    url = f"https://api.github.com/repos/{repo}/contents/{quote(relative, safe='/')}"
    response = github_api_request("PUT", url, token, payload)
    if response.status_code >= 400:
        raise RuntimeError(f"GitHub update failed for {relative}: {response.text}")
    return "updated" if existing else "created"


def github_delete_file(
    repo: str,
    branch: str,
    relative: str,
    sha: str,
    token: str,
    author_name: str,
    author_email: str,
    commit_message: str,
) -> None:
    payload = {
        "message": f"{commit_message}: delete {relative}",
        "sha": sha,
        "branch": branch,
        "committer": {"name": author_name, "email": author_email},
    }
    url = f"https://api.github.com/repos/{repo}/contents/{quote(relative, safe='/')}"
    response = github_api_request("DELETE", url, token, payload)
    if response.status_code >= 400:
        raise RuntimeError(f"GitHub delete failed for {relative}: {response.text}")


def sync_changes_via_github_api(
    commit_message: str,
    stage_paths: list[Path],
    repo: str,
    branch: str,
    token: str,
    author_name: str,
    author_email: str,
) -> dict:
    local_files, managed_dirs, managed_files = collect_sync_targets(stage_paths)
    remote_tree = github_get_branch_tree(repo, branch, token)

    created = 0
    updated = 0
    deleted = 0

    for relative, local_path in sorted(local_files.items()):
        result = github_put_file(repo, branch, relative, local_path, token, author_name, author_email, commit_message)
        if result == "created":
            created += 1
        elif result == "updated":
            updated += 1

    remote_managed = {
        relative: sha
        for relative, sha in remote_tree.items()
        if relative in managed_files or any(relative.startswith(prefix) for prefix in managed_dirs)
    }
    local_paths = set(local_files)
    for relative, sha in sorted(remote_managed.items()):
        if relative in local_paths:
            continue
        github_delete_file(repo, branch, relative, sha, token, author_name, author_email, commit_message)
        deleted += 1

    if created == 0 and updated == 0 and deleted == 0:
        return {"ok": True, "skipped": True, "reason": "no synced changes"}
    return {
        "ok": True,
        "pushed": True,
        "branch": branch,
        "mode": "github_api",
        "created": created,
        "updated": updated,
        "deleted": deleted,
    }


def sync_changes_to_github(commit_message: str, extra_paths: list[Path] | None = None) -> dict:
    env = load_env()
    if not git_sync_enabled(env):
        return {"ok": False, "skipped": True, "reason": "git sync not configured"}

    token = env["GITHUB_TOKEN"].strip()
    repo = env["GITHUB_REPO"].strip()
    branch = env.get("GITHUB_BRANCH", "main").strip() or "main"
    author_name = env.get("GIT_AUTHOR_NAME", "Respira Tech Dashboard").strip() or "Respira Tech Dashboard"
    author_email = env.get("GIT_AUTHOR_EMAIL", "noreply@respira-tech.com").strip() or "noreply@respira-tech.com"

    stage_paths = SYNC_PATHS + (extra_paths or [])
    unique_paths: list[str] = []
    seen: set[str] = set()
    for path in stage_paths:
        relative = repo_relative(path)
        if relative not in seen:
            seen.add(relative)
            unique_paths.append(relative)

    if not shutil.which("git"):
        try:
            return sync_changes_via_github_api(
                commit_message=commit_message,
                stage_paths=stage_paths,
                repo=repo,
                branch=branch,
                token=token,
                author_name=author_name,
                author_email=author_email,
            )
        except Exception as exc:
            return {"ok": False, "error": str(exc)}

    git_env = os.environ.copy()
    git_env.update({
        "GIT_AUTHOR_NAME": author_name,
        "GIT_AUTHOR_EMAIL": author_email,
        "GIT_COMMITTER_NAME": author_name,
        "GIT_COMMITTER_EMAIL": author_email,
        "GIT_TERMINAL_PROMPT": "0",
    })

    def run_git(args: list[str]) -> subprocess.CompletedProcess[str]:
        result = subprocess.run(
            args,
            cwd=BASE_DIR,
            env=git_env,
            text=True,
            capture_output=True,
        )
        if result.returncode != 0:
            combined = "\n".join(part for part in [result.stdout, result.stderr] if part).strip()
            sanitized = combined.replace(token, "***")
            raise RuntimeError(sanitized or f"git command failed: {' '.join(args[:2])}")
        return result

    try:
        run_git(["git", "add", "-A", "--", *unique_paths])
        diff = subprocess.run(
            ["git", "diff", "--cached", "--quiet"],
            cwd=BASE_DIR,
            env=git_env,
        )
        if diff.returncode == 0:
            return {"ok": True, "skipped": True, "reason": "no staged changes"}

        run_git(["git", "commit", "-m", commit_message])
        push_url = f"https://x-access-token:{token}@github.com/{repo}.git"
        run_git(["git", "push", push_url, f"HEAD:{branch}"])
        return {"ok": True, "pushed": True, "branch": branch}
    except Exception as exc:
        return {"ok": False, "error": str(exc)}


def dashboard_config() -> dict:
    env = load_env()
    site_data = load_json(SITE_FILE, {})
    site = site_data.get("site", {})
    store_data = load_json(STORE_FILE, {"config": {}})
    store_config = store_data.get("config", {}) if isinstance(store_data, dict) else {}
    return {
        "settings": {
            "openai_api_key_set": bool(env.get("OPENAI_API_KEY")),
            "openai_api_key_masked": "********" if env.get("OPENAI_API_KEY") else "",
            "auto_publish_blogs": str(env.get("AUTO_PUBLISH_BLOGS", "false")).lower() == "true",
            "daily_blog_posts": int(env.get("DAILY_BLOG_POSTS", "2") or "2"),
            "generate_blog_images": str(env.get("GENERATE_BLOG_IMAGES", "true")).lower() != "false",
            "openai_text_model": env.get("OPENAI_TEXT_MODEL", "gpt-4.1"),
            "openai_image_model": env.get("OPENAI_IMAGE_MODEL", "dall-e-3"),
            "whatsapp_number": env.get("WHATSAPP_NUMBER", site.get("whatsapp_number", store_config.get("whatsapp_phone", "201012566955"))),
            "site_base_url": env.get("SITE_BASE_URL", site.get("base_url", "https://respira-tech.com")),
            "admin_password_set": bool(env.get("ADMIN_PASSWORD")),
            "cron_secret_set": bool(env.get("CRON_SECRET")),
            "blog_publish_time": "09:00 Africa/Cairo",
            "seo_brain_auto": str(env.get("SEO_BRAIN_AUTO", "true")).lower() != "false",
            "seo_brain_runs_per_day": int(env.get("SEO_BRAIN_RUNS_PER_DAY", "2") or "2"),
            "gsc_site_url": env.get("GSC_SITE_URL", env.get("SITE_BASE_URL", site.get("base_url", "https://respira-tech.com"))),
            "gsc_credentials_set": GSC_CREDENTIALS_FILE.exists(),
            "auto_push_changes": str(env.get("AUTO_PUSH_CHANGES", "true")).lower() != "false",
            "github_repo": env.get("GITHUB_REPO", ""),
            "github_branch": env.get("GITHUB_BRANCH", "main"),
            "github_sync_configured": git_sync_enabled(env),
        },
        "logs": read_blog_logs(),
        "articles": load_articles(),
        "seo_brain": seo_brain.current_state(),
        "activity_logs": read_activity_logs(),
    }


def normalize_article(payload: dict, existing: dict | None = None) -> dict:
    site_data = load_json(SITE_FILE, {})
    site = site_data.get("site", {})
    now_iso = datetime.utcnow().isoformat()
    article = {**(existing or {}), **payload}
    article["slug"] = slugify(article.get("slug") or article.get("title_ar", ""))
    article["id"] = article.get("id") or article["slug"]
    article["author"] = article.get("author") or site.get("author", "فريق Respira Tech")
    article["featured_image"] = article.get("featured_image") or site.get("default_image", "/assets/images/store/respira-tech-logo.png")
    article["featured_image_prompt"] = article.get(
        "featured_image_prompt",
        "Clean white medical website image, CPAP/BiPAP respiratory therapy, modern bedroom, soft daylight, premium healthcare, no text, no logos, realistic.",
    )
    article["tags"] = article.get("tags") or []
    article["faq"] = article.get("faq") or []
    article["internal_links"] = article.get("internal_links") or site_data.get("core_links", [])[:5]
    article["cta_text"] = article.get("cta_text") or "فريق Respira Tech يساعدك في فهم احتياجك واختيار جهاز CPAP أو BiPAP أو الماسك المناسب حسب حالتك وتوصية الطبيب."
    article["cta_button_text"] = article.get("cta_button_text") or "تواصل معنا عبر واتساب"
    whatsapp_number = load_env().get("WHATSAPP_NUMBER") or site.get("whatsapp_number", "201012566955")
    article["cta_button_url"] = article.get("cta_button_url") or f"https://wa.me/{whatsapp_number}"
    article["medical_disclaimer"] = article.get("medical_disclaimer") or site.get("medical_disclaimer", "هذا المحتوى للتثقيف فقط ولا يغني عن استشارة الطبيب أو المختص.")
    article["status"] = article.get("status") or "draft"
    article["created_at"] = article.get("created_at") or now_iso
    article["updated_at"] = now_iso
    if article["status"] == "published":
        article["published_at"] = article.get("published_at") or now_iso
    elif not article.get("published_at"):
        article["published_at"] = None
    article["reading_time"] = estimate_reading_time(article.get("content_markdown", ""))
    article["seo_score"] = seo_score(article)
    return article


class StoreHandler(SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=str(BASE_DIR), **kwargs)

    def _send_json(self, payload: dict | list, status: int = 200) -> None:
        body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _read_json_body(self) -> dict:
        length = int(self.headers.get("Content-Length", "0"))
        raw = self.rfile.read(length)
        try:
            payload = json.loads(raw.decode("utf-8") or "{}")
        except json.JSONDecodeError as exc:
            raise ValueError("invalid JSON") from exc
        if not isinstance(payload, dict):
            raise ValueError("invalid JSON payload")
        return payload

    def _admin_authorized(self) -> bool:
        env = load_env()
        expected = env.get("ADMIN_PASSWORD", "")
        if not expected:
            return True
        return self.headers.get("X-Admin-Password", "") == expected

    def _cron_authorized(self) -> bool:
        env = load_env()
        expected = env.get("CRON_SECRET", "")
        if not expected:
            return False
        bearer = self.headers.get("Authorization", "")
        direct = self.headers.get("X-Cron-Secret", "")
        return direct == expected or bearer == f"Bearer {expected}"

    def _ensure_admin(self) -> bool:
        if self._admin_authorized():
            return True
        self._send_json({"error": "unauthorized"}, 401)
        return False

    def _save_article(self, payload: dict) -> dict:
        requested_slug = slugify(payload.get("slug") or payload.get("title_ar", ""))
        current_slug = slugify(payload.get("current_slug") or requested_slug)
        existing_path = blog_file_for_slug(current_slug)
        existing = load_json(existing_path, {}) if existing_path.exists() else {}
        article = normalize_article(payload, existing if isinstance(existing, dict) else None)

        if current_slug != article["slug"] and existing_path.exists():
            existing_path.unlink()
            old_generated = generated_blog_dir(current_slug)
            if old_generated.exists():
                shutil.rmtree(old_generated)

        save_json(blog_file_for_slug(article["slug"]), article)
        run_build()
        return article

    def do_GET(self) -> None:
        parsed = urlparse(self.path)

        if parsed.path == "/api/store":
            payload = load_json(STORE_FILE, {"products": [], "categories": []})
            if isinstance(payload, dict):
                payload.setdefault("config", {})
                if isinstance(payload["config"], dict):
                    env = load_env()
                    payload["config"]["whatsapp_phone"] = env.get("WHATSAPP_NUMBER", payload["config"].get("whatsapp_phone", "201012566955"))
            return self._send_json(payload)

        if parsed.path == "/api/health":
            return self._send_json({"ok": True})

        if parsed.path == "/api/blog":
            if not self._ensure_admin():
                return
            query = parse_qs(parsed.query)
            slug = query.get("slug", [""])[0]
            articles = load_articles()
            if slug:
                article = next((item for item in articles if item.get("slug") == slug), None)
                if not article:
                    return self._send_json({"error": "article not found"}, 404)
                return self._send_json({"article": article})
            return self._send_json({"articles": articles})

        if parsed.path == "/api/dashboard/config":
            if not self._ensure_admin():
                return
            return self._send_json(dashboard_config())

        if parsed.path == "/api/blog/logs":
            if not self._ensure_admin():
                return
            return self._send_json({"logs": read_blog_logs()})

        if parsed.path == "/api/seo/brain":
            if not self._ensure_admin():
                return
            return self._send_json(seo_brain.current_state())

        return super().do_GET()

    def do_POST(self) -> None:
        parsed = urlparse(self.path)

        if parsed.path == "/api/store/save":
            try:
                payload = self._read_json_body()
            except ValueError as exc:
                return self._send_json({"error": str(exc)}, 400)
            save_json(STORE_FILE, payload)
            run_build()
            sync = sync_changes_to_github(f"Update store content {datetime.utcnow().isoformat()}")
            append_activity_log(
                "store_save",
                products_count=len(payload.get("products", [])) if isinstance(payload, dict) else None,
                categories_count=len(payload.get("categories", [])) if isinstance(payload, dict) else None,
                sync=sync,
            )
            return self._send_json({"ok": True, "sync": sync})

        if parsed.path == "/api/upload":
            try:
                payload = self._read_json_body()
            except ValueError as exc:
                return self._send_json({"error": str(exc)}, 400)

            files = payload.get("files", [])
            if not isinstance(files, list) or not files:
                return self._send_json({"error": "no files provided"}, 400)

            saved_files = []
            IMAGES_DIR.mkdir(parents=True, exist_ok=True)
            for item in files:
                filename = item.get("filename", "")
                content = item.get("content", "")
                if not filename or not content.startswith("data:"):
                    continue
                ext = Path(filename).suffix.lower() or ".bin"
                safe_name = f"{slugify(Path(filename).stem)}-{datetime.now().strftime('%Y%m%d%H%M%S%f')}{ext}"
                target = IMAGES_DIR / safe_name
                _, encoded = content.split(",", 1)
                binary = base64.b64decode(encoded)
                with target.open("wb") as output:
                    output.write(binary)
                saved_files.append({"filename": safe_name, "url": f"/assets/images/store/{safe_name}"})

            return self._send_json({"files": saved_files})

        if parsed.path == "/api/blog/save":
            if not self._ensure_admin():
                return
            try:
                payload = self._read_json_body()
                article = self._save_article(payload)
            except ValueError as exc:
                return self._send_json({"error": str(exc)}, 400)
            except subprocess.CalledProcessError as exc:
                return self._send_json({"error": f"build failed: {exc}"}, 500)
            sync = sync_changes_to_github(f"Save article {article['slug']} {datetime.utcnow().isoformat()}")
            append_activity_log("article_save", slug=article["slug"], article_status=article.get("status"), sync=sync)
            return self._send_json({"ok": True, "article": article, "sync": sync})

        if parsed.path == "/api/blog/toggle-status":
            if not self._ensure_admin():
                return
            try:
                payload = self._read_json_body()
            except ValueError as exc:
                return self._send_json({"error": str(exc)}, 400)
            slug = slugify(payload.get("slug", ""))
            path = blog_file_for_slug(slug)
            if not path.exists():
                return self._send_json({"error": "article not found"}, 404)
            article = load_json(path, {})
            if not isinstance(article, dict):
                return self._send_json({"error": "article not found"}, 404)
            article["status"] = "published" if article.get("status") != "published" else "draft"
            article["updated_at"] = datetime.utcnow().isoformat()
            if article["status"] == "published":
                article["published_at"] = article.get("published_at") or article["updated_at"]
            save_json(path, article)
            if article["status"] != "published":
                old_generated = generated_blog_dir(slug)
                if old_generated.exists():
                    shutil.rmtree(old_generated)
            try:
                run_build()
            except subprocess.CalledProcessError as exc:
                return self._send_json({"error": f"build failed: {exc}"}, 500)
            sync = sync_changes_to_github(f"Toggle article status {slug} {datetime.utcnow().isoformat()}")
            append_activity_log("article_toggle_status", slug=slug, article_status=article.get("status"), sync=sync)
            return self._send_json({"ok": True, "article": normalize_article(article, article), "sync": sync})

        if parsed.path == "/api/blog/delete":
            if not self._ensure_admin():
                return
            try:
                payload = self._read_json_body()
            except ValueError as exc:
                return self._send_json({"error": str(exc)}, 400)
            slug = slugify(payload.get("slug", ""))
            path = blog_file_for_slug(slug)
            if not path.exists():
                return self._send_json({"error": "article not found"}, 404)
            path.unlink()
            old_generated = generated_blog_dir(slug)
            if old_generated.exists():
                shutil.rmtree(old_generated)
            try:
                run_build()
            except subprocess.CalledProcessError as exc:
                return self._send_json({"error": f"build failed: {exc}"}, 500)
            sync = sync_changes_to_github(f"Delete article {slug} {datetime.utcnow().isoformat()}")
            append_activity_log("article_delete", slug=slug, sync=sync)
            return self._send_json({"ok": True, "sync": sync})

        if parsed.path == "/api/blog/generate":
            if not self._ensure_admin():
                return
            try:
                payload = self._read_json_body()
                count = max(1, min(5, int(payload.get("count", 1) or 1)))
                extra_env = {"DAILY_BLOG_POSTS": str(count), "FORCE_GENERATE": "true"}
                if payload.get("publish_now") is True:
                    extra_env["FORCE_PUBLISH"] = "true"
                elif payload.get("publish_now") is False:
                    extra_env["FORCE_PUBLISH"] = "false"
                run_blog_generator(extra_env)
            except subprocess.CalledProcessError as exc:
                return self._send_json({"error": f"generation failed: {exc}"}, 500)
            except ValueError as exc:
                return self._send_json({"error": str(exc)}, 400)
            sync = sync_changes_to_github(f"Generate blog batch {datetime.utcnow().isoformat()}")
            append_activity_log(
                "blog_generate_batch",
                generated_count=count,
                publish_now=payload.get("publish_now"),
                sync=sync,
            )
            return self._send_json({"ok": True, "sync": sync})

        if parsed.path == "/api/seo/gsc/upload":
            if not self._ensure_admin():
                return
            try:
                payload = self._read_json_body()
            except ValueError as exc:
                return self._send_json({"error": str(exc)}, 400)
            content = str(payload.get("content") or "").strip()
            if not content:
                return self._send_json({"error": "empty credentials"}, 400)
            try:
                parsed_json = json.loads(content)
            except json.JSONDecodeError as exc:
                return self._send_json({"error": f"invalid JSON: {exc}"}, 400)
            save_json(GSC_CREDENTIALS_FILE, parsed_json)
            append_activity_log("gsc_credentials_upload")
            return self._send_json({"ok": True})

        if parsed.path == "/api/seo/brain":
            if not self._ensure_admin():
                return
            try:
                payload = self._read_json_body()
            except ValueError as exc:
                return self._send_json({"error": str(exc)}, 400)
            action = str(payload.get("action") or "").strip()
            try:
                if action == "audit":
                    result = seo_brain.audit_site()
                elif action == "refresh_links":
                    result = seo_brain.refresh_article_links(auto_fix=True)
                elif action == "full_run":
                    result = seo_brain.full_run()
                elif action == "from_url":
                    source_url = str(payload.get("url") or "").strip()
                    if not source_url:
                        return self._send_json({"error": "missing url"}, 400)
                    result = seo_brain.build_article_from_url(source_url, publish=bool(payload.get("publish_now")))
                else:
                    return self._send_json({"error": "unknown action"}, 400)
            except requests.RequestException as exc:
                return self._send_json({"error": f"network error: {exc}"}, 500)
            except subprocess.CalledProcessError as exc:
                return self._send_json({"error": f"build failed: {exc}"}, 500)
            except Exception as exc:
                return self._send_json({"error": str(exc)}, 500)
            sync = sync_changes_to_github(f"Run SEO brain action {action} {datetime.utcnow().isoformat()}")
            append_activity_log(
                f"seo_{action}",
                slug=result.get("slug") if isinstance(result, dict) else None,
                updated_count=result.get("updated_count") if isinstance(result, dict) else None,
                recommendations_count=result.get("recommendations_count") if isinstance(result, dict) else None,
                sync=sync,
            )
            return self._send_json({"ok": True, "result": result, "state": seo_brain.current_state(), "sync": sync})

        if parsed.path == "/api/dashboard/config":
            if not self._ensure_admin():
                return
            try:
                payload = self._read_json_body()
            except ValueError as exc:
                return self._send_json({"error": str(exc)}, 400)

            updates = {
                "AUTO_PUBLISH_BLOGS": "true" if payload.get("auto_publish_blogs") else "false",
                "DAILY_BLOG_POSTS": str(payload.get("daily_blog_posts") or 2),
                "GENERATE_BLOG_IMAGES": "true" if payload.get("generate_blog_images", True) else "false",
                "OPENAI_TEXT_MODEL": str(payload.get("openai_text_model") or "gpt-4.1").strip(),
                "OPENAI_IMAGE_MODEL": str(payload.get("openai_image_model") or "dall-e-3").strip(),
                "WHATSAPP_NUMBER": str(payload.get("whatsapp_number") or "201012566955").strip(),
                "SITE_BASE_URL": str(payload.get("site_base_url") or "https://respira-tech.com").strip(),
                "SEO_BRAIN_AUTO": "true" if payload.get("seo_brain_auto", True) else "false",
                "SEO_BRAIN_RUNS_PER_DAY": str(payload.get("seo_brain_runs_per_day") or 2),
                "GSC_SITE_URL": str(payload.get("gsc_site_url") or payload.get("site_base_url") or "https://respira-tech.com").strip(),
                "AUTO_PUSH_CHANGES": "true" if payload.get("auto_push_changes", True) else "false",
                "GITHUB_REPO": str(payload.get("github_repo") or "").strip(),
                "GITHUB_BRANCH": str(payload.get("github_branch") or "main").strip() or "main",
            }
            api_key = str(payload.get("openai_api_key") or "").strip()
            if api_key and api_key != "********":
                updates["OPENAI_API_KEY"] = api_key
            admin_password = str(payload.get("admin_password") or "").strip()
            if admin_password:
                updates["ADMIN_PASSWORD"] = admin_password
            cron_secret = str(payload.get("cron_secret") or "").strip()
            if cron_secret:
                updates["CRON_SECRET"] = cron_secret
            github_token = str(payload.get("github_token") or "").strip()
            if github_token:
                updates["GITHUB_TOKEN"] = github_token
            save_env(updates)

            site_data = load_json(SITE_FILE, {})
            if isinstance(site_data, dict):
                site_data.setdefault("site", {})
                site_data["site"]["whatsapp_number"] = updates["WHATSAPP_NUMBER"]
                site_data["site"]["base_url"] = updates["SITE_BASE_URL"]
                save_json(SITE_FILE, site_data)

            store_data = load_json(STORE_FILE, {"config": {}, "categories": [], "products": []})
            if isinstance(store_data, dict):
                store_data.setdefault("config", {})
                if isinstance(store_data["config"], dict):
                    store_data["config"]["whatsapp_phone"] = updates["WHATSAPP_NUMBER"]
                save_json(STORE_FILE, store_data)

            try:
                run_build()
            except subprocess.CalledProcessError as exc:
                return self._send_json({"error": f"build failed: {exc}"}, 500)
            sync = sync_changes_to_github(f"Update dashboard settings {datetime.utcnow().isoformat()}")
            append_activity_log("dashboard_settings_update", sync=sync)
            return self._send_json({"ok": True, "config": dashboard_config(), "sync": sync})

        if parsed.path == "/api/build":
            if not self._ensure_admin():
                return
            try:
                run_build()
            except subprocess.CalledProcessError as exc:
                return self._send_json({"error": f"build failed: {exc}"}, 500)
            sync = sync_changes_to_github(f"Manual rebuild {datetime.utcnow().isoformat()}")
            append_activity_log("manual_rebuild", sync=sync)
            return self._send_json({"ok": True, "sync": sync})

        if parsed.path == "/api/cron/generate-blog":
            if not self._cron_authorized():
                return self._send_json({"error": "unauthorized"}, 401)
            try:
                run_blog_generator()
            except subprocess.CalledProcessError as exc:
                return self._send_json({"error": f"generation failed: {exc}"}, 500)
            sync = sync_changes_to_github(f"Cron generate blog {datetime.utcnow().isoformat()}")
            append_activity_log("cron_generate_blog", sync=sync)
            return self._send_json({"ok": True, "sync": sync})

        if parsed.path == "/api/cron/seo-brain":
            if not self._cron_authorized():
                return self._send_json({"error": "unauthorized"}, 401)
            try:
                result = seo_brain.full_run()
            except Exception as exc:
                return self._send_json({"error": str(exc)}, 500)
            sync = sync_changes_to_github(f"Cron SEO brain {datetime.utcnow().isoformat()}")
            append_activity_log(
                "cron_seo_brain",
                updated_count=result.get("updated_count") if isinstance(result, dict) else None,
                recommendations_count=result.get("recommendations_count") if isinstance(result, dict) else None,
                sync=sync,
            )
            return self._send_json({"ok": True, "result": result, "sync": sync})

        return self._send_json({"error": "not found"}, 404)


def main() -> None:
    port = int(os.environ.get("PORT", "8000"))
    host = os.environ.get("HOST", "0.0.0.0")
    server = ThreadingHTTPServer((host, port), StoreHandler)
    print(f"Respira Tech server running on http://{host}:{port}")
    server.serve_forever()


if __name__ == "__main__":
    main()
