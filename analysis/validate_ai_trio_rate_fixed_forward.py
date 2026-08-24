#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
AI3連対率：本番固定係数の前方ホールドアウト検証。

本番Web `AiTrioRateLogic.php` と同じ ENTRY_MODE / 固定係数を、
評価期間では一切再学習せずそのまま適用する。

比較:
- BASE_ENTRY : BB_MEDIUM RAW、今回コース=展示進入
- AI_FIXED   : BASE_ENTRY + 一次Z + 二次Z の固定ロジスティック回帰

固定係数（AiTrioRateLogic.php と同値）:
    intercept   = 0.033713
    base_logit  = 0.828225
    primary_z   = 0.433483
    secondary_z = 0.286814

Usage:
    python3 analysis/validate_ai_trio_rate_fixed_forward.py \
      analysis/output/final_prediction_boats_fast_cached_20260815_20260822.csv \
      2026-08-15 2026-08-22
"""

from __future__ import annotations

import sys
from datetime import datetime
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import base_trio_secondary_compare as secondary_step
import base_trio_entry_mode_compare as entry_step


BETA_FIXED = [
    0.033713,
    0.828225,
    0.433483,
    0.286814,
]


def parse_date(text: str):
    return datetime.strptime(text, "%Y-%m-%d").date()


def pct(x: float) -> str:
    return f"{x * 100:6.2f}%"


def print_row(name: str, m: dict, include_ece: bool = False):
    ece = f" {m['ece'] * 100:7.3f}pt" if include_ece else "         -"
    print(
        f"{name:<14}"
        f" {m['races']:>5d}"
        f"  {m['brier']:.6f}"
        f"  {m['logloss']:.6f}"
        f"{ece}"
        f"  {m['avg_sum6'] * 100:7.2f}%"
        f"      {pct(m['top3_capture'])}"
        f"       {pct(m['top3_exact'])}"
    )


def main() -> int:
    if len(sys.argv) != 4:
        print(
            "Usage: python3 analysis/validate_ai_trio_rate_fixed_forward.py "
            "BOATS_CSV YYYY-MM-DD YYYY-MM-DD"
        )
        return 2

    csv_path = sys.argv[1]
    start = parse_date(sys.argv[2])
    end = parse_date(sys.argv[3])
    if start > end:
        raise RuntimeError("開始日が終了日より後です")

    features = secondary_step.load_feature_csv(csv_path)

    print("AI3連対率 固定係数の前方検証データを構築中...")
    snapshots, course_source, stats = entry_step.load_dual_mode_snapshots(start, end)
    races, join_counts = entry_step.join_csv_features(
        snapshots,
        features["races"],
        start,
        end,
    )
    if not races:
        raise RuntimeError("前方評価可能レースが0件です")

    base = entry_step.evaluate_base_only(races, "base_entry")
    ai = entry_step.evaluate(races, entry_step.ENTRY_MODE, BETA_FIXED)

    changed = entry_step.changed_only(races)
    base_changed = (
        entry_step.evaluate_base_only(changed, "base_entry")
        if changed else None
    )
    ai_changed = (
        entry_step.evaluate(changed, entry_step.ENTRY_MODE, BETA_FIXED)
        if changed else None
    )

    sep = "=" * 132
    print(sep)
    print("AI3連対率 本番固定係数 前方ホールドアウト")
    print(sep)
    print(f"評価期間       : {start} ～ {end}")
    print(f"特徴量CSV      : {csv_path}")
    print(f"共通評価レース : {len(races)}")
    print(f"展示進入変更R  : {len(changed)}")
    print("今回コース     : ENTRY_MODE（展示進入）")
    print("基礎3連対率    : BB_MEDIUM RAW Kpc=20 / Kpvc=10")
    print("AI特徴量       : base_logit + 一次Z + 二次Z")
    print(
        "固定係数       : "
        f"intercept={BETA_FIXED[0]:.6f} / "
        f"base={BETA_FIXED[1]:.6f} / "
        f"primary={BETA_FIXED[2]:.6f} / "
        f"secondary={BETA_FIXED[3]:.6f}"
    )
    print("※ 評価期間では再学習・係数調整を行わない。")

    print("\n【全体】")
    print(
        "方式              R     Brier     LogLoss       ECE      平均Σ6"
        "   上位3艇捕捉   Top3完全一致"
    )
    print("-" * 118)
    print_row("BASE_ENTRY", base, False)
    print_row("AI_FIXED", ai, True)

    print("\n【AI_FIXED - BASE_ENTRY】")
    print(f"Brier差          : {ai['brier'] - base['brier']:+.6f}  （マイナスが改善）")
    print(f"LogLoss差        : {ai['logloss'] - base['logloss']:+.6f}  （マイナスが改善）")
    print(f"平均Σ6差         : {(ai['avg_sum6'] - base['avg_sum6']) * 100:+.2f}pt")
    print(f"上位3艇捕捉率差  : {(ai['top3_capture'] - base['top3_capture']) * 100:+.2f}pt")
    print(f"Top3完全一致率差 : {(ai['top3_exact'] - base['top3_exact']) * 100:+.2f}pt")

    if changed and base_changed is not None and ai_changed is not None:
        print("\n【展示進入変更レースのみ】")
        print(
            "方式              R     Brier     LogLoss       ECE      平均Σ6"
            "   上位3艇捕捉   Top3完全一致"
        )
        print("-" * 118)
        print_row("BASE_ENTRY", base_changed, False)
        print_row("AI_FIXED", ai_changed, True)
        print(
            f"差: Brier {ai_changed['brier'] - base_changed['brier']:+.6f} / "
            f"LogLoss {ai_changed['logloss'] - base_changed['logloss']:+.6f} / "
            f"捕捉 {(ai_changed['top3_capture'] - base_changed['top3_capture']) * 100:+.2f}pt / "
            f"完全一致 {(ai_changed['top3_exact'] - base_changed['top3_exact']) * 100:+.2f}pt"
        )

    print("\n【参考】")
    print(f"CSV join        : {dict(join_counts)}")
    print(f"course source   : {dict(course_source)}")
    print(f"snapshot stats  : {dict(stats)}")

    print("\n判断ポイント:")
    print("1. AI_FIXEDがBASE_ENTRYよりBrier/LogLossの両方で改善するか。")
    print("2. 上位3艇捕捉率・Top3完全一致率も悪化していないか。")
    print("3. 平均Σ6は300%へ強制正規化しない本番仕様なので、300%との差自体は異常ではない。")
    print("4. この前方結果を見て係数を再調整しない。")
    print(sep)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
