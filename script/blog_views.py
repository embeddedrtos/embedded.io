import json
import os
import tarfile
from datetime import datetime

# === Environment variables ===
LOGS_PATH    = os.environ.get("LOGS_PATH")
STORAGE_PATH = os.environ.get("STORAGE_PATH")

if not LOGS_PATH or not STORAGE_PATH:
    raise EnvironmentError("Missing LOGS_PATH or STORAGE_PATH environment variables")

BLOGS_FILE      = "categories/blogs.json"
BLOG_VIEWS_FILE = os.path.join(STORAGE_PATH, "blog_views.json")

# Dynamic log archive filename — updates automatically each month
LOG_ARCHIVE = datetime.now().strftime("%b-%Y.tar.gz")
ARCHIVE_PATH = os.path.join(LOGS_PATH, LOG_ARCHIVE)


def count_views_in_archive(archive_path: str, blog_slug: str) -> int:
    """Count log lines matching /blogs/?id=<slug> inside a .tar.gz file.
    Uses Python tarfile — no shell, no injection risk."""
    if not os.path.exists(archive_path):
        print(f"[WARN] Log archive not found: {archive_path}")
        return 0

    search_term = f"/blogs/?id={blog_slug}".encode()
    count = 0
    try:
        with tarfile.open(archive_path, "r:gz") as tar:
            for member in tar.getmembers():
                if not member.isfile():
                    continue
                f = tar.extractfile(member)
                if f:
                    for line in f:
                        if search_term in line:
                            count += 1
    except Exception as e:
        print(f"[ERROR] Failed reading archive {archive_path}: {e}")
    return count


# === Load blogs ===
if not os.path.exists(BLOGS_FILE):
    raise FileNotFoundError(f"[ERROR] File not found: {BLOGS_FILE}")

with open(BLOGS_FILE, "r", encoding="utf-8") as f:
    blogs = json.load(f)

# === Load persisted view counts ===
os.makedirs(os.path.dirname(BLOG_VIEWS_FILE), exist_ok=True)
if os.path.exists(BLOG_VIEWS_FILE):
    with open(BLOG_VIEWS_FILE, "r", encoding="utf-8") as f:
        views_save = json.load(f)
else:
    views_save = {}

# === Count views per blog ===
for blog in blogs:
    blog_slug = blog.get("id")
    if not blog_slug:
        continue

    views_today   = count_views_in_archive(ARCHIVE_PATH, blog_slug)
    previous_total = views_save.get(blog_slug, 0)
    new_total      = previous_total + views_today

    views_save[blog_slug] = new_total
    blog["views"] = new_total

# === Persist results ===
with open(BLOGS_FILE, "w", encoding="utf-8") as f:
    json.dump(blogs, f, ensure_ascii=False, indent=2)

with open(BLOG_VIEWS_FILE, "w", encoding="utf-8") as f:
    json.dump(views_save, f, ensure_ascii=False, indent=2)

print(f"[OK] blog_views updated — archive: {LOG_ARCHIVE}")
