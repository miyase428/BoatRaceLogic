#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""RAW_TEMP本番反映後の最終整合性テスト。

既存exact/AMG-TKY/virtualの出力と、Web本番用calibratedラッパーの出力を
同じレースで比較し、後段RAW_TEMP以外が変わっていないことを確認する。
"""

from __future__ import annotations

import math
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
FORECAST_DIR = REPO_ROOT / "forecast"
ANALYSIS_DIR = REPO_ROOT / "analysis"
sys.path.insert(0, str(ANALYSIS_DIR))

import validate_rawtemp_production_candidate as pre  # noqa: E402
from slit_validate_v2 import connect_db  # noqa: E402

CALIBRATED = FORECAST_DIR / "corrected_winrate_live_calibrated.py"
TOL = 1.0e-9


def load_calibrated(race_code: str, virtual: str | None = None):
    args = [sys.executable, str(CALIBRATED), race_code]
    if virtual is not None:
        args.append(virtual)
    return pre.run_json(args, timeout=300)


def check_pair(label: str, race_code: str, base, calibrated, virtual: str | None = None):
    expected = pre.candidate(base)
    base_boats = pre.ordered_boats(base)
    cal_boats = pre.ordered_boats(calibrated)
    checks = []

    def add(name, passed, detail=""):
        checks.append((name, bool(passed), detail))

    add("status", calibrated.get("status") == "ok")
    add("race_code維持", calibrated.get("race_code") == base.get("race_code"))
    add("place_code維持", calibrated.get("place_code") == base.get("place_code"))

    method = calibrated.get("method") or {}
    raw_meta = method.get("raw_temp") or {}
    add("GLOBAL tau=0.90", abs(float(raw_meta.get("global_tau", -1)) - 0.90) < TOL)
    add("RAW k=0.80", abs(float(raw_meta.get("raw_k", -1)) - 0.80) < TOL)
    add("raw_total一致", abs(float(raw_meta.get("raw_total", -1)) - expected["raw_total"]) < TOL,
        f"actual={float(raw_meta.get('raw_total', -1))*100:.4f}% expected={expected['raw_total']*100:.4f}%")
    add("tau一致", abs(float(raw_meta.get("tau", -1)) - expected["tau"]) < TOL,
        f"actual={float(raw_meta.get('tau', -1)):.9f} expected={expected['tau']:.9f}")

    before = []
    after = []
    courses_same = True
    ex_same = True
    sum_same = True
    slit_same = True

    for idx, (b0, b1) in enumerate(zip(base_boats, cal_boats)):
        p0 = float(b0["corrected_rate"]) / 100.0
        pb = float(b1["corrected_rate_before_raw_temp"]) / 100.0
        p1 = float(b1["corrected_rate"]) / 100.0
        before.append(pb)
        after.append(p1)

        if abs(pb - p0) >= TOL:
            ex_same = False
        if abs(p1 - expected["rawtemp"][idx]) >= TOL:
            sum_same = False
        if int(b0["exhibition_course"]) != int(b1["exhibition_course"]):
            courses_same = False
        if b0.get("slit_raw_buff") != b1.get("slit_raw_buff"):
            slit_same = False

    add("RAW前corrected_rate完全一致", ex_same)
    add("RAW後corrected_rate候補式一致", sum_same)
    add("course完全一致", courses_same)
    add("slit_raw_buff完全一致", slit_same)
    add("RAW前合計100%", abs(sum(before) - 1.0) < TOL, f"{sum(before)*100:.12f}%")
    add("RAW後合計100%", abs(sum(after) - 1.0) < TOL, f"{sum(after)*100:.12f}%")

    before_order = sorted(range(6), key=lambda i: (-before[i], i))
    after_order = sorted(range(6), key=lambda i: (-after[i], i))
    add("全順位不変", before_order == after_order)
    add("Top1不変", before_order[0] == after_order[0])

    if virtual is not None:
        actual = "".join(str(int(b["exhibition_course"])) for b in cal_boats)
        add("仮想course一致", actual == virtual, f"actual={actual} expected={virtual}")

    print(f"\n[{label}] {race_code}")
    print(f"raw_total={expected['raw_total']*100:.2f}% tau={expected['tau']:.6f}")
    for lane, (b0, b1) in enumerate(zip(base_boats, cal_boats), start=1):
        print(
            f" {lane}号艇 C{int(b1['exhibition_course'])}  "
            f"before={float(b0['corrected_rate']):7.2f}%  after={float(b1['corrected_rate']):7.2f}%"
        )
    for name, passed, detail in checks:
        print(f"  {'OK' if passed else 'NG':2}  {name}{('  ' + detail) if detail else ''}")

    return checks


def main():
    with connect_db() as conn:
        normal = pre.select_recent_race(conn, "NORMAL")
        amg = pre.select_recent_race(conn, "AMG")
        tky = pre.select_recent_race(conn, "TKY")
        changed = pre.select_recent_race(conn, "NORMAL", require_entry_change=True)
        if changed is None:
            changed = pre.select_recent_race(conn, "AMG", require_entry_change=True)
        if changed is None:
            changed = pre.select_recent_race(conn, "TKY", require_entry_change=True)

    required = {"通常場": normal, "AMG": amg, "TKY": tky, "実進入変更": changed}
    missing = [k for k, v in required.items() if not v]
    if missing:
        raise RuntimeError("テスト用レースを取得できません: " + ", ".join(missing))

    print("=" * 112)
    print("RAW_TEMP Web本番反映後 最終整合性テスト")
    print("=" * 112)
    print("Web候補式: tau = clamp(0.90 + 0.80 * ln(raw_total), 0.45, 1.60)")

    all_checks = []
    for label, code in required.items():
        base = pre.load_actual(code)
        cal = load_calibrated(code)
        checks = check_pair(label, code, base, cal)
        all_checks.extend((f"{label}:{name}", ok, detail) for name, ok, detail in checks)

    base_normal = pre.load_actual(normal)
    virtual = pre.swapped_virtual_mapping(base_normal)
    base_virtual = pre.load_virtual(normal, virtual)
    cal_virtual = load_calibrated(normal, virtual)
    checks = check_pair("仮想進入", normal, base_virtual, cal_virtual, virtual)
    all_checks.extend((f"仮想進入:{name}", ok, detail) for name, ok, detail in checks)

    ok_n = sum(1 for _, ok, _ in all_checks if ok)
    ng = [(name, detail) for name, ok, detail in all_checks if not ok]

    print("\n" + "=" * 112)
    print(f"最終結果: OK={ok_n} / NG={len(ng)} / TOTAL={len(all_checks)}")
    if ng:
        for name, detail in ng:
            print(f"NG: {name}{(' / ' + detail) if detail else ''}")
        print("RAW_TEMP Web本番反映後テスト: NGあり")
        return 1

    print("RAW_TEMP Web本番反映後テスト: ALL OK")
    print("次工程: ブラウザ表示確認後、補正後1着率を完了扱いにできる")
    print("=" * 112)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
