#!/usr/bin/env python3
"""指定場の全6コースで、コンパクトML候補と現行サインを最終比較する。"""

from __future__ import annotations

import argparse
import json
import sys
from datetime import date, timedelta
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "analysis"))

import audit_tamagawa_course_signals_zero_base_ml as audit  # noqa: E402
from audit_tamagawa_center_ml_factors import feature_groups  # noqa: E402
from analyze_tamagawa_boaters_hypothesis import months_ago, parse_date  # noqa: E402
from validate_tamagawa_center_ml_candidate import TARGETS, validate_target  # noqa: E402
from validate_tamagawa_other_course_ml_candidate import validate_lane6  # noqa: E402


PLACE_NAMES = {
    "ASY": "芦屋",
    "AMG": "尼崎",
    "EDG": "江戸川",
    "MKN": "三国",
    "KRY": "桐生",
    "HWJ": "平和島",
    "TDA": "戸田",
    "TMG": "多摩川",
    "OMR": "大村",
    "SMS": "下関",
    "SME": "住之江",
}


def pct(value) -> str:
    return "-" if value is None else f"{100.0 * float(value):.2f}%"


def write_markdown(report: dict, path: Path) -> None:
    group_labels = {
        "player_strength": "選手力",
        "motor_boat": "モーター・ボート",
        "st": "ST",
        "technique": "決まり手履歴",
        "exhibition": "展示",
    }
    lines = [
        f"# {report['place_name']} 全コース コンパクトML候補検証",
        "",
        f"- 対象: {report['period']['start']}～{report['period']['end']}",
        f"- 学習: ～{report['period']['train_end']}",
        f"- 特徴選択用検証: {report['period']['valid_start']}～{report['period']['valid_end']}",
        f"- 最終テスト: {report['period']['test_start']}～{report['period']['end']}",
        f"- 使用レース: {report['races']:,}R",
        "- 現行サインありは表示件数を完全一致。停止中コースは表示率別に全体平均との差を評価。",
        "",
        "## 現行サインとの同数比較",
        "",
        "|対象|採用要素|現行 N/率|ML同数 N/率|差|改善確率|",
        "|---|---|---:|---:|---:|---:|",
    ]
    for course in report["current_courses"]:
        for target in TARGETS:
            item = report["courses"][str(course)][target]
            labels = "＋".join(group_labels[name] for name in item["groups"])
            current = item["current"]
            candidate = item["candidate_equal_count"]
            uncertainty = item["uncertainty_equal_count"]
            lines.append(
                f"|{course}C {target}|{labels}|{current['n']} / {pct(current['rate'])}|"
                f"{candidate['n']} / {pct(candidate['rate'])}|{pct(uncertainty['difference'])}|"
                f"{pct(uncertainty['probability_improves'])}|"
            )
    lines += ["", "## 停止中コース", ""]
    for course in report["disabled_courses"]:
        lines += [
            f"### {course}C",
            "",
            "|対象|採用要素|表示率|N/率|全体率|差|改善確率|",
            "|---|---|---:|---:|---:|---:|---:|",
        ]
        for target in TARGETS:
            item = report["courses"][str(course)][target]
            labels = "＋".join(group_labels[name] for name in item["groups"])
            for key, candidate in item["coverage_candidates"].items():
                uncertainty = candidate["uncertainty_vs_baseline"]
                lines.append(
                    f"|{target}|{labels}|{key}|{candidate['n']} / {pct(candidate['rate'])}|"
                    f"{pct(item['baseline']['rate'])}|{pct(uncertainty['difference'])}|"
                    f"{pct(uncertainty['probability_improves'])}|"
                )
        lines.append("")
    path.write_text("\n".join(lines) + "\n", encoding="utf-8")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--place", required=True)
    parser.add_argument("--end", default=(date.today() - timedelta(days=1)).isoformat())
    parser.add_argument("--months", type=int, default=36)
    parser.add_argument("--valid-months", type=int, default=6)
    parser.add_argument("--test-months", type=int, default=6)
    parser.add_argument("--output-prefix", type=Path, default=None)
    args = parser.parse_args()
    place = str(args.place).strip().upper()
    if not (len(place) == 3 and place.isalnum()):
        raise SystemExit(f"invalid place: {place}")
    audit.PLACE = place
    end = parse_date(args.end)
    start = months_ago(end + timedelta(days=1), args.months)
    test_start = months_ago(end + timedelta(days=1), args.test_months)
    valid_start = months_ago(test_start, args.valid_months)
    config = json.loads((ROOT / "config" / "course_signal_rules.json").read_text(encoding="utf-8"))
    records, pre_features, post_features = audit.build_dataset(start, end, config)
    for row in records:
        row["split"] = "train" if row["date"] < valid_start else ("valid" if row["date"] < test_start else "test")
    groups = feature_groups(pre_features, post_features)
    venue_rules = config.get("places", {}).get(place, {})
    current_courses = [
        course for course in range(1, 7)
        if any(bool(rule.get("enabled")) for rule in venue_rules.get(str(course), {}).get("primary_rules", {}).values())
    ]
    disabled_courses = [course for course in range(1, 7) if course not in current_courses]
    report = {
        "status": "ok",
        "version": "venue-course-compact-ml-candidate-v1",
        "place": place,
        "place_name": PLACE_NAMES.get(place, place),
        "period": {
            "start": start.isoformat(),
            "end": end.isoformat(),
            "train_end": (valid_start - timedelta(days=1)).isoformat(),
            "valid_start": valid_start.isoformat(),
            "valid_end": (test_start - timedelta(days=1)).isoformat(),
            "test_start": test_start.isoformat(),
        },
        "races": len(records) // 6,
        "current_courses": current_courses,
        "disabled_courses": disabled_courses,
        "courses": {},
    }
    for course in range(1, 7):
        print(f"{course}C候補検証", flush=True)
        rows = [row for row in records if row["course"] == course]
        report["courses"][str(course)] = {}
        for target in TARGETS:
            print(f"  {target}", flush=True)
            if course in current_courses:
                item, _, _, _ = validate_target(rows, target, post_features, groups)
            else:
                item = validate_lane6(rows, target, post_features, groups)
            report["courses"][str(course)][target] = item
    output_prefix = args.output_prefix or (
        ROOT / "analysis" / "output" / f"{place.lower()}_course_ml_candidate_{end.strftime('%Y%m%d')}"
    )
    json_path = output_prefix.with_suffix(".json")
    md_path = output_prefix.with_suffix(".md")
    json_path.parent.mkdir(parents=True, exist_ok=True)
    json_path.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    write_markdown(report, md_path)
    print(f"JSON: {json_path}")
    print(f"REPORT: {md_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
