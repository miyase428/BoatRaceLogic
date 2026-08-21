#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""リポジトリ直下の .env からDB接続設定を読む共通処理。"""

from __future__ import annotations

from pathlib import Path

ROOT_DIR = Path(__file__).resolve().parent.parent
ENV_PATH = ROOT_DIR / ".env"


def _load_env_file() -> dict[str, str]:
    if not ENV_PATH.is_file():
        raise RuntimeError(f".env が見つかりません: {ENV_PATH}")

    values: dict[str, str] = {}
    with ENV_PATH.open("r", encoding="utf-8") as fh:
        for raw_line in fh:
            line = raw_line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            key, value = line.split("=", 1)
            values[key.strip()] = value.strip()

    return values


def load_db_config() -> dict[str, object]:
    env = _load_env_file()

    required = ["DB_HOST", "DB_NAME", "DB_USER", "DB_PASSWORD"]
    missing = [key for key in required if not env.get(key, "").strip()]
    if missing:
        raise RuntimeError(".env の必須項目が未設定です: " + ", ".join(missing))

    port_text = env.get("DB_PORT", "5432").strip() or "5432"
    try:
        port = int(port_text)
    except ValueError as exc:
        raise RuntimeError(".env の DB_PORT が不正です") from exc

    return {
        "host": env["DB_HOST"].strip(),
        "port": port,
        "dbname": env["DB_NAME"].strip(),
        "user": env["DB_USER"].strip(),
        "password": env["DB_PASSWORD"],
    }
