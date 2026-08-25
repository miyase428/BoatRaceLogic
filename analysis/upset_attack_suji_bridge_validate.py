#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
穴目予想：現在Web表示の「展開候補」と既存すじ舟券を橋渡しする前方検証。

目的
----
過去の固定閾値（攻め率>=15/20%）を再探索せず、現在本番表示している
「3〜5Cの6month PIT攻め率最大 / sample_n>=10」の展開候補だけを使い、
レース前の時点で具体的な形成 A-相手-* が増えるかを P1/P2/P3 で確認する。

重要
----
- HIGH条件、展開候補の選び方は既存検証から固定。変更しない。
- actual_* は評価ラベルとしてのみ使用。
- 実頭条件では絞らない。レース前に候補が出た全レースを評価する。
- P3を見て条件・候補・閾値を変更しない。
- 本番Web/PredictionLogicは変更しない。

Usage:
python3 analysis/upset_attack_suji_bridge_validate.py \
  analysis/output/final_prediction_boats_20260615_20260714_OLD.csv \
  analysis/output/final_prediction_boats_20260715_20260814_OLD.csv \
  analysis/output/final_prediction_boats_fast_cached_20260815_20260822.csv \
  analysis/output/kimarite_analysis_dataset_20250815_20260814.csv \
  analysis/output/kimarite_analysis_dataset_20260815_20260822.csv
"""

from __future__ import annotations

import sys
from pathlib import Path
from collections import Counter

sys.path.insert(0, str(Path(__file__).resolve().parent))

import upset_in_remaining_validate as remain
import upset_attack_scenario_validate as attack

ATTACKERS = (3, 4, 5)


def pct(n, d):
    return 100.0 * n / d if d else 0.0


def attach(rows, kimarite_map):
    out = []
    skip = Counter()
    for high in rows:
        code = str(high["race_code"])
        k = kimarite_map.get(code)
        if k is None:
            skip["kimarite_missing"] += 1
            continue
        picked = attack.pick_attack(k)
        if picked is None:
            skip["attack_sample_missing"] += 1
            continue

        a1 = attack.to_int(k.get("actual_1st_course"))
        a2 = attack.to_int(k.get("actual_2nd_course"))
        a3 = attack.to_int(k.get("actual_3rd_course"))
        if not all(x in range(1, 7) for x in (a1, a2, a3)):
            skip["actual_missing"] += 1
            continue

        out.append({
            **high,
            "kimarite": k,
            "candidate": int(picked["course"]),
            "attack_rate": float(picked["attack_rate"]),
            "technique": str(picked["technique"]),
            "actual": (a1, a2, a3),
        })
        skip["ready"] += 1
    return out, skip


def sample_ok(row, attacker):
    k = row["kimarite"]
    return attack.to_int(k.get(f"c{attacker}_6m_sample_n")) >= attack.MIN_SAMPLE


def formation_defs(attacker):
    defs = []

    def add(label, second=None, third=None):
        defs.append((label, second, third))

    add(f"{attacker}-1-*", second=1)

    inner = attacker - 1
    if inner >= 1 and inner != 1:
        add(f"{attacker}-{inner}-*", second=inner)

    two_inner = attacker - 2
    if two_inner >= 1 and two_inner not in (1, inner):
        add(f"{attacker}-{two_inner}-*", second=two_inner)

    outer = attacker + 1
    if outer <= 6:
        add(f"{attacker}-{outer}-*", second=outer)
        add(f"{attacker}-*-{outer}", third=outer)

    two_outer = attacker + 2
    if two_outer <= 6:
        add(f"{attacker}-{two_outer}-*", second=two_outer)
        add(f"{attacker}-*-{two_outer}", third=two_outer)

    return defs


def match(actual, attacker, second=None, third=None):
    a, b, c = actual
    if a != attacker:
        return False
    if second is not None and b != second:
        return False
    if third is not None and c != third:
        return False
    return True


def evaluate(rows, attacker, second=None, third=None):
    base = [r for r in rows if sample_ok(r, attacker)]
    cond = [r for r in base if int(r["candidate"]) == attacker]

    base_hit = sum(match(r["actual"], attacker, second, third) for r in base)
    cond_hit = sum(match(r["actual"], attacker, second, third) for r in cond)

    br = pct(base_hit, len(base))
    cr = pct(cond_hit, len(cond))
    lift = cr / br if br > 0 else 0.0
    return {
        "base_n": len(base),
        "cond_n": len(cond),
        "base_hit": int(base_hit),
        "cond_hit": int(cond_hit),
        "base_rate": br,
        "cond_rate": cr,
        "delta": cr - br,
        "lift": lift,
    }


def head_stat(rows, attacker):
    return evaluate(rows, attacker)


def print_period(title, rows):
    print("\n" + "=" * 122)
    print(f"【{title}】 HIGH連結済み={len(rows)}R")
    print("=" * 122)

    for a in ATTACKERS:
        hs = head_stat(rows, a)
        print(
            f"\n--- 展開候補 {a}C --- 条件N={hs['cond_n']} / "
            f"{a}C頭 {hs['cond_hit']}/{hs['cond_n']}={hs['cond_rate']:.2f}% "
            f"(BASE {hs['base_rate']:.2f}% / 差{hs['delta']:+.2f}pt / Lift {hs['lift']:.2f})"
        )
        print("形成          BASE率      候補時率      差pt    Lift   候補hit")
        print("-" * 78)
        for label, second, third in formation_defs(a):
            s = evaluate(rows, a, second, third)
            print(
                f"{label:<12} {s['base_rate']:>7.2f}%   {s['cond_rate']:>8.2f}%   "
                f"{s['delta']:>+7.2f}  {s['lift']:>6.2f}   {s['cond_hit']:>3}/{s['cond_n']:<3}"
            )


def main():
    if len(sys.argv) != 6:
        print(
            "Usage: python3 analysis/upset_attack_suji_bridge_validate.py "
            "P1_BOATS P2_BOATS P3_BOATS TRAIN_KIMARITE P3_KIMARITE",
            file=sys.stderr,
        )
        sys.exit(1)

    p1, p2, p3, train_k, p3_k = sys.argv[1:]

    print("HIGH・現行展開候補を固定し、すじ形成への事前接続を検証中...", flush=True)
    high = remain.build_all(p1, p2, p3)
    train_map = attack.load_kimarite(train_k)
    p3_map = attack.load_kimarite(p3_k)

    p1_rows, p1_skip = attach(high["p1"], train_map)
    p2_rows, p2_skip = attach(high["p2"], train_map)
    p3_rows, p3_skip = attach(high["p3"], p3_map)

    print("=" * 122)
    print("穴目予想：現行展開候補 → すじ舟券形成 事前予測 P1/P2/P3")
    print("=" * 122)
    print("HIGH     : AI本命=1C / CURRENT本命!=1C / イン補正後1着率<50%（固定）")
    print("展開候補 : 3〜5Cの6m攻め率最大 / sample_n>=10 / 閾値追加なし")
    print("評価     : 実頭条件で絞らず、候補が表示された全レースで A-相手-* を評価")
    print("BASE     : 同じHIGH内で対象コースsample_n>=10の全レース")
    print("本番変更 : なし")

    print_period("P1", p1_rows)
    print_period("P2", p2_rows)
    print_period("P3完全未来", p3_rows)

    print("\n【join参考】")
    print("P1:", dict(p1_skip))
    print("P2:", dict(p2_skip))
    print("P3:", dict(p3_skip))

    print("\n【判断ポイント】")
    print("1. P1/P2/P3で候補時率がBASEより同方向に上がる形成だけ残す")
    print("2. 特に4C候補 → 4-5-* が3期間とも再現するか")
    print("3. 3C候補 → 3-5-* は下関8Rの個別例。全体で再現しなければ一般化しない")
    print("4. P3を見て新しい閾値や技別条件を追加しない")
    print("5. 通れば次に120通り穴ヒモ候補との重なり/補完を検証する")
    print("=" * 122)


if __name__ == "__main__":
    main()
