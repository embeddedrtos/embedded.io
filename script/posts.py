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

for post in data.get("data", []):
    print("Message:", post.get("message", "No message"))
    print("Date:", post["created_time"])
    print("Link:", post["permalink_url"])
    print("Image:", post.get("full_picture", "No image"))
    print("-----")

with open("categories/posts.json", "w", encoding="utf-8") as f:
    json.dump(data["data"], f, ensure_ascii=False, indent=2)
