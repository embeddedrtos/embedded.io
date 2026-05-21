import requests
import json
import re
import os

ACCESS_TOKEN = os.environ.get("ACCESS_TOKEN")
PAGE_ID      = os.environ.get("PAGE_ID")

GRAPH_URL = (
    f"https://graph.facebook.com/v23.0/{PAGE_ID}/posts"
    f"?fields=id,message&access_token={ACCESS_TOKEN}"
)


def post_to_event(post_id: str, text: str) -> dict:
    data = {"id": f"EV-{post_id}"}  # Facebook post ID → stable event ID

    patterns = {
        "title":       r"Title:\s*(.+)",
        "date":        r"Date:\s*(.+)",
        "location":    r"Location:\s*(.+)",
        "categories":  r"Categories:\s*(.+)",
        "members":     r"Members:\s*(.+)",
        "logo":        r"Logo:\s*(.+)",
        "background":  r"Background:\s*(.+)",
        "url":         r"URL:\s*(.+)",
        "description": r"Description:\s*(.+)",
    }

    for key, pattern in patterns.items():
        match = re.search(pattern, text, re.IGNORECASE)
        if match:
            value = match.group(1).strip()
            if key == "categories":
                data[key] = [c.strip() for c in re.split(r"[|,]", value)]
            elif value.lower() == "null":
                data[key] = None
            else:
                data[key] = value

    # Defaults
    data.setdefault("background", "linear-gradient(45deg, #FDEB71, #F8D800)")

    # Drop always-null fields
    for field in ("members", "logo"):
        if data.get(field) is None:
            data.pop(field, None)

    return data


def insert_event(json_file: str, event: dict) -> bool:
    try:
        with open(json_file, "r", encoding="utf-8") as f:
            events = json.load(f)
    except (FileNotFoundError, json.JSONDecodeError):
        events = []

    # Dedup by id (stable) with title fallback
    existing_ids    = {e.get("id") for e in events}
    existing_titles = {e.get("title") for e in events}

    if event.get("id") in existing_ids or event.get("title") in existing_titles:
        return False

    events.insert(0, event)

    with open(json_file, "w", encoding="utf-8") as f:
        json.dump(events, f, indent=2, ensure_ascii=False)

    return True


if __name__ == "__main__":
    resp = requests.get(GRAPH_URL)
    resp.raise_for_status()
    posts = resp.json().get("data", [])

    added = 0
    for post in posts:
        message = post.get("message", "")
        if "#events" not in message.lower():
            continue
        event = post_to_event(post["id"], message)
        if insert_event("categories/events.json", event):
            added += 1
            print(f"[+] {event.get('title', 'Untitled')}")

    print(f"[OK] events.py — {added} new events added")
