#!/usr/bin/env python3
import json
import sys
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
THEORY_DIR = REPO_ROOT / "theories" / "course_correction"
sys.path.insert(0, str(THEORY_DIR))
sys.path.insert(0, str(Path(__file__).resolve().parent))

from slit_pattern_condition_analyze import prepare_races
from slit_buff_rebuild_validate import (
    calc_baseline,
    calc_pattern_rates,
    calc_buff,
    classify_prepared,
)

K = 40.0
MAX_CAP = 0.08
ACTIVE_METRICS = {"win", "trio"}
ALL_METRICS = ("win", "place2", "place3", "trio")

PATTERN_NAMES = {
    1: "通常型", 2: "横一線", 3: "1・2先行", 4: "スロー先行",
    5: "壁なし", 6: "2・3遅れ", 7: "中凹み", 8: "3号艇攻め",
    9: "中ぶくれ", 10: "1号艇遅れ", 11: "外側先行", 12: "ダッシュ先行",
}


def load_settings():
    with (THEORY_DIR / "venue_slit_settings.json").open(encoding="utf-8") as f:
        return json.load(f)["default"]


def zero_inactive_metrics(buff):
    for pid in range(1, 13):
        for course in range(1, 7):
            for metric in ALL_METRICS:
                if metric not in ACTIVE_METRICS:
                    buff[pid][course][metric] = 0.0
    return buff


def stringify_keys(obj):
    if isinstance(obj, dict):
        return {str(k): stringify_keys(v) for k, v in obj.items()}
    if isinstance(obj, list):
        return [stringify_keys(v) for v in obj]
    return obj


def main():
    if len(sys.argv) != 3:
        print("Usage: python3 analysis/slit_buff_generate_production.py YYYY-MM-DD YYYY-MM-DD")
        sys.exit(1)

    start_date, end_date = sys.argv[1], sys.argv[2]
    settings = load_settings()

    prepared, skip, terms = prepare_races(start_date, end_date)
    rows, freq = classify_prepared(prepared, settings)

    if not rows:
        raise RuntimeError("生成対象レースが0件です")

    baseline, _ = calc_baseline(rows)
    pattern_rates, pattern_counts = calc_pattern_rates(rows)
    buff = calc_buff(pattern_rates, baseline, pattern_counts)
    buff = zero_inactive_metrics(buff)

    # 本番buff_debuff_slit.jsonはこの段階では上書きしない。
    # 候補を確認後、ユーザーが明示的に置換する。
    out_buff = THEORY_DIR / "buff_debuff_slit_candidate.json"
    out_meta = THEORY_DIR / "buff_debuff_slit_candidate_meta.json"

    with out_buff.open("w", encoding="utf-8") as f:
        json.dump(stringify_keys(buff), f, indent=2, ensure_ascii=False)
        f.write("\n")

    meta = {
        "version": 2,
        "status": "candidate",
        "prediction_method": "C_ST_RANK",
        "pattern_classifier": "current production classify_slit_pattern + venue_slit_settings.json",
        "training_start": start_date,
        "training_end": end_date,
        "term_info": terms,
        "processed_races": len(rows),
        "k": K,
        "max_cap": MAX_CAP,
        "active_metrics": ["win", "trio"],
        "inactive_metrics": ["place2", "place3"],
        "pid_counts": {str(pid): freq[pid] for pid in range(1, 13)},
        "skip": {k: int(v) for k, v in skip.items()},
        "generated_at_utc": datetime.now(timezone.utc).isoformat(),
        "notes": [
            "buff is learned from predicted C_ST_RANK PID -> actual finish outcome",
            "win/trio passed bidirectional validation and final holdout",
            "place2/place3 are intentionally forced to zero",
            "candidate file does not affect slit_api.php until explicitly promoted",
        ],
    }

    with out_meta.open("w", encoding="utf-8") as f:
        json.dump(meta, f, indent=2, ensure_ascii=False)
        f.write("\n")

    print("=" * 108)
    print("スリット 本番buff候補生成（C_ST_RANK予測PID → 実着順）")
    print("=" * 108)
    print(f"期間       : {start_date} ～ {end_date}")
    print(f"使用期     : {', '.join(terms)}")
    print(f"処理レース : {len(rows)}")
    print(f"K          : {int(K)}")
    print(f"cap        : ±{MAX_CAP:.2f}")
    print("採用指標   : win / trio")
    print("0補正      : place2 / place3")
    print("本番JSON   : 未変更")
    print("\n【skip】")
    for key in ["not_6_entry", "not_6_exhibition", "missing_ex_st", "bad_result", "missing_racer_term_or_st"]:
        print(f"{key:<29}: {skip[key]}")

    print("\n【PID件数】")
    for pid in range(1, 13):
        print(f"{pid:>2} {PATTERN_NAMES[pid]:<12}: {freq[pid]:>6}")

    print("\n【安全確認】")
    max_abs = 0.0
    inactive_nonzero = 0
    for pid in range(1, 13):
        for course in range(1, 7):
            for metric in ACTIVE_METRICS:
                max_abs = max(max_abs, abs(buff[pid][course][metric]))
            for metric in ("place2", "place3"):
                if abs(buff[pid][course][metric]) > 1e-12:
                    inactive_nonzero += 1
    print(f"win/trio 最大絶対値 : {max_abs:.6f} (cap={MAX_CAP:.2f})")
    print(f"place2/place3 非0セル: {inactive_nonzero}")
    print(f"候補       : {out_buff}")
    print(f"meta       : {out_meta}")
    print("=" * 108)


if __name__ == "__main__":
    main()
