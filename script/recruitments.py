import re
import json
import os
import requests
from pathlib import Path

ACCESS_TOKEN = os.environ.get("ACCESS_TOKEN")
PAGE_ID      = os.environ.get("PAGE_ID")

# Strip only supplementary-plane emoji (U+10000–U+10FFFF).
# Vietnamese characters are in the Basic Multilingual Plane and are preserved.
EMOJI_PATTERN = re.compile(r"[\U00010000-\U0010FFFF]", flags=re.UNICODE)


def remove_emojis(text: str) -> str:
    return EMOJI_PATTERN.sub("", text)


def fetch_all_posts(url: str, params: dict) -> list:
    """Follow Facebook pagination and return all posts."""
    posts = []
    while url:
        response = requests.get(url, params=params)
        response.raise_for_status()
        payload = response.json()
        posts.extend(payload.get("data", []))
        paging = payload.get("paging", {})
        url    = paging.get("next")
        params = {}  # next URL already contains all params
    return posts


def parse_job_post(post: dict) -> dict:
    clean_text = remove_emojis(post.get("message", ""))

    job = {
        "id":           post.get("id"),
        "created_time": post.get("created_time"),
        "full_picture": post.get("full_picture"),
    }

    def find(pattern, text, flags=0):
        m = re.search(pattern, text, flags)
        return m.group(1).strip() if m else None

    job_fields = {
        "company":       lambda t: (lambda m: {"name": m} if m else None)(find(r"Company:\s*(.+)", t)),
        "title":         lambda t: find(r"Position:\s*(.+)", t),
        "description":   lambda t: find(r"Description:\s*(.+)", t),
        "level":         lambda t: find(r"Level:\s*(.+)", t),
        "salary":        lambda t: find(r"Salary:\s*(.+)", t),
        "work_time":     lambda t: find(r"Work Time:\s*(.+)", t),
        "apply_deadline":lambda t: find(r"Deadline:\s*(.+)", t),
    }

    for key, extractor in job_fields.items():
        value = extractor(clean_text)
        if value:
            job[key] = value

    # Location block
    loc_match = re.search(r"Location:(.+?)Salary:", clean_text, re.S)
    if loc_match:
        lb = loc_match.group(1)
        job["location"] = {
            "type":    (find(r"Type:\s*(.+)", lb) or ""),
            "address": (find(r"Address:\s*(.+)", lb) or ""),
            "city":    (find(r"City:\s*(.+)", lb) or ""),
        }

    # Requirements (list)
    req_match = re.search(r"Requirements:(.+?)Benefits:", clean_text, re.S)
    if req_match:
        job["requirements"] = [
            line.lstrip("- ").strip()
            for line in req_match.group(1).strip().splitlines()
            if line.strip()
        ]

    # Benefits (list)
    ben_match = re.search(r"Benefits:(.+?)Deadline:", clean_text, re.S)
    if ben_match:
        job["benefits"] = [
            line.lstrip("- ").strip()
            for line in ben_match.group(1).strip().splitlines()
            if line.strip()
        ]

    # Apply
    apply = {}
    email   = find(r"Email:\s*([^\n\r]+)", clean_text)
    contact = find(r"Contact:\s*([^\n\r]+)", clean_text)
    if email:
        apply["email"] = email
    if contact:
        apply["contact_person"] = contact
    if apply:
        job["apply"] = apply

    # Tags (list)
    tag_match = re.search(r"Tags:(.+?)Apply:", clean_text, re.S)
    if tag_match:
        job["tags"] = [
            line.lstrip("- ").strip()
            for line in tag_match.group(1).strip().splitlines()
            if line.strip()
        ]

    return job


if __name__ == "__main__":
    base_url = f"https://graph.facebook.com/v23.0/{PAGE_ID}/posts"
    params   = {
        "fields":       "id,message,created_time,full_picture",
        "access_token": ACCESS_TOKEN,
    }

    all_posts = fetch_all_posts(base_url, params)

    json_path = Path("categories/recruitments.json")
    data = json.loads(json_path.read_text(encoding="utf-8")) if json_path.exists() else []

    existing_ids = {j.get("id") for j in data}

    added = 0
    for post in all_posts:
        if "#recruitment" not in post.get("message", "").lower():
            continue
        job = parse_job_post(post)
        if job["id"] not in existing_ids:
            data.insert(0, job)
            existing_ids.add(job["id"])
            added += 1
            print(f"[+] {job.get('title', 'Untitled')}")
        else:
            print(f"[=] Skipped duplicate: {job['id']}")

    json_path.write_text(
        json.dumps(data, ensure_ascii=False, indent=2),
        encoding="utf-8"
    )
    print(f"[OK] recruitments.json — {added} new, {len(data)} total")
