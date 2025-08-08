import sys
import time
import logging
from pathlib import Path
from selenium import webdriver
from selenium.webdriver.common.by import By
import undetected_chromedriver as uc

logging.basicConfig(level=logging.INFO)

def report_phishing(url):
    try:
        options = uc.ChromeOptions()
        options.add_argument('--headless')
        options.add_argument('--no-sandbox')
        options.add_argument('--disable-gpu')
        options.add_argument('--disable-dev-shm-usage')

        driver = uc.Chrome(options=options)
        driver.get("https://safebrowsing.google.com/safebrowsing/report_phish/")

        time.sleep(2)
        input_box = driver.find_element(By.NAME, "url")
        input_box.send_keys(url)

        submit_btn = driver.find_element(By.XPATH, "//form//button[@type='submit']")
        submit_btn.click()

        time.sleep(2)

        success = "Terima kasih" in driver.page_source or "Thank you" in driver.page_source
        driver.quit()
        return success

    except Exception as e:
        logging.error(f"[Exception] {url} -> {e}")
        return False

def main(user_id):
    targets_file = f"targets_user_{user_id}.txt"
    status_file = f"report_status_user_{user_id}.txt"
    log_file = f"report_log_user_{user_id}.txt"

    if not Path(targets_file).exists():
        logging.error("Target file not found.")
        return

    urls = Path(targets_file).read_text().splitlines()
    reported = []

    for url in urls:
        logging.info(f"🚀 Reporting: {url}")
        success = report_phishing(url)
        with open(log_file, "a") as log:
            if success:
                log.write(f"[✅ REPORTED] {url}\n")
                reported.append(url)
            else:
                log.write(f"[❌ FAILED] {url}\n")

    # Tandai status selesai
    Path(status_file).write_text("done")

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("Usage: python3 reporter.py <user_id>")
        sys.exit(1)

    main(sys.argv[1])