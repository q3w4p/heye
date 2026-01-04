import discord
from discord.ext import commands
import asyncio
import time
import sys
import random
import math
import aiohttp
import base64
import requests
import json
import re
import io
from typing import Optional

from fast_api import FastDiscordAPI
from ansi_formatter import ANSIFormatter
from config import Config
from task_manager import TaskManager


class SelfBot(commands.Bot):

    def __init__(self, token):
        self.config = Config()
        self.token = token

        intents = discord.Intents.all()
        super().__init__(command_prefix=self.get_prefix,
                         self_bot=True,
                         intents=intents)

        self.remove_command('help')

        self.api = None
        self.task_manager = TaskManager()
        self.start_time = time.time()
        self.autoreply_tasks = {}
        self.autoreact_users = {}
        self.messages_to_delete = {}

        self.is_rotating = False
        self.rotation_items = []
        self.rotation_task = None

        self.setup_events()
        self.load_commands()

    async def get_prefix(self, message):
        prefixes = self.config.data.get("prefixes", ["-", "."])
        return commands.when_mentioned_or(*prefixes)(self, message)

    def setup_events(self):

        @self.event
        async def on_ready():
            print(f"{ANSIFormatter.success('Logged in as')} {self.user}")
            self.api = FastDiscordAPI(self.token)
            await self.api.init()
            self.loop.create_task(self.task_manager.start())

            async def cleanup_messages():
                while True:
                    now = time.time()
                    for channel_id, messages in list(
                            self.messages_to_delete.items()):
                        for msg_id, delete_time in list(messages.items()):
                            if now >= delete_time:
                                try:
                                    await self.api.delete_message(
                                        channel_id, msg_id)
                                    del self.messages_to_delete[channel_id][
                                        msg_id]
                                except:
                                    pass
                            if not self.messages_to_delete[channel_id]:
                                del self.messages_to_delete[channel_id]
                    await asyncio.sleep(1)

            self.loop.create_task(cleanup_messages())

        @self.event
        async def on_connect():
            print(f"{ANSIFormatter.success('Connected to Discord')}")

    async def upload_n_get_asset_key(self, image_url: str):
        discord_cdn_pattern = r"https?://(?:cdn\.discordapp\.com|media\.discordapp\.net)/attachments/(\d+)/(\d+)/(.+)"
        match = re.search(discord_cdn_pattern, image_url)
        if match:
            channel_id, attachment_id, filename = match.groups()
            return f"mp:attachments/{channel_id}/{attachment_id}/{filename}"

        try:
            self_dm = self.user.dm_channel or await self.user.create_dm()
            async with aiohttp.ClientSession() as session:
                async with session.get(image_url) as r:
                    if r.status != 200:
                        return None
                    image_bytes = await r.read()
                    filename = image_url.split('/')[-1].split('?')[0]
                    if not '.' in filename or len(filename) > 50:
                        filename = "asset.gif" if 'gif' in r.headers.get(
                            'Content-Type', '') else "asset.png"
                    message = await self_dm.send(file=discord.File(
                        io.BytesIO(image_bytes), filename=filename))
                    if not message.attachments:
                        return None
                    new_url = message.attachments[0].url
                    new_match = re.search(discord_cdn_pattern, new_url)
                    if not new_match:
                        return None
                    cid, aid, fname = new_match.groups()
                    return f"mp:attachments/{cid}/{aid}/{fname}"
        except Exception as e:
            print(f"{ANSIFormatter.error('Asset upload error:')} {e}")
            return None

    async def universal_rotate(self):
        index = 0
        try:
            while self.is_rotating and self.rotation_items:
                item = self.rotation_items[index]
                activity = item.copy()

                if "image_url" in activity and activity["image_url"]:
                    asset_key = await self.upload_n_get_asset_key(
                        activity["image_url"])
                    if asset_key:
                        if "assets" not in activity:
                            activity["assets"] = {}
                        activity["assets"]["large_image"] = asset_key
                        activity["assets"]["large_text"] = activity.get(
                            "large_text", activity.get("name", "Activity"))
                    del activity["image_url"]
                elif "assets" in activity and "large_image" in activity[
                        "assets"]:
                    pass
                else:
                    if "assets" not in activity:
                        activity["assets"] = {}

                payload = {
                    "op": 3,
                    "d": {
                        "since": 0,
                        "activities": [activity],
                        "status": "online",
                        "afk": False
                    }
                }
                if hasattr(self, 'ws') and self.ws:
                    await self.ws.send(json.dumps(payload))

                index = (index + 1) % len(self.rotation_items)
                await asyncio.sleep(3)
        except asyncio.CancelledError:
            payload = {
                "op": 3,
                "d": {
                    "since": 0,
                    "activities": [],
                    "status": "online",
                    "afk": False
                }
            }
            if hasattr(self, 'ws') and self.ws:
                await self.ws.send(json.dumps(payload))
        except Exception as e:
            print(f"{ANSIFormatter.error('Rotation error:')} {e}")
            self.is_rotating = False

    async def send_spotify_with_spoofing(self,
                                         song_name,
                                         artist,
                                         album,
                                         duration_minutes,
                                         current_position_minutes=0,
                                         image_url=None):
        current_ms = int(current_position_minutes * 60 * 1000)
        total_ms = int(duration_minutes * 60 * 1000)
        start_time = int(time.time() * 1000) - current_ms
        end_time = start_time + (total_ms - current_ms)

        spotify_track_id = "0VjIjW4GlUZAMYd2vXMi3b"
        album_id = "4yP0hdKOZPNshxUOjY0cZj"
        artist_id = "1Xyo4u8uXC1ZmMpatF05PJ"

        activity = {
            "type": 2,
            "name": "Spotify",
            "details": song_name,
            "state": artist,
            "timestamps": {
                "start": start_time,
                "end": end_time
            },
            "application_id": "3201606009684",
            "sync_id": spotify_track_id,
            "session_id": f"spotify:{spotify_track_id}",
            "party": {
                "id": f"spotify:{spotify_track_id}",
                "size": [1, 1]
            },
            "secrets": {
                "join": f"spotify:{spotify_track_id}",
                "spectate": f"spotify:{spotify_track_id}",
                "match": f"spotify:{spotify_track_id}"
            },
            "instance": True,
            "flags": 48,
            "metadata": {
                "context_uri": f"spotify:album:{album_id}",
                "album_id": album_id,
                "artist_ids": [artist_id],
                "track_id": spotify_track_id,
            }
        }

        if image_url:
            asset_key = await self.upload_n_get_asset_key(image_url)
            if asset_key:
                activity["assets"] = {
                    "large_image": asset_key,
                    "large_text": f"{album} on Spotify"
                }
            else:
                activity["assets"] = {
                    "large_image": "spotify",
                    "large_text": f"{album} on Spotify"
                }
        else:
            activity["assets"] = {
                "large_image": "spotify",
                "large_text": f"{album} on Spotify"
            }

        payload = {
            "op": 3,
            "d": {
                "since": 0,
                "activities": [activity],
                "status": "online",
                "afk": False
            }
        }

        if hasattr(self, 'ws') and self.ws:
            await self.ws.send(json.dumps(payload))

    async def send_youtube_with_spoofing(self,
                                         video_title,
                                         channel_name,
                                         duration_minutes=10,
                                         current_position_minutes=0,
                                         image_url=None):
        current_ms = int(current_position_minutes * 60 * 1000)
        total_ms = int(duration_minutes * 60 * 1000)
        start_time = int(time.time() * 1000) - current_ms
        end_time = start_time + (total_ms - current_ms)

        activity = {
            "type": 3,
            "name": "YouTube",
            "details": video_title,
            "state": channel_name,
            "timestamps": {
                "start": start_time,
                "end": end_time
            },
            "application_id": "111299001912"
        }

        if image_url:
            asset_key = await self.upload_n_get_asset_key(image_url)
            if asset_key:
                activity["assets"] = {
                    "large_image": asset_key,
                    "large_text": f"{video_title} on YouTube"
                }
            else:
                activity["assets"] = {
                    "large_image": "youtube",
                    "large_text": f"{video_title} on YouTube"
                }
        else:
            activity["assets"] = {
                "large_image": "youtube",
                "large_text": f"{video_title} on YouTube"
            }

        payload = {
            "op": 3,
            "d": {
                "since": 0,
                "activities": [activity],
                "status": "online",
                "afk": False
            }
        }

        if hasattr(self, 'ws') and self.ws:
            await self.ws.send(json.dumps(payload))

    async def send_listening_activity(self,
                                      name,
                                      button_label=None,
                                      button_url=None,
                                      image_url=None):
        activity = {
            "type": 2,
            "name": "Custom",
            "details": name,
            "application_id": "534203414247112723",
            "flags": 0
        }

        if image_url:
            asset_key = await self.upload_n_get_asset_key(image_url)
            if asset_key:
                activity["assets"] = {
                    "large_image": asset_key,
                    "large_text": name
                }
            else:
                activity["assets"] = {
                    "large_image": "spotify",
                    "large_text": name
                }
        else:
            activity["assets"] = {"large_image": "spotify", "large_text": name}

        if button_label and button_url:
            activity["buttons"] = [button_label]
            activity["metadata"] = {"button_urls": [button_url]}

        payload = {
            "op": 3,
            "d": {
                "since": 0,
                "activities": [activity],
                "status": "online",
                "afk": False
            }
        }

        if hasattr(self, 'ws') and self.ws:
            await self.ws.send(json.dumps(payload))

    async def send_streaming_activity(self,
                                      name,
                                      button_label=None,
                                      button_url=None,
                                      image_url=None):
        activity = {
            "type": 1,
            "name": "Twitch",
            "details": name,
            "url": "https://twitch.tv/kaicenat",
            "application_id": "111299001912"
        }

        if image_url:
            asset_key = await self.upload_n_get_asset_key(image_url)
            if asset_key:
                activity["assets"] = {
                    "large_image": asset_key,
                    "large_text": name
                }
            else:
                activity["assets"] = {
                    "large_image": "youtube",
                    "large_text": name
                }
        else:
            activity["assets"] = {"large_image": "youtube", "large_text": name}

        if button_label and button_url:
            activity["buttons"] = [button_label]
            activity["metadata"] = {"button_urls": [button_url]}

        payload = {
            "op": 3,
            "d": {
                "since": 0,
                "activities": [activity],
                "status": "online",
                "afk": False
            }
        }

        if hasattr(self, 'ws') and self.ws:
            await self.ws.send(json.dumps(payload))

    async def send_playing_activity(self,
                                    name,
                                    button_label=None,
                                    button_url=None,
                                    image_url=None):
        activity = {
            "type": 0,
            "name": name,
            "application_id": "367827983903490050",
        }

        if image_url:
            asset_key = await self.upload_n_get_asset_key(image_url)
            if asset_key:
                activity["assets"] = {
                    "large_image": asset_key,
                    "large_text": name
                }
            else:
                activity["assets"] = {
                    "large_image": "game",
                    "large_text": name
                }
        else:
            activity["assets"] = {"large_image": "game", "large_text": name}

        if button_label and button_url:
            activity["buttons"] = [button_label]
            activity["metadata"] = {"button_urls": [button_url]}

        payload = {
            "op": 3,
            "d": {
                "since": 0,
                "activities": [activity],
                "status": "online",
                "afk": False
            }
        }

        if hasattr(self, 'ws') and self.ws:
            await self.ws.send(json.dumps(payload))

    async def send_with_delete(self, channel_id, content, delete_after=None):
        result = await self.api.send_message(channel_id, content)
        if result and 'id' in result and self.config.data["settings"][
                "auto_delete"]:
            msg_id = result['id']
            if channel_id not in self.messages_to_delete:
                self.messages_to_delete[channel_id] = {}

            delay = delete_after if delete_after is not None else self.config.data[
                "settings"]["delete_delay"]
            self.messages_to_delete[channel_id][msg_id] = time.time() + delay

        return result

    def load_commands(self):

        @self.command()
        async def autodelete(ctx, setting: str = None):
            if setting is None:
                current = self.config.data["settings"]["auto_delete"]
                status = "enabled" if current else "disabled"
                await self.send_with_delete(
                    ctx.channel.id,
                    f"```ansi\n{ANSIFormatter.info(f'Auto-delete is {status}')}\n```",
                    delete_after=10)
                return

            setting = setting.lower()
            if setting in ["on", "true", "enable", "yes"]:
                self.config.data["settings"]["auto_delete"] = True
                self.config.save()
                await self.send_with_delete(
                    ctx.channel.id,
                    f"```ansi\n{ANSIFormatter.success('Auto-delete enabled')}\n```",
                    delete_after=10)
            elif setting in ["off", "false", "disable", "no"]:
                self.config.data["settings"]["auto_delete"] = False
                self.config.save()
                await self.api.send_message(
                    ctx.channel.id,
                    f"```ansi\n{ANSIFormatter.success('Auto-delete disabled')}\n```"
                )
            else:
                await self.send_with_delete(
                    ctx.channel.id,
                    f"```ansi\n{ANSIFormatter.error('Usage: !autodelete on/off')}\n```",
                    delete_after=10)

        @self.command()
        async def rpc(ctx, rpc_type: str = None, *, args: str = None):
            if not rpc_type:
                help_text = f"""```ansi
{ANSIFormatter.header("RPC COMMANDS")}
{ANSIFormatter.cyan("Format for all types:")} !rpc <type> <name> - <state> - <details> | <image_url>
{ANSIFormatter.cyan("Spotify:")} !rpc spotify <song> - <artist> - <album> - <duration> | <image_url>
{ANSIFormatter.cyan("YouTube:")} !rpc youtube <video> - <channel> - <duration> | <image_url>
{ANSIFormatter.cyan("Listening:")} !rpc listening <name> - <state> - <details> | <image_url>
{ANSIFormatter.cyan("Streaming:")} !rpc streaming <name> - <state> - <details> | <image_url>
{ANSIFormatter.cyan("Playing:")} !rpc playing <name> - <state> - <details> | <image_url>
{ANSIFormatter.cyan("Watching:")} !rpc watching <name> - <state> - <details> | <image_url>
{ANSIFormatter.cyan("Competing:")} !rpc competing <name> - <state> - <details> | <image_url>
{ANSIFormatter.cyan("Rotation:")} !rpc <type> <item1, item2, item3> (comma separated)
{ANSIFormatter.cyan("Stop:")} !rpc stop
{ANSIFormatter.yellow("Add image:")} Add | <image_url> at end or attach image
{ANSIFormatter.yellow("Add button:")} Add >> <button_label> >> <button_url>
{ANSIFormatter.magenta("Examples:")}
!rpc playing Minecraft - In Survival - Level 100 | https://image.com/mc.png
!rpc streaming Just Chatting - Live on Twitch - Playing with viewers | attach image
!rpc spotify Stairway to Heaven - Led Zeppelin - IV - 8:02
!rpc youtube How to Code - Tech Channel - 15:30
```"""
                await self.send_with_delete(ctx.channel.id,
                                            help_text,
                                            delete_after=30)
                return

            rpc_type = rpc_type.lower()

            if rpc_type == "stop":
                if self.rotation_task:
                    self.is_rotating = False
                    self.rotation_task.cancel()
                    self.rotation_task = None
                    await self.send_with_delete(
                        ctx.channel.id,
                        "```ansi\n" +
                        ANSIFormatter.success("Rotation stopped") + "\n```",
                        delete_after=10)
                return

            if not args:
                await self.send_with_delete(
                    ctx.channel.id,
                    f"```ansi\n{ANSIFormatter.error('No arguments provided')}\n```",
                    delete_after=10)
                return

            image_url = None
            button_label = None
            button_url = None

            if ' | ' in args:
                parts = args.split(' | ', 1)
                args = parts[0].strip()
                image_part = parts[1].strip()

                if ctx.message.attachments and image_part == "attach":
                    image_url = ctx.message.attachments[0].url
                else:
                    image_url = image_part

            elif ctx.message.attachments:
                image_url = ctx.message.attachments[0].url

            if ' >> ' in args:
                btn_parts = args.split(' >> ')
                if len(btn_parts) >= 3:
                    args = btn_parts[0].strip()
                    button_label = btn_parts[1].strip()
                    button_url = btn_parts[2].strip()
                elif len(btn_parts) == 2:
                    args = btn_parts[0].strip()
                    button_label = btn_parts[1].strip()
                    button_url = "https://discord.com"

            if ',' in args:
                self.rotation_items = []
                items = [
                    item.strip() for item in args.split(',') if item.strip()
                ]

                for item in items:
                    item_image_url = None
                    text_part = item

                    if ' | ' in text_part:
                        parts = text_part.split(' | ', 1)
                        text_part = parts[0].strip()
                        item_image_url = parts[1].strip() if parts[1].strip(
                        ) else None

                    if rpc_type == "spotify":
                        parts = [p.strip() for p in text_part.split('-')]
                        if len(parts) >= 3:
                            song, artist, album = parts[0], parts[1], parts[2]
                            duration = float(
                                parts[3]) if len(parts) > 3 else 3.5
                            current_pos = float(
                                parts[4]) if len(parts) > 4 else 0

                            current_ms = int(current_pos * 60 * 1000)
                            total_ms = int(duration * 60 * 1000)
                            start_time = int(time.time() * 1000) - current_ms
                            end_time = start_time + (total_ms - current_ms)

                            spotify_track_id = "0VjIjW4GlUZAMYd2vXMi3b"
                            album_id = "4yP0hdKOZPNshxUOjY0cZj"
                            artist_id = "1Xyo4u8uXC1ZmMpatF05PJ"

                            activity = {
                                "type": 2,
                                "name": "Spotify",
                                "details": song,
                                "state": f"by {artist}",
                                "timestamps": {
                                    "start": start_time,
                                    "end": end_time
                                },
                                "application_id": "3201606009684",
                                "sync_id": spotify_track_id,
                                "session_id": f"spotify:{spotify_track_id}",
                                "party": {
                                    "id": f"spotify:{spotify_track_id}",
                                    "size": [1, 1]
                                },
                                "secrets": {
                                    "join": f"spotify:{spotify_track_id}",
                                    "spectate": f"spotify:{spotify_track_id}",
                                    "match": f"spotify:{spotify_track_id}"
                                },
                                "instance": True,
                                "flags": 48,
                                "metadata": {
                                    "context_uri": f"spotify:album:{album_id}",
                                    "album_id": album_id,
                                    "artist_ids": [artist_id],
                                    "track_id": spotify_track_id,
                                }
                            }

                            if item_image_url:
                                activity["image_url"] = item_image_url
                            else:
                                activity["assets"] = {
                                    "large_image": "spotify",
                                    "large_text": f"{album} on Spotify"
                                }

                            self.rotation_items.append(activity)

                    elif rpc_type == "youtube":
                        parts = [p.strip() for p in text_part.split('-')]
                        if len(parts) >= 3:
                            video, channel, duration = parts[0], parts[
                                1], float(parts[2])
                            current_pos = float(
                                parts[3]) if len(parts) > 3 else 0

                            current_ms = int(current_pos * 60 * 1000)
                            total_ms = int(duration * 60 * 1000)
                            start_time = int(time.time() * 1000) - current_ms
                            end_time = start_time + (total_ms - current_ms)

                            activity = {
                                "type": 3,
                                "name": "YouTube",
                                "details": video,
                                "state": channel,
                                "timestamps": {
                                    "start": start_time,
                                    "end": end_time
                                },
                                "application_id": "111299001912"
                            }

                            if item_image_url:
                                activity["image_url"] = item_image_url
                            else:
                                activity["assets"] = {
                                    "large_image": "youtube",
                                    "large_text": f"{video} on YouTube"
                                }

                            self.rotation_items.append(activity)

                    else:
                        parts = [p.strip() for p in text_part.split('-')]
                        name = parts[0] if len(parts) > 0 else "Activity"
                        state = parts[1] if len(parts) > 1 else None
                        details = parts[2] if len(parts) > 2 else None

                        activity_types = {
                            "streaming": 1,
                            "playing": 0,
                            "listening": 2,
                            "watching": 3,
                            "competing": 5
                        }

                        activity = {
                            "type": activity_types.get(rpc_type, 0),
                            "name": name,
                            "state": state,
                            "details": details,
                            "application_id": "367827983903490050"
                        }

                        if rpc_type == "streaming":
                            activity["url"] = "https://twitch.tv/streamer"
                            activity["name"] = "Twitch"
                            activity["application_id"] = "111299001912"

                        elif rpc_type == "listening":
                            activity["name"] = "Custom"
                            activity["application_id"] = "534203414247112723"

                        if item_image_url:
                            activity["image_url"] = item_image_url
                        else:
                            app_images = {
                                "streaming": "youtube",
                                "playing": "game",
                                "listening": "spotify",
                                "watching": "youtube",
                                "competing": "game"
                            }
                            activity["assets"] = {
                                "large_image":
                                app_images.get(rpc_type, "game"),
                                "large_text": name
                            }

                        self.rotation_items.append(activity)

                if self.rotation_task:
                    self.rotation_task.cancel()

                self.is_rotating = True
                self.rotation_task = self.loop.create_task(
                    self.universal_rotate())
                await self.send_with_delete(
                    ctx.channel.id,
                    f"```ansi\n{ANSIFormatter.success(f'{rpc_type.title()} Rotation: {len(self.rotation_items)} items')}\n```",
                    delete_after=10)

            else:
                if rpc_type == "spotify":
                    try:
                        parts = [part.strip() for part in args.split('-')]
                        if len(parts) >= 3:
                            song, artist, album = parts[0], parts[1], parts[2]
                            duration = float(
                                parts[3]) if len(parts) > 3 else 3.5
                            current_pos = float(
                                parts[4]) if len(parts) > 4 else 0

                            await self.send_spotify_with_spoofing(
                                song, artist, album, duration, current_pos,
                                image_url)
                            await self.send_with_delete(
                                ctx.channel.id,
                                f"```ansi\n{ANSIFormatter.success(f'Spotify: {song} by {artist}')}\n```",
                                delete_after=10)
                        else:
                            await self.send_with_delete(
                                ctx.channel.id,
                                f"```ansi\n{ANSIFormatter.error('Need: Song - Artist - Album - Duration')}\n```",
                                delete_after=10)
                    except Exception as e:
                        await self.send_with_delete(
                            ctx.channel.id,
                            f"```ansi\n{ANSIFormatter.error(f'Error: {e}')}\n```",
                            delete_after=10)

                elif rpc_type == "youtube":
                    try:
                        parts = [part.strip() for part in args.split('-')]
                        if len(parts) >= 3:
                            video, channel, duration = parts[0], parts[
                                1], float(parts[2])
                            current_pos = float(
                                parts[3]) if len(parts) > 3 else 0

                            await self.send_youtube_with_spoofing(
                                video, channel, duration, current_pos,
                                image_url)
                            await self.send_with_delete(
                                ctx.channel.id,
                                f"```ansi\n{ANSIFormatter.success(f'YouTube: {video}')}\n```",
                                delete_after=10)
                        else:
                            await self.send_with_delete(
                                ctx.channel.id,
                                f"```ansi\n{ANSIFormatter.error('Need: Video - Channel - Duration')}\n```",
                                delete_after=10)
                    except Exception as e:
                        await self.send_with_delete(
                            ctx.channel.id,
                            f"```ansi\n{ANSIFormatter.error(f'Error: {e}')}\n```",
                            delete_after=10)

                else:
                    parts = [part.strip() for part in args.split('-')]
                    name = parts[0] if len(parts) > 0 else "Activity"
                    state = parts[1] if len(parts) > 1 else ""
                    details = parts[2] if len(parts) > 2 else ""

                    if rpc_type == "playing":
                        await self.send_playing_activity(
                            name, button_label, button_url, image_url)
                        if state or details:
                            activity = {
                                "type": 0,
                                "name": name,
                                "state": state,
                                "details": details,
                                "application_id": "367827983903490050"
                            }

                            if image_url:
                                asset_key = await self.upload_n_get_asset_key(
                                    image_url)
                                if asset_key:
                                    activity["assets"] = {
                                        "large_image": asset_key,
                                        "large_text": name
                                    }
                                else:
                                    activity["assets"] = {
                                        "large_image": "game",
                                        "large_text": name
                                    }
                            else:
                                activity["assets"] = {
                                    "large_image": "game",
                                    "large_text": name
                                }

                            if button_label and button_url:
                                activity["buttons"] = [button_label]
                                activity["metadata"] = {
                                    "button_urls": [button_url]
                                }

                            payload = {
                                "op": 3,
                                "d": {
                                    "since": 0,
                                    "activities": [activity],
                                    "status": "online",
                                    "afk": False
                                }
                            }

                            if hasattr(self, 'ws') and self.ws:
                                await self.ws.send(json.dumps(payload))

                    elif rpc_type == "streaming":
                        await self.send_streaming_activity(
                            name, button_label, button_url, image_url)
                        if state or details:
                            activity = {
                                "type": 1,
                                "name": "Twitch",
                                "details": name,
                                "state": state,
                                "url": "https://twitch.tv/streamer",
                                "application_id": "111299001912"
                            }

                            if image_url:
                                asset_key = await self.upload_n_get_asset_key(
                                    image_url)
                                if asset_key:
                                    activity["assets"] = {
                                        "large_image": asset_key,
                                        "large_text": details or name
                                    }
                                else:
                                    activity["assets"] = {
                                        "large_image": "youtube",
                                        "large_text": details or name
                                    }
                            else:
                                activity["assets"] = {
                                    "large_image": "youtube",
                                    "large_text": details or name
                                }

                            if button_label and button_url:
                                activity["buttons"] = [button_label]
                                activity["metadata"] = {
                                    "button_urls": [button_url]
                                }

                            payload = {
                                "op": 3,
                                "d": {
                                    "since": 0,
                                    "activities": [activity],
                                    "status": "online",
                                    "afk": False
                                }
                            }

                            if hasattr(self, 'ws') and self.ws:
                                await self.ws.send(json.dumps(payload))

                    elif rpc_type == "listening":
                        await self.send_listening_activity(
                            name, button_label, button_url, image_url)
                        if state or details:
                            activity = {
                                "type": 2,
                                "name": "Custom",
                                "details": name,
                                "state": state,
                                "application_id": "534203414247112723"
                            }

                            if image_url:
                                asset_key = await self.upload_n_get_asset_key(
                                    image_url)
                                if asset_key:
                                    activity["assets"] = {
                                        "large_image": asset_key,
                                        "large_text": details or name
                                    }
                                else:
                                    activity["assets"] = {
                                        "large_image": "spotify",
                                        "large_text": details or name
                                    }
                            else:
                                activity["assets"] = {
                                    "large_image": "spotify",
                                    "large_text": details or name
                                }

                            if button_label and button_url:
                                activity["buttons"] = [button_label]
                                activity["metadata"] = {
                                    "button_urls": [button_url]
                                }

                            payload = {
                                "op": 3,
                                "d": {
                                    "since": 0,
                                    "activities": [activity],
                                    "status": "online",
                                    "afk": False
                                }
                            }

                            if hasattr(self, 'ws') and self.ws:
                                await self.ws.send(json.dumps(payload))

                    elif rpc_type in ["watching", "competing"]:
                        activity_type = 3 if rpc_type == "watching" else 5
                        activity = {
                            "type": activity_type,
                            "name": name,
                            "state": state,
                            "details": details,
                            "application_id": "367827983903490050"
                        }

                        if image_url:
                            asset_key = await self.upload_n_get_asset_key(
                                image_url)
                            if asset_key:
                                activity["assets"] = {
                                    "large_image": asset_key,
                                    "large_text": name
                                }
                            else:
                                activity["assets"] = {
                                    "large_image": "game",
                                    "large_text": name
                                }
                        else:
                            activity["assets"] = {
                                "large_image": "game",
                                "large_text": name
                            }

                        if button_label and button_url:
                            activity["buttons"] = [button_label]
                            activity["metadata"] = {
                                "button_urls": [button_url]
                            }

                        payload = {
                            "op": 3,
                            "d": {
                                "since": 0,
                                "activities": [activity],
                                "status": "online",
                                "afk": False
                            }
                        }

                        if hasattr(self, 'ws') and self.ws:
                            await self.ws.send(json.dumps(payload))

                    btn_text = f" + Button: {button_label}" if button_label else ""
                    img_text = f" + Image" if image_url else ""
                    details_text = f" - Details: {details}" if details else ""
                    state_text = f" - State: {state}" if state else ""

                    await self.send_with_delete(
                        ctx.channel.id,
                        f"```ansi\n{ANSIFormatter.success(f'{rpc_type.title()}: {name}{state_text}{details_text}{btn_text}{img_text}')}\n```",
                        delete_after=10)

        @self.command()
        async def ar(ctx,
                     user: discord.User = None,
                     *,
                     custom_msg: str = None):
            if not user or not custom_msg:
                await self.send_with_delete(
                    ctx.channel.id,
                    f"```ansi\n{ANSIFormatter.error('Usage: !ar @user <message>')}\n```",
                    delete_after=10)
                return

            channel_id = ctx.channel.id
            await self.send_with_delete(
                channel_id,
                f"```ansi\n{ANSIFormatter.success(f'Autoreply for {user.name} started')}\n```",
                delete_after=10)

            async def send_autoreply(message):
                while True:
                    try:
                        await self.api.send_message(message.channel.id,
                                                    custom_msg)
                        print(
                            f"{ANSIFormatter.success('Replied to')} {user.name}"
                        )
                        break
                    except discord.errors.HTTPException as e:
                        if e.status == 429:
                            try:
                                retry_after = e.response.json().get(
                                    'retry_after', 1)
                            except:
                                retry_after = 1
                            print(
                                f"{ANSIFormatter.warning('Rate limited, waiting')} {retry_after}s"
                            )
                            await asyncio.sleep(retry_after)
                        else:
                            print(f"{ANSIFormatter.error('HTTP Error:')} {e}")
                            await asyncio.sleep(1)
                    except Exception as e:
                        print(f"{ANSIFormatter.error('Error:')} {e}")
                        await asyncio.sleep(1)

            async def reply_loop():

                def check(m):
                    return m.author == user and m.channel == ctx.channel

                while True:
                    try:
                        message = await self.wait_for('message', check=check)
                        asyncio.create_task(send_autoreply(message))
                        await asyncio.sleep(0.1)
                    except Exception as e:
                        print(f"{ANSIFormatter.error('Loop error:')} {e}")
                        await asyncio.sleep(1)

            task = self.loop.create_task(reply_loop())
            self.autoreply_tasks[(user.id, channel_id)] = task

        @self.command()
        async def arend(ctx):
            channel_id = ctx.channel.id
            tasks_to_stop = [
                key for key in self.autoreply_tasks.keys()
                if key[1] == channel_id
            ]

            if tasks_to_stop:
                for user_id in tasks_to_stop:
                    task = self.autoreply_tasks.pop(user_id)
                    task.cancel()
                await self.send_with_delete(
                    channel_id,
                    f"```ansi\n{ANSIFormatter.success('Autoreply stopped')}\n```",
                    delete_after=10)
            else:
                await self.send_with_delete(
                    channel_id,
                    f"```ansi\n{ANSIFormatter.error('No active autoreply')}\n```",
                    delete_after=10)

        @self.command()
        async def autoreact(ctx, user: discord.User = None, emoji: str = None):
            if not user or not emoji:
                await self.send_with_delete(
                    ctx.channel.id,
                    f"```ansi\n{ANSIFormatter.error('Usage: !autoreact @user :emoji:')}\n```",
                    delete_after=10)
                return

            self.autoreact_users[user.id] = emoji
            await self.send_with_delete(
                ctx.channel.id,
                f"```ansi\n{ANSIFormatter.success(f'Autoreact {emoji} to {user.name}')}\n```",
                delete_after=10)

        @self.command()
        async def autoreactoff(ctx, user: discord.User = None):
            if not user:
                await self.send_with_delete(
                    ctx.channel.id,
                    f"```ansi\n{ANSIFormatter.error('Usage: !autoreactoff @user')}\n```",
                    delete_after=10)
                return

            if user.id in self.autoreact_users:
                del self.autoreact_users[user.id]
                await self.send_with_delete(
                    ctx.channel.id,
                    f"```ansi\n{ANSIFormatter.success(f'Autoreact off for {user.name}')}\n```",
                    delete_after=10)
            else:
                await self.send_with_delete(
                    ctx.channel.id,
                    f"```ansi\n{ANSIFormatter.error('No autoreact for user')}\n```",
                    delete_after=10)

        @self.command(aliases=['si'])
        async def serverinfo(ctx):
            if not ctx.guild:
                await self.send_with_delete(
                    ctx.channel.id,
                    f"```ansi\n{ANSIFormatter.error('Not in a server')}\n```",
                    delete_after=10)
                return

            server = ctx.guild

            owner = server.owner
            owner_field = f"{owner.name} (ID: {owner.id})"

            creation_date = server.created_at
            formatted_creation = f"{creation_date.strftime('%Y-%m-%d')}"

            total_members = server.member_count
            humans = sum(1 for member in server.members if not member.bot)
            bots = total_members - humans

            text_channels = len([
                c for c in server.channels
                if isinstance(c, discord.TextChannel)
            ])
            voice_channels = len([
                c for c in server.channels
                if isinstance(c, discord.VoiceChannel)
            ])
            categories = len([
                c for c in server.channels
                if isinstance(c, discord.CategoryChannel)
            ])

            total_roles = len(server.roles)
            total_emojis = len(server.emojis)
            total_boosters = server.premium_subscription_count

            verification = server.verification_level
            boost_level = server.premium_tier

            response = f"""```ansi
{ANSIFormatter.header("SERVER INFO")}
{ANSIFormatter.cyan("Name:")} {ANSIFormatter.white(server.name)}
{ANSIFormatter.cyan("ID:")} {ANSIFormatter.white(server.id)}
{ANSIFormatter.cyan("Created:")} {ANSIFormatter.white(formatted_creation)}
{ANSIFormatter.cyan("Owner:")} {ANSIFormatter.white(owner_field)}

{ANSIFormatter.cyan("Members:")} {ANSIFormatter.white(f"{total_members} total")}
{ANSIFormatter.cyan("Humans:")} {ANSIFormatter.white(f"{humans}")}
{ANSIFormatter.cyan("Bots:")} {ANSIFormatter.white(f"{bots}")}

{ANSIFormatter.cyan("Channels:")} {ANSIFormatter.white(f"Text: {text_channels}")}
{ANSIFormatter.cyan("Voice:")} {ANSIFormatter.white(f"{voice_channels}")}
{ANSIFormatter.cyan("Categories:")} {ANSIFormatter.white(f"{categories}")}

{ANSIFormatter.cyan("Stats:")} {ANSIFormatter.white(f"Roles: {total_roles}")}
{ANSIFormatter.cyan("Emojis:")} {ANSIFormatter.white(f"{total_emojis}")}
{ANSIFormatter.cyan("Boosts:")} {ANSIFormatter.white(f"{total_boosters} (Tier {boost_level})")}

{ANSIFormatter.cyan("Verification:")} {ANSIFormatter.white(f"{verification}")}
```"""

            await self.api.send_message(ctx.channel.id, response)

        @self.command(aliases=['ui', 'whois'])
        async def userinfo(ctx, user: discord.User = None):
            if not user:
                await self.send_with_delete(
                    ctx.channel.id,
                    f"```ansi\n{ANSIFormatter.error('Usage: !userinfo @user')}\n```",
                    delete_after=10)
                return

            try:
                creation_date = user.created_at
                formatted_creation = f"{creation_date.strftime('%Y-%m-%d')}"

                avatar_format = "gif" if user.is_avatar_animated() else "png"
                avatar_url = str(
                    user.avatar_url_as(format=avatar_format,
                                       size=1024)) if user.avatar else None

                headers = {"Authorization": f"{self.http.token}"}
                banner_url = None
                bio = "N/A"
                pronouns = "N/A"
                displayname = user.name

                try:
                    r = requests.get(
                        f"https://discord.com/api/v9/users/{user.id}/profile",
                        headers=headers,
                        timeout=5)

                    if r.status_code == 200:
                        profile_data = r.json()

                        banner_hash = profile_data.get("user",
                                                       {}).get("banner")
                        if banner_hash:
                            banner_format = "gif" if banner_hash.startswith(
                                "a_") else "png"
                            banner_url = f"https://cdn.discordapp.com/banners/{user.id}/{banner_hash}.{banner_format}?size=1024"

                        bio = profile_data.get("user_profile", {}).get(
                            "bio", "N/A") or "N/A"
                        pronouns = profile_data.get("user_profile", {}).get(
                            "pronouns", "N/A") or "N/A"
                        displayname = profile_data.get(
                            "user", {}).get("global_name") or user.name
                except:
                    pass

                response = f"""```ansi
{ANSIFormatter.header("USER INFO")}
{ANSIFormatter.red("User Name:")} {ANSIFormatter.white(user.name)}
{ANSIFormatter.red("Display Name:")} {ANSIFormatter.white(displayname)}
{ANSIFormatter.red("User ID:")} {ANSIFormatter.white(user.id)}
{ANSIFormatter.blue("Created On:")} {ANSIFormatter.white(formatted_creation)}
{ANSIFormatter.yellow("Pronouns:")} {ANSIFormatter.white(pronouns)}
{ANSIFormatter.green("Bio:")} {ANSIFormatter.white(bio[:100] + "..." if len(bio) > 100 else bio)}
```"""

                await self.api.send_message(ctx.channel.id, response)

                if avatar_url:
                    await self.api.send_message(ctx.channel.id, avatar_url)
                if banner_url:
                    await self.api.send_message(ctx.channel.id, banner_url)

            except Exception as e:
                await self.send_with_delete(
                    ctx.channel.id,
                    f"```ansi\n{ANSIFormatter.error(f'Error: {e}')}\n```",
                    delete_after=10)

        @self.command()
        async def stealpfp(ctx, user: discord.User = None):
            if not user:
                await self.send_with_delete(
                    ctx.channel.id,
                    f"```ansi\n{ANSIFormatter.error('Usage: !stealpfp @user')}\n```",
                    delete_after=10)
                return

            avatar_format = "gif" if user.is_avatar_animated() else "png"
            url = str(user.avatar_url_as(format=avatar_format, size=1024))

            if not url:
                await self.send_with_delete(
                    ctx.channel.id,
                    f"```ansi\n{ANSIFormatter.error('User has no avatar')}\n```",
                    delete_after=10)
                return

            try:
                headers = await self.api.spoofer.get_headers()

                async with aiohttp.ClientSession() as session:
                    async with session.get(url) as r:
                        if r.status == 200:
                            image_data = await r.read()
                            image_b64 = base64.b64encode(image_data).decode()

                            result = await self.api.request(
                                "PATCH",
                                "/users/@me",
                                json={
                                    "avatar":
                                    f"data:image/{avatar_format};base64,{image_b64}"
                                })

                            if result:
                                await self.send_with_delete(
                                    ctx.channel.id,
                                    f"```ansi\n{ANSIFormatter.success(f'Stole PFP from {user.name}')}\n```",
                                    delete_after=10)
                            else:
                                await self.send_with_delete(
                                    ctx.channel.id,
                                    f"```ansi\n{ANSIFormatter.error('Failed to update PFP')}\n```",
                                    delete_after=10)
                        else:
                            await self.send_with_delete(
                                ctx.channel.id,
                                f"```ansi\n{ANSIFormatter.error('Failed to download avatar')}\n```",
                                delete_after=10)
            except Exception as e:
                await self.send_with_delete(
                    ctx.channel.id,
                    f"```ansi\n{ANSIFormatter.error(f'Error: {e}')}\n```",
                    delete_after=10)

        @self.command()
        async def setbanner(ctx, url: str = None):
            if not url and not ctx.message.attachments:
                await self.send_with_delete(
                    ctx.channel.id,
                    f"```ansi\n{ANSIFormatter.error('Usage: !setbanner <url> or attach an image')}\n```",
                    delete_after=10)
                return

            image_url = url
            if not url and ctx.message.attachments:
                image_url = ctx.message.attachments[0].url

            try:
                headers = await self.api.spoofer.get_headers()

                async with aiohttp.ClientSession() as session:
                    async with session.get(image_url) as r:
                        if r.status == 200:
                            image_data = await r.read()
                            image_b64 = base64.b64encode(image_data).decode()
                            content_type = r.headers.get('Content-Type', '')

                            if 'gif' in content_type:
                                image_format = 'gif'
                            else:
                                image_format = 'png'

                            result = await self.api.request(
                                "PATCH",
                                "/users/@me",
                                json={
                                    "banner":
                                    f"data:image/{image_format};base64,{image_b64}"
                                })

                            if result:
                                await self.send_with_delete(
                                    ctx.channel.id,
                                    f"```ansi\n{ANSIFormatter.success('Banner set successfully')}\n```",
                                    delete_after=10)
                            else:
                                await self.send_with_delete(
                                    ctx.channel.id,
                                    f"```ansi\n{ANSIFormatter.error('Failed to set banner')}\n```",
                                    delete_after=10)
                        else:
                            await self.send_with_delete(
                                ctx.channel.id,
                                f"```ansi\n{ANSIFormatter.error('Failed to download image')}\n```",
                                delete_after=10)
            except Exception as e:
                await self.send_with_delete(
                    ctx.channel.id,
                    f"```ansi\n{ANSIFormatter.error(f'Error: {e}')}\n```",
                    delete_after=10)

        @self.command()
        async def setpfp(ctx, url: str = None):
            if not url and not ctx.message.attachments:
                await self.send_with_delete(
                    ctx.channel.id,
                    f"```ansi\n{ANSIFormatter.error('Usage: !setpfp <url> or attach an image')}\n```",
                    delete_after=10)
                return

            image_url = url
            if not url and ctx.message.attachments:
                image_url = ctx.message.attachments[0].url

            try:
                headers = await self.api.spoofer.get_headers()

                async with aiohttp.ClientSession() as session:
                    async with session.get(image_url) as r:
                        if r.status == 200:
                            image_data = await r.read()
                            image_b64 = base64.b64encode(image_data).decode()
                            content_type = r.headers.get('Content-Type', '')

                            if 'gif' in content_type:
                                image_format = 'gif'
                            else:
                                image_format = 'png'

                            result = await self.api.request(
                                "PATCH",
                                "/users/@me",
                                json={
                                    "avatar":
                                    f"data:image/{image_format};base64,{image_b64}"
                                })

                            if result:
                                await self.send_with_delete(
                                    ctx.channel.id,
                                    f"```ansi\n{ANSIFormatter.success('Profile picture set successfully')}\n```",
                                    delete_after=10)
                            else:
                                await self.send_with_delete(
                                    ctx.channel.id,
                                    f"```ansi\n{ANSIFormatter.error('Failed to set profile picture')}\n```",
                                    delete_after=10)
                        else:
                            await self.send_with_delete(
                                ctx.channel.id,
                                f"```ansi\n{ANSIFormatter.error('Failed to download image')}\n```",
                                delete_after=10)
            except Exception as e:
                await self.send_with_delete(
                    ctx.channel.id,
                    f"```ansi\n{ANSIFormatter.error(f'Error: {e}')}\n```",
                    delete_after=10)

        @self.event
        async def on_message(message):
            if message.author.id == self.user.id:
                prefixes = self.config.data.get("prefixes", ["-", "."])
                if self.config.data["settings"]["auto_delete"] and any(
                        message.content.startswith(prefix)
                        for prefix in prefixes):
                    delete_time = time.time(
                    ) + self.config.data["settings"]["delete_delay"]
                    if message.channel.id not in self.messages_to_delete:
                        self.messages_to_delete[message.channel.id] = {}
                    self.messages_to_delete[message.channel.id][
                        message.id] = delete_time

            if message.author.id in self.autoreact_users:
                try:
                    emoji = self.autoreact_users[message.author.id]
                    await self.api.add_reaction(message.channel.id, message.id,
                                                emoji)
                except:
                    pass

            if message.author.bot:
                return

            ctx = await self.get_context(message)
            await self.invoke(ctx)

        categories = {
            "automation": "ar arend autoreact autoreactoff",
            "server": "serverinfo",
            "user": "userinfo stealpfp setbanner setpfp",
            "rpc": "rpc",
            "config": "autodelete",
            "help": "help"
        }

        @self.command(name="help")
        async def help_cmd(ctx, category: str = None):
            prefixes = self.config.data.get("prefixes", ["-", "."])
            prefix = prefixes[0] if prefixes else "!"

            if category:
                category = category.lower()
                if category in categories:
                    commands_list = categories[category].split()
                    commands_desc = "\n".join(
                        [f"{prefix}{cmd}" for cmd in commands_list])
                    await self.api.send_message(
                        ctx.channel.id,
                        f"```ansi\n{ANSIFormatter.header(category.upper())}\n\n{commands_desc}\n```"
                    )
                else:
                    available = ", ".join(sorted(categories.keys()))
                    await self.send_with_delete(
                        ctx.channel.id,
                        f"```ansi\n{ANSIFormatter.error(f'Invalid category. Available: {available}')}\n```",
                        delete_after=10)
            else:
                categories_text = "\n".join([
                    f"{cat} - {len(cmds.split())} commands"
                    for cat, cmds in categories.items()
                ])
                await self.api.send_message(
                    ctx.channel.id,
                    f"```ansi\n{ANSIFormatter.header('CATEGORIES')}\n\n{categories_text}\n\n{ANSIFormatter.yellow(f'Use {prefix}help <category> for commands')}\n```"
                )


def main():
    if len(sys.argv) < 2:
        token = input("Token: ")
    else:
        token = sys.argv[1]

    bot = SelfBot(token)

    try:
        bot.run(token, bot=False)
    except KeyboardInterrupt:
        print(f"{ANSIFormatter.warning('Shutting down...')}")
    except Exception as e:
        print(f"{ANSIFormatter.error(f'Error: {e}')}")


if __name__ == "__main__":
    main()
