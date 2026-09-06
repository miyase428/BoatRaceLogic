#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
配当サイン固定後の穴目研究：AI3連対率を基準に、穴頭候補を1/2/3艇の
どこまで広げるのが妥当か比較する。

背景
----
- primary=イン崩壊 かつ実1C敗戦では、AI3 Top2がDEV/CONFIRMとも約70%。
- AI3 Top3まで広げると約83～85%まで実頭を拾える。
- 一方、展開/モーター/補正後1着率によるAI3 3位艇の常時置換は再現しなかった。

そこで「誰に置換するか」の探索は一旦止め、AI3順位を素直に使った候補数を比較する。

比較ルール
----------
FIX_TOP1              : 常にAI3 1位だけ
FIX_TOP2              : 常にAI3 1～2位
FIX_TOP3              : 常にAI3 1～3位
GAP10_TOP1_ELSE_TOP2  : AI3 1-2位差>=10ptなら1頭、それ以外2頭
GAP2_TOP3_ELSE_TOP2   : AI3 1-2位差<2ptなら3頭、それ以外2頭
DYNAMIC_10_2          : >=10ptは1頭、<2ptは3頭、それ以外2頭

10pt / 2pt は今回のDEV/CONFIRMから掘った閾値ではなく、既存の
upset_head_confidence_tier_validate.py で以前固定した信頼度境界を再利用する。
今回の期間を見て閾値を動かさない。

評価
----
- イン崩壊全体での頭捕捉率（1C勝ちは穴頭として外れ扱い）
- 実際に1Cが敗れたレースだけでの条件付き頭捕捉率
- 平均頭数
- AI3 gap帯別のTop1/Top2/Top3捕捉

重要
----
- 荒れ判定は変更しない。
- 展開特徴は捨てず、現時点ではAI3順位の置換に使わない。
- 本番買い目/本命/対抗には接続しない。
- 9/7以降の未使用期間で最終前方検証する。

Usage:
python3 analysis/compare_payout_signal_head_count_rules.py \
  analysis/output/final_prediction_boats_fast_cached_20260815_20260822.csv \
  analysis/output/final_prediction_boats_fast_cached_20260823_20260831.csv \
  analysis/output/final_prediction_boats_fast_cached_20260901_20260905.csv \
  analysis/output/kimarite_analysis_dataset_20260815_20260822.csv \
  analysis/output/kimarite_analysis_dataset_20260823_20260831.csv \
  analysis/output/kimarite_analysis_dataset_20260901_20260905.csv
"""

from __future__ import annotations

import sys
import time
from collections import Counter, defaultdict
from datetime import datetime
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import final_prediction_ai_opponent_compare as b2
import trifecta_probability_order_compare as step3
import payout_signal_relative_features as rel
import analyze_payout_signal_winner_relative_profile as profile
import compare_payout_signal_head_rules as cmp

RULES = (
    "FIX_TOP1",
    "FIX_TOP2",
    "FIX_TOP3",
    "GAP10_TOP1_ELSE_TOP2",
    "GAP2_TOP3_ELSE_TOP2",
    "DYNAMIC_10_2",
)


def pct(n: int, d: int) -> float:
    return 100.0 * n / d if d else 0.0


def format_elapsed(seconds: float) -> str:
    sec = max(0, int(round(seconds)))
    m, s = divmod(sec, 60)
    h, m = divmod(m, 60)
    if h:
        return f"{h}時間{m}分{s}秒"
    if m:
        return f"{m}分{s}秒"
    return f"{s}秒"


def target_rows(rows: list[dict]) -> list[dict]:
    return [r for r in rows if r["type"] == "イン崩壊"]


def trio_order(row: dict) -> list[int]:
    return cmp.method_order(row, "TRIO")


def trio_gap_pt(row: dict) -> float:
    order = trio_order(row)
    if len(order) < 2:
        return 0.0
    f = row["features"]
    p1 = float(rel.safe_float(f[order[0]].get("trio_p")) or 0.0)
    p2 = float(rel.safe_float(f[order[1]].get("trio_p")) or 0.0)
    return (p1 - p2) * 100.0


def head_count(row: dict, rule: str) -> int:
    gap = trio_gap_pt(row)
    if rule == "FIX_TOP1":
        return 1
    if rule == "FIX_TOP2":
        return 2
    if rule == "FIX_TOP3":
        return 3
    if rule == "GAP10_TOP1_ELSE_TOP2":
        return 1 if gap >= 10.0 else 2
    if rule == "GAP2_TOP3_ELSE_TOP2":
        return 3 if gap < 2.0 else 2
    if rule == "DYNAMIC_10_2":
        if gap >= 10.0:
            return 1
        if gap < 2.0:
            return 3
        return 2
    raise ValueError(rule)


def evaluate(rows: list[dict], rule: str) -> dict:
    total = len(rows)
    nonin_rows = [r for r in rows if r["in_failed"]]
    total_hit = 0
    nonin_hit = 0
    sum_heads = 0
    counts = Counter()

    for r in rows:
        k = head_count(r, rule)
        counts[k] += 1
        sum_heads += k
        heads = set(trio_order(r)[:k])
        winner = int(r["actual_first"])
        hit = winner in heads
        total_hit += 1 if hit else 0
        if r["in_failed"] and hit:
            nonin_hit += 1

    return {
        "n": total,
        "nonin_n": len(nonin_rows),
        "total_hit": total_hit,
        "total_rate": pct(total_hit, total),
        "nonin_hit": nonin_hit,
        "nonin_rate": pct(nonin_hit, len(nonin_rows)),
        "avg_heads": sum_heads / total if total else 0.0,
        "counts": counts,
    }


def gap_band(row: dict) -> str:
    gap = trio_gap_pt(row)
    if gap >= 10.0:
        return ">=10pt"
    if gap < 2.0:
        return "<2pt"
    return "2-10pt"


def fixed_capture(rows: list[dict], k: int) -> float:
    part = [r for r in rows if r["in_failed"]]
    hit = 0
    for r in part:
        if int(r["actual_first"]) in set(trio_order(r)[:k]):
            hit += 1
    return pct(hit, len(part))


def print_table(title: str, rows: list[dict]) -> dict[str, dict]:
    print(f"\n【{title}: primary=イン崩壊 {len(rows)}R】")
    nonin = sum(1 for r in rows if r["in_failed"])
    print(f"実1C敗戦={nonin}R ({pct(nonin,len(rows)):.2f}%)")
    print("ルール                         平均頭数  1頭/2頭/3頭構成       全R頭捕捉    1C敗戦時頭捕捉")
    print("-" * 102)
    out = {}
    for rule in RULES:
        m = evaluate(rows, rule)
        out[rule] = m
        c = m["counts"]
        comp = f"{c.get(1,0):>3d}/{c.get(2,0):>3d}/{c.get(3,0):>3d}"
        print(
            f"{rule:<30} {m['avg_heads']:>6.3f}頭   {comp:<17}  "
            f"{m['total_rate']:>7.2f}%      {m['nonin_rate']:>7.2f}%"
        )
    return out


def print_gap_table(title: str, rows: list[dict]) -> None:
    print(f"\n【{title}: AI3 1-2位差帯別 / 実1C敗戦時】")
    print("gap帯       全R   1C敗戦R   Top1捕捉   Top2捕捉   Top3捕捉")
    print("-" * 72)
    for band in (">=10pt", "2-10pt", "<2pt"):
        all_part = [r for r in rows if gap_band(r) == band]
        fail = [r for r in all_part if r["in_failed"]]
        print(
            f"{band:<10} {len(all_part):>5d}   {len(fail):>7d}    "
            f"{fixed_capture(all_part,1):>7.2f}%    "
            f"{fixed_capture(all_part,2):>7.2f}%    "
            f"{fixed_capture(all_part,3):>7.2f}%"
        )


def print_reproduction(dev: dict[str, dict], confirm: dict[str, dict]) -> None:
    print("\n【DEV→CONFIRM 再現】")
    print("ルール                         DEV平均頭  CONF平均頭   DEV敗戦時捕捉  CONF敗戦時捕捉")
    print("-" * 92)
    for rule in RULES:
        d = dev[rule]
        c = confirm[rule]
        print(
            f"{rule:<30} {d['avg_heads']:>7.3f}     {c['avg_heads']:>7.3f}       "
            f"{d['nonin_rate']:>7.2f}%          {c['nonin_rate']:>7.2f}%"
        )


def main() -> None:
    if len(sys.argv) != 7:
        print(
            "Usage: python3 analysis/compare_payout_signal_head_count_rules.py "
            "DEV1_BOATS DEV2_BOATS CONFIRM_BOATS DEV1_KIMARITE DEV2_KIMARITE CONFIRM_KIMARITE"
        )
        sys.exit(1)

    p1_csv, p2_csv, p3_csv, k1_csv, k2_csv, k3_csv = sys.argv[1:]
    t0 = time.perf_counter()
    print(f"開始時刻 : {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}", flush=True)
    print("共通レコード構築中...", flush=True)

    dev_common = step3.build_common_records(p1_csv, p2_csv)
    confirm_common = step3.build_common_records(p2_csv, p3_csv)
    dev_records = list(dev_common["records"]["P1"]) + list(dev_common["records"]["P2"])
    confirm_records = list(confirm_common["records"]["P2"])

    print("CSV/決まり手/モーター読込中...", flush=True)
    boats_map = b2.load_boats(p1_csv, p2_csv, p3_csv)
    kimarite_map = rel.load_kimarite(k1_csv, k2_csv, k3_csv)
    race_codes = [str(r["race_code"]) for r in dev_records + confirm_records]
    engine_map = rel.load_engine_map(race_codes)

    dev_all, dev_skip = profile.build_rows(dev_records, boats_map, kimarite_map, engine_map, "DEV")
    confirm_all, confirm_skip = profile.build_rows(
        confirm_records, boats_map, kimarite_map, engine_map, "CONFIRM"
    )
    dev = target_rows(dev_all)
    confirm = target_rows(confirm_all)

    print("=" * 122)
    print("配当サイン固定：AI3順位を使った穴頭候補数ルール比較")
    print("=" * 122)
    print(f"DEV     : {dev_common['p1_start']} ～ {dev_common['p2_end']}")
    print(f"CONFIRM : {confirm_common['p2_start']} ～ {confirm_common['p2_end']} ※既観察期間")
    print("対象     : primary=イン崩壊。1C勝ちは穴頭として外れ扱い")
    print("基準     : 非イン5艇のAI3連対率順位")
    print("閾値     : 10pt/2ptは既存穴頭信頼度研究の固定境界を再利用。今回の期間では動かさない")
    print("本番変更 : なし。買い目・本命・対抗にも未接続")

    dev_result = print_table("DEV", dev)
    confirm_result = print_table("CONFIRM", confirm)
    print_gap_table("DEV", dev)
    print_gap_table("CONFIRM", confirm)
    print_reproduction(dev_result, confirm_result)

    print("\n【判断方針】")
    print("1. 展開特徴によるAI3 3位の常時置換は再現しなかったため、一旦行わない")
    print("2. FIX_TOP2を基準に、Top3追加でどこまで捕捉が増えるかと平均頭数の増加を比較する")
    print("3. 10pt/2ptの既存信頼度境界で候補数を可変にする価値があるかDEV→CONFIRMで見る")
    print("4. この結果でも閾値を掘らず、最終候補を1～2方式に絞る")
    print("5. 最終候補は9/7以降の未使用期間で前方検証する")
    print("6. 穴目方式が固定するまで本命/対抗には接続しない")

    print("\njoin/skip DEV    :", dict(dev_skip))
    print("join/skip CONFIRM:", dict(confirm_skip))
    print("=" * 122)
    print(f"総所要時間 : {format_elapsed(time.perf_counter()-t0)}")


if __name__ == "__main__":
    main()
