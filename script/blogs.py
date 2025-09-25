import json

# Load blogs.json
with open("categories/blogs.json", "r", encoding="utf-8") as f:
    blogs = json.load(f)

# Load authors.json
with open("authors/authors.json", "r", encoding="utf-8") as f:
    authors = json.load(f)

# Create a lookup dictionary for authors by id
author_map = {a["id"]: a for a in authors}

# Enrich each blog with full author info
for blog in blogs:
    author_id = blog.get("author_id")
    if author_id in author_map:
        blog["author"] = author_map[author_id]  # add full author object
        del blog["author_id"]  # remove author_id if you don’t need it

# Overwrite blogs.json with enriched data
with open("categories/blogs.json", "w", encoding="utf-8") as f:
    json.dump(blogs, f, ensure_ascii=False, indent=2)
