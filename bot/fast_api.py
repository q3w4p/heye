# fast_api.py
import asyncio
import time
from typing import Optional, Dict, Any, List
from connection_manager import ConnectionManager
from header_spoofer import HeaderSpoofer, BrowserProfile
from rate_handler import RateLimitHandler
import logging

logger = logging.getLogger(__name__)


class FastDiscordAPI:

    def __init__(self, token: str, profile: Optional[BrowserProfile] = None):
        self.token = token
        self.conn = ConnectionManager()
        self.spoofer = HeaderSpoofer(token, profile)
        self.rate_handler = RateLimitHandler()
        self._initialized = False

    async def init(self):
        if not self._initialized:
            await self.conn.init_session()
            await self.spoofer.fetch_fingerprint()
            self._initialized = True

    async def request(self, method: str, endpoint: str,
                      **kwargs) -> Optional[Dict]:
        await self.rate_handler.wait_for_rate_limit(endpoint)

        headers = await self.spoofer.get_headers()
        headers.update(kwargs.pop('headers', {}))

        url = f"https://discord.com/api/v9{endpoint}"

        try:
            response = await self.conn.request(method,
                                               url,
                                               headers=headers,
                                               **kwargs)

            if isinstance(response, dict) and 'headers' in response:
                self.rate_handler.update_from_response(endpoint,
                                                       response['headers'])
                return response.get('data')

            return response
        except Exception as e:
            logger.error(f"Request failed for {endpoint}: {e}")
            return None

    async def send_message(self, channel_id: int, content: str,
                           **kwargs) -> Optional[Dict]:
        payload = {"content": content, "flags": 0, **kwargs}
        return await self.request("POST",
                                  f"/channels/{channel_id}/messages",
                                  json=payload)

    async def edit_message(self, channel_id: int, message_id: int,
                           content: str, **kwargs) -> Optional[Dict]:
        payload = {"content": content, **kwargs}
        return await self.request(
            "PATCH",
            f"/channels/{channel_id}/messages/{message_id}",
            json=payload)

    async def delete_message(self, channel_id: int,
                             message_id: int) -> Optional[Dict]:
        return await self.request(
            "DELETE", f"/channels/{channel_id}/messages/{message_id}")

    async def add_reaction(self, channel_id: int, message_id: int,
                           emoji: str) -> Optional[Dict]:
        if ":" in emoji:
            emoji = emoji.split(":")[-1].replace(">", "")
        emoji_encoded = emoji
        return await self.request(
            "PUT",
            f"/channels/{channel_id}/messages/{message_id}/reactions/{emoji_encoded}/@me"
        )

    async def get_messages(self,
                           channel_id: int,
                           limit: int = 100,
                           before: Optional[str] = None) -> List[Dict]:
        params = {"limit": limit}
        if before:
            params["before"] = before

        messages = await self.request("GET",
                                      f"/channels/{channel_id}/messages",
                                      params=params)
        return messages if isinstance(messages, list) else []

    async def purge_messages(self, channel_id: int, limit: int = 100) -> int:
        messages = await self.get_messages(channel_id, limit)
        if not messages:
            return 0

        message_ids = [
            msg['id'] for msg in messages
            if time.time() - (int(msg['id']) >> 22) / 1000 < 1209600
        ]

        if len(message_ids) == 1:
            await self.delete_message(channel_id, message_ids[0])
        elif message_ids:
            await self.request("POST",
                               f"/channels/{channel_id}/messages/bulk-delete",
                               json={"messages": message_ids})

        return len(message_ids)

    async def get_channel(self, channel_id: int) -> Optional[Dict]:
        return await self.request("GET", f"/channels/{channel_id}")

    async def close(self):
        await self.conn.close()
        self._initialized = False
