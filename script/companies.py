import json

# Path to the JSON file
file_path = "categories/companies.json"

# Read JSON data
with open(file_path, "r", encoding="utf-8") as f:
    companies = json.load(f)

vn_count = 1
ob_count = 1
updated_companies = []

# Add ID field at the beginning of each company
for company in companies:
    if company.get("country") == "Vietnam":
        cid = f"COMVN{vn_count:02d}"
        vn_count += 1
    else:
        cid = f"COMOB{ob_count:02d}"
        ob_count += 1

    # Rebuild dict with ID first, then the rest
    new_company = {"id": cid}
    new_company.update(company)
    updated_companies.append(new_company)

# Overwrite the same JSON file with updated data
with open(file_path, "w", encoding="utf-8") as f:
    json.dump(updated_companies, f, ensure_ascii=False, indent=2)

print("IDs added at the beginning of each company object.")
