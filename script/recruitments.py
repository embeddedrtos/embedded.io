import re
import json

# Bước 1: Regex để loại bỏ emoji/icon unicode
EMOJI_PATTERN = re.compile(r"[^\x00-\x7F]+", flags=re.UNICODE)

def remove_emojis(text: str) -> str:
    """Xóa tất cả icon/emojis trong chuỗi"""
    return EMOJI_PATTERN.sub(r'', text)

# Bước 2: Parse message thành JSON
def parse_job_post(text: str):
    clean_text = remove_emojis(text)
    job = {}

    # Company
    match = re.search(r"Company:\s*(.+)", clean_text)
    if match:
        job["company"] = {"name": match.group(1).strip()}

    # Position
    match = re.search(r"Position:\s*(.+)", clean_text)
    if match:
        job["title"] = match.group(1).strip()

    # Level
    match = re.search(r"Level:\s*(.+)", clean_text)
    if match:
        job["level"] = match.group(1).strip()

    # Location (Type, Address, City)
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

    # Salary (giữ nguyên text)
    match = re.search(r"Salary:\s*(.+)", clean_text)
    if match:
        job["salary"] = match.group(1).strip()

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
    match = re.search(r"Apply:\s*(.+)", clean_text)
    if match:
        job["apply"] = match.group(1).strip()

    return job


if __name__ == "__main__":
    post_text = """
    🏢 Company: ABCDEF Tech  
    💼 Position: Embedded Software Engineer (CA55/RTOS)  
    📊 Level: Mid  
    📍 Location:  
        - Type: Onsite  
        - Address: Tòa nhà XYZ, Quận 1  
        - City: Ho Chi Minh 
    💰 Salary: 15–30M VND (negotiable)  

    🛠️ Requirements:  
    - 💻 C/C++  
    - ⚡ FreeRTOS/Zephyr  
    - 🔌 UART/SPI/I2C  

    🎁 Benefits:  
    - 💵 Competitive salary  
    - 🩺 Social & Health Insurance  
    - 📈 Performance review every 6 months  

    ⏳ Deadline: 30/09/2025  
    📧 Apply: hr@abctech.vn (Nguyen Van A – HR)  
    """

    job_json = parse_job_post(post_text)
    print(json.dumps(job_json, ensure_ascii=False, indent=2))
