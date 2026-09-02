#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
第二世代MLの固定F3ホールドアウト検証。

凍結仕様
--------
- ベース: 共通2着確率③ AI_FINAL
- ML: 第一世代と同一仕様の HGB
- 補正: BLEND alpha=0.50
- 合成: log-space
    log p_final = log p_ai + 0.50 * (log p_hgb - log p_ai)
- 本命 / kiru / 3着候補は現行固定
- 本番Webは変更しない

重要
----
F1/F2を見て上記仕様を決めたため、F1/F2は今後の採用判定に使わない。
このスクリプトは、仕様凍結後に新しく蓄積するF3だけで
③ AI_FINAL と BLEND alpha=0.50 を比較する。

F3は 2026-09-03 以降を推奨する。
9/1～9/2は仕様凍結前の期間を含むため、最終採用判定には使わない。

Usage
-----
python3 analysis/second_place_ml_stage2_f3_holdout.py \
  analysis/output/final_prediction_boats_fast_cached_20260903_20260909.csv
"""

from __future__ import annotations

import sys
from pathlib import Path

import second_place_ml_benchmark as ml1
import second_place_ml_stage2_correction as ml2


FROZEN_MODE = "BLEND"
FROZEN_ALPHA = 0.50
RECOMMENDED_F3_START = "2026-09-03"


def load_f3(f3_csv: str):
    path = Path(f3_csv)
    if not path.exists():
        raise FileNotFoundError(f"F3 CSVがありません: {f3_csv}")

    # 既知F2をP1、完全未来F3をP2として既存の120通り共通レコード生成を再利用する。
    future = ml1.step3.build_common_records(ml1.HOLDOUT_F2, f3_csv)
    boats = ml1.final_aite.load_boats(ml1.HOLDOUT_F2, f3_csv)
    maps = ml1.common2.load_exhibition_course_maps(
        future["p1_start"],
        future["p2_end"],
    )
    f3, skip = ml1.prepare_period(
        future["records"]["P2"],
        boats,
        maps,
        "F3",
    )
    return f3, skip, future


def print_result_row(name, x):
    print(
        f"{name:<22} "
        f"ProbN={x['n']:>4d}  "
        f"LL={x['logloss']:.6f}  "
        f"Br={x['brier5']:.6f}  "
        f"実2着P={x['actual_p']*100:>6.3f}%  "
        f"RankN={x['rank_n']:>4d}  "
        f"T1={x['rank_top1']*100:>6.2f}%  "
        f"T2={x['rank_top2']*100:>6.2f}%  "
        f"T3={x['rank_top3']*100:>6.2f}%  "
        f"3単={x['tri_hit']:>3d}/{x['tri_n']:<3d} {x['tri_rate']*100:>6.2f}%"
    )


def print_delta(champion, blend):
    print("\n【BLEND - ③】 ※LL/Brierはマイナス、Top系/3連単はプラスが改善")
    print(
        f"LogLoss={blend['logloss']-champion['logloss']:+.6f} / "
        f"Brier5={blend['brier5']-champion['brier5']:+.6f} / "
        f"実2着平均P={(blend['actual_p']-champion['actual_p'])*100:+.3f}pt / "
        f"Top1={(blend['rank_top1']-champion['rank_top1'])*100:+.2f}pt / "
        f"Top2={(blend['rank_top2']-champion['rank_top2'])*100:+.2f}pt / "
        f"Top3={(blend['rank_top3']-champion['rank_top3'])*100:+.2f}pt / "
        f"3連単={(blend['tri_rate']-champion['tri_rate'])*100:+.2f}pt"
    )


def print_decision(champion, blend, f3_n):
    ll_ok = blend["logloss"] < champion["logloss"]
    br_ok = blend["brier5"] < champion["brier5"]

    print("\n【F3判定】")
    print(f"確率品質: LogLoss={'改善' if ll_ok else '非改善'} / Brier5={'改善' if br_ok else '非改善'}")

    if f3_n < 500:
        print(
            f"対象レース={f3_n}R。まだサンプルが小さいため途中経過扱い。"
            " 仕様は固定したままF3を増やす。"
        )
    elif ll_ok and br_ok:
        print(
            "一次条件PASS: F3でもLogLoss/Brier5の両方が③を上回った。\n"
            "次は日別安定性・頭コース別・場別の偏りを診断してから本番採用可否を決める。"
        )
    else:
        print(
            "一次条件FAIL: F3で確率品質の両方改善を再現できていない。\n"
            "現時点では③ AI_FINALを本番チャンピオンとして維持する。"
        )

    print("※F3結果を見てalphaやHGB設定を変更した場合、それは第三世代候補として新しい未来期間が必要。")


def main():
    if len(sys.argv) != 2:
        raise SystemExit(
            "Usage: python3 analysis/second_place_ml_stage2_f3_holdout.py F3_BOATS_CSV"
        )

    f3_csv = sys.argv[1]

    print("=" * 132)
    print("第二世代ML F3固定ホールドアウト：③ AI_FINAL vs BLEND alpha=0.50")
    print("=" * 132)
    print(f"F3 CSV       : {f3_csv}")
    print(f"固定方式      : {FROZEN_MODE}")
    print(f"固定alpha     : {FROZEN_ALPHA:.2f}")
    print("HGB仕様       : 第一世代と同一（変更なし）")
    print("学習期間      : 2026-06-15～2026-08-31（F1/F2を含む全既知期間）")
    print(f"推奨F3開始    : {RECOMMENDED_F3_START} 以降")
    print("本番Web変更   : なし")

    # 仕様凍結後は、F2までの全既知データを固定HGBの学習へ使ってよい。
    data = ml1.load_datasets()
    train_all = data["train"] + data["valid"] + data["holdout"]

    f3, skip, future = load_f3(f3_csv)
    if not f3:
        raise RuntimeError("F3の評価可能レースが0件です")

    print("\n【F3データ準備】")
    start = future.get("p2_start")
    end = future.get("p2_end")
    print(f"期間          : {start} ～ {end}")
    print(f"ready         : {int(skip.get('ready', 0))}")
    others = {k: v for k, v in skip.items() if k != "ready" and v}
    if others:
        print("skip          : " + ", ".join(f"{k}={v}" for k, v in sorted(others.items())))

    if start is not None and hasattr(start, "isoformat"):
        start_iso = start.isoformat()
    else:
        start_iso = str(start)
    if start_iso and start_iso < RECOMMENDED_F3_START:
        print(
            f"警告          : F3開始={start_iso}。最終採用判定では "
            f"{RECOMMENDED_F3_START} 以降だけを使うことを推奨。"
        )

    schema = ml1.build_schema(train_all)
    hgb = ml2.fit_hgb(train_all, schema)

    champion_pred = ml1.champion_predictions(f3)
    hgb_pred = ml1.model_predictions(hgb, f3, schema)
    blend_pred = ml2.build_corrected_predictions(
        f3,
        champion_pred,
        hgb_pred,
        FROZEN_ALPHA,
        FROZEN_MODE,
    )

    champion = ml1.evaluate_method("CHAMPION_AI_FINAL", f3, champion_pred)
    blend = ml1.evaluate_method("BLEND_a0.50", f3, blend_pred)

    print("\n【F3結果】")
    print_result_row("CHAMPION_AI_FINAL", champion)
    print_result_row("BLEND alpha=0.50", blend)
    print_delta(champion, blend)

    changed, change_n = ml2.top1_change_stats(f3, champion_pred, blend_pred)
    print(f"Top1変更     : {changed}/{change_n}R")

    print_decision(champion, blend, len(f3))


if __name__ == "__main__":
    main()
