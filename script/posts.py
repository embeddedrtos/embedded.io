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
    'fields': 'id,message,created_time,permalink_url,full_picture,insights.metric(post_impressions)',
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
    views = None
    
    # Get views from insights
    insights = post.get("insights", {}).get("data", [])
    for metric in insights:
        if metric.get("name") == "post_impressions":
            views = metric.get("values", [{}])[0].get("value", 0)
            break

    post["views"] = views if views is not None else 0

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
    print("📝 Message:", message if message else "No content")
    print("📅 Date:", post["created_time"])
    print("🔗 Link:", post["permalink_url"])
    print("🖼 Image:", post.get("full_picture", "No image"))
    print("👀 Views:", post["views"])
    if "category" in post:
        print("🏷 Category:", post["category"])
    print("-----")

    # Check hashtags in message
    if "#news" in message.lower():
        news_posts.append(post)
    if "#posts" in message.lower():
        posts_posts.append(post)

# Save posts containing #news
if news_posts:
    with open("categories/news.json", "w", encoding="utf-8") as f:
        json.dump(news_posts, f, ensure_ascii=False, indent=2)

# Save posts containing #posts
if posts_posts:
    with open("categories/posts.json", "w", encoding="utf-8") as f:
        json.dump(posts_posts, f, ensure_ascii=False, indent=2)
