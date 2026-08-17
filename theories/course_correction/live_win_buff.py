#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""Webの補正後1着率用: 対象日前180日のスリットwin buffを生成してJSON返却する。

STEP8-3/8-4の検証と同じ定義を使う。
- predicted PID: C_ST_RANK / プロフィール不足時 A_EX_FALLBACK
- PID×展示進入コースのwin lift
- K=40 shrink
- cap=±0.08

同一日では学習範囲が同じなので /tmp に日付単位キャッシュする。
"""

from __future__ import annotations

import json
import sys
from datetime import datetime, timedelta
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
ANALYSIS_DIR = REPO_ROOT / "analysis"
THEORY_DIR = Path(__file__).resolve().parent
sys.path.insert(0, str(ANALYSIS_DIR))
sys.path.insert(0, str(THEORY_DIR))

from base_winrate_slit_compare import (  # noqa: E402
    SLIT_BUFF_DAYS,
    build_slit_records,
    inclusive_window_start,
    learn_buff,
)

CACHE_VERSION = "v1"


def stringify_keys(obj):
    if isinstance(obj, dict):
        return {str(k): stringify_keys(v) for k, v in obj.items()}
    if isinstance(obj, list):
        return [stringify_keys(v) for v in obj]
    return obj


def main():
    if len(sys.argv) != 2:
        print(json.dumps({"error": "Usage: live_win_buff.py YYYY-MM-DD"}, ensure_ascii=False))
        return 1

    try:
        target_date = datetime.strptime(sys.argv[1], "%Y-%m-%d").date()
    except ValueError:
        print(json.dumps({"error": "日付形式が不正です"}, ensure_ascii=False))
        return 1

    cache_path = Path("/tmp") / f"boatrace_slit_win_buff_{CACHE_VERSION}_{target_date:%Y%m%d}.json"
    if cache_path.exists():
        try:
            cached = json.loads(cache_path.read_text(encoding="utf-8"))
            if cached.get("target_date") == target_date.isoformat() and cached.get("buff"):
                print(json.dumps(cached, ensure_ascii=False))
                return 0
        except Exception:
            pass

    buff_end = target_date - timedelta(days=1)
    buff_start = inclusive_window_start(buff_end, SLIT_BUFF_DAYS)

    try:
        records, skip, _ = build_slit_records(buff_start, buff_end)
        buff, rows, freq = learn_buff(records, buff_start, buff_end)
        payload = {
            "target_date": target_date.isoformat(),
            "training_start": buff_start.isoformat(),
            "training_end": buff_end.isoformat(),
            "training_races": len(rows),
            "k": 40,
            "cap": 0.08,
            "buff": stringify_keys(buff),
            "pid_counts": {str(pid): int(freq[pid]) for pid in range(1, 13)},
            "skip": {str(k): int(v) for k, v in skip.items()},
        }
        text = json.dumps(payload, ensure_ascii=False)
        try:
            cache_path.write_text(text, encoding="utf-8")
        except Exception:
            pass
        print(text)
        return 0
    except Exception as exc:
        print(json.dumps({"error": str(exc)}, ensure_ascii=False))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
