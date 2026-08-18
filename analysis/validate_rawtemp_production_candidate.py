#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
RAW_TEMP 本番反映前の最終回帰テスト。

目的
----
既存の本番補正後1着率チェーンは一切変更せず、最終 corrected_rate の後段だけに
固定済み候補 RAW_TEMP を適用したときの互換性を確認する。

固定候補
--------
  tau = clamp(0.90 + 0.80 * ln(raw_total), 0.45, 1.60)
  p'_i ∝ p_i ** tau

raw_total は本番JSONの各艇 base_detail.p_final_raw の6艇合計。

確認項目
--------
- 通常22場 exact ラッパーが正常
- AMG/TKY 専用 exact ラッパーが正常
- 6艇合計100%
- RAW_TEMP後も6艇合計100%
- 全順位不変 / Top1不変
- raw_total / tau が有限・妥当
- AMG/TKY は EX_TOTAL3 のまま
- 実進入変更レースで展示進入が維持される
- 仮想進入で指定 course mapping が維持される
- 仮想進入でもRAW_TEMP後の順位/100%が壊れない

本番Webロジックは変更しない。読み取り回帰テストのみ。

Usage:
  python3 analysis/validate_rawtemp_production_candidate.py

任意で通常場・AMG・TKYのrace_codeを明示:
  python3 analysis/validate_rawtemp_production_candidate.py NORMAL_RACE AMG_RACE TKY_RACE
"""

from __future__ import annotations

import json
import math
import subprocess
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
FORECAST_DIR = REPO_ROOT / "forecast"
ANALYSIS_DIR = REPO_ROOT / "analysis"
sys.path.insert(0, str(ANALYSIS_DIR))

from slit_validate_v2 import connect_db  # noqa: E402

GLOBAL_TAU = 0.90
RAW_K = 0.80
TAU_MIN = 0.45
TAU_MAX = 1.60
SPECIAL = {"AMG", "TKY"}


def place_of(race_code: str) -> str:
    code = str(race_code)
    return code[8:11] if len(code) >= 11 else "???"


def run_json(args, timeout=240):
    proc = subprocess.run(
        args,
        capture_output=True,
        text=True,
        timeout=timeout,
        check=False,
        cwd=str(REPO_ROOT),
    )
    text = (proc.stdout or "").strip()
    if not text:
        raise RuntimeError(f"JSON出力なし: {' '.join(map(str, args))}; stderr={proc.stderr[:500]}")
    try:
        data = json.loads(text)
    except json.JSONDecodeError as exc:
        raise RuntimeError(
            f"JSON解析失敗: {exc}; stdout={text[:500]}; stderr={(proc.stderr or '')[:500]}"
        )
    if data.get("status") != "ok":
        raise RuntimeError(str(data.get("error") or "補正後1着率スクリプト失敗"))
    return data


def wrapper_for(race_code: str) -> Path:
    if place_of(race_code) in SPECIAL:
        return FORECAST_DIR / "corrected_winrate_live_exact_amg_tky.py"
    return FORECAST_DIR / "corrected_winrate_live_exact.py"


def load_actual(race_code: str):
    return run_json([sys.executable, str(wrapper_for(race_code)), race_code])


def load_virtual(race_code: str, lane_to_course: str):
    return run_json(
        [
            sys.executable,
            str(FORECAST_DIR / "corrected_winrate_live_virtual.py"),
            race_code,
            lane_to_course,
        ]
    )


def ordered_boats(payload):
    boats = payload.get("boats")
    if not isinstance(boats, dict) or set(boats.keys()) != set("123456"):
        raise RuntimeError("boatsが1～6号艇分ではありません")
    return [boats[str(lane)] for lane in range(1, 7)]


def candidate(payload):
    boats = ordered_boats(payload)
    raw_parts = []
    current = []

    for row in boats:
        detail = row.get("base_detail") or {}
        raw = float(detail.get("p_final_raw"))
        p = float(row.get("corrected_rate")) / 100.0
        if not math.isfinite(raw) or raw < 0:
            raise RuntimeError("p_final_rawが不正です")
        if not math.isfinite(p) or p <= 0:
            raise RuntimeError("corrected_rateが不正です")
        raw_parts.append(raw)
        current.append(p)

    raw_total = sum(raw_parts)
    if not math.isfinite(raw_total) or raw_total <= 0:
        raise RuntimeError("raw_totalが不正です")

    tau = GLOBAL_TAU + RAW_K * math.log(raw_total)
    tau = min(TAU_MAX, max(TAU_MIN, tau))

    powered = [max(p, 1.0e-15) ** tau for p in current]
    total = sum(powered)
    rawtemp = [x / total for x in powered]

    current_order = sorted(range(6), key=lambda i: (-current[i], i))
    raw_order = sorted(range(6), key=lambda i: (-rawtemp[i], i))

    return {
        "raw_total": raw_total,
        "tau": tau,
        "current": current,
        "rawtemp": rawtemp,
        "current_order": current_order,
        "raw_order": raw_order,
    }


def select_recent_race(conn, place_mode: str, require_entry_change: bool = False):
    if place_mode == "NORMAL":
        place_cond = "SUBSTRING(re.race_code, 9, 3) NOT IN ('AMG','TKY')"
        metric_cond = "COUNT(el.straight_time) = 6"
    elif place_mode in SPECIAL:
        place_cond = "SUBSTRING(re.race_code, 9, 3) = %s"
        metric_cond = "1=1"
    else:
        raise RuntimeError(f"unknown place_mode: {place_mode}")

    change_having = ""
    if require_entry_change:
        change_having = "AND COUNT(*) FILTER (WHERE el.entry_course::int <> re.lane_number::int) > 0"

    sql = f"""
        SELECT re.race_code
        FROM boat_race.race_entry re
        JOIN boat_race.race_master rm
          ON rm.race_code = re.race_code
        LEFT JOIN LATERAL (
            SELECT
                x.entry_course,
                x.exhibition_time,
                x.start_timing,
                x.lap_time,
                x.around_time,
                x.straight_time
            FROM boat_race.exhibition_live x
            WHERE x.race_code = re.race_code
              AND x.player_id = re.player_id
            LIMIT 1
        ) el ON TRUE
        WHERE {place_cond}
        GROUP BY re.race_code, rm.race_date
        HAVING COUNT(*) = 6
           AND COUNT(DISTINCT re.lane_number) = 6
           AND COUNT(el.entry_course) = 6
           AND COUNT(DISTINCT el.entry_course) = 6
           AND COUNT(el.exhibition_time) = 6
           AND COUNT(el.start_timing) = 6
           AND COUNT(el.lap_time) = 6
           AND COUNT(el.around_time) = 6
           AND {metric_cond}
           {change_having}
        ORDER BY rm.race_date DESC, re.race_code DESC
        LIMIT 1
    """

    params = () if place_mode == "NORMAL" else (place_mode,)
    with conn.cursor() as cur:
        cur.execute(sql, params)
        row = cur.fetchone()
    return str(row[0]) if row else None


def swapped_virtual_mapping(payload):
    boats = ordered_boats(payload)
    mapping = [int(row["exhibition_course"]) for row in boats]
    if sorted(mapping) != [1, 2, 3, 4, 5, 6]:
        raise RuntimeError("実展示進入が1～6の置換ではありません")

    # 3号艇と4号艇のcourseだけ交換。必ず正しい置換のまま実進入と異なる。
    mapping[2], mapping[3] = mapping[3], mapping[2]
    return "".join(str(x) for x in mapping)


def check_case(label: str, race_code: str, payload, expected_virtual: str | None = None):
    c = candidate(payload)
    boats = ordered_boats(payload)
    current_total = sum(c["current"])
    raw_total = sum(c["rawtemp"])

    checks = []

    def ok(name, passed, detail=""):
        checks.append((name, bool(passed), detail))

    ok("status", payload.get("status") == "ok")
    ok("6艇", len(boats) == 6)
    ok("CURRENT合計100%", abs(current_total - 1.0) < 1.0e-9, f"{current_total*100:.12f}%")
    ok("RAW_TEMP合計100%", abs(raw_total - 1.0) < 1.0e-12, f"{raw_total*100:.12f}%")
    ok("raw_total有限", math.isfinite(c["raw_total"]) and c["raw_total"] > 0, f"{c['raw_total']*100:.2f}%")
    ok("tau範囲", TAU_MIN <= c["tau"] <= TAU_MAX, f"{c['tau']:.6f}")
    ok("全順位不変", c["current_order"] == c["raw_order"])
    ok("Top1不変", c["current_order"][0] == c["raw_order"][0])

    courses = [int(row["exhibition_course"]) for row in boats]
    ok("course置換", sorted(courses) == [1, 2, 3, 4, 5, 6], str(courses))

    place = place_of(race_code)
    if place in SPECIAL:
        methods = [(row.get("ex_detail") or {}).get("ex_method") for row in boats]
        ok("AMG/TKY EX_TOTAL3維持", all(m == "EX_TOTAL3" for m in methods), str(methods))
    else:
        straight = [(row.get("ex_detail") or {}).get("straight_score") for row in boats]
        ok("通常場 straight維持", all(v is not None for v in straight), str(straight))

    if expected_virtual is not None:
        expected = [int(x) for x in expected_virtual]
        ok("仮想course一致", courses == expected, f"expected={expected}, actual={courses}")

    top_lane = c["current_order"][0] + 1
    print(f"\n[{label}] {race_code} ({place})")
    print(f"raw_total={c['raw_total']*100:.2f}%  tau={c['tau']:.6f}  Top1={top_lane}号艇")
    print("lane  course   CURRENT    RAW_TEMP")
    for lane, row in enumerate(boats, start=1):
        print(
            f" {lane}      {int(row['exhibition_course'])}      "
            f"{c['current'][lane-1]*100:7.2f}%    {c['rawtemp'][lane-1]*100:7.2f}%"
        )
    for name, passed, detail in checks:
        mark = "OK" if passed else "NG"
        suffix = f"  {detail}" if detail else ""
        print(f"  {mark:2}  {name}{suffix}")

    return checks


def main():
    explicit = sys.argv[1:]
    if explicit and len(explicit) != 3:
        print(
            "Usage: python3 analysis/validate_rawtemp_production_candidate.py "
            "[NORMAL_RACE AMG_RACE TKY_RACE]"
        )
        return 1

    with connect_db() as conn:
        if explicit:
            normal_race, amg_race, tky_race = [x.strip().upper() for x in explicit]
        else:
            normal_race = select_recent_race(conn, "NORMAL")
            amg_race = select_recent_race(conn, "AMG")
            tky_race = select_recent_race(conn, "TKY")

        entry_change_race = select_recent_race(conn, "NORMAL", require_entry_change=True)
        if entry_change_race is None:
            entry_change_race = select_recent_race(conn, "AMG", require_entry_change=True)
        if entry_change_race is None:
            entry_change_race = select_recent_race(conn, "TKY", require_entry_change=True)

    required = {
        "通常場": normal_race,
        "AMG": amg_race,
        "TKY": tky_race,
        "実進入変更": entry_change_race,
    }
    missing = [name for name, code in required.items() if not code]
    if missing:
        raise RuntimeError("回帰テスト用レースを取得できません: " + ", ".join(missing))

    print("=" * 112)
    print("RAW_TEMP 本番反映前 最終回帰テスト")
    print("=" * 112)
    print(f"固定式: tau = clamp({GLOBAL_TAU:.2f} + {RAW_K:.2f} * ln(raw_total), {TAU_MIN:.2f}, {TAU_MAX:.2f})")
    print("本番変更: なし（候補後段変換だけを検証）")
    print(f"通常場      : {normal_race}")
    print(f"AMG         : {amg_race}")
    print(f"TKY         : {tky_race}")
    print(f"実進入変更  : {entry_change_race}")

    all_checks = []
    seen = set()
    cases = [
        ("通常場", normal_race),
        ("AMG", amg_race),
        ("TKY", tky_race),
        ("実進入変更", entry_change_race),
    ]

    for label, code in cases:
        key = (label, code)
        if key in seen:
            continue
        seen.add(key)
        payload = load_actual(code)
        checks = check_case(label, code, payload)
        all_checks.extend((f"{label}:{name}", passed, detail) for name, passed, detail in checks)

        if label == "実進入変更":
            actual_courses = [int(row["exhibition_course"]) for row in ordered_boats(payload)]
            changed = any(course != lane for lane, course in enumerate(actual_courses, start=1))
            all_checks.append(("実進入変更:実際に変更あり", changed, str(actual_courses)))
            print(f"  {'OK' if changed else 'NG':2}  実際に進入変更あり  {actual_courses}")

    # 仮想進入は通常場を使い、実展示courseの3号艇/4号艇を交換して検証する。
    base_payload = load_actual(normal_race)
    virtual_mapping = swapped_virtual_mapping(base_payload)
    virtual_payload = load_virtual(normal_race, virtual_mapping)
    vchecks = check_case("仮想進入", normal_race, virtual_payload, expected_virtual=virtual_mapping)
    all_checks.extend((f"仮想進入:{name}", passed, detail) for name, passed, detail in vchecks)

    total = len(all_checks)
    ng = [(name, detail) for name, passed, detail in all_checks if not passed]
    ok_n = total - len(ng)

    print("\n" + "=" * 112)
    print(f"最終結果: OK={ok_n} / NG={len(ng)} / TOTAL={total}")
    if ng:
        print("NG項目:")
        for name, detail in ng:
            print(f"  - {name}: {detail}")
        print("RAW_TEMP 本番反映前回帰テスト: NG")
        return 2

    print("RAW_TEMP 本番反映前回帰テスト: ALL OK")
    print("次工程: Web本番へRAW_TEMP後段較正を追加可能")
    print("=" * 112)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
