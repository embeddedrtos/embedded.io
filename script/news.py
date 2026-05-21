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


all_posts = fetch_all_posts(PAGE_ID, ACCESS_TOKEN)

news_posts = []
for post in all_posts:
    message = post.get("message", "")
    if "#news" not in message.lower():
        continue

    author_match = ID_PATTERN.search(message)
    if author_match:
        author_id = author_match.group(1).upper()
        if author_id in authors_dict:
            post["author"] = authors_dict[author_id]

    cat_match = CATEGORY_PATTERN.search(message)
    if cat_match:
        post["category"] = cat_match.group(1)

    news_posts.append(post)

with open("categories/news.json", "w", encoding="utf-8") as f:
    json.dump(news_posts, f, ensure_ascii=False, indent=2)

print(f"[OK] news.json — {len(news_posts)} posts")
