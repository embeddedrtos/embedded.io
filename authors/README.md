# 📚 Author JSON Schema Guide

This guide explains how to **add a new author** to `authors.json` following the required JSON Schema. Using the correct format ensures that your author information is **valid, consistent, and ready for automated validation**.

---

## 📂 Repository Structure

```
authors/
  ├── schema.json       # JSON Schema definition
  ├── authors.json      # Authors data file
.github/
  └── workflows/
      └── validate-author.yml   # GitHub Actions workflow for validation
package.json
```

* `schema.json` → defines required/optional fields and rules  
* `authors.json` → stores all author entries  
* `validate-author.yml` → automatically checks new entries for validity (for admin use)  

---

## 📝 Author JSON Rules

Each author object must follow this schema:

### Required Fields

| Field  | Type   | Description                     |
| ------ | ------ | ------------------------------- |
| `id`   | string | Unique identifier               |
| `name` | string | Full name of the author         |
| `bio`  | string | Short biography                 |

### Optional Fields

| Field                                                  | Type           | Description                                                   |
| ------------------------------------------------------ | -------------- | ------------------------------------------------------------- |
| `avatar`                                               | string         | Image URL (default: `default-avatar.jpg`) |
| `job_title`                                            | string or null | Job title of the author                                       |
| `company`                                              | string or null | Company/organization                                          |
| `email`                                                | string or null | Email address                                                 |
| `socials`                                              | object         | Social media links (all optional, each must be URL or `null`) |
| `github`, `facebook`, `linkedin`, `twitter`, `youtube` |                |                                                               |

---

## 🔑 Author ID Rules

Each `id` must be **unique** across all authors.

**Structure:**  
```
[One uppercase letter][Number code]
```

### Prefix Rules

- **A** → Administrator, Developer  
- **C** → Contributor  
- **U** → User  
- **R** → Recruiter  

✅ Example valid IDs:  
- `A001`, `C12`, `U7`, `R100`  

❌ Invalid IDs:  
- `a01` (lowercase not allowed)  
- `123` (must start with a letter)  
- `B001` (letter not in `[A, C, U, R]`)  

---

## ✅ Example: Valid Author Entry

```json
{
  "id": "A001",
  "name": "Pham Van Nhat",
  "bio": "Hi there, I'm Nhat, an embedded systems engineer passionate about IoT, real-time systems, and AI integration.",
  "avatar": "https://avatars.githubusercontent.com/u/156271796?v=4",
  "job_title": "Embedded Software Engineer",
  "company": "Ho Chi Minh City University of Technology",
  "email": "embeddedrtos.vietnam@gmail.com",
  "socials": {
    "github": "https://github.com/embeddedrtos",
    "facebook": "https://www.facebook.com/emrtosVN",
    "linkedin": null,
    "twitter": null,
    "youtube": null
  }
}
```

---

## ❌ Example: Invalid Author Entry

```json
{
  "id": "2",
  "bio": "Missing name field → this will fail validation"
}
```

**Error:**  
- Missing required field `name`.  
- Invalid `id` format (must start with `A`, `C`, `U`, or `R` followed by number).  

---

## ✨ How to Add a New Author

1. Open `authors/authors.json` in your editor.  
2. Add a new object **inside the JSON array** using the correct format:  

```json
{
  "id": "C002",
  "name": "New Author",
  "bio": "Short description about this author.",
  "avatar": null,
  "job_title": "Software Developer",
  "company": "Tech Company",
  "email": "author@example.com",
  "socials": {
    "github": "https://github.com/example",
    "facebook": null,
    "linkedin": null,
    "twitter": null,
    "youtube": null
  }
}
```

3. Save the file. ✅  

> Tip: Always assign a **unique `id`**. Never reuse an existing one.

---

## 📖 References

* [JSON Schema](https://json-schema.org/)  
* [AJV CLI](https://github.com/ajv-validator/ajv-cli)  

---

With this guide, you can **add new authors confidently**, ensuring their information is **standardized, valid, and ready for automation** 🚀
