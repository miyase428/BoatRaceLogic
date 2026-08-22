#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
⑤⑥本命時のkimarite頭補正で使う凍結モデル設定を生成する。

学習データは、完全未使用ホールドアウト検証前に凍結した
kimarite_analysis_dataset_20250815_20260814.csv のみを許可する。

学習仕様:
- 正式分析対象のみ
  result_top3_course_complete=1 / result_boat_match=1
- 現行Web honmei_head != 1
- 2C: 6か月差し率
- 3C/4C: 6か月攻め率（まくり + まくり差し）
- sample_n >= 10
- 帯: 0-5 / 5-10 / 10-15 / 15-20 / 20-25 / 25+
- 各帯 N>=100 の時だけ帯別1着率を採用。未満はコース基礎率へフォールバック。

出力:
  config/kimarite_head_model.php

Usage:
  python3 analysis/generate_kimarite_head_model_config.py \
    analysis/output/kimarite_analysis_dataset_20250815_20260814.csv
"""

from __future__ import annotations

import csv
import sys
from collections import defaultdict
from datetime import datetime, timezone
from pathlib import Path

EXPECTED_INPUT = "kimarite_analysis_dataset_20250815_20260814.csv"
COURSES = (2, 3, 4)
MIN_SAMPLE = 10
MIN_BAND_N = 100
BAND_ORDER = ("0-5", "5-10", "10-15", "15-20", "20-25", "25+")
MODEL_VERSION = "honmei56-kimarite-v1-20260814"


def to_int(v, default=0):
    try:
        return int(float(v))
    except (TypeError, ValueError):
        return default


def to_float(v, default=0.0):
    try:
        return float(v)
    except (TypeError, ValueError):
        return default


def formal(row):
    return (
        to_int(row.get("result_top3_course_complete")) == 1
        and to_int(row.get("result_boat_match")) == 1
    )


def feature(row, course):
    if course == 2:
        return to_float(row.get("c2_6m_sashi"))
    return (
        to_float(row.get(f"c{course}_6m_makuri"))
        + to_float(row.get(f"c{course}_6m_makurizashi"))
    )


def band(value):
    if value < 5:
        return "0-5"
    if value < 10:
        return "5-10"
    if value < 15:
        return "10-15"
    if value < 20:
        return "15-20"
    if value < 25:
        return "20-25"
    return "25+"


def php_str(value: str) -> str:
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"


def main():
    if len(sys.argv) != 2:
        print(f"Usage: python3 {sys.argv[0]} {EXPECTED_INPUT}", file=sys.stderr)
        sys.exit(1)

    src = Path(sys.argv[1]).resolve()
    if not src.is_file():
        raise RuntimeError(f"CSVがありません: {src}")
    if src.name != EXPECTED_INPUT:
        raise RuntimeError(
            "凍結モデルの再学習元は " + EXPECTED_INPUT + " のみ許可しています: " + src.name
        )

    with src.open("r", encoding="utf-8-sig", newline="") as f:
        rows = list(csv.DictReader(f))

    base = {c: [0, 0] for c in COURSES}
    bands = {c: defaultdict(lambda: [0, 0]) for c in COURSES}
    train_rows = 0
    dates = []

    for row in rows:
        if not formal(row):
            continue
        if to_int(row.get("honmei_head")) == 1:
            continue

        train_rows += 1
        race_date = (row.get("race_date") or "").strip()
        if race_date:
            dates.append(race_date)

        winner = to_int(row.get("actual_1st_course"))
        for course in COURSES:
            sample_n = to_int(row.get(f"c{course}_6m_sample_n"))
            if sample_n < MIN_SAMPLE:
                continue

            hit = 1 if winner == course else 0
            base[course][0] += hit
            base[course][1] += 1
            b = band(feature(row, course))
            bands[course][b][0] += hit
            bands[course][b][1] += 1

    if train_rows <= 0:
        raise RuntimeError("学習対象がありません")

    output = Path(__file__).resolve().parents[1] / "config" / "kimarite_head_model.php"
    output.parent.mkdir(parents=True, exist_ok=True)

    generated_at = datetime.now(timezone.utc).isoformat(timespec="seconds")
    start_date = min(dates) if dates else "2025-08-15"
    end_date = max(dates) if dates else "2026-08-14"

    lines = [
        "<?php",
        "// このファイルは analysis/generate_kimarite_head_model_config.py で生成した凍結モデルです。",
        "// 2026-08-15以降の評価結果を使って再調整しないこと。",
        "return [",
        f"    'version' => {php_str(MODEL_VERSION)},",
        f"    'train_start' => {php_str(start_date)},",
        f"    'train_end' => {php_str(end_date)},",
        f"    'train_rows' => {train_rows},",
        f"    'min_sample' => {MIN_SAMPLE},",
        f"    'min_band_n' => {MIN_BAND_N},",
        f"    'generated_at_utc' => {php_str(generated_at)},",
        "    'courses' => [",
    ]

    for course in COURSES:
        hits, n = base[course]
        base_p = hits / n if n else 0.0
        feature_name = "sashi" if course == 2 else "attack"
        lines.extend([
            f"        {course} => [",
            f"            'feature' => {php_str(feature_name)},",
            f"            'base_hits' => {hits},",
            f"            'base_n' => {n},",
            f"            'base_p' => {base_p:.12f},",
            "            'bands' => [",
        ])

        for b in BAND_ORDER:
            bh, bn = bands[course].get(b, (0, 0))
            if bn >= MIN_BAND_N:
                bp = bh / bn if bn else 0.0
                lines.append(
                    f"                {php_str(b)} => ['hits' => {bh}, 'n' => {bn}, 'p' => {bp:.12f}],"
                )

        lines.extend([
            "            ],",
            "        ],",
        ])

    lines.extend([
        "    ],",
        "];",
        "",
    ])

    output.write_text("\n".join(lines), encoding="utf-8")

    print("=" * 88)
    print("⑤⑥本命 kimarite頭補正 凍結モデル設定生成完了")
    print("=" * 88)
    print(f"学習元       : {src}")
    print(f"学習対象     : {train_rows}")
    print(f"学習期間     : {start_date} ～ {end_date}")
    print(f"モデルversion: {MODEL_VERSION}")
    print(f"出力         : {output}")
    print("")

    for course in COURSES:
        hits, n = base[course]
        print(f"【{course}C】 base={hits}/{n} ({(100.0 * hits / n if n else 0.0):.2f}%)")
        for b in BAND_ORDER:
            bh, bn = bands[course].get(b, (0, 0))
            mark = "採用" if bn >= MIN_BAND_N else "baseへfallback"
            rate = 100.0 * bh / bn if bn else 0.0
            print(f"  {b:<5} N={bn:>6}  1着率={rate:>6.2f}%  {mark}")
    print("=" * 88)


if __name__ == "__main__":
    main()
