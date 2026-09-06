#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
配当サイン連動・穴目頭研究用の「レース相対 / 展開特徴量」共通部品。

方針
----
- 現行の荒れ判定(PayoutSignalClassifier v1相当)は変更しない。
- この部品は「荒れるとしたら誰が1着か」を調べるためだけに使う。
- まず穴目研究だけで使用し、本命/対抗の本番ロジックには接続しない。
- 結果ラベルは特徴量生成には使わない。

主な特徴
--------
自力:
  motor / boat / primary / secondary / final3
AI:
  corrected win / AI3連対 / 120通り頭確率
自艇の決まり手:
  6m sashi / makuri / makurizashi / attack(makuri+makurizashi)
展開:
  ひとつ内艇の makuri / makurizashi / attack
  自艇より内側の attack 最大
相互作用(探索用):
  ひとつ内艇attack × 自艇makurizashi
  ひとつ内艇attack × 自艇(sashi+makurizashi)
  内側attack最大 × 自艇makurizashi

決まり手率は point-in-time kimarite dataset の6ヶ月を使い、sample_n>=10のみ有効。
"""

from __future__ import annotations

import csv
import sys
from pathlib import Path
from typing import Dict, Iterable, Optional

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))
if str(ROOT / "analysis") not in sys.path:
    sys.path.insert(0, str(ROOT / "analysis"))

import upset_probability_validate as c1

MIN_KIMARITE_SAMPLE = 10

FEATURE_LABELS = {
    "motor": "モーター2連率",
    "boat": "ボート2連率",
    "primary": "一次評価",
    "secondary": "二次評価",
    "final3": "最終評価",
    "win_p": "補正後1着率",
    "trio_p": "AI3連対率",
    "outcome_p": "120通り頭確率",
    "self_sashi": "自艇6m差し率",
    "self_makuri": "自艇6mまくり率",
    "self_mz": "自艇6mまくり差し率",
    "self_attack": "自艇6m攻め率",
    "inner_makuri": "1つ内艇6mまくり率",
    "inner_mz": "1つ内艇6mまくり差し率",
    "inner_attack": "1つ内艇6m攻め率",
    "inside_attack_max": "内側艇6m攻め率最大",
    "inner_attack_x_self_mz": "内艇攻め×自艇まくり差し",
    "inner_attack_x_self_sashi_mz": "内艇攻め×自艇差し/まくり差し",
    "inside_attack_x_self_mz": "内側最大攻め×自艇まくり差し",
}

RANK_FEATURES = tuple(FEATURE_LABELS.keys())


def safe_float(v) -> Optional[float]:
    if v is None:
        return None
    s = str(v).strip()
    if s == "":
        return None
    try:
        return float(s)
    except (TypeError, ValueError):
        return None


def safe_int(v) -> Optional[int]:
    x = safe_float(v)
    return int(x) if x is not None else None


def load_kimarite(*paths: str) -> Dict[str, dict]:
    out: Dict[str, dict] = {}
    for path in paths:
        p = Path(path)
        if not p.is_file():
            raise RuntimeError(f"kimarite datasetがありません: {path}")
        with p.open("r", encoding="utf-8-sig", newline="") as fh:
            for row in csv.DictReader(fh):
                code = str(row.get("race_code") or "").strip()
                if not code:
                    continue
                complete = safe_int(row.get("result_top3_course_complete"))
                matched = safe_int(row.get("result_boat_match"))
                if complete == 1 and matched == 1:
                    out[code] = row
    return out


def _course_stats(row: dict, course: int) -> Optional[dict]:
    n = safe_int(row.get(f"c{course}_6m_sample_n")) or 0
    if n < MIN_KIMARITE_SAMPLE:
        return None
    sashi = safe_float(row.get(f"c{course}_6m_sashi"))
    makuri = safe_float(row.get(f"c{course}_6m_makuri"))
    mz = safe_float(row.get(f"c{course}_6m_makurizashi"))
    if sashi is None or makuri is None or mz is None:
        return None
    return {
        "sample_n": n,
        "sashi": sashi,
        "makuri": makuri,
        "mz": mz,
        "attack": makuri + mz,
        "sashi_mz": sashi + mz,
    }


def load_engine_map(race_codes: Iterable[str]) -> Dict[tuple[str, int], dict]:
    """race時点のモーター/ボート2連率をDBから取得。失敗時は空map。"""
    codes = sorted({str(c) for c in race_codes if c})
    if not codes:
        return {}

    try:
        import psycopg2
        from common.db_config import load_db_config

        conn = psycopg2.connect(**load_db_config())
        try:
            with conn.cursor() as cur:
                cur.execute(
                    """
                    SELECT
                        re.race_code,
                        re.lane_number,
                        es.motor_exacta_rate,
                        es.boat_exacta_rate
                    FROM boat_race.race_entry re
                    LEFT JOIN boat_race.engine_specs es
                      ON es.race_code = re.race_code
                     AND es.motor_number = re.motor_number
                     AND es.boat_number = re.boat_number
                    WHERE re.race_code = ANY(%s)
                    """,
                    (codes,),
                )
                out: Dict[tuple[str, int], dict] = {}
                for race_code, lane, motor, boat in cur.fetchall():
                    lane_i = int(lane)
                    if lane_i not in range(1, 7):
                        continue
                    out[(str(race_code), lane_i)] = {
                        "motor": safe_float(motor),
                        "boat": safe_float(boat),
                    }
                return out
        finally:
            conn.close()
    except Exception as exc:
        print(f"[WARN] モーター/ボートDB読込をスキップ: {exc}", file=sys.stderr)
        return {}


def build_features(record: dict, boats: dict, kimarite_row: dict, engine_map: dict) -> Optional[dict]:
    """1レース6艇の相対・展開特徴量を生成する。"""
    if boats is None or set(boats) != set(range(1, 7)):
        return None

    base = c1.make_features(record, boats)
    if base is None:
        return None

    race_code = str(record.get("race_code") or "")
    course_by_lane = {int(k): int(v) for k, v in base["course_by_lane"].items()}
    if set(course_by_lane) != set(range(1, 7)):
        return None

    stats_by_course = {c: _course_stats(kimarite_row, c) for c in range(1, 7)}
    out = {}

    for lane in range(1, 7):
        course = int(course_by_lane[lane])
        self_s = stats_by_course.get(course)
        inner_s = stats_by_course.get(course - 1) if course > 1 else None

        inside_attacks = []
        if course > 2:
            for c in range(2, course):
                s = stats_by_course.get(c)
                if s is not None:
                    inside_attacks.append(float(s["attack"]))
        inside_attack_max = max(inside_attacks) if inside_attacks else None

        engine = engine_map.get((race_code, lane), {})
        self_sashi = self_s["sashi"] if self_s else None
        self_makuri = self_s["makuri"] if self_s else None
        self_mz = self_s["mz"] if self_s else None
        self_attack = self_s["attack"] if self_s else None

        inner_makuri = inner_s["makuri"] if inner_s else None
        inner_mz = inner_s["mz"] if inner_s else None
        inner_attack = inner_s["attack"] if inner_s else None

        out[lane] = {
            "lane": lane,
            "course": course,
            "motor": safe_float(engine.get("motor")),
            "boat": safe_float(engine.get("boat")),
            "primary": safe_float(boats[lane].get("first_score")),
            "secondary": safe_float(boats[lane].get("second_score")),
            "final3": safe_float(boats[lane].get("final3")),
            "win_p": safe_float(base["win"].get(lane)),
            "trio_p": safe_float(base["trio"].get(lane)),
            "outcome_p": safe_float(base["outcome_head"].get(lane)),
            "self_sashi": self_sashi,
            "self_makuri": self_makuri,
            "self_mz": self_mz,
            "self_attack": self_attack,
            "inner_makuri": inner_makuri,
            "inner_mz": inner_mz,
            "inner_attack": inner_attack,
            "inside_attack_max": inside_attack_max,
            "inner_attack_x_self_mz": (
                inner_attack * self_mz if inner_attack is not None and self_mz is not None else None
            ),
            "inner_attack_x_self_sashi_mz": (
                inner_attack * (self_sashi + self_mz)
                if inner_attack is not None and self_sashi is not None and self_mz is not None
                else None
            ),
            "inside_attack_x_self_mz": (
                inside_attack_max * self_mz
                if inside_attack_max is not None and self_mz is not None
                else None
            ),
        }

    return {
        "race_code": race_code,
        "in_lane": int(base["in_lane"]),
        "course_by_lane": course_by_lane,
        "boats": out,
    }


def rank_lanes(boat_features: dict, feature: str, eligible: Iterable[int]) -> list[int]:
    rows = []
    for lane in eligible:
        v = safe_float(boat_features.get(int(lane), {}).get(feature))
        if v is None:
            continue
        rows.append((int(lane), float(v)))
    rows.sort(key=lambda x: (-x[1], x[0]))
    return [lane for lane, _ in rows]
