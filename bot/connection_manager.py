# connection_manager.py
import aiohttp
import ssl
import certifi
import time
from typing import Dict, Any, Optional
import asyncio
from aiohttp import ClientTimeout, TCPConnector
import logging

logger = logging.getLogger(__name__)


class ConnectionManager:

    def __init__(self, max_connections: int = 100, timeout: int = 30):
        self.session: Optional[aiohttp.ClientSession] = None
        self.ssl_context: Optional[ssl.SSLContext] = None
        self.request_timestamps = []
        self.max_connections = max_connections
        self.timeout = timeout
        self._session_lock = asyncio.Lock()

    async def init_session(self):
        async with self._session_lock:
            if self.session and not self.session.closed:
                return

            self.ssl_context = ssl.create_default_context(
                cafile=certifi.where())
            self.ssl_context.set_ciphers(
                "ECDHE+AESGCM:ECDHE+CHACHA20:DHE+AESGCM:DHE+CHACHA20")
            self.ssl_context.minimum_version = ssl.TLSVersion.TLSv1_2

            connector = TCPConnector(ssl=self.ssl_context,
                                     limit=self.max_connections,
                                     ttl_dns_cache=300,
                                     enable_cleanup_closed=True,
                                     force_close=False,
                                     keepalive_timeout=30)

            timeout = ClientTimeout(total=self.timeout,
                                    connect=10,
                                    sock_read=15,
                                    sock_connect=10)

            self.session = aiohttp.ClientSession(
                connector=connector,
                timeout=timeout,
                cookie_jar=aiohttp.DummyCookieJar())

    async def _enforce_rate_limit(self):
        now = time.time()
        self.request_timestamps = [
            ts for ts in self.request_timestamps if now - ts < 1.0
        ]

        if len(self.request_timestamps) >= 50:
            oldest = self.request_timestamps[0]
            wait_time = max(0.0, oldest + 1.0 - now)
            if wait_time > 0:
                await asyncio.sleep(wait_time)

    async def request(self,
                      method: str,
                      url: str,
                      headers: Dict[str, str] = None,
                      **kwargs) -> Any:
        if not self.session or self.session.closed:
            await self.init_session()

        await self._enforce_rate_limit()
        self.request_timestamps.append(time.time())

        max_retries = 3
        for attempt in range(max_retries):
            try:
                async with self.session.request(method,
                                                url,
                                                headers=headers,
                                                **kwargs) as resp:
                    response_data = {
                        'status': resp.status,
                        'headers': dict(resp.headers),
                        'data': None
                    }

                    if resp.status == 429:
                        retry_after = float(resp.headers.get('retry-after', 1))
                        logger.warning(
                            f"Rate limited, retrying after {retry_after}s")
                        await asyncio.sleep(retry_after)
                        continue

                    if resp.content_type == 'application/json':
                        response_data['data'] = await resp.json()
                    else:
                        response_data['data'] = await resp.text()

                    if 200 <= resp.status < 300:
                        return response_data
                    else:
                        logger.error(
                            f"HTTP {resp.status} for {url}: {response_data.get('data')}"
                        )

                        if resp.status >= 500 and attempt < max_retries - 1:
                            await asyncio.sleep(2**attempt)
                            continue

                        return response_data

            except asyncio.TimeoutError:
                logger.warning(f"Timeout on attempt {attempt + 1} for {url}")
                if attempt == max_retries - 1:
                    return {'status': 408, 'headers': {}, 'data': 'Timeout'}
                await asyncio.sleep(1)
            except Exception as e:
                logger.error(f"Request failed: {e}", exc_info=True)
                if attempt == max_retries - 1:
                    return {'status': 0, 'headers': {}, 'data': None}
                await asyncio.sleep(1)

    async def close(self):
        async with self._session_lock:
            if self.session and not self.session.closed:
                await self.session.close()
                self.session = None
