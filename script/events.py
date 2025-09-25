import requests
import json
import re
import os

# Facebook Page settings
ACCESS_TOKEN = os.environ.get("ACCESS_TOKEN")
PAGE_ID = os.environ.get("PAGE_ID")

GRAPH_URL = f"https://graph.facebook.com/v23.0/{PAGE_ID}/posts?fields=message&access_token={ACCESS_TOKEN}"


def post_to_json(post_text):
    """
    Parse a structured Facebook post (formatted with 'Title:', 'Date:', etc.)
    into a clean JSON dictionary.
    """
    data = {}
    patterns = {
        "title": r"Title:\s*(.+)",
        "date": r"Date:\s*(.+)",
        "location": r"Location:\s*(.+)",
        "categories": r"Categories:\s*(.+)",
        "members": r"Members:\s*(.+)",
        "logo": r"Logo:\s*(.+)",
        "background": r"Background:\s*(.+)",
        "url": r"URL:\s*(.+)",
        "description": r"Description:\s*(.+)"
    }

    for key, pattern in patterns.items():
        match = re.search(pattern, post_text, re.IGNORECASE)
        if match:
            value = match.group(1).strip()

            # Special handling for categories
            if key == "categories":
                data[key] = [c.strip() for c in re.split(r"[|,]", value)]
            elif value.lower() == "null":
                data[key] = None
            else:
                data[key] = value
        else:
            data[key] = None

    # Apply default rules
    if not data["logo"]:
        data["logo"] = None
    if not data["members"]:
        data["members"] = None
    if not data["background"]:
        data["background"] = "linear-gradient(45deg, #FDEB71, #F8D800)"

    return data


def insert_event(json_file, event_data):
    """
    Insert new event JSON at the beginning of categories/events.json
    - Only insert if 'title' is unique
    """
    try:
        with open(json_file, "r", encoding="utf-8") as f:
            events = json.load(f)
    except (FileNotFoundError, json.JSONDecodeError):
        events = []

    # Check duplicate by title
    existing_titles = {e.get("title") for e in events}
    if event_data["title"] in existing_titles:
        #print(f"Skipped: Event '{event_data['title']}' already exists.")
        return

    events.insert(0, event_data)

    with open(json_file, "w", encoding="utf-8") as f:
        json.dump(events, f, indent=2, ensure_ascii=False)

    #print(f"Inserted new event: {event_data['title']}")


def fetch_facebook_posts():
    """
    Fetch Facebook posts using the Graph API
    """
    response = requests.get(GRAPH_URL)
    response.raise_for_status()
    return response.json().get("data", [])


if __name__ == "__main__":
    posts = fetch_facebook_posts()

    for post in posts:
        message = post.get("message", "")
        if not message:
            continue

        # Only process posts that have hashtag #events
        if "#events" in message.lower():
            #print("Found event post, parsing...")

            event_json = post_to_json(message)
            insert_event("categories/events.json", event_json)
