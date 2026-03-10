from __future__ import annotations

import json
import re
import time
import tkinter as tk
from dataclasses import dataclass, asdict
from tkinter import messagebox
from typing import List, Optional

import requests
from bs4 import BeautifulSoup
from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.common.by import By

BASE_URL = "https://step.lme.jp"
FRIEND_LIST_URL = f"{BASE_URL}/basic/friendlist"
FRIEND_HREF_RE = re.compile(r"/basic/friendlist/my_page/([^/?#]+)")
DEFAULT_EMAIL = "miomama0605@gmail.com"
DEFAULT_PASSWORD = "20250606@Mio"
FRIEND_SYNC_API_URL = "https://totalappworks.com/support_aori/macro/friend_id_sync_api.php"


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



def post_friend_ids(api_url: str, rows: List[FriendRow]) -> dict:
    payload = {"friends": [asdict(r) for r in rows]}
    headers = {"Content-Type": "application/json"}

    res = requests.post(api_url, headers=headers, data=json.dumps(payload, ensure_ascii=False).encode("utf-8"), timeout=30)
    res.raise_for_status()
    return res.json()

def prefill_login_form(driver: webdriver.Chrome, email: str, password: str) -> None:
    selectors = {
        "email": [
            (By.NAME, "email"),
            (By.ID, "email"),
            (By.CSS_SELECTOR, "input[type='email']"),
        ],
        "password": [
            (By.NAME, "password"),
            (By.ID, "password"),
            (By.CSS_SELECTOR, "input[type='password']"),
        ],
    }

    for by, value in selectors["email"]:
        elems = driver.find_elements(by, value)
        if not elems:
            continue
        elems[0].clear()
        elems[0].send_keys(email)
        break

    for by, value in selectors["password"]:
        elems = driver.find_elements(by, value)
        if not elems:
            continue
        elems[0].clear()
        elems[0].send_keys(password)
        break

def main() -> None:
    run_scraper()


def run_scraper() -> None:
    options = Options()
    options.add_experimental_option("detach", True)
    driver = webdriver.Chrome(options=options)

    try:
        driver.get(BASE_URL)
        time.sleep(0.5)
        prefill_login_form(driver, DEFAULT_EMAIL, DEFAULT_PASSWORD)
        messagebox.showinfo(
            "操作待ち",
            "Seleniumブラウザで手動ログインし、友だち一覧ページへ移動したら\n"
            "このメッセージを閉じてください。",
        )

        rows = scrape_friend_list(driver)
        print(f"取得件数: {len(rows)}")

        print("\n=== 送信用JSONサンプル（先頭5件） ===")
        print(json.dumps({"friends": [asdict(r) for r in rows[:5]]}, ensure_ascii=False, indent=2))

        result = post_friend_ids(api_url=FRIEND_SYNC_API_URL, rows=rows)
        print("\nAPI結果:")
        print(json.dumps(result, ensure_ascii=False, indent=2))

    finally:
        driver.quit()

def start_ui() -> None:
    root = tk.Tk()
    root.title("friend_id_scraper")
    root.geometry("240x120")

    run_button = tk.Button(root, text="実行", width=16)

    def on_run() -> None:
        run_button.config(state=tk.DISABLED)
        try:
            run_scraper()
            messagebox.showinfo("完了", "処理が完了しました。")
        except Exception as e:
            messagebox.showerror("エラー", f"処理中にエラーが発生しました。\n{e}")
        finally:
            run_button.config(state=tk.NORMAL)

    run_button.config(command=on_run)
    run_button.pack(expand=True)
    root.mainloop()

if __name__ == "__main__":
    start_ui()
