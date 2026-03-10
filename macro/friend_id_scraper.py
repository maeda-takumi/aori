from __future__ import annotations

import json
import re
import time
from dataclasses import dataclass, asdict
from typing import List, Optional

import requests
from bs4 import BeautifulSoup
from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.common.by import By

BASE_URL = "https://step.lme.jp"
FRIEND_LIST_URL = f"{BASE_URL}/basic/friendlist"
FRIEND_HREF_RE = re.compile(r"/basic/friendlist/my_page/([^/?#]+)")


@dataclass
class FriendRow:
    line_display_name: str
    href: str
    friend_id: str


def extract_friend_id_from_href(href: str) -> Optional[str]:
    if not href:
        return None
    m = FRIEND_HREF_RE.search(href)
    if not m:
        return None
    friend_id = m.group(1).strip()
    return friend_id or None


def scrape_current_page(driver: webdriver.Chrome) -> List[FriendRow]:
    soup = BeautifulSoup(driver.page_source, "html.parser")
    rows: List[FriendRow] = []

    for tr in soup.select("table tr"):
        name_link = tr.select_one("a[href*='/basic/friendlist/my_page/']")
        if not name_link:
            continue

        href = (name_link.get("href") or "").strip()
        line_display_name = name_link.get_text(strip=True)
        friend_id = extract_friend_id_from_href(href)
        if not friend_id:
            continue

        rows.append(
            FriendRow(
                line_display_name=line_display_name,
                href=href,
                friend_id=friend_id,
            )
        )

    return rows


def has_next_page(driver: webdriver.Chrome) -> bool:
    try:
        next_button = driver.find_element(By.CSS_SELECTOR, ".glyphicon.glyphicon-menu-right")
        parent_li = next_button.find_element(By.XPATH, "./ancestor::li")
        class_attr = parent_li.get_attribute("class") or ""
        return "disabled" not in class_attr
    except Exception:
        return False


def go_to_next_page(driver: webdriver.Chrome, sleep_sec: float = 1.5) -> None:
    next_button = driver.find_element(By.CSS_SELECTOR, ".glyphicon.glyphicon-menu-right")
    next_button.click()
    time.sleep(sleep_sec)


def scrape_friend_list(driver: webdriver.Chrome) -> List[FriendRow]:
    all_rows: dict[str, FriendRow] = {}

    while True:
        for row in scrape_current_page(driver):
            # 重複friend_idは更新扱い
            all_rows[row.friend_id] = row

        if has_next_page(driver):
            go_to_next_page(driver)
        else:
            break

    return list(all_rows.values())


def post_friend_ids(api_url: str, rows: List[FriendRow], api_token: Optional[str] = None) -> dict:
    payload = {"friends": [asdict(r) for r in rows]}
    headers = {"Content-Type": "application/json"}
    if api_token:
        headers["X-API-Token"] = api_token

    res = requests.post(api_url, headers=headers, data=json.dumps(payload, ensure_ascii=False).encode("utf-8"), timeout=30)
    res.raise_for_status()
    return res.json()


def main() -> None:
    options = Options()
    options.add_experimental_option("detach", True)
    driver = webdriver.Chrome(options=options)

    try:
        driver.get(BASE_URL)
        input("ログイン完了後に Enter を押してください → ")

        driver.get(FRIEND_LIST_URL)
        time.sleep(1.0)

        rows = scrape_friend_list(driver)
        print(f"取得件数: {len(rows)}")

        print("\n=== 送信用JSONサンプル（先頭5件） ===")
        print(json.dumps({"friends": [asdict(r) for r in rows[:5]]}, ensure_ascii=False, indent=2))

        api_url = input("\nfriend_id保存API URL（未入力なら送信スキップ）: ").strip()
        if api_url:
            api_token = input("APIトークン（不要なら空Enter）: ").strip() or None
            result = post_friend_ids(api_url=api_url, rows=rows, api_token=api_token)
            print("\nAPI結果:")
            print(json.dumps(result, ensure_ascii=False, indent=2))

    finally:
        driver.quit()


if __name__ == "__main__":
    main()
