import os
import requests
import json
import re

ACCESS_TOKEN = os.environ.get("ACCESS_TOKEN")
PAGE_ID      = os.environ.get("PAGE_ID")

with open("authors/authors.json", "r", encoding="utf-8") as f:
    authors_dict = {a["id"]: a for a in json.load(f)}

ID_PATTERN       = re.compile(r"#([A-Z]\d{3})", re.IGNORECASE)
CATEGORY_PATTERN = re.compile(r"#CA([A-Za-z0-9_]+)", re.IGNORECASE)
TITLE_PATTERN    = re.compile(r"Title:\s*(.+)", re.IGNORECASE)


def fetch_all_posts(page_id: str, token: str) -> list:
    """Follow Facebook pagination — never drops old posts."""
    url    = f"https://graph.facebook.com/v23.0/{page_id}/posts"
    params = {
        "fields":       "id,message,created_time,permalink_url,full_picture",
        "access_token": token,
    }
    posts = []
    while url:
        resp = requests.get(url, params=params)
        resp.raise_for_status()
        payload = resp.json()
        posts.extend(payload.get("data", []))
        url    = payload.get("paging", {}).get("next")
        params = {}
    return posts


def build_post(raw: dict, authors: dict) -> dict:
    message = raw.get("message", "")

    title_match = TITLE_PATTERN.search(message)
    title = title_match.group(1).strip() if title_match else message.split("\n")[0].strip()

    post = {
        "id":            raw["id"],
        "title":         title,
        "description":   None,
        "created_time":  raw["created_time"],
        "permalink_url": raw["permalink_url"],
        "full_picture":  raw.get("full_picture"),
    }

    author_match = ID_PATTERN.search(message)
    if author_match:
        author_id = author_match.group(1).upper()
        if author_id in authors:
            post["author"] = authors[author_id]

    cat_match = CATEGORY_PATTERN.search(message)
    if cat_match:
        post["category"] = cat_match.group(1)

    return post


all_posts = fetch_all_posts(PAGE_ID, ACCESS_TOKEN)

facebook_posts = [
    build_post(p, authors_dict)
    for p in all_posts
    if "#posts" in p.get("message", "").lower()
]

with open("categories/facebook_posts.json", "w", encoding="utf-8") as f:
    json.dump(facebook_posts, f, ensure_ascii=False, indent=2)

print(f"[OK] facebook_posts.json — {len(facebook_posts)} posts")
