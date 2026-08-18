#!/usr/bin/env python3
import argparse
import json
import math
import shutil
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
THEORY_DIR = REPO_ROOT / "theories" / "course_correction"
CANDIDATE = THEORY_DIR / "buff_debuff_slit_candidate.json"
META = THEORY_DIR / "buff_debuff_slit_candidate_meta.json"
PRODUCTION = THEORY_DIR / "buff_debuff_slit.json"
BACKUP = THEORY_DIR / "buff_debuff_slit.pre_c_st_rank_backup.json"

METRICS = ("win", "place2", "place3", "trio")
ACTIVE = ("win", "place2", "trio")
INACTIVE = ("place3",)
MAX_CAP = 0.08
EPS = 1e-12


def load_json(path: Path):
    if not path.exists():
        raise RuntimeError(f"ファイルがありません: {path}")
    with path.open(encoding="utf-8") as f:
        return json.load(f)


def as_finite_number(value, label):
    if not isinstance(value, (int, float)) or isinstance(value, bool):
        raise RuntimeError(f"数値ではありません: {label}={value!r}")
    value = float(value)
    if not math.isfinite(value):
        raise RuntimeError(f"有限値ではありません: {label}={value!r}")
    return value


def validate_candidate(buff, meta):
    expected_pids = {str(i) for i in range(1, 13)}
    if set(buff.keys()) != expected_pids:
        raise RuntimeError(
            f"PIDキーが不正です: actual={sorted(buff.keys())} expected={sorted(expected_pids)}"
        )

    max_abs = 0.0
    inactive_nonzero = []
    cell_count = 0

    for pid in range(1, 13):
        p = buff[str(pid)]
        expected_courses = {str(i) for i in range(1, 7)}
        if set(p.keys()) != expected_courses:
            raise RuntimeError(f"PID{pid} のcourseキーが不正です: {sorted(p.keys())}")

        for course in range(1, 7):
            cell = p[str(course)]
            if set(cell.keys()) != set(METRICS):
                raise RuntimeError(
                    f"PID{pid} {course}C のmetricキーが不正です: {sorted(cell.keys())}"
                )

            for metric in METRICS:
                value = as_finite_number(cell[metric], f"PID{pid}/{course}C/{metric}")
                if metric in ACTIVE:
                    max_abs = max(max_abs, abs(value))
                    if abs(value) > MAX_CAP + EPS:
                        raise RuntimeError(
                            f"cap超過: PID{pid} {course}C {metric}={value:.12f}"
                        )
                elif abs(value) > EPS:
                    inactive_nonzero.append((pid, course, metric, value))
            cell_count += 1

    if inactive_nonzero:
        sample = inactive_nonzero[:5]
        raise RuntimeError(f"place3に非0値があります: {sample}")

    if meta.get("prediction_method") != "C_ST_RANK":
        raise RuntimeError(
            f"meta prediction_method が不正です: {meta.get('prediction_method')!r}"
        )

    active_metrics = set(meta.get("active_metrics", []))
    inactive_metrics = set(meta.get("inactive_metrics", []))
    if active_metrics != {"win", "place2", "trio"}:
        raise RuntimeError(f"meta active_metrics が不正です: {active_metrics}")
    if inactive_metrics != {"place3"}:
        raise RuntimeError(f"meta inactive_metrics が不正です: {inactive_metrics}")

    k = as_finite_number(meta.get("k"), "meta.k")
    cap = as_finite_number(meta.get("max_cap"), "meta.max_cap")
    if abs(k - 40.0) > EPS:
        raise RuntimeError(f"meta.k が40ではありません: {k}")
    if abs(cap - MAX_CAP) > EPS:
        raise RuntimeError(f"meta.max_cap が0.08ではありません: {cap}")

    processed = int(meta.get("processed_races", 0))
    if processed <= 0:
        raise RuntimeError(f"meta.processed_races が不正です: {processed}")

    pid_counts = meta.get("pid_counts", {})
    if set(pid_counts.keys()) != expected_pids:
        raise RuntimeError("meta.pid_counts のPIDキーが不正です")

    return {
        "cells": cell_count,
        "max_abs": max_abs,
        "processed_races": processed,
        "training_start": meta.get("training_start"),
        "training_end": meta.get("training_end"),
        "pid_counts": pid_counts,
    }


def promote():
    if not PRODUCTION.exists():
        raise RuntimeError(f"現在の本番JSONがありません: {PRODUCTION}")

    # 旧本番を1回だけ固定名で保存。すでにあれば上書きしない。
    if not BACKUP.exists():
        shutil.copy2(PRODUCTION, BACKUP)
        print(f"旧本番backup: {BACKUP}")
    else:
        print(f"旧本番backup: 既存を保持 {BACKUP}")

    tmp = PRODUCTION.with_suffix(".json.tmp")
    shutil.copy2(CANDIDATE, tmp)
    tmp.replace(PRODUCTION)
    print(f"本番へ昇格   : {PRODUCTION}")


def main():
    parser = argparse.ArgumentParser(
        description="C_ST_RANK用スリットbuff候補を検証し、安全に本番へ昇格する"
    )
    parser.add_argument(
        "--apply",
        action="store_true",
        help="検証通過後にcandidateをbuff_debuff_slit.jsonへ昇格する",
    )
    args = parser.parse_args()

    buff = load_json(CANDIDATE)
    meta = load_json(META)
    result = validate_candidate(buff, meta)

    print("=" * 100)
    print("スリット buff candidate 昇格前チェック")
    print("=" * 100)
    print(f"candidate    : {CANDIDATE}")
    print(f"meta         : {META}")
    print(f"学習期間     : {result['training_start']} ～ {result['training_end']}")
    print(f"処理レース   : {result['processed_races']}")
    print(f"検証セル     : {result['cells']} (12PID × 6course)")
    print(f"active max   : {result['max_abs']:.6f} (cap={MAX_CAP:.2f})")
    print("active       : win / place2 / trio")
    print("place3       : 全セル 0 確認（未採用）")
    print("prediction   : C_ST_RANK")
    print("K            : 40")
    print("\n【PID件数】")
    for pid in range(1, 13):
        print(f"PID{pid:>2}: {int(result['pid_counts'][str(pid)]):>6}")

    if args.apply:
        print("\n検証OK。--apply 指定ありのため本番へ昇格します。")
        promote()
        print("昇格完了。curlで slit_api.php のbuff_debuffを確認してください。")
    else:
        print("\n検証OK。本番JSONは未変更です。")
        print("昇格する場合: python3 analysis/slit_buff_promote.py --apply")

    print("=" * 100)


if __name__ == "__main__":
    main()
