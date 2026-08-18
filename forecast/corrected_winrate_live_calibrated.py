#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""Web本番用 補正後1着率 + RAW_TEMP後段較正ラッパー。

既存の検証済みチェーンは変更せず、最終 corrected_rate にだけ
raw_total 連動の温度較正を適用する。

採用式（固定2期間ホールドアウト検証済み）:
  tau = clamp(0.90 + 0.80 * ln(raw_total), 0.45, 1.60)
  p'_i = p_i ** tau / sum_j(p_j ** tau)

- 通常22場: corrected_winrate_live_exact.py
- AMG/TKY: corrected_winrate_live_exact_amg_tky.py
- 仮想進入: corrected_winrate_live_virtual.py

元チェーンの出力は corrected_rate_before_raw_temp として残す。
"""

from __future__ import annotations

import json
import math
import subprocess
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent

GLOBAL_TAU = 0.90
RAW_K = 0.80
TAU_MIN = 0.45
TAU_MAX = 1.60
SPECIAL_PLACES = {"AMG", "TKY"}


def clamp(value: float, low: float, high: float) -> float:
    return min(high, max(low, value))


def normalize(values: list[float]) -> list[float]:
    vals = [max(float(x), 1.0e-15) for x in values]
    total = sum(vals)
    if total <= 0 or not math.isfinite(total):
        raise RuntimeError("RAW_TEMP正規化前合計が不正です")
    return [x / total for x in vals]


def temperature_probs(probs: list[float], tau: float) -> list[float]:
    if not math.isfinite(tau) or tau <= 0:
        raise RuntimeError("RAW_TEMP tauが不正です")
    powered = [max(float(p), 1.0e-15) ** tau for p in probs]
    return normalize(powered)


def run_base_script(race_code: str, virtual_lane_to_course: str | None) -> dict:
    place_code = race_code[8:11]

    if virtual_lane_to_course is not None:
        script = HERE / "corrected_winrate_live_virtual.py"
        args = [sys.executable, str(script), race_code, virtual_lane_to_course]
    elif place_code in SPECIAL_PLACES:
        script = HERE / "corrected_winrate_live_exact_amg_tky.py"
        args = [sys.executable, str(script), race_code]
    else:
        script = HERE / "corrected_winrate_live_exact.py"
        args = [sys.executable, str(script), race_code]

    proc = subprocess.run(
        args,
        capture_output=True,
        text=True,
        timeout=300,
        check=False,
    )
    text = (proc.stdout or "").strip()
    if not text:
        err = (proc.stderr or "").strip()
        raise RuntimeError(f"補正後1着率の元チェーン出力が空です: {err}")

    try:
        data = json.loads(text)
    except json.JSONDecodeError as exc:
        err = (proc.stderr or "").strip()
        raise RuntimeError(f"補正後1着率の元チェーンJSON解析失敗: {exc}; {err or text[:300]}")

    if not isinstance(data, dict):
        raise RuntimeError("補正後1着率の元チェーンJSONがobjectではありません")
    return data


def apply_raw_temp(data: dict) -> dict:
    if data.get("status") != "ok":
        return data

    boats = data.get("boats")
    if not isinstance(boats, dict) or len(boats) != 6:
        raise RuntimeError("RAW_TEMP対象のboatsが6艇ではありません")

    lane_keys = sorted(boats.keys(), key=lambda x: int(x))
    if [int(x) for x in lane_keys] != [1, 2, 3, 4, 5, 6]:
        raise RuntimeError("RAW_TEMP対象のlaneが1～6で揃っていません")

    raw_total = 0.0
    current_probs: list[float] = []

    for key in lane_keys:
        row = boats[key]
        detail = row.get("base_detail") if isinstance(row, dict) else None
        if not isinstance(detail, dict):
            raise RuntimeError(f"{key}号艇のbase_detailがありません")

        p_final_raw = float(detail.get("p_final_raw"))
        corrected_rate = float(row.get("corrected_rate"))
        if not math.isfinite(p_final_raw) or p_final_raw < 0:
            raise RuntimeError(f"{key}号艇のp_final_rawが不正です")
        if not math.isfinite(corrected_rate) or corrected_rate < 0:
            raise RuntimeError(f"{key}号艇のcorrected_rateが不正です")

        raw_total += p_final_raw
        current_probs.append(corrected_rate / 100.0)

    if raw_total <= 0 or not math.isfinite(raw_total):
        raise RuntimeError("基本1着率raw_totalが不正です")

    current_probs = normalize(current_probs)
    tau = clamp(GLOBAL_TAU + RAW_K * math.log(raw_total), TAU_MIN, TAU_MAX)
    calibrated = temperature_probs(current_probs, tau)

    for key, old_p, new_p in zip(lane_keys, current_probs, calibrated):
        row = boats[key]
        row["corrected_rate_before_raw_temp"] = old_p * 100.0
        row["corrected_rate"] = new_p * 100.0

    method = data.setdefault("method", {})
    if not isinstance(method, dict):
        method = {}
        data["method"] = method
    method["raw_temp"] = {
        "global_tau": GLOBAL_TAU,
        "raw_k": RAW_K,
        "tau_min": TAU_MIN,
        "tau_max": TAU_MAX,
        "raw_total": raw_total,
        "tau": tau,
        "formula": "clamp(0.90 + 0.80 * ln(raw_total), 0.45, 1.60)",
    }

    totals = data.setdefault("totals", {})
    if not isinstance(totals, dict):
        totals = {}
        data["totals"] = totals
    totals["corrected_before_raw_temp"] = sum(current_probs) * 100.0
    totals["corrected"] = sum(calibrated) * 100.0

    data["calibration"] = {
        "method": "RAW_TEMP",
        "raw_total": raw_total,
        "tau": tau,
        "sum_percent": sum(calibrated) * 100.0,
        "rank_preserving": True,
    }
    return data


def main() -> int:
    if len(sys.argv) not in (2, 3):
        print(
            json.dumps(
                {
                    "status": "error",
                    "boats": {},
                    "error": "Usage: corrected_winrate_live_calibrated.py RACE_CODE [LANE_TO_COURSE]",
                },
                ensure_ascii=False,
            )
        )
        return 1

    race_code = sys.argv[1].strip().upper()
    virtual_lane_to_course = sys.argv[2].strip() if len(sys.argv) == 3 else None

    try:
        data = run_base_script(race_code, virtual_lane_to_course)
        data = apply_raw_temp(data)
        print(json.dumps(data, ensure_ascii=False))
        return 0 if data.get("status") == "ok" else 1
    except Exception as exc:
        print(json.dumps({"status": "error", "boats": {}, "error": str(exc)}, ensure_ascii=False))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
