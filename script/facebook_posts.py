import os
import requests
import json
import re

ACCESS_TOKEN = os.environ.get("ACCESS_TOKEN")
PAGE_ID = os.environ.get("PAGE_ID")

# Load authors.json
with open("authors/authors.json", "r", encoding="utf-8") as f:
    authors_data = json.load(f)

# Convert authors list to dict for quick lookup
authors_dict = {author["id"]: author for author in authors_data}

url = f'https://graph.facebook.com/v23.0/{PAGE_ID}/posts'
params = {
    'fields': 'id,message,created_time,permalink_url,full_picture',
    'access_token': ACCESS_TOKEN
}

response = requests.get(url, params=params)
data = response.json()

# List to store posts containing #posts
facebook_posts = []

# Regex patterns
id_pattern = re.compile(r"#([A-Z]\d{3})", re.IGNORECASE)   # Author ID (#A001, #B123...)
ca_category_pattern = re.compile(r"#CA([A-Za-z0-9_]+)", re.IGNORECASE)  # Category hashtag
title_pattern = re.compile(r"Title:\s*(.+)", re.IGNORECASE)
description_pattern = re.compile(r"Description:\s*(.+)", re.IGNORECASE | re.DOTALL)

# Process posts
for post in data.get("data", []):
    message = post.get("message", "")

    # Skip if no message
    if not message:
        continue

    # Default values
    title = None
    description = None

    # Extract title
    title_match = title_pattern.search(message)
    if title_match:
        title = title_match.group(1).strip()

    # Extract description (can be multi-line)
    description_match = description_pattern.search(message)
    if description_match:
        description = description_match.group(1).strip()

    # If no title, fallback to first line of message
    if not title:
        title = message.split("\n")[0].strip()

    # Check author ID
    author_info = None
    match = id_pattern.search(message)
    if match:
        author_id = match.group(1).upper()
        if author_id in authors_dict:
            author_info = authors_dict[author_id]
            post["author"] = author_info

    # Check category hashtag
    category_match = ca_category_pattern.search(message)
    if category_match:
        category = category_match.group(1)
        post["category"] = category

    # Build clean post data
    clean_post = {
        "id": post["id"],
        "title": title,
        "description": description,
        "created_time": post["created_time"],
        "permalink_url": post["permalink_url"],
        "full_picture": post.get("full_picture"),
    }

    if "author" in post:
        clean_post["author"] = post["author"]

    if "category" in post:
        clean_post["category"] = post["category"]

    # Save only posts that contain #posts hashtag
    if "#posts" in message.lower():
        facebook_posts.append(clean_post)

# Save to JSON
if facebook_posts:
    with open("categories/facebook_posts.json", "w", encoding="utf-8") as f:
        json.dump(facebook_posts, f, ensure_ascii=False, indent=2)

print(f"Saved {len(facebook_posts)} posts to categories/facebook_posts.json")
