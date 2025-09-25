import json
from pathlib import Path

# === File paths ===
authors_file = Path("authors/authors.json")
roles_badges_file = Path("roles_and_badges.json")

# === Load data ===
with authors_file.open(encoding="utf-8") as f:
    authors = json.load(f)

with roles_badges_file.open(encoding="utf-8") as f:
    roles_and_badges = json.load(f)

# === Index roles by id ===
role_map = {r["id"]: r for r in roles_and_badges["roles"]}

# === Index badges by id ===
badge_map = {}
for category, items in roles_and_badges["badges"].items():
    for b in items:
        badge_map[b["id"]] = b

# === Replace ids with full objects ===
for author in authors:
    # Replace role
    if "roles" in author and author["roles"]:
        role_id = author["roles"]
        if role_id in role_map:
            author["roles"] = role_map[role_id]
        else:
            print(f"Warning: Role id '{role_id}' not found in roles_and_badges.json")

    # Replace badges
    if "badges" in author and author["badges"]:
        new_badges = []
        for b_id in author["badges"]:
            if b_id in badge_map:
                new_badges.append(badge_map[b_id])
            else:
                print(f"Warning: Badge id '{b_id}' not found in roles_and_badges.json")
        author["badges"] = new_badges

# === Save back to file ===
with authors_file.open("w", encoding="utf-8") as f:
    json.dump(authors, f, ensure_ascii=False, indent=2)

print("authors/authors.json updated successfully!")
