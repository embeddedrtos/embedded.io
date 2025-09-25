import re
import json
import os
import requests
from pathlib import Path

ACCESS_TOKEN = os.environ.get("ACCESS_TOKEN")
PAGE_ID = os.environ.get("PAGE_ID")

url = f'https://graph.facebook.com/v23.0/{PAGE_ID}/posts'
params = {
    'fields': 'id,message,created_time,full_picture',
    'access_token': ACCESS_TOKEN
}

# Regex to remove emojis (non-ASCII characters)
EMOJI_PATTERN = re.compile(r"[^\x00-\x7F]+", flags=re.UNICODE)

def remove_emojis(text: str) -> str:
    """Remove emojis/icons from text"""
    return EMOJI_PATTERN.sub(r'', text)

def parse_job_post(post: dict):
    """Parse a recruitment post into structured JSON"""
    clean_text = remove_emojis(post.get("message", ""))

    job = {
        "id": post.get("id"),
        "created_time": post.get("created_time"),
        "full_picture": post.get("full_picture")
    }

    # Company
    match = re.search(r"Company:\s*(.+)", clean_text)
    if match:
        job["company"] = {"name": match.group(1).strip()}

    # Title
    match = re.search(r"Position:\s*(.+)", clean_text)
    if match:
        job["title"] = match.group(1).strip()

    # Description
    match = re.search(r"Description:\s*(.+)", clean_text)
    if match:
        job["description"] = match.group(1).strip()

    # Level
    match = re.search(r"Level:\s*(.+)", clean_text)
    if match:
        job["level"] = match.group(1).strip()

    # Location
    loc_match = re.search(r"Location:(.+?)Salary:", clean_text, re.S)
    if loc_match:
        loc_block = loc_match.group(1)
        loc_type = re.search(r"Type:\s*(.+)", loc_block)
        loc_addr = re.search(r"Address:\s*(.+)", loc_block)
        loc_city = re.search(r"City:\s*(.+)", loc_block)
        job["location"] = {
            "type": loc_type.group(1).strip() if loc_type else "",
            "address": loc_addr.group(1).strip() if loc_addr else "",
            "city": loc_city.group(1).strip() if loc_city else ""
        }

    # Salary
    match = re.search(r"Salary:\s*(.+)", clean_text)
    if match:
        job["salary"] = match.group(1).strip()

    # Work Time
    match = re.search(r"Work Time:\s*(.+)", clean_text)
    if match:
        job["work_time"] = match.group(1).strip()

    # Requirements
    req_match = re.search(r"Requirements:(.+?)Benefits:", clean_text, re.S)
    if req_match:
        reqs = [line.strip("- ").strip()
                for line in req_match.group(1).strip().splitlines() if line.strip()]
        job["requirements"] = reqs

    # Benefits
    ben_match = re.search(r"Benefits:(.+?)Deadline:", clean_text, re.S)
    if ben_match:
        bens = [line.strip("- ").strip()
                for line in ben_match.group(1).strip().splitlines() if line.strip()]
        job["benefits"] = bens

    # Deadline
    match = re.search(r"Deadline:\s*(.+)", clean_text)
    if match:
        job["apply_deadline"] = match.group(1).strip()

    # Apply
    apply = {}
    email_match = re.search(r"Email:\s*([^\n\r]+)", clean_text)
    if email_match:
        apply["email"] = email_match.group(1).strip()
    contact_match = re.search(r"Contact:\s*([^\n\r]+)", clean_text)
    if contact_match:
        apply["contact_person"] = contact_match.group(1).strip()
    if apply:
        job["apply"] = apply

    # Tags
    tag_match = re.search(r"Tags:(.+?)Apply:", clean_text, re.S)
    if tag_match:
        tags = [line.strip("- ").strip()
                for line in tag_match.group(1).strip().splitlines() if line.strip()]
        job["tags"] = tags

    return job

if __name__ == "__main__":
    response = requests.get(url, params=params)
    posts = response.json().get("data", [])

    json_path = Path("categories/recruitments.json")

    # Load existing JSON
    if json_path.exists():
        with open(json_path, "r", encoding="utf-8") as f:
            data = json.load(f)
    else:
        data = []

    # Process only posts containing #recruitment
    for post in posts:
        message = post.get("message", "")
        
        if "#recruitment" in message.lower():
            job_json = parse_job_post(post)
            # Check if ID already exists
            if not any(j.get("id") == job_json["id"] for j in data):
                data.insert(0, job_json)
                print(f"Added job: {job_json['title']}")
            else:
                print(f"Skipped duplicate job ID: {job_json['id']}")

    # Save back to file
    with open(json_path, "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, indent=2)

    print("Recruitment data updated in categories/recruitments.json")
