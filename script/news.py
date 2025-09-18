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

# Lists to store posts by hashtag
news_posts = []
posts_posts = []

# Regex patterns
id_pattern = re.compile(r"#([A-Z]\d{3})", re.IGNORECASE)
ca_category_pattern = re.compile(r"#CA([A-Za-z0-9_]+)", re.IGNORECASE)

# Print results and classify by hashtag
for post in data.get("data", []):
    message = post.get("message", "")

    # Check author id pattern (#A001, #B123...)
    author_info = None
    match = id_pattern.search(message)
    if match:
        author_id = match.group(1).upper()
        if author_id in authors_dict:
            author_info = authors_dict[author_id]
            post["author"] = author_info

    # Check CA + CATEGORY
    category_match = ca_category_pattern.search(message)
    if category_match:
        category = category_match.group(1)
        post["category"] = category

    # Debug print
    #print("📝 Message:", message if message else "No content")
    #print("📅 Date:", post["created_time"])
    #print("🔗 Link:", post["permalink_url"])
    #print("🖼 Image:", post.get("full_picture", "No image"))
    if "category" in post:
        print("🏷 Category:", post["category"])
    #print("-----")

    # Check hashtags in message
    if "#news" in message.lower():
        news_posts.append(post)

# Save posts containing #news
if news_posts:
    with open("categories/news.json", "w", encoding="utf-8") as f:
        json.dump(news_posts, f, ensure_ascii=False, indent=2)
