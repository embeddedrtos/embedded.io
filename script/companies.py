import json

# Path to the JSON file
file_path = "categories/company.json"

# Read JSON data
with open(file_path, "r", encoding="utf-8") as f:
    companies = json.load(f)

vn_count = 1
ob_count = 1

# Add ID field for each company
for company in companies:
    if company.get("country") == "Vietnam":
        company["id"] = f"COMVN{vn_count:02d}"  # ID format for Vietnam
        vn_count += 1
    else:
        company["id"] = f"COMOB{ob_count:02d}"  # ID format for Other countries
        ob_count += 1

# Overwrite the same JSON file with updated data
with open(file_path, "w", encoding="utf-8") as f:
    json.dump(companies, f, ensure_ascii=False, indent=2)

print(f"IDs added successfully.")
