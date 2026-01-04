# config.py
import json
import os
from typing import Any, Dict, List, Optional


class Config:

    def __init__(self, path: str = "config.json"):
        self.path = path
        self.data = {
            "token": "",
            "prefixes": ["-", "."],
            "owners": [],
            "blacklist": [],
            "settings": {
                "auto_delete": True,
                "delete_delay": 10,
                "stealth_mode": False
            }
        }
        self.load()

    def load(self):
        if os.path.exists(self.path):
            try:
                with open(self.path, 'r', encoding='utf-8') as f:
                    loaded = json.load(f)
                    if isinstance(loaded, dict):
                        for key in self.data:
                            if key in loaded:
                                if isinstance(self.data[key],
                                              dict) and isinstance(
                                                  loaded[key], dict):
                                    self.data[key].update(loaded[key])
                                else:
                                    self.data[key] = loaded[key]
            except:
                pass

    def save(self):
        with open(self.path, 'w', encoding='utf-8') as f:
            json.dump(self.data, f, indent=2)

    def get(self, key: str, default: Any = None) -> Any:
        keys = key.split('.')
        value = self.data
        for k in keys:
            if isinstance(value, dict) and k in value:
                value = value[k]
            else:
                return default
        return value

    def set(self, key: str, value: Any):
        keys = key.split('.')
        target = self.data
        for k in keys[:-1]:
            if k not in target:
                target[k] = {}
            target = target[k]
        target[keys[-1]] = value
        self.save()
