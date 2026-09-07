#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
指定期間について、日別の
  analysis/compare_payout_signal_fixed12_three_date.php
を実行し、固定3方式を同条件で集計比較する。

比較方式
- FIX2_S3_T2 : AI3非インTop2頭 × 2着Top3 × 条件付き3着Top2
- FIX2_S2_T3 : AI3非インTop2頭 × 2着Top2 × 条件付き3着Top3
- FIX2_S2_T2 : AI3非インTop2頭 × 2着Top2 × 条件付き3着Top2

注意
- 過去期間の参考集計用。未使用前方検証には数えない。
- 各日の判定は既存PHPの現在再構築条件をそのまま利用する。
- 本命/対抗/PredictionLogic/PayoutSignalClassifierは変更しない。

Usage:
  python3 analysis/compare_payout_signal_fixed12_three_range.py 2026-09-01 2026-09-06
"""

from __future__ import annotations

import re
import subprocess
import sys
from dataclasses import dataclass, field
from datetime import date, datetime, timedelta
from pathlib import Path

METHODS = ("FIX2_S3_T2", "FIX2_S2_T3", "FIX2_S2_T2")


@dataclass
class MethodAgg:
    points: int = 0
    hits: int = 0
    invest100: float = 0.0
    return100: float = 0.0
    return1000: float = 0.0
    pair_hits: int = 0
    only_hits: int = 0


@dataclass
class DaySummary:
    day: str
    n: int
    in_fail: int
    head_hit: int
    methods: dict[str, MethodAgg] = field(default_factory=dict)


def parse_day(text: str, day: str) -> DaySummary:
    valid_m = re.search(r"有効候補\s*:\s*(\d+)R", text)
    fail_m = re.search(r"1C敗戦\s*:\s*(\d+)R\s*/\s*(\d+)R", text)
    head_m = re.search(r"1C敗戦時頭\s*:\s*(\d+)R\s*/\s*(\d+)R", text)
    if not valid_m or not fail_m or not head_m:
        raise RuntimeError(f"{day}: サマリーを解析できません")

    n = int(valid_m.group(1))
    in_fail = int(fail_m.group(1))
    head_hit = int(head_m.group(1))
    methods = {m: MethodAgg() for m in METHODS}

    # 各レース行を解析。例:
    # びわこ  1R 2,3  2-3-1/5,760  YES  12点○  12点○  8点○
    race_re = re.compile(
        r"^\s*\S+\s+\d+R\s+\S+\s+"
        r"(?P<actual>[1-6]-[1-6]-[1-6])/(?P<payout>[\d,]+)\s+"
        r"(?P<fail>YES|NO)\s+"
        r"(?P<p1>\d+)点(?P<h1>[○×])\s+"
        r"(?P<p2>\d+)点(?P<h2>[○×])\s+"
        r"(?P<p3>\d+)点(?P<h3>[○×])\s*$"
    )

    rows = 0
    hit_sets: list[set[str]] = []
    for line in text.splitlines():
        m = race_re.match(line)
        if not m:
            continue
        rows += 1
        payout = int(m.group("payout").replace(",", ""))
        hit_methods: set[str] = set()
        for idx, method in enumerate(METHODS, start=1):
            pts = int(m.group(f"p{idx}"))
            hit = m.group(f"h{idx}") == "○"
            agg = methods[method]
            agg.points += pts
            agg.invest100 += pts * 100.0
            if hit:
                agg.hits += 1
                agg.return100 += payout
                if pts > 0:
                    agg.return1000 += payout * (1000.0 / pts) / 100.0
                hit_methods.add(method)
        hit_sets.append(hit_methods)

    if rows != n:
        raise RuntimeError(f"{day}: レース行 {rows}R を解析、サマリーは {n}R")

    # 頭+2着捕捉は日別サマリーの百分率から整数件数を復元する。
    table_re = re.compile(
        r"^(FIX2_S3_T2|FIX2_S2_T3|FIX2_S2_T2)\s+"
        r"[\d.]+\s+\d+/\d+\s+[\d.]+%\s+[\d.]+%\s+([\d.]+)%",
        re.MULTILINE,
    )
    for m in table_re.finditer(text):
        method = m.group(1)
        pair_pct = float(m.group(2))
        methods[method].pair_hits = round(n * pair_pct / 100.0)

    # その方式だけ的中した件数も記録。
    for hs in hit_sets:
        if len(hs) == 1:
            methods[next(iter(hs))].only_hits += 1

    return DaySummary(day=day, n=n, in_fail=in_fail, head_hit=head_hit, methods=methods)


def daterange(start: date, end: date):
    cur = start
    while cur <= end:
        yield cur
        cur += timedelta(days=1)


def pct(n: int, d: int) -> float:
    return 100.0 * n / d if d else 0.0


def main() -> None:
    if len(sys.argv) != 3:
        print("Usage: python3 analysis/compare_payout_signal_fixed12_three_range.py YYYY-MM-DD YYYY-MM-DD")
        sys.exit(1)

    try:
        start = datetime.strptime(sys.argv[1], "%Y-%m-%d").date()
        end = datetime.strptime(sys.argv[2], "%Y-%m-%d").date()
    except ValueError:
        print("日付は YYYY-MM-DD 形式で指定してください")
        sys.exit(1)
    if end < start:
        print("終了日は開始日以降にしてください")
        sys.exit(1)

    root = Path(__file__).resolve().parent.parent
    daily = root / "analysis" / "compare_payout_signal_fixed12_three_date.php"
    if not daily.is_file():
        print(f"日別比較スクリプトが見つかりません: {daily}")
        sys.exit(1)

    days: list[DaySummary] = []
    for d in daterange(start, end):
        ds = d.isoformat()
        print(f"[{ds}] 3方式の日別比較を実行中...", flush=True)
        proc = subprocess.run(
            ["php", str(daily), ds],
            cwd=root,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
        )
        if proc.returncode != 0:
            print(proc.stderr)
            raise SystemExit(f"{ds}: 日別比較に失敗しました")
        days.append(parse_day(proc.stdout, ds))

    total_n = sum(d.n for d in days)
    total_fail = sum(d.in_fail for d in days)
    total_head = sum(d.head_hit for d in days)

    totals = {m: MethodAgg() for m in METHODS}
    for d in days:
        for method in METHODS:
            src = d.methods[method]
            dst = totals[method]
            dst.points += src.points
            dst.hits += src.hits
            dst.invest100 += src.invest100
            dst.return100 += src.return100
            dst.return1000 += src.return1000
            dst.pair_hits += src.pair_hits
            dst.only_hits += src.only_hits

    print("=" * 132)
    print("イン崩壊：固定3方式 期間比較")
    print(f"期間       : {start.isoformat()} ～ {end.isoformat()}")
    print("位置づけ   : 既観察期間の参考比較。未使用前方検証には数えない")
    print("方式       : FIX2_S3_T2 / FIX2_S2_T3 / FIX2_S2_T2（条件固定）")
    print("=" * 132)
    print("\n【日別】")
    print("日付         R数  1C敗戦  頭捕捉   S3_T2      S2_T3      S2_T2")
    print("-" * 90)
    for d in days:
        cells = []
        for m in METHODS:
            a = d.methods[m]
            cells.append(f"{a.hits:2d}/{d.n:<2d} {pct(a.hits,d.n):5.1f}%")
        print(
            f"{d.day}  {d.n:3d}  {d.in_fail:3d}({pct(d.in_fail,d.n):5.1f}%) "
            f"{d.head_hit:3d}({pct(d.head_hit,d.in_fail):5.1f}%)  "
            + "  ".join(cells)
        )

    print("\n【期間合計】")
    print(f"有効候補   : {total_n}R")
    print(f"1C敗戦     : {total_fail}R / {total_n}R = {pct(total_fail,total_n):.2f}%")
    print(f"1C敗戦時頭 : {total_head}R / {total_fail}R = {pct(total_head,total_fail):.2f}%")
    print()
    print("方式             平均点   的中        的中率  1C敗戦時的中  頭+2着捕捉  3着|頭2着   100円ROI  1000円均等ROI  単独的中")
    print("-" * 132)
    for method in METHODS:
        a = totals[method]
        avg_points = a.points / total_n if total_n else 0.0
        exact = pct(a.hits, total_n)
        fail_exact = pct(a.hits, total_fail)
        pair = pct(a.pair_hits, total_n)
        third_given_pair = pct(a.hits, a.pair_hits)
        roi100 = 100.0 * a.return100 / a.invest100 if a.invest100 else 0.0
        roi1000 = 100.0 * a.return1000 / (total_n * 1000.0) if total_n else 0.0
        print(
            f"{method:<16} {avg_points:7.2f}  {a.hits:3d}/{total_n:<3d}  {exact:7.2f}% "
            f"{fail_exact:12.2f}% {pair:10.2f}% {third_given_pair:10.2f}% "
            f"{roi100:9.2f}% {roi1000:13.2f}% {a.only_hits:8d}R"
        )

    # 方式間のユニオン/差分は日別PHPの表示だけでは正確なレース集合を横断取得できないため、
    # 単独的中件数を補助指標として表示する。
    print("\n※各日の判定は日別PHPと同一。期間集計のためにルールや閾値は変更していません。")
    print("※9/1～9/6は既観察期間なので、ここで得た結果を使って方式を再調整しません。")
    print("=" * 132)


if __name__ == "__main__":
    main()
