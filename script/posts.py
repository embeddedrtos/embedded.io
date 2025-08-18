import os
import requests
import json

ACCESS_TOKEN = os.environ.get("ACCESS_TOKEN")
PAGE_ID = os.environ.get("PAGE_ID")

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

# Print results and classify by hashtag
for post in data.get("data", []):
    message = post.get("message", "")
    print("📝 Message:", message if message else "No content")
    print("📅 Date:", post["created_time"])
    print("🔗 Link:", post["permalink_url"])
    print("🖼 Image:", post.get("full_picture", "No image"))
    print("-----")

    # Check hashtags in the message (convert to lowercase for consistency)
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
