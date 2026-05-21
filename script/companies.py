import json

FILE_PATH = "categories/companies.json"

with open(FILE_PATH, "r", encoding="utf-8") as f:
    companies = json.load(f)

vn_count = 1
ob_count = 1
updated = []

for company in companies:
    existing_id = company.get("id", "")

    # Skip re-assigning if ID already matches the expected pattern
    if existing_id.startswith("COMVN") or existing_id.startswith("COMOB"):
        updated.append(company)
        # Advance counters to stay in sync
        if existing_id.startswith("COMVN"):
            vn_count += 1
        else:
            ob_count += 1
        continue

    # Assign new ID for entries without one
    if company.get("country") == "Vietnam":
        cid = f"COMVN{vn_count:02d}"
        vn_count += 1
    else:
        cid = f"COMOB{ob_count:02d}"
        ob_count += 1

    # Remove stale id (if any) and rebuild with id first
    company.pop("id", None)
    updated.append({"id": cid, **company})

with open(FILE_PATH, "w", encoding="utf-8") as f:
    json.dump(updated, f, ensure_ascii=False, indent=2)

print(f"[OK] companies.json — {len(updated)} entries, idempotent")
