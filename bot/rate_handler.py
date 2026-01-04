# rate_handler.py
import time
import asyncio
from collections import defaultdict, deque
from typing import Dict, Optional
from dataclasses import dataclass
import logging

logger = logging.getLogger(__name__)


@dataclass
class RateLimit:
    limit: int
    remaining: int
    reset: float
    window: float


class RateLimitHandler:

    def __init__(self, default_limit: int = 5, default_window: float = 1.0):
        self.buckets = defaultdict(lambda: deque(maxlen=default_limit))
        self.limits: Dict[str, RateLimit] = {}
        self.global_limited = False
        self.global_reset = 0
        self.locks = defaultdict(asyncio.Lock)
        self.default_window = default_window
        self.default_limit = default_limit
        self._global_lock = asyncio.Lock()

    def check_rate_limit(self, endpoint: str) -> float:
        now = time.time()

        if self.global_limited and now < self.global_reset:
            return self.global_reset - now

        if endpoint in self.limits:
            limit = self.limits[endpoint]
            if limit.remaining <= 0 and now < limit.reset:
                return limit.reset - now

        bucket = self.buckets[endpoint]
        while bucket and now - bucket[0] > self.default_window:
            bucket.popleft()

        if len(bucket) >= self.default_limit:
            return max(0.0, bucket[0] + self.default_window - now)

        bucket.append(now)
        return 0.0

    async def wait_for_rate_limit(self, endpoint: str):
        async with self.locks[endpoint]:
            while True:
                delay = self.check_rate_limit(endpoint)
                if delay <= 0:
                    break
                await asyncio.sleep(min(delay, 0.1))

    def update_from_response(self, endpoint: str, headers: Dict):
        now = time.time()

        remaining = headers.get("x-ratelimit-remaining")
        limit = headers.get("x-ratelimit-limit")
        reset = headers.get("x-ratelimit-reset")
        reset_after = headers.get("x-ratelimit-reset-after")

        if remaining is not None and limit is not None:
            reset_time = now + float(reset_after) if reset_after else float(
                reset)
            self.limits[endpoint] = RateLimit(
                limit=int(limit),
                remaining=int(remaining),
                reset=reset_time,
                window=float(reset_after)
                if reset_after else self.default_window)

        if remaining == "0":
            self.buckets[endpoint].clear()
            window = float(reset_after) if reset_after else self.default_window
            for _ in range(int(limit)):
                self.buckets[endpoint].append(reset_time - window)

        if "x-ratelimit-global" in headers:

            async def set_global_limit():
                async with self._global_lock:
                    self.global_limited = True
                    if "retry-after" in headers:
                        self.global_reset = now + float(headers["retry-after"])
                    else:
                        self.global_reset = now + 1.0

            asyncio.create_task(set_global_limit())

    def clear_bucket(self, endpoint: str):
        if endpoint in self.buckets:
            self.buckets[endpoint].clear()

    def get_bucket_state(self, endpoint: str) -> Dict:
        return {
            'requests': list(self.buckets[endpoint]),
            'limit': self.limits.get(endpoint),
            'global_blocked': self.global_limited,
            'global_reset': self.global_reset
        }
