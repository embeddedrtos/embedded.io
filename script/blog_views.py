import json
import subprocess
import os

# === File paths ===
blogs_file = "categories/blogs.json"
views_save_file = "categories/views_save.json"

# --- Step 1: Load blogs.json ---
if os.path.exists(blogs_file):
    with open(blogs_file, "r", encoding="utf-8") as f:
        blogs = json.load(f)
else:
    blogs = []

# --- Step 2: Load views_save.json (create empty if not exists) ---
if os.path.exists(views_save_file):
    with open(views_save_file, "r", encoding="utf-8") as f:
        views_save = json.load(f)
else:
    views_save = {}

# --- Step 3: Process each blog ---
for blog in blogs:
    blog_id = blog.get("id")

    # Shell command to count today's views
    cmd = f'''
    cd /home/he341e3e01/domains/embedded.io.vn/logs && \
    for f in Aug-2025.tar.gz*; do
        tar -xOzf "$f" embedded.io.vn.log.1 | grep "/blogs/?id={blog_id}" | awk '{{print $1}}'
    done | wc -l
    '''
    
    try:
        views_today = int(subprocess.check_output(cmd, shell=True, text=True).strip())
    except Exception:
        views_today = 0

    # Update cumulative views using views_save.json only
    previous_total = views_save.get(blog_id, 0)
    new_total = previous_total + views_today
    views_save[blog_id] = new_total

    # Update blogs.json with the latest total
    blog["views"] = new_total

    print(f"{blog_id}: today +{views_today}, total {new_total}")

# --- Step 4: Save blogs.json ---
with open(blogs_file, "w", encoding="utf-8") as f:
    json.dump(blogs, f, ensure_ascii=False, indent=2)

# --- Step 5: Save views_save.json ---
with open(views_save_file, "w", encoding="utf-8") as f:
    json.dump(views_save, f, ensure_ascii=False, indent=2)

print("✅ Updated views_save.json and blogs.json successfully")
