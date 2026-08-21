#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""Web表示用 SUMマスタ stats_*.json を日次で事前更新する。

public/sum_api.php は stats_場.json が当日更新でない場合、最初のWebアクセス時に
new_sam.py を実行して再生成する。その待ち時間をWeb表示から外すため、既存cronの中で
同じ再生成を先に済ませる。

Usage:
  python3 analysis/update_sum_master_stats.py
  python3 analysis/update_sum_master_stats.py SME OMR

指定なしでは features.json に定義された全場を対象にする。
当日すでに更新済みのファイルは SKIP する。
"""

from __future__ import annotations

import json
import subprocess
import sys
import time
from datetime import datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SUM_DIR = ROOT / "theories" / "new_sam"
FEATURES_PATH = SUM_DIR / "features.json"
SCRIPT_PATH = SUM_DIR / "new_sam.py"


def is_updated_today(path: Path, today) -> bool:
    if not path.is_file():
        return False
    return datetime.fromtimestamp(path.stat().st_mtime).date() >= today


def main() -> int:
    if not FEATURES_PATH.is_file() or not SCRIPT_PATH.is_file():
        print("features.json または new_sam.py が見つかりません", file=sys.stderr)
        return 1

    features = json.loads(FEATURES_PATH.read_text(encoding="utf-8"))
    all_places = sorted(str(k).upper() for k in features.keys())

    requested = [arg.strip().upper() for arg in sys.argv[1:] if arg.strip()]
    places = requested or all_places

    unknown = [p for p in places if p not in features]
    if unknown:
        print("features.json にない場コード: " + ", ".join(unknown), file=sys.stderr)
        return 1

    today = datetime.now().date()
    total_t0 = time.perf_counter()
    rebuilt = 0
    skipped = 0
    failed = 0

    print("=" * 92)
    print("Web表示用 SUMマスタ 日次事前更新")
    print("=" * 92)
    print("対象: " + ", ".join(places))
    print("-" * 92)

    for place in places:
        stats_path = SUM_DIR / f"stats_{place}.json"
        if is_updated_today(stats_path, today):
            skipped += 1
            print(f"{place}: SKIP  当日更新済み")
            continue

        t0 = time.perf_counter()
        proc = subprocess.run(
            ["/usr/bin/python3", str(SCRIPT_PATH), place],
            cwd=str(SUM_DIR),
            stdout=subprocess.DEVNULL,
            stderr=subprocess.PIPE,
            text=True,
        )
        sec = time.perf_counter() - t0

        if proc.returncode == 0 and stats_path.is_file() and is_updated_today(stats_path, today):
            rebuilt += 1
            print(f"{place}: REBUILD {sec:6.2f}s")
        else:
            failed += 1
            err = (proc.stderr or "").strip().replace("\n", " | ")
            print(f"{place}: ERROR   {sec:6.2f}s / return={proc.returncode} / {err}")

    total_sec = time.perf_counter() - total_t0
    print("-" * 92)
    print(f"REBUILD={rebuilt} / SKIP={skipped} / ERROR={failed}")
    print(f"総時間: {total_sec:.2f} sec")
    print("=" * 92)

    return 0 if failed == 0 else 2


if __name__ == "__main__":
    raise SystemExit(main())
