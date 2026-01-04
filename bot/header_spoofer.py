# header_spoofer.py
import json
import base64
import time
import random
import aiohttp
from typing import Tuple, Dict, Optional
from dataclasses import dataclass
import logging
import asyncio

logger = logging.getLogger(__name__)


@dataclass
class BrowserProfile:
    user_agent: str
    os: str
    browser: str
    browser_version: str
    os_version: str
    locale: str
    timezone: str


class HeaderSpoofer:

    def __init__(self, token: str, profile: Optional[BrowserProfile] = None):
        self.token = token
        self.fingerprint = ""
        self.cookies = ""
        self.cache_time = 0
        self.profile = profile or self._generate_profile()
        self._fingerprint_lock = asyncio.Lock()

    @staticmethod
    def _generate_profile() -> BrowserProfile:
        browsers = [
            ("Chrome", "120.0.0.0",
             "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"),
            ("Firefox", "121.0",
             "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0)"),
            ("Edge", "120.0.0.0",
             "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36")
        ]

        browser, version, base_ua = random.choice(browsers)

        return BrowserProfile(
            user_agent=
            f"{base_ua} ({'Gecko' if browser == 'Firefox' else 'KHTML, like Gecko'}) {browser}/{version} Safari/537.36",
            os="Windows",
            browser=browser,
            browser_version=version,
            os_version="10",
            locale="en-US",
            timezone=random.choice([
                "America/New_York", "America/Chicago", "America/Los_Angeles",
                "Europe/London"
            ]))

    async def fetch_fingerprint(self) -> Tuple[str, str]:
        async with self._fingerprint_lock:
            if time.time() - self.cache_time < 7200 and self.fingerprint:
                return self.fingerprint, self.cookies

            try:
                async with aiohttp.ClientSession() as session:
                    async with session.get(
                            "https://discord.com/api/v9/experiments",
                            headers={"User-Agent": self.profile.user_agent},
                            timeout=aiohttp.ClientTimeout(total=10)) as resp:
                        if resp.status == 200:
                            data = await resp.json()
                            self.fingerprint = data.get(
                                "fingerprint",
                                self._generate_fallback_fingerprint())

                            cookie_list = []
                            for key, morsel in resp.cookies.items():
                                cookie_list.append(f"{key}={morsel.value}")
                            self.cookies = "; ".join(
                                cookie_list) or "__dcfduid=1; __sdcfduid=1"

                            self.cache_time = time.time()
                        else:
                            self.fingerprint = self._generate_fallback_fingerprint(
                            )
                            self.cookies = "__dcfduid=1; __sdcfduid=1"
            except Exception as e:
                logger.warning(f"Failed to fetch fingerprint: {e}")
                self.fingerprint = self._generate_fallback_fingerprint()
                self.cookies = "__dcfduid=1; __sdcfduid=1"

            return self.fingerprint, self.cookies

    def _generate_fallback_fingerprint(self) -> str:
        return f"{random.randint(100000000000000000, 999999999999999999)}.{random.randint(100000000000000000, 999999999999999999)}"

    def generate_super_properties(self) -> str:
        props = {
            "os": self.profile.os,
            "browser": self.profile.browser,
            "device": "",
            "system_locale": self.profile.locale,
            "browser_user_agent": self.profile.user_agent,
            "browser_version": self.profile.browser_version,
            "os_version": self.profile.os_version,
            "referrer": "",
            "referring_domain": "",
            "release_channel": "stable",
            "client_build_number":
            int(self.profile.browser_version.split('.')[0]),
            "client_event_source": None
        }
        return base64.b64encode(
            json.dumps(props, separators=(',', ':')).encode()).decode()

    async def get_headers(self) -> Dict[str, str]:
        fingerprint, cookies = await self.fetch_fingerprint()

        chrome_version = self.profile.browser_version.split('.')[0]
        sec_ch_ua = f'"Google Chrome";v="{chrome_version}", "Chromium";v="{chrome_version}", "Not?A_Brand";v="99"'

        if self.profile.browser == "Firefox":
            sec_ch_ua = f'"Firefox";v="{chrome_version}"'
        elif self.profile.browser == "Edge":
            sec_ch_ua = f'"Microsoft Edge";v="{chrome_version}", "Chromium";v="{chrome_version}", "Not?A_Brand";v="99"'

        return {
            "Authorization": self.token,
            "User-Agent": self.profile.user_agent,
            "Content-Type": "application/json",
            "Accept": "*/*",
            "Accept-Language": "en-US,en;q=0.9",
            "Accept-Encoding": "gzip, deflate, br",
            "Origin": "https://discord.com",
            "Referer": "https://discord.com/channels/@me",
            "Sec-Ch-Ua": sec_ch_ua,
            "Sec-Ch-Ua-Mobile": "?0",
            "Sec-Ch-Ua-Platform": f'"{self.profile.os}"',
            "Sec-Fetch-Dest": "empty",
            "Sec-Fetch-Mode": "cors",
            "Sec-Fetch-Site": "same-origin",
            "X-Debug-Options": "bugReporterEnabled",
            "X-Discord-Locale": self.profile.locale,
            "X-Discord-Timezone": self.profile.timezone,
            "X-Super-Properties": self.generate_super_properties(),
            "X-Fingerprint": fingerprint,
            "Cookie": cookies
        }
