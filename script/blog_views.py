import json
import subprocess
import os

# === Load environment variables ===
LOGS_PATH = os.environ.get("LOGS_PATH")

if not LOGS_PATH:
    raise EnvironmentError("❌ Missing BLOG_VIEWS or LOGS_PATH environment variables")

# === File paths ===
blogs_file = "categories/blogs.json"
blog_views_file = "storage/blog_views.json" 

if os.path.exists(blogs_file):
    with open(blogs_file, "r", encoding="utf-8") as f:
        blogs = json.load(f)
else:
    blogs = []

if os.path.exists(blog_views_file):
    with open(blog_views_file, "r", encoding="utf-8") as f:
        views_save = json.load(f)
else:
    views_save = {}
    with open(blog_views_file, "w", encoding="utf-8") as f:
        json.dump(views_save, f)

for blog in blogs:
    blog_id = blog.get("id")
    if not blog_id:
        continue

    cmd = f'''
    cd "{LOGS_PATH}" && \
    for f in Aug-2025.tar.gz; do
        tar -xOzf "$f" embedded.io.vn.log.1 | grep "/blogs/?id={blog_id}" | awk '{{print $1}}'
    done | wc -l
    '''

    try:
        views_today = int(subprocess.check_output(cmd, shell=True, text=True).strip())
    except Exception:
        views_today = 0

    # Update cumulative views
    previous_total = views_save.get(blog_id, 0)
    new_total = previous_total + views_today
    views_save[blog_id] = new_total

    # Update blogs.json
    blog["views"] = new_total

    print(f"{blog_id}: today +{views_today}, total {new_total}")

with open(blogs_file, "w", encoding="utf-8") as f:
    json.dump(blogs, f, ensure_ascii=False, indent=2)

with open(blog_views_file, "w", encoding="utf-8") as f:
    json.dump(views_save, f, ensure_ascii=False, indent=2)

print("✅ Updated BLOG_VIEWS and blogs.json successfully")
