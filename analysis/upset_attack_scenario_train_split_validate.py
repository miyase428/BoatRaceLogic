#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
穴目予想：展開候補のTRAIN内期間分割再現性を確認する。

目的
----
P3完全未来を見た後に閾値を後付けしないため、既存TRAINをP1/P2へ分け、
固定済みの展開候補定義と攻め率帯が両期間で同方向に再現していたか確認する。

展開候補定義は upset_attack_scenario_validate.py と完全共通。
- HIGH: AI本命=1C / CURRENT本命!=1C / イン補正後1着率<50%
- 3～5C
- 6m sample_n>=10
- 攻め率=まくり+まくり差し
- 最大のコースを候補
- 帯: <15 / 15-20 / 20-30 / >=30%

Usage:
python3 analysis/upset_attack_scenario_train_split_validate.py \
  analysis/output/final_prediction_boats_20260615_20260714_OLD.csv \
  analysis/output/final_prediction_boats_20260715_20260814_OLD.csv \
  analysis/output/final_prediction_boats_fast_cached_20260815_20260822.csv \
  analysis/output/kimarite_analysis_dataset_20250815_20260814.csv
"""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import upset_attack_scenario_validate as attack
import upset_in_remaining_validate as remain


def main():
    if len(sys.argv) != 5:
        print(
            "Usage: python3 analysis/upset_attack_scenario_train_split_validate.py "
            "P1_BOATS P2_BOATS P3_BOATS TRAIN_KIMARITE"
        )
        sys.exit(1)

    p1, p2, p3, train_k = sys.argv[1:]

    print("展開候補のTRAINをP1/P2へ分け、固定帯の再現性を確認中...")
    high = remain.build_all(p1, p2, p3)
    train_map = attack.load_kimarite(train_k)

    p1_rows, p1_skip = attack.attach(high["p1"], train_map)
    p2_rows, p2_skip = attack.attach(high["p2"], train_map)

    print("=" * 112)
    print("穴目予想：展開候補 TRAIN内 P1/P2期間分割再現性")
    print("=" * 112)
    print(f"P1    : {high['train_start']} ～ 2026-07-14")
    print(f"P2    : 2026-07-15 ～ {high['train_end']}")
    print("HIGH  : AI本命=1C / CURRENT本命!=1C / イン補正後1着率<50%（固定）")
    print("候補  : 3～5Cの6m攻め率（まくり+まくり差し）最大 / sample_n>=10")
    print("帯    : <15 / 15-20 / 20-30 / >=30%（固定・変更なし）")
    print("P3はこの判定に使用しない")
    print("本番Web変更: なし")

    attack.print_overall("P1", p1_rows)
    attack.print_overall("P2", p2_rows)
    attack.print_band_table("P1", p1_rows)
    attack.print_band_table("P2", p2_rows)
    attack.print_distribution("P1", p1_rows)
    attack.print_distribution("P2", p2_rows)

    print("\n【join参考】")
    print("P1:", dict(p1_skip))
    print("P2:", dict(p2_skip))

    print("\n【判断ポイント】")
    print("1. P1/P2の両方で高攻め率帯ほど候補頭率・敗戦時候補頭率が上がるか")
    print("2. 特に20%以上の帯が、P1/P2の両方で<20%帯より明確に高いか")
    print("3. 片期間だけなら攻め率による強弱表示は固定しない")
    print("4. 候補コース自体の再現性と、攻め率帯による信頼度は分けて判断する")
    print("5. P3結果を理由に帯境界を変更しない")
    print("=" * 112)


if __name__ == "__main__":
    main()
